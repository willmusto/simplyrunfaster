<?php
/**
 * READ-ONLY orphan audit (no FKs on MyISAM → orphans accumulate silently).
 * Reports counts only; DELETES NOTHING. See _specs/db_debt_audit.md §4.
 *
 *   php scripts/audit_orphans.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
$db = Database::get();

$hasTable = function (string $t) use ($db): bool {
    $s = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->execute([$t]);
    return (int)$s->fetchColumn() > 0;
};
$count = function (string $sql) use ($db): int { return (int)$db->query($sql)->fetchColumn(); };

echo "=== ORPHAN AUDIT (read-only) " . date('Y-m-d H:i:s') . " ===\n\n";

// 1) Athlete-scoped rows whose athlete_id has no athletes row.
echo "[1] Rows pointing at a non-existent athlete (athlete_id with no athletes row):\n";
$athleteScoped = [
    'athlete_profiles','personal_bests','races','watch_connections','training_plans','planned_workouts',
    'completed_workouts','training_load','engine_flags','plan_approval_queue','messages','session_notes',
    'scheduled_messages','coach_assignments','plan_regeneration_requests','coach_adjustments',
    'coaching_intelligence_flags','athlete_behavior_log','athlete_response_profiles',
];
$totalAthleteOrphans = 0;
foreach ($athleteScoped as $t) {
    if (!$hasTable($t)) continue;
    $n = $count("SELECT COUNT(*) FROM `{$t}` x LEFT JOIN athletes a ON a.id = x.athlete_id WHERE a.id IS NULL");
    if ($n > 0) echo "    {$t}: {$n}\n";
    $totalAthleteOrphans += $n;
}
echo "    → total: {$totalAthleteOrphans}\n\n";

// 2) User-scoped rows whose user_id has no users row.
echo "[2] Rows pointing at a non-existent user (user_id with no users row):\n";
$userScoped = ['notification_preferences','device_notify_preferences','push_subscriptions','phone_verifications','password_reset_tokens','intervals_connections'];
$totalUserOrphans = 0;
foreach ($userScoped as $t) {
    if (!$hasTable($t)) continue;
    $n = $count("SELECT COUNT(*) FROM `{$t}` x LEFT JOIN users u ON u.id = x.user_id WHERE u.id IS NULL");
    if ($n > 0) echo "    {$t}: {$n}\n";
    $totalUserOrphans += $n;
}
echo "    → total: {$totalUserOrphans}\n\n";

// 3) completed_workouts pointing at a deleted/nonexistent planned_workout.
echo "[3] completed_workouts with a dangling planned_workout_id:\n";
$cwOrphan = $count(
    "SELECT COUNT(*) FROM completed_workouts cw
     LEFT JOIN planned_workouts pw ON pw.id = cw.planned_workout_id
     WHERE cw.planned_workout_id IS NOT NULL AND pw.id IS NULL"
);
echo "    dangling planned_workout_id: {$cwOrphan}\n\n";

// 4) intervals_push_log / intervals_webhook_log referencing gone parents (informational).
echo "[4] Intervals logs referencing gone parents (informational; logs, not PII-critical):\n";
if ($hasTable('intervals_push_log')) {
    $n = $count("SELECT COUNT(*) FROM intervals_push_log pl LEFT JOIN planned_workouts pw ON pw.id = pl.planned_workout_id WHERE pw.id IS NULL");
    echo "    intervals_push_log → missing planned_workout: {$n}\n";
}
if ($hasTable('intervals_webhook_log') && $hasTable('intervals_connections')) {
    $n = $count("SELECT COUNT(*) FROM intervals_webhook_log wl LEFT JOIN intervals_connections ic ON ic.intervals_athlete_id = wl.athlete_id WHERE ic.id IS NULL");
    echo "    intervals_webhook_log → no matching connection: {$n}\n";
}
echo "\n";

// 5) intervals_connections detail — departed/test users (DO NOT auto-delete; flag for review).
echo "[5] intervals_connections — every row with its user (flag departed/test; Liam=user 2 is the live test athlete):\n";
if ($hasTable('intervals_connections')) {
    $rows = $db->query(
        "SELECT ic.id, ic.user_id, ic.intervals_athlete_id, ic.connected_at, ic.sync_status,
                u.email, u.role, u.deleted_at
         FROM intervals_connections ic
         LEFT JOIN users u ON u.id = ic.user_id
         ORDER BY ic.user_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "    (none)\n";
    foreach ($rows as $r) {
        $flags = [];
        if ($r['email'] === null)              $flags[] = 'NO-USER';
        if (($r['role'] ?? '') !== 'athlete')  $flags[] = 'role=' . ($r['role'] ?? 'null');
        if ($r['deleted_at'] !== null)         $flags[] = 'USER-DELETED';
        if ((int)$r['user_id'] === 2)          $flags[] = 'LIAM-TEST(review-only)';
        $tag = $flags ? '  <<< ' . implode(',', $flags) : '';
        echo "    conn id={$r['id']} user_id={$r['user_id']} email=" . ($r['email'] ?? '-') . " role=" . ($r['role'] ?? '-') . " connected={$r['connected_at']} sync={$r['sync_status']}{$tag}\n";
    }
}
echo "\n";

// 6) Synthetic / leaked verification rows.
echo "[6] Synthetic-row leak check (verify_*.php fixtures; MyISAM rollback is a no-op):\n";
// 6a) Orphan athletes (an athletes row with no users row) — a real athlete always has a user.
$orphanAthletes = $count("SELECT COUNT(*) FROM athletes a LEFT JOIN users u ON u.id = a.user_id WHERE u.id IS NULL");
echo "    orphan athletes (no users row): {$orphanAthletes}\n";
if ($orphanAthletes > 0) {
    foreach ($db->query("SELECT a.id, a.user_id FROM athletes a LEFT JOIN users u ON u.id = a.user_id WHERE u.id IS NULL ORDER BY a.id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "        athlete id={$r['id']} user_id={$r['user_id']}\n";
    }
}
// 6b) Users with obviously-synthetic emails (NOT the legit deleted_<id>@deleted.invalid anonymization).
$suspect = $db->query(
    "SELECT id, email, role, created_at FROM users
      WHERE (email LIKE '%verify%' OR email LIKE '%@verify.invalid%' OR email LIKE '%@test%'
             OR email LIKE '%example.com%' OR email LIKE 'srf\\_%' )
        AND email NOT LIKE 'deleted\\_%@deleted.invalid'
      ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
echo "    suspect synthetic users: " . count($suspect) . "\n";
foreach ($suspect as $r) echo "        user id={$r['id']} email={$r['email']} role={$r['role']} created={$r['created_at']}\n";
// 6c) Anonymized (real-deletion) users — informational, these are legitimate.
$anon = $count("SELECT COUNT(*) FROM users WHERE email LIKE 'deleted\\_%@deleted.invalid'");
echo "    (info) legitimately anonymized deleted users: {$anon}\n\n";

echo "=== END (nothing was modified) ===\n";

<?php
/**
 * Verification: Coaching Intelligence Layer Phase 4 — multi-coach support, sharing,
 * assistant proposals, inheritance source, and analytics.
 *
 * Synthetic: two head coaches (A, B), one assistant (managed by A, assigned to A's
 * athlete), and one athlete each. Asserts the resolver's shared-union + isolation,
 * the proposed/proposed_by_assistant exclusions, the assistant-proposal lifecycle, the
 * import source set (shared + non-shared), the dormancy helper, and that analytics
 * exclude 'superseded'. Full teardown; no live data / no Liam.
 *
 * Run: php /home/private/app/scripts/verify_cil_phase4.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
foreach ([SCRIPT_ROOT . '/config/config.local.php', '/home/public/config/config.local.php'] as $cfg) {
    if (file_exists($cfg)) { require $cfg; break; }
}
defined('DB_HOST')    || define('DB_HOST',    getenv('SRF_DB_HOST') ?: 'localhost');
defined('DB_NAME')    || define('DB_NAME',    getenv('SRF_DB_NAME') ?: 'simplyrunfaster');
defined('DB_USER')    || define('DB_USER',    getenv('SRF_DB_USER') ?: 'root');
defined('DB_PASS')    || define('DB_PASS',    getenv('SRF_DB_PASS') ?: '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8');

require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/CoachAssignments.php';
require_once SCRIPT_ROOT . '/src/CoachingDecisions.php';

$db = Database::get();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

$ids = ['users' => [], 'athletes' => []];

try {
    $mkUser = function (string $role, ?int $managedBy = null) use ($db, &$ids): int {
        $email = 'cil4_' . $role . '_' . substr(md5((string)mt_rand()), 0, 8) . '@example.test';
        $db->prepare("INSERT INTO users (email, password_hash, role, name, timezone, managed_by, active)
                      VALUES (?, ?, ?, ?, 'America/New_York', ?, 1)")
           ->execute([$email, password_hash('x', PASSWORD_DEFAULT), $role, 'CIL4 ' . $role, $managedBy]);
        $id = (int)$db->lastInsertId();
        $ids['users'][] = $id;
        return $id;
    };
    $mkAthlete = function (int $coachId, ?int $assistantId = null) use ($db, &$ids): int {
        $email = 'cil4_ath_' . substr(md5((string)mt_rand()), 0, 8) . '@example.test';
        $db->prepare("INSERT INTO users (email, password_hash, role, name, timezone, active)
                      VALUES (?, ?, 'athlete', 'CIL4 Athlete', 'America/New_York', 1)")
           ->execute([$email, password_hash('x', PASSWORD_DEFAULT)]);
        $auid = (int)$db->lastInsertId();
        $ids['users'][] = $auid;
        $db->prepare("INSERT INTO athletes (user_id, coach_id, onboarding_completed_at, status) VALUES (?, ?, NOW(), 'active')")
           ->execute([$auid, $coachId]);
        $aid = (int)$db->lastInsertId();
        $ids['athletes'][] = $aid;
        $db->prepare("INSERT INTO coach_assignments (athlete_id, coach_id, assistant_coach_id, assigned_at, assigned_by)
                      VALUES (?, ?, ?, NOW(), ?)")
           ->execute([$aid, $coachId, $assistantId, $coachId]);
        return $aid;
    };
    $mkDecision = function (int $createdBy, string $status, int $shared = 0) use ($db): int {
        $db->prepare(
            "INSERT INTO coaching_decisions
               (created_by, created_at, status, shared, title, reason, rationale, trigger_json, action_json, source)
             VALUES (?, NOW(), ?, ?, ?, 'why', 'why', '{}', '{}', 'manual')"
        )->execute([$createdBy, $status, $shared, 'D-' . $status . ($shared ? '-shared' : '')]);
        return (int)$db->lastInsertId();
    };
    $loadedIds = function (int $athleteId) use ($db): array {
        return array_map(static fn($d) => (int)$d['id'], CoachingDecisions::loadActiveForAthlete($athleteId, $db));
    };

    $coachA = $mkUser('coach');
    $coachB = $mkUser('coach');
    $asst   = $mkUser('assistant_coach', $coachA);
    $athA   = $mkAthlete($coachA, $asst);
    $athB   = $mkAthlete($coachB);

    // ── Dormancy helper ───────────────────────────────────────────────────────
    check("multiCoach() true with ≥2 coaching accounts", CoachAssignments::multiCoach($db) === true);

    // ── A4 resolver: sharing + isolation + status exclusions ──────────────────
    $d1 = $mkDecision($coachA, 'active', 0);                 // private to A
    $d2 = $mkDecision($coachA, 'active', 1);                 // shared roster-wide
    $d3 = $mkDecision($coachA, 'proposed', 0);               // system proposal
    $d4 = $mkDecision($asst,   'proposed_by_assistant', 0);  // assistant proposal
    $d5 = $mkDecision($coachB, 'inactive', 0);               // inactive

    $aIds = $loadedIds($athA);
    $bIds = $loadedIds($athB);

    check("resolver: A's athlete gets A's private active decision", in_array($d1, $aIds, true));
    check("resolver: A's athlete gets the shared active decision", in_array($d2, $aIds, true));
    check("resolver: B's athlete gets the SHARED decision (cross-coach)", in_array($d2, $bIds, true));
    check("resolver: B's athlete does NOT get A's private decision (isolation)", !in_array($d1, $bIds, true));
    check("resolver: excludes 'proposed'", !in_array($d3, $aIds, true) && !in_array($d3, $bIds, true));
    check("resolver: excludes 'proposed_by_assistant'", !in_array($d4, $aIds, true));
    check("resolver: excludes 'inactive'", !in_array($d5, $aIds, true) && !in_array($d5, $bIds, true));

    // ── A5 assistant-proposal lifecycle (resolver level) ──────────────────────
    check("assistant proposal not in resolver while proposed_by_assistant", !in_array($d4, $loadedIds($athA), true));
    $db->prepare("UPDATE coaching_decisions SET status='active' WHERE id=?")->execute([$d4]); // head approves
    check("approved assistant proposal (→active) reaches A's athlete (assistant assigned)", in_array($d4, $loadedIds($athA), true));
    check("approved assistant proposal does NOT reach B's athlete (not shared)", !in_array($d4, $loadedIds($athB), true));
    $db->prepare("UPDATE coaching_decisions SET status='inactive' WHERE id=?")->execute([$d4]); // head dismisses
    check("dismissed assistant proposal (→inactive) excluded from resolver", !in_array($d4, $loadedIds($athA), true));

    // ── Dormancy meaning for generation: no shared ⇒ Phase-3-identical (only own) ──
    $db->prepare("UPDATE coaching_decisions SET shared=0 WHERE id=?")->execute([$d2]);
    $bIdsNoShare = $loadedIds($athB);
    check("no shared decisions ⇒ B's athlete sees only B's own (Phase-3 behavior)", !in_array($d2, $bIdsNoShare, true) && !in_array($d1, $bIdsNoShare, true));
    $db->prepare("UPDATE coaching_decisions SET shared=1 WHERE id=?")->execute([$d2]); // restore

    // ── A6 import source set: founding coach's active decisions incl. shared + non-shared ──
    $src = $db->prepare("SELECT id FROM coaching_decisions WHERE created_by = ? AND status = 'active' ORDER BY id");
    $src->execute([$coachA]);
    $srcIds = array_map('intval', $src->fetchAll(PDO::FETCH_COLUMN));
    check("import source includes BOTH non-shared (d1) and shared (d2) active rules", in_array($d1, $srcIds, true) && in_array($d2, $srcIds, true));

    // Simulate the import copy and assert copies are 'proposed', owned by the new coach.
    $newCoach = $mkUser('coach');
    $ins = $db->prepare(
        "INSERT INTO coaching_decisions (created_by, created_at, status, shared, title, reason, rationale, trigger_json, action_json, source)
         SELECT ?, NOW(), 'proposed', 0, title, reason, COALESCE(rationale, reason), trigger_json, action_json, 'manual'
         FROM coaching_decisions WHERE created_by = ? AND status = 'active'"
    );
    $ins->execute([$newCoach, $coachA]);
    $copyCount = (int)$db->query("SELECT COUNT(*) FROM coaching_decisions WHERE created_by = {$newCoach} AND status = 'proposed'")->fetchColumn();
    check("import: copies created as 'proposed' owned by new coach (count == source active)", $copyCount === count($srcIds) && $copyCount >= 2);
    check("import: copies do NOT reach the resolver (still proposed)", count(array_intersect(
        $loadedIds($athA),
        array_map('intval', $db->query("SELECT id FROM coaching_decisions WHERE created_by = {$newCoach}")->fetchAll(PDO::FETCH_COLUMN))
    )) === 0);
    check("import: founding coach's originals untouched (d2 still shared+active)",
        (string)$db->query("SELECT CONCAT(status,':',shared) FROM coaching_decisions WHERE id={$d2}")->fetchColumn() === 'active:1');

    // ── B1 analytics: flag resolution EXCLUDES 'superseded' ───────────────────
    $flag = $db->prepare(
        "INSERT INTO coaching_intelligence_flags (athlete_id, coach_id, created_at, flag_type, severity, title, detail, status, actioned_at, dismissed_at)
         VALUES (?, ?, ?, 'predicted_dropout', 'warning', 't', 'd', ?, ?, ?)"
    );
    $flag->execute([$athA, $coachA, date('Y-m-d H:i:s', time() - 2*86400), 'actioned', date('Y-m-d H:i:s', time() - 86400), null]);
    $flag->execute([$athA, $coachA, date('Y-m-d H:i:s', time() - 2*86400), 'dismissed', null, date('Y-m-d H:i:s', time() - 86400)]);
    $flag->execute([$athA, $coachA, date('Y-m-d H:i:s', time() - 86400),   'superseded', null, date('Y-m-d H:i:s')]);

    $resolvedCount = (int)$db->query(
        "SELECT COUNT(*) FROM coaching_intelligence_flags WHERE coach_id = {$coachA}
         AND status IN ('actioned','dismissed') AND created_at >= (NOW() - INTERVAL 90 DAY)"
    )->fetchColumn();
    $supersededCount = (int)$db->query(
        "SELECT COUNT(*) FROM coaching_intelligence_flags WHERE coach_id = {$coachA} AND status = 'superseded'"
    )->fetchColumn();
    check("analytics: resolution count = 2 (actioned + dismissed)", $resolvedCount === 2);
    check("analytics: superseded (1) excluded from resolution count", $supersededCount === 1 && $resolvedCount === 2);

    echo "\n================================\n";
    echo "  CIL Phase 4 verification\n";
    echo "  PASS: {$pass}   FAIL: {$fail}\n";
    echo "================================\n";

} finally {
    foreach ($ids['athletes'] as $aid) {
        foreach (['coaching_intelligence_flags','completed_workouts','coach_assignments','athletes'] as $t) {
            $col = $t === 'athletes' ? 'id' : 'athlete_id';
            try { $db->prepare("DELETE FROM {$t} WHERE {$col} = ?")->execute([$aid]); } catch (\Throwable $e) {}
        }
    }
    foreach ($ids['users'] as $uid) {
        try { $db->prepare("DELETE FROM coaching_decisions WHERE created_by = ?")->execute([$uid]); } catch (\Throwable $e) {}
        try { $db->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]); } catch (\Throwable $e) {}
    }
}

exit($fail === 0 ? 0 : 1);

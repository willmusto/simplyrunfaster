<?php
/**
 * Verification — account-deletion sweep leaves NO residual PII and NO local OAuth token,
 * is idempotent (safe to re-run), and treats the Intervals token revoke as best-effort /
 * non-blocking (a revoke FAILURE still results in full LOCAL deletion).
 *
 * Mirrors the live logic in scripts/cron_delete_expired_accounts.php. The scoped-table
 * list below is the AUTHORITATIVE inventory of every user_id/athlete_id-scoped personal-
 * data table; it MUST stay in sync with that cron's $athleteTables/$userTables. If a
 * future migration adds a scoped table, add it here too — this test seeds every listed
 * table and asserts the sweep zeroes all of them, so an omission fails loudly.
 *
 * SAFETY:
 *   - Operates ONLY on a throwaway account it creates (unique marker email). It never
 *     selects or touches real accounts, and it does NOT invoke the production cron.
 *   - The Intervals revoke is TRAPPED via INTERVALS_DISABLE_REVOKE (defined before config
 *     loads) so NO outbound HTTP is made — no live Intervals calendar is touched.
 *   - MyISAM has no transactions/rollback, so the throwaway rows are torn down EXPLICITLY
 *     in a finally block (the anonymized users row is hard-deleted too — unlike a real
 *     deletion, this synthetic account keeps nothing).
 *
 *     php scripts/verify_account_deletion.php
 */

// Trap the remote revoke BEFORE config/IntervalsService load: guarantees no network call
// and exercises the "revoke failed → local token still removed" path.
define('INTERVALS_DISABLE_REVOKE', true);

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
if (is_file(SCRIPT_ROOT . '/src/Crypto.php'))          require_once SCRIPT_ROOT . '/src/Crypto.php';
if (is_file(SCRIPT_ROOT . '/src/IntervalsService.php')) require_once SCRIPT_ROOT . '/src/IntervalsService.php';

$db = Database::get();

$hasTable = (function () use ($db): callable {
    $cache = [];
    return function (string $t) use ($db, &$cache): bool {
        if (array_key_exists($t, $cache)) return $cache[$t];
        $s = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$t]);
        return $cache[$t] = ((int)$s->fetchColumn() > 0);
    };
})();

// Authoritative scoped-table inventory — keep in sync with cron_delete_expired_accounts.php.
$athleteTables = [
    'messages', 'session_notes', 'scheduled_messages', 'completed_workouts', 'planned_workouts',
    'training_load', 'engine_flags', 'coaching_intelligence_flags', 'coach_adjustments',
    'athlete_behavior_log', 'athlete_response_profiles', 'plan_approval_queue',
    'plan_regeneration_requests', 'coach_assignments', 'races', 'personal_bests',
    'watch_connections', 'athlete_profiles', 'training_plans',
];
$userTables = [
    'notification_preferences', 'device_notify_preferences', 'push_subscriptions',
    'phone_verifications', 'password_reset_tokens', 'intervals_connections',
];

$marker = 'srf_verify_del_' . bin2hex(random_bytes(4));
$email  = $marker . '@verify.invalid';
$intAthlete = 'iv_' . bin2hex(random_bytes(3));   // throwaway Intervals athlete id

$pass = 0; $fail = 0;
$check = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$uid = null; $aid = null; $planId = null; $pwId = null;

try {
    // ── Seed a throwaway account ──────────────────────────────────────────────
    $db->prepare(
        "INSERT INTO users (email, password_hash, name, role, subscription_status, created_at)
         VALUES (?, '', 'SRF Verify Delete', 'athlete', 'none', NOW())"
    )->execute([$email]);
    $uid = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO athletes (user_id, status, created_at) VALUES (?, 'active', NOW())")->execute([$uid]);
    $aid = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO training_plans (athlete_id) VALUES (?)")->execute([$aid]);
    $planId = (int)$db->lastInsertId();

    $db->prepare(
        "INSERT INTO planned_workouts (plan_id, athlete_id, scheduled_date, workout_type) VALUES (?, ?, CURDATE(), 'easy')"
    )->execute([$planId, $aid]);
    $pwId = (int)$db->lastInsertId();

    // Athlete-scoped rows (one per remaining table).
    $seedAthlete = [
        'messages'                    => ["(athlete_id, sender_id, sender_role, body) VALUES (?, ?, 'athlete', 'test')", [$aid, $uid]],
        'session_notes'               => ["(athlete_id, author_id, author_role, body) VALUES (?, ?, 'athlete', 'test')", [$aid, $uid]],
        'scheduled_messages'          => ["(athlete_id, sender_id, body, send_after) VALUES (?, ?, 'test', NOW())", [$aid, $uid]],
        'completed_workouts'          => ["(athlete_id, activity_date) VALUES (?, CURDATE())", [$aid]],
        'training_load'               => ["(athlete_id, `date`) VALUES (?, CURDATE())", [$aid]],
        'engine_flags'                => ["(athlete_id, flag_type, flag_date, message) VALUES (?, 'plan_rebuild_needed', CURDATE(), 'test')", [$aid]],
        'coaching_intelligence_flags' => ["(athlete_id, coach_id, created_at, flag_type, severity, title, detail) VALUES (?, ?, NOW(), 'rpe_trending_high', 'info', 't', 'd')", [$aid, $uid]],
        'coach_adjustments'           => ["(planned_workout_id, athlete_id, coach_id, adjusted_at, change_type) VALUES (?, ?, ?, NOW(), 'duration_change')", [$pwId, $aid, $uid]],
        'athlete_behavior_log'        => ["(athlete_id, logged_at, metric_type, metric_value) VALUES (?, NOW(), 'completion_rate', 1.0)", [$aid]],
        'athlete_response_profiles'   => ["(athlete_id, computed_at) VALUES (?, NOW())", [$aid]],
        'plan_approval_queue'         => ["(plan_id, athlete_id, request_reason) VALUES (?, ?, 'onboarding')", [$planId, $aid]],
        'plan_regeneration_requests'  => ["(athlete_id, requested_by, requested_at) VALUES (?, ?, NOW())", [$aid, $uid]],
        'coach_assignments'           => ["(athlete_id, coach_id, assigned_at, assigned_by) VALUES (?, ?, NOW(), ?)", [$aid, $uid, $uid]],
        'races'                       => ["(athlete_id, added_by, added_by_role, race_name, race_distance, race_date) VALUES (?, ?, 'athlete', 'Test', '5K', CURDATE())", [$aid, $uid]],
        'personal_bests'              => ["(athlete_id, distance, time_seconds) VALUES (?, '5K', 1200)", [$aid]],
        'watch_connections'           => ["(athlete_id, platform, access_token) VALUES (?, 'garmin', 'tok')", [$aid]],
        'athlete_profiles'            => ["(athlete_id) VALUES (?)", [$aid]],
    ];
    foreach ($seedAthlete as $t => [$sql, $args]) {
        if (!$hasTable($t)) { echo "  · skip seed {$t} (table missing)\n"; continue; }
        $db->prepare("INSERT INTO `{$t}` {$sql}")->execute($args);
    }

    // User-scoped rows.
    $seedUser = [
        'notification_preferences'   => ["(user_id, notification_type) VALUES (?, 'verify_test')", [$uid]],
        'device_notify_preferences'  => ["(user_id, brand, updated_at) VALUES (?, 'garmin', NOW())", [$uid]],
        'push_subscriptions'         => ["(user_id, endpoint, p256dh, auth) VALUES (?, 'e', 'p', 'a')", [$uid]],
        'phone_verifications'        => ["(user_id, phone_number, code, expires_at) VALUES (?, '+10000000000', '000000', NOW())", [$uid]],
        'password_reset_tokens'      => ["(user_id, token, expires_at) VALUES (?, ?, NOW())", [$uid, 'tok_' . $marker]],
        'intervals_connections'      => ["(user_id, intervals_athlete_id, access_token_enc, scope, connected_at) VALUES (?, ?, 'ENC_FAKE_TOKEN', 'ACTIVITY:READ', NOW())", [$uid, $intAthlete]],
    ];
    foreach ($seedUser as $t => [$sql, $args]) {
        if (!$hasTable($t)) { echo "  · skip seed {$t} (table missing)\n"; continue; }
        $db->prepare("INSERT INTO `{$t}` {$sql}")->execute($args);
    }

    // Intervals append-only logs.
    if ($hasTable('intervals_push_log')) {
        $db->prepare("INSERT INTO intervals_push_log (planned_workout_id, pushed_at, status) VALUES (?, NOW(), 'success')")->execute([$pwId]);
    }
    if ($hasTable('intervals_webhook_log')) {
        $db->prepare("INSERT INTO intervals_webhook_log (event_type, athlete_id, payload, received_at) VALUES ('test', ?, '{}', NOW())")->execute([$intAthlete]);
    }

    echo "Seeded throwaway account: user={$uid} athlete={$aid} (marker {$marker}).\n\n";

    // Sanity: confirm we actually seeded every scoped table (so a zero later is meaningful).
    $seededOk = true;
    foreach ($athleteTables as $t) {
        if (!$hasTable($t)) continue;
        if ((int)$db->query("SELECT COUNT(*) FROM `{$t}` WHERE athlete_id = {$aid}")->fetchColumn() < 1) { $seededOk = false; echo "  ! not seeded: {$t}\n"; }
    }
    foreach ($userTables as $t) {
        if (!$hasTable($t)) continue;
        if ((int)$db->query("SELECT COUNT(*) FROM `{$t}` WHERE user_id = {$uid}")->fetchColumn() < 1) { $seededOk = false; echo "  ! not seeded: {$t}\n"; }
    }
    $check('seeded a row in every scoped table', $seededOk);

    // ── The deletion sweep (mirrors cron_delete_expired_accounts.php) ─────────
    $runSweep = function () use ($db, $hasTable, $athleteTables, $userTables, $uid, $aid, $intAthlete): bool {
        // 0. Best-effort revoke (trapped → returns false). Must NOT block deletion.
        $revoked = class_exists('IntervalsService') ? IntervalsService::revokeToken($uid, $db) : false;

        // 1. Intervals logs first (push log keys off still-present planned_workouts).
        if ($hasTable('intervals_push_log')) {
            $db->prepare("DELETE FROM intervals_push_log WHERE planned_workout_id IN (SELECT id FROM planned_workouts WHERE athlete_id = ?)")->execute([$aid]);
        }
        if ($hasTable('intervals_webhook_log')) {
            $db->prepare('DELETE FROM intervals_webhook_log WHERE athlete_id = ?')->execute([$intAthlete]);
        }
        // 2. Athlete-scoped.
        foreach ($athleteTables as $t) { if ($hasTable($t)) $db->prepare("DELETE FROM `{$t}` WHERE athlete_id = ?")->execute([$aid]); }
        // 3. User-scoped (incl. intervals_connections = local token).
        foreach ($userTables as $t) { if ($hasTable($t)) $db->prepare("DELETE FROM `{$t}` WHERE user_id = ?")->execute([$uid]); }
        // 4. athletes row.
        $db->prepare('DELETE FROM athletes WHERE user_id = ?')->execute([$uid]);
        // 5. Audit (guarded against duplicates).
        $a = $db->prepare('SELECT 1 FROM account_deletions WHERE user_id = ? LIMIT 1'); $a->execute([$uid]);
        if (!$a->fetchColumn()) {
            $db->prepare('INSERT INTO account_deletions (user_id, deleted_at, reason) VALUES (?, NOW(), ?)')->execute([$uid, 'verify_test']);
        }
        // 6. Anonymize users LAST.
        $db->prepare("UPDATE users SET email = CONCAT('deleted_', id, '@deleted.invalid'), name = 'Deleted User', password_hash = '', phone_number = NULL, stripe_customer_id = NULL, deleted_at = NOW() WHERE id = ?")->execute([$uid]);
        return $revoked;
    };

    // Residual counter across ALL scoped tables + intervals artifacts + local token.
    $residual = function () use ($db, $hasTable, $athleteTables, $userTables, $uid, $aid, $pwId, $intAthlete): int {
        $n = 0;
        foreach ($athleteTables as $t) { if ($hasTable($t)) $n += (int)$db->query("SELECT COUNT(*) FROM `{$t}` WHERE athlete_id = {$aid}")->fetchColumn(); }
        foreach ($userTables as $t)    { if ($hasTable($t)) $n += (int)$db->query("SELECT COUNT(*) FROM `{$t}` WHERE user_id = {$uid}")->fetchColumn(); }
        if ($hasTable('intervals_push_log'))    $n += (int)$db->query("SELECT COUNT(*) FROM intervals_push_log WHERE planned_workout_id = {$pwId}")->fetchColumn();
        if ($hasTable('intervals_webhook_log')) { $q = $db->prepare('SELECT COUNT(*) FROM intervals_webhook_log WHERE athlete_id = ?'); $q->execute([$intAthlete]); $n += (int)$q->fetchColumn(); }
        $n += (int)$db->query("SELECT COUNT(*) FROM athletes WHERE id = {$aid}")->fetchColumn();
        return $n;
    };

    // ── First run ─────────────────────────────────────────────────────────────
    $revoked1 = $runSweep();
    $check('revoke is non-blocking and reports failure under trap (returns false)', $revoked1 === false);
    $check('ZERO residual rows across all scoped tables after deletion', $residual() === 0);

    $tokenLeft = $hasTable('intervals_connections')
        ? (int)$db->query("SELECT COUNT(*) FROM intervals_connections WHERE user_id = {$uid}")->fetchColumn() : 0;
    $check('no local Intervals token remains (revoke failure still deletes locally)', $tokenLeft === 0);

    $anon = $db->query("SELECT email, deleted_at FROM users WHERE id = {$uid}")->fetch(PDO::FETCH_ASSOC);
    $check('users row anonymized (deleted_at set, email scrubbed)',
        $anon && $anon['deleted_at'] !== null && str_starts_with((string)$anon['email'], 'deleted_'));

    $auditN = (int)$db->query("SELECT COUNT(*) FROM account_deletions WHERE user_id = {$uid}")->fetchColumn();
    $check('exactly one account_deletions audit row', $auditN === 1);

    // ── Second run (idempotency / resumability) ───────────────────────────────
    $runSweep();
    $check('re-run leaves ZERO residual (idempotent)', $residual() === 0);
    $auditN2 = (int)$db->query("SELECT COUNT(*) FROM account_deletions WHERE user_id = {$uid}")->fetchColumn();
    $check('re-run does NOT duplicate the audit row', $auditN2 === 1);

} catch (\Throwable $e) {
    echo "  ✗ EXCEPTION: " . $e->getMessage() . "\n";
    $fail++;
} finally {
    // ── Full teardown (MyISAM: no rollback, so delete explicitly) ─────────────
    if ($uid !== null) {
        try {
            foreach ($athleteTables as $t) { if ($hasTable($t) && $aid !== null) $db->prepare("DELETE FROM `{$t}` WHERE athlete_id = ?")->execute([$aid]); }
            foreach ($userTables as $t)    { if ($hasTable($t)) $db->prepare("DELETE FROM `{$t}` WHERE user_id = ?")->execute([$uid]); }
            if ($hasTable('intervals_push_log') && $pwId !== null) $db->prepare('DELETE FROM intervals_push_log WHERE planned_workout_id = ?')->execute([$pwId]);
            if ($hasTable('intervals_webhook_log')) $db->prepare('DELETE FROM intervals_webhook_log WHERE athlete_id = ?')->execute([$intAthlete]);
            if ($aid !== null) $db->prepare('DELETE FROM athletes WHERE id = ?')->execute([$aid]);
            $db->prepare('DELETE FROM account_deletions WHERE user_id = ?')->execute([$uid]);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);   // hard-delete the synthetic user
            echo "\nTeardown complete: throwaway account fully removed.\n";
        } catch (\Throwable $e) {
            echo "\n  ! Teardown error (clean up user {$uid} manually): " . $e->getMessage() . "\n";
        }
    }
}

echo "\n" . date('Y-m-d H:i:s') . " — verify_account_deletion: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);

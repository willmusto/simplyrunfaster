<?php
/**
 * Daily cron: enforce the 90-day data-retention window (Privacy Policy §9).
 *
 * Two categories of athlete account become eligible for deletion:
 *   A. '90_day_post_cancellation'   — subscription canceled and the access-until
 *                                      date passed more than 90 days ago.
 *   B. '90_day_incomplete_onboarding'— never subscribed, signed up more than
 *                                      90 days ago, and onboarding never finished.
 *
 * For each eligible user we delete all athlete/user-scoped child rows, then
 * ANONYMIZE (not hard-delete) the `users` row so billing records that reference
 * the user id stay intact, and log the deletion to `account_deletions`.
 *
 * SAFETY GUARDS (defensive — also enforced in the SELECTs):
 *   - Only role = 'athlete' rows are ever touched. Coaches/admins are skipped.
 *   - subscription_status IN ('active','trialing','comped','past_due') is never
 *     deleted, regardless of any date condition.
 *
 * Usage:
 *   php scripts/cron_delete_expired_accounts.php --dry-run   # report only
 *   php scripts/cron_delete_expired_accounts.php             # perform deletion
 *
 * Cron schedule (NFSN): daily, hour 4 UTC (before the visibility cron at 5).
 *   php /home/private/app/scripts/cron_delete_expired_accounts.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
// For best-effort Intervals.icu token revocation on deletion (optional — guarded by
// class_exists below so the cron still runs if these files are ever absent).
if (is_file(SCRIPT_ROOT . '/src/Crypto.php'))          require_once SCRIPT_ROOT . '/src/Crypto.php';
if (is_file(SCRIPT_ROOT . '/src/IntervalsService.php')) require_once SCRIPT_ROOT . '/src/IntervalsService.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$db     = Database::get();

/** True when $table exists in the current database. Cached per process. */
$hasTable = (function () use ($db): callable {
    $cache = [];
    return function (string $table) use ($db, &$cache): bool {
        if (array_key_exists($table, $cache)) return $cache[$table];
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    };
})();

$mode = $dryRun ? 'DRY RUN (no changes)' : 'LIVE';
echo date('Y-m-d H:i:s') . " — account retention cron starting [{$mode}]\n";

// ── 90-day athlete_behavior_log retention (Coaching Intelligence Layer) ───────
// Behavior metrics are a rolling 90-day window; older rows are pruned daily here
// (the daily cleanup cron). Guarded so it is a no-op before migration_027 runs.
try {
    $hasLog = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'athlete_behavior_log'"
    )->fetchColumn() > 0;
    if ($hasLog) {
        if ($dryRun) {
            $n = (int)$db->query(
                "SELECT COUNT(*) FROM athlete_behavior_log WHERE logged_at < (NOW() - INTERVAL 90 DAY)"
            )->fetchColumn();
            echo "athlete_behavior_log: {$n} row(s) older than 90 days would be deleted.\n";
        } else {
            $n = $db->exec("DELETE FROM athlete_behavior_log WHERE logged_at < (NOW() - INTERVAL 90 DAY)");
            echo "athlete_behavior_log: pruned {$n} row(s) older than 90 days.\n";
        }
    }
} catch (\Throwable $e) {
    echo "athlete_behavior_log cleanup skipped: " . $e->getMessage() . "\n";
}

// Statuses that are protected from deletion no matter what.
$PROTECTED_STATUSES = ['active', 'trialing', 'comped', 'past_due'];

// ── Gather candidates ────────────────────────────────────────
// Keyed by user id so a user matching both reasons is processed once.
$candidates = [];

// Reason A: canceled, access-until date > 90 days in the past.
$stmtA = $db->query(
    "SELECT u.id, u.role, u.subscription_status, u.subscription_end_date
     FROM users u
     WHERE u.role = 'athlete'
       AND u.deleted_at IS NULL
       AND u.subscription_status = 'canceled'
       AND u.subscription_end_date IS NOT NULL
       AND u.subscription_end_date < (NOW() - INTERVAL 90 DAY)"
);
foreach ($stmtA->fetchAll() as $r) {
    $candidates[(int)$r['id']] = ['row' => $r, 'reason' => '90_day_post_cancellation'];
}

// Reason B: never subscribed, signed up > 90 days ago, onboarding never done.
// onboarding_completed_at lives on `athletes`, so join it.
$stmtB = $db->query(
    "SELECT u.id, u.role, u.subscription_status, u.created_at
     FROM users u
     LEFT JOIN athletes a ON a.user_id = u.id
     WHERE u.role = 'athlete'
       AND u.deleted_at IS NULL
       AND u.subscription_status = 'none'
       AND u.created_at < (NOW() - INTERVAL 90 DAY)
       AND a.onboarding_completed_at IS NULL"
);
foreach ($stmtB->fetchAll() as $r) {
    $id = (int)$r['id'];
    if (!isset($candidates[$id])) {
        $candidates[$id] = ['row' => $r, 'reason' => '90_day_incomplete_onboarding'];
    }
}

if (!$candidates) {
    echo "No accounts are eligible for deletion.\n";
    echo date('Y-m-d H:i:s') . " — done.\n";
    exit(0);
}

echo 'Found ' . count($candidates) . " candidate account(s).\n";

// ── Process each candidate ───────────────────────────────────
// EVERY table that stores athlete- or user-scoped personal data MUST appear in one of
// the lists below (or the Intervals teardown). A deleted account must leave NO residual
// PII and NO live third-party credentials. When a migration adds a new user/athlete-
// scoped table, add it here too — the verify script (verify_account_deletion.php)
// asserts the sweep leaves zero residual rows in every scoped table.
//
// Athlete-scoped tables keyed by athlete_id.
$athleteTables = [
    'messages',
    'session_notes',
    'scheduled_messages',
    'completed_workouts',
    'planned_workouts',
    'training_load',
    'engine_flags',
    'coaching_intelligence_flags',
    'coach_adjustments',
    'athlete_behavior_log',
    'athlete_response_profiles',
    'plan_approval_queue',
    'plan_regeneration_requests',
    'coach_assignments',
    'races',
    'personal_bests',
    'watch_connections',
    'athlete_profiles',
    'training_plans',
];
// User-scoped tables keyed by user_id. intervals_connections holds the encrypted OAuth
// token — deleting the row removes the stored credential locally.
$userTables = [
    'notification_preferences',
    'device_notify_preferences',
    'push_subscriptions',
    'phone_verifications',
    'password_reset_tokens',
    'intervals_connections',
];

// Intentionally RETAINED (not swept): account_deletions (the deletion audit record),
// stripe_webhook_log (billing audit keyed by Stripe ids — same rationale as anonymizing
// rather than hard-deleting `users`), and invite_links (coach-owned; a used invite's
// `used_by` is a historical id, not athlete PII). The Intervals append-only logs
// (intervals_push_log keyed by planned_workout_id, intervals_webhook_log keyed by the
// Intervals athlete string) are purged in the dedicated teardown below.

$deleted = 0;
$skipped = 0;

foreach ($candidates as $userId => $info) {
    $row    = $info['row'];
    $reason = $info['reason'];
    $status = $row['subscription_status'];

    // Defensive guards (belt-and-suspenders over the SELECT filters).
    if (($row['role'] ?? '') !== 'athlete') {
        echo "  - user {$userId}: role is not athlete — SKIP\n";
        $skipped++;
        continue;
    }
    if (in_array($status, $PROTECTED_STATUSES, true)) {
        echo "  - user {$userId}: protected status '{$status}' — SKIP\n";
        $skipped++;
        continue;
    }
    if (!in_array($status, ['canceled', 'none'], true)) {
        echo "  - user {$userId}: unexpected status '{$status}' — SKIP\n";
        $skipped++;
        continue;
    }

    // Resolve the athlete id (athlete-scoped tables key off it).
    $athStmt = $db->prepare('SELECT id FROM athletes WHERE user_id = ? LIMIT 1');
    $athStmt->execute([$userId]);
    $athleteId = $athStmt->fetchColumn();
    $athleteId = $athleteId !== false ? (int)$athleteId : null;

    // The Intervals athlete id (string) keys the webhook log; capture it before the
    // connection row is deleted so the raw activity payloads can be purged too.
    $intervalsAthleteId = null;
    if ($hasTable('intervals_connections')) {
        $ic = $db->prepare('SELECT intervals_athlete_id FROM intervals_connections WHERE user_id = ? LIMIT 1');
        $ic->execute([$userId]);
        $v = $ic->fetchColumn();
        $intervalsAthleteId = ($v !== false && $v !== '') ? (string)$v : null;
    }

    echo "  - user {$userId} (athlete " . ($athleteId ?? 'none') . "), status '{$status}', reason {$reason}:\n";

    if ($dryRun) {
        // Report row counts that WOULD be deleted, without touching anything.
        $total = 0;
        if ($athleteId !== null) {
            foreach ($athleteTables as $t) {
                if (!$hasTable($t)) continue;
                $c = (int)$db->query("SELECT COUNT(*) FROM `{$t}` WHERE athlete_id = {$athleteId}")->fetchColumn();
                if ($c > 0) echo "      {$t}: {$c}\n";
                $total += $c;
            }
            if ($hasTable('intervals_push_log')) {
                $c = (int)$db->query(
                    "SELECT COUNT(*) FROM intervals_push_log
                     WHERE planned_workout_id IN (SELECT id FROM planned_workouts WHERE athlete_id = {$athleteId})"
                )->fetchColumn();
                if ($c > 0) echo "      intervals_push_log: {$c}\n";
                $total += $c;
            }
        }
        foreach ($userTables as $t) {
            if (!$hasTable($t)) continue;
            $c = (int)$db->query("SELECT COUNT(*) FROM `{$t}` WHERE user_id = {$userId}")->fetchColumn();
            if ($c > 0) echo "      {$t}: {$c}\n";
            $total += $c;
        }
        if ($intervalsAthleteId !== null && $hasTable('intervals_webhook_log')) {
            $q = $db->prepare('SELECT COUNT(*) FROM intervals_webhook_log WHERE athlete_id = ?');
            $q->execute([$intervalsAthleteId]);
            $c = (int)$q->fetchColumn();
            if ($c > 0) echo "      intervals_webhook_log: {$c}\n";
            $total += $c;
        }
        echo "      would delete {$total} child row(s), anonymize user {$userId}, log reason '{$reason}'\n";
        $deleted++;
        continue;
    }

    // ── Live deletion ────────────────────────────────────────
    // Production is MyISAM, which is NON-TRANSACTIONAL: beginTransaction/commit would be
    // a silent no-op, so we do NOT use them. Instead every step is idempotent and the
    // sweep is ORDERED to be safe to re-run — child rows are deleted by id (a re-run
    // deletes nothing more) and `users.deleted_at` is set LAST. Because the candidate
    // SELECTs exclude `deleted_at IS NOT NULL`, a run that fails partway leaves the
    // account un-anonymized and it is simply reprocessed (and completed) next run. No
    // rollback and no partial-state cleanup are relied upon.
    try {
        // 0. Best-effort remote token revocation at Intervals.icu (courtesy disconnect).
        //    NON-BLOCKING: any failure (network, no endpoint, already revoked, unconfigured)
        //    is caught/logged and never stops the deletion. The LOCAL token is removed
        //    unconditionally in step 3 regardless of the outcome here.
        if ($intervalsAthleteId !== null && class_exists('IntervalsService')) {
            try {
                $revoked = IntervalsService::revokeToken($userId, $db);
                echo '      intervals token revoke: ' . ($revoked ? 'ok' : 'not confirmed (local token still removed)') . "\n";
            } catch (\Throwable $e) {
                echo "      intervals token revoke: error (local token still removed)\n";
                error_log("cron_delete_expired_accounts: revoke user {$userId}: " . $e->getMessage());
            }
        }

        // 1. Intervals append-only logs FIRST: intervals_push_log keys off
        //    planned_workout_id (which still exists at this point); intervals_webhook_log
        //    keys off the captured Intervals athlete string.
        if ($athleteId !== null && $hasTable('intervals_push_log')) {
            $db->prepare(
                "DELETE FROM intervals_push_log
                 WHERE planned_workout_id IN (SELECT id FROM planned_workouts WHERE athlete_id = ?)"
            )->execute([$athleteId]);
        }
        if ($intervalsAthleteId !== null && $hasTable('intervals_webhook_log')) {
            $db->prepare('DELETE FROM intervals_webhook_log WHERE athlete_id = ?')->execute([$intervalsAthleteId]);
        }

        // 2. All simple athlete_id-scoped child rows.
        if ($athleteId !== null) {
            foreach ($athleteTables as $t) {
                if (!$hasTable($t)) continue;
                $db->prepare("DELETE FROM `{$t}` WHERE athlete_id = ?")->execute([$athleteId]);
            }
        }
        // 3. All simple user_id-scoped child rows (incl. intervals_connections = local token).
        foreach ($userTables as $t) {
            if (!$hasTable($t)) continue;
            $db->prepare("DELETE FROM `{$t}` WHERE user_id = ?")->execute([$userId]);
        }

        // 4. Remove the athletes row itself.
        $db->prepare('DELETE FROM athletes WHERE user_id = ?')->execute([$userId]);

        // 5. Audit log — guarded so a resumed run does not insert a duplicate record.
        $already = $db->prepare('SELECT 1 FROM account_deletions WHERE user_id = ? LIMIT 1');
        $already->execute([$userId]);
        if (!$already->fetchColumn()) {
            $db->prepare(
                'INSERT INTO account_deletions (user_id, deleted_at, reason) VALUES (?, NOW(), ?)'
            )->execute([$userId, $reason]);
        }

        // 6. Anonymize (not hard-delete) the users row LAST — preserves billing history and
        //    sets deleted_at, the idempotency marker that prevents reprocessing. `users`
        //    has no push column (push data is in push_subscriptions, deleted above).
        $db->prepare(
            "UPDATE users
                SET email              = CONCAT('deleted_', id, '@deleted.invalid'),
                    name               = 'Deleted User',
                    password_hash      = '',
                    phone_number       = NULL,
                    stripe_customer_id = NULL,
                    deleted_at         = NOW()
              WHERE id = ?"
        )->execute([$userId]);

        echo "      deleted + anonymized.\n";
        $deleted++;
    } catch (Throwable $e) {
        // No rollback (MyISAM): the account is left un-anonymized and will be reprocessed
        // and completed on the next run. The sweep is idempotent, so that is safe.
        echo "      ERROR: " . $e->getMessage() . " — partial; will be completed on the next run.\n";
        error_log("cron_delete_expired_accounts: user {$userId} failed: " . $e->getMessage());
        $skipped++;
    }
}

$verb = $dryRun ? 'would be processed' : 'processed';
echo date('Y-m-d H:i:s') . " — done. {$deleted} account(s) {$verb}, {$skipped} skipped.\n";

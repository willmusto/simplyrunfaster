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

$dryRun = in_array('--dry-run', $argv ?? [], true);
$db     = Database::get();

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
// Athlete-scoped tables keyed by athlete_id, in the spec-defined order.
$athleteTables = [
    'messages',
    'session_notes',
    'completed_workouts',
    'planned_workouts',
    'athlete_profiles',
    'engine_flags',
    'training_plans',
];
// User-scoped tables keyed by user_id.
$userTables = [
    'notification_preferences',
    'device_notify_preferences',
    'push_subscriptions',
];

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

    echo "  - user {$userId} (athlete " . ($athleteId ?? 'none') . "), status '{$status}', reason {$reason}:\n";

    if ($dryRun) {
        // Report row counts that WOULD be deleted, without touching anything.
        $total = 0;
        if ($athleteId !== null) {
            foreach ($athleteTables as $t) {
                $c = (int)$db->query("SELECT COUNT(*) FROM `{$t}` WHERE athlete_id = {$athleteId}")->fetchColumn();
                if ($c > 0) echo "      {$t}: {$c}\n";
                $total += $c;
            }
        }
        foreach ($userTables as $t) {
            $c = (int)$db->query("SELECT COUNT(*) FROM `{$t}` WHERE user_id = {$userId}")->fetchColumn();
            if ($c > 0) echo "      {$t}: {$c}\n";
            $total += $c;
        }
        echo "      would delete {$total} child row(s), anonymize user {$userId}, log reason '{$reason}'\n";
        $deleted++;
        continue;
    }

    // ── Live deletion, atomic per user ───────────────────────
    try {
        $db->beginTransaction();

        if ($athleteId !== null) {
            foreach ($athleteTables as $t) {
                $s = $db->prepare("DELETE FROM `{$t}` WHERE athlete_id = ?");
                $s->execute([$athleteId]);
            }
        }
        foreach ($userTables as $t) {
            $s = $db->prepare("DELETE FROM `{$t}` WHERE user_id = ?");
            $s->execute([$userId]);
        }

        // Remove the athletes row itself.
        $db->prepare('DELETE FROM athletes WHERE user_id = ?')->execute([$userId]);

        // Anonymize (not hard-delete) the users row to preserve billing history.
        // NOTE: `users` has no push_subscription column (push data lives in the
        // push_subscriptions table, deleted above), so it is not referenced here.
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

        // Audit log.
        $db->prepare(
            'INSERT INTO account_deletions (user_id, deleted_at, reason) VALUES (?, NOW(), ?)'
        )->execute([$userId, $reason]);

        $db->commit();
        echo "      deleted + anonymized.\n";
        $deleted++;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo "      ERROR: " . $e->getMessage() . " — left untouched.\n";
        error_log("cron_delete_expired_accounts: user {$userId} failed: " . $e->getMessage());
        $skipped++;
    }
}

$verb = $dryRun ? 'would be processed' : 'processed';
echo date('Y-m-d H:i:s') . " — done. {$deleted} account(s) {$verb}, {$skipped} skipped.\n";

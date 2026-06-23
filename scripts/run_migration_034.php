<?php
/**
 * Migration 034 runner — drop confirmed-dead schema (GATED; run only after confirmation).
 *
 * Removes items that have ZERO data AND ZERO code usage (verified 2026-06-23):
 *   1. athlete_behavior_log.metric_type — remove dead ENUM members 'easy_pace_drift','response_time'
 *      (kept members: rpe_vs_target, completion_rate, engagement_score).
 *   2. coach_adjustments.reason_tag — drop column (no writers/readers anywhere; table empty).
 *   3. athletes legacy billing columns superseded by users.* (migration 010):
 *      stripe_customer_id, stripe_subscription_id, comp_reason, trial_ends_at, billing_notes.
 *      KEEP athletes.billing_status — it is still WRITTEN (Auth.php, AdminController.php) and
 *      READ (CoachController.php); it is NOT dead.
 *
 * DECISION 2026-06-23: apply ONLY the metric_type ENUM cleanup by default. The DROP COLUMNs
 * (reason_tag + the 4 dead athletes billing columns) are HELD — on MariaDB 5.3/MyISAM a
 * DROP COLUMN forces a full table rebuild, which is real risk on the central `athletes`
 * table for zero functional benefit (dead columns cost nothing in place). They remain
 * available behind an explicit --with-drops flag if that calculus changes.
 *
 * SAFETY:
 *   - Idempotent: each op is skipped if already applied (column gone / ENUM member absent).
 *   - No-row-uses guard: a destructive op is SKIPPED (with a warning) if any row would be
 *     affected — never silently coerce data (MyISAM ENUM hazard).
 *   - Per-op try/catch: MariaDB 5.3 may reject DROP COLUMN; a failure is reported, not fatal,
 *     and does not block the other ops.
 *   - Dry-run: report what it WOULD do without changing anything.
 *
 *     php scripts/run_migration_034.php --dry-run               # report only (ENUM-only scope)
 *     php scripts/run_migration_034.php                         # apply metric_type ENUM cleanup
 *     php scripts/run_migration_034.php --with-drops            # also drop the dead columns
 *     php scripts/run_migration_034.php --with-drops --dry-run  # report incl. the held drops
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$dryRun    = in_array('--dry-run', $argv ?? [], true);
$withDrops = in_array('--with-drops', $argv ?? [], true);  // column DROPs are HELD by default (MariaDB 5.3 rebuild risk)
$db        = Database::get();

$hasColumn = function (string $t, string $c) use ($db): bool {
    $s = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$t, $c]);
    return (int)$s->fetchColumn() > 0;
};
$columnType = function (string $t, string $c) use ($db): string {
    $s = $db->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$t, $c]);
    return (string)($s->fetchColumn() ?: '');
};
$cnt = function (string $sql) use ($db): int { return (int)$db->query($sql)->fetchColumn(); };
$try = function (string $what, string $sql) use ($db, $dryRun): void {
    if ($dryRun) { echo "  [dry] would: {$what}\n"; return; }
    try { $db->exec($sql); echo "  [ok]  {$what}\n"; }
    catch (\Throwable $e) { echo "  [ERR] {$what} — {$e->getMessage()}\n"; }
};

echo date('Y-m-d H:i:s') . ' — migration 034 ' . ($dryRun ? '(DRY RUN)' : '(APPLY)') . "\n";

// 1) athlete_behavior_log.metric_type — drop dead members if unused.
if ($hasColumn('athlete_behavior_log', 'metric_type')) {
    $type = $columnType('athlete_behavior_log', 'metric_type');
    $hasDead = (strpos($type, "'easy_pace_drift'") !== false) || (strpos($type, "'response_time'") !== false);
    if (!$hasDead) {
        echo "  [skip] metric_type dead members already removed.\n";
    } else {
        $inUse = $cnt("SELECT COUNT(*) FROM athlete_behavior_log WHERE metric_type IN ('easy_pace_drift','response_time')");
        if ($inUse > 0) {
            echo "  [WARN] metric_type: {$inUse} row(s) use a dead member — SKIP (re-check before removing).\n";
        } else {
            $try("MODIFY athlete_behavior_log.metric_type → drop easy_pace_drift,response_time",
                "ALTER TABLE `athlete_behavior_log` MODIFY COLUMN `metric_type`
                 ENUM('rpe_vs_target','completion_rate','engagement_score') NOT NULL");
        }
    }
}

// Column DROPs (2 & 3) are HELD by default — pass --with-drops to apply.
if (!$withDrops) {
    echo "  [hold] column drops deferred (reason_tag + 5 dead athletes billing cols). "
        . "Run with --with-drops to apply (MariaDB 5.3 DROP COLUMN = table rebuild).\n";
} else {
    // 2) coach_adjustments.reason_tag — drop column if unused.
    if (!$hasColumn('coach_adjustments', 'reason_tag')) {
        echo "  [skip] coach_adjustments.reason_tag already dropped.\n";
    } else {
        $inUse = $cnt("SELECT COUNT(*) FROM coach_adjustments WHERE reason_tag IS NOT NULL AND reason_tag <> ''");
        if ($inUse > 0) echo "  [WARN] reason_tag: {$inUse} row(s) populated — SKIP.\n";
        else $try("DROP coach_adjustments.reason_tag", "ALTER TABLE `coach_adjustments` DROP COLUMN `reason_tag`");
    }

    // 3) athletes legacy billing columns (KEEP billing_status).
    $deadBilling = [
        'stripe_customer_id'     => "stripe_customer_id IS NOT NULL",
        'stripe_subscription_id' => "stripe_subscription_id IS NOT NULL",
        'comp_reason'            => "comp_reason IS NOT NULL",
        'trial_ends_at'          => "trial_ends_at IS NOT NULL",
        'billing_notes'          => "billing_notes IS NOT NULL",
    ];
    foreach ($deadBilling as $col => $usedWhere) {
        if (!$hasColumn('athletes', $col)) { echo "  [skip] athletes.{$col} already dropped.\n"; continue; }
        $inUse = $cnt("SELECT COUNT(*) FROM athletes WHERE {$usedWhere}");
        if ($inUse > 0) echo "  [WARN] athletes.{$col}: {$inUse} row(s) populated — SKIP.\n";
        else $try("DROP athletes.{$col}", "ALTER TABLE `athletes` DROP COLUMN `{$col}`");
    }
}

echo date('Y-m-d H:i:s') . " — migration 034 " . ($dryRun ? 'dry-run complete (nothing changed).' : 'complete.') . "\n";

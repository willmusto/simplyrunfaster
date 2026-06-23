<?php
/**
 * Data-retention cron — ONE mechanism, one policy table. Prunes append-only / closed /
 * soft-deleted rows that otherwise grow without bound (see _specs/db_debt_audit.md §3).
 *
 * GATED: run with --dry-run to report EXACTLY how many rows each policy WOULD delete, per
 * table, and touch nothing. Only a plain run (no --dry-run) deletes. Confirm the windows
 * and the dry-run counts before scheduling a real run.
 *
 *   php scripts/cron_data_retention.php --dry-run    # report only, deletes nothing
 *   php scripts/cron_data_retention.php              # apply
 *
 * EXTENSIBLE: add a new policy by appending one entry to $policies below. This is the
 * intended home for generate-to-draft's future draft-TTL sweep — e.g. a 'plan_drafts'
 * policy ['table'=>'plan_drafts','where'=>"created_at < (NOW() - INTERVAL {$W['plan_drafts']} DAY)"].
 *
 * MyISAM throughout: each DELETE is independent and idempotent (re-running prunes only what
 * is newly past-window); there is no transaction to rely on.
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$db     = Database::get();

$hasTable = (function () use ($db): callable {
    $cache = [];
    return function (string $t) use ($db, &$cache): bool {
        if (array_key_exists($t, $cache)) return $cache[$t];
        $s = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$t]);
        return $cache[$t] = ((int)$s->fetchColumn() > 0);
    };
})();

// ── Retention windows in DAYS. PROPOSED defaults — confirm before the first real run. ──
$W = [
    'intervals_webhook_processed' => 30,   // raw webhook payloads, processed/skipped (fastest-growing)
    'intervals_webhook_failed'    => 90,   // keep failed webhooks longer for debugging
    'intervals_push_log'          => 90,   // watch-push delivery audit
    'engine_flags'                => 180,  // closed (dismissed/acted_on) flags — Flags tab shows only 90d, so safe
    'cif'                         => 180,  // closed coaching_intelligence_flags — same 90d-visible margin
    'training_load'               => 730,  // 2 years: long-term fitness history + cross-cycle continuity (not log exhaust)
    'planned_cancelled'           => 180,  // coach soft-deleted workouts — no report reads cancelled=1 past this
    'training_plans_archived'     => 180,  // archived plans past archived_at + N (keep latest per athlete)
    'stripe_webhook_log'          => 365,  // financial audit — keep >= 1 year for disputes/accounting
];

$mode = $dryRun ? 'DRY RUN (no deletes)' : 'LIVE';
echo date('Y-m-d H:i:s') . " — data-retention cron [{$mode}]\n";

// ── Simple single-table policies: prune rows matching WHERE. ──
$simple = [
    [
        'table' => 'intervals_webhook_log',
        'label' => "intervals_webhook_log processed/skipped > {$W['intervals_webhook_processed']}d",
        'where' => "status IN ('processed','skipped') AND received_at < (NOW() - INTERVAL {$W['intervals_webhook_processed']} DAY)",
    ],
    [
        'table' => 'intervals_webhook_log',
        'label' => "intervals_webhook_log failed > {$W['intervals_webhook_failed']}d",
        'where' => "status = 'failed' AND received_at < (NOW() - INTERVAL {$W['intervals_webhook_failed']} DAY)",
    ],
    [
        'table' => 'intervals_push_log',
        'label' => "intervals_push_log > {$W['intervals_push_log']}d",
        'where' => "pushed_at < (NOW() - INTERVAL {$W['intervals_push_log']} DAY)",
    ],
    [
        'table' => 'engine_flags',
        'label' => "engine_flags closed > {$W['engine_flags']}d",
        'where' => "status IN ('dismissed','acted_on') AND created_at < (NOW() - INTERVAL {$W['engine_flags']} DAY)",
    ],
    [
        'table' => 'coaching_intelligence_flags',
        'label' => "coaching_intelligence_flags closed > {$W['cif']}d",
        'where' => "status IN ('dismissed','superseded','actioned') AND created_at < (NOW() - INTERVAL {$W['cif']} DAY)",
    ],
    [
        'table' => 'training_load',
        'label' => "training_load > {$W['training_load']}d",
        'where' => "`date` < (CURDATE() - INTERVAL {$W['training_load']} DAY)",
    ],
    [
        'table' => 'planned_workouts',
        'label' => "planned_workouts cancelled > {$W['planned_cancelled']}d",
        'where' => "cancelled = 1 AND cancelled_at IS NOT NULL AND cancelled_at < (NOW() - INTERVAL {$W['planned_cancelled']} DAY)",
    ],
    [
        'table' => 'stripe_webhook_log',
        'label' => "stripe_webhook_log > {$W['stripe_webhook_log']}d",
        'where' => "received_at < (NOW() - INTERVAL {$W['stripe_webhook_log']} DAY)",
    ],
];

$grandTotal = 0;

foreach ($simple as $p) {
    if (!$hasTable($p['table'])) { echo "  · skip {$p['label']} (table missing)\n"; continue; }
    $n = (int)$db->query("SELECT COUNT(*) FROM `{$p['table']}` WHERE {$p['where']}")->fetchColumn();
    if ($dryRun) {
        echo "  [dry] {$p['label']}: {$n}\n";
    } else {
        $del = $db->exec("DELETE FROM `{$p['table']}` WHERE {$p['where']}");
        echo "  [del] {$p['label']}: {$del}\n";
        $n = (int)$del;
    }
    $grandTotal += $n;
}

// ── Custom policy: archived training_plans (keep the most-recent archived plan per athlete
//    as a fallback; prune older archived plans past archived_at + N, AND their planned_workouts
//    so no orphaned child rows are left behind). ──
if ($hasTable('training_plans')) {
    $n = (int)$W['training_plans_archived'];
    // Prunable archived plan ids: archived, aged past window, and NOT the latest archived per athlete.
    $rows = $db->query(
        "SELECT id FROM training_plans
          WHERE status = 'archived'
            AND archived_at IS NOT NULL
            AND archived_at < (NOW() - INTERVAL {$n} DAY)
            AND id NOT IN (
                SELECT keep FROM (
                    SELECT MAX(id) AS keep FROM training_plans WHERE status = 'archived' GROUP BY athlete_id
                ) k
            )"
    )->fetchAll(PDO::FETCH_COLUMN);
    $planCount = count($rows);

    $childCount = 0;
    if ($planCount > 0 && $hasTable('planned_workouts')) {
        $in = implode(',', array_map('intval', $rows));
        $childCount = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE plan_id IN ({$in})")->fetchColumn();
    }

    if ($dryRun) {
        echo "  [dry] training_plans archived > {$n}d (keep latest/athlete): {$planCount} plan(s) + {$childCount} planned_workout(s)\n";
        $grandTotal += $planCount + $childCount;
    } else {
        $delChild = 0; $delPlan = 0;
        if ($planCount > 0) {
            $in = implode(',', array_map('intval', $rows));
            if ($hasTable('planned_workouts')) {
                $delChild = (int)$db->exec("DELETE FROM planned_workouts WHERE plan_id IN ({$in})");
            }
            $delPlan = (int)$db->exec("DELETE FROM training_plans WHERE id IN ({$in})");
        }
        echo "  [del] training_plans archived > {$n}d (keep latest/athlete): {$delPlan} plan(s) + {$delChild} planned_workout(s)\n";
        $grandTotal += $delPlan + $delChild;
    }
}

$verb = $dryRun ? 'would be deleted' : 'deleted';
echo date('Y-m-d H:i:s') . " — done. {$grandTotal} row(s) {$verb}.\n";

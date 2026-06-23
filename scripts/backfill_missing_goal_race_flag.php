<?php
/**
 * Backfill — repair engine_flags rows coerced to flag_type='' by the
 * 'missing_goal_race_date' bug (PlanGenerator refused to build a race cycle with no
 * goal race date and wrote a flag_type that was NOT an ENUM member, so MyISAM silently
 * truncated it to '' and the CRITICAL flag became invisible to every coach UI filter).
 *
 * The code now reuses the valid 'plan_rebuild_needed' flag_type for this case (see
 * src/Engine/PlanGenerator.php). This one-shot re-types the historical '' rows so the
 * coach finally sees them.
 *
 * Identification is conservative: only rows that are BOTH flag_type='' AND carry the
 * distinctive missing-goal-race message are touched. Other '' rows (if any) are a
 * different bug and are left alone. Idempotent: re-running matches nothing once fixed.
 *
 *     php scripts/backfill_missing_goal_race_flag.php --dry-run   # report only
 *     php scripts/backfill_missing_goal_race_flag.php             # apply
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$db     = Database::get();

// The message prefix is stable across the old and new wording — both begin with this.
$MSG_PREFIX = 'Cannot generate a race cycle plan without a goal race date%';

$where = "flag_type = '' AND message LIKE ?";

$count = $db->prepare("SELECT COUNT(*) FROM engine_flags WHERE {$where}");
$count->execute([$MSG_PREFIX]);
$n = (int)$count->fetchColumn();

echo date('Y-m-d H:i:s') . ' — ' . ($dryRun ? 'DRY RUN' : 'APPLY') . ": {$n} coerced missing-goal-race flag row(s).\n";

if ($n === 0) {
    echo "Nothing to backfill.\n";
    exit(0);
}

if ($dryRun) {
    $rows = $db->prepare("SELECT id, athlete_id, severity, flag_date, LEFT(message, 80) AS msg FROM engine_flags WHERE {$where} ORDER BY id");
    $rows->execute([$MSG_PREFIX]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  id={$r['id']} athlete={$r['athlete_id']} sev={$r['severity']} date={$r['flag_date']} :: {$r['msg']}\n";
    }
    echo "Would re-type {$n} row(s) to flag_type='plan_rebuild_needed'. Re-run without --dry-run to apply.\n";
    exit(0);
}

$upd = $db->prepare("UPDATE engine_flags SET flag_type = 'plan_rebuild_needed' WHERE {$where}");
$upd->execute([$MSG_PREFIX]);
echo "Re-typed {$upd->rowCount()} row(s) from '' to 'plan_rebuild_needed'.\n";
echo date('Y-m-d H:i:s') . " — backfill complete.\n";

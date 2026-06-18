<?php
/**
 * Migration 031 runner — regen carry-over of athlete-exposed weeks.
 *
 *  planned_workouts.carried_over_from_plan_id INT NULL
 *  planned_workouts.carried_over_at           DATETIME NULL
 *
 * Idempotent: columns added only when missing. Safe to re-run.
 *
 *     php scripts/run_migration_031.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

$hasColumn = function (string $table, string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
};

$addColumn = function (string $table, string $col, string $ddl) use ($db, $hasColumn): void {
    if (!$hasColumn($table, $col)) {
        echo "Adding {$table}.{$col}…\n";
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
        echo "  ok.\n";
    } else {
        echo "{$table}.{$col} already present — skipping.\n";
    }
};

$addColumn('planned_workouts', 'carried_over_from_plan_id',
    "`carried_over_from_plan_id` INT NULL COMMENT 'set when this row was carried into a regenerated plan from the prior plan'");
$addColumn('planned_workouts', 'carried_over_at',
    "`carried_over_at` DATETIME NULL COMMENT 'when the row was carried over during a regen'");

echo date('Y-m-d H:i:s') . " — migration 031 complete.\n";

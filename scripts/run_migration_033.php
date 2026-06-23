<?php
/**
 * Migration 033 runner — add training_plans.archived_at + backfill existing archived plans.
 *
 * Idempotent: the column is added only when missing; the backfill only touches archived
 * rows whose archived_at is still NULL. Safe to re-run.
 *
 *     php scripts/run_migration_033.php
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

if (!$hasColumn('training_plans', 'archived_at')) {
    echo "Adding training_plans.archived_at…\n";
    $db->exec(
        "ALTER TABLE `training_plans`
         ADD COLUMN `archived_at` DATETIME DEFAULT NULL
             COMMENT 'set when status becomes archived; enables age-based retention'
             AFTER `status`"
    );
    echo "  ok.\n";
} else {
    echo "training_plans.archived_at already present — skipping ADD COLUMN.\n";
}

// Backfill existing archived plans from generated_at (only the NULLs).
$stmt = $db->prepare(
    "UPDATE training_plans
        SET archived_at = generated_at
      WHERE status = 'archived' AND archived_at IS NULL"
);
$stmt->execute();
echo "Backfilled archived_at on {$stmt->rowCount()} archived plan(s).\n";

echo date('Y-m-d H:i:s') . " — migration 033 complete.\n";

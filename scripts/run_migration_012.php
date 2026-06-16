<?php
/**
 * Migration 012 runner — coach soft-delete (cancel) for planned workouts.
 *
 * Adds `cancelled`, `cancelled_at`, `cancelled_by` to planned_workouts plus an
 * index. Idempotent: each column/index is only added when missing, so it is safe
 * to re-run.
 *
 *     php scripts/run_migration_012.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

// config.php loads config.local.php itself (web-root fallback), so don't also
// require it here or constants get defined twice.
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

/** True when planned_workouts already has the given column. */
$hasColumn = function (string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "planned_workouts" AND COLUMN_NAME = ?'
    );
    $stmt->execute([$col]);
    return (int)$stmt->fetchColumn() > 0;
};

$hasIndex = function (string $idx) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "planned_workouts" AND INDEX_NAME = ?'
    );
    $stmt->execute([$idx]);
    return (int)$stmt->fetchColumn() > 0;
};

if (!$hasColumn('cancelled')) {
    echo "Adding planned_workouts.cancelled…\n";
    $db->exec("ALTER TABLE `planned_workouts`
        ADD COLUMN `cancelled` TINYINT(1) NOT NULL DEFAULT 0
            COMMENT 'coach soft-deleted; day renders as rest' AFTER `notes`");
    echo "  ok.\n";
} else {
    echo "planned_workouts.cancelled already present — skipping.\n";
}

if (!$hasColumn('cancelled_at')) {
    echo "Adding planned_workouts.cancelled_at…\n";
    $db->exec("ALTER TABLE `planned_workouts` ADD COLUMN `cancelled_at` DATETIME NULL AFTER `cancelled`");
    echo "  ok.\n";
} else {
    echo "planned_workouts.cancelled_at already present — skipping.\n";
}

if (!$hasColumn('cancelled_by')) {
    echo "Adding planned_workouts.cancelled_by…\n";
    $db->exec("ALTER TABLE `planned_workouts`
        ADD COLUMN `cancelled_by` INT NULL COMMENT 'user_id of the coach who cancelled it' AFTER `cancelled_at`");
    echo "  ok.\n";
} else {
    echo "planned_workouts.cancelled_by already present — skipping.\n";
}

if (!$hasIndex('idx_pw_cancelled')) {
    echo "Adding index idx_pw_cancelled…\n";
    $db->exec("ALTER TABLE `planned_workouts` ADD INDEX `idx_pw_cancelled` (`plan_id`, `cancelled`)");
    echo "  ok.\n";
} else {
    echo "Index idx_pw_cancelled already present — skipping.\n";
}

echo date('Y-m-d H:i:s') . " — migration 012 complete.\n";

<?php
/**
 * Migration 036 runner: add notification_preferences.delivery
 * (immediate vs daily_digest, for coach warning/info flag batching).
 *
 * Idempotent: the column is added only when missing. Safe to re-run.
 * Run BEFORE deploying the code that selects the column.
 *
 *     php scripts/run_migration_036.php
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

if (!$hasColumn('notification_preferences', 'delivery')) {
    echo "Adding notification_preferences.delivery...\n";
    $db->exec(
        "ALTER TABLE `notification_preferences`
         ADD COLUMN `delivery` ENUM('immediate','daily_digest') NOT NULL DEFAULT 'immediate'
             COMMENT 'warning/info flags only: batch into the daily flag digest'
             AFTER `channel_sms`"
    );
    echo "  ok.\n";
} else {
    echo "notification_preferences.delivery already present, nothing to do.\n";
}

echo date('Y-m-d H:i:s') . " migration 036 complete.\n";

<?php
/**
 * Migration 011 runner — device notify-me preferences.
 *
 * Creates `device_notify_preferences`. Idempotent (CREATE TABLE IF NOT EXISTS),
 * so it is safe to re-run.
 *
 *     php scripts/run_migration_011.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

// config.php loads config.local.php itself (web-root fallback), so don't also
// require it here or constants get defined twice.
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

echo "Creating device_notify_preferences…\n";
$db->exec(
    "CREATE TABLE IF NOT EXISTS `device_notify_preferences` (
        `user_id`    INT NOT NULL,
        `brand`      VARCHAR(32) NOT NULL,
        `notify`     TINYINT(1) NOT NULL DEFAULT 0,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`user_id`, `brand`),
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
);
echo "  ok.\n";

echo date('Y-m-d H:i:s') . " — migration 011 complete.\n";

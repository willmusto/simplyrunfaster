<?php
/**
 * Migration 017 runner — scheduled_messages table.
 *
 * Creates the scheduled_messages table that backs delayed coach messages
 * (onboarding welcome note). Idempotent: CREATE TABLE IF NOT EXISTS, safe to re-run.
 *
 *     php scripts/run_migration_017.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

// config.php loads config.local.php itself (web-root fallback), so don't also
// require it here or constants get defined twice.
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

echo "Creating scheduled_messages…\n";
$db->exec(
    "CREATE TABLE IF NOT EXISTS `scheduled_messages` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `athlete_id` INT UNSIGNED NOT NULL,
        `sender_id`  INT UNSIGNED NOT NULL COMMENT 'coach user_id',
        `body`       TEXT NOT NULL,
        `send_after` DATETIME NOT NULL,
        `sent`       TINYINT(1) NOT NULL DEFAULT 0,
        `sent_at`    DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_sm_pending` (`sent`, `send_after`),
        KEY `idx_sm_athlete` (`athlete_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci"
);
echo "  ok.\n";

echo date('Y-m-d H:i:s') . " — migration 017 complete.\n";

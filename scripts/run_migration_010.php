<?php
/**
 * Migration 010 runner — Stripe billing.
 *
 * Idempotent. Adds subscription columns to `users`, billing/discount columns
 * to `invite_links`, creates `stripe_webhook_log`, and seeds the two new
 * always-on notification types (payment_failed_athlete / payment_failed_coach)
 * for every existing user.
 *
 * Unlike the raw .sql file, this guards each ADD COLUMN against
 * INFORMATION_SCHEMA so re-running is safe on MariaDB 5.3 (which has no
 * ADD COLUMN IF NOT EXISTS).
 *
 *     php scripts/run_migration_010.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Timezone.php';
require_once SCRIPT_ROOT . '/src/Notifications.php';

$db = Database::get();

/** Add a column only if it is not already present. */
function addColumnIfMissing(PDO $db, string $table, string $column, string $definition): void
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo "  · {$table}.{$column} already present — skipped.\n";
        return;
    }
    $db->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    echo "  + {$table}.{$column} added.\n";
}

echo "Applying users columns…\n";
addColumnIfMissing($db, 'users', 'stripe_customer_id',
    "`stripe_customer_id` VARCHAR(64) DEFAULT NULL AFTER `invite_code`");
addColumnIfMissing($db, 'users', 'subscription_status',
    "`subscription_status` ENUM('none','trialing','active','past_due','canceled','comped') NOT NULL DEFAULT 'none' AFTER `stripe_customer_id`");
addColumnIfMissing($db, 'users', 'subscription_end_date',
    "`subscription_end_date` DATE DEFAULT NULL AFTER `subscription_status`");
addColumnIfMissing($db, 'users', 'billing_interval',
    "`billing_interval` ENUM('monthly','annual') DEFAULT NULL AFTER `subscription_end_date`");
addColumnIfMissing($db, 'users', 'grace_period_ends',
    "`grace_period_ends` DATE DEFAULT NULL AFTER `billing_interval`");

echo "Applying invite_links columns…\n";
addColumnIfMissing($db, 'invite_links', 'discount_percent',
    "`discount_percent` TINYINT DEFAULT NULL AFTER `coupon_code`");
addColumnIfMissing($db, 'invite_links', 'discount_duration',
    "`discount_duration` VARCHAR(16) DEFAULT NULL AFTER `discount_percent`");
addColumnIfMissing($db, 'invite_links', 'stripe_coupon_id',
    "`stripe_coupon_id` VARCHAR(64) DEFAULT NULL AFTER `discount_duration`");
addColumnIfMissing($db, 'invite_links', 'billing_interval',
    "`billing_interval` ENUM('monthly','annual') DEFAULT NULL AFTER `stripe_coupon_id`");

echo "Creating stripe_webhook_log…\n";
$db->exec(
    "CREATE TABLE IF NOT EXISTS `stripe_webhook_log` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `event_id`    VARCHAR(64) NOT NULL,
        `event_type`  VARCHAR(80) NOT NULL,
        `payload`     LONGTEXT DEFAULT NULL,
        `received_at` DATETIME NOT NULL,
        `processed`   TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_swl_event` (`event_id`),
        KEY `idx_swl_type` (`event_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci"
);
echo "  ok.\n";

echo "Seeding payment_failed_* notification preferences for all users…\n";
$inserted = Notifications::seedDefaultsForAllUsers();
echo "  Seeded {$inserted} new preference rows.\n";

echo date('Y-m-d H:i:s') . " — migration 010 complete.\n";

<?php
/**
 * Migration 013 runner — privacy consent + account deletion.
 *
 * Adds consent_age / consent_privacy / consent_given_at / deleted_at to
 * `users`, creates the `account_deletions` audit table, and (the first time
 * the consent columns are created) grandfathers all existing users as
 * consented since they predate the policy. Idempotent: each column/table is
 * only added when missing and the backfill runs only on first application, so
 * it is safe to re-run.
 *
 *     php scripts/run_migration_013.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

// config.php loads config.local.php itself (web-root fallback), so don't also
// require it here or constants get defined twice.
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

/** True when `users` already has the given column. */
$hasColumn = function (string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "users" AND COLUMN_NAME = ?'
    );
    $stmt->execute([$col]);
    return (int)$stmt->fetchColumn() > 0;
};

$columns = [
    'consent_age'      => "ADD COLUMN `consent_age` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '18+/parental consent confirmed at onboarding' AFTER `invite_code`",
    'consent_privacy'  => "ADD COLUMN `consent_privacy` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Privacy Policy agreed at onboarding' AFTER `consent_age`",
    'consent_given_at' => "ADD COLUMN `consent_given_at` DATETIME DEFAULT NULL
        COMMENT 'when both consents were recorded' AFTER `consent_privacy`",
    'deleted_at'       => "ADD COLUMN `deleted_at` DATETIME DEFAULT NULL
        COMMENT 'set when the account is anonymized by the retention cron' AFTER `consent_given_at`",
];

$consentColumnsJustCreated = false;
foreach ($columns as $col => $ddl) {
    if (!$hasColumn($col)) {
        echo "Adding users.$col…\n";
        $db->exec("ALTER TABLE `users` $ddl");
        echo "  ok.\n";
        if ($col === 'consent_given_at') {
            $consentColumnsJustCreated = true;
        }
    } else {
        echo "users.$col already present — skipping.\n";
    }
}

echo "Creating account_deletions…\n";
$db->exec(
    "CREATE TABLE IF NOT EXISTS `account_deletions` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT NOT NULL,
        `deleted_at` DATETIME NOT NULL,
        `reason`     VARCHAR(64) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_acctdel_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci"
);
echo "  ok.\n";

// First-time grandfather backfill: every user that exists at the moment the
// consent columns are created predates the policy and is treated as consented.
// Guarded so it never re-applies to users who register later (and would
// otherwise have a NULL consent_given_at until they finish onboarding).
if ($consentColumnsJustCreated) {
    $affected = $db->exec(
        'UPDATE `users` SET `consent_age` = 1, `consent_privacy` = 1, `consent_given_at` = NOW()
         WHERE `consent_given_at` IS NULL'
    );
    echo "Grandfathered {$affected} existing user(s) as consented.\n";
} else {
    echo "Consent columns already existed — skipping grandfather backfill.\n";
}

echo date('Y-m-d H:i:s') . " — migration 013 complete.\n";

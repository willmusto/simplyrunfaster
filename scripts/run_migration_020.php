<?php
/**
 * Migration 020 runner — Terms of Service consent.
 *
 * Adds consent_tos / consent_tos_at to `users` and (the first time the columns
 * are created) grandfathers all existing users as consented, since they predate
 * the Terms (effective 2026-06-17). Idempotent: each column is added only when
 * missing and the backfill runs only on first application, so it is safe to re-run.
 *
 *     php scripts/run_migration_020.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

$hasColumn = function (string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "users" AND COLUMN_NAME = ?'
    );
    $stmt->execute([$col]);
    return (int)$stmt->fetchColumn() > 0;
};

$columns = [
    'consent_tos'    => "ADD COLUMN `consent_tos` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Terms of Service agreed at onboarding' AFTER `consent_privacy`",
    'consent_tos_at' => "ADD COLUMN `consent_tos_at` DATETIME DEFAULT NULL
        COMMENT 'when ToS consent was recorded' AFTER `consent_tos`",
];

$justCreated = false;
foreach ($columns as $col => $ddl) {
    if (!$hasColumn($col)) {
        echo "Adding users.{$col}…\n";
        $db->exec("ALTER TABLE `users` $ddl");
        echo "  ok.\n";
        if ($col === 'consent_tos_at') {
            $justCreated = true;
        }
    } else {
        echo "users.{$col} already present — skipping.\n";
    }
}

// First-time grandfather backfill: every user that exists when the column is
// created predates the Terms and is treated as consented. Guarded so it never
// re-applies to users who register later (NULL consent_tos_at until they finish).
if ($justCreated) {
    $affected = $db->exec(
        'UPDATE `users` SET `consent_tos` = 1, `consent_tos_at` = NOW() WHERE `consent_tos_at` IS NULL'
    );
    echo "Grandfathered {$affected} existing user(s) as ToS-consented.\n";
} else {
    echo "consent_tos column already existed — skipping grandfather backfill.\n";
}

echo date('Y-m-d H:i:s') . " — migration 020 complete.\n";

<?php
/**
 * Migration 016 runner — invite-link deactivation.
 *
 * Adds invite_links.deactivated_at (DATETIME NULL). A non-NULL value means a
 * coach has disabled the link and it can no longer be used for onboarding.
 *
 * Idempotent: the column is only added when missing, so it is safe to re-run.
 *
 *     php scripts/run_migration_016.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

// config.php loads config.local.php itself (web-root fallback), so don't also
// require it here or constants get defined twice.
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

/** True when $table already has column $col. */
$hasColumn = function (string $table, string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
};

if (!$hasColumn('invite_links', 'deactivated_at')) {
    echo "Adding invite_links.deactivated_at…\n";
    $db->exec(
        "ALTER TABLE `invite_links`
         ADD COLUMN `deactivated_at` DATETIME DEFAULT NULL
         COMMENT 'set when a coach manually deactivates the link' AFTER `used_by`"
    );
    echo "  ok.\n";
} else {
    echo "invite_links.deactivated_at already present — skipping.\n";
}

echo date('Y-m-d H:i:s') . " — migration 016 complete.\n";

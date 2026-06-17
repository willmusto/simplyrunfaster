<?php
/**
 * Migration 015 runner — session-card reply_count.
 *
 * Adds messages.reply_count (INT NOT NULL DEFAULT 0), backing the single
 * "session card" per workout that re-floats to the bottom of the thread on
 * each new comment.
 *
 * Idempotent: the column is only added when missing, so it is safe to re-run.
 *
 *     php scripts/run_migration_015.php
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

if (!$hasColumn('messages', 'reply_count')) {
    echo "Adding messages.reply_count…\n";
    $db->exec(
        "ALTER TABLE `messages`
         ADD COLUMN `reply_count` INT NOT NULL DEFAULT 0
         COMMENT 'session card: comments after the first (re-float counter)' AFTER `thread_id`"
    );
    echo "  ok.\n";
} else {
    echo "messages.reply_count already present — skipping.\n";
}

echo date('Y-m-d H:i:s') . " — migration 015 complete.\n";

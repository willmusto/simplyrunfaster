<?php
/**
 * Migration 034 (idempotent): add planned_workouts.push_text_only (edit -> Intervals.icu
 * GAP A). Checks information_schema so re-running is a no-op. No data is changed; the
 * column defaults to 0, so every existing row keeps pushing its structure as before.
 *
 * Run from /home/private/app: php scripts/migrate_push_text_only.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = Database::get();

$exists = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'planned_workouts'
       AND COLUMN_NAME = 'push_text_only'"
)->fetchColumn();

if ((int)$exists > 0) {
    echo "push_text_only already present; nothing to do.\n";
    exit(0);
}

$pdo->exec(
    "ALTER TABLE `planned_workouts`
       ADD COLUMN `push_text_only` tinyint(1) NOT NULL DEFAULT '0'
       COMMENT 'Surface/inline coach edit: push athlete_instructions text, not the (now-stale) structure (GAP A).'
       AFTER `pushed_to_watch`"
);

echo "Added planned_workouts.push_text_only (default 0). No rows changed.\n";
exit(0);

<?php
/**
 * Migration 022 runner — workout-linked threads keyed on planned_workout_id.
 *
 *  - messages.planned_workout_id (NULL)
 *  - session_notes.planned_workout_id (NULL) + completed_workout_id made NULLABLE
 *  - backfill both planned_workout_id columns from completed_workouts (first run only)
 *  - supporting indexes
 *
 * Idempotent: columns/indexes added only when missing; backfill runs only the first
 * time the columns are created. Safe to re-run.
 *
 *     php scripts/run_migration_022.php
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
$hasIndex = function (string $table, string $idx) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $idx]);
    return (int)$stmt->fetchColumn() > 0;
};

$justCreated = false;

if (!$hasColumn('messages', 'planned_workout_id')) {
    echo "Adding messages.planned_workout_id…\n";
    $db->exec("ALTER TABLE `messages` ADD COLUMN `planned_workout_id` INT UNSIGNED DEFAULT NULL
               COMMENT 'session card link to a planned workout (available pre-completion)' AFTER `completed_workout_id`");
    $justCreated = true;
    echo "  ok.\n";
} else {
    echo "messages.planned_workout_id already present — skipping.\n";
}

if (!$hasColumn('session_notes', 'planned_workout_id')) {
    echo "Adding session_notes.planned_workout_id + making completed_workout_id nullable…\n";
    $db->exec("ALTER TABLE `session_notes` ADD COLUMN `planned_workout_id` INT UNSIGNED DEFAULT NULL
               COMMENT 'thread link to a planned workout (available pre-completion)' AFTER `completed_workout_id`");
    $db->exec("ALTER TABLE `session_notes` MODIFY COLUMN `completed_workout_id` INT UNSIGNED DEFAULT NULL
               COMMENT 'set once the workout is completed; NULL for pre-completion notes'");
    $justCreated = true;
    echo "  ok.\n";
} else {
    echo "session_notes.planned_workout_id already present — skipping.\n";
}

if ($justCreated) {
    echo "Backfilling planned_workout_id from completed_workouts…\n";
    $m = (int)$db->exec(
        'UPDATE `messages` m JOIN `completed_workouts` cw ON cw.id = m.completed_workout_id
         SET m.planned_workout_id = cw.planned_workout_id
         WHERE m.planned_workout_id IS NULL AND cw.planned_workout_id IS NOT NULL'
    );
    $s = (int)$db->exec(
        'UPDATE `session_notes` sn JOIN `completed_workouts` cw ON cw.id = sn.completed_workout_id
         SET sn.planned_workout_id = cw.planned_workout_id
         WHERE sn.planned_workout_id IS NULL AND cw.planned_workout_id IS NOT NULL'
    );
    echo "  backfilled {$m} message card(s), {$s} session note(s).\n";
} else {
    echo "Columns already existed — skipping backfill.\n";
}

if (!$hasIndex('messages', 'idx_msg_planned')) {
    $db->exec('ALTER TABLE `messages` ADD KEY `idx_msg_planned` (`planned_workout_id`)');
    echo "Added idx_msg_planned.\n";
}
if (!$hasIndex('session_notes', 'idx_sn_planned')) {
    $db->exec('ALTER TABLE `session_notes` ADD KEY `idx_sn_planned` (`planned_workout_id`)');
    echo "Added idx_sn_planned.\n";
}

echo date('Y-m-d H:i:s') . " — migration 022 complete.\n";

<?php
/**
 * Migration 014 runner — Intervals.icu integration.
 *
 * Creates the three Intervals.icu tables (intervals_connections,
 * intervals_push_log, intervals_webhook_log), adds planned_workouts.intervals_event_id,
 * and extends completed_workouts (source_device, the 'intervals' source enum value,
 * and a (source, external_activity_id) uniqueness key for idempotent activity import).
 *
 * Idempotent: every column / table / index / enum change is only applied when
 * missing, so it is safe to re-run.
 *
 *     php scripts/run_migration_014.php
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

/** True when $table already has an index named $index. */
$hasIndex = function (string $table, string $index) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
};

/** The COLUMN_TYPE string for a column (e.g. the full enum definition), or ''. */
$columnType = function (string $table, string $col) use ($db): string {
    $stmt = $db->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (string)($stmt->fetchColumn() ?: '');
};

// NOTE: the live database is MyISAM throughout (verified against the production
// schema) and therefore carries no foreign keys. MyISAM cannot be the parent of an
// InnoDB foreign key, so these tables intentionally use ENGINE=MyISAM and plain
// indexes (not FK constraints) — matching every other table in the schema. Without
// FK enforcement, a log row may reference a since-deleted planned_workout (e.g. after
// return-to-running window regeneration); that is harmless for append-only log tables.

// ── intervals_connections ─────────────────────────────────────────────────
// user_id is INT UNSIGNED to match users.id (kept consistent even without an FK).
echo "Creating intervals_connections…\n";
$db->exec(
    "CREATE TABLE IF NOT EXISTS `intervals_connections` (
        `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`              INT UNSIGNED NOT NULL,
        `intervals_athlete_id` VARCHAR(32) NOT NULL,
        `access_token_enc`     TEXT NOT NULL COMMENT 'OAuth access token, encrypted at rest',
        `scope`                VARCHAR(255) NOT NULL,
        `connected_at`         DATETIME NOT NULL,
        `last_synced_at`       DATETIME DEFAULT NULL,
        `sync_status`          ENUM('ok','error','pending') NOT NULL DEFAULT 'pending',
        `last_error`           TEXT DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_intervals_user` (`user_id`),
        KEY `idx_intervals_athlete` (`intervals_athlete_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci"
);
echo "  ok.\n";

// ── intervals_push_log ────────────────────────────────────────────────────
echo "Creating intervals_push_log…\n";
$db->exec(
    "CREATE TABLE IF NOT EXISTS `intervals_push_log` (
        `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `planned_workout_id` INT UNSIGNED NOT NULL,
        `intervals_event_id` VARCHAR(64) DEFAULT NULL,
        `pushed_at`          DATETIME NOT NULL,
        `status`             ENUM('success','failed','skipped') NOT NULL,
        `error_message`      TEXT DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_push_workout` (`planned_workout_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci"
);
echo "  ok.\n";

// ── intervals_webhook_log ─────────────────────────────────────────────────
echo "Creating intervals_webhook_log…\n";
$db->exec(
    "CREATE TABLE IF NOT EXISTS `intervals_webhook_log` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `event_type`    VARCHAR(64) NOT NULL,
        `athlete_id`    VARCHAR(32) NOT NULL COMMENT 'Intervals.icu athlete id from payload',
        `payload`       LONGTEXT NOT NULL,
        `received_at`   DATETIME NOT NULL,
        `status`        ENUM('received','processed','skipped','failed') NOT NULL DEFAULT 'received',
        `error_message` TEXT DEFAULT NULL,
        `processed_at`  DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_webhook_athlete` (`athlete_id`),
        KEY `idx_webhook_status` (`status`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci"
);
echo "  ok.\n";

// ── planned_workouts.intervals_event_id ───────────────────────────────────
if (!$hasColumn('planned_workouts', 'intervals_event_id')) {
    echo "Adding planned_workouts.intervals_event_id…\n";
    $db->exec(
        "ALTER TABLE `planned_workouts`
         ADD COLUMN `intervals_event_id` VARCHAR(64) DEFAULT NULL
         COMMENT 'Intervals.icu calendar event id (srf_{id} upsert)' AFTER `pushed_at`"
    );
    echo "  ok.\n";
} else {
    echo "planned_workouts.intervals_event_id already present — skipping.\n";
}

// ── completed_workouts.source_device ──────────────────────────────────────
if (!$hasColumn('completed_workouts', 'source_device')) {
    echo "Adding completed_workouts.source_device…\n";
    $db->exec(
        "ALTER TABLE `completed_workouts`
         ADD COLUMN `source_device` VARCHAR(32) DEFAULT NULL
         COMMENT 'garmin/coros/polar/suunto when known from the Intervals.icu payload' AFTER `source`"
    );
    echo "  ok.\n";
} else {
    echo "completed_workouts.source_device already present — skipping.\n";
}

// ── completed_workouts.external_activity_id (exists in base schema as VARCHAR(255)) ──
if (!$hasColumn('completed_workouts', 'external_activity_id')) {
    echo "Adding completed_workouts.external_activity_id…\n";
    $db->exec(
        "ALTER TABLE `completed_workouts`
         ADD COLUMN `external_activity_id` VARCHAR(64) DEFAULT NULL
         COMMENT 'platform own activity id' AFTER `source_device`"
    );
    echo "  ok.\n";
} else {
    echo "completed_workouts.external_activity_id already present — skipping.\n";
}

// ── completed_workouts.source enum — add 'intervals' if missing ───────────
$sourceType = $columnType('completed_workouts', 'source');
if ($sourceType !== '' && stripos($sourceType, "'intervals'") === false) {
    echo "Adding 'intervals' to completed_workouts.source enum…\n";
    $db->exec(
        "ALTER TABLE `completed_workouts`
         MODIFY COLUMN `source`
         ENUM('garmin','polar','apple','wahoo','manual','intervals') NOT NULL DEFAULT 'manual'"
    );
    echo "  ok.\n";
} else {
    echo "completed_workouts.source already includes 'intervals' (or column missing) — skipping.\n";
}

// ── completed_workouts uniqueness key for idempotent activity import ──────
// (source, external_activity_id). A 190-char prefix on external_activity_id
// keeps the index well under InnoDB's per-index byte limit on utf8mb3; activity
// ids are short, so the prefix never truncates a real value. NULL external ids
// (manual logs) are exempt from the constraint (NULL != NULL in MySQL).
if (!$hasIndex('completed_workouts', 'uniq_source_external')) {
    echo "Adding completed_workouts uniq_source_external…\n";
    $db->exec(
        "ALTER TABLE `completed_workouts`
         ADD UNIQUE KEY `uniq_source_external` (`source`, `external_activity_id`(190))"
    );
    echo "  ok.\n";
} else {
    echo "completed_workouts.uniq_source_external already present — skipping.\n";
}

echo date('Y-m-d H:i:s') . " — migration 014 complete.\n";

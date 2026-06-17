<?php
/**
 * Migration 024 runner — coach assignments, assistant coaches, regen requests.
 *
 *  1) coach_assignments + plan_regeneration_requests tables.
 *  2) users.managed_by, users.must_change_password.
 *  3) planned_workouts.added_by_role.
 *  4) engine_flags.flag_type += 'assistant_pace_zone_edit'.
 *  5) Seed coach_assignments from each athlete's current coach (fallback user 1).
 *
 * Idempotent: tables/columns are created only when missing; the ENUM MODIFY
 * re-states the full set; the seed uses INSERT IGNORE on the unique athlete key.
 * Safe to re-run.
 *
 *     php scripts/run_migration_024.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

$hasTable = function (string $table) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
};

$hasColumn = function (string $table, string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
};

if (!$hasTable('coach_assignments')) {
    echo "Creating coach_assignments…\n";
    $db->exec(
        "CREATE TABLE `coach_assignments` (
            `id`                 INT AUTO_INCREMENT PRIMARY KEY,
            `athlete_id`         INT NOT NULL,
            `coach_id`           INT NOT NULL,
            `assistant_coach_id` INT NULL,
            `assigned_at`        DATETIME NOT NULL,
            `assigned_by`        INT NOT NULL,
            UNIQUE KEY `unique_athlete` (`athlete_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
    );
    echo "  ok.\n";
} else {
    echo "coach_assignments already present — skipping.\n";
}

if (!$hasTable('plan_regeneration_requests')) {
    echo "Creating plan_regeneration_requests…\n";
    $db->exec(
        "CREATE TABLE `plan_regeneration_requests` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `athlete_id`   INT NOT NULL,
            `requested_by` INT NOT NULL,
            `requested_at` DATETIME NOT NULL,
            `status`       ENUM('pending','approved','dismissed') NOT NULL DEFAULT 'pending',
            `actioned_by`  INT NULL,
            `actioned_at`  DATETIME NULL,
            `notes`        TEXT NULL,
            KEY `idx_prr_athlete` (`athlete_id`),
            KEY `idx_prr_status` (`status`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
    );
    echo "  ok.\n";
} else {
    echo "plan_regeneration_requests already present — skipping.\n";
}

if (!$hasColumn('users', 'managed_by')) {
    echo "Adding users.managed_by…\n";
    $db->exec(
        "ALTER TABLE `users` ADD COLUMN `managed_by` INT NULL
         COMMENT 'head coach user_id for assistant coaches; NULL otherwise' AFTER `role`"
    );
    echo "  ok.\n";
} else {
    echo "users.managed_by already present — skipping.\n";
}

if (!$hasColumn('users', 'must_change_password')) {
    echo "Adding users.must_change_password…\n";
    $db->exec(
        "ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0
         COMMENT 'force a password change on next login' AFTER `password_hash`"
    );
    echo "  ok.\n";
} else {
    echo "users.must_change_password already present — skipping.\n";
}

if (!$hasColumn('users', 'active')) {
    echo "Adding users.active…\n";
    $db->exec(
        "ALTER TABLE `users` ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1
         COMMENT 'deactivated accounts (0) cannot log in'"
    );
    echo "  ok.\n";
} else {
    echo "users.active already present — skipping.\n";
}

if (!$hasColumn('planned_workouts', 'added_by_role')) {
    echo "Adding planned_workouts.added_by_role…\n";
    $db->exec(
        "ALTER TABLE `planned_workouts` ADD COLUMN `added_by_role` VARCHAR(32) NULL
         COMMENT \"'assistant_coach' when added by an assistant coach (coach-only AC badge)\""
    );
    echo "  ok.\n";
} else {
    echo "planned_workouts.added_by_role already present — skipping.\n";
}

echo "Ensuring engine_flags.flag_type includes 'assistant_pace_zone_edit'…\n";
$db->exec(
    "ALTER TABLE `engine_flags`
     MODIFY COLUMN `flag_type` ENUM(
        'missed_workouts','hr_elevated','load_spike','compliance_low',
        'plan_rebuild_needed','compliance_trend','compliance_pattern',
        'excessive_fatigue','fitness_decline','taper_concern',
        'insufficient_base','return_to_running_discomfort',
        'limited_development_opportunity','long_run_day_conflict',
        'display_generation_incomplete',
        'profile_updated','pace_zones_missing',
        'schedule_day_ramp','ultra_surface_reminder',
        'race_added','goal_race_changed','pace_recalibration',
        'hyrox_supplement_reminder','assistant_pace_zone_edit'
     ) NOT NULL"
);
echo "  ok.\n";

echo "Seeding coach_assignments from each athlete's current coach (fallback user 1)…\n";
$n = $db->exec(
    "INSERT IGNORE INTO `coach_assignments` (`athlete_id`, `coach_id`, `assigned_at`, `assigned_by`)
     SELECT a.id, COALESCE(a.coach_id, 1), NOW(), 1 FROM `athletes` a"
);
echo "  seeded {$n} assignment(s).\n";

echo date('Y-m-d H:i:s') . " — migration 024 complete.\n";

<?php
/**
 * Migration 027 runner — Coaching Intelligence Layer (Phase 1 of 4).
 *
 *  1) coach_adjustments, coaching_decisions, athlete_behavior_log,
 *     coaching_intelligence_flags tables (MyISAM, utf8, no FK, LONGTEXT for JSON).
 *  2) users.last_login_at.
 *  3) training_plans.coach_generation_notes (decision-resolver audit per generation).
 *
 * Idempotent: tables created only when missing; columns added only when missing.
 * Safe to re-run.
 *
 *     php scripts/run_migration_027.php
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

if (!$hasTable('coach_adjustments')) {
    echo "Creating coach_adjustments…\n";
    $db->exec(
        "CREATE TABLE `coach_adjustments` (
            `id`                    INT AUTO_INCREMENT PRIMARY KEY,
            `planned_workout_id`    INT NOT NULL,
            `athlete_id`            INT NOT NULL,
            `coach_id`              INT NOT NULL,
            `adjusted_at`           DATETIME NOT NULL,
            `flagged_for_review`    TINYINT(1) NOT NULL DEFAULT 0,
            `change_type`           ENUM(
                'archetype_substitution','duration_change','day_swap',
                'workout_removed','workout_added','instructions_edited','pace_zone_edit'
            ) NOT NULL,
            `before_archetype_code` VARCHAR(64) NULL,
            `before_workout_type`   VARCHAR(32) NULL,
            `before_duration_mins`  INT NULL,
            `before_scheduled_date` DATE NULL,
            `before_instructions`   LONGTEXT NULL,
            `after_archetype_code`  VARCHAR(64) NULL,
            `after_workout_type`    VARCHAR(32) NULL,
            `after_duration_mins`   INT NULL,
            `after_scheduled_date`  DATE NULL,
            `after_instructions`    LONGTEXT NULL,
            `ctx_goal_distance`     VARCHAR(32) NULL,
            `ctx_phase`             VARCHAR(16) NULL,
            `ctx_week_number`       INT NULL,
            `ctx_classification`    VARCHAR(16) NULL,
            `ctx_weekly_mins`       INT NULL,
            `ctx_plan_week`         INT NULL,
            `reason_tag`            ENUM(
                'athlete_fatigue','schedule_conflict','insufficient_recovery','wrong_phase',
                'athlete_preference','injury_concern','weather_conditions','race_preparation',
                'coach_preference','other'
            ) NULL,
            `reason_notes`          TEXT NULL,
            `coaching_decision_id`  INT NULL,
            KEY `idx_ca_coach` (`coach_id`),
            KEY `idx_ca_athlete` (`athlete_id`),
            KEY `idx_ca_flagged` (`flagged_for_review`, `coaching_decision_id`),
            KEY `idx_ca_workout` (`planned_workout_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
    );
    echo "  ok.\n";
} else {
    echo "coach_adjustments already present — skipping.\n";
}

if (!$hasTable('coaching_decisions')) {
    echo "Creating coaching_decisions…\n";
    $db->exec(
        "CREATE TABLE `coaching_decisions` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `created_by`       INT NOT NULL,
            `created_at`       DATETIME NOT NULL,
            `updated_at`       DATETIME NULL,
            `status`           ENUM('active','inactive','proposed') NOT NULL DEFAULT 'proposed',
            `title`            VARCHAR(255) NOT NULL,
            `reason`           TEXT NOT NULL,
            `trigger_json`     LONGTEXT NOT NULL,
            `action_json`      LONGTEXT NOT NULL,
            `scope_distances`  LONGTEXT NULL,
            `scope_phases`     LONGTEXT NULL,
            `scope_plan_types` LONGTEXT NULL,
            `times_fired`      INT NOT NULL DEFAULT 0,
            `last_fired_at`    DATETIME NULL,
            `source`           ENUM('manual','proposed_from_adjustment') NOT NULL DEFAULT 'manual',
            KEY `idx_cd_creator` (`created_by`, `status`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
    );
    echo "  ok.\n";
} else {
    echo "coaching_decisions already present — skipping.\n";
}

if (!$hasTable('athlete_behavior_log')) {
    echo "Creating athlete_behavior_log…\n";
    $db->exec(
        "CREATE TABLE `athlete_behavior_log` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `athlete_id`      INT NOT NULL,
            `logged_at`       DATETIME NOT NULL,
            `metric_type`     ENUM(
                'rpe_vs_target','completion_rate','easy_pace_drift','response_time','engagement_score'
            ) NOT NULL,
            `metric_value`    FLOAT NOT NULL,
            `metric_context`  LONGTEXT NULL,
            `plan_week`       INT NULL,
            `phase`           VARCHAR(16) NULL,
            KEY `idx_abl_athlete_metric` (`athlete_id`, `metric_type`, `logged_at`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
    );
    echo "  ok.\n";
} else {
    echo "athlete_behavior_log already present — skipping.\n";
}

if (!$hasTable('coaching_intelligence_flags')) {
    echo "Creating coaching_intelligence_flags…\n";
    $db->exec(
        "CREATE TABLE `coaching_intelligence_flags` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `athlete_id`       INT NOT NULL,
            `coach_id`         INT NOT NULL,
            `created_at`       DATETIME NOT NULL,
            `flag_type`        ENUM(
                'rpe_trending_high','rpe_trending_low','compliance_dropping','compliance_streak',
                'engagement_dropping','adaptation_ahead_of_schedule','dropout_risk','plan_adjustment_recommended'
            ) NOT NULL,
            `severity`         ENUM('info','warning','opportunity') NOT NULL,
            `title`            VARCHAR(255) NOT NULL,
            `detail`           TEXT NOT NULL,
            `suggested_action` TEXT NULL,
            `suggested_adjustment` LONGTEXT NULL,
            `status`           ENUM('open','actioned','dismissed') NOT NULL DEFAULT 'open',
            `actioned_at`      DATETIME NULL,
            `dismissed_at`     DATETIME NULL,
            KEY `idx_cif_coach_status` (`coach_id`, `status`),
            KEY `idx_cif_athlete_type` (`athlete_id`, `flag_type`, `created_at`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
    );
    echo "  ok.\n";
} else {
    echo "coaching_intelligence_flags already present — skipping.\n";
}

if (!$hasColumn('users', 'last_login_at')) {
    echo "Adding users.last_login_at…\n";
    $db->exec("ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME NULL");
    echo "  ok.\n";
} else {
    echo "users.last_login_at already present — skipping.\n";
}

if (!$hasColumn('training_plans', 'coach_generation_notes')) {
    echo "Adding training_plans.coach_generation_notes…\n";
    $db->exec("ALTER TABLE `training_plans` ADD COLUMN `coach_generation_notes` LONGTEXT NULL");
    echo "  ok.\n";
} else {
    echo "training_plans.coach_generation_notes already present — skipping.\n";
}

echo date('Y-m-d H:i:s') . " — migration 027 complete.\n";

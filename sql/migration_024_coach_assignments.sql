-- ============================================================
-- Migration 024: coach assignments, assistant coaches, regeneration requests
--
-- NOTE: the spec called this "migration_022", but 022 (workout_thread) and 023
-- (hyrox_ever) already shipped, so this is 024.
--
-- 1) coach_assignments — authoritative athlete→coach (+ optional assistant coach)
--    mapping for the permission model. athletes.coach_id is kept in sync with
--    coach_assignments.coach_id by the app so all existing reads (Auth,
--    Notifications, billing) keep working unchanged.
-- 2) plan_regeneration_requests — assistant coach asks a head coach to rebuild a
--    plan; head coach approves (triggers generation) or dismisses.
-- 3) users.managed_by — head coach user_id for an assistant coach; NULL otherwise.
-- 4) users.must_change_password — forces a password change on next login (set when
--    an account is created from the admin panel with a temporary password).
-- 5) planned_workouts.added_by_role — 'assistant_coach' when an assistant coach
--    added the workout (drives a coach-only "AC" badge; never shown to athletes).
-- 6) engine_flags.flag_type += 'assistant_pace_zone_edit'.
-- 7) Seed coach_assignments for every existing athlete from their current
--    athletes.coach_id (fallback to user_id 1 — Will's admin account).
--
-- MariaDB constraints (see migration_018/021 notes): utf8 (not utf8mb4), no
-- IF NOT EXISTS on ALTER ADD COLUMN. The runner (scripts/run_migration_024.php)
-- guards each ADD COLUMN/CREATE TABLE against INFORMATION_SCHEMA and the seed is
-- idempotent (INSERT IGNORE on the unique athlete key). This raw file is for
-- fresh installs / manual application only.
--
--     php scripts/run_migration_024.php
-- ============================================================

SET NAMES utf8;

CREATE TABLE IF NOT EXISTS `coach_assignments` (
    `id`                 INT AUTO_INCREMENT PRIMARY KEY,
    `athlete_id`         INT NOT NULL,
    `coach_id`           INT NOT NULL,
    `assistant_coach_id` INT NULL,
    `assigned_at`        DATETIME NOT NULL,
    `assigned_by`        INT NOT NULL,
    UNIQUE KEY `unique_athlete` (`athlete_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `plan_regeneration_requests` (
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
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

ALTER TABLE `users`
    ADD COLUMN `managed_by` INT NULL
        COMMENT 'head coach user_id for assistant coaches; NULL otherwise' AFTER `role`;

ALTER TABLE `users`
    ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'force a password change on next login' AFTER `password_hash`;

ALTER TABLE `users`
    ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'deactivated accounts (0) cannot log in';

ALTER TABLE `planned_workouts`
    ADD COLUMN `added_by_role` VARCHAR(32) NULL
        COMMENT "'assistant_coach' when added by an assistant coach (coach-only AC badge)";

ALTER TABLE `engine_flags`
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
    ) NOT NULL;

-- Seed coach_assignments from each athlete's current coach (fallback user 1).
INSERT IGNORE INTO `coach_assignments` (`athlete_id`, `coach_id`, `assigned_at`, `assigned_by`)
SELECT a.id, COALESCE(a.coach_id, 1), NOW(), 1 FROM `athletes` a;

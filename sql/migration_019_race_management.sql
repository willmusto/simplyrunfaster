-- ============================================================
-- Migration 019: race management (tune-up races + goal race display)
--
-- The `races` table already exists (see schema.sql). This migration extends it
-- for the race-management feature rather than recreating it:
--   * race_distance ENUM gains the four ultra keys (50k/50_miler/100k/100_miler)
--     so a race can carry the same specific distance the engine uses.
--   * distance_override_unit — the miles/km the athlete chose for an "other" race
--     (distance_override stays in miles for the engine; this records the entry unit).
--   * result_notes — athlete's free-text notes logged with a race result
--     (distinct from `notes`, which is the coach's internal note on the race).
--   * updated_at — touched on result logging / recalibration.
--
-- engine_flags.flag_type gains: race_added (info, tune-up), goal_race_changed
-- (warning, new goal race), pace_recalibration (info, post-race zone update ready).
--
-- MariaDB constraints (see migration_016/018 notes): utf8 (not utf8mb4), no
-- IF NOT EXISTS on ALTER ADD COLUMN. The runner (scripts/run_migration_019.php)
-- guards each ADD COLUMN against INFORMATION_SCHEMA, so it is safe to re-run; the
-- ENUM MODIFYs re-state the full set. This raw file is for fresh installs.
--
--     php scripts/run_migration_019.php
-- ============================================================

SET NAMES utf8;

ALTER TABLE `races`
    MODIFY COLUMN `race_distance` ENUM(
        '5K','10K','15K','half','marathon','ultra','other',
        '50k','50_miler','100k','100_miler'
    ) NOT NULL;

ALTER TABLE `races`
    ADD COLUMN `distance_override_unit` ENUM('miles','km') DEFAULT NULL
        COMMENT 'unit the athlete entered for an "other" distance' AFTER `distance_override`,
    ADD COLUMN `result_notes` TEXT DEFAULT NULL
        COMMENT 'athlete free-text notes logged with the result' AFTER `result_synced_from_watch`,
    ADD COLUMN `updated_at` DATETIME DEFAULT NULL AFTER `created_at`;

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
        'race_added','goal_race_changed','pace_recalibration'
    ) NOT NULL;

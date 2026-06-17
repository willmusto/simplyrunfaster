-- ============================================================
-- Migration 018: ultra-marathon training distances
--
-- 1) athlete_profiles.ultra_surface — 'road' | 'trail', populated during
--    onboarding only for ultra goal distances (50K / 50 miler / 100K /
--    100 miler). NULL for every non-ultra athlete. Drives the engine's
--    trail-vs-road archetype weighting and the trail/power-hike long-run
--    instruction cues.
--
-- 2) engine_flags.flag_type += 'ultra_surface_reminder' — an info flag
--    raised once at plan generation for trail ultra athletes (night-run
--    suggestion for peak phase). See PlanGenerator.
--
-- MariaDB constraints (see migration_010/013/016 notes): utf8 (not
-- utf8mb4), no IF NOT EXISTS on ALTER TABLE ADD COLUMN. The accompanying
-- runner (scripts/run_migration_018.php) guards the ADD COLUMN against
-- INFORMATION_SCHEMA, so it is safe to re-run. This raw file is for fresh
-- installs / manual application only. The ENUM MODIFY re-states the full
-- current set and only appends a value, so it is order- and re-run-safe.
--
--     php scripts/run_migration_018.php
-- ============================================================

SET NAMES utf8;

ALTER TABLE `athlete_profiles`
    ADD COLUMN `ultra_surface` ENUM('road','trail') DEFAULT NULL
        COMMENT 'trail vs road, ultra goal distances only'
        AFTER `goal_finish_time`;

ALTER TABLE `engine_flags`
    MODIFY COLUMN `flag_type` ENUM(
        'missed_workouts','hr_elevated','load_spike','compliance_low',
        'plan_rebuild_needed','compliance_trend','compliance_pattern',
        'excessive_fatigue','fitness_decline','taper_concern',
        'insufficient_base','return_to_running_discomfort',
        'limited_development_opportunity','long_run_day_conflict',
        'display_generation_incomplete',
        'profile_updated','pace_zones_missing',
        'schedule_day_ramp','ultra_surface_reminder'
    ) NOT NULL;

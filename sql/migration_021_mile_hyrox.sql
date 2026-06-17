-- ============================================================
-- Migration 021: mile-focused training + Hyrox UI facade
--
-- 1) athlete_profiles.is_hyrox — set when the athlete selected Hyrox at
--    onboarding. The engine runs ordinary mile (goal_distance='mile') logic
--    underneath; is_hyrox drives the cosmetic Hyrox display facade only.
-- 2) engine_flags.flag_type += 'hyrox_supplement_reminder' — info flag raised
--    once per plan for Hyrox athletes (functional-fitness supplement reminder).
--
-- MariaDB constraints (see migration_018/020 notes): utf8 (not utf8mb4), no
-- IF NOT EXISTS on ALTER ADD COLUMN. The runner (scripts/run_migration_021.php)
-- guards the ADD COLUMN against INFORMATION_SCHEMA; the ENUM MODIFY re-states
-- the full set. This raw file is for fresh installs / manual application only.
--
--     php scripts/run_migration_021.php
-- ============================================================

SET NAMES utf8;

ALTER TABLE `athlete_profiles`
    ADD COLUMN `is_hyrox` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Hyrox UI facade; engine runs mile logic underneath'
        AFTER `ultra_surface`;

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
        'hyrox_supplement_reminder'
    ) NOT NULL;

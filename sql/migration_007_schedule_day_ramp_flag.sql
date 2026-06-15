-- ============================================================
-- Migration 007: engine_flags.flag_type += 'schedule_day_ramp'
-- Informational flag raised at plan generation (Item 2) when the
-- athlete's requested training_days_per_week exceeds what week-1
-- weekly volume can structurally support — the plan still generates
-- and ramps day count up as volume grows (NOT a hold state).
--
-- MariaDB 5.x safe: plain ENUM MODIFY. Run after
-- migration_006_rtr_current_stage.sql.
-- ============================================================

SET NAMES utf8;

ALTER TABLE `engine_flags`
    MODIFY COLUMN `flag_type` ENUM(
        'missed_workouts','hr_elevated','load_spike','compliance_low',
        'plan_rebuild_needed','compliance_trend','compliance_pattern',
        'excessive_fatigue','fitness_decline','taper_concern',
        'insufficient_base','return_to_running_discomfort',
        'limited_development_opportunity','long_run_day_conflict',
        'display_generation_incomplete',
        'profile_updated','pace_zones_missing',
        'schedule_day_ramp'
    ) NOT NULL;

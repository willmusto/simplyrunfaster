-- ============================================================
-- Migration 005: Expand engine_flags.flag_type ENUM
-- Adds flag types defined in schema.sql but never applied to
-- production (compliance_trend, compliance_pattern, etc.), plus
-- preserves long_run_day_conflict (present in production but
-- previously missing from schema.sql), and adds the new
-- display_generation_incomplete type.
--
-- Run after migration_003_archetype_instance_fields.sql.
-- ============================================================

SET NAMES utf8;

ALTER TABLE `engine_flags`
    MODIFY COLUMN `flag_type` ENUM(
        'missed_workouts','hr_elevated','load_spike','compliance_low',
        'plan_rebuild_needed','compliance_trend','compliance_pattern',
        'excessive_fatigue','fitness_decline','taper_concern',
        'insufficient_base','return_to_running_discomfort',
        'limited_development_opportunity','long_run_day_conflict',
        'display_generation_incomplete'
    ) NOT NULL;

-- ============================================================
-- Migration 004: Athlete profile editing + easy-pace zone derivation
--
-- NOTE ON NUMBERING: 004 is authored AFTER 005 in wall-clock time
-- (005 already shipped to production). The engine_flags ALTER below
-- therefore re-states the FULL current enum set and only APPENDS new
-- values, so it is safe to run regardless of ordering and never drops
-- a value 005 added.
--
-- MariaDB 5.3 constraints observed:
--   * No IF NOT EXISTS on ALTER TABLE ADD COLUMN — every column added
--     here was confirmed absent from production before authoring.
--   * LONGTEXT used instead of JSON.
--
-- Production audit (athlete_profiles) confirmed these target fields
-- ALREADY EXIST and need no change:
--   years_running (float), months_at_current_volume (int),
--   peak_weekly_minutes (int)  ← serves "highest-ever weekly volume",
--   scheduling_preference enum('fixed','flex') default 'flex',
--   primary_workout_day tinyint,
--   track_access enum('yes','no','road_reps_ok') default 'road_reps_ok'.
--
-- `workout_day_preference` was specified for removal but does NOT exist
-- in production (never implemented), so no DROP is issued — a bare
-- DROP COLUMN would error on MariaDB 5.3.
--
-- Run before deploying the profile-editing code.
-- ============================================================

SET NAMES utf8;

-- ── athlete_profiles ─────────────────────────────────────────
-- must_off_days: widen to LONGTEXT (was varchar(20)) to safely hold a
-- JSON-encoded array of day-of-week integers.
ALTER TABLE `athlete_profiles`
    MODIFY COLUMN `must_off_days` LONGTEXT DEFAULT NULL
        COMMENT 'JSON array of day numbers 0=Sun';

-- Easy-pace pathway: typical easy-day pace range (seconds per mile) and
-- the provenance of the derived pace_zones profile.
ALTER TABLE `athlete_profiles`
    ADD COLUMN `typical_easy_pace_min` INT DEFAULT NULL
        COMMENT 'Faster end of typical easy-day pace, seconds per mile'
        AFTER `pace_zones`,
    ADD COLUMN `typical_easy_pace_max` INT DEFAULT NULL
        COMMENT 'Slower end of typical easy-day pace, seconds per mile'
        AFTER `typical_easy_pace_min`,
    ADD COLUMN `pace_zones_source` ENUM('race_result','easy_pace_estimate','manual') DEFAULT NULL
        COMMENT 'How pace_zones was derived — drives estimated-vs-verified framing'
        AFTER `typical_easy_pace_max`;

-- ── engine_flags ─────────────────────────────────────────────
-- Append 'profile_updated' (profile-change audit flag) and
-- 'pace_zones_missing' (onboarding info flag when an athlete provides
-- neither a race result nor a typical easy pace) to the existing enum.
ALTER TABLE `engine_flags`
    MODIFY COLUMN `flag_type` ENUM(
        'missed_workouts','hr_elevated','load_spike','compliance_low',
        'plan_rebuild_needed','compliance_trend','compliance_pattern',
        'excessive_fatigue','fitness_decline','taper_concern',
        'insufficient_base','return_to_running_discomfort',
        'limited_development_opportunity','long_run_day_conflict',
        'display_generation_incomplete',
        'profile_updated','pace_zones_missing'
    ) NOT NULL;

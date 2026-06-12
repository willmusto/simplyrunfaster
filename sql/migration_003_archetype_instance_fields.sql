-- ============================================================
-- Migration 003: Archetype Instance Snapshot Fields
-- Adds per-generated-workout snapshot and display columns to
-- planned_workouts, supporting the archetype-based engine.
--
-- Run after migration_002_archetype_engine.sql and seed_archetypes.php.
-- Safe to re-run: each statement is guarded by column existence checks
-- or uses IF NOT EXISTS equivalents where available.
-- ============================================================

SET NAMES utf8;

ALTER TABLE `planned_workouts`
    ADD COLUMN IF NOT EXISTS `workout_archetype_id`        INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to workout_archetypes.id (snapshot; row may still exist if archetype is updated)'
        AFTER `archetype_params`,

    ADD COLUMN IF NOT EXISTS `archetype_version_snapshot`  TINYINT UNSIGNED DEFAULT NULL
        COMMENT 'Version of archetype at generation time for audit trail'
        AFTER `workout_archetype_id`,

    ADD COLUMN IF NOT EXISTS `instance_signature`          VARCHAR(255) DEFAULT NULL
        COMMENT 'Computed key used for anti-repeat detection (code|variant|params hash)'
        AFTER `archetype_version_snapshot`,

    ADD COLUMN IF NOT EXISTS `structure`                   LONGTEXT DEFAULT NULL
        COMMENT 'JSON: resolved segment structure rendered from archetype structure_template'
        AFTER `instance_signature`,

    ADD COLUMN IF NOT EXISTS `display_title`               VARCHAR(255) DEFAULT NULL
        COMMENT 'Athlete-facing workout title, generated once at plan creation'
        AFTER `structure`,

    ADD COLUMN IF NOT EXISTS `display_summary`             VARCHAR(255) DEFAULT NULL
        COMMENT 'Athlete-facing one-line summary (duration, distance range, rep count, etc.)'
        AFTER `display_title`,

    ADD COLUMN IF NOT EXISTS `athlete_instructions`        TEXT DEFAULT NULL
        COMMENT 'Athlete-facing workout description generated from archetype display.description_template'
        AFTER `display_summary`;

-- Index for anti-repeat queries (signature lookups by athlete + date window)
ALTER TABLE `planned_workouts`
    ADD INDEX IF NOT EXISTS `idx_pw_sig_athlete_date` (`athlete_id`, `instance_signature`, `scheduled_date`);

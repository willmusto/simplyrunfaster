-- ============================================================
-- Migration 002: Archetype-Based Training Engine
-- Run against an existing Milestone-1 database.
-- MariaDB / MySQL compatible.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS, no destructive
-- changes to tables that already carry live data.
-- ============================================================

SET NAMES utf8;

-- ============================================================
-- 1. workout_archetypes — new table
-- ============================================================

CREATE TABLE IF NOT EXISTS `workout_archetypes` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`               VARCHAR(60)  NOT NULL COMMENT 'stable slug, e.g. continuous_easy',
    `version`            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `status`             ENUM('active','inactive','draft') NOT NULL DEFAULT 'active',

    -- Core identity
    `name`               VARCHAR(255) NOT NULL,
    `workout_type`       ENUM(
                             'easy','long','tempo','interval','hill','fartlek',
                             'race_pace','recovery','rest','cross_train',
                             'speed','plyometric'
                         ) NOT NULL,
    `mapped_templates`   LONGTEXT DEFAULT NULL COMMENT 'JSON array of WL-xxx codes',
    `description`        TEXT DEFAULT NULL,

    -- Engine selection (all stored as JSON objects)
    `selection`          LONGTEXT NOT NULL COMMENT 'JSON: slot_types, phases, plan_types, goal_distances, min_classification, track_requirement, coach_clearance_required, requires, excludes',
    `weights`            LONGTEXT NOT NULL COMMENT 'JSON: phase/goal_distance/classification/plan_type weight maps (0-10)',
    `generation`         LONGTEXT NOT NULL COMMENT 'JSON: prescription_model, duration_source, progression_model, recovery_model, intensity_factor',

    -- Template definition (JSON objects)
    `variants`           LONGTEXT DEFAULT NULL COMMENT 'JSON array of {code, name, ...} variant objects',
    `parameters`         LONGTEXT DEFAULT NULL COMMENT 'JSON: parameter definitions with workable/well_trained ranges',
    `structure_template` LONGTEXT DEFAULT NULL COMMENT 'JSON: segment structure template with {{token}} placeholders',
    `display`            LONGTEXT DEFAULT NULL COMMENT 'JSON: lead_with, title_template, summary_template, description_template',
    `instance_signature` LONGTEXT DEFAULT NULL COMMENT 'JSON: field list used to identify a unique generated instance',
    `coach_notes`        LONGTEXT DEFAULT NULL COMMENT 'JSON: intended_use string, special_rules array',

    -- Ownership / visibility
    `created_by`         INT UNSIGNED DEFAULT NULL COMMENT 'coach user_id; NULL = system archetype',
    `platform_wide`      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'system archetypes always platform-wide; coach-created default 0',
    `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME  DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_archetype_code`       (`code`),
    KEY         `idx_archetype_status`    (`status`),
    KEY         `idx_archetype_type`      (`workout_type`),
    KEY         `idx_archetype_created_by`(`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


-- ============================================================
-- 2. Extend workout_type ENUMs to include speed + plyometric
-- ============================================================

ALTER TABLE `planned_workouts`
    MODIFY COLUMN `workout_type`
        ENUM('easy','long','tempo','interval','hill','fartlek',
             'race_pace','recovery','rest','cross_train',
             'speed','plyometric') NOT NULL;

ALTER TABLE `workout_library`
    MODIFY COLUMN `workout_type`
        ENUM('easy','long','tempo','interval','hill','fartlek',
             'race_pace','recovery','rest','cross_train',
             'speed','plyometric') NOT NULL;


-- ============================================================
-- 3. planned_workouts — replace workout_template_id with archetype fields
--
-- No active athletes: DROP is safe.  All three ADD COLUMNs come
-- right after workout_type so the column order stays readable.
-- ============================================================

ALTER TABLE `planned_workouts`
    DROP   COLUMN `workout_template_id`,
    ADD    COLUMN `archetype_code`    VARCHAR(60) DEFAULT NULL
               COMMENT 'References workout_archetypes.code'
               AFTER `workout_type`,
    ADD    COLUMN `archetype_variant` VARCHAR(60) DEFAULT NULL
               COMMENT 'Variant code selected at generation time'
               AFTER `archetype_code`,
    ADD    COLUMN `archetype_params`  LONGTEXT    DEFAULT NULL
               COMMENT 'JSON: resolved parameters (rep_count, duration, effort, etc.)'
               AFTER `archetype_variant`,
    ADD    KEY `idx_pw_archetype` (`archetype_code`);


-- ============================================================
-- 4. athlete_profiles — classification, clearances, terrain
-- ============================================================

-- Rename the plyometric clearance field to its canonical engine name.
ALTER TABLE `athlete_profiles`
    CHANGE COLUMN `coach_clearance_bounding` `plyometric_clearance`
        TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Cleared for plyometric/bounding workouts; auto-set if track_field_background=1';

-- Add new classification and constraint fields used by ArchetypeSelector.
ALTER TABLE `athlete_profiles`
    ADD COLUMN `track_field_background` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Has track or field athletics background; auto-grants plyometric_clearance'
        AFTER `track_access`,

    ADD COLUMN `hill_access` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Has access to hilly terrain; required for sustained_hill_repeats and plyometric_hill_circuits'
        AFTER `track_field_background`,

    ADD COLUMN `base_classification`
        ENUM('well_trained','workable','insufficient') DEFAULT NULL
        COMMENT 'Cached engine classification (recomputed on profile update or coach override)'
        AFTER `hill_access`;


-- ============================================================
-- After running this migration, execute:
--   php scripts/seed_archetypes.php
-- to populate the workout_archetypes table with the 16 system
-- archetypes from the cleaned JSON spec.
-- ============================================================

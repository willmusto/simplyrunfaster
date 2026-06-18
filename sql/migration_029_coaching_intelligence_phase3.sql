-- ============================================================
-- Migration 029 — Coaching Intelligence Layer (Phase 3 of 4)
-- Predictive flags & athlete response modeling.
--
-- Canonical DDL. Production runner is scripts/run_migration_029.php
-- (idempotent: checks table/column/enum existence before CREATE/ALTER).
--
-- Phase 3 reads from athlete_behavior_log, coaching_intelligence_flags,
-- training_load, completed_workouts, planned_workouts, athlete_profiles and
-- writes only to its own intelligence tables: athlete_response_profiles plus
-- new columns/flag types on coaching_intelligence_flags.
--
-- Schema constraints (MariaDB 5.3, MyISAM throughout):
--   - New tables ENGINE=MyISAM DEFAULT CHARSET=utf8
--   - No FOREIGN KEY constraints
--   - No JSON column type — LONGTEXT for all JSON fields
--   - No IF NOT EXISTS on ALTER ADD COLUMN (runner guards via INFORMATION_SCHEMA)
-- ============================================================

SET NAMES utf8;

-- One row per athlete: the interpretable response-profile metrics (each with
-- value / sample_size / confidence) that individualize Phase 3 predictions and
-- surface in the athlete context panel.
CREATE TABLE IF NOT EXISTS `athlete_response_profiles` (
    `athlete_id`    INT NOT NULL,
    `computed_at`   DATETIME NOT NULL,
    `weeks_of_data` INT NOT NULL DEFAULT 0,
    `metrics_json`  LONGTEXT NULL,
    PRIMARY KEY (`athlete_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Predictive metadata on the existing flag feed (NULL for Phase 1/2 flags).
ALTER TABLE `coaching_intelligence_flags`
    ADD COLUMN `confidence` ENUM('low','medium','high') NULL
        COMMENT 'Phase 3 predictive confidence tier; NULL for non-predictive flags';
ALTER TABLE `coaching_intelligence_flags`
    ADD COLUMN `prediction_horizon_days` INT NULL
        COMMENT 'Phase 3: how many days ahead the prediction looks';
ALTER TABLE `coaching_intelligence_flags`
    ADD COLUMN `predicted_for_date` DATE NULL
        COMMENT 'Phase 3: target date the prediction is about';

-- Four predictive flag types added to the existing ENUM.
ALTER TABLE `coaching_intelligence_flags`
    MODIFY COLUMN `flag_type` ENUM(
        'rpe_trending_high','rpe_trending_low','compliance_dropping','compliance_streak',
        'engagement_dropping','adaptation_ahead_of_schedule','dropout_risk','plan_adjustment_recommended',
        'predicted_fatigue','predicted_dropout','injury_risk_pattern','adaptation_ahead'
    ) NOT NULL;

-- ============================================================
-- Migration 027 — Coaching Intelligence Layer (Phase 1 of 4)
--
-- Canonical DDL. Production runner is scripts/run_migration_027.php
-- (idempotent: checks table/column existence before CREATE/ALTER).
--
-- Schema constraints (MariaDB 5.3, MyISAM throughout):
--   - All new tables ENGINE=MyISAM DEFAULT CHARSET=utf8
--   - No FOREIGN KEY constraints
--   - No JSON column type — LONGTEXT for all JSON fields
-- ============================================================

-- Capture of every coach (or athlete-initiated) adjustment to a planned workout,
-- with a frozen snapshot of athlete context so patterns stay analyzable even after
-- the athlete's profile later changes.
CREATE TABLE IF NOT EXISTS `coach_adjustments` (
  `id`                    INT AUTO_INCREMENT PRIMARY KEY,
  `planned_workout_id`    INT NOT NULL,
  `athlete_id`            INT NOT NULL,
  `coach_id`              INT NOT NULL,
  `adjusted_at`           DATETIME NOT NULL,
  `flagged_for_review`    TINYINT(1) NOT NULL DEFAULT 0,

  `change_type`           ENUM(
    'archetype_substitution',
    'duration_change',
    'day_swap',
    'workout_removed',
    'workout_added',
    'instructions_edited',
    'pace_zone_edit'
  ) NOT NULL,

  -- Before state snapshot
  `before_archetype_code` VARCHAR(64) NULL,
  `before_workout_type`   VARCHAR(32) NULL,
  `before_duration_mins`  INT NULL,
  `before_scheduled_date` DATE NULL,
  `before_instructions`   LONGTEXT NULL,

  -- After state snapshot
  `after_archetype_code`  VARCHAR(64) NULL,
  `after_workout_type`    VARCHAR(32) NULL,
  `after_duration_mins`   INT NULL,
  `after_scheduled_date`  DATE NULL,
  `after_instructions`    LONGTEXT NULL,

  -- Athlete context snapshot at adjustment time
  `ctx_goal_distance`     VARCHAR(32) NULL,
  `ctx_phase`             VARCHAR(16) NULL,
  `ctx_week_number`       INT NULL,
  `ctx_classification`    VARCHAR(16) NULL,
  `ctx_weekly_mins`       INT NULL,
  `ctx_plan_week`         INT NULL,

  -- Optional reason added during weekly review
  `reason_tag`            ENUM(
    'athlete_fatigue',
    'schedule_conflict',
    'insufficient_recovery',
    'wrong_phase',
    'athlete_preference',
    'injury_concern',
    'weather_conditions',
    'race_preparation',
    'coach_preference',
    'other'
  ) NULL,
  `reason_notes`          TEXT NULL,

  -- Set when this adjustment is approved as a rule
  `coaching_decision_id`  INT NULL,

  KEY `idx_ca_coach` (`coach_id`),
  KEY `idx_ca_athlete` (`athlete_id`),
  KEY `idx_ca_flagged` (`flagged_for_review`, `coaching_decision_id`),
  KEY `idx_ca_workout` (`planned_workout_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Coaching rules distilled from flagged adjustments (or authored manually). The
-- decision resolver in PlanGenerator consults active rows at generation time.
CREATE TABLE IF NOT EXISTS `coaching_decisions` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `created_by`       INT NOT NULL,
  `created_at`       DATETIME NOT NULL,
  `updated_at`       DATETIME NULL,
  `status`           ENUM('active','inactive','proposed') NOT NULL DEFAULT 'proposed',

  `title`            VARCHAR(255) NOT NULL,
  `reason`           TEXT NOT NULL,

  -- LONGTEXT (not JSON type) for structured conditions / actions
  `trigger_json`     LONGTEXT NOT NULL,
  `action_json`      LONGTEXT NOT NULL,

  `scope_distances`  LONGTEXT NULL,
  `scope_phases`     LONGTEXT NULL,
  `scope_plan_types` LONGTEXT NULL,

  `times_fired`      INT NOT NULL DEFAULT 0,
  `last_fired_at`    DATETIME NULL,
  `source`           ENUM('manual','proposed_from_adjustment') NOT NULL DEFAULT 'manual',

  KEY `idx_cd_creator` (`created_by`, `status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Daily behavior metrics per athlete (90-day rolling retention, cleaned by the
-- daily retention cron).
CREATE TABLE IF NOT EXISTS `athlete_behavior_log` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `athlete_id`      INT NOT NULL,
  `logged_at`       DATETIME NOT NULL,
  `metric_type`     ENUM(
    'rpe_vs_target',
    'completion_rate',
    'easy_pace_drift',
    'response_time',
    'engagement_score'
  ) NOT NULL,
  `metric_value`    FLOAT NOT NULL,
  `metric_context`  LONGTEXT NULL,
  `plan_week`       INT NULL,
  `phase`           VARCHAR(16) NULL,

  KEY `idx_abl_athlete_metric` (`athlete_id`, `metric_type`, `logged_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Pattern-detection flags surfaced to coaches on the Intelligence page.
CREATE TABLE IF NOT EXISTS `coaching_intelligence_flags` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `athlete_id`       INT NOT NULL,
  `coach_id`         INT NOT NULL,
  `created_at`       DATETIME NOT NULL,
  `flag_type`        ENUM(
    'rpe_trending_high',
    'rpe_trending_low',
    'compliance_dropping',
    'compliance_streak',
    'engagement_dropping',
    'adaptation_ahead_of_schedule',
    'dropout_risk',
    'plan_adjustment_recommended'
  ) NOT NULL,
  `severity`         ENUM('info','warning','opportunity') NOT NULL,
  `title`            VARCHAR(255) NOT NULL,
  `detail`           TEXT NOT NULL,
  `suggested_action` TEXT NULL,
  `suggested_adjustment` LONGTEXT NULL,
  `status`           ENUM('open','actioned','dismissed') NOT NULL DEFAULT 'open',
  `actioned_at`      DATETIME NULL,
  `dismissed_at`     DATETIME NULL,

  KEY `idx_cif_coach_status` (`coach_id`, `status`),
  KEY `idx_cif_athlete_type` (`athlete_id`, `flag_type`, `created_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Last successful login (used by the engagement_score behavior metric).
ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME NULL;

-- Per-generation log of which coaching decisions fired (and any conflicts).
ALTER TABLE `training_plans` ADD COLUMN `coach_generation_notes` LONGTEXT NULL;

-- ============================================================
-- Migration 028 — Coaching Intelligence Layer (Phase 2 of 4)
--
-- Canonical DDL. Production runner is scripts/run_migration_028.php
-- (idempotent: checks table/column existence before CREATE/ALTER).
--
-- Phase 2 adds: the pattern proposer (proposed coaching_decisions distilled
-- from recurring adjustments), cross-athlete roster insights, and the weekly
-- coaching review log.
--
-- Schema constraints (MariaDB 5.3, MyISAM throughout):
--   - All new tables ENGINE=MyISAM DEFAULT CHARSET=utf8
--   - No FOREIGN KEY constraints
--   - No JSON column type — LONGTEXT for all JSON fields
--   - No IF NOT EXISTS on ALTER ADD COLUMN (runner guards via INFORMATION_SCHEMA)
-- ============================================================

SET NAMES utf8;

-- How a proposed rule was distilled (pattern proposer bookkeeping).
ALTER TABLE `coaching_decisions`
    ADD COLUMN `proposed_from_count` INT NULL
        COMMENT 'how many adjustments triggered this proposal';
ALTER TABLE `coaching_decisions`
    ADD COLUMN `proposed_at` DATETIME NULL
        COMMENT 'when the pattern proposer generated this';

-- Set when an adjustment contributed to (or is already covered by) a proposal,
-- so the proposer never reconsiders it.
ALTER TABLE `coach_adjustments`
    ADD COLUMN `proposed_decision_id` INT NULL
        COMMENT 'set when this adjustment contributed to a proposal';

-- Cross-athlete patterns surfaced to a coach (the Roster Insights feed).
CREATE TABLE IF NOT EXISTS `coach_roster_insights` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`        INT NOT NULL,
    `created_at`      DATETIME NOT NULL,
    `insight_type`    ENUM(
        'compliance_cluster',
        'engagement_cluster',
        'upcoming_races',
        'adjustment_pattern',
        'streak_cluster',
        'workload_spike'
    ) NOT NULL,
    `title`           VARCHAR(255) NOT NULL,
    `detail`          TEXT NOT NULL,
    `athlete_ids`     LONGTEXT NOT NULL,
    `severity`        ENUM('info','warning','opportunity') NOT NULL DEFAULT 'info',
    `status`          ENUM('open','dismissed') NOT NULL DEFAULT 'open',
    `dismissed_at`    DATETIME NULL,
    KEY `idx_cri_coach_status` (`coach_id`, `status`),
    KEY `idx_cri_type_created` (`insight_type`, `created_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- One row per coach per week recording that the weekly review was completed.
CREATE TABLE IF NOT EXISTS `weekly_review_log` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`        INT NOT NULL,
    `week_start`      DATE NOT NULL,
    `completed_at`    DATETIME NULL,
    `items_reviewed`  INT NOT NULL DEFAULT 0,
    `decisions_added` INT NOT NULL DEFAULT 0,
    `flags_actioned`  INT NOT NULL DEFAULT 0,
    `flags_dismissed` INT NOT NULL DEFAULT 0,
    UNIQUE KEY `unique_coach_week` (`coach_id`, `week_start`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

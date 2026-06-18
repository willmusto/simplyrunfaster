-- ============================================================
-- Migration 031 — Regen carry-over of athlete-exposed weeks
--
-- Canonical DDL. Production runner is scripts/run_migration_031.php
-- (idempotent: checks column existence before ALTER).
--
-- A regen now PRESERVES every whole week the athlete has already seen (and any
-- coach_locked row) by MOVING those planned_workouts rows into the new plan and
-- marking them carried. These two columns record that provenance; the row keeps
-- its id (so the srf_{id} Intervals.icu event survives) and all content.
--
-- Schema constraints (MariaDB 5.3, MyISAM throughout):
--   - utf8 (not utf8mb4); no FOREIGN KEY constraints; MariaDB-5.3-safe
--   - No IF NOT EXISTS on ALTER ADD COLUMN (runner guards via INFORMATION_SCHEMA)
-- ============================================================

SET NAMES utf8;

ALTER TABLE `planned_workouts`
    ADD COLUMN `carried_over_from_plan_id` INT NULL
        COMMENT 'set when this row was carried into a regenerated plan from the prior plan';
ALTER TABLE `planned_workouts`
    ADD COLUMN `carried_over_at` DATETIME NULL
        COMMENT 'when the row was carried over during a regen';

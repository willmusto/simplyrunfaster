-- ============================================================
-- Migration 006: training_plans.rtr_current_stage
-- Tracks the current run/walk stage (1-10) for return_to_running
-- plans. NULL for every other plan_type. Initialised to 1 at plan
-- creation by PlanGenerator::generateReturnToRunning(). The adaptive
-- per-session stage advancement is a follow-on; this column is the
-- foundation it will read/update.
--
-- MariaDB 5.x safe: plain nullable INT, no JSON/generated columns.
-- Run after migration_005_engine_flags_enum.sql.
-- ============================================================

SET NAMES utf8;

ALTER TABLE `training_plans`
    ADD COLUMN `rtr_current_stage` INT DEFAULT NULL
        COMMENT 'return_to_running run/walk stage 1-10; NULL for other plan types'
        AFTER `plan_type`;

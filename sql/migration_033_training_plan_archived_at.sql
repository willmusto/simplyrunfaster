-- ============================================================
-- Migration 033: training_plans.archived_at
--
-- Age-based pruning of archived plans (retention cron) needs a timestamp;
-- status='archived' alone carries none. Adds archived_at, set at archive time
-- going forward (PlanGenerator / CoachController) and backfilled here for
-- existing archived plans from generated_at (the best available timestamp —
-- training_plans has no updated_at).
--
-- MariaDB 5.x safe: plain ADD COLUMN, no IF NOT EXISTS. The runner
-- (scripts/run_migration_033.php) guards the ADD COLUMN against
-- INFORMATION_SCHEMA, so it is idempotent. This raw file is for fresh installs.
--
--     php scripts/run_migration_033.php
-- ============================================================

SET NAMES utf8;

ALTER TABLE `training_plans`
    ADD COLUMN `archived_at` DATETIME DEFAULT NULL
        COMMENT 'set when status becomes archived; enables age-based retention'
        AFTER `status`;

UPDATE `training_plans`
    SET `archived_at` = `generated_at`
    WHERE `status` = 'archived' AND `archived_at` IS NULL;

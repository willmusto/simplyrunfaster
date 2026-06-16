-- ============================================================
-- Migration 012: Soft-delete (cancel) for planned workouts
-- Lets a coach remove a workout from the macro plan without
-- destroying the training-log audit trail. Cancelled workouts
-- are excluded from every athlete-facing and load-computation
-- query; the coach view renders a faint "Removed" marker.
--
-- MariaDB-safe (plain ADD COLUMN, no IF NOT EXISTS needed —
-- run once after migration_011_device_notify.sql).
-- ============================================================

ALTER TABLE `planned_workouts`
    ADD COLUMN `cancelled`    TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Coach soft-deleted this workout; day renders as rest'
        AFTER `notes`,
    ADD COLUMN `cancelled_at` DATETIME NULL
        COMMENT 'When the workout was cancelled'
        AFTER `cancelled`,
    ADD COLUMN `cancelled_by` INT NULL
        COMMENT 'user_id of the coach who cancelled it'
        AFTER `cancelled_at`;

ALTER TABLE `planned_workouts`
    ADD INDEX `idx_pw_cancelled` (`plan_id`, `cancelled`);

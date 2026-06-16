-- ============================================================
-- Migration 008: users.timezone
-- Per-user IANA timezone preference. The server and all stored
-- DATETIME/DATE values remain UTC; conversion to local time happens
-- at read/write time in PHP (see src/Timezone.php). The athlete's
-- timezone governs plan-generation day boundaries ("tomorrow") and
-- the rolling-window cron; display surfaces convert UTC → the
-- viewing user's timezone.
--
-- Existing rows default to America/New_York. Run after
-- migration_007_schedule_day_ramp_flag.sql.
--
-- MariaDB 5.x safe: plain ADD COLUMN with a literal default.
-- ============================================================

SET NAMES utf8;

ALTER TABLE `users`
    ADD COLUMN `timezone` VARCHAR(64) NOT NULL DEFAULT 'America/New_York'
    AFTER `theme_preference`;

-- Backfill is implicit via the column default, but make it explicit
-- for any row that somehow lands NULL (defensive; column is NOT NULL).
UPDATE `users` SET `timezone` = 'America/New_York'
    WHERE `timezone` IS NULL OR `timezone` = '';

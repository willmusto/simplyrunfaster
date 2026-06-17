-- ============================================================
-- Migration 020: Terms of Service consent
--
-- Adds the onboarding ToS-acceptance columns to `users` and grandfathers all
-- pre-existing users as consented (they predate the Terms, effective
-- 2026-06-17).
--
-- MariaDB constraints (see migration_013 notes): utf8 (not utf8mb4), no
-- IF NOT EXISTS on ALTER TABLE ADD COLUMN. The accompanying runner
-- (scripts/run_migration_020.php) guards each ADD COLUMN against
-- INFORMATION_SCHEMA and only runs the grandfather backfill the first time the
-- columns are created, so it is safe to re-run. This raw file is for fresh
-- installs / manual application only.
--
--     php scripts/run_migration_020.php
-- ============================================================

SET NAMES utf8;

ALTER TABLE `users`
    ADD COLUMN `consent_tos`    TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Terms of Service agreed at onboarding'
        AFTER `consent_privacy`,
    ADD COLUMN `consent_tos_at` DATETIME DEFAULT NULL
        COMMENT 'when ToS consent was recorded'
        AFTER `consent_tos`;

-- Grandfather existing users (predate the Terms).
UPDATE `users`
   SET `consent_tos` = 1, `consent_tos_at` = NOW()
 WHERE `consent_tos_at` IS NULL;

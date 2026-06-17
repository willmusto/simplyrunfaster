-- ============================================================
-- Migration 016: invite-link deactivation
--
-- Adds invite_links.deactivated_at so a coach can manually disable an
-- active/unused invite link from the Invite Athletes panel. A non-NULL
-- value means the link can no longer be used for onboarding.
--
-- MariaDB/MyISAM constraints (see migration_010/013/014/015 notes): utf8
-- (not utf8mb4), no IF NOT EXISTS on ALTER TABLE ADD COLUMN. The accompanying
-- runner (scripts/run_migration_016.php) guards the ADD COLUMN against
-- INFORMATION_SCHEMA, so it is safe to re-run. This raw file is for fresh
-- installs / manual application only.
--
--     php scripts/run_migration_016.php
-- ============================================================

SET NAMES utf8;

ALTER TABLE `invite_links`
    ADD COLUMN `deactivated_at` DATETIME DEFAULT NULL
        COMMENT 'set when a coach manually deactivates the link'
        AFTER `used_by`;

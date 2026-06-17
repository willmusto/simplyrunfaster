-- ============================================================
-- Migration 015: session-card reply_count
--
-- The message thread now carries ONE "session card" row per completed
-- workout (message_type='session_note'); every later comment re-floats that
-- single card and bumps this counter instead of inserting a new card.
--
-- MariaDB/MyISAM constraints (see migration_010/013/014 notes): utf8 (not
-- utf8mb4), no IF NOT EXISTS on ALTER TABLE ADD COLUMN. The accompanying
-- runner (scripts/run_migration_015.php) guards the ADD COLUMN against
-- INFORMATION_SCHEMA, so it is safe to re-run. This raw file is for fresh
-- installs / manual application only.
--
--     php scripts/run_migration_015.php
-- ============================================================

SET NAMES utf8;

ALTER TABLE `messages`
    ADD COLUMN `reply_count` INT NOT NULL DEFAULT 0
        COMMENT 'session card: comments after the first (re-float counter)'
        AFTER `thread_id`;

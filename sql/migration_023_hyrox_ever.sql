-- ============================================================
-- Migration 023: athlete_profiles.hyrox_ever
--
-- Latches to 1 the first time an athlete selects Hyrox and never resets, so the
-- Hyrox goal-distance pill stays visible in Training Settings after they switch
-- away. is_hyrox tracks the *current* selection; hyrox_ever tracks "ever chosen".
--
-- MariaDB constraints (see migration_018/021 notes): utf8 (not utf8mb4), no
-- IF NOT EXISTS on ALTER ADD COLUMN. The runner (scripts/run_migration_023.php)
-- guards the ADD COLUMN against INFORMATION_SCHEMA. This raw file is for fresh
-- installs / manual application only.
--
--     php scripts/run_migration_023.php
-- ============================================================

SET NAMES utf8;

ALTER TABLE `athlete_profiles`
    ADD COLUMN `hyrox_ever` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Latches to 1 once Hyrox is ever selected; keeps the Hyrox pill visible'
        AFTER `is_hyrox`;

-- Backfill: anyone currently on Hyrox has obviously chosen it before.
UPDATE `athlete_profiles` SET `hyrox_ever` = 1 WHERE `is_hyrox` = 1;

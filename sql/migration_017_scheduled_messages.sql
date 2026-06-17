-- ============================================================
-- Migration 017: scheduled_messages
--
-- Backs delayed coach messages — currently the onboarding welcome note,
-- scheduled ~12 minutes after an athlete finishes onboarding and delivered by
-- scripts/cron_scheduled_messages.php.
--
-- MyISAM/utf8, consistent with the rest of the live schema. CREATE TABLE
-- IF NOT EXISTS is inherently idempotent; the runner (run_migration_017.php)
-- is safe to re-run.
--
--     php scripts/run_migration_017.php
-- ============================================================

SET NAMES utf8;

CREATE TABLE IF NOT EXISTS `scheduled_messages` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `athlete_id` INT UNSIGNED NOT NULL,
    `sender_id`  INT UNSIGNED NOT NULL COMMENT 'coach user_id',
    `body`       TEXT NOT NULL,
    `send_after` DATETIME NOT NULL,
    `sent`       TINYINT(1) NOT NULL DEFAULT 0,
    `sent_at`    DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sm_pending` (`sent`, `send_after`),
    KEY `idx_sm_athlete` (`athlete_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

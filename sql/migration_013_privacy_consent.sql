-- ============================================================
-- Migration 013: Privacy consent + account deletion
--
-- 1. Adds onboarding-consent columns and a soft-delete marker to `users`.
-- 2. Creates the `account_deletions` audit table used by the 90-day
--    retention cron (scripts/cron_delete_expired_accounts.php).
-- 3. Grandfathers all pre-existing users as consented (they predate the
--    policy effective 2026-06-16).
--
-- MariaDB constraints (see migration_010 notes): utf8 (not utf8mb4),
-- no IF NOT EXISTS on ALTER TABLE ADD COLUMN. The accompanying runner
-- (scripts/run_migration_013.php) guards every ADD COLUMN against
-- INFORMATION_SCHEMA and only runs the grandfather backfill the first
-- time the columns are created, so it is safe to re-run. This raw file
-- is for fresh installs / manual application only.
--
--     php scripts/run_migration_013.php
-- ============================================================

SET NAMES utf8;

-- ── users: consent + soft-delete ─────────────────────────────
ALTER TABLE `users`
    ADD COLUMN `consent_age`      TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '18+/parental consent confirmed at onboarding'
        AFTER `invite_code`,
    ADD COLUMN `consent_privacy`  TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Privacy Policy agreed at onboarding'
        AFTER `consent_age`,
    ADD COLUMN `consent_given_at` DATETIME DEFAULT NULL
        COMMENT 'when both consents were recorded'
        AFTER `consent_privacy`,
    ADD COLUMN `deleted_at`       DATETIME DEFAULT NULL
        COMMENT 'set when the account is anonymized by the retention cron'
        AFTER `consent_given_at`;

-- ── account deletion audit log ───────────────────────────────
CREATE TABLE IF NOT EXISTS `account_deletions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT NOT NULL,
    `deleted_at` DATETIME NOT NULL,
    `reason`     VARCHAR(64) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_acctdel_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- ── grandfather existing users (predate the policy) ──────────
UPDATE `users`
   SET `consent_age` = 1, `consent_privacy` = 1, `consent_given_at` = NOW()
 WHERE `consent_given_at` IS NULL;

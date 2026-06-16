-- ============================================================
-- Migration 010: Stripe billing (Milestone 8, partial)
--
-- Adds subscription state to `users`, billing/discount fields to
-- `invite_links`, and a `stripe_webhook_log` debug table.
--
-- NOTE: the canonical subscription state for an athlete lives on the
-- USERS row (per the Milestone 8 spec). The pre-existing billing columns
-- on `athletes` (stripe_customer_id, billing_status, …) date from the
-- Milestone 1 schema and are left in place but unused by the billing
-- engine — `users` is the single source of truth.
--
-- MariaDB 5.3 constraints observed:
--   * utf8 (not utf8mb4); LONGTEXT (not JSON).
--   * No IF NOT EXISTS on ALTER TABLE ADD COLUMN. The accompanying
--     runner (scripts/run_migration_010.php) guards every ADD COLUMN
--     against INFORMATION_SCHEMA so it is safe to re-run; this raw file
--     is for fresh installs / manual application only.
--
-- Per-user default rows for the two new notification types are seeded by
-- PHP (role-dependent), not SQL. Run AFTER applying this file:
--     php scripts/run_migration_010.php
-- ============================================================

SET NAMES utf8;

-- ── users: subscription state ────────────────────────────────
ALTER TABLE `users`
    ADD COLUMN `stripe_customer_id`    VARCHAR(64) DEFAULT NULL
        COMMENT 'Stripe customer id (cus_…)'
        AFTER `invite_code`,
    ADD COLUMN `subscription_status`   ENUM('none','trialing','active','past_due','canceled','comped')
        NOT NULL DEFAULT 'none'
        AFTER `stripe_customer_id`,
    ADD COLUMN `subscription_end_date` DATE DEFAULT NULL
        COMMENT 'access-until date for canceled; NULL for comped/forever'
        AFTER `subscription_status`,
    ADD COLUMN `billing_interval`      ENUM('monthly','annual') DEFAULT NULL
        AFTER `subscription_end_date`,
    ADD COLUMN `grace_period_ends`     DATE DEFAULT NULL
        COMMENT 'set on first payment failure; NULL otherwise'
        AFTER `billing_interval`;

-- ── invite_links: discount + offered interval ────────────────
ALTER TABLE `invite_links`
    ADD COLUMN `discount_percent`  TINYINT DEFAULT NULL
        COMMENT '25, 50, or 100'
        AFTER `coupon_code`,
    ADD COLUMN `discount_duration` VARCHAR(16) DEFAULT NULL
        COMMENT '30d,60d,90d,120d,365d,forever'
        AFTER `discount_percent`,
    ADD COLUMN `stripe_coupon_id`  VARCHAR(64) DEFAULT NULL
        COMMENT 'created at link-generation time'
        AFTER `discount_duration`,
    ADD COLUMN `billing_interval`  ENUM('monthly','annual') DEFAULT NULL
        COMMENT 'interval offered to the athlete at checkout'
        AFTER `stripe_coupon_id`;

-- ── stripe webhook log (debugging / idempotency) ─────────────
CREATE TABLE IF NOT EXISTS `stripe_webhook_log` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_id`    VARCHAR(64) NOT NULL,
    `event_type`  VARCHAR(80) NOT NULL,
    `payload`     LONGTEXT DEFAULT NULL,
    `received_at` DATETIME NOT NULL,
    `processed`   TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_swl_event` (`event_id`),
    KEY `idx_swl_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

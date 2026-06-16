-- Migration 009 — Notification system (Section 28)
-- MariaDB 5.3-safe / utf8. Idempotent: both tables already ship in schema.sql,
-- so these CREATE ... IF NOT EXISTS are no-ops on existing installs and only
-- matter for fresh databases built from migrations alone.
--
-- NOTE: per-user default preference rows are seeded by PHP, not SQL, because the
-- defaults are role-dependent. Run AFTER applying this file:
--     php scripts/run_migration_009.php
--
-- SMS is deferred: channel_sms stays in the table, always 0, and is never wired.
-- Push subscriptions use the existing multi-device push_subscriptions table
-- (one row per device); there is intentionally no users.push_subscription column.

SET NAMES utf8;

CREATE TABLE IF NOT EXISTS `notification_preferences` (
    `user_id`           INT UNSIGNED NOT NULL,
    `notification_type` VARCHAR(60) NOT NULL,
    `enabled`           TINYINT(1) NOT NULL DEFAULT 1,
    `channel_push`      TINYINT(1) NOT NULL DEFAULT 1,
    `channel_email`     TINYINT(1) NOT NULL DEFAULT 0,
    `channel_sms`       TINYINT(1) NOT NULL DEFAULT 0,
    `quiet_hours_start` TIME NOT NULL DEFAULT '22:00:00',
    `quiet_hours_end`   TIME NOT NULL DEFAULT '07:00:00',
    `preferred_time`    TIME DEFAULT NULL COMMENT 'for scheduled notifications',
    `preferred_day`     TINYINT DEFAULT NULL COMMENT '0-6 for weekly notifications',
    `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `notification_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `endpoint`      TEXT NOT NULL,
    `p256dh`        TEXT NOT NULL COMMENT 'client public key',
    `auth`          TEXT NOT NULL COMMENT 'auth secret',
    `user_agent`    VARCHAR(255) DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at`  DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_push_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

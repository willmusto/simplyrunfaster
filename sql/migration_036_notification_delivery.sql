-- Migration 036: notification_preferences.delivery (immediate vs daily digest).
-- Coach warning/info flag notifications may be batched into a once-daily
-- flag_digest send instead of dispatching per-flag. critical_flag is always-on
-- and always immediate; the UI only exposes this control on warning_flag and
-- info_flag rows, and Notifications::applyPrefChange enforces the same.
-- Run via scripts/run_migration_036.php (idempotent) BEFORE deploying the code
-- that selects the column.

ALTER TABLE `notification_preferences`
  ADD COLUMN `delivery` ENUM('immediate','daily_digest') NOT NULL DEFAULT 'immediate'
      COMMENT 'warning/info flags only: batch into the daily flag digest'
      AFTER `channel_sms`;

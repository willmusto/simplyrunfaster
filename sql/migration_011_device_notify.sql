-- ============================================================
-- Migration 011: device "notify me when available" preferences
--
-- Stores an athlete's opt-in to be notified when integration support
-- for a wearable brand (Garmin, COROS, Polar, Suunto) ships. One row
-- per (user, brand); a row's presence means the athlete wants a heads-up.
--
-- MariaDB-safe: utf8 (not utf8mb4), InnoDB, no JSON.
--
--     php scripts/run_migration_011.php
-- ============================================================

SET NAMES utf8;

CREATE TABLE device_notify_preferences (
  user_id      INT NOT NULL,
  brand        VARCHAR(32) NOT NULL,
  notify       TINYINT(1) NOT NULL DEFAULT 0,
  updated_at   DATETIME NOT NULL,
  PRIMARY KEY (user_id, brand),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

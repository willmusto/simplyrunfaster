-- ============================================================
-- Migration 030 — Coaching Intelligence Layer (Phase 4 of 4)
-- Multi-coach support, decision sharing/proposals, coaching philosophy export.
--
-- Canonical DDL. Production runner is scripts/run_migration_030.php
-- (idempotent: checks column/enum/data state before ALTER/UPDATE).
--
-- (031 is reserved for the upcoming regeneration build.)
--
-- Schema constraints (MariaDB 5.3, MyISAM throughout):
--   - utf8 (not utf8mb4); no FOREIGN KEY constraints; LONGTEXT (not JSON)
--   - No IF NOT EXISTS on ALTER ADD COLUMN (runner guards via INFORMATION_SCHEMA)
-- ============================================================

SET NAMES utf8;

-- coaching_decisions: assistant-proposed status + sharing flag + export rationale.
ALTER TABLE `coaching_decisions`
    MODIFY COLUMN `status` ENUM('active','inactive','proposed','proposed_by_assistant') NOT NULL DEFAULT 'proposed';
ALTER TABLE `coaching_decisions`
    ADD COLUMN `shared` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Phase 4: head coach shares this active rule across the whole roster';
ALTER TABLE `coaching_decisions`
    ADD COLUMN `rationale` TEXT NULL
        COMMENT 'Phase 4: the "why", surfaced in the coaching philosophy export';

-- coaching_intelligence_flags: distinct "superseded" auto-resolution status (replaces the
-- Phase 3 [auto-resolved] text marker in suggested_action).
ALTER TABLE `coaching_intelligence_flags`
    MODIFY COLUMN `status` ENUM('open','actioned','dismissed','superseded') NOT NULL DEFAULT 'open';

-- Data-migrate the Phase 3 marker rows to the new status.
UPDATE `coaching_intelligence_flags`
   SET `status` = 'superseded'
 WHERE `status` = 'dismissed' AND `suggested_action` LIKE '[auto-resolved]%';

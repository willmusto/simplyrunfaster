-- Migration 037: generate-to-draft Stage 1 staging tables (g2d spec section 3).
--
-- plan_drafts / plan_draft_workouts are CLONES of training_plans /
-- planned_workouts (CREATE TABLE LIKE), so every generation-path INSERT/UPDATE
-- works against them with only the table name swapped, and column drift is
-- impossible at creation time. Extra draft metadata (coach_id, expires_at,
-- source_trigger) is added to plan_drafts.
--
-- AUTO_INCREMENT starts at 1,000,000 on both tables: draft ids can never
-- collide with live plan/workout ids, so even a hypothetical missed table swap
-- keyed by a draft id matches nothing in the production tables.
--
-- NO production query reads these tables. That is the core invariant: a draft
-- is structurally incapable of being mistaken for a live plan.
--
-- Run via scripts/run_migration_037.php (idempotent).

CREATE TABLE IF NOT EXISTS `plan_drafts` LIKE `training_plans`;
ALTER TABLE `plan_drafts`
  ADD COLUMN `coach_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'coach who generated the draft',
  ADD COLUMN `expires_at` DATETIME DEFAULT NULL COMMENT 'TTL; retention cron removes expired drafts',
  ADD COLUMN `source_trigger` VARCHAR(50) DEFAULT NULL,
  AUTO_INCREMENT = 1000000;

CREATE TABLE IF NOT EXISTS `plan_draft_workouts` LIKE `planned_workouts`;
ALTER TABLE `plan_draft_workouts` AUTO_INCREMENT = 1000000;

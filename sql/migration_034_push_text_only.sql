-- Migration 034: push_text_only flag on planned_workouts (edit -> Intervals.icu GAP A).
--
-- A surface/inline coach edit updates display fields / athlete_instructions / duration but
-- NOT the stored structure. The watch renders the structure (ignoring the edited text), so
-- the edit reaches the app but the watch shows stale structured steps. This flag, set by the
-- surface edit, makes generateWorkoutText() push the athlete_instructions text instead (the
-- structure is preserved on the row for a future structured editor).
--
-- Idempotent: guarded so re-running is a no-op (MySQL has no ADD COLUMN IF NOT EXISTS pre-8.0,
-- so this is applied via scripts/migrate_push_text_only.php which checks information_schema).

ALTER TABLE `planned_workouts`
  ADD COLUMN `push_text_only` tinyint(1) NOT NULL DEFAULT '0'
  COMMENT 'Surface/inline coach edit: push athlete_instructions text, not the (now-stale) structure (GAP A).'
  AFTER `pushed_to_watch`;

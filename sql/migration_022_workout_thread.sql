-- ============================================================
-- Migration 022: workout-linked threads keyed on planned_workout_id
--
-- Lets a session thread attach to a workout BEFORE it is completed. The
-- per-comment content stays in session_notes and the single re-floated card
-- stays in messages — both gain planned_workout_id so the thread is reachable
-- pre-completion (completed_workout_id only exists post-completion).
--
--   * messages.planned_workout_id      — on the session card.
--   * session_notes.planned_workout_id — on each comment.
--   * session_notes.completed_workout_id is made NULLABLE (pre-completion notes
--     have no completed workout yet).
--   * Backfill both planned_workout_id columns from completed_workouts.
--
-- MariaDB constraints (see migration_018/021 notes): utf8, no IF NOT EXISTS on
-- ADD COLUMN. The runner (scripts/run_migration_022.php) guards each ADD COLUMN
-- against INFORMATION_SCHEMA and only backfills on first creation, so it is safe
-- to re-run. This raw file is for fresh installs / manual application only.
--
--     php scripts/run_migration_022.php
-- ============================================================

SET NAMES utf8;

ALTER TABLE `messages`
    ADD COLUMN `planned_workout_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'session card link to a planned workout (available pre-completion)'
        AFTER `completed_workout_id`;

ALTER TABLE `session_notes`
    ADD COLUMN `planned_workout_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'thread link to a planned workout (available pre-completion)'
        AFTER `completed_workout_id`,
    MODIFY COLUMN `completed_workout_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'set once the workout is completed; NULL for pre-completion notes';

-- Backfill planned_workout_id from the completed workout each row already points at.
UPDATE `messages` m
    JOIN `completed_workouts` cw ON cw.id = m.completed_workout_id
   SET m.planned_workout_id = cw.planned_workout_id
 WHERE m.planned_workout_id IS NULL AND cw.planned_workout_id IS NOT NULL;

UPDATE `session_notes` sn
    JOIN `completed_workouts` cw ON cw.id = sn.completed_workout_id
   SET sn.planned_workout_id = cw.planned_workout_id
 WHERE sn.planned_workout_id IS NULL AND cw.planned_workout_id IS NOT NULL;

ALTER TABLE `messages`      ADD KEY `idx_msg_planned` (`planned_workout_id`);
ALTER TABLE `session_notes` ADD KEY `idx_sn_planned`  (`planned_workout_id`);

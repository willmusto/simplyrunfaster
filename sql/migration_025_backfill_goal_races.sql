-- ============================================================
-- Migration 025: backfill goal races + races.added_by_role += assistant_coach
--
-- 1) Athletes who set a goal race during onboarding have goal_race_date /
--    goal_race_distance on athlete_profiles but no row in `races`, so the goal
--    race never surfaced in the races UI. Backfill one is_goal_race=1 row per
--    such athlete (mapping the profile distance label to the races ENUM).
-- 2) races.added_by_role ENUM gains 'assistant_coach' (the new role can add races
--    via the coach race form; without this the value would be truncated).
--
-- MariaDB constraints: utf8. The backfill is guarded by NOT EXISTS so it is
-- idempotent; the ENUM MODIFY re-states the full set. Safe to re-run.
-- The runner (scripts/run_migration_025.php) is preferred.
--
--     php scripts/run_migration_025.php
-- ============================================================

SET NAMES utf8;

ALTER TABLE `races`
    MODIFY COLUMN `added_by_role` ENUM('athlete','coach','assistant_coach','admin') NOT NULL;

-- Backfill a goal race for every athlete with a goal_race_date and no existing
-- goal race row. Distance labels map to the races ENUM; anything without a clean
-- mapping (e.g. 'mile' / Hyrox) is stored as 'other'.
INSERT INTO `races`
    (`athlete_id`, `added_by`, `added_by_role`, `race_name`, `race_distance`, `race_date`, `is_goal_race`, `created_at`)
SELECT
    ap.athlete_id,
    a.user_id,
    'athlete',
    'Goal Race',
    CASE ap.goal_race_distance
        WHEN '5K'            THEN '5K'
        WHEN '10K'           THEN '10K'
        WHEN '15K'           THEN '15K'
        WHEN 'Half Marathon' THEN 'half'
        WHEN 'Marathon'      THEN 'marathon'
        WHEN '50k'           THEN '50k'
        WHEN '50_miler'      THEN '50_miler'
        WHEN '100k'          THEN '100k'
        WHEN '100_miler'     THEN '100_miler'
        ELSE 'other'
    END,
    ap.goal_race_date,
    1,
    NOW()
FROM `athlete_profiles` ap
JOIN `athletes` a ON a.id = ap.athlete_id
WHERE ap.goal_race_date IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `races` r WHERE r.athlete_id = ap.athlete_id AND r.is_goal_race = 1
  );

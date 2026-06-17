<?php
/**
 * Migration 025 runner — backfill goal races + races.added_by_role enum.
 *
 *  1) races.added_by_role ENUM += 'assistant_coach'.
 *  2) Backfill one is_goal_race=1 row per athlete who has a goal_race_date on
 *     their profile but no existing goal race (idempotent via NOT EXISTS).
 *
 * Safe to re-run.
 *
 *     php scripts/run_migration_025.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

echo "Ensuring races.added_by_role includes 'assistant_coach'…\n";
$db->exec(
    "ALTER TABLE `races`
     MODIFY COLUMN `added_by_role` ENUM('athlete','coach','assistant_coach','admin') NOT NULL"
);
echo "  ok.\n";

echo "Backfilling goal races from athlete_profiles…\n";
$n = $db->exec(
    "INSERT INTO `races`
        (`athlete_id`, `added_by`, `added_by_role`, `race_name`, `race_distance`, `race_date`, `is_goal_race`, `created_at`)
     SELECT
        ap.athlete_id, a.user_id, 'athlete', 'Goal Race',
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
        ap.goal_race_date, 1, NOW()
     FROM `athlete_profiles` ap
     JOIN `athletes` a ON a.id = ap.athlete_id
     WHERE ap.goal_race_date IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM `races` r WHERE r.athlete_id = ap.athlete_id AND r.is_goal_race = 1
       )"
);
echo "  backfilled {$n} goal race(s).\n";

echo date('Y-m-d H:i:s') . " — migration 025 complete.\n";

<?php
/**
 * Migration 018 runner — ultra-marathon training distances.
 *
 *  1) athlete_profiles.ultra_surface ENUM('road','trail') NULL — trail vs road,
 *     ultra goal distances only (50K / 50 miler / 100K / 100 miler).
 *  2) engine_flags.flag_type += 'ultra_surface_reminder' (info flag, trail ultras).
 *
 * Idempotent: the column is only added when missing; the ENUM MODIFY re-states
 * the full set and is safe to re-run.
 *
 *     php scripts/run_migration_018.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

// config.php loads config.local.php itself (web-root fallback), so don't also
// require it here or constants get defined twice.
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

/** True when $table already has column $col. */
$hasColumn = function (string $table, string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
};

if (!$hasColumn('athlete_profiles', 'ultra_surface')) {
    echo "Adding athlete_profiles.ultra_surface…\n";
    $db->exec(
        "ALTER TABLE `athlete_profiles`
         ADD COLUMN `ultra_surface` ENUM('road','trail') DEFAULT NULL
         COMMENT 'trail vs road, ultra goal distances only' AFTER `goal_finish_time`"
    );
    echo "  ok.\n";
} else {
    echo "athlete_profiles.ultra_surface already present — skipping.\n";
}

echo "Ensuring engine_flags.flag_type includes 'ultra_surface_reminder'…\n";
$db->exec(
    "ALTER TABLE `engine_flags`
     MODIFY COLUMN `flag_type` ENUM(
        'missed_workouts','hr_elevated','load_spike','compliance_low',
        'plan_rebuild_needed','compliance_trend','compliance_pattern',
        'excessive_fatigue','fitness_decline','taper_concern',
        'insufficient_base','return_to_running_discomfort',
        'limited_development_opportunity','long_run_day_conflict',
        'display_generation_incomplete',
        'profile_updated','pace_zones_missing',
        'schedule_day_ramp','ultra_surface_reminder'
     ) NOT NULL"
);
echo "  ok.\n";

echo date('Y-m-d H:i:s') . " — migration 018 complete.\n";

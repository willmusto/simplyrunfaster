<?php
/**
 * Migration 019 runner — race management.
 *
 * Extends the existing `races` table (race_distance ENUM += ultra keys;
 * distance_override_unit, result_notes, updated_at) and adds the engine_flags
 * types race_added / goal_race_changed / pace_recalibration.
 *
 * Idempotent: columns are added only when missing; ENUM MODIFYs re-state the
 * full set, so it is safe to re-run.
 *
 *     php scripts/run_migration_019.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

$hasColumn = function (string $table, string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
};

echo "Expanding races.race_distance ENUM (+ ultra keys)…\n";
$db->exec(
    "ALTER TABLE `races`
     MODIFY COLUMN `race_distance` ENUM(
        '5K','10K','15K','half','marathon','ultra','other',
        '50k','50_miler','100k','100_miler'
     ) NOT NULL"
);
echo "  ok.\n";

$adds = [
    'distance_override_unit' => "ADD COLUMN `distance_override_unit` ENUM('miles','km') DEFAULT NULL COMMENT 'unit the athlete entered for an \"other\" distance' AFTER `distance_override`",
    'result_notes'           => "ADD COLUMN `result_notes` TEXT DEFAULT NULL COMMENT 'athlete free-text notes logged with the result' AFTER `result_synced_from_watch`",
    'updated_at'             => "ADD COLUMN `updated_at` DATETIME DEFAULT NULL AFTER `created_at`",
];
foreach ($adds as $col => $clause) {
    if ($hasColumn('races', $col)) {
        echo "races.$col already present — skipping.\n";
        continue;
    }
    echo "Adding races.$col…\n";
    $db->exec("ALTER TABLE `races` $clause");
    echo "  ok.\n";
}

echo "Ensuring engine_flags.flag_type includes race_added / goal_race_changed / pace_recalibration…\n";
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
        'schedule_day_ramp','ultra_surface_reminder',
        'race_added','goal_race_changed','pace_recalibration'
     ) NOT NULL"
);
echo "  ok.\n";

echo date('Y-m-d H:i:s') . " — migration 019 complete.\n";

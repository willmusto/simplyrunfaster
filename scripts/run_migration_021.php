<?php
/**
 * Migration 021 runner — mile training + Hyrox UI facade.
 *
 *  1) athlete_profiles.is_hyrox TINYINT(1) NOT NULL DEFAULT 0.
 *  2) engine_flags.flag_type += 'hyrox_supplement_reminder'.
 *
 * Idempotent: the column is added only when missing; the ENUM MODIFY re-states
 * the full set, so it is safe to re-run.
 *
 *     php scripts/run_migration_021.php
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

if (!$hasColumn('athlete_profiles', 'is_hyrox')) {
    echo "Adding athlete_profiles.is_hyrox…\n";
    $db->exec(
        "ALTER TABLE `athlete_profiles`
         ADD COLUMN `is_hyrox` TINYINT(1) NOT NULL DEFAULT 0
         COMMENT 'Hyrox UI facade; engine runs mile logic underneath' AFTER `ultra_surface`"
    );
    echo "  ok.\n";
} else {
    echo "athlete_profiles.is_hyrox already present — skipping.\n";
}

echo "Ensuring engine_flags.flag_type includes 'hyrox_supplement_reminder'…\n";
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
        'race_added','goal_race_changed','pace_recalibration',
        'hyrox_supplement_reminder'
     ) NOT NULL"
);
echo "  ok.\n";

echo date('Y-m-d H:i:s') . " — migration 021 complete.\n";

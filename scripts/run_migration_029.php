<?php
/**
 * Migration 029 runner — Coaching Intelligence Layer (Phase 3 of 4).
 *
 *  1) athlete_response_profiles (one row per athlete; LONGTEXT metrics payload).
 *  2) coaching_intelligence_flags.confidence / .prediction_horizon_days /
 *     .predicted_for_date (predictive metadata; NULL for Phase 1/2 flags).
 *  3) coaching_intelligence_flags.flag_type ENUM += predicted_fatigue,
 *     predicted_dropout, injury_risk_pattern, adaptation_ahead.
 *
 * Idempotent: tables created only when missing; columns added only when missing;
 * the ENUM is widened only when the new values are absent. Safe to re-run.
 *
 *     php scripts/run_migration_029.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

$hasTable = function (string $table) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
};

$hasColumn = function (string $table, string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
};

$columnType = function (string $table, string $col) use ($db): string {
    $stmt = $db->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (string)$stmt->fetchColumn();
};

$addColumn = function (string $table, string $col, string $ddl) use ($db, $hasColumn): void {
    if (!$hasColumn($table, $col)) {
        echo "Adding {$table}.{$col}…\n";
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
        echo "  ok.\n";
    } else {
        echo "{$table}.{$col} already present — skipping.\n";
    }
};

if (!$hasTable('athlete_response_profiles')) {
    echo "Creating athlete_response_profiles…\n";
    $db->exec(
        "CREATE TABLE `athlete_response_profiles` (
            `athlete_id`    INT NOT NULL,
            `computed_at`   DATETIME NOT NULL,
            `weeks_of_data` INT NOT NULL DEFAULT 0,
            `metrics_json`  LONGTEXT NULL,
            PRIMARY KEY (`athlete_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
    );
    echo "  ok.\n";
} else {
    echo "athlete_response_profiles already present — skipping.\n";
}

$addColumn('coaching_intelligence_flags', 'confidence',
    "`confidence` ENUM('low','medium','high') NULL COMMENT 'Phase 3 predictive confidence tier; NULL for non-predictive flags'");
$addColumn('coaching_intelligence_flags', 'prediction_horizon_days',
    "`prediction_horizon_days` INT NULL COMMENT 'Phase 3: how many days ahead the prediction looks'");
$addColumn('coaching_intelligence_flags', 'predicted_for_date',
    "`predicted_for_date` DATE NULL COMMENT 'Phase 3: target date the prediction is about'");

// Widen flag_type ENUM only if the new values are missing.
$flagTypeDef = $columnType('coaching_intelligence_flags', 'flag_type');
if (strpos($flagTypeDef, 'predicted_fatigue') === false) {
    echo "Widening coaching_intelligence_flags.flag_type ENUM…\n";
    $db->exec(
        "ALTER TABLE `coaching_intelligence_flags`
         MODIFY COLUMN `flag_type` ENUM(
            'rpe_trending_high','rpe_trending_low','compliance_dropping','compliance_streak',
            'engagement_dropping','adaptation_ahead_of_schedule','dropout_risk','plan_adjustment_recommended',
            'predicted_fatigue','predicted_dropout','injury_risk_pattern','adaptation_ahead'
         ) NOT NULL"
    );
    echo "  ok.\n";
} else {
    echo "coaching_intelligence_flags.flag_type already has predictive values — skipping.\n";
}

echo date('Y-m-d H:i:s') . " — migration 029 complete.\n";

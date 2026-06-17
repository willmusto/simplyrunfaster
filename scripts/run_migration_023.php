<?php
/**
 * Migration 023 runner — athlete_profiles.hyrox_ever.
 *
 *  1) athlete_profiles.hyrox_ever TINYINT(1) NOT NULL DEFAULT 0.
 *  2) Backfill hyrox_ever = 1 for athletes currently on Hyrox (is_hyrox = 1).
 *
 * Idempotent: the column is added only when missing; the backfill is harmless to
 * re-run, so the whole script is safe to re-run.
 *
 *     php scripts/run_migration_023.php
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

if (!$hasColumn('athlete_profiles', 'hyrox_ever')) {
    echo "Adding athlete_profiles.hyrox_ever…\n";
    $db->exec(
        "ALTER TABLE `athlete_profiles`
         ADD COLUMN `hyrox_ever` TINYINT(1) NOT NULL DEFAULT 0
         COMMENT 'Latches to 1 once Hyrox is ever selected; keeps the Hyrox pill visible'
         AFTER `is_hyrox`"
    );
    echo "  ok.\n";
} else {
    echo "athlete_profiles.hyrox_ever already present — skipping.\n";
}

echo "Backfilling hyrox_ever = 1 where is_hyrox = 1…\n";
$n = $db->exec("UPDATE `athlete_profiles` SET `hyrox_ever` = 1 WHERE `is_hyrox` = 1");
echo "  updated {$n} row(s).\n";

echo date('Y-m-d H:i:s') . " — migration 023 complete.\n";

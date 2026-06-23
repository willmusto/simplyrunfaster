<?php
/**
 * Migration 032 runner — add 'unmatched_activity' to engine_flags.flag_type ENUM + backfill.
 *
 * BUG: engine_flags.flag_type is an ENUM that never included 'unmatched_activity', so the
 * Intervals importer's INSERT … flag_type='unmatched_activity' was MyISAM-coerced to '' on
 * every off-plan import. This adds the member and re-types the already-blanked rows.
 *
 * Idempotent: the ENUM is altered only when the value is missing; the backfill only touches
 * blank rows that carry the unmatched-activity message + an intervals_activity_id. Safe to re-run.
 *
 *     php scripts/run_migration_032.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

// ── 1. Add 'unmatched_activity' to the flag_type ENUM (preserving every existing member) ──
$col = $db->query(
    "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'engine_flags' AND COLUMN_NAME = 'flag_type'"
)->fetch(PDO::FETCH_ASSOC);

if (!$col) {
    fwrite(STDERR, "engine_flags.flag_type not found — aborting.\n");
    exit(1);
}

if (strpos($col['COLUMN_TYPE'], "'unmatched_activity'") !== false) {
    echo "flag_type ENUM already contains 'unmatched_activity' — skipping ALTER.\n";
} else {
    // Insert the new member just before the closing paren of enum(...).
    $newType = preg_replace('/\)\s*$/', ",'unmatched_activity')", $col['COLUMN_TYPE'], 1);
    $null    = strtoupper((string)$col['IS_NULLABLE']) === 'YES' ? 'NULL' : 'NOT NULL';
    $default = '';
    if ($col['COLUMN_DEFAULT'] !== null) {
        $default = " DEFAULT " . $db->quote($col['COLUMN_DEFAULT']);
    }
    echo "Altering engine_flags.flag_type to add 'unmatched_activity'…\n";
    $db->exec("ALTER TABLE `engine_flags` MODIFY COLUMN `flag_type` {$newType} {$null}{$default}");
    echo "  ok.\n";
}

// ── 2. Backfill rows that were coerced to '' but are unmatched-activity flags ──
$stmt = $db->prepare(
    "UPDATE engine_flags
     SET flag_type = 'unmatched_activity'
     WHERE (flag_type = '' OR flag_type IS NULL)
       AND message LIKE 'Imported a run on%didn''t match a planned workout.'
       AND details LIKE '%intervals_activity_id%'"
);
$stmt->execute();
echo "Backfilled {$stmt->rowCount()} blank flag_type row(s) to 'unmatched_activity'.\n";

echo date('Y-m-d H:i:s') . " — migration 032 complete.\n";

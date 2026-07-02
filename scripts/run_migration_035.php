<?php
/**
 * Migration 035 runner: drop athlete_profiles.experience_level (dead field).
 *
 * Idempotent: the column is dropped only when present. Safe to re-run.
 * Run AFTER deploying the code that no longer references the column.
 * (MyISAM DROP COLUMN rebuilds the table; athlete_profiles is tiny, so this
 * is not held the way migration 034's athletes-table drops were.)
 *
 *     php scripts/run_migration_035.php --dry-run   # report only
 *     php scripts/run_migration_035.php             # apply
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$db     = Database::get();

$hasColumn = function (string $table, string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
};

echo date('Y-m-d H:i:s') . ' migration 035 ' . ($dryRun ? '(DRY RUN)' : '(APPLY)') . "\n";

if (!$hasColumn('athlete_profiles', 'experience_level')) {
    echo "  [skip] athlete_profiles.experience_level already absent.\n";
} elseif ($dryRun) {
    $n = (int)$db->query('SELECT COUNT(*) FROM athlete_profiles WHERE experience_level IS NOT NULL')->fetchColumn();
    echo "  [dry] would DROP athlete_profiles.experience_level (discarding {$n} stored value(s); field is dead, nothing reads it).\n";
} else {
    echo "  Dropping athlete_profiles.experience_level...\n";
    $db->exec("ALTER TABLE `athlete_profiles` DROP COLUMN `experience_level`");
    echo "  [ok]\n";
}

echo date('Y-m-d H:i:s') . ' migration 035 ' . ($dryRun ? 'dry-run complete (nothing changed).' : 'complete.') . "\n";

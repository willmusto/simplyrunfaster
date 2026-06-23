<?php
/**
 * Regenerate sql/schema.sql structure from the LIVE database (structure only, no data).
 * Emits CREATE TABLE DDL to stdout, stats to stderr. Stable output (AUTO_INCREMENT stripped),
 * so a regen only changes schema.sql when the live structure actually changed.
 *
 *   php scripts/dump_schema.php > sql/schema.sql.structure   # then re-append the seed block
 *
 * Lives in scripts/ (excluded from the public web root). MyISAM throughout — see
 * _specs/db_debt_audit.md.
 */
define('SCRIPT_ROOT', dirname(__DIR__));
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
$db = Database::get();

$tables = $db->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
     ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);

echo "-- SimplyRunFaster Database Schema\n";
echo "-- Regenerated from the LIVE production schema on " . date('Y-m-d') . " (structure only).\n";
echo "-- Engine, tables, and columns reflect production EXACTLY. Do not hand-edit — regenerate\n";
echo "-- with scripts/_tmp_dump_schema.php (or mysqldump --no-data) when the live schema changes.\n";
echo "-- NOTE: production is MyISAM throughout (no FKs, no transactions); see _specs/db_debt_audit.md.\n\n";
echo "SET NAMES utf8;\n";
echo "SET time_zone = '+00:00';\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tables as $t) {
    $row = $db->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM);
    // SHOW CREATE TABLE returns CREATE TABLE (not IF NOT EXISTS); add IF NOT EXISTS for safe re-provision.
    $ddl = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $row[1], 1);
    // Strip the volatile AUTO_INCREMENT counter so the committed file is stable across regens.
    $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
    echo $ddl . ";\n\n";
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";

$cols = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
)->fetchColumn();
$eng = $db->query(
    "SELECT COALESCE(ENGINE,'NULL') e, COUNT(*) c FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' GROUP BY ENGINE"
)->fetchAll(PDO::FETCH_KEY_PAIR);
fwrite(STDERR, 'STATS tables=' . count($tables) . ' columns=' . $cols . ' engines=' . json_encode($eng) . "\n");
fwrite(STDERR, 'TABLELIST=' . implode(',', $tables) . "\n");

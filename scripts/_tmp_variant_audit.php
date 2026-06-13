<?php
/**
 * Audit workout_type for all variants of fallback-candidate archetypes.
 */
define('SCRIPT_ROOT', dirname(__DIR__));
foreach ([SCRIPT_ROOT . '/config/config.local.php', '/home/public/config/config.local.php'] as $cfg) {
    if (file_exists($cfg)) { require $cfg; break; }
}
defined('DB_HOST')    || define('DB_HOST',    'localhost');
defined('DB_NAME')    || define('DB_NAME',    'simplyrunfaster');
defined('DB_USER')    || define('DB_USER',    'root');
defined('DB_PASS')    || define('DB_PASS',    '');

$db = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Audit continuous archetypes — all variants and their workout_type
$archetypes = $db->query(
    "SELECT code, name, display FROM workout_archetypes ORDER BY code"
)->fetchAll();

foreach ($archetypes as $a) {
    $display = json_decode($a['display'], true) ?? [];
    $variants = $display['variants'] ?? [];
    if (empty($variants)) continue;

    echo "\n{$a['code']}:\n";
    foreach ($variants as $v) {
        $wt = $v['workout_type'] ?? '(none)';
        printf("  %-40s workout_type=%s\n", $v['code'] ?? '?', $wt);
    }
}

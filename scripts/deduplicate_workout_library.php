<?php
/**
 * Deduplicate workout_library rows.
 *
 * Keeps the row with the lowest id for each duplicate name.
 * Reroutes planned_workouts.workout_template_id to the kept row
 * before deleting the duplicates.
 *
 * Idempotent — safe to run multiple times.
 *
 *   php /home/private/app/scripts/deduplicate_workout_library.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));

foreach ([
    SCRIPT_ROOT . '/config/config.local.php',
    '/home/public/config/config.local.php',
] as $cfg) {
    if (file_exists($cfg)) { require $cfg; break; }
}
defined('DB_HOST')    || define('DB_HOST',    getenv('SRF_DB_HOST') ?: 'localhost');
defined('DB_NAME')    || define('DB_NAME',    getenv('SRF_DB_NAME') ?: 'simplyrunfaster');
defined('DB_USER')    || define('DB_USER',    getenv('SRF_DB_USER') ?: 'root');
defined('DB_PASS')    || define('DB_PASS',    getenv('SRF_DB_PASS') ?: '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8');

$db = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo "Workout library deduplication\n";
echo "==============================\n\n";

// Find names that appear more than once
$dupes = $db->query(
    'SELECT name, MIN(id) AS keep_id, COUNT(*) AS cnt
     FROM workout_library
     GROUP BY name
     HAVING COUNT(*) > 1
     ORDER BY name'
)->fetchAll();

if (empty($dupes)) {
    echo "No duplicates found. Nothing to do.\n";
    exit(0);
}

echo count($dupes) . " duplicate name(s) found:\n\n";

$totalRerouted = 0;
$totalDeleted  = 0;

foreach ($dupes as $row) {
    $name    = $row['name'];
    $keepId  = (int)$row['keep_id'];
    $count   = (int)$row['cnt'];

    // All IDs for this name except the keeper
    $allIds = $db->prepare('SELECT id FROM workout_library WHERE name = ? ORDER BY id ASC');
    $allIds->execute([$name]);
    $ids        = array_column($allIds->fetchAll(), 'id');
    $deleteIds  = array_filter($ids, fn($id) => (int)$id !== $keepId);

    if (empty($deleteIds)) continue;

    $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));

    // Reroute any planned_workouts pointing at duplicate rows
    $reroute = $db->prepare(
        "UPDATE planned_workouts SET workout_template_id = ?
         WHERE workout_template_id IN ($placeholders)"
    );
    $reroute->execute(array_merge([$keepId], array_values($deleteIds)));
    $rerouted = $reroute->rowCount();

    // Delete the duplicates
    $del = $db->prepare("DELETE FROM workout_library WHERE id IN ($placeholders)");
    $del->execute(array_values($deleteIds));
    $deleted = $del->rowCount();

    echo sprintf(
        "  %-45s keep id=%-4d  deleted %d dup(s)  rerouted %d workout(s)\n",
        '"' . $name . '"',
        $keepId,
        $deleted,
        $rerouted
    );

    $totalRerouted += $rerouted;
    $totalDeleted  += $deleted;
}

echo "\nDone. Deleted: {$totalDeleted} row(s). Rerouted: {$totalRerouted} planned workout(s).\n";
echo "Remaining workout_library rows: " . $db->query('SELECT COUNT(*) FROM workout_library')->fetchColumn() . "\n";

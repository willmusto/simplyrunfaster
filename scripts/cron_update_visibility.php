<?php
/**
 * Daily cron: open the athlete's 10-day rolling window.
 *
 * Sets planned_workouts.visible_to_athlete = 1 for any workout
 * within the next ATHLETE_WINDOW_DAYS days that belongs to an
 * athlete whose plan is approved and active.
 *
 * Cron schedule (NFSN): daily, any time (suggest 01:00 server time)
 *   php /home/private/app/scripts/cron_update_visibility.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
define('ATHLETE_WINDOW_DAYS', 10);

date_default_timezone_set('UTC');
require_once SCRIPT_ROOT . '/src/Timezone.php';

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
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// The rolling window is anchored on each athlete's LOCAL "today": a single UTC run
// (hour 5 UTC) covers athletes across timezones, so we group active-plan athletes by
// their timezone and open each group's window using that zone's local today/horizon.
$athleteZones = $db->query(
    "SELECT a.id AS athlete_id, COALESCE(u.timezone, 'America/New_York') AS tz
     FROM training_plans tp
     JOIN athletes a ON a.id = tp.athlete_id
     JOIN users u    ON u.id = a.user_id
     WHERE tp.status = 'active'
     GROUP BY a.id, tz"
)->fetchAll(PDO::FETCH_ASSOC);

$byTz = [];
foreach ($athleteZones as $row) {
    $tz = Timezone::isValid($row['tz']) ? $row['tz'] : Timezone::DEFAULT_TZ;
    $byTz[$tz][] = (int)$row['athlete_id'];
}

$opened = 0;
foreach ($byTz as $tz => $athleteIds) {
    $today   = Timezone::dateInZone($tz, 'now');
    $horizon = Timezone::dateInZone($tz, '+' . ATHLETE_WINDOW_DAYS . ' days');
    $placeholders = implode(',', array_fill(0, count($athleteIds), '?'));

    $stmt = $db->prepare(
        "UPDATE planned_workouts pw
         INNER JOIN training_plans tp ON tp.id = pw.plan_id
         SET pw.visible_to_athlete = 1
         WHERE pw.visible_to_athlete = 0
           AND (pw.cancelled = 0 OR pw.cancelled IS NULL)
           AND pw.athlete_id IN ($placeholders)
           AND pw.scheduled_date BETWEEN ? AND ?
           AND tp.status = 'active'"
    );
    $stmt->execute([...$athleteIds, $today, $horizon]);
    $opened += $stmt->rowCount();
}

echo date('Y-m-d H:i:s') . " — visibility window updated (per athlete timezone). Opened: {$opened} workouts.\n";

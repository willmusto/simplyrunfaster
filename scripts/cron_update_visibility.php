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

date_default_timezone_set('UTC');

// Full bootstrap so we can also push newly-visible workouts to Intervals.icu
// (config.php loads config.local.php + the INTERVALS_*/APP_ENCRYPTION_KEY constants;
// Crypto/IntervalsService handle the calendar push, RecoveryModel sharpens the text).
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Timezone.php';
require_once SCRIPT_ROOT . '/src/Engine/RecoveryModel.php';
require_once SCRIPT_ROOT . '/src/Crypto.php';
require_once SCRIPT_ROOT . '/src/IntervalsService.php';

defined('ATHLETE_WINDOW_DAYS') || define('ATHLETE_WINDOW_DAYS', 10);

$db = Database::get();

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
$pushed = 0;
foreach ($byTz as $tz => $athleteIds) {
    $today   = Timezone::dateInZone($tz, 'now');
    $horizon = Timezone::dateInZone($tz, '+' . ATHLETE_WINDOW_DAYS . ' days');
    $placeholders = implode(',', array_fill(0, count($athleteIds), '?'));

    // Capture the workouts about to be opened that belong to Intervals.icu-connected
    // athletes, so we can push each to their calendar after the visibility flip.
    $toPush = $db->prepare(
        "SELECT pw.id, pw.athlete_id
         FROM planned_workouts pw
         INNER JOIN training_plans tp ON tp.id = pw.plan_id
         INNER JOIN athletes a ON a.id = pw.athlete_id
         INNER JOIN intervals_connections ic ON ic.user_id = a.user_id
         WHERE pw.visible_to_athlete = 0
           AND (pw.cancelled = 0 OR pw.cancelled IS NULL)
           AND pw.workout_type <> 'rest'
           AND pw.athlete_id IN ($placeholders)
           AND pw.scheduled_date BETWEEN ? AND ?
           AND tp.status = 'active'"
    );
    $toPush->execute([...$athleteIds, $today, $horizon]);
    $pushList = $toPush->fetchAll(PDO::FETCH_ASSOC);

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

    foreach ($pushList as $w) {
        if (IntervalsService::pushWorkout((int)$w['athlete_id'], (int)$w['id'], $db)) $pushed++;
    }
}

echo date('Y-m-d H:i:s') . " — visibility window updated (per athlete timezone). "
    . "Opened: {$opened} workouts. Pushed to Intervals.icu: {$pushed}.\n";

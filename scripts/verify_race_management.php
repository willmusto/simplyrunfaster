<?php
/**
 * Race-management verification (architecture §26). Builds a throwaway athlete +
 * active plan + planned workouts inside a transaction that is ALWAYS rolled back,
 * adds a race, runs PlanGenerator::applyRaceAdjustments, and asserts the spec's
 * engine behaviours (pre-race aerobic taper, no quality within 3 days, race-day
 * skip, post-race recovery) plus RaceController conflict detection and the
 * PaceZones recalibration proposal.
 *
 *     php scripts/verify_race_management.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Engine/PaceZones.php';
require_once SCRIPT_ROOT . '/src/Engine/ArchetypeSelector.php';
require_once SCRIPT_ROOT . '/src/Engine/PlanGenerator.php';
require_once SCRIPT_ROOT . '/src/Controllers/RaceController.php';

$db = Database::get();

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  [PASS] " : "  [FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

$db->beginTransaction();
try {
    // Throwaway user + athlete + profile.
    $email = 'race_verify_' . bin2hex(random_bytes(6)) . '@example.invalid';
    $db->prepare('INSERT INTO users (email, password_hash, role, name) VALUES (?, "x", "athlete", "Race Test")')->execute([$email]);
    $userId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO athletes (user_id, status) VALUES (?, "active")')->execute([$userId]);
    $athleteId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO athlete_profiles (athlete_id, goal_race_distance, current_weekly_minutes, pace_zones_visible) VALUES (?, "10K", 200, 1)')
       ->execute([$athleteId]);

    $today = date('Y-m-d');
    $start = $today;
    $end   = date('Y-m-d', strtotime('+21 days'));
    $db->prepare('INSERT INTO training_plans (athlete_id, status, plan_start_date, plan_end_date, generated_at, plan_type) VALUES (?, "active", ?, ?, NOW(), "race_cycle")')
       ->execute([$athleteId, $start, $end]);
    $planId = (int)$db->lastInsertId();

    $raceDate = date('Y-m-d', strtotime('+10 days'));

    // Planned workouts: a full long run far from the race (sets normalLong=150), a long
    // run 4 days before (cap test), a quality session 2 days before (no-quality test),
    // a race-day workout (skip test), and easy filler.
    $ins = $db->prepare(
        'INSERT INTO planned_workouts (plan_id, athlete_id, scheduled_date, workout_type, archetype_code, target_duration, visible_to_athlete, display_title)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $ins->execute([$planId, $athleteId, date('Y-m-d', strtotime('+1 day')),  'long',     'progression_long', 150, 'Long Run']);          // normalLong
    $ins->execute([$planId, $athleteId, date('Y-m-d', strtotime('+6 days')), 'long',     'progression_long', 140, 'Long Run']);          // 4 days before race
    $ins->execute([$planId, $athleteId, date('Y-m-d', strtotime('+8 days')), 'interval', 'tempo_intervals',   55, 'Intervals']);         // 2 days before race
    $ins->execute([$planId, $athleteId, $raceDate,                            'easy',     'continuous_easy',   45, 'Easy']);              // race day
    $ins->execute([$planId, $athleteId, date('Y-m-d', strtotime('+12 days')),'interval', 'tempo_intervals',   55, 'Intervals']);         // after race (will be recovery)

    // Conflict detection (Part 3): quality within 7 days before the race date.
    $conflicts = RaceController::conflictsFor($athleteId, $raceDate, $db);
    check('coach conflict detection finds the nearby quality session', count($conflicts) >= 1);

    // Add the race + run the engine patch.
    $db->prepare('INSERT INTO races (athlete_id, added_by, added_by_role, race_name, race_distance, race_date, is_goal_race, created_at)
                  VALUES (?, ?, "athlete", "Verify 10K", "10K", ?, 0, NOW())')
       ->execute([$athleteId, $userId, $raceDate]);
    PlanGenerator::applyRaceAdjustments($athleteId, $db);

    $row = function (string $date) use ($db, $planId) {
        $s = $db->prepare('SELECT * FROM planned_workouts WHERE plan_id = ? AND scheduled_date = ? ORDER BY id LIMIT 1');
        $s->execute([$planId, $date]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    // 6: race day has no planned workout.
    check('race day has no planned workout', $row($raceDate) === null);

    // 4: long run within 7 days is pure aerobic.
    $long4 = $row(date('Y-m-d', strtotime('+6 days')));
    check('pre-race long run is pure aerobic', $long4 && in_array($long4['archetype_code'], ['continuous_long','continuous_easy'], true));
    // 3–4 days out capped to 60% of a normal long run (0.6 * 150 = 90).
    check('pre-race long run capped to ~60% (got ' . (int)($long4['target_duration'] ?? 0) . ')', $long4 && (int)$long4['target_duration'] <= 90);

    // 5: no quality within 3 days before the race.
    $q2 = $row(date('Y-m-d', strtotime('+8 days')));
    check('no quality session within 3 days of race', $q2 && !in_array($q2['workout_type'], ['interval','tempo','hill','fartlek','speed','race_pace'], true));

    // 9: post-race recovery days inserted.
    $recCount = 0;
    for ($d = 1; $d <= 5; $d++) {
        $r = $row(date('Y-m-d', strtotime("+" . (10 + $d) . " days")));
        if ($r && in_array($r['workout_type'], ['recovery','rest'], true)) $recCount++;
    }
    check("post-race recovery/rest days inserted (got {$recCount})", $recCount >= 3);

    // Recalibration proposal math (Part 6/7): zones project from a 10K result.
    $zones = PaceZones::fromRace('10K', 48 * 60);
    check('PaceZones proposes zones from a 10K result', is_array($zones) && !empty($zones['5K']));
} finally {
    $db->rollBack();
}

echo "\n================ {$pass} passed, {$fail} failed ================\n";
exit($fail === 0 ? 0 : 1);

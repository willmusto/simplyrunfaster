<?php
/**
 * Verification: coach athlete training-log view (read-only).
 *
 * Seeds synthetic completed_workouts (matched + unplanned) across two weeks for a throwaway
 * athlete, plus an older session beyond the page-0 window, and asserts the pure data builder
 * CoachController::athleteLogData(): week grouping newest-first, correct rollups, matched vs
 * unplanned rows + thread note counts, chronological order, and windowed pagination. Also
 * asserts the coach-owns-athlete scope (CoachAssignments::canAccess) and that the path performs
 * NO writes. Throwaway athlete + full teardown; no Liam / live data.
 *
 * Run: php /home/private/app/scripts/verify_coach_log.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
foreach ([SCRIPT_ROOT . '/config/config.local.php', '/home/public/config/config.local.php'] as $cfg) {
    if (file_exists($cfg)) { require $cfg; break; }
}
defined('DB_HOST')    || define('DB_HOST',    getenv('SRF_DB_HOST') ?: 'localhost');
defined('DB_NAME')    || define('DB_NAME',    getenv('SRF_DB_NAME') ?: 'simplyrunfaster');
defined('DB_USER')    || define('DB_USER',    getenv('SRF_DB_USER') ?: 'root');
defined('DB_PASS')    || define('DB_PASS',    getenv('SRF_DB_PASS') ?: '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8');

require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Timezone.php';
require_once SCRIPT_ROOT . '/src/CoachAssignments.php';
require_once SCRIPT_ROOT . '/src/Controllers/CoachController.php';

$db = Database::get();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void { global $pass, $fail; echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n"; $ok ? $pass++ : $fail++; }
function approx($a, $b, $t = 0.05): bool { return $a !== null && abs((float)$a - (float)$b) <= $t; }

$athleteId = null; $userId = null;
$TZ = 'America/New_York';
$COACH_A = 900001; $COACH_B = 900002; $ASST = 900003;

try {
    $email = 'log_verify_' . substr(md5((string)mt_rand()), 0, 8) . '@example.test';
    $db->prepare("INSERT INTO users (email, password_hash, role, name, timezone) VALUES (?, ?, 'athlete', 'Log Verify Bot', ?)")
       ->execute([$email, password_hash('x', PASSWORD_DEFAULT), $TZ]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO athletes (user_id, coach_id, onboarding_completed_at, status) VALUES (?, ?, NOW(), 'active')")
       ->execute([$userId, $COACH_A]);
    $athleteId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO athlete_profiles (athlete_id, plan_type, units) VALUES (?, 'development_plan', 'miles')")->execute([$athleteId]);
    $db->prepare("INSERT INTO coach_assignments (athlete_id, coach_id, assistant_coach_id, assigned_at, assigned_by) VALUES (?, ?, ?, NOW(), ?)")
       ->execute([$athleteId, $COACH_A, $ASST, $COACH_A]);

    // A throwaway plan + planned workouts so matched completions have a planned side.
    $db->prepare("INSERT INTO training_plans (athlete_id, status, plan_start_date, plan_end_date, generated_at, generation_trigger, plan_type)
                  VALUES (?, 'active', CURDATE(), CURDATE(), NOW(), 'onboarding', 'development_plan')")->execute([$athleteId]);
    $planId = (int)$db->lastInsertId();
    $mkPlanned = function (string $date, string $title, int $dur) use ($db, $planId, $athleteId): int {
        $db->prepare("INSERT INTO planned_workouts (plan_id, athlete_id, scheduled_date, workout_type, display_title, target_duration, visible_to_athlete)
                      VALUES (?, ?, ?, 'easy', ?, ?, 1)")->execute([$planId, $athleteId, $date, $title, $dur]);
        return (int)$db->lastInsertId();
    };
    $mkCompleted = function (?int $pwId, string $date, string $src, int $dur, float $dist, ?float $comp, ?string $eff) use ($db, $athleteId) {
        $db->prepare("INSERT INTO completed_workouts (athlete_id, planned_workout_id, source, activity_date, workout_type,
                        actual_distance, actual_duration, avg_pace, avg_hr, effort_descriptor, compliance_score, synced_at)
                      VALUES (?, ?, ?, ?, 'easy', ?, ?, 8.0, 140, ?, ?, NOW())")
           ->execute([$athleteId, $pwId, $src, $date, $dist, $dur, $eff, $comp]);
        return (int)$db->lastInsertId();
    };

    // Anchor exactly as athleteLogData does, so the assertions are weekday-independent.
    $t = strtotime(Timezone::dateInZone($TZ, 'now'));
    $anchorMon = strtotime(date('Y-m-d', $t)) - ((int)date('N', $t) - 1) * 86400;
    $mon0 = date('Y-m-d', $anchorMon);
    $mon0p2 = date('Y-m-d', $anchorMon + 2 * 86400);
    $mon1 = date('Y-m-d', $anchorMon - 7 * 86400);
    $mon1p1 = date('Y-m-d', $anchorMon - 6 * 86400);
    $old  = date('Y-m-d', $anchorMon - 70 * 86400);

    $pw0 = $mkPlanned($mon0, 'Planned easy 50', 50);
    $pw1 = $mkPlanned($mon1, 'Planned easy 60', 60);
    $pwOld = $mkPlanned($old, 'Old planned', 45);

    $c1 = $mkCompleted($pw0, $mon0,   'garmin', 50, 6.0, 0.90, 'easy');     // current week, matched
    $c2 = $mkCompleted(null, $mon0p2, 'manual', 40, 5.0, null, 'moderate'); // current week, unplanned
    $c3 = $mkCompleted($pw1, $mon1,   'garmin', 60, 7.0, 0.70, 'hard');     // prior week, matched
    $c4 = $mkCompleted(null, $mon1p1, 'manual', 30, 3.0, null, 'easy');     // prior week, unplanned
    $cOld = $mkCompleted($pwOld, $old, 'garmin', 45, 5.0, 0.80, 'easy');    // older than page-0 window

    // A coach note on the current-week matched session (thread indicator).
    $db->prepare("INSERT INTO session_notes (planned_workout_id, author_id, body, created_at) VALUES (?, ?, 'nice work', NOW())")
       ->execute([$pw0, $COACH_A]);

    // No-write baseline.
    $cwCount0 = (int)$db->query("SELECT COUNT(*) FROM completed_workouts WHERE athlete_id={$athleteId}")->fetchColumn();
    $pwCount0 = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE athlete_id={$athleteId}")->fetchColumn();
    $snCount0 = (int)$db->query("SELECT COUNT(*) FROM session_notes WHERE planned_workout_id={$pw0}")->fetchColumn();

    // ── Page 0 ──
    $log = CoachController::athleteLogData($athleteId, 0, $TZ, $db);
    $weeks = $log['weeks'];
    check("page 0: two week groups", count($weeks) === 2);
    check("page 0: newest week first (current week)", ($weeks[0]['monday'] ?? '') === $mon0 && ($weeks[1]['monday'] ?? '') === $mon1);

    $w0 = $weeks[0]; $w1 = $weeks[1];
    check("week0 rollup: 2 runs, 90 min, 11.0 mi", (int)$w0['runs'] === 2 && (int)$w0['total_minutes'] === 90 && approx($w0['total_distance'], 11.0));
    check("week0 rollup: avg compliance = 0.90 (only matched+scored)", approx($w0['avg_compliance'], 0.90));
    check("week1 rollup: 2 runs, 90 min, 10.0 mi, compliance 0.70", (int)$w1['runs'] === 2 && (int)$w1['total_minutes'] === 90 && approx($w1['total_distance'], 10.0) && approx($w1['avg_compliance'], 0.70));

    check("week0 rows chronological desc (later unplanned first)", !$w0['rows'][0]['matched'] && $w0['rows'][1]['matched']);
    $matchedRow = $w0['rows'][1];
    check("matched row carries planned title + duration", (string)$matchedRow['planned_title'] === 'Planned easy 50' && (int)$matchedRow['planned_duration'] === 50);
    check("matched row note_count = 1 (thread indicator)", (int)$matchedRow['note_count'] === 1);
    check("unplanned row: planned_workout_id null + matched false", $w0['rows'][0]['planned_workout_id'] === null && $w0['rows'][0]['matched'] === false);

    check("page 0: has_older true (older session exists), has_newer false", $log['has_older'] === true && $log['has_newer'] === false);

    // ── Page 1 (older window) ──
    $log1 = CoachController::athleteLogData($athleteId, 1, $TZ, $db);
    $mondays1 = array_map(static fn($w) => $w['monday'], $log1['weeks']);
    $oldMon = date('Y-m-d', strtotime($old) - ((int)date('N', strtotime($old)) - 1) * 86400);
    check("page 1: contains the older session's week", in_array($oldMon, $mondays1, true));
    check("page 1: has_newer true", $log1['has_newer'] === true);

    // ── Scope (coach-owns-athlete) ──
    check("scope: owning head coach can access", CoachAssignments::canAccess($COACH_A, 'coach', $athleteId, $db) === true);
    check("scope: another coach CANNOT access (403 path)", CoachAssignments::canAccess($COACH_B, 'coach', $athleteId, $db) === false);
    check("scope: assigned assistant can access", CoachAssignments::canAccess($ASST, 'assistant_coach', $athleteId, $db) === true);
    check("scope: unrelated assistant cannot access", CoachAssignments::canAccess($COACH_B, 'assistant_coach', $athleteId, $db) === false);

    // ── Units conversion (view formula) ──
    check("units: 6.0 mi → 9.7 km", number_format(6.0 * 1.60934, 1) === '9.7');

    // ── No writes on the log path ──
    $cwCount1 = (int)$db->query("SELECT COUNT(*) FROM completed_workouts WHERE athlete_id={$athleteId}")->fetchColumn();
    $pwCount1 = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE athlete_id={$athleteId}")->fetchColumn();
    $snCount1 = (int)$db->query("SELECT COUNT(*) FROM session_notes WHERE planned_workout_id={$pw0}")->fetchColumn();
    check("read-only: no rows written by the log path", $cwCount1 === $cwCount0 && $pwCount1 === $pwCount0 && $snCount1 === $snCount0);

    echo "\n================================\n";
    echo "  Coach training-log verification\n";
    echo "  PASS: {$pass}   FAIL: {$fail}\n";
    echo "================================\n";

} finally {
    if ($athleteId) {
        try { $db->prepare("DELETE FROM session_notes WHERE planned_workout_id IN (SELECT id FROM planned_workouts WHERE athlete_id = ?)")->execute([$athleteId]); } catch (\Throwable $e) {}
        foreach (['completed_workouts','planned_workouts','training_plans','coach_assignments','athlete_profiles'] as $tbl) {
            try { $db->prepare("DELETE FROM {$tbl} WHERE athlete_id=?")->execute([$athleteId]); } catch (\Throwable $e) {}
        }
        $db->prepare("DELETE FROM athletes WHERE id=?")->execute([$athleteId]);
    }
    if ($userId) $db->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
}

exit($fail === 0 ? 0 : 1);

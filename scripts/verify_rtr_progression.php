<?php
/**
 * Verification: return-to-running adaptive stage progression (engine spec §18.10 / §19 item 6).
 *
 * Spins up a throwaway return_to_running athlete, approves the plan, then simulates a
 * sequence of completed run_walk_intervals sessions with varying modified-RPE responses
 * (including discomfort at the floor, a discomfort-driven regression mid-climb, a full
 * climb to stage 10, and a clean stage-10 completion). After each completion it asserts:
 *
 *   - rtr_current_stage advanced / regressed / held exactly as specified
 *   - the next scheduled run/walk session's display reflects the NEW stage
 *   - return_to_running_discomfort fires on a discomfort response
 *   - plan_rebuild_needed (transition) fires on a clean stage-10 completion, with no
 *     further run scheduled
 *   - the rolling window stays populated (a visible next session exists) until the
 *     progression completes
 *   - validateGeneratedDisplays() leaves no display_generation_incomplete flag
 *
 * Everything it creates is torn down in the finally block. Read-only against the rest
 * of the database.
 *
 * Run: php /home/private/app/scripts/verify_rtr_progression.php
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
require_once SCRIPT_ROOT . '/src/Engine/TrainingLoad.php';
require_once SCRIPT_ROOT . '/src/Engine/EffortMapper.php';
require_once SCRIPT_ROOT . '/src/Engine/RecoveryModel.php';
require_once SCRIPT_ROOT . '/src/Engine/PaceZones.php';
require_once SCRIPT_ROOT . '/src/Engine/ArchetypeSelector.php';
require_once SCRIPT_ROOT . '/src/Engine/PlanGenerator.php';

$db = Database::get();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

$athleteId = null;
$userId    = null;

try {
    // ── Setup: throwaway return_to_running athlete ────────────────────────────
    $email = 'rtr_verify_' . substr(md5((string)mt_rand()), 0, 8) . '@example.test';
    $db->prepare(
        "INSERT INTO users (email, password_hash, role, name, timezone)
         VALUES (?, ?, 'athlete', 'RTR Verify Bot', 'America/New_York')"
    )->execute([$email, password_hash('x', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();

    $db->prepare(
        "INSERT INTO athletes (user_id, onboarding_completed_at, status)
         VALUES (?, NOW(), 'active')"
    )->execute([$userId]);
    $athleteId = (int)$db->lastInsertId();

    // Profile: return_to_running, 4 training days, no must-off, has a bike (cross-train
    // off days), no pace zones (run/walk is effort-only anyway).
    $db->prepare(
        "INSERT INTO athlete_profiles
            (athlete_id, plan_type, training_days_per_week, must_off_days,
             return_time_off_band, cross_training_bike, experience_level,
             current_weekly_minutes, pace_zones_visible)
         VALUES (?, 'return_to_running', 4, '[]', '6_16_weeks', 'stationary',
                 'beginner', 60, 1)"
    )->execute([$athleteId]);

    // Generate the initial stage-1 window, then approve (activate) it.
    $planId = PlanGenerator::generate($athleteId, 'onboarding');
    if (!$planId) {
        throw new RuntimeException('PlanGenerator::generate returned null for the RTR athlete.');
    }
    $db->prepare("UPDATE training_plans SET status = 'active' WHERE id = ?")->execute([$planId]);
    $db->prepare(
        "UPDATE planned_workouts SET visible_to_athlete = 1
         WHERE plan_id = ? AND scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 10 DAY)"
    )->execute([$planId]);

    $stage0 = (int)$db->query("SELECT rtr_current_stage FROM training_plans WHERE id = {$planId}")->fetchColumn();
    check("initial rtr_current_stage == 1", $stage0 === 1);

    $initialRun = nextRunWalk($db, $planId);
    check("initial window has a run/walk session", $initialRun !== null);

    // ── Helpers bound to this plan ────────────────────────────────────────────
    $discomfortFlags = fn() => countFlags($db, $athleteId, 'return_to_running_discomfort');
    $rebuildFlags    = fn() => countFlags($db, $athleteId, 'plan_rebuild_needed');
    $displayFlags    = fn() => countFlags($db, $athleteId, 'display_generation_incomplete');

    // Each step: [modified-RPE response, expected stage AFTER the step, human label].
    // Climb 1→1 (floor discomfort), 1→2→3→4, discomfort 4→3, climb 3→…→10, clean 10 (hold).
    $steps = [
        ['discomfort', 1,  'stage 1 discomfort holds at floor 1'],
        ['moderate',   2,  'clean advance 1→2'],
        ['easy',       3,  'clean advance 2→3'],
        ['hard',       4,  'clean advance 3→4'],
        ['discomfort', 3,  'discomfort regresses 4→3'],
        ['moderate',   4,  'clean advance 3→4'],
        ['very_hard',  5,  'clean advance 4→5'],
        ['moderate',   6,  'clean advance 5→6'],
        ['easy',       7,  'clean advance 6→7'],
        ['moderate',   8,  'clean advance 7→8'],
        ['hard',       9,  'clean advance 8→9'],
        ['moderate',  10,  'clean advance 9→10'],
        ['moderate',  10,  'clean stage-10 completion holds at 10 + transition flag'],
    ];

    $prevDiscomfort = $discomfortFlags();
    $prevRebuild    = $rebuildFlags();

    foreach ($steps as $i => [$rpe, $expectedStage, $label]) {
        $stepNo  = $i + 1;
        $session = nextRunWalk($db, $planId);
        if ($session === null) {
            check("step {$stepNo} ({$label}) — a pending run/walk session exists", false);
            break;
        }

        $stageBefore = (int)$db->query("SELECT rtr_current_stage FROM training_plans WHERE id = {$planId}")->fetchColumn();

        // Simulate the athlete logging completion of this session with the given RPE.
        $db->prepare(
            "INSERT INTO completed_workouts
                (athlete_id, planned_workout_id, source, activity_date, workout_type,
                 actual_duration, completion_status, rpe, rpe_discomfort, effort_descriptor,
                 compliance_score, synced_at)
             VALUES (?, ?, 'manual', ?, 'easy', ?, 'full', ?, ?, ?, 1.0, NOW())"
        )->execute([
            $athleteId, (int)$session['id'], $session['scheduled_date'],
            (int)($session['target_duration'] ?? 30),
            ['easy'=>2,'moderate'=>4,'hard'=>7,'very_hard'=>9,'discomfort'=>5][$rpe],
            $rpe === 'discomfort' ? 1 : 0, $rpe,
        ]);

        // Run the progression engine (exactly what AthleteController::manualLog calls).
        PlanGenerator::onRunWalkCompletion($athleteId, (int)$session['id'], $rpe, $db);

        $stageAfter = (int)$db->query("SELECT rtr_current_stage FROM training_plans WHERE id = {$planId}")->fetchColumn();
        check("step {$stepNo}: {$label} (stage {$stageBefore}→{$stageAfter})", $stageAfter === $expectedStage);

        $isCleanStage10 = ($rpe !== 'discomfort' && $stageBefore >= 10);

        if ($isCleanStage10) {
            // Transition flag raised, no further run scheduled.
            check("step {$stepNo}: plan_rebuild_needed (transition) flag raised",
                  $rebuildFlags() > $prevRebuild);
            check("step {$stepNo}: no further run/walk session scheduled after stage-10 finish",
                  nextRunWalk($db, $planId) === null);
            $prevRebuild = $rebuildFlags();
        } else {
            // Window stays populated with a visible next session reflecting the new stage.
            $next = nextRunWalk($db, $planId);
            check("step {$stepNo}: rolling window has a next run/walk session", $next !== null);
            if ($next) {
                $reflects = str_contains((string)$next['display_title'], "Stage {$stageAfter}");
                check("step {$stepNo}: next session display reflects stage {$stageAfter} "
                      . "(\"{$next['display_title']}\")", $reflects);
                check("step {$stepNo}: next session is visible to athlete",
                      (int)$next['visible_to_athlete'] === 1);
            }
        }

        if ($rpe === 'discomfort') {
            check("step {$stepNo}: return_to_running_discomfort flag raised",
                  $discomfortFlags() > $prevDiscomfort);
            $prevDiscomfort = $discomfortFlags();
        }

        check("step {$stepNo}: no display_generation_incomplete flag", $displayFlags() === 0);
    }

    // Final sanity: plan_end_date extended well past the original 10-day static window.
    $row = $db->query("SELECT plan_start_date, plan_end_date FROM training_plans WHERE id = {$planId}")->fetch();
    $span = (strtotime($row['plan_end_date']) - strtotime($row['plan_start_date'])) / 86400;
    check("plan window extended across the climb (span {$span} days > 10)", $span > 10);

    echo "\n";
    echo "================================\n";
    echo "  RTR progression verification\n";
    echo "  PASS: {$pass}   FAIL: {$fail}\n";
    echo "================================\n";

} finally {
    // ── Teardown ──────────────────────────────────────────────────────────────
    if ($athleteId) {
        $db->prepare("DELETE FROM completed_workouts WHERE athlete_id = ?")->execute([$athleteId]);
        $db->prepare("DELETE FROM planned_workouts   WHERE athlete_id = ?")->execute([$athleteId]);
        $db->prepare("DELETE FROM plan_approval_queue WHERE athlete_id = ?")->execute([$athleteId]);
        $db->prepare("DELETE FROM training_plans      WHERE athlete_id = ?")->execute([$athleteId]);
        $db->prepare("DELETE FROM engine_flags        WHERE athlete_id = ?")->execute([$athleteId]);
        $db->prepare("DELETE FROM training_load       WHERE athlete_id = ?")->execute([$athleteId]);
        $db->prepare("DELETE FROM athlete_profiles    WHERE athlete_id = ?")->execute([$athleteId]);
        $db->prepare("DELETE FROM athletes            WHERE id = ?")->execute([$athleteId]);
    }
    if ($userId) {
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    }
}

exit($fail === 0 ? 0 : 1);

/**
 * The earliest uncompleted run/walk session in the plan (no matching completed_workout).
 */
function nextRunWalk(PDO $db, int $planId): ?array
{
    $stmt = $db->prepare(
        "SELECT pw.id, pw.scheduled_date, pw.display_title, pw.target_duration, pw.visible_to_athlete
         FROM planned_workouts pw
         LEFT JOIN completed_workouts cw ON cw.planned_workout_id = pw.id
         WHERE pw.plan_id = ? AND pw.archetype_code = 'run_walk_intervals' AND cw.id IS NULL
         ORDER BY pw.scheduled_date ASC, pw.id ASC
         LIMIT 1"
    );
    $stmt->execute([$planId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function countFlags(PDO $db, int $athleteId, string $type): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM engine_flags WHERE athlete_id = ? AND flag_type = ?");
    $stmt->execute([$athleteId, $type]);
    return (int)$stmt->fetchColumn();
}

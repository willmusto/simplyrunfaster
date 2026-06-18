<?php
/**
 * Verification: Coaching Intelligence Layer Phase 3 — STAGE A (response profiling).
 *
 * Spins up a throwaway athlete, seeds SYNTHETIC behavior_log / completed_workouts /
 * planned_workouts / training_load with known patterns, and asserts:
 *   - below MIN_WEEKS_DATA the profile reports "not enough data yet" (no metric values)
 *   - with a rich history the four response-profile metrics compute to their known
 *     values, with sample_size and confidence that scale with data volume
 *   - NO plan generation occurs as a side effect of any Phase 3 code path
 *
 * Uses a brand-new throwaway athlete (no live data touched). Everything it creates is
 * torn down in the finally block. Stage B extends this file (verify_cil_phase3 still
 * runs Stage A; the predictive-flag checks are appended there).
 *
 * Run: php /home/private/app/scripts/verify_cil_phase3.php
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
require_once SCRIPT_ROOT . '/src/PredictiveConstants.php';
require_once SCRIPT_ROOT . '/src/ResponseProfiler.php';

$db = Database::get();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}
function approx(?float $v, float $target, float $tol = 0.001): bool {
    return $v !== null && abs($v - $target) <= $tol;
}

$athleteId = null;
$userId    = null;

try {
    // ── Setup: throwaway athlete ──────────────────────────────────────────────
    $email = 'cil3_verify_' . substr(md5((string)mt_rand()), 0, 8) . '@example.test';
    $db->prepare(
        "INSERT INTO users (email, password_hash, role, name, timezone)
         VALUES (?, ?, 'athlete', 'CIL3 Verify Bot', 'America/New_York')"
    )->execute([$email, password_hash('x', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO athletes (user_id, onboarding_completed_at, status) VALUES (?, NOW(), 'active')")->execute([$userId]);
    $athleteId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO athlete_profiles (athlete_id, plan_type, current_weekly_minutes) VALUES (?, 'development_plan', 100)")->execute([$athleteId]);

    // 16 consecutive Mondays, oldest first, ending ~1 week ago.
    $mondays = [];
    $base = strtotime('monday this week') - 16 * 7 * 86400;
    for ($i = 0; $i < 16; $i++) { $mondays[$i] = date('Y-m-d', $base + $i * 7 * 86400); }

    $seedWeek = function (int $i, string $monday, bool $full) use ($db, $athleteId) {
        // Weekly completion_rate: 0.9 baseline; 1.0 on the cutback week (i=7) for a +0.1 bounce.
        $comp = ($i === 7) ? 1.0 : 0.9;
        $db->prepare(
            "INSERT INTO athlete_behavior_log (athlete_id, logged_at, metric_type, metric_value, plan_week, phase)
             VALUES (?, ?, 'completion_rate', ?, ?, 'base')"
        )->execute([$athleteId, $monday . ' 09:00:00', $comp, $i + 1]);
        if (!$full) return;
        // Easy session run HARD (+4 vs prescribed); quality (tempo) run EASY (-4 vs prescribed).
        $db->prepare(
            "INSERT INTO completed_workouts (athlete_id, planned_workout_id, source, activity_date, workout_type, actual_duration, completion_status, effort_descriptor, compliance_score, synced_at)
             VALUES (?, NULL, 'manual', ?, 'easy', 50, 'full', 'hard', ?, NOW())"
        )->execute([$athleteId, $monday, $comp]);
        $db->prepare(
            "INSERT INTO completed_workouts (athlete_id, planned_workout_id, source, activity_date, workout_type, actual_duration, completion_status, effort_descriptor, compliance_score, synced_at)
             VALUES (?, NULL, 'manual', ?, 'tempo', 50, 'full', 'easy', ?, NOW())"
        )->execute([$athleteId, date('Y-m-d', strtotime($monday) + 2 * 86400), $comp]);
        // Planned weekly volume: 100 normally, 40 on the cutback week (i=7).
        $db->prepare(
            "INSERT INTO planned_workouts (plan_id, athlete_id, scheduled_date, workout_type, target_duration, visible_to_athlete)
             VALUES (0, ?, ?, 'easy', ?, 1)"
        )->execute([$athleteId, $monday, ($i === 7) ? 40 : 100]);
    };

    // ── Phase 1: sparse (2 weeks) → "not enough data" ─────────────────────────
    $seedWeek(14, $mondays[14], false);
    $seedWeek(15, $mondays[15], false);
    $sparse = ResponseProfiler::recompute($athleteId, $db);
    check("sparse: weeks_of_data == 2", $sparse['weeks_of_data'] === 2);
    check("sparse: enough_data is false (< " . PredictiveConstants::MIN_WEEKS_DATA . " weeks)", $sparse['enough_data'] === false);
    check("sparse: easy_rpe_delta confidence == none", ($sparse['metrics']['easy_rpe_delta']['confidence'] ?? '') === 'none');
    check("sparse: volume_tolerance value is null", ($sparse['metrics']['volume_tolerance_mins']['value'] ?? 1) === null);

    // ── Phase 2: rich history (weeks 0–13 full) ───────────────────────────────
    for ($i = 0; $i < 14; $i++) { $seedWeek($i, $mondays[$i], true); }

    // Training-load recovery pattern: two 5-day dips (TSB < -15 → back to ≥ -5).
    $tlBase = strtotime($mondays[0]);
    $tsbByDay = [0=>-20,1=>-16,2=>-16,3=>-16,4=>-16,5=>-3,6=>-3,7=>-3,8=>-3,9=>-3,
                 10=>-20,11=>-16,12=>-16,13=>-16,14=>-16,15=>-3];
    foreach ($tsbByDay as $d => $tsb) {
        $db->prepare(
            "INSERT INTO training_load (athlete_id, `date`, atl, ctl, tsb, daily_stress, computed_at)
             VALUES (?, ?, 0, 0, ?, 0, NOW())"
        )->execute([$athleteId, date('Y-m-d', $tlBase + $d * 86400), $tsb]);
    }

    // Guard: no plan generation as a Phase 3 side effect.
    $plansBefore = (int)$db->query("SELECT COUNT(*) FROM training_plans WHERE athlete_id = {$athleteId}")->fetchColumn();
    $pwBefore    = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE athlete_id = {$athleteId}")->fetchColumn();

    $rich = ResponseProfiler::recompute($athleteId, $db);

    $plansAfter = (int)$db->query("SELECT COUNT(*) FROM training_plans WHERE athlete_id = {$athleteId}")->fetchColumn();
    $pwAfter    = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE athlete_id = {$athleteId}")->fetchColumn();
    check("no plan generated by Phase 3 (training_plans 0)", $plansBefore === 0 && $plansAfter === 0);
    check("Phase 3 did not add/modify planned_workouts", $pwBefore === $pwAfter);

    $m = $rich['metrics'];
    check("rich: weeks_of_data == 16", $rich['weeks_of_data'] === 16);
    check("rich: enough_data true", $rich['enough_data'] === true);

    check("easy_rpe_delta value == +4.0 (ran easy too hard)", approx((float)$m['easy_rpe_delta']['value'], 4.0));
    check("easy_rpe_delta sample_size == 14", (int)$m['easy_rpe_delta']['sample_size'] === 14);
    check("easy_rpe_delta confidence == high (large sample, 16 wk)", $m['easy_rpe_delta']['confidence'] === 'high');

    check("quality_rpe_delta value == -4.0 (ran quality too easy)", approx((float)$m['quality_rpe_delta']['value'], -4.0));
    check("quality_rpe_delta confidence == high", $m['quality_rpe_delta']['confidence'] === 'high');

    check("volume_tolerance value == 100 mins (sustained compliant block)", (int)$m['volume_tolerance_mins']['value'] === 100);
    check("volume_tolerance confidence == high", $m['volume_tolerance_mins']['confidence'] === 'high');

    check("recovery_days value == 5.0", approx((float)$m['recovery_days']['value'], 5.0, 0.01));
    check("recovery_days sample_size == 2 (two dips)", (int)$m['recovery_days']['sample_size'] === 2);
    check("recovery_days confidence == medium (thin sample drops a tier)", $m['recovery_days']['confidence'] === 'medium');

    check("cutback_response sample_size == 1", (int)$m['cutback_response']['sample_size'] === 1);
    check("cutback_response value > 0 (compliance bounced up)", $m['cutback_response']['value'] !== null && (float)$m['cutback_response']['value'] > 0);
    check("cutback_response confidence == medium (thin sample drops a tier)", $m['cutback_response']['confidence'] === 'medium');

    // Confidence-tier table sanity.
    check("tierForWeeks: 3→none, 5→low, 10→medium, 20→high",
        PredictiveConstants::tierForWeeks(3) === 'none'
        && PredictiveConstants::tierForWeeks(5) === 'low'
        && PredictiveConstants::tierForWeeks(10) === 'medium'
        && PredictiveConstants::tierForWeeks(20) === 'high');

    echo "\n================================\n";
    echo "  CIL Phase 3 — Stage A verification\n";
    echo "  PASS: {$pass}   FAIL: {$fail}\n";
    echo "================================\n";

} finally {
    if ($athleteId) {
        foreach (['athlete_behavior_log','completed_workouts','planned_workouts','training_load',
                  'athlete_response_profiles','coaching_intelligence_flags','training_plans',
                  'engine_flags','athlete_profiles'] as $t) {
            try { $db->prepare("DELETE FROM {$t} WHERE athlete_id = ?")->execute([$athleteId]); } catch (\Throwable $e) {}
        }
        $db->prepare("DELETE FROM athletes WHERE id = ?")->execute([$athleteId]);
    }
    if ($userId) { $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]); }
}

exit($fail === 0 ? 0 : 1);

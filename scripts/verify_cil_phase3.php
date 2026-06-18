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
require_once SCRIPT_ROOT . '/src/PredictiveFlags.php';

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

$athleteId   = null;
$userId      = null;
$coachUserId = null;

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

    // Throwaway coach (predictive flags must attribute to a coach).
    $db->prepare("INSERT INTO users (email, password_hash, role, name, timezone) VALUES (?, ?, 'coach', 'CIL3 Coach Bot', 'America/New_York')")
       ->execute(['cil3_coach_' . substr(md5((string)mt_rand()), 0, 8) . '@example.test', password_hash('x', PASSWORD_DEFAULT)]);
    $coachUserId = (int)$db->lastInsertId();
    $db->prepare("UPDATE athletes SET coach_id = ? WHERE id = ?")->execute([$coachUserId, $athleteId]);

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
    check("sparse: volume_tolerance value is null",
        array_key_exists('value', $sparse['metrics']['volume_tolerance_mins'])
        && $sparse['metrics']['volume_tolerance_mins']['value'] === null);

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

    // ════════ STAGE B — predictive flags ════════
    $monByWeeks = static function (int $n): int { return strtotime('monday this week') - $n * 7 * 86400; };
    $seedWeeksCompletion = function (int $nWeeks, float $val) use ($db, $athleteId, $monByWeeks) {
        $base = $monByWeeks($nWeeks);
        for ($i = 0; $i < $nWeeks; $i++) {
            $m = date('Y-m-d', $base + $i * 7 * 86400);
            $db->prepare("INSERT INTO athlete_behavior_log (athlete_id, logged_at, metric_type, metric_value, phase) VALUES (?, ?, 'completion_rate', ?, 'base')")
               ->execute([$athleteId, $m . ' 09:00:00', $val]);
        }
    };
    $seedRpe = function (float $val, int $count = 3) use ($db, $athleteId) {
        for ($i = 1; $i <= $count; $i++) {
            $db->prepare("INSERT INTO athlete_behavior_log (athlete_id, logged_at, metric_type, metric_value, phase) VALUES (?, ?, 'rpe_vs_target', ?, 'base')")
               ->execute([$athleteId, date('Y-m-d H:i:s', time() - $i * 86400), $val]);
        }
    };
    $seedEngagement = function (int $daysAgo, float $val) use ($db, $athleteId) {
        $db->prepare("INSERT INTO athlete_behavior_log (athlete_id, logged_at, metric_type, metric_value, phase) VALUES (?, ?, 'engagement_score', ?, 'base')")
           ->execute([$athleteId, date('Y-m-d H:i:s', time() - $daysAgo * 86400), $val]);
    };
    $seedCompleted = function (int $daysAgo, int $mins) use ($db, $athleteId) {
        $db->prepare("INSERT INTO completed_workouts (athlete_id, source, activity_date, workout_type, actual_duration, synced_at) VALUES (?, 'manual', ?, 'easy', ?, NOW())")
           ->execute([$athleteId, date('Y-m-d', time() - $daysAgo * 86400), $mins]);
    };
    $seedTsb = function (int $daysAgo, float $tsb, float $ctl) use ($db, $athleteId) {
        $db->prepare("INSERT INTO training_load (athlete_id, `date`, atl, ctl, tsb, daily_stress, computed_at) VALUES (?, ?, 0, ?, ?, 0, NOW())")
           ->execute([$athleteId, date('Y-m-d', time() - $daysAgo * 86400), $ctl, $tsb]);
    };
    $clearInputs = function () use ($db, $athleteId) {
        foreach (['athlete_behavior_log','completed_workouts','training_load'] as $t) {
            $db->prepare("DELETE FROM {$t} WHERE athlete_id = ?")->execute([$athleteId]);
        }
    };
    $resetAll = function () use ($db, $athleteId) {
        foreach (['athlete_behavior_log','completed_workouts','training_load','coaching_intelligence_flags'] as $t) {
            $db->prepare("DELETE FROM {$t} WHERE athlete_id = ?")->execute([$athleteId]);
        }
    };
    $openFlag = function (string $type) use ($db, $athleteId) {
        $s = $db->prepare("SELECT confidence, prediction_horizon_days, predicted_for_date FROM coaching_intelligence_flags WHERE athlete_id = ? AND flag_type = ? AND status = 'open' LIMIT 1");
        $s->execute([$athleteId, $type]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $dismissedExists = function (string $type) use ($db, $athleteId) {
        $s = $db->prepare("SELECT 1 FROM coaching_intelligence_flags WHERE athlete_id = ? AND flag_type = ? AND status = 'dismissed' LIMIT 1");
        $s->execute([$athleteId, $type]);
        return (bool)$s->fetchColumn();
    };
    $evaluate = function () use ($athleteId, $coachUserId, $db) {
        $p = ResponseProfiler::recompute($athleteId, $db);
        return PredictiveFlags::evaluateAthlete($athleteId, $coachUserId, $db, $p);
    };

    // ── Scenario 1: fatigue + injury fire simultaneously ──────────────────────
    $resetAll();
    $seedWeeksCompletion(16, 0.9);
    $seedRpe(2.0, 3);                                  // RPE +2 vs prescribed (high)
    $seedCompleted(1, 500);                            // last-7 spike
    $seedCompleted(8, 150); $seedCompleted(15, 150); $seedCompleted(17, 150); $seedCompleted(22, 150);
    $seedTsb(7, -5, 50); $seedTsb(0, -20, 50);         // TSB now -20, falling, < -15
    $evaluate();
    $fat = $openFlag('predicted_fatigue');
    $inj = $openFlag('injury_risk_pattern');
    check("predicted_fatigue fires (ramp + RPE high + TSB falling)", $fat !== null);
    check("predicted_fatigue horizon == " . PredictiveConstants::FATIGUE_HORIZON_DAYS, $fat && (int)$fat['prediction_horizon_days'] === PredictiveConstants::FATIGUE_HORIZON_DAYS);
    check("predicted_fatigue confidence == medium (rpe sample caps 16wk tier)", $fat && $fat['confidence'] === 'medium');
    check("predicted_fatigue predicted_for_date is set", $fat && !empty($fat['predicted_for_date']));
    check("injury_risk_pattern fires (spike + RPE high)", $inj !== null);
    check("injury_risk_pattern horizon == " . PredictiveConstants::INJURY_HORIZON_DAYS, $inj && (int)$inj['prediction_horizon_days'] === PredictiveConstants::INJURY_HORIZON_DAYS);
    check("adaptation_ahead does NOT fire while fatigued", $openFlag('adaptation_ahead') === null);
    check("no plan generated by predictions (training_plans 0)", (int)$db->query("SELECT COUNT(*) FROM training_plans WHERE athlete_id = {$athleteId}")->fetchColumn() === 0);
    check("predictions did NOT auto-create a regeneration request", (int)$db->query("SELECT COUNT(*) FROM plan_regeneration_requests WHERE athlete_id = {$athleteId}")->fetchColumn() === 0);

    // ── Scenario 1 clear: conditions vanish → flags auto-resolve ──────────────
    $clearInputs();
    $seedWeeksCompletion(16, 0.9);                     // keep weeks_of_data; no RPE/volume/TSB triggers
    $evaluate();
    check("predicted_fatigue auto-resolves when condition clears", $openFlag('predicted_fatigue') === null && $dismissedExists('predicted_fatigue'));
    check("injury_risk_pattern auto-resolves when condition clears", $openFlag('injury_risk_pattern') === null && $dismissedExists('injury_risk_pattern'));

    // ── Scenario 2: dropout fires on declining engagement trajectory ──────────
    $resetAll();
    $seedWeeksCompletion(16, 0.8);
    foreach ([28 => 80, 21 => 60, 14 => 40, 7 => 25, 0 => 18] as $ago => $score) { $seedEngagement($ago, (float)$score); }
    $evaluate();
    $drop = $openFlag('predicted_dropout');
    check("predicted_dropout fires (declining slope + low absolute)", $drop !== null);
    check("predicted_dropout horizon == " . PredictiveConstants::DROPOUT_HORIZON_DAYS, $drop && (int)$drop['prediction_horizon_days'] === PredictiveConstants::DROPOUT_HORIZON_DAYS);

    // ── Scenario 3: adaptation_ahead fires (opportunity), no auto-regen ───────
    $resetAll();
    $seedWeeksCompletion(16, 0.95);                    // compliance high
    $seedRpe(-2.0, 3);                                 // quality RPE easy
    foreach ([21 => 40, 14 => 46, 7 => 52, 0 => 58] as $ago => $ctl) { $seedTsb($ago, 5.0, (float)$ctl); } // CTL rising
    $evaluate();
    $adapt = $openFlag('adaptation_ahead');
    check("adaptation_ahead fires (compliance high + RPE easy + CTL rising, no fatigue)", $adapt !== null);
    check("adaptation_ahead horizon == " . PredictiveConstants::ADAPT_HORIZON_DAYS, $adapt && (int)$adapt['prediction_horizon_days'] === PredictiveConstants::ADAPT_HORIZON_DAYS);
    check("adaptation_ahead did NOT auto-create a regeneration request", (int)$db->query("SELECT COUNT(*) FROM plan_regeneration_requests WHERE athlete_id = {$athleteId}")->fetchColumn() === 0);

    // ── Scenario 3 clear: RPE no longer easy → adaptation resolves ────────────
    $clearInputs();
    $seedWeeksCompletion(16, 0.95);
    $seedRpe(0.0, 3);
    $evaluate();
    check("adaptation_ahead auto-resolves when condition clears", $openFlag('adaptation_ahead') === null && $dismissedExists('adaptation_ahead'));

    // ── Scenario 4: confidence scales with data volume ────────────────────────
    $resetAll();
    $seedWeeksCompletion(5, 0.9);                      // only 5 weeks → 'low' tier
    $seedRpe(2.0, 3);
    $seedCompleted(1, 500); $seedCompleted(8, 150); $seedCompleted(15, 150); $seedCompleted(17, 150); $seedCompleted(22, 150);
    $seedTsb(7, -5, 50); $seedTsb(0, -20, 50);
    $evaluate();
    $fatLow = $openFlag('predicted_fatigue');
    check("confidence scales: 5 weeks of data → low (was medium at 16)", $fatLow && $fatLow['confidence'] === 'low');

    // ── Scenario 5: predicted_dropout hands off to the reactive engagement flags ──
    $countDropoutRows = function () use ($db, $athleteId) {
        return (int)$db->query("SELECT COUNT(*) FROM coaching_intelligence_flags WHERE athlete_id = {$athleteId} AND flag_type = 'predicted_dropout'")->fetchColumn();
    };
    $openReactive = function (string $type) use ($db, $athleteId, $coachUserId) {
        $db->prepare("INSERT INTO coaching_intelligence_flags (athlete_id, coach_id, created_at, flag_type, severity, title, detail, suggested_action, status)
                      VALUES (?, ?, NOW(), ?, 'warning', 'Reactive engagement flag', 'present-tense', 'reach out', 'open')")
           ->execute([$athleteId, $coachUserId, $type]);
    };
    $clearReactive = function (string $type) use ($db, $athleteId) {
        $db->prepare("UPDATE coaching_intelligence_flags SET status = 'dismissed', dismissed_at = NOW() WHERE athlete_id = ? AND flag_type = ? AND status = 'open'")->execute([$athleteId, $type]);
    };

    $resetAll();
    $seedWeeksCompletion(16, 0.8);
    foreach ([28 => 80, 21 => 60, 14 => 40, 7 => 25, 0 => 18] as $ago => $score) { $seedEngagement($ago, (float)$score); }
    $evaluate();
    check("handoff: predicted_dropout open before any reactive flag", $openFlag('predicted_dropout') !== null);

    // Phase 1's present-tense engagement_dropping opens → prediction has materialized.
    $openReactive('engagement_dropping');
    $evaluate();
    $resolvedRow = $db->query("SELECT status, suggested_action, dismissed_at FROM coaching_intelligence_flags WHERE athlete_id = {$athleteId} AND flag_type = 'predicted_dropout' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check("handoff: predicted_dropout auto-resolves when reactive opens", $openFlag('predicted_dropout') === null);
    check("handoff: resolve is condition-cleared path, marked [auto-resolved] (not coach dismissal)",
        $resolvedRow && $resolvedRow['status'] === 'dismissed'
        && strpos((string)$resolvedRow['suggested_action'], '[auto-resolved]') === 0
        && !empty($resolvedRow['dismissed_at']));

    // Reactive still open → suppressed; no re-raise / no flapping across passes.
    $rowsAfterResolve = $countDropoutRows();
    $evaluate();
    check("handoff: predicted_dropout stays down while reactive open (suppressed raise)", $openFlag('predicted_dropout') === null);
    check("handoff: no flapping — no new predicted_dropout row while reactive open", $countDropoutRows() === $rowsAfterResolve);

    // Reactive clears, trajectory still down → re-arm (cooldown must NOT block the handoff resolve).
    $clearReactive('engagement_dropping');
    $evaluate();
    check("re-arm: predicted_dropout raises again once reactive clears (not cooldown-blocked)", $openFlag('predicted_dropout') !== null);

    check("scope: predicted_fatigue / injury_risk_pattern / adaptation_ahead unaffected by handoff",
        $openFlag('predicted_fatigue') === null && $openFlag('injury_risk_pattern') === null && $openFlag('adaptation_ahead') === null);
    check("handoff: still no plan generated (training_plans 0)",
        (int)$db->query("SELECT COUNT(*) FROM training_plans WHERE athlete_id = {$athleteId}")->fetchColumn() === 0);
    check("handoff: still no auto-created regeneration request",
        (int)$db->query("SELECT COUNT(*) FROM plan_regeneration_requests WHERE athlete_id = {$athleteId}")->fetchColumn() === 0);

    echo "\n================================\n";
    echo "  CIL Phase 3 — Stage A + B verification\n";
    echo "  PASS: {$pass}   FAIL: {$fail}\n";
    echo "================================\n";

} finally {
    if ($athleteId) {
        foreach (['athlete_behavior_log','completed_workouts','planned_workouts','training_load',
                  'athlete_response_profiles','coaching_intelligence_flags','training_plans',
                  'engine_flags','athlete_profiles'] as $t) {
            try { $db->prepare("DELETE FROM {$t} WHERE athlete_id = ?")->execute([$athleteId]); } catch (\Throwable $e) {}
        }
        try { $db->prepare("DELETE FROM plan_regeneration_requests WHERE athlete_id = ?")->execute([$athleteId]); } catch (\Throwable $e) {}
        $db->prepare("DELETE FROM athletes WHERE id = ?")->execute([$athleteId]);
    }
    if ($userId)      { $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]); }
    if ($coachUserId) { $db->prepare("DELETE FROM users WHERE id = ?")->execute([$coachUserId]); }
}

exit($fail === 0 ? 0 : 1);

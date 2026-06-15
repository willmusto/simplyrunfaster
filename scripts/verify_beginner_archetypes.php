<?php
/**
 * Verification for the run_walk_intervals + standalone_strides foundation
 * (engine spec §19 item 6 / §18.10). Creates a throwaway test athlete, exercises
 * both the return_to_running pathway and an insufficient-base development plan,
 * asserts rendering + rtr_current_stage + clean validateGeneratedDisplays, then
 * deletes everything it created.
 *
 * Run: php scripts/verify_beginner_archetypes.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Engine/TrainingLoad.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';

$db = Database::get();

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  {$label}\n"; }
    else     { $fail++; echo "  FAIL  {$label}" . ($detail ? "  — {$detail}" : '') . "\n"; }
}

// ── Create throwaway test athlete ───────────────────────────────────────────
$suffix = 'rtrtest_' . time();
$db->prepare("INSERT INTO users (email, password_hash, role, name) VALUES (?, 'x', 'athlete', 'RTR Test')")
   ->execute(["{$suffix}@example.invalid"]);
$userId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO athletes (user_id, status, onboarding_completed_at) VALUES (?, 'active', NOW())")
   ->execute([$userId]);
$athleteId = (int)$db->lastInsertId();

$displayIncompleteFlags = function (int $planId) use ($db, $athleteId): int {
    $s = $db->prepare(
        "SELECT COUNT(*) FROM engine_flags
         WHERE athlete_id = ? AND flag_type = 'display_generation_incomplete'
           AND details LIKE ?"
    );
    try { $s->execute([$athleteId, '%"plan_id":' . $planId . ',%']); return (int)$s->fetchColumn(); }
    catch (Throwable $e) { return -1; }
};

$rows = function (int $planId) use ($db): array {
    $s = $db->prepare('SELECT * FROM planned_workouts WHERE plan_id = ? ORDER BY scheduled_date');
    $s->execute([$planId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};
$latestPlan = function () use ($db, $athleteId): array {
    $s = $db->prepare('SELECT * FROM training_plans WHERE athlete_id = ? ORDER BY id DESC LIMIT 1');
    $s->execute([$athleteId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
};
$hasUnresolvedTokens = function (array $r): bool {
    foreach (['display_title', 'display_summary', 'athlete_instructions'] as $f) {
        if (preg_match('/\{\{\w+\}\}/', (string)($r[$f] ?? ''))) return true;
    }
    return false;
};

try {
    // ════════════════════════════════════════════════════════════════════════
    // TEST A — return_to_running
    // ════════════════════════════════════════════════════════════════════════
    echo "TEST A — return_to_running (stage 1, every-other-day, must-off Sun, bike)\n";
    $db->prepare(
        "INSERT INTO athlete_profiles
            (athlete_id, plan_type, goal_race_distance, training_days_per_week,
             must_off_days, current_weekly_minutes, longest_recent_run_mins,
             return_time_off_band, cross_training_bike)
         VALUES (?, 'return_to_running', '5K', 3, '[0]', 80, 20, '6_16_weeks', 'stationary')"
    )->execute([$athleteId]);

    $planId = PlanGenerator::generate($athleteId, 'coach_manual');
    check('R2R generation returns a plan id', $planId !== null);
    $plan = $latestPlan();
    check('rtr_current_stage = 1', (int)($plan['rtr_current_stage'] ?? 0) === 1, 'got ' . var_export($plan['rtr_current_stage'] ?? null, true));

    $r2rRows = $rows((int)$planId);
    $runDays = array_values(array_filter($r2rRows, fn($r) => $r['archetype_code'] === 'run_walk_intervals'));
    $crossDays = array_values(array_filter($r2rRows, fn($r) => $r['workout_type'] === 'cross_train'));
    check('run days use run_walk_intervals', count($runDays) > 0, count($runDays) . ' run days');
    check('run days capped by training_days_per_week (<=3)', count($runDays) <= 3, count($runDays) . ' run days');
    check('no run day on a must-off Sunday',
        count(array_filter($runDays, fn($r) => (int)date('w', strtotime($r['scheduled_date'])) === 0)) === 0);
    check('cross-training days present (bike equipment)', count($crossDays) > 0, count($crossDays) . ' cross days');
    check('cross/rest days carry coach-drill note',
        count($crossDays) === 0 || strpos((string)$crossDays[0]['athlete_instructions'], 'rehab Phases') !== false);

    $rw = $runDays[0] ?? [];
    check('run/walk variant is stage_1', ($rw['archetype_variant'] ?? '') === 'stage_1', $rw['archetype_variant'] ?? 'none');
    check('run/walk workout_type = easy', ($rw['workout_type'] ?? '') === 'easy', $rw['workout_type'] ?? 'none');
    check('run/walk title is stage-specific',
        strpos((string)($rw['display_title'] ?? ''), 'Stage 1:') === 0, $rw['display_title'] ?? 'none');
    check('run/walk target_duration = 55 (10+40+5)', (int)($rw['target_duration'] ?? 0) === 55, (string)($rw['target_duration'] ?? 0));
    check('run/walk instruction is effort-only (no /mile)',
        strpos((string)($rw['athlete_instructions'] ?? ''), '/mile') === false);
    check('no unresolved tokens in any R2R row',
        count(array_filter($r2rRows, $hasUnresolvedTokens)) === 0);
    check('validateGeneratedDisplays clean for R2R plan', $displayIncompleteFlags((int)$planId) === 0);
    echo "    sample: [{$rw['display_title']}] {$rw['display_summary']}\n";
    echo "    instr:  " . substr((string)$rw['athlete_instructions'], 0, 110) . "...\n\n";

    // ════════════════════════════════════════════════════════════════════════
    // TEST B — insufficient-base development plan
    // ════════════════════════════════════════════════════════════════════════
    echo "TEST B — insufficient-base development_plan\n";
    $db->prepare(
        "UPDATE athlete_profiles
            SET plan_type = 'development_plan', training_days_per_week = 4,
                current_weekly_minutes = 110, longest_recent_run_mins = 30,
                goal_race_distance = '5K'
          WHERE athlete_id = ?"
    )->execute([$athleteId]);

    // Confirm the athlete classifies as insufficient.
    $cm = new ReflectionMethod('PlanGenerator', 'classifyAthlete');
    $cm->setAccessible(true);
    $prof = $db->prepare('SELECT * FROM athlete_profiles WHERE athlete_id = ?');
    $prof->execute([$athleteId]);
    $profRow = $prof->fetch(PDO::FETCH_ASSOC);
    $classification = $cm->invoke(null, $profRow, '5K');
    check("athlete classifies as insufficient", $classification === 'insufficient', "got {$classification}");

    $planId2 = PlanGenerator::generate($athleteId, 'coach_manual');
    check('dev generation returns a plan id', $planId2 !== null);
    $devRows = $rows((int)$planId2);

    $rwRows  = array_filter($devRows, fn($r) => $r['archetype_code'] === 'run_walk_intervals');
    $ssRows  = array_filter($devRows, fn($r) => $r['archetype_code'] === 'standalone_strides');
    check('run_walk_intervals appears in insufficient dev plan', count($rwRows) > 0, count($rwRows) . ' rows');
    check('standalone_strides appears in insufficient dev plan', count($ssRows) > 0, count($ssRows) . ' rows');

    $rwAll = array_values($rwRows);
    check('dev run/walk is fixed stage 1',
        count(array_filter($rwAll, fn($r) => $r['archetype_variant'] !== 'stage_1')) === 0);
    $ss = array_values($ssRows)[0] ?? [];
    check('standalone_strides renders a title/instruction',
        !empty($ss['display_title']) && !empty($ss['athlete_instructions']));
    check('no unresolved tokens in any dev row',
        count(array_filter($devRows, $hasUnresolvedTokens)) === 0);
    check('validateGeneratedDisplays clean for dev plan', $displayIncompleteFlags((int)$planId2) === 0);

    $codes = array_count_values(array_map(fn($r) => (string)$r['archetype_code'], $devRows));
    echo "    archetype mix: " . json_encode($codes) . "\n";
    if ($ss) echo "    strides: [{$ss['display_title']}] {$ss['display_summary']}\n";

} finally {
    // ── Cleanup ─────────────────────────────────────────────────────────────
    $planIds = $db->prepare('SELECT id FROM training_plans WHERE athlete_id = ?');
    $planIds->execute([$athleteId]);
    foreach ($planIds->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $db->prepare('DELETE FROM plan_approval_queue WHERE plan_id = ?')->execute([$pid]);
        $db->prepare('DELETE FROM planned_workouts WHERE plan_id = ?')->execute([$pid]);
    }
    $db->prepare('DELETE FROM training_plans WHERE athlete_id = ?')->execute([$athleteId]);
    $db->prepare('DELETE FROM engine_flags WHERE athlete_id = ?')->execute([$athleteId]);
    $db->prepare('DELETE FROM athlete_profiles WHERE athlete_id = ?')->execute([$athleteId]);
    $db->prepare('DELETE FROM athletes WHERE id = ?')->execute([$athleteId]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    echo "\nCleaned up test athlete (athlete_id={$athleteId}, user_id={$userId}).\n";
}

echo "\n=====================================\n";
echo ($fail === 0 ? "ALL PASS" : "FAILURES: {$fail}") . "  ({$pass} passed, {$fail} failed)\n";
exit($fail === 0 ? 0 : 1);

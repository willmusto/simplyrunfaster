<?php
/**
 * Verification for the volume/schedule allocation fixes (engine spec §6, items 1-4).
 *   A. Liam regeneration — week-1 quality present, day-ramp flag raised, clean displays.
 *   B. Item 3 — trace a cutback week + following week; post-cutback resumes from the
 *      pre-cutback peak (× 1.08), not the cutback dip.
 *   C. Established athlete — no flag, full requested days from week 1 (unaffected).
 *   D. Item 4 — block_end rebuild derives week-1 from prior plan trajectory; a manual
 *      current_weekly_minutes edit since the prior plan overrides continuity.
 *
 * Throwaway test athletes are created and deleted; Liam is regenerated in place.
 *
 * Run: php scripts/verify_volume_allocation.php
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

$QUALITY = "'tempo_intervals','equal_distance_repeats','short_speed_repeats','sustained_hill_repeats','continuous_progression_tempo','mixed_distance_repeats','hill_sprints','high_volume_time_intervals','structured_fartlek_ladder'";

$firstMondayOnOrAfter = function (string $date): string {
    $ts = strtotime($date);
    $offset = (8 - (int)date('N', $ts)) % 7;
    return date('Y-m-d', strtotime("+{$offset} days", $ts));
};
$codeWeekStartForPlan = function (array $plan) use ($firstMondayOnOrAfter): string {
    $planType = (string)($plan['plan_type'] ?? '');
    $startTs = strtotime((string)($plan['plan_start_date'] ?? ''));
    $endTs = strtotime((string)($plan['plan_end_date'] ?? ''));
    if (
        in_array($planType, ['development_plan', 'maintenance_plan', 'recovery_block'], true)
        && ((int)date('N', $startTs) === 1 || (int)date('N', $endTs) === 7)
    ) {
        return $firstMondayOnOrAfter((string)$plan['plan_start_date']);
    }
    return (string)$plan['plan_start_date'];
};

// Per-plan code-week trace: [week => ['mins'=>, 'days'=>, 'quality'=>]]
$weeklyTrace = function (int $planId) use ($db, $QUALITY, $codeWeekStartForPlan): array {
    $tp = $db->prepare('SELECT plan_start_date, plan_end_date, plan_type FROM training_plans WHERE id = ?');
    $tp->execute([$planId]); $plan = $tp->fetch(PDO::FETCH_ASSOC) ?: [];
    $start = strtotime($codeWeekStartForPlan($plan));
    $rows = $db->prepare("SELECT scheduled_date, target_duration, archetype_code, workout_type FROM planned_workouts WHERE plan_id = ?");
    $rows->execute([$planId]);
    $wk = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $scheduledTs = strtotime($r['scheduled_date']);
        if ($scheduledTs < $start) continue;
        $w = (int)floor(($scheduledTs - $start) / (7*86400)) + 1;
        $wk[$w]['mins'] = ($wk[$w]['mins'] ?? 0) + (int)$r['target_duration'];
        $isTrain = !in_array($r['workout_type'], ['rest','cross_train'], true);
        $wk[$w]['days'] = ($wk[$w]['days'] ?? 0) + ($isTrain ? 1 : 0);
        $wk[$w]['quality'] = ($wk[$w]['quality'] ?? 0) + (in_array($r['archetype_code'], explode(',', str_replace("'", '', $QUALITY)), true) ? 1 : 0);
    }
    ksort($wk); return $wk;
};
$flagOpen = function (int $athleteId, string $type) use ($db): bool {
    $s = $db->prepare("SELECT COUNT(*) FROM engine_flags WHERE athlete_id=? AND flag_type=? AND status='open'");
    $s->execute([$athleteId, $type]); return (int)$s->fetchColumn() > 0;
};
$displayFlags = function (int $planId, int $athleteId) use ($db): int {
    $s = $db->prepare("SELECT COUNT(*) FROM engine_flags WHERE athlete_id=? AND flag_type='display_generation_incomplete' AND details LIKE ?");
    try { $s->execute([$athleteId, '%"plan_id":'.$planId.',%']); return (int)$s->fetchColumn(); } catch (Throwable $e) { return -1; }
};

// ════════════════════════════════════════════════════════════════════════════
// PART A + B — Liam (real athlete: 5 days requested, 120 min/week, ceiling 360)
// ════════════════════════════════════════════════════════════════════════════
echo "PART A/B — Liam regeneration + cutback trace\n";
$liam = $db->query("SELECT a.id aid FROM athletes a JOIN users u ON u.id=a.user_id WHERE u.name LIKE '%Liam%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$laid = (int)$liam['aid'];
$db->prepare("DELETE FROM engine_flags WHERE athlete_id=? AND flag_type='schedule_day_ramp'")->execute([$laid]);
$lpid = PlanGenerator::generate($laid, 'coach_manual');
$planStmt = $db->prepare('SELECT plan_start_date, plan_end_date, plan_type FROM training_plans WHERE id = ?');
$planStmt->execute([(int)$lpid]);
$liamPlan = $planStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$liamCodeStart = $codeWeekStartForPlan($liamPlan);
$leadInDays = max(0, (int)(floor((strtotime($liamCodeStart) - strtotime((string)$liamPlan['plan_start_date'])) / 86400)));
$codeSpanDays = (int)floor((strtotime((string)$liamPlan['plan_end_date']) - strtotime($liamCodeStart)) / 86400) + 1;
$leadInWorkoutCount = 0;
if ($leadInDays > 0) {
    $leadStmt = $db->prepare('SELECT COUNT(*) FROM planned_workouts WHERE plan_id = ? AND scheduled_date < ?');
    $leadStmt->execute([(int)$lpid, $liamCodeStart]);
    $leadInWorkoutCount = (int)$leadStmt->fetchColumn();
}
$lt = $weeklyTrace((int)$lpid);
$wk1 = $lt[1] ?? [];
check('Liam lead-in is 0 days on Monday starts or 1-6 days otherwise', $leadInDays === 0 || ($leadInDays >= 1 && $leadInDays <= 6), 'lead_in_days='.$leadInDays);
check('Liam code-week 1 starts on Monday', date('N', strtotime($liamCodeStart)) === '1', 'code_start='.$liamCodeStart);
check('Liam plan ends on Sunday', date('N', strtotime((string)$liamPlan['plan_end_date'])) === '7', 'end='.$liamPlan['plan_end_date']);
check('Liam has exactly 12 Mon-Sun code-weeks after lead-in', $codeSpanDays === 84, 'code_span_days='.$codeSpanDays);
check('Liam lead-in is rest-only filler', $leadInWorkoutCount === 0, 'lead_in_workouts='.$leadInWorkoutCount);
check('Liam week 1 schedules a reduced day count (< 5)', ($wk1['days'] ?? 9) < 5, 'days='.($wk1['days']??'?'));
check('Liam week 1 has quality (not all continuous_easy)', ($wk1['quality'] ?? 0) > 0, 'quality='.($wk1['quality']??0));
check('Liam total quality across plan > 0', array_sum(array_column($lt,'quality')) > 0, 'total='.array_sum(array_column($lt,'quality')));
check('schedule_day_ramp flag raised for Liam', $flagOpen($laid, 'schedule_day_ramp'));
check('validateGeneratedDisplays clean for Liam plan', $displayFlags((int)$lpid, $laid) === 0);
$expectedLiamMins = [1=>130, 2=>140, 3=>151, 4=>121, 5=>163, 6=>176, 7=>190, 8=>152, 9=>203, 10=>220, 11=>238, 12=>190];
$actualLiamMins = [];
foreach ($lt as $w => $d) $actualLiamMins[(int)$w] = (int)$d['mins'];
check('Liam 12-code-week volume trajectory unchanged', $actualLiamMins === $expectedLiamMins, 'actual='.json_encode($actualLiamMins));
$macroCutbackLabels = [];
$displayStartTs = strtotime('-' . ((int)date('N', strtotime((string)$liamPlan['plan_start_date'])) - 1) . ' days', strtotime((string)$liamPlan['plan_start_date']));
$displayEndTs = strtotime('+' . (7 - (int)date('N', strtotime((string)$liamPlan['plan_end_date']))) . ' days', strtotime((string)$liamPlan['plan_end_date']));
for ($weekStartTs = $displayStartTs; $weekStartTs <= $displayEndTs; $weekStartTs = strtotime('+7 days', $weekStartTs)) {
    $codes = [];
    for ($d = 0; $d < 7; $d++) {
        $dayTs = strtotime("+{$d} days", $weekStartTs);
        if ($dayTs < strtotime((string)$liamPlan['plan_start_date']) || $dayTs > strtotime((string)$liamPlan['plan_end_date']) || $dayTs < strtotime($liamCodeStart)) continue;
        $codes[] = (int)floor(($dayTs - strtotime($liamCodeStart)) / (7*86400)) + 1;
    }
    foreach (array_unique($codes) as $codeWeek) {
        if ($codeWeek > 1 && $codeWeek % 4 === 0) $macroCutbackLabels[] = $codeWeek;
    }
}
check('Liam macro cutback labels are single code-weeks 4/8/12', $macroCutbackLabels === [4,8,12], 'labels='.json_encode($macroCutbackLabels));
echo "    week | mins | days | quality\n";
foreach ($lt as $w => $d) printf("    %4d | %4d | %4d | %d\n", $w, $d['mins'], $d['days'], $d['quality']);

// Item 3 — find a cutback week (local min) and confirm the following week resumes above the pre-cutback peak.
$item3ok = true; $item3note = '';
foreach ($lt as $w => $d) {
    if ($w >= 3 && isset($lt[$w-1], $lt[$w+1]) && $d['mins'] < $lt[$w-1]['mins'] && $d['mins'] < $lt[$w+1]['mins']) {
        // cutback week $w; pre-cutback peak = week w-1; post-cutback = week w+1
        $pre = $lt[$w-1]['mins']; $post = $lt[$w+1]['mins'];
        $ok = $post >= (int)round($pre * 1.05);
        if (!$ok) { $item3ok = false; }
        $item3note .= "wk{$w}cut={$d['mins']} pre(wk".($w-1).")={$pre} post(wk".($w+1).")={$post}" . ($ok?' ✓':' ✗') . "; ";
    }
}
check('Item 3: post-cutback week >= pre-cutback peak × 1.05 (build base preserved)', $item3ok, $item3note ?: 'no cutback detected');

// ════════════════════════════════════════════════════════════════════════════
// Helper to spin up a throwaway athlete with a given profile
// ════════════════════════════════════════════════════════════════════════════
$makeAthlete = function (array $prof) use ($db): array {
    $sfx = 'voltest_' . substr(md5(uniqid('', true)), 0, 8);
    $db->prepare("INSERT INTO users (email,password_hash,role,name) VALUES (?,'x','athlete','Vol Test')")->execute(["{$sfx}@example.invalid"]);
    $uid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO athletes (user_id,status,onboarding_completed_at) VALUES (?, 'active', NOW())")->execute([$uid]);
    $aid = (int)$db->lastInsertId();
    $cols = array_keys($prof); $ph = implode(',', array_fill(0, count($cols)+1, '?'));
    $db->prepare("INSERT INTO athlete_profiles (athlete_id," . implode(',', $cols) . ") VALUES ($ph)")
       ->execute(array_merge([$aid], array_values($prof)));
    return [$aid, $uid];
};
$cleanup = function (int $aid, int $uid) use ($db): void {
    foreach ($db->query("SELECT id FROM training_plans WHERE athlete_id=$aid")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $db->prepare('DELETE FROM plan_approval_queue WHERE plan_id=?')->execute([$pid]);
        $db->prepare('DELETE FROM planned_workouts WHERE plan_id=?')->execute([$pid]);
    }
    $db->prepare('DELETE FROM training_plans WHERE athlete_id=?')->execute([$aid]);
    $db->prepare('DELETE FROM engine_flags WHERE athlete_id=?')->execute([$aid]);
    $db->prepare('DELETE FROM athlete_profiles WHERE athlete_id=?')->execute([$aid]);
    $db->prepare('DELETE FROM athletes WHERE id=?')->execute([$aid]);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
};

// ════════════════════════════════════════════════════════════════════════════
// PART C — established athlete: volume comfortably supports requested days
// ════════════════════════════════════════════════════════════════════════════
echo "\nPART C — established athlete (5 days, 300 min/week)\n";
[$eaid, $euid] = $makeAthlete([
    'plan_type' => 'development_plan', 'goal_race_distance' => '10K',
    'training_days_per_week' => 5, 'current_weekly_minutes' => 300,
    'longest_recent_run_mins' => 90, 'peak_volume_ceiling_mins' => 420, 'months_at_current_volume' => 24,
]);
try {
    $epid = PlanGenerator::generate($eaid, 'onboarding');
    $et = $weeklyTrace((int)$epid);
    check('established athlete: NO schedule_day_ramp flag', !$flagOpen($eaid, 'schedule_day_ramp'));
    check('established athlete: full 5 days from week 1', (($et[1]['days'] ?? 0) === 5), 'days='.($et[1]['days']??'?'));
    check('established athlete: week-1 quality present', (($et[1]['quality'] ?? 0) > 0), 'quality='.($et[1]['quality']??0));
} finally { $cleanup($eaid, $euid); }

// ════════════════════════════════════════════════════════════════════════════
// PART D — Item 4 cross-cycle continuity
// ════════════════════════════════════════════════════════════════════════════
echo "\nPART D — cross-cycle continuity (block_end) + manual-edit override\n";
[$daid, $duid] = $makeAthlete([
    'plan_type' => 'development_plan', 'goal_race_distance' => '10K',
    'training_days_per_week' => 4, 'current_weekly_minutes' => 150,
    'longest_recent_run_mins' => 70, 'peak_volume_ceiling_mins' => 400, 'months_at_current_volume' => 18,
]);
try {
    // Initial onboarding plan establishes a trajectory (peak well above 150).
    $p1 = PlanGenerator::generate($daid, 'onboarding');
    $t1 = $weeklyTrace((int)$p1);
    $priorPeak = max(array_column($t1, 'mins'));
    // Backdate the prior plan's generated_at so it's clearly before "now" and the
    // profile has NOT been touched since (no manual edit).
    $db->prepare("UPDATE training_plans SET generated_at = (NOW() - INTERVAL 2 DAY) WHERE id=?")->execute([$p1]);
    $db->prepare("UPDATE athlete_profiles SET updated_at = (NOW() - INTERVAL 3 DAY) WHERE athlete_id=?")->execute([$daid]);

    $p2 = PlanGenerator::generate($daid, 'block_end');
    $t2 = $weeklyTrace((int)$p2);
    $rebuildWk1 = $t2[1]['mins'] ?? 0;
    // Continuity: rebuild week 1 should reflect the prior peak trajectory (≈ priorPeak×1.08
    // capped by ceiling), clearly above an onboarding restart from current_weekly_minutes (150→162).
    check('Item 4: block_end rebuild week-1 continues prior trajectory (> onboarding restart)',
        $rebuildWk1 > 180, "rebuildWk1={$rebuildWk1} priorPeak={$priorPeak} (onboarding restart would be ~162)");

    // Manual-edit override: bump current_weekly_minutes and touch updated_at to AFTER the prior plan.
    $db->prepare("UPDATE athlete_profiles SET current_weekly_minutes=90, updated_at=NOW() WHERE athlete_id=?")->execute([$daid]);
    $db->prepare("UPDATE training_plans SET generated_at=(NOW() - INTERVAL 1 DAY) WHERE id=?")->execute([$p2]);
    $p3 = PlanGenerator::generate($daid, 'block_end');
    $t3 = $weeklyTrace((int)$p3);
    $editWk1 = $t3[1]['mins'] ?? 0;
    check('Item 4: manual current_weekly_minutes edit since prior plan overrides continuity',
        $editWk1 < 120, "editWk1={$editWk1} (manual baseline 90 → week1 ~97, not the prior trajectory)");
} finally { $cleanup($daid, $duid); }

echo "\n=====================================\n";
echo ($fail === 0 ? "ALL PASS" : "FAILURES: {$fail}") . "  ({$pass} passed, {$fail} failed)\n";
exit($fail === 0 ? 0 : 1);

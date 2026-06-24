<?php
/**
 * LIVE backend verification for the structured workout editor (Phase 1, 5 uniform-rep
 * archetypes). Creates a throwaway athlete + planned_workouts rows, runs the real
 * composeStructuredEdit / composeWorkoutEdit, asserts structure + re-render + push agree,
 * then hard-deletes everything. No UI involved.
 *
 * Run from /home/private/app: php scripts/verify_structured_editor.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Timezone.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
if (is_file(__DIR__ . '/../src/Engine/RecoveryModel.php')) require_once __DIR__ . '/../src/Engine/RecoveryModel.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';
require_once __DIR__ . '/../src/IntervalsService.php';
require_once __DIR__ . '/../src/Controllers/CoachController.php';

$db = Database::get();
$fails = [];
function check(bool $c, string $m): void { global $fails; if (!$c) $fails[] = $m; }

$athleteTables = ['planned_workouts','training_plans','athlete_profiles','engine_flags','plan_approval_queue'];
$cleanup = function (int $aid, int $uid) use ($db, $athleteTables) {
    foreach ($athleteTables as $t) { try { $db->prepare("DELETE FROM `$t` WHERE athlete_id=?")->execute([$aid]); } catch (\Throwable $e) {} }
    foreach (['athletes'] as $t) { try { $db->prepare("DELETE FROM `$t` WHERE id=?")->execute([$aid]); } catch (\Throwable $e) {} }
    try { $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]); } catch (\Throwable $e) {}
};

// throwaway well_trained athlete
$sfx = 'sedit_' . substr(md5(uniqid('', true)), 0, 8);
$db->prepare("INSERT INTO users (email,password_hash,role,name) VALUES (?,'x','athlete','SEdit')")->execute(["{$sfx}@example.invalid"]);
$uid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO athletes (user_id,status,onboarding_completed_at) VALUES (?, 'active', NOW())")->execute([$uid]);
$aid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO athlete_profiles (athlete_id, plan_type, goal_race_distance, training_days_per_week, current_weekly_minutes, longest_recent_run_mins, peak_volume_ceiling_mins, months_at_current_volume) VALUES (?, 'development_plan', '10K', 6, 320, 110, 448, 24)")->execute([$aid]);
$db->prepare("INSERT INTO training_plans (athlete_id, plan_type, plan_start_date, plan_end_date, status, generation_trigger) VALUES (?, 'development_plan', CURDATE(), CURDATE()+INTERVAL 84 DAY, 'pending_approval', 'coach_manual')")->execute([$aid]);
$planId = (int)$db->lastInsertId();

$structOf = function (?string $json): array { $d = json_decode((string)$json, true); $s = []; foreach (($d['segments'] ?? []) as $seg) $s[strtolower($seg['segment_type'] ?? '')] = $seg; return $s; };

/** Insert a generated workout as a planned_workouts row (push_text_only=1 to simulate a prior surface edit). */
function seedWorkout($db, $aid, $planId, $code, $variant) {
    $w = PlanGenerator::composeManualWorkout($aid, $code, $variant, 70, $db);
    if (!$w) return null;
    $db->prepare("INSERT INTO planned_workouts (plan_id, athlete_id, scheduled_date, workout_type, archetype_code, archetype_variant, archetype_params, structure, display_title, athlete_instructions, target_duration, push_text_only, visible_to_athlete) VALUES (?,?,CURDATE()+INTERVAL 3 DAY,?,?,?,?,?,?,?,?,1,1)")
       ->execute([$planId, $aid, $w['workout_type'], $code, $w['archetype_variant'], $w['archetype_params'], $w['structure'], $w['display_title'], $w['athlete_instructions'], $w['target_duration']]);
    return (int)$db->lastInsertId();
}

try {
    echo "\n=== Structured editor (Phase 1) backend verification ===\n";

    // ── tempo: rep_count 8->6 + rep_duration 10 + recovery 45 ──
    echo "\n[tempo_intervals] edit rep_count->6, rep_duration->10, recovery->45:\n";
    $id = seedWorkout($db, $aid, $planId, 'tempo_intervals', 'time_based');
    $cols = PlanGenerator::composeStructuredEdit($id, ['rep_count'=>6,'rep_duration_minutes'=>10,'recovery_duration_seconds'=>45], $db);
    $seg = $structOf($cols['structure'])['tempo_intervals'] ?? [];
    printf("  structure rep_count=%s rep_duration_minutes=%s ; params recovery=%s\n", $seg['rep_count']??'?', $seg['rep_duration_minutes']??'?', (json_decode($cols['archetype_params'],true)['recovery_duration_seconds']??'?'));
    check((int)($seg['rep_count']??0)===6 && (int)($seg['rep_duration_minutes']??0)===10, "tempo structure not updated to 6x10");
    $watch = IntervalsService::generateWorkoutText(['structure'=>$cols['structure'],'archetype_params'=>$cols['archetype_params'],'athlete_instructions'=>$cols['athlete_instructions'],'archetype_code'=>'tempo_intervals','push_text_only'=>0], ['archetype_code'=>'tempo_intervals']);
    $hasMain = strpos($watch,'Main Set 6x')!==false && strpos($watch,'10m')!==false; $rec45 = strpos($watch,'45s')!==false;
    printf("  watch: Main Set 6x + 10m: %s ; explicit 45s recovery: %s\n", $hasMain?'yes':'NO', $rec45?'yes':'NO');
    check($hasMain, "tempo watch steps not 6x10"); check($rec45, "tempo explicit recovery 45s not honored");

    // ── coach override 7x7 (violates even-or-3): allowed ──
    $cols7 = PlanGenerator::composeStructuredEdit($id, ['rep_count'=>7,'rep_duration_minutes'=>7], $db);
    $seg7 = $structOf($cols7['structure'])['tempo_intervals'] ?? [];
    printf("\n[override] tempo 7x7 (violates even-or-3): structure rep_count=%s\n", $seg7['rep_count']??'?');
    check((int)($seg7['rep_count']??0)===7, "coach override 7 was snapped/blocked (should be allowed)");

    // ── sanity: rep_count 40 accepted by backend (warning is client-side, never blocks) ──
    $cols40 = PlanGenerator::composeStructuredEdit($id, ['rep_count'=>40], $db);
    check($cols40 !== null && (int)(($structOf($cols40['structure'])['tempo_intervals']['rep_count'])??0)===40, "rep_count 40 should save (no hard block)");
    echo "  rep_count 40 accepted (no backend block; warning is client-side)\n";

    // ── equal_distance: rep_distance 600->800 (dropdown) ──
    echo "\n[equal_distance_repeats] edit rep_distance->800:\n";
    $id2 = seedWorkout($db, $aid, $planId, 'equal_distance_repeats', '600s');
    $cols2 = PlanGenerator::composeStructuredEdit($id2, ['rep_count'=>6,'rep_distance_meters'=>800], $db);
    $seg2 = $structOf($cols2['structure'])['repeats'] ?? [];
    printf("  structure rep_distance_meters=%s ; title=\"%s\" ; variant=%s\n", $seg2['rep_distance_meters']??'?', $cols2['display_title'], $cols2['archetype_variant']);
    check((int)($seg2['rep_distance_meters']??0)===800, "equal_distance structure not 800m");
    check(strpos((string)$cols2['display_title'],'800m')!==false, "equal_distance title not '6 x 800m'");
    check($cols2['archetype_variant']==='800s', "variant not updated to 800s");

    // ── short_speed: rep_distance 200 ──
    echo "\n[short_speed_repeats] edit rep_distance->200:\n";
    $id3 = seedWorkout($db, $aid, $planId, 'short_speed_repeats', 'speed_300s');
    $cols3 = PlanGenerator::composeStructuredEdit($id3, ['rep_count'=>8,'rep_distance_meters'=>200], $db);
    $seg3 = $structOf($cols3['structure'])['speed_repeats'] ?? [];
    printf("  structure rep_distance_meters=%s ; title=\"%s\"\n", $seg3['rep_distance_meters']??'?', $cols3['display_title']);
    check((int)($seg3['rep_distance_meters']??0)===200, "short_speed structure not 200m");

    // ── hills: rep_duration_seconds 90 ──
    echo "\n[sustained_hill_repeats] edit rep_duration_seconds->90:\n";
    $id4 = seedWorkout($db, $aid, $planId, 'sustained_hill_repeats', 'standard_sustained');
    $cols4 = PlanGenerator::composeStructuredEdit($id4, ['rep_count'=>6,'rep_duration_seconds'=>90], $db);
    $seg4 = $structOf($cols4['structure'])['hill_repeats'] ?? [];
    printf("  structure rep_duration_seconds=%s ; title=\"%s\"\n", $seg4['rep_duration_seconds']??'?', $cols4['display_title']);
    check((int)($seg4['rep_duration_seconds']??0)===90, "hills structure not 90s");
    check(strpos((string)$cols4['display_title'],'1m 30s')!==false || strpos((string)$cols4['display_title'],'1m30s')!==false, "hills title rep_duration_display wrong");

    // ── high_volume: work 120 / recovery 60 ──
    echo "\n[high_volume_time_intervals] edit work->120, recovery->60:\n";
    $id5 = seedWorkout($db, $aid, $planId, 'high_volume_time_intervals', null);
    $cols5 = PlanGenerator::composeStructuredEdit($id5, ['rep_count'=>16,'work_duration_seconds'=>120,'recovery_duration_seconds'=>60], $db);
    $seg5 = $structOf($cols5['structure'])['time_intervals'] ?? [];
    printf("  structure rep_count=%s work=%s recovery=%s ; title=\"%s\"\n", $seg5['rep_count']??'?', $seg5['work_duration_seconds']??'?', $seg5['recovery_duration_seconds']??'?', $cols5['display_title']);
    check((int)($seg5['rep_count']??0)===16 && (int)($seg5['work_duration_seconds']??0)===120 && (int)($seg5['recovery_duration_seconds']??0)===60, "high_volume structure not 16x120/60");

    // ── controller wiring: composeWorkoutEdit('structured') clears push_text_only ──
    echo "\n[controller] composeWorkoutEdit('structured') clears push_text_only:\n";
    $before = $db->query("SELECT * FROM planned_workouts WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $r = CoachController::composeWorkoutEdit('structured', $before, ['mode'=>'structured','rep_count'=>6,'rep_duration_minutes'=>12], $db);
    printf("  push_text_only=%s ; writes structure: %s ; change_type=%s\n", var_export($r['columns']['push_text_only']??'?',true), array_key_exists('structure',$r['columns'])?'yes':'NO', $r['change_type']??'?');
    check(($r['columns']['push_text_only']??null)===0, "structured edit must clear push_text_only");
    check(array_key_exists('structure',$r['columns']) && !empty($r['columns']['structure']), "structured edit must write structure");

    // structured edit rejects a non-uniform archetype (mixed = Phase 2)
    check(PlanGenerator::isStructuredEditable('mixed_distance_repeats') === false, "mixed should NOT be structured-editable in Phase 1");
    check(PlanGenerator::isStructuredEditable('tempo_intervals') === true, "tempo should be structured-editable");

    // ── regression: generation (manual=false) still even-or-3 ──
    echo "\n[regression] generation unchanged (tempo even-or-3 over 40 samples):\n";
    $ok = true; for ($i=0;$i<40;$i++){ $w=PlanGenerator::composeManualWorkout($aid,'tempo_intervals',null,60,$db); $rc=json_decode($w['archetype_params'],true)['rep_count']??0; if(!($rc===3||$rc%2===0))$ok=false; }
    printf("  even-or-3 holds: %s\n", $ok?'yes':'NO'); check($ok, "generation regressed (non-even-or-3)");

} finally {
    echo "\n=== Teardown ===\n";
    $cleanup($aid, $uid);
    $resid = (int)$db->query("SELECT (SELECT COUNT(*) FROM planned_workouts WHERE athlete_id=$aid)+(SELECT COUNT(*) FROM athletes WHERE id=$aid)+(SELECT COUNT(*) FROM users WHERE id=$uid)")->fetchColumn();
    echo $resid===0 ? "0 residual rows.\n" : "RESIDUAL: $resid\n";
    if ($resid !== 0) $fails[] = "teardown residual";
}

echo "\n=== Verdict ===\n";
if (empty($fails)) { echo "PASS\n\n"; exit(0); }
echo "FAIL (".count($fails)."):\n"; foreach ($fails as $f) echo "  - $f\n"; echo "\n"; exit(1);

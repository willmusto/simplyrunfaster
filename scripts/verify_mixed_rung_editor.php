<?php
/**
 * LIVE backend verification for the structured editor Phase 2: mixed_distance_repeats rung
 * editor. Throwaway athlete + planned_workouts row; runs the real composeStructuredEdit /
 * composeWorkoutEdit + generateWorkoutText to confirm an edited ladder updates structure,
 * re-renders, and pushes in the NEW order. Full teardown.
 *
 * Run from /home/private/app: php scripts/verify_mixed_rung_editor.php
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

$sfx = 'mrung_' . substr(md5(uniqid('', true)), 0, 8);
$db->prepare("INSERT INTO users (email,password_hash,role,name) VALUES (?,'x','athlete','MRung')")->execute(["{$sfx}@example.invalid"]);
$uid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO athletes (user_id,status,onboarding_completed_at) VALUES (?, 'active', NOW())")->execute([$uid]);
$aid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO athlete_profiles (athlete_id, plan_type, goal_race_distance, training_days_per_week, current_weekly_minutes, longest_recent_run_mins, peak_volume_ceiling_mins, months_at_current_volume) VALUES (?, 'development_plan', '10K', 6, 320, 110, 448, 24)")->execute([$aid]);
$db->prepare("INSERT INTO training_plans (athlete_id, plan_type, plan_start_date, plan_end_date, status, generation_trigger) VALUES (?, 'development_plan', CURDATE(), CURDATE()+INTERVAL 84 DAY, 'pending_approval', 'coach_manual')")->execute([$aid]);
$planId = (int)$db->lastInsertId();
$cleanup = function () use ($db, $aid, $uid) {
    foreach (['planned_workouts','training_plans','athlete_profiles','engine_flags','plan_approval_queue'] as $t) { try { $db->prepare("DELETE FROM `$t` WHERE athlete_id=?")->execute([$aid]); } catch (\Throwable $e) {} }
    try { $db->prepare("DELETE FROM athletes WHERE id=?")->execute([$aid]); } catch (\Throwable $e) {}
    try { $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]); } catch (\Throwable $e) {}
};
$mixedSeg = function (?string $json): array { foreach ((json_decode((string)$json, true)['segments'] ?? []) as $s) if (($s['segment_type'] ?? '') === 'mixed_repeats') return $s; return []; };
$watchMiles = function (string $text): array { preg_match_all('/- ([\d.]+)mi /', $text, $m); return array_map('floatval', $m[1]); };

try {
    echo "\n=== Structured editor Phase 2: mixed_distance rung editor ===\n";

    check(PlanGenerator::isStructuredEditable('mixed_distance_repeats') === true, "mixed should now be structured-editable");

    // Seed a mixed_distance workout (long_to_short -> descending ladder).
    $w = PlanGenerator::composeManualWorkout($aid, 'mixed_distance_repeats', 'long_to_short', 75, $db);
    $db->prepare("INSERT INTO planned_workouts (plan_id, athlete_id, scheduled_date, workout_type, archetype_code, archetype_variant, archetype_params, structure, display_title, athlete_instructions, target_duration, push_text_only, visible_to_athlete) VALUES (?,?,CURDATE()+INTERVAL 3 DAY,?,?,?,?,?,?,?,?,1,1)")
       ->execute([$planId, $aid, $w['workout_type'], 'mixed_distance_repeats', $w['archetype_variant'], $w['archetype_params'], $w['structure'], $w['display_title'], $w['athlete_instructions'], $w['target_duration']]);
    $id = (int)$db->lastInsertId();
    $origLadder = json_decode($w['archetype_params'], true)['interval_distances'] ?? [];
    echo "\n[seed] generated ladder: " . implode('-', $origLadder) . " (title \"{$w['display_title']}\")\n";

    // data-mw pre-fill (rung editor reads structured.interval_distances).
    $apFields = json_decode($w['archetype_params'], true) ?: [];
    $rungs = array_values(array_filter(array_map('intval', (array)($apFields['interval_distances'] ?? [])), fn($m) => $m > 0));
    check(!empty($rungs) && $rungs === array_map('intval', $origLadder), "data-mw rung pre-fill missing/incorrect");
    printf("  [pre-fill] rung editor would show %d dropdowns: %s\n", count($rungs), implode(', ', $rungs));

    // ── REORDER: descending -> ascending (reverse) ──
    echo "\n[reorder] make it ascending:\n";
    $asc = array_values(array_reverse($rungs));
    $cols = PlanGenerator::composeStructuredEdit($id, ['interval_distances' => $asc], $db);
    $seg = $mixedSeg($cols['structure']);
    $structDists = array_map('intval', (array)($seg['interval_distances'] ?? []));
    printf("  structure interval_distances: %s ; variant=%s ; title=\"%s\"\n", implode('-', $structDists), $cols['archetype_variant'], $cols['display_title']);
    printf("  description: %s\n", mb_substr((string)$cols['athlete_instructions'], 0, 90));
    check($structDists === $asc, "structure ladder not in the new (ascending) order");
    check($cols['archetype_variant'] === 'short_to_long', "variant not re-detected as short_to_long");
    check(strpos((string)$cols['athlete_instructions'], implode(' - ', $asc)) !== false, "description does not show the new ladder");
    $watch = IntervalsService::generateWorkoutText(['structure'=>$cols['structure'],'archetype_params'=>$cols['archetype_params'],'athlete_instructions'=>$cols['athlete_instructions'],'archetype_code'=>'mixed_distance_repeats','push_text_only'=>0], ['archetype_code'=>'mixed_distance_repeats']);
    $wm = $watchMiles($watch);
    $ascending = true; for ($i=1;$i<count($wm);$i++) if ($wm[$i] < $wm[$i-1]) $ascending = false;
    printf("  watch Main Set miles in order: %s -> ascending: %s\n", implode(' ', $wm), $ascending && count($wm)===count($asc) ? 'yes' : 'NO');
    check($ascending && count($wm) === count($asc), "watch steps not pushed in the new order");

    // ── ADD a rung ──
    echo "\n[add rung]:\n";
    $plus = array_merge($asc, [1600]);
    $colsA = PlanGenerator::composeStructuredEdit($id, ['interval_distances' => $plus], $db);
    $segA = $mixedSeg($colsA['structure']);
    printf("  rung_count=%s ; ladder=%s\n", json_decode($colsA['archetype_params'],true)['rung_count']??'?', implode('-', array_map('intval',(array)$segA['interval_distances'])));
    check((int)(json_decode($colsA['archetype_params'],true)['rung_count']??0) === count($plus), "rung_count not updated after add");
    check(array_map('intval',(array)$segA['interval_distances']) === $plus, "ladder not updated after add");

    // ── REMOVE rungs down to 3 ──
    echo "\n[remove rungs]:\n";
    $three = [400, 800, 1200];
    $colsR = PlanGenerator::composeStructuredEdit($id, ['interval_distances' => $three], $db);
    check(array_map('intval',(array)$mixedSeg($colsR['structure'])['interval_distances']) === $three, "ladder not updated after remove");
    printf("  ladder=%s rung_count=%s\n", implode('-', $three), json_decode($colsR['archetype_params'],true)['rung_count']??'?');

    // ── SANITY: 1 rung accepted by backend (warning is client-side, never blocks) ──
    $cols1 = PlanGenerator::composeStructuredEdit($id, ['interval_distances' => [800]], $db);
    check($cols1 !== null && array_map('intval',(array)$mixedSeg($cols1['structure'])['interval_distances']) === [800], "1-rung edit should save (no hard block)");
    echo "\n[sanity] 1-rung ladder accepted by backend (no block; <2-rung warning is client-side)\n";

    // ── controller wiring: composeWorkoutEdit('structured') with interval_distances array ──
    echo "\n[controller] composeWorkoutEdit('structured') for mixed:\n";
    $before = $db->query("SELECT * FROM planned_workouts WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $r = CoachController::composeWorkoutEdit('structured', $before, ['mode'=>'structured','interval_distances'=>[300,600,1000]], $db);
    $rd = array_map('intval',(array)$mixedSeg($r['columns']['structure'] ?? '')['interval_distances']);
    printf("  ladder=%s ; push_text_only=%s\n", implode('-', $rd), var_export($r['columns']['push_text_only']??'?',true));
    check($rd === [300,600,1000] && ($r['columns']['push_text_only']??null)===0, "controller mixed structured wiring wrong");

    // ── regression: Phase 1 (tempo) + generation unchanged ──
    echo "\n[regression] Phase 1 tempo edit + generation even-or-3:\n";
    $tw = PlanGenerator::composeManualWorkout($aid, 'tempo_intervals', 'time_based', 70, $db);
    $db->prepare("INSERT INTO planned_workouts (plan_id, athlete_id, scheduled_date, workout_type, archetype_code, archetype_variant, archetype_params, structure, display_title, athlete_instructions, target_duration, visible_to_athlete) VALUES (?,?,CURDATE()+INTERVAL 5 DAY,?,?,?,?,?,?,?,?,1)")
       ->execute([$planId, $aid, $tw['workout_type'], 'tempo_intervals', $tw['archetype_variant'], $tw['archetype_params'], $tw['structure'], $tw['display_title'], $tw['athlete_instructions'], $tw['target_duration']]);
    $tid = (int)$db->lastInsertId();
    $tc = PlanGenerator::composeStructuredEdit($tid, ['rep_count'=>6,'rep_duration_minutes'=>10], $db);
    foreach (json_decode($tc['structure'],true)['segments'] as $s) if (($s['segment_type']??'')==='tempo_intervals') check((int)$s['rep_count']===6, "Phase 1 tempo edit regressed");
    $ok=true; for($i=0;$i<30;$i++){ $g=PlanGenerator::composeManualWorkout($aid,'tempo_intervals',null,60,$db); $rc=json_decode($g['archetype_params'],true)['rep_count']??0; if(!($rc===3||$rc%2===0))$ok=false; }
    printf("  Phase 1 tempo 6x ok ; generation even-or-3: %s\n", $ok?'yes':'NO'); check($ok, "generation regressed");

} finally {
    echo "\n=== Teardown ===\n";
    $cleanup();
    $resid = (int)$db->query("SELECT (SELECT COUNT(*) FROM planned_workouts WHERE athlete_id=$aid)+(SELECT COUNT(*) FROM athletes WHERE id=$aid)+(SELECT COUNT(*) FROM users WHERE id=$uid)")->fetchColumn();
    echo $resid===0 ? "0 residual rows.\n" : "RESIDUAL: $resid\n";
    if ($resid!==0) $fails[]='residual';
}

echo "\n=== Verdict ===\n";
if (empty($fails)) { echo "PASS\n\n"; exit(0); }
echo "FAIL (".count($fails)."):\n"; foreach ($fails as $f) echo "  - $f\n"; echo "\n"; exit(1);

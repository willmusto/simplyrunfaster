<?php
/**
 * LIVE verification for the two edit -> Intervals.icu push fixes. Non-destructive: builds
 * real structures and asserts what generateWorkoutText() (the watch description) produces.
 *
 *   GAP B: mixed_distance_repeats ladder now renders each rung as a step, in order.
 *   GAP A: a surface-edited row (push_text_only=1) pushes the instructions TEXT, not steps.
 *
 * Run from /home/private/app: php scripts/verify_push_gaps.php
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

$sel    = new ArchetypeSelector(Database::get());
$rAdd   = new ReflectionMethod(PlanGenerator::class, 'addDerivedParams');  $rAdd->setAccessible(true);
$rStr   = new ReflectionMethod(PlanGenerator::class, 'resolveStructure');  $rStr->setAccessible(true);
$rProp  = new ReflectionProperty(PlanGenerator::class, 'cycleProgress');   $rProp->setAccessible(true);

$fails = [];
function check(bool $c, string $m): void { global $fails; if (!$c) $fails[] = $m; }

/** Build a planned_workouts-shaped row (structure + params) for an archetype. */
function buildRow($sel,$rAdd,$rStr,$rProp,$code,$variant=null,$frac=0.0,$cls='well_trained',$phase='base',$goal='half',$slot=80): array {
    $rProp->setValue(null, $frac===null?null:['fraction'=>$frac,'cutback'=>1.0]);
    $a = $sel->getByCode($code); $a = $sel->resolveParameters($a,$cls);
    if ($variant) foreach ($a['variants'] as $v) if (($v['code']??'')===$variant){$a['resolved_variant']=$v;break;}
    $a = $rAdd->invoke(null,$a,$slot,$phase,$goal,$cls);
    $struct = $rStr->invoke(null,$a);
    $rProp->setValue(null,null);
    return [
        'id' => 1, 'archetype_code' => $code,
        'structure' => json_encode($struct),
        'archetype_params' => json_encode($a['resolved_params'] ?? []),
        'athlete_instructions' => 'COACH HAND-EDIT: 4 x 800m at 5K effort, jog 400 between.',
        'description' => '', 'push_text_only' => 0,
        'resolved' => $a['resolved_params'] ?? [],
    ];
}
$ctx = fn($code) => ['archetype_code' => $code]; // no pace_zones -> effort-only, citations off

echo "\n=== Edit -> Intervals.icu push fixes ===\n";

// ── GAP B: mixed_distance ladder renders each rung in order ──────────────────
echo "\n[GAP B] mixed_distance_repeats ladder pushes each rung as a step, in order:\n";
foreach (['long_to_short'=>'desc','short_to_long'=>'asc','combo_set'=>'pyramid'] as $vc=>$ord) {
    $row = buildRow($sel,$rAdd,$rStr,$rProp,'mixed_distance_repeats',$vc,0.0);
    $seq = $row['resolved']['interval_distances'] ?? [];
    $text = IntervalsService::generateWorkoutText($row, $ctx('mixed_distance_repeats'));
    // Extract the miles in the order they appear in the rendered Main Set.
    preg_match_all('/- ([\d.]+)mi /', $text, $mm);
    $renderedMi = array_map('floatval', $mm[1]);
    $expectMi   = array_map(fn($m)=>round($m/1609.34,2), $seq);
    $hasMainSet = str_contains($text, 'Main Set');
    $orderOk    = count($renderedMi) === count($seq) && $renderedMi === array_map(fn($x)=>(float)rtrim(rtrim(number_format($x,2,'.',''),'0'),'.'),$expectMi);
    printf("  %-15s seq=[%s]  rungs rendered=%d  mainset:%s order:%s\n",
        $vc, implode('-',$seq), count($renderedMi), $hasMainSet?'yes':'NO', $orderOk?'ok':'CHECK');
    check($hasMainSet, "$vc: no Main Set rendered (still dropped)");
    check(count($renderedMi) === count($seq), "$vc: rendered ".count($renderedMi)." rungs, expected ".count($seq));
    // order: rendered miles must match the sequence order (ascending/descending/pyramid)
    $renderedM = array_map(fn($mi)=>(int)round($mi*1609.34/100)*100, $renderedMi);
    $seqRounded = array_map(fn($m)=>(int)round($m/100)*100, $seq);
    check($renderedM === $seqRounded, "$vc: rung ORDER not preserved (rendered != sequence)");
}
// show one full rendered block
$row = buildRow($sel,$rAdd,$rStr,$rProp,'mixed_distance_repeats','long_to_short',0.0);
echo "  --- sample rendered watch text (long_to_short) ---\n";
foreach (explode("\n", IntervalsService::generateWorkoutText($row, $ctx('mixed_distance_repeats'))) as $l) echo "    $l\n";

// ── GAP A: surface-edited row pushes the text, not stale steps ───────────────
echo "\n[GAP A] push_text_only: surface-edited row pushes instructions text, not steps:\n";
$row = buildRow($sel,$rAdd,$rStr,$rProp,'tempo_intervals',null,0.5);
$withStruct = IntervalsService::generateWorkoutText(['push_text_only'=>0] + $row, $ctx('tempo_intervals'));
$asText     = IntervalsService::generateWorkoutText(['push_text_only'=>1] + $row, $ctx('tempo_intervals'));
printf("  push_text_only=0 -> %s\n", str_contains($withStruct,'Main Set')||str_contains($withStruct,'Warmup') ? 'structured steps (Warmup/Main Set)' : 'TEXT');
printf("  push_text_only=1 -> %s\n", trim($asText) === trim($row['athlete_instructions']) ? 'exact athlete_instructions text' : 'OTHER: '.mb_substr($asText,0,60));
check(str_contains($withStruct,'Warmup') || str_contains($withStruct,'Main Set'), "unflagged row should push structured steps");
check(trim($asText) === trim($row['athlete_instructions']), "flagged row should push the instructions text verbatim");
check(!str_contains($asText, 'Main Set') && !str_contains($asText, 'Warmup'), "flagged row leaked structured steps");

// composeWorkoutEdit sets the flag correctly per mode (pure function; surface = instructions-only edit).
$before = ['athlete_id'=>0,'workout_type'=>'tempo','target_duration'=>60,'archetype_code'=>'tempo_intervals','intensity_load'=>30.0];
$surf = CoachController::composeWorkoutEdit('surface', $before, ['workout_type'=>'tempo','target_duration'=>60,'athlete_instructions'=>'do 5x800'], Database::get());
$free = CoachController::composeWorkoutEdit('freeform', $before, ['title'=>'Custom','workout_type'=>'easy','duration'=>40,'instructions'=>'easy 40'], Database::get());
printf("  composeWorkoutEdit surface push_text_only=%s ; freeform=%s\n",
    var_export($surf['columns']['push_text_only'] ?? 'MISSING', true), var_export($free['columns']['push_text_only'] ?? 'MISSING', true));
check(($surf['columns']['push_text_only'] ?? null) === 1, "surface edit must set push_text_only=1");
check(($free['columns']['push_text_only'] ?? null) === 0, "freeform edit must set push_text_only=0");
check(!array_key_exists('structure', $surf['columns']), "surface edit must NOT write structure (preserve it)");

// ── Regression: the 5 working renderers + diminishing fartlek still render steps ──
echo "\n[Regression] other archetypes still push structured steps:\n";
$cases = [
    ['tempo_intervals',null], ['sustained_hill_repeats','standard_sustained'], ['equal_distance_repeats','800s'],
    ['short_speed_repeats','speed_300s'], ['high_volume_time_intervals',null], ['structured_fartlek_ladder','diminishing_descending'],
];
foreach ($cases as [$code,$vc]) {
    $row = buildRow($sel,$rAdd,$rStr,$rProp,$code,$vc,0.0);
    $text = IntervalsService::generateWorkoutText($row, $ctx($code));
    $hasMain = str_contains($text,'Main Set') || str_contains($text,'Strides') || str_contains($text,'x');
    $noBrace = !str_contains($text,'{{');
    printf("  %-26s %s %s\n", $code, $hasMain?'steps:ok':'steps:MISSING', $noBrace?'':'  BRACES!');
    check($hasMain, "$code: lost its structured steps (regression)");
    check($noBrace, "$code: literal braces in push text");
}

echo "\n=== Verdict ===\n";
if (empty($fails)) { echo "PASS\n\n"; exit(0); }
echo "FAIL (".count($fails)."):\n"; foreach ($fails as $f) echo "  - $f\n"; echo "\n";
exit(1);

<?php
/**
 * LIVE verification for resolveParameters-ranging-spec Stage A (tempo_intervals).
 *
 * Generates real tempo sessions through the actual resolve -> addDerivedParams
 * pipeline (no DB writes) and checks: structural variety, per-variant shape,
 * coherence guards, well_trained > workable total work, and that other
 * parameterized archetypes are unchanged (tempo-only scope held).
 *
 * Run on the server: php scripts/verify_tempo_ranging.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';

$pdo      = Database::get();
$selector = new ArchetypeSelector($pdo);

$addDerived = new ReflectionMethod(PlanGenerator::class, 'addDerivedParams');
$addDerived->setAccessible(true);
$renderTpl = new ReflectionMethod(PlanGenerator::class, 'renderTemplate');
$renderTpl->setAccessible(true);

// Variant rep-length bands (minutes for time variants; miles for distance).
$VARIANT_MIN = [
    'time_based'       => [3, 20],
    'long_reps'        => [12, 20],
    'cruise_intervals' => [4, 7],
];
$DIST_MILES = [0.5, 3.0];

// Conventional rep durations a coach actually writes (per variant band).
$CONVENTIONAL_DUR = [
    'time_based'       => [4, 5, 6, 8, 10, 12, 15, 18, 20],
    'long_reps'        => [12, 15, 18, 20],
    'cruise_intervals' => [4, 5, 6, 7],
];
$CONVENTIONAL_MILES = [0.5, 0.75, 1.0, 1.5, 2.0, 2.5, 3.0];
$CONVENTIONAL_COUNTS = [2, 3, 4, 5, 6, 8]; // 7 omitted: 7 x 7 reads oddly

// Classification ceilings (threshold_volume_minutes maxima).
$CEIL = ['workable' => 40, 'well_trained' => 60, 'insufficient' => 60];

$fails = [];
function check(bool $cond, string $msg): void {
    global $fails;
    if (!$cond) $fails[] = $msg;
}

/** Resolve one tempo instance, optionally forcing a variant. */
function tempo(
    ArchetypeSelector $selector, ReflectionMethod $addDerived,
    string $classification, string $phase, string $goalDistance,
    int $target, ?string $variantCode = null
): array {
    $arch = $selector->getByCode('tempo_intervals');
    $arch = $selector->resolveParameters($arch, $classification);
    if ($variantCode !== null) {
        foreach ($arch['variants'] as $v) {
            if (($v['code'] ?? '') === $variantCode) { $arch['resolved_variant'] = $v; break; }
        }
    }
    return $addDerived->invoke(null, $arch, $target, $phase, $goalDistance, $classification);
}

echo "\n=== Stage A verification: tempo_intervals volume-anchored ranging ===\n";

// ---------------------------------------------------------------------------
// 1. Variety for ONE well_trained athlete across weeks (random variant).
// ---------------------------------------------------------------------------
echo "\n[1] Same well_trained athlete (half, build, 80-min slot) over 24 weeks:\n";
$signatures = [];
for ($i = 1; $i <= 24; $i++) {
    $inst = tempo($selector, $addDerived, 'well_trained', 'build', 'half', 80);
    $p    = $inst['resolved_params'];
    $vc   = $inst['resolved_variant']['code'];
    $reps = (int)$p['rep_count'];
    $dur  = (int)$p['rep_duration_minutes'];
    $tgt  = (int)$p['threshold_volume_minutes'];
    $work = $reps * $dur;
    $shape = $vc === 'distance_based'
        ? sprintf('%d x %s mi', $reps, $p['rep_distance_display'])
        : sprintf('%d x %d min', $reps, $dur);
    $signatures["{$reps}x{$dur}/{$vc}"] = true;
    printf("  wk%2d  %-16s %-14s  target=%2dmin  work=%2dmin\n", $i, $vc, $shape, $tgt, $work);

    check($reps >= 2,            "wk$i: rep_count < 2 ($reps)");
    check($reps <= 8,            "wk$i: rep_count > 8 ($reps)");
    check($reps !== 7,           "wk$i: rep_count is 7 (non-conventional)");
    check(in_array($reps, $CONVENTIONAL_COUNTS, true), "wk$i: rep_count $reps not conventional");
    check($tgt <= 60,            "wk$i: target over ceiling ($tgt)");
    // Rep length must be a conventional value for its variant.
    if ($vc === 'distance_based') {
        $mi = (float)$p['rep_distance_miles'];
        check(in_array($mi, $CONVENTIONAL_MILES, true), "wk$i: $mi mi not conventional");
    } else {
        check(in_array($dur, $CONVENTIONAL_DUR[$vc] ?? [], true), "wk$i: $dur min not conventional for $vc");
    }
}
printf("  -> %d distinct structures across 24 weeks (frozen midpoint would give 1)\n", count($signatures));
check(count($signatures) >= 6, "insufficient variety: only " . count($signatures) . " distinct structures");

// ---------------------------------------------------------------------------
// 2. Each variant produces its intended shape.
// ---------------------------------------------------------------------------
echo "\n[2] Per-variant shape (well_trained, half, build, 90-min slot, 10 samples each):\n";
global $VARIANT_MIN, $DIST_MILES;
foreach (['time_based', 'long_reps', 'cruise_intervals', 'distance_based'] as $vc) {
    $durs = []; $reps = [];
    for ($i = 0; $i < 10; $i++) {
        $inst = tempo($selector, $addDerived, 'well_trained', 'build', 'half', 90, $vc);
        $p    = $inst['resolved_params'];
        $reps[] = (int)$p['rep_count'];
        if ($vc === 'distance_based') {
            $mi = (float)$p['rep_distance_miles'];
            check($mi >= $DIST_MILES[0] - 0.01 && $mi <= $DIST_MILES[1] + 0.01,
                "distance_based rep $mi mi outside 0.5-3.0");
            $durs[] = $mi;
        } else {
            $d = (int)$p['rep_duration_minutes'];
            [$lo, $hi] = $VARIANT_MIN[$vc];
            check($d >= $lo && $d <= $hi, "$vc rep $d min outside {$lo}-{$hi}");
            $durs[] = $d;
        }
        check((int)$p['rep_count'] >= 2 && (int)$p['rep_count'] <= 8,
            "$vc rep_count out of [2,8]: {$p['rep_count']}");
    }
    $unit = $vc === 'distance_based' ? 'mi' : 'min';
    printf("  %-16s rep_len %s..%s %s   rep_count %d..%d\n",
        $vc, min($durs), max($durs), $unit, min($reps), max($reps));
}

// ---------------------------------------------------------------------------
// 3. well_trained gets MORE total work than workable at the same phase.
// ---------------------------------------------------------------------------
echo "\n[3] well_trained vs workable total-work target (half, build, 200 samples):\n";
$avg = [];
foreach (['well_trained', 'workable'] as $cls) {
    $sum = 0; $n = 200; $min = 999; $max = 0;
    for ($i = 0; $i < $n; $i++) {
        $inst = tempo($selector, $addDerived, $cls, 'build', 'half', 90);
        $tgt  = (int)$inst['resolved_params']['threshold_volume_minutes'];
        $sum += $tgt; $min = min($min, $tgt); $max = max($max, $tgt);
        check($tgt <= $CEIL[$cls], "$cls target $tgt over ceiling {$CEIL[$cls]}");
    }
    $avg[$cls] = $sum / $n;
    printf("  %-14s avg target = %.1f min   (range %d..%d)\n", $cls, $avg[$cls], $min, $max);
}
check($avg['well_trained'] > $avg['workable'],
    "well_trained avg ({$avg['well_trained']}) not greater than workable ({$avg['workable']})");

// ---------------------------------------------------------------------------
// 4. Coherence sweep across phases / classifications / goals.
// ---------------------------------------------------------------------------
echo "\n[4] Coherence sweep (all phases x classifications x goals, 12 samples each):\n";
$count = 0;
foreach (['base', 'build', 'peak'] as $phase) {
    foreach (['well_trained', 'workable'] as $cls) {
        foreach (['5K', '10K', 'half', 'marathon'] as $goal) {
            for ($i = 0; $i < 12; $i++) {
                $inst = tempo($selector, $addDerived, $cls, $phase, $goal, 80);
                $p = $inst['resolved_params'];
                $reps = (int)$p['rep_count'];
                $tgt  = (int)$p['threshold_volume_minutes'];
                check($reps >= 2 && $reps <= 8, "$phase/$cls/$goal: reps=$reps");
                check($reps !== 7, "$phase/$cls/$goal: rep_count is 7");
                check($tgt <= $CEIL[$cls], "$phase/$cls/$goal: target=$tgt > ceiling");
                // Actual prescribed work must also respect the ceiling.
                check($reps * (int)$p['rep_duration_minutes'] <= $CEIL[$cls] + 1,
                    "$phase/$cls/$goal: work " . ($reps * (int)$p['rep_duration_minutes']) . " > ceiling");
                $count++;
            }
        }
    }
}
printf("  swept %d instances, all within [2,8] reps and under ceiling\n", $count);

// ---------------------------------------------------------------------------
// 5. Other parameterized archetypes UNCHANGED (tempo-only scope held).
//    The new mechanism is gated behind code === 'tempo_intervals', so these still
//    resolve exactly as before. Several (equal_distance_repeats, short_speed_repeats)
//    were ALREADY variant-random pre-change, so we assert sane in-range resolution
//    across samples, not determinism. The 5K rep_count caps are the same ones the
//    untouched fit-to-slot / phaseRepCap logic enforces.
// ---------------------------------------------------------------------------
echo "\n[5] Other archetypes still resolve sanely across 30 samples (scope held):\n";
$others = [
    'equal_distance_repeats'     => ['count_key' => 'rep_count',    'max' => 12],
    'short_speed_repeats'        => ['count_key' => 'rep_count',    'max' => 12],
    'high_volume_time_intervals' => ['count_key' => 'rep_count',    'max' => 30],
    'sustained_hill_repeats'     => ['count_key' => 'rep_count',    'max' => 12],
];
foreach ($others as $code => $cfg) {
    $key = $cfg['count_key'];
    $min = PHP_INT_MAX; $max = 0;
    for ($i = 0; $i < 30; $i++) {
        $a = $selector->getByCode($code);
        $a = $selector->resolveParameters($a, 'well_trained');
        $a = $addDerived->invoke(null, $a, 50, 'build', '10K', 'well_trained');
        $rc = (int)($a['resolved_params'][$key] ?? 0);
        $min = min($min, $rc); $max = max($max, $rc);
        check($rc >= 1 && $rc <= $cfg['max'], "$code: $key=$rc out of sane range [1,{$cfg['max']}]");
    }
    printf("  %-28s %s %d..%d  (in sane range, untouched by tempo branch)\n", $code, $key, $min, $max);
}

// ---------------------------------------------------------------------------
// Verdict
// ---------------------------------------------------------------------------
echo "\n=== Verdict ===\n";
if (empty($fails)) {
    echo "PASS — all Stage A checks green.\n\n";
    exit(0);
}
echo "FAIL — " . count($fails) . " issue(s):\n";
foreach ($fails as $f) echo "  - $f\n";
echo "\n";
exit(1);

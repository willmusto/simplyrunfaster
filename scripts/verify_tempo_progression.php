<?php
/**
 * LIVE verification for Stage B: across-cycle tempo progression. Non-destructive (no
 * plan is written). It replays the engine's exact weekly-volume curve, calls the REAL
 * PlanGenerator::tempoProgressContext() per week, sets the same static the generators
 * set, and resolves REAL tempo instances via addDerivedParams -> distributeTempoIntervals.
 * So it exercises the actual Stage B code paths against real archetype data.
 *
 * Run from /home/private/app: php scripts/verify_tempo_progression.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Timezone.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';

$sel = new ArchetypeSelector(Database::get());
$add  = new ReflectionMethod(PlanGenerator::class, 'addDerivedParams');      $add->setAccessible(true);
$ctx  = new ReflectionMethod(PlanGenerator::class, 'tempoProgressContext');  $ctx->setAccessible(true);
$prop = new ReflectionProperty(PlanGenerator::class, 'tempoCycleProgress');  $prop->setAccessible(true);

$fails = [];
function check(bool $c, string $m): void { global $fails; if (!$c) $fails[] = $m; }
$VALID = fn(int $n) => $n === 3 || $n % 2 === 0;

/** Average tempo total-work target over N samples for a (classification, progress) context. */
function tempoTarget($sel, $add, $prop, string $cls, string $phase, string $goal, int $slot, ?array $progress, int $n = 60): array {
    $prop->setValue(null, $progress);
    $sum = 0; $sample = ''; $allValid = true;
    for ($i = 0; $i < $n; $i++) {
        $a = $sel->getByCode('tempo_intervals');
        $a = $sel->resolveParameters($a, $cls);
        $a = $add->invoke(null, $a, $slot, $phase, $goal, $cls);
        $p = $a['resolved_params'];
        $sum += (int)$p['threshold_volume_minutes'];
        if (!($p['rep_count'] === 3 || $p['rep_count'] % 2 === 0)) $allValid = false;
        if ($i === 0) $sample = (int)$p['rep_count'] . 'x' . (int)$p['rep_duration_minutes'];
    }
    $prop->setValue(null, null);
    return ['avg' => $sum / $n, 'sample' => $sample, 'valid' => $allValid];
}

echo "\n=== Stage B: across-cycle tempo progression (live, non-destructive) ===\n";

// ---------------------------------------------------------------------------
// Replay the DEVELOPMENT-plan weekly volume curve EXACTLY (mirrors
// generateDevelopmentPlan: weeklyMins = cutback ? buildBase*0.80 : min(buildBase*1.08, peak)).
// ---------------------------------------------------------------------------
$cls = 'well_trained'; $goal = '10K'; $slot = 60;
$currentMins = 180; $peakCeiling = (int)round($currentMins * 1.4); // 252
$floor = $currentMins; $buildBase = $currentMins;

echo "\n[DEV cycle] well_trained / 10K  (volume floor {$floor}, peak {$peakCeiling})\n";
printf("  %-4s %-3s %-7s %-7s %-5s %-7s %-11s %-9s %s\n",
    'wk','cb','volume','trend','p','cutbck','tempoTarget','sample','evenOr3');
$targets = []; $baseT = null; $peakT = null; $cutbackRows = [];
for ($week = 1; $week <= 12; $week++) {
    $isCutback = ($week > 1 && $week % 4 === 0);
    if ($isCutback) { $weeklyMins = max(30, (int)round($buildBase * 0.80)); }
    else            { $weeklyMins = min((int)round($buildBase * 1.08), $peakCeiling); $buildBase = $weeklyMins; }

    $progress = $ctx->invoke(null, $isCutback, $buildBase, $weeklyMins, $floor, $peakCeiling);
    $t = tempoTarget($sel, $add, $prop, $cls, 'base', $goal, $slot, $progress);
    $targets[$week] = ['vol' => $weeklyMins, 'trend' => $buildBase, 'p' => $progress['fraction'],
                       'cb' => $progress['cutback'], 'tt' => $t['avg'], 'isCb' => $isCutback];
    printf("  %-4d %-3s %-7d %-7d %-5.2f %-7.3f %-11.1f %-9s %s\n",
        $week, $isCutback ? 'Y' : '', $weeklyMins, $buildBase, $progress['fraction'],
        $progress['cutback'], $t['avg'], $t['sample'], $t['valid'] ? 'ok' : 'BAD');
    check($t['valid'], "week $week produced a non-even-or-3 rep count");
    if ($week === 1)  $baseT = $t['avg'];
    if (!$isCutback)  $peakT = $t['avg'];
    if ($isCutback)   $cutbackRows[] = $week;
}

// Progression: a late (peak-volume) tempo out-totals an early (base-volume) one.
check($peakT > $baseT + 3, sprintf("no progression: base %.1f vs peak %.1f", $baseT, $peakT));
printf("  -> base-week target %.1f  <  peak-week target %.1f  (progression: %s)\n",
    $baseT, $peakT, $peakT > $baseT + 3 ? 'YES' : 'NO');

// Tracking, no divergence: non-cutback target rises monotonically with the volume trend.
$prevTrend = -1; $prevTT = -1; $mono = true;
foreach ($targets as $w => $r) {
    if ($r['isCb']) continue;
    if ($prevTrend >= 0 && $r['trend'] > $prevTrend && $r['tt'] < $prevTT - 1.5) $mono = false;
    $prevTrend = $r['trend']; $prevTT = $r['tt'];
}
check($mono, "tempo target diverged from the volume trend (non-monotonic on build weeks)");
printf("  -> tempo target tracks the volume trend on build weeks (monotonic up): %s\n", $mono ? 'YES' : 'NO');

// Cutback subtlety: tempo dips ~half the % that volume dips vs the surrounding trend.
foreach ($cutbackRows as $w) {
    $prev = $targets[$w - 1]; $cur = $targets[$w];
    $volDropPct  = ($prev['vol'] - $cur['vol']) / $prev['vol'] * 100;
    $tempoDropPct = ($prev['tt'] - $cur['tt']) / $prev['tt'] * 100;
    printf("  -> cutback wk%d: volume -%.0f%%, tempo -%.0f%% (target ~half of volume drop)\n",
        $w, $volDropPct, $tempoDropPct);
    // tempo drop should be positive but clearly smaller than the volume drop.
    check($tempoDropPct > 0 && $tempoDropPct < $volDropPct * 0.85,
        "cutback wk$w tempo drop ({$tempoDropPct}%) not subtly less than volume drop ({$volDropPct}%)");
}

// ---------------------------------------------------------------------------
// Classification separation holds across the cycle (well_trained > workable each week).
// ---------------------------------------------------------------------------
echo "\n[Classification] well_trained vs workable target at the SAME cycle position:\n";
foreach ([1, 6, 11] as $week) {
    // recompute the dev progress at this week for each classification (same volume curve fractions)
    $p = $targets[$week]['p']; $cb = $targets[$week]['cb'];
    $prog = ['fraction' => $p, 'cutback' => $cb];
    $wt = tempoTarget($sel, $add, $prop, 'well_trained', 'base', $goal, $slot, $prog);
    $wk = tempoTarget($sel, $add, $prop, 'workable',     'base', $goal, $slot, $prog);
    printf("  wk%-2d (p=%.2f)  well_trained %.1f   workable %.1f\n", $week, $p, $wt['avg'], $wk['avg']);
    check($wt['avg'] > $wk['avg'], "wk$week: well_trained ({$wt['avg']}) not > workable ({$wk['avg']})");
}

// ---------------------------------------------------------------------------
// No cycle context -> Stage A (full-band, no progression): early and late draw the same.
// ---------------------------------------------------------------------------
echo "\n[Stage A fallback] null cycle context = no progression (ad-hoc/preview/maintenance):\n";
$a1 = tempoTarget($sel, $add, $prop, 'well_trained', 'base', $goal, $slot, null, 300);
$a2 = tempoTarget($sel, $add, $prop, 'well_trained', 'base', $goal, $slot, null, 300);
printf("  null-context avg targets: %.1f and %.1f (broad band, no week-position bias)\n", $a1['avg'], $a2['avg']);
check(abs($a1['avg'] - $a2['avg']) < 4, "null-context targets unstable (should be the same broad distribution)");

echo "\n=== Verdict ===\n";
if (empty($fails)) { echo "PASS\n\n"; exit(0); }
echo "FAIL (" . count($fails) . "):\n"; foreach ($fails as $f) echo "  - $f\n"; echo "\n";
exit(1);

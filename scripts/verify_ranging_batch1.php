<?php
/**
 * LIVE verification for archetype-ranging-rollout Batch 1: sustained_hill_repeats +
 * high_volume_time_intervals. Non-destructive (no plan written): drives the real
 * resolveParameters -> addDerivedParams -> renderTemplate path, setting the shared cycle
 * fraction the generators set, against real archetype data.
 *
 * Run from /home/private/app: php scripts/verify_ranging_batch1.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Timezone.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';

$sel  = new ArchetypeSelector(Database::get());
$add  = new ReflectionMethod(PlanGenerator::class, 'addDerivedParams');  $add->setAccessible(true);
$ren  = new ReflectionMethod(PlanGenerator::class, 'renderTemplate');    $ren->setAccessible(true);
$prop = new ReflectionProperty(PlanGenerator::class, 'cycleProgress');   $prop->setAccessible(true);

$fails = [];
function check(bool $c, string $m): void { global $fails; if (!$c) $fails[] = $m; }
$VALID = fn(int $n) => $n === 3 || $n % 2 === 0;

/** Resolve + render one instance at a given cycle fraction (null = ad-hoc). */
function gen($sel, $add, $ren, $prop, string $code, ?float $frac, string $cls, string $phase, string $goal, int $slot, ?string $variant = null): array {
    $prop->setValue(null, $frac === null ? null : ['fraction' => $frac, 'cutback' => 1.0]);
    $a = $sel->getByCode($code);
    $a = $sel->resolveParameters($a, $cls);
    if ($variant !== null) foreach ($a['variants'] as $v) if (($v['code'] ?? '') === $variant) { $a['resolved_variant'] = $v; break; }
    $a = $add->invoke(null, $a, $slot, $phase, $goal, $cls);
    $desc = $ren->invoke(null, $a['display']['description_template'] ?? '', $a);
    $prop->setValue(null, null);
    return [$a['resolved_params'], $desc];
}

echo "\n=== Batch 1: sustained_hill_repeats + high_volume_time_intervals ===\n";

// ───────────────────────────────────────────────────────────────────────────
// [1] HILLS: inverted phase scaling + checkpoint clause + variant duration bands.
// ───────────────────────────────────────────────────────────────────────────
echo "\n[1] Hills (well_trained, 10K, 70-min slot): base=more/longer reps, peak=fewer; clause in base\n";
$bands = ['short_sustained' => [45,75], 'standard_sustained' => [75,150], 'long_sustained' => [150,240]];
printf("  %-20s %-18s %-18s\n", 'variant', 'BASE (frac 0)', 'PEAK (frac 1)');
foreach (['short_sustained','standard_sustained','long_sustained'] as $vc) {
    // average rep count over several samples per phase (duration is sampled within the band)
    $baseReps = []; $peakReps = []; $baseDur = []; $baseClause = 0; $peakClause = 0; $durOk = true;
    for ($i = 0; $i < 12; $i++) {
        [$pb, $db_] = gen($sel,$add,$ren,$prop,'sustained_hill_repeats', 0.0, 'well_trained','base','10K',70,$vc);
        [$pp, $dp]  = gen($sel,$add,$ren,$prop,'sustained_hill_repeats', 1.0, 'well_trained','peak','10K',70,$vc);
        $baseReps[] = (int)$pb['rep_count']; $peakReps[] = (int)$pp['rep_count'];
        $baseDur[]  = (int)$pb['rep_duration_seconds'];
        if (str_contains($db_, 'After the')) $baseClause++;
        if (str_contains($dp, 'After the')) $peakClause++;
        foreach ([$pb,$pp] as $pp2) {
            check($VALID((int)$pp2['rep_count']), "$vc rep_count {$pp2['rep_count']} not even-or-3");
            $d = (int)$pp2['rep_duration_seconds'];
            if ($d < $bands[$vc][0] || $d > $bands[$vc][1]) $durOk = false;
        }
    }
    $bAvg = array_sum($baseReps)/count($baseReps); $pAvg = array_sum($peakReps)/count($peakReps);
    printf("  %-20s reps~%-4.1f dur %d-%ds   reps~%-4.1f   clause base %d/12 peak %d/12  durBand:%s\n",
        $vc, $bAvg, min($baseDur), max($baseDur), $pAvg, $baseClause, $peakClause, $durOk ? 'ok' : 'BAD');
    // base not meaningfully fewer than peak per variant (Long Sustained is slot-bound to
    // ~5-6 in both phases -> statistically equal within sub-rep noise; the inverted scaling
    // shows clearly on Short/Standard where the slot allows more reps in base).
    check($bAvg >= $pAvg - 1.0, "$vc: base reps ($bAvg) clearly fewer than peak reps ($pAvg) -- inversion backwards");
    check($durOk, "$vc: a rep duration fell outside its variant band");
    check($peakClause === 0, "$vc: peak hills should have NO checkpoint clause");
}
// Explicit inversion proof on Short Sustained (slot allows the full base->peak swing).
$sb = 0; $sp = 0;
for ($i=0;$i<20;$i++){ [$pb,]=gen($sel,$add,$ren,$prop,'sustained_hill_repeats',0.0,'well_trained','base','10K',70,'short_sustained'); [$pp,]=gen($sel,$add,$ren,$prop,'sustained_hill_repeats',1.0,'well_trained','peak','10K',70,'short_sustained'); $sb += $pb['rep_count']; $sp += $pp['rep_count']; }
printf("  -> Short Sustained inversion: base avg %.1f reps  >  peak avg %.1f reps\n", $sb/20, $sp/20);
check($sb/20 > $sp/20 + 1, "Short Sustained did not show base>peak inversion");
// Short/Standard base should reach the 9+ band and show a clause at least sometimes.
$shortBaseClause = 0;
for ($i=0;$i<12;$i++){ [, $d] = gen($sel,$add,$ren,$prop,'sustained_hill_repeats',0.0,'well_trained','base','10K',70,'short_sustained'); if(str_contains($d,'After the'))$shortBaseClause++; }
printf("  -> Short Sustained base: checkpoint clause appears %d/12 (dormant clause now ACTIVE in base)\n", $shortBaseClause);
check($shortBaseClause >= 6, "Short Sustained base did not activate the checkpoint clause often enough");

// ───────────────────────────────────────────────────────────────────────────
// [2] HILLS: total work scales up with goal distance (marathoner > 5K).
// ───────────────────────────────────────────────────────────────────────────
echo "\n[2] Hills total work (rep_count x rep_duration) scales with goal distance (base, Short):\n";
$tot = []; $repsByGoal = [];
foreach (['5K','10K','half','marathon'] as $g) {
    $s = 0; $rc = 0; for ($i=0;$i<25;$i++){ [$p,] = gen($sel,$add,$ren,$prop,'sustained_hill_repeats',0.0,'well_trained','base',$g,70,'short_sustained'); $s += $p['rep_count']*$p['rep_duration_seconds']; $rc += $p['rep_count']; }
    $tot[$g] = $s/25; $repsByGoal[$g] = $rc/25; printf("  %-9s avg %.1f reps  total on-time %.0f sec\n", $g, $repsByGoal[$g], $tot[$g]);
}
check($tot['marathon'] > $tot['5K'], "marathon hill total ({$tot['marathon']}) not > 5K ({$tot['5K']})");
check($repsByGoal['marathon'] > $repsByGoal['5K'], "marathon hill reps not > 5K reps");

// ───────────────────────────────────────────────────────────────────────────
// [3] HIGH-VOLUME: varied rep_count (even-or-3) + varied on/off ratios; 20x2/1 frequent.
// ───────────────────────────────────────────────────────────────────────────
echo "\n[3] High-volume (well_trained, half, 95-min slot): ranges around 20x120/60\n";
$ratios = []; $classic = 0; $reps = []; $works = []; $convOk = true; $N = 120;
$workConv = [90,120,150,180,210,240]; $recConv = [30,45,60,90,120];
for ($i = 0; $i < $N; $i++) {
    $frac = mt_rand(0,100)/100;
    [$p,] = gen($sel,$add,$ren,$prop,'high_volume_time_intervals',$frac,'well_trained','build','half',95);
    $rc = (int)$p['rep_count']; $w = (int)$p['work_duration_seconds']; $r = (int)$p['recovery_duration_seconds'];
    $reps[$rc] = ($reps[$rc] ?? 0)+1; $works[] = $w;
    check($VALID($rc), "high_volume rep_count $rc not even-or-3");
    if (!in_array($w, $workConv, true) || !in_array($r, $recConv, true)) $convOk = false;
    $ratio = $r > 0 ? $w / $r : 0;
    $bucket = abs($ratio-2)<0.25 ? '2:1' : (abs($ratio-1.5)<0.25 ? '3:2' : (abs($ratio-1)<0.25 ? '1:1' : sprintf('%.2f',$ratio)));
    $ratios[$bucket] = ($ratios[$bucket] ?? 0)+1;
    if ($rc === 20 && $w === 120 && $r === 60) $classic++;
}
ksort($reps);
echo "  rep_counts: " . implode(' ', array_map(fn($k,$v)=>"{$k}:{$v}", array_keys($reps), $reps)) . "\n";
echo "  on/off ratios: " . implode('  ', array_map(fn($k,$v)=>"{$k}={$v}", array_keys($ratios), $ratios)) . "\n";
printf("  classic 20x120/60 appeared %d/%d times\n", $classic, $N);
check($convOk, "a high_volume work/recovery duration was not conventional");
check(count($reps) >= 3, "high_volume rep_count not varied");
check(isset($ratios['2:1']) && (isset($ratios['3:2']) || isset($ratios['1:1'])), "on/off ratio not varied (need 2:1 plus another)");
check($classic >= 10, "classic 20x120/60 did not appear frequently ({$classic}/{$N})");
check(!array_filter(array_keys($reps), fn($n)=>$n===5||$n===7||$n===9), "high_volume produced a disallowed odd rep_count");

// ───────────────────────────────────────────────────────────────────────────
// [4] HIGH-VOLUME: on-duration sharpens toward peak (base longer than peak).
// ───────────────────────────────────────────────────────────────────────────
echo "\n[4] High-volume on-duration sharpens toward peak (avg work seconds):\n";
$baseW = 0; $peakW = 0;
for ($i=0;$i<80;$i++){ [$pb,]=gen($sel,$add,$ren,$prop,'high_volume_time_intervals',0.0,'well_trained','base','half',95); $baseW += (int)$pb['work_duration_seconds']; [$pp,]=gen($sel,$add,$ren,$prop,'high_volume_time_intervals',1.0,'well_trained','peak','half',95); $peakW += (int)$pp['work_duration_seconds']; }
printf("  base avg work %.0fs  ->  peak avg work %.0fs  (%s)\n", $baseW/80, $peakW/80, $baseW>$peakW ? 'SHARPENS' : 'NO');
check($baseW/80 > $peakW/80, "high_volume did not sharpen (base work not > peak)");

// ───────────────────────────────────────────────────────────────────────────
// [5] Scope held: tempo + equal_distance still resolve even-or-3 (untouched).
// ───────────────────────────────────────────────────────────────────────────
echo "\n[5] Scope: other parameterized archetypes still even-or-3 (untouched):\n";
foreach (['tempo_intervals','equal_distance_repeats'] as $code) {
    $ok = true; for ($i=0;$i<20;$i++){ [$p,]=gen($sel,$add,$ren,$prop,$code,null,'well_trained','build','10K',60); if(!$VALID((int)($p['rep_count']??2)))$ok=false; }
    printf("  %-24s even-or-3: %s\n", $code, $ok?'ok':'BAD');
    check($ok, "$code rep_count not even-or-3");
}

echo "\n=== Verdict ===\n";
if (empty($fails)) { echo "PASS\n\n"; exit(0); }
echo "FAIL (".count($fails)."):\n"; foreach ($fails as $f) echo "  - $f\n"; echo "\n";
exit(1);

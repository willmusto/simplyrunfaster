<?php
/**
 * LIVE verification for archetype-ranging-rollout Batch 2: equal_distance_repeats +
 * short_speed_repeats. Non-destructive: drives the real resolveParameters ->
 * addDerivedParams -> renderTemplate path with the shared cycle fraction set.
 *
 * Run from /home/private/app: php scripts/verify_ranging_batch2.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Timezone.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';

$sel  = new ArchetypeSelector(Database::get());
$add  = new ReflectionMethod(PlanGenerator::class, 'addDerivedParams'); $add->setAccessible(true);
$ren  = new ReflectionMethod(PlanGenerator::class, 'renderTemplate');   $ren->setAccessible(true);
$prop = new ReflectionProperty(PlanGenerator::class, 'cycleProgress');  $prop->setAccessible(true);

$fails = [];
function check(bool $c, string $m): void { global $fails; if (!$c) $fails[] = $m; }
$VALID = fn(int $n) => $n === 3 || $n % 2 === 0;

/** Resolve one instance; returns [params, desc, variantDist]. variant null = engine picks. */
function gen($sel,$add,$ren,$prop,$code,?float $frac,$cls,$phase,$goal,$slot,?string $variant=null): array {
    $prop->setValue(null, $frac===null?null:['fraction'=>$frac,'cutback'=>1.0]);
    $a=$sel->getByCode($code); $a=$sel->resolveParameters($a,$cls);
    if($variant!==null) foreach($a['variants'] as $v) if(($v['code']??'')===$variant){$a['resolved_variant']=$v;break;}
    $a=$add->invoke(null,$a,$slot,$phase,$goal,$cls);
    $prop->setValue(null,null);
    return [$a['resolved_params'], $ren->invoke(null,$a['display']['description_template']??'',$a), (int)($a['resolved_variant']['rep_distance_meters']??0)];
}
$TRACK_EQ = [600,800,1000,1200]; $TRACK_SP = [60,80,100,150,200,300,400,500,600];

echo "\n=== Batch 2: equal_distance_repeats + short_speed_repeats ===\n";

// [1] FREQUENCY bias: long-rep variants out-number short-rep (weighted variant pick).
echo "\n[1] equal_distance variant frequency (400 generations, engine-picked variant):\n";
$dist = [];
for ($i=0;$i<400;$i++){ [, , $d] = gen($sel,$add,$ren,$prop,'equal_distance_repeats',0.5,'well_trained','build','10K',70); $dist[$d]=($dist[$d]??0)+1; }
ksort($dist);
echo "  ".implode('  ', array_map(fn($k,$v)=>"{$k}m:{$v}", array_keys($dist), $dist))."\n";
$short = ($dist[600]??0)+($dist[800]??0); $long = ($dist[1000]??0)+($dist[1200]??0);
printf("  short-rep (600+800): %d   long-rep (1000+1200): %d  -> long %s short\n", $short, $long, $long>$short?'>':'<=');
check($long > $short, "frequency bias failed: long-rep ($long) not > short-rep ($short)");

// [2] DISTANCE biases volume: short rep = smaller total + MORE reps; long rep = larger + fewer.
echo "\n[2] equal_distance volume by rep distance (well_trained, base, marathon, 85-min slot):\n";
$byVar = [];
foreach (['600s'=>600,'800s'=>800,'1000s'=>1000,'1200s'=>1200] as $vc=>$dm) {
    $tot=0;$rc=0;$ok=true; for($i=0;$i<25;$i++){ [$p,]=gen($sel,$add,$ren,$prop,'equal_distance_repeats',0.0,'well_trained','base','marathon',85,$vc); $tot+=$p['rep_count']*$p['rep_distance_meters']; $rc+=$p['rep_count']; if(!($p['rep_count']===3||$p['rep_count']%2===0))$ok=false; if(!in_array($p['rep_distance_meters'],$TRACK_EQ,true))$ok=false; }
    $byVar[$dm]=['tot'=>$tot/25,'rc'=>$rc/25]; printf("  %-7s avg %.1f reps x %dm = %.0fm total  evenOr3+track:%s\n",$vc,$rc/25,$dm,$tot/25,$ok?'ok':'BAD');
    check($ok,"$vc produced non-even-or-3 or off-track distance");
}
check($byVar[600]['tot'] < $byVar[1200]['tot'], "short-rep total not < long-rep total");
check($byVar[600]['rc']  > $byVar[1200]['rc'],  "short-rep count not > long-rep count");
printf("  -> 600m total %.0fm < 1200m total %.0fm; 600m %.1f reps > 1200m %.1f reps\n",
    $byVar[600]['tot'],$byVar[1200]['tot'],$byVar[600]['rc'],$byVar[1200]['rc']);

// [3] SHARPEN toward peak + GOAL scaling (force 800m).
echo "\n[3] equal_distance sharpen + goal scaling (800m):\n";
$baseT=0;$peakT=0; for($i=0;$i<25;$i++){ [$pb,]=gen($sel,$add,$ren,$prop,'equal_distance_repeats',0.0,'well_trained','base','10K',85,'800s'); [$pp,]=gen($sel,$add,$ren,$prop,'equal_distance_repeats',1.0,'well_trained','peak','10K',85,'800s'); $baseT+=$pb['rep_count']; $peakT+=$pp['rep_count']; }
printf("  base avg %.1f reps  >  peak avg %.1f reps (sharpen)\n",$baseT/25,$peakT/25);
check($baseT/25 >= $peakT/25, "did not sharpen (base reps < peak reps)");
$m=0;$f=0; for($i=0;$i<25;$i++){ [$pm,]=gen($sel,$add,$ren,$prop,'equal_distance_repeats',0.0,'well_trained','base','marathon',85,'800s'); [$pf,]=gen($sel,$add,$ren,$prop,'equal_distance_repeats',0.0,'well_trained','base','5K',85,'800s'); $m+=$pm['rep_count']*$pm['rep_distance_meters']; $f+=$pf['rep_count']*$pf['rep_distance_meters']; }
printf("  marathon total %.0fm  >  5K total %.0fm (goal scaling)\n",$m/25,$f/25);
check($m/25 > $f/25, "goal scaling failed: marathon total not > 5K");

// [4] CHECKPOINT clause fires when a session reaches 9+ reps (high-volume short-rep base).
echo "\n[4] equal_distance checkpoint clause (600m, marathon, base, 90-min slot):\n";
$clause=0;$reps=[]; for($i=0;$i<20;$i++){ [$p,$d]=gen($sel,$add,$ren,$prop,'equal_distance_repeats',0.0,'well_trained','base','marathon',90,'600s'); $reps[$p['rep_count']]=true; if(str_contains($d,'After the'))$clause++; }
printf("  rep_counts seen: %s ; checkpoint clause %d/20\n", implode(',',array_keys($reps)), $clause);
check($clause >= 1, "checkpoint clause never fired for high-volume base equal_distance (form check/reset)");

// [5] short_speed: LOW total by design, varied distances, even-or-3, sharpens.
echo "\n[5] short_speed (well_trained, base, 5K, 60-min slot) -- low total, varied:\n";
$spTot=[]; foreach (['economy_200s'=>200,'speed_300s'=>300,'speed_endurance_400s'=>400,'broken_speed_set'=>150,'repetition_session'=>600] as $vc=>$dm) {
    $tot=0;$rc=0;$ok=true; for($i=0;$i<20;$i++){ [$p,]=gen($sel,$add,$ren,$prop,'short_speed_repeats',0.0,'well_trained','base','5K',60,$vc); $tot+=$p['rep_count']*$p['rep_distance_meters']; $rc+=$p['rep_count']; if(!($p['rep_count']===3||$p['rep_count']%2===0))$ok=false; if(!in_array($p['rep_distance_meters'],$TRACK_SP,true))$ok=false; }
    $spTot[$dm]=$tot/20; printf("  %-22s avg %.1f reps x %dm = %.0fm total  evenOr3+track:%s\n",$vc,$rc/20,$dm,$tot/20,$ok?'ok':'BAD');
    check($ok,"$vc non-even-or-3 or off-track");
    check($tot/20 <= 4000, "$vc total exceeds the low-volume design (>4000m)");
}
check(max($spTot) < $byVar[1200]['tot'], "short_speed max total not below equal_distance long-rep total");
printf("  -> short_speed max total %.0fm  <  equal_distance 1200m total %.0fm (speed stays low)\n", max($spTot), $byVar[1200]['tot']);

// [6] Scope held: other parameterized archetypes unchanged (even-or-3).
echo "\n[6] Scope: tempo / hills / high_volume still even-or-3 (untouched):\n";
foreach (['tempo_intervals','sustained_hill_repeats','high_volume_time_intervals'] as $code) {
    $ok=true; for($i=0;$i<20;$i++){ [$p,]=gen($sel,$add,$ren,$prop,$code,0.3,'well_trained','build','10K',70); if(!$VALID((int)($p['rep_count']??2)))$ok=false; }
    printf("  %-26s even-or-3: %s\n",$code,$ok?'ok':'BAD'); check($ok,"$code not even-or-3");
}

echo "\n=== Verdict ===\n";
if (empty($fails)) { echo "PASS\n\n"; exit(0); }
echo "FAIL (".count($fails)."):\n"; foreach ($fails as $f) echo "  - $f\n"; echo "\n";
exit(1);

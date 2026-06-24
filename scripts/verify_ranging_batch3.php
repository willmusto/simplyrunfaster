<?php
/**
 * LIVE verification for archetype-ranging-rollout Batch 3 (final): mixed_distance_repeats +
 * structured_fartlek_ladder. Non-destructive: drives the real addDerivedParams ->
 * renderTemplate path with the shared cycle fraction set.
 *
 * Run from /home/private/app: php scripts/verify_ranging_batch3.php
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
$TRACK = [200,300,400,600,800,1000,1200,1600];

function gen($sel,$add,$ren,$prop,$code,?float $frac,$cls,$phase,$goal,$slot,?string $variant=null): array {
    $prop->setValue(null, $frac===null?null:['fraction'=>$frac,'cutback'=>1.0]);
    $a=$sel->getByCode($code); $a=$sel->resolveParameters($a,$cls);
    if($variant!==null) foreach($a['variants'] as $v) if(($v['code']??'')===$variant){$a['resolved_variant']=$v;break;}
    $a=$add->invoke(null,$a,$slot,$phase,$goal,$cls);
    $prop->setValue(null,null);
    $desc=$ren->invoke(null,$a['display']['description_template']??'',$a);
    $title=$ren->invoke(null,$a['display']['title_template']??'',$a);
    return [$a['resolved_params'], $desc, $title, $a['resolved_variant']['code']??''];
}
function isAsc(array $s){ for($i=1;$i<count($s);$i++) if($s[$i]<=$s[$i-1]) return false; return true; }
function isDesc(array $s){ for($i=1;$i<count($s);$i++) if($s[$i]>=$s[$i-1]) return false; return true; }
function isPyramid(array $s){ $m=max($s); $pi=array_search($m,$s); if($pi===0||$pi===count($s)-1) return false;
    for($i=1;$i<=$pi;$i++) if($s[$i]<=$s[$i-1]) return false; for($i=$pi+1;$i<count($s);$i++) if($s[$i]>=$s[$i-1]) return false; return true; }

echo "\n=== Batch 3: mixed_distance_repeats + structured_fartlek_ladder ===\n";

// [1] mixed_distance: coherent ladder per variant; track-standard; 3-9 rungs.
echo "\n[1] mixed_distance ladder coherence per variant (well_trained, base, half, 75-min slot):\n";
$orderOf = ['long_to_short'=>'desc','strength_speed'=>'desc','short_to_long'=>'asc','speed_strength'=>'asc','combo_set'=>'pyramid'];
foreach ($orderOf as $vc=>$ord) {
    $okShape=true;$okTrack=true;$okCount=true;$sample='';
    for($i=0;$i<25;$i++){
        [$p,, ]=gen($sel,$add,$ren,$prop,'mixed_distance_repeats',0.0,'well_trained','base','half',75,$vc);
        $seq=$p['interval_distances']??[]; if($i===0)$sample=implode('-',$seq);
        $coh = $ord==='asc'?isAsc($seq):($ord==='desc'?isDesc($seq):isPyramid($seq));
        if(!$coh)$okShape=false;
        foreach($seq as $d) if(!in_array($d,$TRACK,true))$okTrack=false;
        $rc=count($seq); if($rc<3||$rc>9)$okCount=false;
    }
    printf("  %-15s %-8s rungs sample[%s]  shape:%s track:%s count3-9:%s\n",$vc,$ord,$sample,$okShape?'ok':'BAD',$okTrack?'ok':'BAD',$okCount?'ok':'BAD');
    check($okShape,"$vc incoherent sequence"); check($okTrack,"$vc off-track distance"); check($okCount,"$vc rung count out of 3-9");
}

// [2] mixed rung count scales (base>peak) + goal scaling (marathon>5K total).
echo "\n[2] mixed rung count by phase + total by goal (long_to_short):\n";
$br=0;$pr=0; for($i=0;$i<30;$i++){ [$pb,]=gen($sel,$add,$ren,$prop,'mixed_distance_repeats',0.0,'well_trained','base','half',80,'long_to_short'); [$pp,]=gen($sel,$add,$ren,$prop,'mixed_distance_repeats',1.0,'well_trained','peak','half',80,'long_to_short'); $br+=$pb['rung_count']; $pr+=$pp['rung_count']; }
printf("  base avg %.1f rungs  >=  peak avg %.1f rungs\n",$br/30,$pr/30); check($br/30 >= $pr/30,"mixed base rungs < peak rungs");
$mt=0;$ft=0; for($i=0;$i<30;$i++){ [$pm,]=gen($sel,$add,$ren,$prop,'mixed_distance_repeats',0.0,'well_trained','base','marathon',80,'long_to_short'); [$pf,]=gen($sel,$add,$ren,$prop,'mixed_distance_repeats',0.0,'well_trained','base','5K',80,'long_to_short'); $mt+=array_sum($pm['interval_distances']); $ft+=array_sum($pf['interval_distances']); }
printf("  marathon total %.0fm  >  5K total %.0fm\n",$mt/30,$ft/30); check($mt/30>$ft/30,"mixed goal scaling failed");

// [3] mixed rendered description shows the ladder, no braces.
[$p,$d,$t,]=gen($sel,$add,$ren,$prop,'mixed_distance_repeats',0.0,'well_trained','base','half',75,'combo_set');
printf("\n[3] mixed render: title=\"%s\"\n     desc=%s\n",$t,mb_substr($d,0,150));
check(!str_contains($d,'{{') && !str_contains($t,'{{'),"mixed literal braces in render");
check(str_contains($d,'m,') || preg_match('/\d+ - \d+/',$d),"mixed description does not show the ladder");

// [4] fartlek: pattern selection VARIES + round_count varies + diminishing occasional.
echo "\n[4] fartlek variety (well_trained, build, 70-min slot, 500 generations):\n";
$patterns=[];$rounds=[];$dimin=0;$diminSeqs=[];
for($i=0;$i<500;$i++){
    [$p,$d,$t,$vc]=gen($sel,$add,$ren,$prop,'structured_fartlek_ladder',0.5,'well_trained','build','10K',70);
    if($vc==='diminishing_descending'){ $dimin++; $diminSeqs[implode('-',$p['work_intervals_seconds']??[])]=true; if(str_contains($d,'{{'))check(false,'diminishing literal braces'); }
    else { $patterns[implode('-',$p['work_intervals_seconds']??[])]=($patterns[implode('-',$p['work_intervals_seconds']??[])]??0)+1; $rounds[(int)($p['round_count']??0)]=true; }
}
printf("  distinct standard patterns used: %d  (round_counts seen: %s)\n",count($patterns),implode(',',array_keys($rounds)));
foreach($patterns as $pat=>$n) printf("     %-28s %d\n",$pat,$n);
printf("  diminishing family: %d/500 (~%.0f%%); distinct diminishing sequences: %d\n",$dimin,$dimin/5,count($diminSeqs));
check(count($patterns) >= 5,"fartlek pattern selection not varied (only ".count($patterns).")");
check(count($rounds) >= 2,"fartlek round_count not varied");
check($dimin >= 20 && $dimin <= 120,"diminishing family frequency off (".$dimin."/500, want occasional)");
check(count($diminSeqs) >= 5,"diminishing family not varied (only ".count($diminSeqs)." distinct)");

// [5] fartlek diminishing render sample.
echo "\n[5] fartlek diminishing render samples:\n";
$shown=0; for($i=0;$i<200 && $shown<3;$i++){ [$p,$d,$t,$vc]=gen($sel,$add,$ren,$prop,'structured_fartlek_ladder',0.5,'well_trained','build','10K',70); if($vc==='diminishing_descending'){ printf("  \"%s\": %s\n",$t,mb_substr($d,0,140)); $shown++; } }

// [6] Scope held.
echo "\n[6] Scope: tempo / equal_distance / hills still even-or-3 (untouched):\n";
foreach (['tempo_intervals','equal_distance_repeats','sustained_hill_repeats'] as $code) {
    $ok=true; for($i=0;$i<20;$i++){ [$p,]=gen($sel,$add,$ren,$prop,$code,0.3,'well_trained','build','10K',70); $rc=(int)($p['rep_count']??2); if(!($rc===3||$rc%2===0))$ok=false; }
    printf("  %-24s even-or-3: %s\n",$code,$ok?'ok':'BAD'); check($ok,"$code not even-or-3");
}

echo "\n=== Verdict ===\n";
if (empty($fails)) { echo "PASS\n\n"; exit(0); }
echo "FAIL (".count($fails)."):\n"; foreach ($fails as $f) echo "  - $f\n"; echo "\n";
exit(1);

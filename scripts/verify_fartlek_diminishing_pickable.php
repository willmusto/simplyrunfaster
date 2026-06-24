<?php
/**
 * LIVE verification: the Diminishing Descending Ladder is now coach-pickable in the
 * Library AND still auto-rolls in generation. Run AFTER the migration. Non-destructive.
 *
 * Run from /home/private/app: php scripts/verify_fartlek_diminishing_pickable.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Timezone.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';

$db   = Database::get();
$sel  = new ArchetypeSelector($db);
$add  = new ReflectionMethod(PlanGenerator::class, 'addDerivedParams'); $add->setAccessible(true);
$fails = [];
function check(bool $c, string $m): void { global $fails; if (!$c) $fails[] = $m; }

echo "\n=== Diminishing Descending Ladder: coach-pickable + auto-roll preserved ===\n";

// [1] Library variant list now includes the diminishing family.
$arch = $sel->getByCode('structured_fartlek_ladder');
$codes = array_map(fn($v) => $v['code'] ?? '', $arch['variants'] ?? []);
echo "\n[1] fartlek variant list: " . implode(', ', $codes) . "\n";
check(in_array('diminishing_descending', $codes, true), "diminishing_descending not in the DB variant list (migration?)");
check(count($codes) === 5, "expected 5 fartlek variants, got " . count($codes));

// [2] Coach-pick via the real Library preview path: previewArchetype(..., variant).
echo "\n[2] Coach-pick (previewArchetype) renders a sampled, varied diminishing ladder:\n";
$seen = []; $ok = true;
for ($i = 0; $i < 10; $i++) {
    $p = PlanGenerator::previewArchetype('structured_fartlek_ladder', 'well_trained', 70, '10K', 'diminishing_descending', $db);
    if (!$p) { $ok = false; break; }
    $instr = (string)($p['athlete_instructions'] ?? '');
    $seen[$instr] = true;
    if ($p['variant'] !== 'diminishing_descending') $ok = false;
    if (str_contains($instr, '{{')) { check(false, "literal braces in coach-pick render"); $ok = false; }
    if (!preg_match('/\d+-\d+/', $instr)) { check(false, "coach-pick render has no surge sequence"); $ok = false; }
    if ($i < 2) printf("   \"%s\": %s\n", $p['display_title'], mb_substr($instr, 0, 120));
}
check($ok, "coach-pick preview failed to render the diminishing ladder");
printf("   -> %d distinct rendered instructions across 10 picks (sampled/varied)\n", count($seen));
check(count($seen) >= 4, "coach-pick instances not varied (only " . count($seen) . " distinct)");

// [3] Auto-roll frequency unchanged (~9%) + varied, via the generation path (no forced variant).
echo "\n[3] Auto-roll frequency + variety (generation path, 500 samples):\n";
$dimin = 0; $diminSeqs = []; $standard = 0;
for ($i = 0; $i < 500; $i++) {
    $a = $sel->getByCode('structured_fartlek_ladder');
    $a = $sel->resolveParameters($a, 'well_trained');
    $a = $add->invoke(null, $a, 70, 'build', '10K', 'well_trained'); // no resolved_variant -> weighted auto-roll
    $vc = $a['resolved_variant']['code'] ?? '';
    if ($vc === 'diminishing_descending') { $dimin++; $diminSeqs[implode('-', $a['resolved_params']['work_intervals_seconds'] ?? [])] = true; }
    else $standard++;
}
printf("   diminishing %d/500 (~%.0f%%); standard %d; distinct diminishing sequences %d\n",
    $dimin, $dimin / 5, $standard, count($diminSeqs));
check($dimin >= 20 && $dimin <= 120, "auto-roll frequency drifted ({$dimin}/500, want ~9%)");
check(count($diminSeqs) >= 5, "auto-roll diminishing not varied (only " . count($diminSeqs) . ")");

// [4] Standard variant coach-pick still works (unchanged).
echo "\n[4] Standard fartlek variant (descending) still renders its pattern:\n";
$p = PlanGenerator::previewArchetype('structured_fartlek_ladder', 'well_trained', 70, '10K', 'descending', $db);
printf("   \"%s\": %s\n", $p['display_title'] ?? '', mb_substr((string)($p['athlete_instructions'] ?? ''), 0, 100));
check($p && $p['variant'] === 'descending' && !str_contains((string)$p['athlete_instructions'], '{{'),
    "standard fartlek variant preview broke");

// [5] Another archetype unchanged (sanity).
$t = PlanGenerator::previewArchetype('tempo_intervals', 'well_trained', 60, '10K', null, $db);
check($t && !str_contains((string)($t['athlete_instructions'] ?? ''), '{{'), "tempo preview broke");
echo "\n[5] tempo preview still renders: " . mb_substr((string)($t['athlete_instructions'] ?? ''), 0, 70) . "...\n";

echo "\n=== Verdict ===\n";
if (empty($fails)) { echo "PASS\n\n"; exit(0); }
echo "FAIL (" . count($fails) . "):\n"; foreach ($fails as $f) echo "  - $f\n"; echo "\n";
exit(1);

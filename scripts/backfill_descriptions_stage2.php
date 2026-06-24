<?php
/**
 * STAGE 2 (APPLY) — backfill future-dated planned-workout descriptions.
 *
 * PART A: the 6 templated quality archetypes (154 rows) — re-render description TEXT ONLY from
 *   stored params + current zones via PlanGenerator::recomposeDescriptionOnly(). Structure is
 *   re-derived and asserted byte-identical to the stored one; a row whose structure would differ
 *   is SKIPPED (never written). Writes display_title / athlete_instructions / display_summary only.
 *
 * PART B: Liz (athlete 80) continuous_progression_tempo + structured_fartlek_ladder — SURGICAL
 *   pace-citation swap: replace only the wrong "M:SS-M:SS/mile" range token with the range derived
 *   from her CURRENT zones (PaceZones::qualityCitation), preserving the ladder/progression specifics.
 *   No full re-render. Writes athlete_instructions only.
 *
 * RE-PUSH: rows that changed AND already have an Intervals event (intervals_event_id NOT NULL) are
 *   re-pushed (structure unchanged -> identical steps; name/description/pace refresh from current zones).
 *
 * Idempotent. Scope: scheduled_date >= 2026-06-25 (today 2026-06-24), not cancelled, not completed.
 * Run from /home/private/app: php scripts/backfill_descriptions_stage2.php [--apply]
 *   (default is DRY RUN; pass --apply to write + push.)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Timezone.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
require_once __DIR__ . '/../src/Engine/RecoveryModel.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';
require_once __DIR__ . '/../src/IntervalsService.php';

$APPLY = in_array('--apply', $argv, true);
$db = Database::get();
const CUTOFF = '2026-06-25';
$SIX = ['tempo_intervals','sustained_hill_repeats','equal_distance_repeats','short_speed_repeats','high_volume_time_intervals','mixed_distance_repeats'];

echo "\n=== STAGE 2 " . ($APPLY ? "(APPLY)" : "(DRY RUN — pass --apply to write)") . " ===\n";
echo "Scope: scheduled_date >= " . CUTOFF . ", not cancelled, not completed.\n";

function canon($v){ if(is_array($v)){ $l=array_keys($v)===range(0,count($v)-1); if(!$l)ksort($v); foreach($v as $k=>$vv)$v[$k]=canon($vv);} return $v;}
function structEq($a,$b){ return json_encode(canon(json_decode((string)$a,true)))===json_encode(canon(json_decode((string)$b,true))); }

$scope = "pw.scheduled_date >= '" . CUTOFF . "' AND (pw.cancelled=0 OR pw.cancelled IS NULL)
          AND NOT EXISTS (SELECT 1 FROM completed_workouts cw WHERE cw.planned_workout_id = pw.id)";

$repush = []; // [athlete_id => [ids]]
$changedTotal = 0; $skipped = [];

// ── PART A: the 154 ──
echo "\n--- PART A: re-render the 6 quality archetypes (text only) ---\n";
$ids = $db->query("SELECT pw.id FROM planned_workouts pw
                   WHERE $scope AND pw.archetype_code IN ('" . implode("','", $SIX) . "')")->fetchAll(PDO::FETCH_COLUMN);
echo "In scope (Part A): " . count($ids) . " rows.\n";
$aChanged = 0; $aNoop = 0;
foreach ($ids as $id) {
    $row = $db->query("SELECT athlete_id, scheduled_date, structure, intervals_event_id, display_title, display_summary, athlete_instructions FROM planned_workouts WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    if ((string)$row['scheduled_date'] < CUTOFF) { $skipped[] = "$id (past)"; continue; }
    $new = PlanGenerator::recomposeDescriptionOnly((int)$id, $db);
    if (!$new) { $skipped[] = "$id (no re-render)"; continue; }
    if (!structEq($row['structure'], $new['structure'])) { $skipped[] = "$id (structure would differ)"; continue; }
    $changed = ($new['display_title'] !== $row['display_title'])
            || ($new['athlete_instructions'] !== $row['athlete_instructions'])
            || ($new['display_summary'] !== $row['display_summary']);
    if (!$changed) { $aNoop++; continue; }
    if ($APPLY) {
        $db->prepare("UPDATE planned_workouts SET display_title=?, display_summary=?, athlete_instructions=? WHERE id=?")
           ->execute([$new['display_title'], $new['display_summary'], $new['athlete_instructions'], $id]);
    }
    $aChanged++;
    if ($row['intervals_event_id'] !== null) $repush[(int)$row['athlete_id']][] = (int)$id;
}
echo "Part A: $aChanged would change, $aNoop already current, " . count($ids) . " total.\n";
$changedTotal += $aChanged;

// ── PART B: Liz's surgical pace swap ──
echo "\n--- PART B: Liz #80 surgical pace-citation swap (specifics preserved) ---\n";
$prof = $db->query("SELECT pace_zones, pace_zones_visible, goal_race_distance FROM athlete_profiles WHERE athlete_id=80")->fetch(PDO::FETCH_ASSOC);
$zones = (!empty($prof['pace_zones_visible']) && PaceZones::isPopulated($prof['pace_zones'] ?? null))
       ? (json_decode($prof['pace_zones'] ?? 'null', true) ?: null) : null;
$goal  = (string)($prof['goal_race_distance'] ?? '5K');
echo "Liz goal=$goal zones_visible=" . (int)!empty($prof['pace_zones_visible']) . "\n";

$rangeRe = '/\d+:\d\d[\x{2013}\-]\d+:\d\d\/mile/u';
$lizRows = $db->query("SELECT pw.id, pw.archetype_code, pw.archetype_variant, pw.archetype_params, pw.athlete_instructions, pw.structure, pw.intervals_event_id, pw.scheduled_date
                       FROM planned_workouts pw
                       WHERE pw.athlete_id=80 AND $scope
                         AND pw.archetype_code IN ('continuous_progression_tempo','structured_fartlek_ladder')")->fetchAll(PDO::FETCH_ASSOC);
$bChanged = 0;
foreach ($lizRows as $r) {
    $id = (int)$r['id'];
    $text = (string)$r['athlete_instructions'];
    $params = json_decode((string)$r['archetype_params'], true) ?: [];
    $clause = PaceZones::qualityCitation((string)$r['archetype_code'], $params, $zones, (string)$r['archetype_variant'], $goal);
    if (!$clause || !preg_match($rangeRe, $clause, $cm)) { $skipped[] = "$id (no correct range derivable)"; continue; }
    $correctRange = $cm[0];
    if (!preg_match_all($rangeRe, $text, $tm)) { $skipped[] = "$id (no pace range in stored text)"; continue; }
    if (count($tm[0]) !== 1) { $skipped[] = "$id (" . count($tm[0]) . " ranges found, expected 1)"; continue; }
    $oldRange = $tm[0][0];
    echo "\n  #$id ({$r['archetype_code']}, {$r['scheduled_date']})\n";
    echo "    BEFORE: " . $text . "\n";
    if ($oldRange === $correctRange) { echo "    (range already correct: $correctRange — no change)\n"; continue; }
    $newText = preg_replace($rangeRe, $correctRange, $text, 1);
    echo "    AFTER : " . $newText . "\n";
    echo "    swap: $oldRange  ->  $correctRange   (specifics preserved, structure untouched)\n";
    if ($APPLY) {
        $db->prepare("UPDATE planned_workouts SET athlete_instructions=? WHERE id=?")->execute([$newText, $id]);
    }
    $bChanged++;
    if ($r['intervals_event_id'] !== null) $repush[80][] = $id;
}
echo "\nPart B: $bChanged surgical swaps" . ($APPLY ? " applied" : " (dry run)") . ".\n";
$changedTotal += $bChanged;

// ── RE-PUSH (only already-pushed rows; structure unchanged) ──
echo "\n--- RE-PUSH (rows with an existing Intervals event) ---\n";
$pushOk = 0; $pushTotal = 0;
if ($APPLY) {
    foreach ($repush as $aid => $list) {
        foreach (array_unique($list) as $wid) {
            $pushTotal++;
            if (IntervalsService::pushWorkout((int)$aid, (int)$wid, $db)) $pushOk++;
        }
    }
    echo "Re-pushed $pushOk of $pushTotal (others: athlete not connected / no-op).\n";
} else {
    $q = 0; foreach ($repush as $list) $q += count(array_unique($list));
    echo "$q rows queued for re-push (have intervals_event_id). Run with --apply to push.\n";
}

// ── Report ──
echo "\n=== SUMMARY ===\n";
echo "Part A (6 archetypes) changed:  $aChanged\n";
echo "Part B (Liz surgical) changed:  $bChanged\n";
echo "Total descriptions changed:     $changedTotal\n";
if ($APPLY) echo "Re-pushed to watch:             $pushOk\n";
if (!empty($skipped)) { echo "Skipped (" . count($skipped) . "): " . implode('; ', array_slice($skipped, 0, 40)) . "\n"; }
else echo "Skipped: 0\n";
echo "Structure: untouched on every row (Part A asserts byte-identical; Part B writes text only).\n";
echo "Completed/past (< " . CUTOFF . "): excluded by scope, not touched.\n\n";

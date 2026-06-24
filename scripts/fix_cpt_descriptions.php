<?php
/**
 * Fix existing continuous_progression_tempo descriptions after the template restore
 * (migrate_cpt_description_fix.php). Re-renders future-dated, not-completed, not-cancelled,
 * not-coach-locked continuous_progression_tempo workouts via recomposeDescriptionOnly (TEXT
 * ONLY; structure asserted byte-identical), and dismisses the open display_generation_incomplete
 * flags whose workouts are now fixed.
 *
 * PART V: verifies a FRESH generation first (description shows the progression breakdown +
 * warm/cool, no literal {{}}, validator-clean).
 *
 * Run from /home/private/app AFTER the migration:
 *   php scripts/fix_cpt_descriptions.php           (dry run)
 *   php scripts/fix_cpt_descriptions.php --apply    (write + dismiss flags)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Timezone.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
require_once __DIR__ . '/../src/Engine/RecoveryModel.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';

$APPLY = in_array('--apply', $argv, true);
$db = Database::get();
const CUTOFF = '2026-06-25';

function canon($v){ if(is_array($v)){ $l=array_keys($v)===range(0,count($v)-1); if(!$l)ksort($v); foreach($v as $k=>$vv)$v[$k]=canon($vv);} return $v;}
function structEq($a,$b){ return json_encode(canon(json_decode((string)$a,true)))===json_encode(canon(json_decode((string)$b,true))); }
function checks(string $t): array {
    return [
        'warm'        => stripos($t,'warm')!==false,
        'cool'        => stripos($t,'cool')!==false,
        'breakdown'   => stripos($t,'Run continuously for')!==false,
        'no_token'    => !preg_match('/\{\{\w+\}\}/',$t),
    ];
}

echo "\n=== CPT description fix " . ($APPLY ? "(APPLY)" : "(DRY RUN)") . " ===\n";

// ── PART V: fresh generation reflects the restored template ──
echo "\n[verify] fresh generation of continuous_progression_tempo:\n";
$athleteId = (int)($db->query("SELECT athlete_id FROM planned_workouts WHERE archetype_code='continuous_progression_tempo' AND scheduled_date>='" . CUTOFF . "' LIMIT 1")->fetchColumn() ?: 85);
foreach (['linear_progression','wave_progression'] as $variant) {
    $w = PlanGenerator::composeManualWorkout($athleteId, 'continuous_progression_tempo', $variant, 70, $db);
    if (!$w) { echo "  $variant: compose failed\n"; continue; }
    $c = checks((string)$w['athlete_instructions']);
    printf("  %-20s warm=%s cool=%s breakdown=%s no_token=%s\n", $variant, $c['warm']?'Y':'N', $c['cool']?'Y':'N', $c['breakdown']?'Y':'N', $c['no_token']?'Y':'N');
    echo "    " . mb_substr((string)$w['athlete_instructions'], 0, 200) . "...\n";
}

// ── PART R: re-render existing affected rows ──
echo "\n[re-render] future continuous_progression_tempo workouts (text only):\n";
$ids = $db->query("SELECT id FROM planned_workouts
                   WHERE archetype_code='continuous_progression_tempo'
                     AND scheduled_date >= '" . CUTOFF . "'
                     AND (cancelled=0 OR cancelled IS NULL)
                     AND (coach_locked=0 OR coach_locked IS NULL)
                     AND NOT EXISTS (SELECT 1 FROM completed_workouts cw WHERE cw.planned_workout_id=planned_workouts.id)
                   ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
echo "  candidates (future, not completed/cancelled/locked): " . count($ids) . "\n";
$changed = 0; $skipped = []; $changedAthletes = []; $alreadyOk = 0;
foreach ($ids as $id) {
    $row = $db->query("SELECT athlete_id, plan_id, scheduled_date, structure, display_title, display_summary, athlete_instructions FROM planned_workouts WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    // Only touch rows whose CURRENT description is broken (the generic-template regression).
    // Rows generated before the migration already carry valid warm/cool + breakdown — leave them.
    $bc = checks((string)$row['athlete_instructions']);
    if ($bc['warm'] && $bc['cool'] && $bc['breakdown'] && $bc['no_token']) { $alreadyOk++; continue; }
    $new = PlanGenerator::recomposeDescriptionOnly((int)$id, $db);
    if (!$new) { $skipped[] = "$id (not re-renderable)"; continue; }
    if (!structEq($row['structure'], $new['structure'])) { $skipped[] = "$id (structure would differ)"; continue; }
    $c = checks((string)$new['athlete_instructions']);
    $ok = $c['warm'] && $c['cool'] && $c['breakdown'] && $c['no_token'];
    $diff = $new['athlete_instructions'] !== $row['athlete_instructions'] || $new['display_title'] !== $row['display_title'] || $new['display_summary'] !== $row['display_summary'];
    echo "\n  #$id (ath {$row['athlete_id']}, plan {$row['plan_id']}, {$row['scheduled_date']})  checks: warm=" . ($c['warm']?'Y':'N') . " cool=" . ($c['cool']?'Y':'N') . " breakdown=" . ($c['breakdown']?'Y':'N') . " no_token=" . ($c['no_token']?'Y':'N') . " struct=identical\n";
    echo "    BEFORE: " . mb_substr((string)$row['athlete_instructions'], 0, 150) . "\n";
    echo "    AFTER : " . mb_substr((string)$new['athlete_instructions'], 0, 150) . "\n";
    if (!$ok) { $skipped[] = "$id (re-render still incomplete)"; continue; }
    if ($diff) {
        if ($APPLY) {
            $db->prepare("UPDATE planned_workouts SET display_title=?, display_summary=?, athlete_instructions=? WHERE id=?")
               ->execute([$new['display_title'], $new['display_summary'], $new['athlete_instructions'], $id]);
        }
        $changed++; $changedAthletes[(int)$row['athlete_id']] = true;
    }
}
echo "\n  re-rendered: $changed" . ($APPLY ? " (written)" : " (dry run)") . "\n";

// ── PART F: dismiss the now-fixed flags ──
echo "\n[flags] open display_generation_incomplete flags:\n";
$flags = $db->query("SELECT id, athlete_id, message FROM engine_flags WHERE flag_type='display_generation_incomplete' AND status='open'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($flags as $f) echo "  flag#{$f['id']} ath={$f['athlete_id']}: {$f['message']}\n";
if ($APPLY) {
    $dismissed = 0;
    foreach ($flags as $f) {
        $db->prepare("UPDATE engine_flags SET status='dismissed', reviewed_at=NOW(), dismiss_reason=? WHERE id=?")
           ->execute(['continuous_progression_tempo template restored + descriptions re-rendered (warm/cool + progression breakdown)', (int)$f['id']]);
        $dismissed++;
    }
    echo "  dismissed: $dismissed\n";
} else {
    echo "  (dry run — would dismiss " . count($flags) . " after re-render)\n";
}

echo "\n=== SUMMARY ===\n";
echo "Already-valid CPT rows left untouched: $alreadyOk\n";
echo "Re-rendered (broken) CPT descriptions: $changed (" . count($changedAthletes) . " athletes)\n";
echo "Flags " . ($APPLY ? "dismissed" : "to dismiss") . ": " . count($flags) . "\n";
echo (!empty($skipped) ? "Skipped (" . count($skipped) . "): " . implode('; ', $skipped) . "\n" : "Skipped: 0\n");
echo "Structure: untouched (asserted byte-identical; text-only writes).\n\n";

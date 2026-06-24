<?php
/**
 * Fix existing degraded structured_fartlek_ladder (standard) descriptions after the template
 * restore (migrate_fartlek_description_fix.php). Re-renders future-dated, not-completed,
 * not-cancelled, not-coach-locked STANDARD fartlek rows whose current description is the generic
 * regression text (lacks the ladder), via recomposeDescriptionOnly (TEXT ONLY; structure asserted
 * byte-identical). Rows that already show the specific ladder (old template or diminishing) are
 * left untouched. The diminishing variant is refused by recomposeDescriptionOnly (its override
 * copy can't be reproduced on re-render).
 *
 * PART V verifies a FRESH generation first (standard shows the actual ladder + rounds; diminishing
 * still renders its bespoke sequence; no literal {{}}).
 *
 * Run from /home/private/app AFTER the migration:
 *   php scripts/fix_fartlek_descriptions.php           (dry run)
 *   php scripts/fix_fartlek_descriptions.php --apply    (write)
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

echo "\n=== Fartlek (standard) description fix " . ($APPLY ? "(APPLY)" : "(DRY RUN)") . " ===\n";

// ── PART V: fresh generation ──
echo "\n[verify] fresh generation:\n";
$aid = (int)($db->query("SELECT athlete_id FROM athlete_profiles WHERE pace_zones_visible=1 LIMIT 1")->fetchColumn() ?: 51);
foreach (['descending', 'symmetric', 'diminishing_descending'] as $variant) {
    $w = PlanGenerator::composeManualWorkout($aid, 'structured_fartlek_ladder', $variant, 42, $db);
    if (!$w) { echo "  $variant: compose failed\n"; continue; }
    $t = (string)$w['athlete_instructions'];
    $noTok = !preg_match('/\{\{\w+\}\}/', $t);
    $hasLadder = (stripos($t, 'fartlek ladder') !== false) || (stripos($t, 'diminishing descending ladder') !== false);
    printf("  %-22s ladder=%s no_token=%s\n", $variant, $hasLadder?'Y':'N', $noTok?'Y':'N');
    echo "    " . mb_substr($t, 0, 170) . "...\n";
}

// ── PART R: re-render degraded standard rows ──
echo "\n[re-render] degraded standard fartlek rows (text only):\n";
$ids = $db->query("SELECT id FROM planned_workouts
                   WHERE archetype_code='structured_fartlek_ladder'
                     AND scheduled_date >= '" . CUTOFF . "'
                     AND (cancelled=0 OR cancelled IS NULL)
                     AND (coach_locked=0 OR coach_locked IS NULL)
                     AND athlete_instructions LIKE '%arranged as a ladder%'
                     AND NOT EXISTS (SELECT 1 FROM completed_workouts cw WHERE cw.planned_workout_id=planned_workouts.id)
                   ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
echo "  degraded in scope: " . count($ids) . "\n";
$changed = 0; $skipped = []; $changedAthletes = [];
foreach ($ids as $id) {
    $row = $db->query("SELECT athlete_id, plan_id, scheduled_date, archetype_variant, archetype_params, structure, display_title, display_summary, athlete_instructions FROM planned_workouts WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $seq = json_decode((string)$row['archetype_params'], true)['fartlek_ladder_sequence'] ?? '';
    $new = PlanGenerator::recomposeDescriptionOnly((int)$id, $db);
    if (!$new) { $skipped[] = "$id (refused: " . $row['archetype_variant'] . ")"; continue; }
    if (!structEq($row['structure'], $new['structure'])) { $skipped[] = "$id (structure would differ)"; continue; }
    $t = (string)$new['athlete_instructions'];
    $ok = (stripos($t, 'fartlek ladder') !== false) && ($seq === '' || strpos($t, $seq) !== false) && !preg_match('/\{\{\w+\}\}/', $t);
    echo "\n  #$id (ath {$row['athlete_id']}, plan {$row['plan_id']}, {$row['scheduled_date']}, {$row['archetype_variant']})  seq='{$seq}' restored=" . ($ok?'Y':'N') . " struct=identical\n";
    echo "    BEFORE: " . mb_substr((string)$row['athlete_instructions'], 0, 140) . "\n";
    echo "    AFTER : " . mb_substr($t, 0, 140) . "\n";
    if (!$ok) { $skipped[] = "$id (sequence not restored)"; continue; }
    if ($t !== $row['athlete_instructions'] || $new['display_title'] !== $row['display_title'] || $new['display_summary'] !== $row['display_summary']) {
        if ($APPLY) {
            $db->prepare("UPDATE planned_workouts SET display_title=?, display_summary=?, athlete_instructions=? WHERE id=?")
               ->execute([$new['display_title'], $new['display_summary'], $t, $id]);
        }
        $changed++; $changedAthletes[(int)$row['athlete_id']] = true;
    }
}

echo "\n=== SUMMARY ===\n";
echo "Re-rendered degraded standard-fartlek rows: $changed (" . count($changedAthletes) . " athletes)" . ($APPLY ? " [written]" : " [dry run]") . "\n";
echo (!empty($skipped) ? "Skipped (" . count($skipped) . "): " . implode('; ', $skipped) . "\n" : "Skipped: 0\n");
echo "Structure: untouched (asserted byte-identical; text-only writes). Diminishing + old-specific rows left untouched.\n\n";

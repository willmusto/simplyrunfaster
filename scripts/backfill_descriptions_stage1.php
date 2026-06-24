<?php
/**
 * STAGE 1 (READ-ONLY) — confirm the description re-render path + report scope.
 *
 * Re-renders in-scope descriptions IN MEMORY via PlanGenerator::composeStructuredEdit($id, [])
 * (empty edits = no param change; $manual mode = no structure re-roll; current pace zones), and
 * compares the rebuilt structure to the STORED structure to prove structure is untouched. Writes
 * NOTHING. Prints scope counts, an archetype breakdown, and BEFORE/AFTER samples.
 *
 * Run from /home/private/app: php scripts/backfill_descriptions_stage1.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Timezone.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
require_once __DIR__ . '/../src/Engine/RecoveryModel.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';

$db = Database::get();
const CUTOFF = '2026-06-25'; // tomorrow; today is 2026-06-24

// In-scope filter: future-dated, not cancelled, not completed.
$scopeSql = "FROM planned_workouts pw
             WHERE pw.scheduled_date >= '" . CUTOFF . "'
               AND (pw.cancelled = 0 OR pw.cancelled IS NULL)
               AND NOT EXISTS (SELECT 1 FROM completed_workouts cw WHERE cw.planned_workout_id = pw.id)";

// Canonical JSON (recursive ksort) so structure equality is order-insensitive for assoc keys.
function canon($v) {
    if (is_array($v)) {
        $isList = array_keys($v) === range(0, count($v) - 1);
        if (!$isList) ksort($v);
        foreach ($v as $k => $vv) $v[$k] = canon($vv);
    }
    return $v;
}
function structEq(?string $a, ?string $b): bool {
    return json_encode(canon(json_decode((string)$a, true))) === json_encode(canon(json_decode((string)$b, true)));
}

echo "\n=== STAGE 1 (read-only): description backfill scope + re-render check ===\n";
echo "Cutoff: scheduled_date >= " . CUTOFF . " (today 2026-06-24), not cancelled, not completed.\n";

// ── Scope counts ──
$total    = (int)$db->query("SELECT COUNT(*) $scopeSql")->fetchColumn();
$athletes = (int)$db->query("SELECT COUNT(DISTINCT pw.athlete_id) $scopeSql")->fetchColumn();
echo "\nIn scope: $total workouts across $athletes athletes.\n";

echo "\nArchetype breakdown (in scope):\n";
$rows = $db->query("SELECT COALESCE(pw.archetype_code,'(none)') AS code, COUNT(*) AS n $scopeSql GROUP BY code ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
$reRenderable = ['tempo_intervals','sustained_hill_repeats','equal_distance_repeats','short_speed_repeats','high_volume_time_intervals','mixed_distance_repeats'];
$nRe = 0;
foreach ($rows as $r) {
    $tag = in_array($r['code'], $reRenderable, true) ? ' <- re-renderable (lead line + pace)' : '';
    if (in_array($r['code'], $reRenderable, true)) $nRe += (int)$r['n'];
    printf("  %-32s %5d%s\n", $r['code'], (int)$r['n'], $tag);
}
echo "\nRe-renderable by composeStructuredEdit (the 6 quality archetypes): $nRe of $total.\n";
echo "Others (easy/long/recovery/rest/run-walk/strides/continuous/fartlek) have no lead line; only\n";
echo "continuous_progression_tempo + structured_fartlek_ladder carry pace citations among them.\n";

// ── Liz #80 lookup ──
echo "\n=== Liz #80 ===\n";
$liz = $db->query("SELECT a.id AS athlete_id, u.name, ap.pace_zones, ap.pace_zones_visible
                   FROM athletes a JOIN users u ON u.id=a.user_id
                   LEFT JOIN athlete_profiles ap ON ap.athlete_id=a.id
                   WHERE a.id=80 OR u.name LIKE 'Liz%' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($liz as $l) {
    printf("  athlete_id=%s name=%s zones_visible=%s zones=%s\n", $l['athlete_id'], $l['name'],
        $l['pace_zones_visible'], $l['pace_zones'] ? mb_substr($l['pace_zones'],0,80) : 'NULL');
}
$lizId = !empty($liz) ? (int)$liz[0]['athlete_id'] : 80;

// ── BEFORE/AFTER samples (dry run, no writes) ──
$show = function (int $id) use ($db) {
    $row = $db->query("SELECT id, athlete_id, scheduled_date, archetype_code, structure, display_title, athlete_instructions FROM planned_workouts WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo "  (workout $id not found)\n"; return; }
    echo "\n  --- workout #{$row['id']} (athlete {$row['athlete_id']}, {$row['scheduled_date']}, {$row['archetype_code']}) ---\n";
    echo "  BEFORE title: " . $row['display_title'] . "\n";
    echo "  BEFORE instr: " . mb_substr((string)$row['athlete_instructions'], 0, 240) . "\n";
    $new = PlanGenerator::recomposeDescriptionOnly((int)$id, $db); // dry run: not persisted
    if (!$new) { echo "  (not re-renderable by composeStructuredEdit — archetype out of the 6)\n"; return; }
    echo "  AFTER  title: " . $new['display_title'] . "\n";
    echo "  AFTER  instr: " . mb_substr((string)$new['athlete_instructions'], 0, 240) . "\n";
    $same = structEq($row['structure'], $new['structure']);
    echo "  STRUCTURE identical: " . ($same ? 'YES (safe — text only would change)' : 'NO *** would not write; needs review ***') . "\n";
};

echo "\n=== Sample: Liz #80 upcoming quality workouts ===\n";
$lizWk = $db->query("SELECT pw.id FROM planned_workouts pw
                     WHERE pw.athlete_id=$lizId AND pw.scheduled_date >= '" . CUTOFF . "'
                       AND (pw.cancelled=0 OR pw.cancelled IS NULL)
                       AND pw.archetype_code IN ('" . implode("','", $reRenderable) . "')
                       AND NOT EXISTS (SELECT 1 FROM completed_workouts cw WHERE cw.planned_workout_id=pw.id)
                     ORDER BY pw.scheduled_date LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (empty($lizWk)) echo "  (no in-scope re-renderable quality workouts for athlete $lizId)\n";
foreach ($lizWk as $id) $show((int)$id);

echo "\n=== Sample: a normal athlete's upcoming tempo ===\n";
$tempo = $db->query("SELECT pw.id FROM planned_workouts pw
                     WHERE pw.scheduled_date >= '" . CUTOFF . "'
                       AND pw.archetype_code='tempo_intervals'
                       AND pw.athlete_id <> $lizId
                       AND (pw.cancelled=0 OR pw.cancelled IS NULL)
                       AND NOT EXISTS (SELECT 1 FROM completed_workouts cw WHERE cw.planned_workout_id=pw.id)
                     ORDER BY pw.scheduled_date LIMIT 1")->fetchAll(PDO::FETCH_COLUMN);
if (empty($tempo)) echo "  (no in-scope tempo for another athlete)\n";
foreach ($tempo as $id) $show((int)$id);

// ── Structure-identity sweep over ALL in-scope re-renderable rows (read-only) ──
echo "\n=== Structure-identity sweep (all in-scope re-renderable rows, dry run) ===\n";
$ids = $db->query("SELECT pw.id FROM planned_workouts pw
                   WHERE pw.scheduled_date >= '" . CUTOFF . "'
                     AND (pw.cancelled=0 OR pw.cancelled IS NULL)
                     AND pw.archetype_code IN ('" . implode("','", $reRenderable) . "')
                     AND NOT EXISTS (SELECT 1 FROM completed_workouts cw WHERE cw.planned_workout_id=pw.id)")->fetchAll(PDO::FETCH_COLUMN);
$ok = 0; $bad = 0; $badIds = [];
foreach ($ids as $id) {
    $row = $db->query("SELECT structure FROM planned_workouts WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $new = PlanGenerator::recomposeDescriptionOnly((int)$id, $db);
    if ($new && structEq($row['structure'], $new['structure'])) $ok++;
    else { $bad++; $badIds[] = (int)$id; }
}
printf("  %d of %d rebuild an IDENTICAL structure (safe to text-refresh).\n", $ok, count($ids));
if ($bad > 0) printf("  %d differ -> would be SKIPPED, ids: %s\n", $bad, implode(',', array_slice($badIds, 0, 30)));

echo "\n=== STAGE 1 complete. No rows were modified. ===\n\n";

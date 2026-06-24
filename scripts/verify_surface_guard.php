<?php
/**
 * LIVE verification for the surface-edit server-side guard: on a GENERATED (archetype-backed)
 * workout, composeWorkoutEdit('surface', ...) must IGNORE incoming workout_type/target_duration
 * (structure is source of truth) while still saving title/instructions/notes; on a FREE-FORM
 * workout (archetype_code NULL) it must still accept type/duration. PURE compose call, no writes
 * to planned_workouts needed (composeWorkoutEdit is read-only); throwaway athlete for context.
 *
 * Run from /home/private/app: php scripts/verify_surface_guard.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Timezone.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
if (is_file(__DIR__ . '/../src/Engine/RecoveryModel.php')) require_once __DIR__ . '/../src/Engine/RecoveryModel.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';
require_once __DIR__ . '/../src/Controllers/CoachController.php';
// base.php display helpers (pill_label / format_duration) used by the surface branch.
foreach (['/../views/layout/base.php'] as $h) { if (is_file(__DIR__ . $h)) require_once __DIR__ . $h; }

$db = Database::get();
$fails = [];
function check(bool $c, string $m): void { global $fails; if (!$c) $fails[] = $m; }

echo "\n=== Surface-edit guard: generated ignores type/duration, free-form accepts ===\n";

// A GENERATED row (archetype_code non-null): the structure owns type + duration.
$gen = [
    'athlete_id' => 1, 'workout_type' => 'tempo', 'target_duration' => 81,
    'archetype_code' => 'tempo_intervals', 'intensity_load' => 68.85,
];
$r = CoachController::composeWorkoutEdit('surface', $gen, [
    'mode' => 'surface',
    'workout_type' => 'hill',        // hostile: try to relabel
    'target_duration' => 60,         // hostile: try to shrink
    'title' => 'Coach title',
    'athlete_instructions' => 'Coach instructions',
    'coach_notes' => 'Coach notes',
], $db);
$c = $r['columns'];
echo "\n[generated] posted type=hill dur=60 onto tempo/81:\n";
printf("  workout_type   -> %s (want tempo)\n", $c['workout_type']);
printf("  target_duration-> %s (want 81)\n", $c['target_duration']);
printf("  intensity_load -> %s (want ~68.85, unchanged)\n", $c['intensity_load']);
printf("  display_title  -> %s\n", var_export($c['display_title'] ?? null, true));
printf("  athlete_instr  -> %s\n", var_export($c['athlete_instructions'] ?? null, true));
printf("  notes          -> %s\n", var_export($c['notes'] ?? null, true));
printf("  display_summary regenerated? %s (want no)\n", array_key_exists('display_summary', $c) ? 'YES' : 'no');
printf("  change_type    -> %s (want instructions_edited)\n", $r['change_type']);
check($c['workout_type'] === 'tempo', "generated: workout_type was overwritten");
check((int)$c['target_duration'] === 81, "generated: target_duration was overwritten");
check(abs((float)$c['intensity_load'] - 68.85) < 0.01, "generated: intensity_load drifted");
check(($c['display_title'] ?? null) === 'Coach title', "generated: title not saved");
check(($c['athlete_instructions'] ?? null) === 'Coach instructions', "generated: instructions not saved");
check(($c['notes'] ?? null) === 'Coach notes', "generated: notes not saved");
check(!array_key_exists('display_summary', $c), "generated: summary needlessly regenerated");
check($r['change_type'] === 'instructions_edited', "generated: change_type wrong");

// A FREE-FORM row (archetype_code NULL): type + duration are the source of truth, still accepted.
$ff = [
    'athlete_id' => 1, 'workout_type' => 'easy', 'target_duration' => 40,
    'archetype_code' => null, 'intensity_load' => 20.0,
];
$r2 = CoachController::composeWorkoutEdit('surface', $ff, [
    'mode' => 'surface',
    'workout_type' => 'tempo',
    'target_duration' => 55,
    'title' => 'FF title',
    'athlete_instructions' => 'FF instr',
], $db);
$c2 = $r2['columns'];
echo "\n[free-form] posted type=tempo dur=55 onto easy/40:\n";
printf("  workout_type   -> %s (want tempo)\n", $c2['workout_type']);
printf("  target_duration-> %s (want 55)\n", $c2['target_duration']);
printf("  display_summary-> %s (want regenerated)\n", var_export($c2['display_summary'] ?? null, true));
printf("  change_type    -> %s\n", $r2['change_type']);
check($c2['workout_type'] === 'tempo', "free-form: workout_type not accepted");
check((int)$c2['target_duration'] === 55, "free-form: target_duration not accepted");
check(($c2['display_title'] ?? null) === 'FF title', "free-form: title not saved");
check(array_key_exists('display_summary', $c2), "free-form: summary not regenerated on change");

// Free-form text-only edit (no type/dur posted) keeps stored type/duration.
$r3 = CoachController::composeWorkoutEdit('surface', $ff, [
    'mode' => 'surface', 'athlete_instructions' => 'just text',
], $db);
check($r3['columns']['workout_type'] === 'easy' && (int)$r3['columns']['target_duration'] === 40, "free-form text-only edit drifted type/dur");
echo "\n[free-form] text-only edit keeps easy/40: ok\n";

echo "\n=== Verdict ===\n";
if (empty($fails)) { echo "PASS\n\n"; exit(0); }
echo "FAIL (".count($fails)."):\n"; foreach ($fails as $f) echo "  - $f\n"; echo "\n"; exit(1);

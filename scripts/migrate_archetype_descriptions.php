<?php
/**
 * Migration: refresh athlete-facing + coach-facing copy for all 17 archetypes.
 *
 * Updates ONLY three fields per archetype, matched by code:
 *   display.description_template   <- new athlete-facing copy
 *   coach_notes.intended_use       <- new coach-facing copy
 *   coach_notes.special_rules      <- new array (wholesale replacement)
 * All other archetype data (weights, eligibility, parameters, variants, structure,
 * title/summary templates) is preserved untouched.
 *
 * Source of record: database/seeds/archetype_descriptions.json.
 *
 * TEMPLATE-ONLY change. It does NOT touch any planned_workouts row: existing plans keep
 * their stored descriptions and pick up the new copy only when they next regenerate.
 *
 * Two archetypes carry a conditional mid-set checkpoint clause rather than the source
 * file's fixed {{quarter_rep}}/{{three_quarter_rep}} slots: the stored description ends
 * with {{checkpoint_recovery_instruction}}, which PlanGenerator renders per the instance's
 * actual (even-or-3) rep_count (omitted <=8, one at halfway 9-16, two at quarter/three-
 * quarter 17+). The transformed strings are applied here so the DB and the renderer agree.
 *
 * Idempotent: re-running sets the same values. Run from /home/private/app:
 *   php scripts/migrate_archetype_descriptions.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = Database::get();

$jsonPath = __DIR__ . '/../database/seeds/archetype_descriptions.json';
$entries  = json_decode((string)file_get_contents($jsonPath), true);
if (!is_array($entries)) {
    fwrite(STDERR, "Could not read $jsonPath\n");
    exit(1);
}

// Conditional-checkpoint archetypes: replace the source file's fixed-slot checkpoint
// sentence with the single {{checkpoint_recovery_instruction}} token (no preceding space;
// the rendered clause carries its own leading space and is omitted entirely at <=8 reps).
$checkpointDescriptions = [
    'sustained_hill_repeats' =>
        'A hill session built on longer, sustained climbs run at a strong, driving effort. '
        . 'Focus on tall posture, a powerful push off each step, and steady rhythm to the top, '
        . 'then jog down easy to recover fully before the next. Hills build strength and economy '
        . 'with far less pounding than flat speedwork, which is why they earn a place in nearly '
        . 'every phase. Work the effort, not the clock.{{checkpoint_recovery_instruction}}',
    'equal_distance_repeats' =>
        'A set of even-distance repeats run at one consistent, controlled effort, with easy '
        . 'recovery between each. The skill here is even pacing: the last rep should look like '
        . 'the first, so resist the urge to hammer the early ones. This is the work that sharpens '
        . 'your aerobic power and race-specific fitness.{{checkpoint_recovery_instruction}}',
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$updated = 0; $missing = [];

$sel = $pdo->prepare('SELECT display, coach_notes FROM workout_archetypes WHERE code = :code LIMIT 1');
$upd = $pdo->prepare(
    'UPDATE workout_archetypes SET display = :display, coach_notes = :coach_notes WHERE code = :code'
);

foreach ($entries as $e) {
    $code = $e['code'] ?? null;
    if ($code === null) continue;

    $sel->execute([':code' => $code]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $missing[] = $code; continue; }

    $display    = json_decode((string)$row['display'], true);
    $coachNotes = json_decode((string)$row['coach_notes'], true);
    if (!is_array($display))    $display    = [];
    if (!is_array($coachNotes)) $coachNotes = [];

    // 1) athlete-facing description (transformed for the conditional-checkpoint archetypes)
    $display['description_template'] = $checkpointDescriptions[$code] ?? (string)$e['description_template'];
    // 2) coach-facing intended use
    $coachNotes['intended_use'] = (string)$e['intended_use'];
    // 3) special rules (wholesale replacement)
    $coachNotes['special_rules'] = array_values((array)$e['special_rules']);

    $upd->execute([
        ':display'     => json_encode($display, $jsonFlags),
        ':coach_notes' => json_encode($coachNotes, $jsonFlags),
        ':code'        => $code,
    ]);
    $updated++;
    printf("  updated %-28s desc=%d chars  rules=%d\n",
        $code, mb_strlen($display['description_template']), count($coachNotes['special_rules']));
}

echo "\nUpdated {$updated} / " . count($entries) . " archetypes.\n";
if (!empty($missing)) {
    echo "MISSING (not found in DB): " . implode(', ', $missing) . "\n";
    exit(1);
}
echo "Done. No planned_workouts rows touched (template-only).\n";
exit(0);

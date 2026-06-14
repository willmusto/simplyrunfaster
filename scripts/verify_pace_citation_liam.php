<?php
/**
 * Integration verification for pace-zone citation (engine spec §19 item 14),
 * driven off Liam's profile. Regenerates with pace_zones_visible ON then OFF
 * and checks:
 *   1. ON  — quality sessions for citing archetypes carry a "/mile" citation
 *            alongside the effort language; hill archetypes do not.
 *   2. OFF — no workout carries any "/mile" citation (effort-only fallback).
 *   3. validateGeneratedDisplays() raised no display_generation_incomplete flag
 *      for either generated plan.
 *
 * Restores the athlete's original pace_zones_visible value on exit.
 *
 * Run: php scripts/verify_pace_citation_liam.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Engine/TrainingLoad.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';

$db = Database::get();

// Archetypes that should cite a pace zone when zones are visible, and those
// (hill terrain) that should remain effort-only regardless of visibility.
$CITING = [
    'tempo_intervals', 'continuous_progression_tempo', 'high_volume_time_intervals',
    'equal_distance_repeats', 'short_speed_repeats', 'mixed_distance_repeats',
    'structured_fartlek_ladder', 'fast_finish_long',
];
$HILL = ['sustained_hill_repeats', 'hill_sprints', 'plyometric_hill_circuits'];

$liam = $db->query(
    "SELECT a.id AS athlete_id, u.name, ap.pace_zones, ap.pace_zones_visible, ap.pace_zones_source
     FROM athletes a
     JOIN users u ON u.id = a.user_id
     JOIN athlete_profiles ap ON ap.athlete_id = a.id
     WHERE u.name LIKE '%Liam%' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$liam) {
    fwrite(STDERR, "No athlete named Liam found.\n");
    exit(1);
}

$athleteId = (int)$liam['athlete_id'];
$original  = (int)$liam['pace_zones_visible'];
$populated = PaceZones::isPopulated($liam['pace_zones'] ?? null);

echo "Liam pace-citation verification\n";
echo "===============================\n";
echo "Athlete id={$athleteId}; pace_zones_source={$liam['pace_zones_source']}; "
   . "populated=" . ($populated ? 'yes' : 'NO') . "; original visible={$original}\n\n";

if (!$populated) {
    fwrite(STDERR, "Liam's pace_zones are empty — cannot verify the visible-ON path.\n");
    exit(1);
}

$setVisible = function (int $v) use ($db, $athleteId) {
    $db->prepare('UPDATE athlete_profiles SET pace_zones_visible = ? WHERE athlete_id = ?')
       ->execute([$v, $athleteId]);
};

$latestPlanId = function () use ($db, $athleteId): int {
    $s = $db->prepare('SELECT id FROM training_plans WHERE athlete_id = ? ORDER BY id DESC LIMIT 1');
    $s->execute([$athleteId]);
    return (int)$s->fetchColumn();
};

$qualityRows = function (int $planId) use ($db): array {
    $s = $db->prepare(
        "SELECT scheduled_date, archetype_code, archetype_variant, display_title, athlete_instructions
         FROM planned_workouts
         WHERE plan_id = ? AND archetype_code IS NOT NULL
         ORDER BY scheduled_date"
    );
    $s->execute([$planId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};

$flagCount = function (int $planId) use ($db, $athleteId): int {
    $s = $db->prepare(
        "SELECT COUNT(*) FROM engine_flags
         WHERE athlete_id = ? AND flag_type = 'display_generation_incomplete'
           AND JSON_EXTRACT(details, '$.plan_id') = ?"
    );
    try { $s->execute([$athleteId, $planId]); return (int)$s->fetchColumn(); }
    catch (Throwable $e) { return -1; }
};

$problems = [];

// ── Pass 1: visible ON ────────────────────────────────────────────────────
$setVisible(1);
PlanGenerator::generate($athleteId, 'verify_pace_on');
$onPlan = $latestPlanId();
echo "Visible ON  → plan {$onPlan}\n";
$citedSeen = 0;
foreach ($qualityRows($onPlan) as $r) {
    $code = $r['archetype_code'];
    $hasMile = str_contains((string)$r['athlete_instructions'], '/mile');
    $tag = '';
    if (in_array($code, $CITING, true)) {
        if ($hasMile) { $citedSeen++; $tag = ' [cited ✓]'; }
        else { $problems[] = "ON: {$code} on {$r['scheduled_date']} missing /mile citation"; $tag = ' [MISSING]'; }
    } elseif (in_array($code, $HILL, true) && $hasMile) {
        $problems[] = "ON: hill {$code} on {$r['scheduled_date']} unexpectedly cited pace"; $tag = ' [UNEXPECTED]';
    }
    if (in_array($code, $CITING, true) || in_array($code, $HILL, true)) {
        printf("  %-30s %-16s%s\n      %s\n", $code, $r['archetype_variant'] ?? '', $tag, $r['athlete_instructions']);
    }
}
$onFlags = $flagCount($onPlan);
echo "  cited quality sessions: {$citedSeen}; display_incomplete flags: {$onFlags}\n\n";
if ($onFlags > 0) $problems[] = "ON: {$onFlags} display_generation_incomplete flag(s)";

// ── Pass 2: visible OFF ───────────────────────────────────────────────────
$setVisible(0);
PlanGenerator::generate($athleteId, 'verify_pace_off');
$offPlan = $latestPlanId();
echo "Visible OFF → plan {$offPlan}\n";
$offCited = 0;
foreach ($qualityRows($offPlan) as $r) {
    if (str_contains((string)$r['athlete_instructions'], '/mile')) {
        $offCited++;
        $problems[] = "OFF: {$r['archetype_code']} on {$r['scheduled_date']} still cites pace";
    }
}
$offFlags = $flagCount($offPlan);
echo "  workouts citing pace (must be 0): {$offCited}; display_incomplete flags: {$offFlags}\n\n";
if ($offFlags > 0) $problems[] = "OFF: {$offFlags} display_generation_incomplete flag(s)";

// ── Restore + reconcile ────────────────────────────────────────────────────
// Pass 2 left the surviving plan in the visible-OFF state. Restore the athlete's
// original flag and regenerate once more so the live (pending) plan matches it.
$setVisible($original);
PlanGenerator::generate($athleteId, 'verify_pace_reconcile');
echo "Restored pace_zones_visible = {$original}; reconciled plan = " . $latestPlanId() . "\n\n";

if (empty($problems)) {
    echo "RESULT: PASS — citations present when visible, absent when hidden, no display flags.\n";
    exit(0);
}
echo "RESULT: FAIL\n";
foreach ($problems as $p) echo "  - {$p}\n";
exit(1);

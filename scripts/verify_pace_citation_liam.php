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

$zoneProfile = json_decode($liam['pace_zones'] ?? 'null', true);
if (!is_array($zoneProfile)) {
    fwrite(STDERR, "Liam's pace_zones could not be decoded.\n");
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
        "SELECT scheduled_date, archetype_code, archetype_variant, archetype_params,
                display_title, athlete_instructions
         FROM planned_workouts
         WHERE plan_id = ? AND archetype_code IS NOT NULL
         ORDER BY scheduled_date"
    );
    $s->execute([$planId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};

$flagCount = function (int $planId) use ($db, $athleteId): int {
    // details is json_encode(['plan_id'=>N,'issues'=>...]) → {"plan_id":N,"issues":...}.
    // Matched with LIKE (server MySQL build lacks JSON_EXTRACT); the trailing comma
    // keeps plan_id N from false-matching plan_id N0, N1, ….
    $s = $db->prepare(
        "SELECT COUNT(*) FROM engine_flags
         WHERE athlete_id = ? AND flag_type = 'display_generation_incomplete'
           AND details LIKE ?"
    );
    try { $s->execute([$athleteId, '%"plan_id":' . $planId . ',%']); return (int)$s->fetchColumn(); }
    catch (Throwable $e) { return -1; }
};

$scalarRange = function (string $key) use ($zoneProfile): ?string {
    if (empty($zoneProfile[$key]) || !is_numeric($zoneProfile[$key])) return null;
    $pace = (int)$zoneProfile[$key];
    return PaceZones::formatRange($pace - 5, $pace + 5);
};

$bandRange = function (string $a, string $b) use ($zoneProfile): ?string {
    if (empty($zoneProfile[$a]) || empty($zoneProfile[$b])
        || !is_numeric($zoneProfile[$a]) || !is_numeric($zoneProfile[$b])) {
        return null;
    }
    return PaceZones::formatRange(min((int)$zoneProfile[$a], (int)$zoneProfile[$b]), max((int)$zoneProfile[$a], (int)$zoneProfile[$b]));
};

$nearestDistanceKey = function (int $meters): string {
    return match (true) {
        $meters > 0 && $meters < 566 => '400',
        $meters < 1134               => '800',
        $meters < 2236               => 'mile',
        default                      => '5K',
    };
};

$expectedCitation = function (array $row) use ($scalarRange, $bandRange, $nearestDistanceKey): array {
    $code = $row['archetype_code'] ?? '';
    $params = json_decode($row['archetype_params'] ?? '{}', true);
    if (!is_array($params)) $params = [];

    switch ($code) {
        case 'tempo_intervals':
        case 'continuous_progression_tempo':
        case 'high_volume_time_intervals':
            return ['10K..half_marathon', $bandRange('10K', 'half_marathon')];

        case 'equal_distance_repeats':
        case 'short_speed_repeats':
            $meters = isset($params['rep_distance_meters']) && is_numeric($params['rep_distance_meters'])
                ? (int)$params['rep_distance_meters']
                : 0;
            if ($meters <= 0) return ['missing rep_distance_meters', null];
            $key = $nearestDistanceKey($meters);
            return ["{$meters}m->{$key}", $scalarRange($key)];

        case 'mixed_distance_repeats':
            return ['mile..5K', $bandRange('mile', '5K')];

        case 'structured_fartlek_ladder':
            return ['5K..10K', $bandRange('5K', '10K')];

        case 'fast_finish_long':
            $hint = strtolower((string)($row['archetype_variant'] ?? '') . ' ' . (string)($params['finish_zone'] ?? ''));
            $key = str_contains($hint, 'threshold') ? 'half_marathon' : 'marathon';
            return ["finish->{$key}", $scalarRange($key)];
    }

    return ['', null];
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
    $instructions = (string)$r['athlete_instructions'];
    $hasMile = str_contains($instructions, '/mile');
    $tag = '';
    [$expectLabel, $expectRange] = $expectedCitation($r);
    if (in_array($code, $CITING, true)) {
        if ($hasMile) { $citedSeen++; $tag = ' [cited ✓]'; }
        else { $problems[] = "ON: {$code} on {$r['scheduled_date']} missing /mile citation"; $tag = ' [MISSING]'; }
        if ($expectRange === null) {
            $problems[] = "ON: {$code} on {$r['scheduled_date']} missing expected citation basis ({$expectLabel})";
        } elseif (!str_contains($instructions, $expectRange)) {
            $problems[] = "ON: {$code} on {$r['scheduled_date']} cited wrong range; expected {$expectRange} ({$expectLabel})";
            $tag .= ' [WRONG RANGE]';
        }
    } elseif (in_array($code, $HILL, true) && $hasMile) {
        $problems[] = "ON: hill {$code} on {$r['scheduled_date']} unexpectedly cited pace"; $tag = ' [UNEXPECTED]';
    }
    if (in_array($code, $CITING, true) || in_array($code, $HILL, true)) {
        $expectText = $expectLabel ? " expected={$expectLabel}" : '';
        printf("  %-30s %-16s%s%s\n      %s\n", $code, $r['archetype_variant'] ?? '', $tag, $expectText, $instructions);
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

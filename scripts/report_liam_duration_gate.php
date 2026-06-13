<?php
/**
 * Report Liam-specific archetype minimum-duration gating and plan output.
 *
 * Usage:
 *   php scripts/report_liam_duration_gate.php [plan_id]
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';

$db = Database::get();
$settings = require __DIR__ . '/../config/engine_settings.php';
$fraction = (float)($settings['quality_min_duration_week_fraction'] ?? 0.40);
$selector = new ArchetypeSelector($db);

$liam = $db->query(
    "SELECT a.id AS athlete_id, u.name, ap.*
     FROM athletes a
     JOIN users u ON u.id = a.user_id
     JOIN athlete_profiles ap ON ap.athlete_id = a.id
     WHERE u.name LIKE '%Liam%'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$liam) {
    fwrite(STDERR, "No athlete named Liam found.\n");
    exit(1);
}

$athleteId = (int)$liam['athlete_id'];
$planId = isset($argv[1]) ? (int)$argv[1] : 0;
if (!$planId) {
    $stmt = $db->prepare(
        "SELECT id FROM training_plans
         WHERE athlete_id = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([$athleteId]);
    $planId = (int)$stmt->fetchColumn();
}

$plan = null;
if ($planId) {
    $stmt = $db->prepare('SELECT * FROM training_plans WHERE id = ? LIMIT 1');
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$goalDistance = normalizeDistance($liam['goal_race_distance'] ?? '5K');
$classification = classifyProfile($liam, $goalDistance);
$planType = $liam['plan_type'] ?? ($plan['plan_type'] ?? 'development_plan');
$constraints = buildConstraints($liam);
$weeks = weeklyMinutesForProfile($liam, $planType);

echo "Liam duration gate report\n";
echo "=========================\n";
echo "Athlete: {$liam['name']} (athlete_id={$athleteId})\n";
echo "Plan id: " . ($planId ?: 'none') . "\n";
echo "Plan type: {$planType}; classification: {$classification}; goal distance: {$goalDistance}\n";
echo "Threshold: min_session_duration > weekly_minutes x {$fraction}\n\n";

$rows = $db->query('SELECT * FROM workout_archetypes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$archetypes = array_map('decodeArchetype', $rows);

printf("%-34s | %-8s | %-12s | %s\n", 'archetype_code', 'min_dur', 'eligible?', 'excluded weeks');
echo str_repeat('-', 86) . "\n";

$excludedByCode = [];
foreach ($archetypes as $a) {
    $min = $selector->getMinimumSessionDurationMinutes($a, $classification, 'base', $goalDistance);
    $baseEligible = isQualityEligibleWithoutDuration($selector, $a['code'], $goalDistance, $classification, $planType, $constraints);
    $excludedWeeks = [];

    if ($baseEligible && $min !== null) {
        foreach ($weeks as $week => $mins) {
            if ($min > $mins * $fraction) {
                $excludedWeeks[] = "W{$week} ({$mins}m, cap " . round($mins * $fraction, 1) . 'm)';
            }
        }
    }

    if (!empty($excludedWeeks)) {
        $excludedByCode[$a['code']] = $excludedWeeks;
    }

    printf(
        "%-34s | %-8s | %-12s | %s\n",
        $a['code'],
        $min === null ? 'n/a' : rtrim(rtrim(number_format($min, 1), '0'), '.'),
        $baseEligible ? 'yes' : 'no',
        empty($excludedWeeks) ? '-' : implode('; ', $excludedWeeks)
    );
}

echo "\nExcluded quality archetypes for Liam by generated week:\n";
if (empty($excludedByCode)) {
    echo "  None.\n";
} else {
    foreach ($excludedByCode as $code => $exclusions) {
        echo "  {$code}: " . implode('; ', $exclusions) . "\n";
    }
}

if ($planId) {
    echo "\nQuality-session archetypes in plan {$planId}:\n";
    $stmt = $db->prepare(
        "SELECT scheduled_date, workout_type, archetype_code, archetype_variant,
                display_title, target_duration, archetype_params
         FROM planned_workouts
         WHERE plan_id = ?
           AND workout_type IN ('interval','tempo','hill','fartlek','speed')
         ORDER BY scheduled_date"
    );
    $stmt->execute([$planId]);
    $quality = $stmt->fetchAll(PDO::FETCH_ASSOC);

    printf("%-12s | %-28s | %-22s | %-4s | %s\n", 'date', 'archetype', 'variant', 'dur', 'title');
    echo str_repeat('-', 96) . "\n";
    foreach ($quality as $w) {
        $params = json_decode($w['archetype_params'] ?? '{}', true) ?? [];
        printf(
            "%-12s | %-28s | %-22s | %-4d | %s\n",
            $w['scheduled_date'],
            $w['archetype_code'] ?? '-',
            $w['archetype_variant'] ?? '-',
            (int)$w['target_duration'],
            $w['display_title'] ?? ''
        );
    }

    $minimumViolations = countMinimumViableViolations($db, $planId, $archetypes);
    echo "\nMinimum viable quality structure violations: {$minimumViolations}\n";

    echo "\nDuration consistency:\n";
    $issues = countDurationMismatches($db, $planId);
    echo "  target_duration/sum-of-parts mismatches: {$issues}\n";
}

function decodeArchetype(array $row): array
{
    foreach ([
        'mapped_templates', 'selection', 'weights', 'generation', 'variants',
        'parameters', 'structure_template', 'display', 'instance_signature', 'coach_notes',
    ] as $field) {
        if (isset($row[$field]) && is_string($row[$field])) {
            $row[$field] = json_decode($row[$field], true) ?? $row[$field];
        }
    }
    return $row;
}

function isQualityEligibleWithoutDuration(
    ArchetypeSelector $selector, string $code, string $goalDistance, string $classification,
    string $planType, array $constraints
): bool {
    foreach (['quality_primary', 'quality_secondary'] as $slot) {
        $eligible = $selector->getEligible($slot, 'base', $goalDistance, $classification, $planType, $constraints);
        foreach ($eligible as $candidate) {
            if (($candidate['code'] ?? '') === $code) return true;
        }
    }
    return false;
}

function weeklyMinutesForProfile(array $profile, string $planType): array
{
    $current = max(60, (int)($profile['current_weekly_minutes'] ?? 120));
    $peak = max($current, (int)($profile['peak_volume_ceiling_mins'] ?? round($current * 1.4)));

    if ($planType !== 'development_plan') {
        return [1 => $current];
    }

    $weeks = [];
    $prev = $current;
    for ($week = 1; $week <= 12; $week++) {
        $isCutback = ($week > 1 && $week % 4 === 0);
        $weekly = $isCutback
            ? max(30, (int)round($prev * 0.75))
            : min((int)round($prev * 1.08), $peak);
        $weeks[$week] = $weekly;
        $prev = $weekly;
    }
    return $weeks;
}

function buildConstraints(array $profile): array
{
    $hillAccess = !empty($profile['hill_access']) && $profile['hill_access'] !== 'none';
    $trackBg = ($profile['track_field_background'] ?? '') === 'yes';

    return [
        'track_access' => $trackBg ? 'yes' : 'no',
        'hill_access' => $hillAccess,
        'plyometric_clearance' => !empty($profile['plyometric_clearance']),
        'hilly_terrain_or_substitute_route' => $hillAccess,
        'short_steep_hill_or_safe_substitute' => $hillAccess,
        'excludes' => [],
    ];
}

function normalizeDistance(string $distance): string
{
    $d = strtolower(trim($distance));
    return match($d) {
        'half marathon', 'half', 'hm' => 'half',
        'marathon' => 'marathon',
        '10k' => '10K',
        '5k' => '5K',
        default => '5K',
    };
}

function classifyProfile(array $profile, string $distance): string
{
    $thresholds = [
        '5K' => [
            'well_trained' => ['runs_per_week' => 4, 'weekly_minutes' => 180, 'long_run_minutes' => 60],
            'workable' => ['runs_per_week' => 3, 'weekly_minutes' => 120, 'long_run_minutes' => 45],
        ],
        '10K' => [
            'well_trained' => ['runs_per_week' => 4, 'weekly_minutes' => 210, 'long_run_minutes' => 70],
            'workable' => ['runs_per_week' => 3, 'weekly_minutes' => 150, 'long_run_minutes' => 50],
        ],
        'half' => [
            'well_trained' => ['runs_per_week' => 5, 'weekly_minutes' => 270, 'long_run_minutes' => 90],
            'workable' => ['runs_per_week' => 4, 'weekly_minutes' => 180, 'long_run_minutes' => 60],
        ],
        'marathon' => [
            'well_trained' => ['runs_per_week' => 5, 'weekly_minutes' => 360, 'long_run_minutes' => 105],
            'workable' => ['runs_per_week' => 4, 'weekly_minutes' => 240, 'long_run_minutes' => 75],
        ],
    ];

    $runs = (int)($profile['training_days_per_week'] ?? 0);
    $weekly = (int)($profile['current_weekly_minutes'] ?? 0);
    $long = (int)($profile['longest_recent_run_mins'] ?? 0);
    $set = $thresholds[$distance] ?? $thresholds['5K'];

    foreach (['well_trained', 'workable'] as $class) {
        $t = $set[$class];
        if ($runs >= $t['runs_per_week'] && $weekly >= $t['weekly_minutes'] && $long >= $t['long_run_minutes']) {
            return $class;
        }
    }
    return 'insufficient';
}

function countDurationMismatches(PDO $db, int $planId): int
{
    $stmt = $db->prepare(
        "SELECT archetype_code, target_duration, archetype_params
         FROM planned_workouts
         WHERE plan_id = ? AND archetype_code IS NOT NULL"
    );
    $stmt->execute([$planId]);
    $issues = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $params = json_decode($r['archetype_params'] ?? '{}', true) ?? [];
        $warmup = (int)($params['warmup_minutes'] ?? 0);
        $cooldown = (int)($params['cooldown_minutes'] ?? 0);
        if ($warmup + $cooldown === 0) continue;

        $main = match ($r['archetype_code']) {
            'tempo_intervals' =>
                (int)($params['rep_count'] ?? 0) * (float)($params['rep_duration_minutes'] ?? 0),
            'high_volume_time_intervals' =>
                (int)($params['rep_count'] ?? 0)
                    * ((int)($params['work_duration_seconds'] ?? 0) + (int)($params['recovery_duration_seconds'] ?? 0))
                    / 60.0,
            'sustained_hill_repeats' =>
                (int)($params['rep_count'] ?? 0) * (int)($params['rep_duration_seconds'] ?? 0) * 2 / 60.0,
            'hill_sprints' =>
                (int)($params['sprint_count'] ?? 0) * ((int)($params['sprint_duration_seconds'] ?? 0) + 90) / 60.0,
            'structured_fartlek_ladder' =>
                !empty($params['work_intervals_seconds'])
                    ? (int)($params['round_count'] ?? 1) * 2 * array_sum((array)$params['work_intervals_seconds']) / 60.0
                    : null,
            'continuous_progression_tempo' =>
                (float)($params['continuous_work_minutes'] ?? 0),
            default => null,
        };

        if ($main === null) continue;
        if ((int)round($warmup + $main + $cooldown) !== (int)$r['target_duration']) {
            $issues++;
        }
    }

    return $issues;
}

function countMinimumViableViolations(PDO $db, int $planId, array $archetypes): int
{
    $minimums = [];
    foreach ($archetypes as $a) {
        $code = $a['code'] ?? '';
        $params = $a['generation']['minimum_viable_params'] ?? null;
        if ($code && is_array($params)) {
            $minimums[$code] = $params;
        }
    }

    $fallbacks = [
        'sustained_hill_repeats' => ['rep_count' => 3],
        'hill_sprints' => ['sprint_count' => 4],
        'tempo_intervals' => ['rep_count' => 2],
        'continuous_progression_tempo' => ['continuous_work_minutes' => 15],
        'equal_distance_repeats' => ['rep_count' => 3],
        'high_volume_time_intervals' => ['rep_count' => 6],
        'structured_fartlek_ladder' => ['round_count' => 1],
    ];
    $minimums += $fallbacks;

    $stmt = $db->prepare(
        "SELECT scheduled_date, archetype_code, archetype_params
         FROM planned_workouts
         WHERE plan_id = ? AND archetype_code IS NOT NULL"
    );
    $stmt->execute([$planId]);

    $violations = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = $row['archetype_code'] ?? '';
        if (!isset($minimums[$code])) continue;

        $params = json_decode($row['archetype_params'] ?? '{}', true) ?? [];
        foreach ($minimums[$code] as $key => $minimum) {
            if ((float)($params[$key] ?? 0) < (float)$minimum) {
                $violations++;
                echo "  violation: {$row['scheduled_date']} {$code} {$key}="
                    . ($params[$key] ?? 'null') . " < {$minimum}\n";
            }
        }
    }

    return $violations;
}

<?php
/**
 * Systematic audit: resolve and render all 17 archetypes.
 * Reports: code, display_title, athlete_instructions (100-char preview), range present (Y/N).
 * Run from server: php scripts/test_archetype_render.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';

$pdo      = Database::get();
$selector = new ArchetypeSelector($pdo);

$addDerived = new ReflectionMethod(PlanGenerator::class, 'addDerivedParams');
$addDerived->setAccessible(true);

$renderTpl = new ReflectionMethod(PlanGenerator::class, 'renderTemplate');
$renderTpl->setAccessible(true);

$codes = [
    'continuous_easy', 'easy_with_strides', 'continuous_long',
    'progression_long', 'goal_pace_long_segments', 'fast_finish_long',
    'long_run_with_pickups', 'structured_fartlek_ladder',
    'equal_distance_repeats', 'mixed_distance_repeats',
    'high_volume_time_intervals', 'short_speed_repeats',
    'sustained_hill_repeats', 'hill_sprints', 'plyometric_hill_circuits',
    'tempo_intervals', 'continuous_progression_tempo',
];

// Test context: build phase, 10K, workable athlete, 45 min quality session
$classification = 'workable';
$phase          = 'build';
$goalDistance   = '10K';
$targetMinutes  = 45;

$divider = str_repeat('─', 120);

printf("\nAll-17 archetype render audit — context: %s / %s / %s / %d min\n\n",
    $classification, $goalDistance, $phase, $targetMinutes);

$rows = [];
foreach ($codes as $code) {
    $arch = $selector->getByCode($code);
    if (!$arch) {
        $rows[] = [$code, 'NOT FOUND', '—', 'N/A'];
        continue;
    }

    $arch = $selector->resolveParameters($arch, $classification);
    $arch = $addDerived->invoke(null, $arch, $targetMinutes, $phase, $goalDistance, $classification);

    $display  = $arch['display'] ?? [];
    $title    = $renderTpl->invoke(null, $display['title_template']       ?? '', $arch);
    $summary  = $renderTpl->invoke(null, $display['summary_template']     ?? '', $arch);
    $instruct = $renderTpl->invoke(null, $display['description_template'] ?? '', $arch);

    // Range is the part after · in the summary (distance_range or time_range token)
    $range = '';
    if (str_contains($summary, '·')) {
        $range = trim(substr($summary, strpos($summary, '·') + 2));
    } elseif ($summary !== '') {
        $range = $summary;
    }

    // Y if the range token resolved to a non-empty, non-placeholder value
    $rangeOk = ($range !== '' && !str_contains($range, '{{')) ? 'Y' : 'N';

    $rows[] = [
        $code,
        mb_substr($title, 0, 36),
        mb_substr($instruct, 0, 100),
        $rangeOk,
    ];
}

// Print table
echo $divider . "\n";
printf("%-33s | %-36s | %-100s | %s\n", 'code', 'display_title', 'athlete_instructions (~100 chars)', 'range');
echo $divider . "\n";
foreach ($rows as [$code, $title, $instr, $rangeOk]) {
    printf("%-33s | %-36s | %-100s | %s\n", $code, $title, $instr, $rangeOk);
}
echo $divider . "\n";
echo "\nY = distance_range or time_range token rendered with a value.\n";
echo "Archetypes with lead_with=duration display distance_range; distance-based display time_range.\n";
echo "continuous_long/progression_long/goal_pace_long/fast_finish_long are time-based; range Y = distance_range present.\n";

<?php
/**
 * Spot-check: resolve and render all 17 archetypes, report title + distance/time range.
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

printf("%-35s | %-38s | %-20s\n", 'archetype', 'display_title', 'range');
printf("%-35s-+-%-38s-+-%-20s\n", str_repeat('-', 35), str_repeat('-', 38), str_repeat('-', 20));

foreach ($codes as $code) {
    $arch = $selector->getByCode($code);
    if (!$arch) {
        printf("%-35s | NOT FOUND\n", $code);
        continue;
    }

    $arch = $selector->resolveParameters($arch, $classification);
    $arch = $addDerived->invoke(null, $arch, $targetMinutes, $phase, $goalDistance, $classification);

    $display = $arch['display'] ?? [];
    $title   = $renderTpl->invoke(null, $display['title_template']   ?? '', $arch);
    $summary = $renderTpl->invoke(null, $display['summary_template'] ?? '', $arch);

    // Pull just the range portion (after ·) or use full summary if no ·
    $range = str_contains($summary, '·') ? trim(substr($summary, strpos($summary, '·') + 2)) : $summary;

    printf("%-35s | %-38s | %-20s\n", $code, mb_substr($title, 0, 38), $range);
}

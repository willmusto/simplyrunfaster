<?php
/**
 * Deterministic, DB-free unit test for the pace-zone citation logic
 * (engine spec §19 item 14). Exercises PaceZones::qualityCitation, the
 * distance→key mapping, the band/scalar range formatting, and the
 * byte-identity property of the append (effort text untouched when hidden).
 *
 * Run: php scripts/test_pace_citation.php
 */

require_once __DIR__ . '/../src/Engine/PaceZones.php';

$pass = 0;
$fail = 0;

function check(string $label, $got, $want): void
{
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}\n";
        echo "        got:  " . var_export($got, true) . "\n";
        echo "        want: " . var_export($want, true) . "\n";
    }
}

// Representative easy_pace_estimate zones (seconds per mile).
$zones = [
    'source' => 'easy_pace_estimate', 'generated_at' => '2026-06-14',
    'easy' => ['min' => 540, 'max' => 600], 'long' => ['min' => 540, 'max' => 600],
    'marathon' => 480, 'half_marathon' => 458, '10K' => 432,
    '5K' => 414, 'mile' => 372, '800' => 354, '400' => 342,
];

echo "Formatters\n";
check('formatPace(432)', PaceZones::formatPace(432), '7:12/mile');
check('formatRange(432,458)', PaceZones::formatRange(432, 458), '7:12–7:38/mile');

echo "\nThreshold/tempo band (10K..half_marathon)\n";
foreach (['tempo_intervals', 'continuous_progression_tempo', 'high_volume_time_intervals'] as $code) {
    check($code, PaceZones::qualityCitation($code, [], $zones),
        'Target roughly 7:12–7:38/mile on the tempo work.');
}

echo "\nDistance repeats → nearest key, ±5s\n";
check('equal_distance_repeats 800m',
    PaceZones::qualityCitation('equal_distance_repeats', ['rep_distance_meters' => 800], $zones),
    'Aim for around 5:49–5:59/mile on the reps.');           // 800 → 354 ±5
check('equal_distance_repeats 1000m → 800 key',
    PaceZones::qualityCitation('equal_distance_repeats', ['rep_distance_meters' => 1000], $zones),
    'Aim for around 5:49–5:59/mile on the reps.');           // 1000 < 1134 → 800
check('equal_distance_repeats 1200m → mile key',
    PaceZones::qualityCitation('equal_distance_repeats', ['rep_distance_meters' => 1200], $zones),
    'Aim for around 6:07–6:17/mile on the reps.');           // 1200 → mile 372 ±5
check('short_speed_repeats 200m → 400 key',
    PaceZones::qualityCitation('short_speed_repeats', ['rep_distance_meters' => 200], $zones),
    'Aim for around 5:37–5:47/mile on the reps.');           // 200 → 400 342 ±5

echo "\nMixed reps band (mile..5K)\n";
check('mixed_distance_repeats',
    PaceZones::qualityCitation('mixed_distance_repeats', [], $zones),
    'Aim for around 6:12–6:54/mile across the mixed reps.');

echo "\nFartlek ladder band (5K..10K)\n";
check('structured_fartlek_ladder',
    PaceZones::qualityCitation('structured_fartlek_ladder', [], $zones),
    'On the faster efforts, aim for around 6:54–7:12/mile.');

echo "\nFast-finish long — finish segment by variant\n";
check('fast_finish_long threshold_finish → half_marathon',
    PaceZones::qualityCitation('fast_finish_long', [], $zones, 'threshold_finish'),
    'Run the closing segment at around 7:33–7:43/mile.');    // half_marathon 458 ±5
check('fast_finish_long marathon_finish → marathon',
    PaceZones::qualityCitation('fast_finish_long', [], $zones, 'marathon_finish'),
    'Run the closing segment at around 7:55–8:05/mile.');    // marathon 480 ±5
check('fast_finish_long steady_finish → marathon',
    PaceZones::qualityCitation('fast_finish_long', [], $zones, 'steady_finish'),
    'Run the closing segment at around 7:55–8:05/mile.');

echo "\nHill terrain & out-of-scope → effort-only (null)\n";
foreach (['sustained_hill_repeats', 'hill_sprints', 'plyometric_hill_circuits',
          'continuous_easy', 'continuous_long', 'progression_long',
          'goal_pace_long_segments', 'long_run_with_pickups', 'easy_with_strides'] as $code) {
    check($code, PaceZones::qualityCitation($code, ['rep_distance_meters' => 400], $zones), null);
}

echo "\nHidden/empty zones → null (effort-only fallback)\n";
check('null zones', PaceZones::qualityCitation('tempo_intervals', [], null), null);
check('empty zones', PaceZones::qualityCitation('tempo_intervals', [], []), null);

echo "\nByte-identity of the append (effort text untouched)\n";
// Mirrors PlanGenerator::appendPaceCitation: null → unchanged; else effort + ' ' + clause.
$effort = 'Run each tempo segment at a comfortably hard effort.';
$append = function (string $text, ?array $z) use ($effort) {
    $clause = PaceZones::qualityCitation('tempo_intervals', [], $z);
    if ($clause === null || $clause === '') return $text;
    return rtrim($text) . ' ' . $clause;
};
check('hidden leaves effort byte-identical', $append($effort, null), $effort);
check('visible appends and preserves prefix',
    $append($effort, $zones),
    $effort . ' Target roughly 7:12–7:38/mile on the tempo work.');

echo "\n=====================================\n";
echo ($fail === 0 ? "ALL PASS" : "FAILURES: {$fail}") . "  ({$pass} passed, {$fail} failed)\n";
exit($fail === 0 ? 0 : 1);

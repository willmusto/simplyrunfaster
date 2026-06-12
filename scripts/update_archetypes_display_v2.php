<?php
/**
 * v2 migration: fix display templates for 8 archetypes still producing generic athlete_instructions.
 * Complements update_archetypes_display.php (which covered 4 archetypes).
 *
 * Archetypes fixed:
 *   structured_fartlek_ladder  — ladder sequence + round_count + warmup/cooldown in description
 *   equal_distance_repeats     — rep_count × distance × effort in description; default effort added
 *   high_volume_time_intervals — rep_count × work/recovery duration in description
 *   sustained_hill_repeats     — rep_count × rep_duration in description
 *   hill_sprints               — sprint_count added to description (already had sprint_duration)
 *   tempo_intervals            — rep_count × rep_duration in description
 *   fast_finish_long           — finish_segment_percent in description
 *   easy_with_strides          — stride_duration_seconds added to description (already had count)
 *
 * Run from project root: php scripts/update_archetypes_display_v2.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = Database::get();

$updates = [

    'structured_fartlek_ladder' => [
        'display_patch' => function (array $display): array {
            $display['description_template'] = '{{round_count}} × {{fartlek_ladder_sequence}} fartlek ladder with equal easy recovery. Run longer reps at 10K effort; shorter reps a little quicker, toward 5K or mile effort. Warm up {{warmup_minutes}} min · cool down {{cooldown_minutes}} min.';
            return $display;
        },
    ],

    'equal_distance_repeats' => [
        'parameters_patch' => function (array $params): array {
            // target_effort has no default; add one so the description token resolves
            $params['target_effort']['default'] = '10K';
            return $params;
        },
        'display_patch' => function (array $display): array {
            $display['description_template'] = '{{rep_count}} × {{rep_distance_meters}}m at {{target_effort}} effort. Run each rep at a controlled, even pace from first to last. Warm up {{warmup_minutes}} min with strides · cool down {{cooldown_minutes}} min.';
            return $display;
        },
    ],

    'high_volume_time_intervals' => [
        'display_patch' => function (array $display): array {
            $display['description_template'] = '{{rep_count}} × {{work_duration_seconds}} sec on / {{recovery_duration_seconds}} sec off at threshold effort. Run the on segments controlled and hard; keep recoveries easy. The cumulative volume is the point. Warm up {{warmup_minutes}} min · cool down {{cooldown_minutes}} min.';
            return $display;
        },
    ],

    'sustained_hill_repeats' => [
        'display_patch' => function (array $display): array {
            $display['description_template'] = '{{rep_count}} × {{rep_duration_seconds}} sec uphill on a runnable 4–8% hill. Drive up at the assigned effort, jog back easy, and recover fully before the next rep. Optional 45–90 sec standing rest at quarter, halfway, and three-quarter marks. Warm up {{warmup_minutes}} min · cool down {{cooldown_minutes}} min.';
            return $display;
        },
    ],

    'hill_sprints' => [
        'display_patch' => function (array $display): array {
            $display['description_template'] = '{{sprint_count}} × {{sprint_duration_seconds}} sec uphill sprints on a short, steep hill. Sprint hard but controlled, walk back, recover fully before the next one. Power and mechanics — not getting tired. Warm up {{warmup_minutes}} min · cool down {{cooldown_minutes}} min.';
            return $display;
        },
    ],

    'tempo_intervals' => [
        'display_patch' => function (array $display): array {
            $display['description_template'] = '{{rep_count}} × {{rep_duration_minutes}} min tempo reps at comfortably hard effort — the pace you could sustain for roughly an hour of racing. Recover easily between segments and focus on rhythm and consistency. Warm up {{warmup_minutes}} min with strides · cool down {{cooldown_minutes}} min.';
            return $display;
        },
    ],

    'fast_finish_long' => [
        'display_patch' => function (array $display): array {
            $display['description_template'] = 'Run aerobically for most of the run, then close the final {{finish_segment_percent}}% at {{finish_zone}} effort. Finish controlled and efficient rather than all-out.';
            return $display;
        },
    ],

    'easy_with_strides' => [
        'display_patch' => function (array $display): array {
            $display['description_template'] = 'Run easily throughout, then finish with {{stride_count}} × {{stride_duration_seconds}} sec relaxed strides. Accelerate smoothly, stay controlled, and focus on quick, efficient running rather than sprinting.';
            return $display;
        },
    ],

];

$fetchStmt  = $pdo->prepare('SELECT display, parameters FROM workout_archetypes WHERE code = :code LIMIT 1');
$updateStmt = $pdo->prepare(
    'UPDATE workout_archetypes SET display = :display, parameters = :parameters WHERE code = :code'
);

foreach ($updates as $code => $patches) {
    $fetchStmt->execute([':code' => $code]);
    $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo "WARNING: archetype '{$code}' not found — skipping.\n";
        continue;
    }

    $display    = json_decode($row['display'],    true) ?? [];
    $parameters = json_decode($row['parameters'], true) ?? [];

    if (isset($patches['display_patch'])) {
        $display = ($patches['display_patch'])($display);
    }
    if (isset($patches['parameters_patch'])) {
        $parameters = ($patches['parameters_patch'])($parameters);
    }

    $updateStmt->execute([
        ':display'    => json_encode($display,    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':parameters' => json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':code'       => $code,
    ]);

    echo "Updated: {$code}\n";
}

echo "Done.\n";

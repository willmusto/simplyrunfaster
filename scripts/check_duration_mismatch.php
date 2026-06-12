<?php
/**
 * Diagnose target_duration vs expected sum-of-parts for structured workouts in a plan.
 * Usage: php scripts/check_duration_mismatch.php [plan_id]
 * Defaults to the most recent plan if plan_id is omitted.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo    = Database::get();
$planId = isset($argv[1]) ? (int)$argv[1] : 0;

if (!$planId) {
    $planId = (int)$pdo->query('SELECT MAX(id) FROM training_plans')->fetchColumn();
}

echo "Plan id={$planId}\n\n";

$rows = $pdo->prepare(
    "SELECT archetype_code, scheduled_date, target_duration, archetype_params
     FROM planned_workouts
     WHERE plan_id = ?
       AND archetype_code IS NOT NULL
     ORDER BY scheduled_date"
);
$rows->execute([$planId]);

$issues = 0;
printf("%-34s | %-12s | %-10s | %-10s | %s\n",
    'archetype_code', 'date', 'stored_dur', 'sum_parts', 'match?');
echo str_repeat('─', 88) . "\n";

foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $params   = json_decode($r['archetype_params'] ?? '{}', true) ?? [];
    $warmup   = (int)($params['warmup_minutes']   ?? 0);
    $cooldown = (int)($params['cooldown_minutes'] ?? 0);

    $main = null;
    if ($warmup + $cooldown > 0) {
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
    }

    $sum    = ($main !== null) ? (int)round($warmup + $main + $cooldown) : null;
    $stored = (int)$r['target_duration'];
    $match  = ($sum === null) ? 'N/A' : ($sum === $stored ? 'OK' : 'MISMATCH');
    if ($match === 'MISMATCH') $issues++;

    printf("%-34s | %-12s | %-10d | %-10s | %s\n",
        $r['archetype_code'],
        $r['scheduled_date'],
        $stored,
        $sum ?? '—',
        $match
    );
}

echo "\n";
if ($issues > 0) {
    echo "{$issues} mismatch(es) found.\n";
} else {
    echo "All structured workouts: target_duration matches sum-of-parts.\n";
}

<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$pdo  = Database::get();
$rows = $pdo->query(
    "SELECT scheduled_date, archetype_code, target_duration, archetype_params
     FROM planned_workouts
     WHERE plan_id = 16
       AND archetype_code IN ('structured_fartlek_ladder','tempo_intervals','sustained_hill_repeats','continuous_progression_tempo','hill_sprints','high_volume_time_intervals')
     ORDER BY scheduled_date"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $p = json_decode($r['archetype_params'], true);
    $extra = array_filter(array_intersect_key($p, array_flip([
        'round_count','fartlek_ladder_sequence',
        'rep_count','rep_duration_minutes',
        'continuous_work_minutes',
        'rep_duration_seconds','sprint_count','sprint_duration_seconds',
        'work_duration_seconds','recovery_duration_seconds',
    ])), fn($v) => $v !== null);
    printf("%s | %-32s | dur=%-3d | wu=%-3s cd=%-3s | %s\n",
        $r['scheduled_date'], $r['archetype_code'], $r['target_duration'],
        $p['warmup_minutes'] ?? '?', $p['cooldown_minutes'] ?? '?',
        json_encode($extra)
    );
}

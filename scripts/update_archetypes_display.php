<?php
/**
 * One-time migration: fix display templates for 4 archetypes with generic titles/descriptions.
 * Run from project root: php scripts/update_archetypes_display.php
 *
 * Archetypes fixed:
 *   short_speed_repeats        — parameterised title + description
 *   continuous_progression_tempo — instance-specific title + description
 *   mixed_distance_repeats     — variant_name title + quality_volume description
 *   plyometric_hill_circuits   — circuit_count + sprint_duration in description
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = Database::get();

$updates = [

    'short_speed_repeats' => [
        'parameters_patch' => function (array $params): array {
            $params['rep_distance_meters']['default'] = 200;
            return $params;
        },
        'display_patch' => function (array $display): array {
            $display['title_template']       = '{{rep_count}} × {{rep_distance_meters}}m';
            $display['description_template'] = '{{rep_count}} × {{rep_distance_meters}}m at near-sprint effort. Run each rep fast and controlled — powerful but not falling apart. Take full walk-back recovery between reps to preserve speed and mechanics on every one.';
            return $display;
        },
    ],

    'continuous_progression_tempo' => [
        'display_patch' => function (array $display): array {
            $display['title_template']       = '{{continuous_work_minutes}} min Progression Tempo';
            $display['description_template'] = '{{continuous_work_minutes}} minutes of continuous tempo with no recovery breaks. Let the effort build naturally and focus on rhythm, control, and smooth transitions between gears.';
            return $display;
        },
    ],

    'mixed_distance_repeats' => [
        'display_patch' => function (array $display): array {
            $display['title_template']       = '{{variant_name}}';
            $display['description_template'] = '{{quality_volume_meters}}m of mixed-distance intervals combining different repeat lengths and speeds. Stay controlled early and maintain quality throughout.';
            return $display;
        },
    ],

    'plyometric_hill_circuits' => [
        'display_patch' => function (array $display): array {
            $display['description_template'] = 'Complete {{circuit_count}} circuits, each with a {{hill_sprint_duration_seconds}}-second uphill sprint followed by plyometric drills. Focus on coordination, stiffness, rhythm, and quality movement. Recover fully between circuits.';
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

<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo   = Database::get();
$codes = ['short_speed_repeats', 'continuous_progression_tempo', 'mixed_distance_repeats', 'plyometric_hill_circuits'];

foreach ($codes as $code) {
    $r = $pdo->query("SELECT display, parameters FROM workout_archetypes WHERE code='$code' LIMIT 1")
             ->fetch(PDO::FETCH_ASSOC);
    $d = json_decode($r['display'], true);
    $p = json_decode($r['parameters'], true);
    echo "$code:\n";
    echo "  title:   " . $d['title_template'] . "\n";
    echo "  desc:    " . substr($d['description_template'], 0, 80) . "\n";
    if ($code === 'short_speed_repeats') {
        echo "  rep_dist_default: " . ($p['rep_distance_meters']['default'] ?? 'MISSING') . "\n";
    }
}

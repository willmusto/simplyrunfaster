<?php
/**
 * Migration: fix the structured_fartlek_ladder (standard variants) description_template.
 *
 * The archetype-descriptions refresh replaced this archetype's template with generic prose
 * that DROPPED {{round_count}} and {{fartlek_ladder_sequence}} (the actual ladder, e.g.
 * "3 x 90-60-30 sec"). Neither is surfaced elsewhere (title is just the variant name,
 * summary is duration/distance), so the athlete silently lost the ladder. No validator flag
 * (warm/cool + a digit come from the warmup wrapper). The engine still computes both params.
 *
 * This restores them, keeping the good new coaching prose. The DIMINISHING variant is
 * unaffected: addDerivedParams overrides its description_template in code, which wins over
 * this DB template.
 *
 * Reads the restored template from database/seeds/archetype_descriptions.json (source of
 * truth, already updated for fresh installs) and writes display.description_template only.
 * Idempotent. TEMPLATE-ONLY (does not touch any planned_workouts row).
 *
 * Run from /home/private/app: php scripts/migrate_fartlek_description_fix.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

const CODE = 'structured_fartlek_ladder';
$pdo = Database::get();

$jsonPath = __DIR__ . '/../database/seeds/archetype_descriptions.json';
$entries  = json_decode((string)file_get_contents($jsonPath), true);
if (!is_array($entries)) { fwrite(STDERR, "Could not read $jsonPath\n"); exit(1); }

$template = null;
foreach ($entries as $e) {
    if (($e['code'] ?? null) === CODE) { $template = (string)($e['description_template'] ?? ''); break; }
}
if ($template === null || $template === '') { fwrite(STDERR, "No description_template for " . CODE . " in seeds\n"); exit(1); }

foreach (['{{round_count}}', '{{fartlek_ladder_sequence}}'] as $needle) {
    if (strpos($template, $needle) === false) { fwrite(STDERR, "Restored template missing $needle — aborting\n"); exit(1); }
}

$sel = $pdo->prepare('SELECT display FROM workout_archetypes WHERE code = ? LIMIT 1');
$sel->execute([CODE]);
$row = $sel->fetch(PDO::FETCH_ASSOC);
if (!$row) { fwrite(STDERR, "Archetype " . CODE . " not found\n"); exit(1); }

$display = json_decode((string)$row['display'], true);
if (!is_array($display)) $display = [];
$before = $display['description_template'] ?? '';
$display['description_template'] = $template;

$pdo->prepare('UPDATE workout_archetypes SET display = ? WHERE code = ?')
    ->execute([json_encode($display, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), CODE]);

echo ($before === $template ? "Already current" : "Updated") . " description_template for " . CODE . ".\n";
echo "Now: " . mb_substr($template, 0, 120) . "...\n";

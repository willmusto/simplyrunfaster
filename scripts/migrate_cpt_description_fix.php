<?php
/**
 * Migration: fix the continuous_progression_tempo description_template.
 *
 * The archetype-descriptions refresh (migrate_archetype_descriptions.php) replaced this
 * archetype's template with generic prose that DROPPED (a) the {{progression_instruction}}
 * token (the actual "first N easy / middle N moderate / final N tempo" breakdown) and
 * (b) the "Warm up ... / Cool down ..." wrapper. But continuous_progression_tempo stays on
 * the wrapWithWarmupCooldown EXCLUSION list (it is meant to self-supply warm/cool), so the
 * result had no warm/cool text (tripping display_generation_incomplete) and lost the
 * progression detail. This restores both, matching the token names the render path uses
 * (warmup_minutes / cooldown_minutes / progression_instruction).
 *
 * Reads the restored template from database/seeds/archetype_descriptions.json (single source
 * of truth, already updated for fresh installs) and writes display.description_template only.
 * All other archetype data is preserved. Idempotent: re-running sets the same value.
 *
 * TEMPLATE-ONLY: does NOT touch any planned_workouts row (existing plans re-render separately).
 *
 * Run from /home/private/app: php scripts/migrate_cpt_description_fix.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

const CODE = 'continuous_progression_tempo';
$pdo = Database::get();

$jsonPath = __DIR__ . '/../database/seeds/archetype_descriptions.json';
$entries  = json_decode((string)file_get_contents($jsonPath), true);
if (!is_array($entries)) { fwrite(STDERR, "Could not read $jsonPath\n"); exit(1); }

$template = null;
foreach ($entries as $e) {
    if (($e['code'] ?? null) === CODE) { $template = (string)($e['description_template'] ?? ''); break; }
}
if ($template === null || $template === '') { fwrite(STDERR, "No description_template for " . CODE . " in seeds\n"); exit(1); }

// Sanity: the restored template must carry the warm/cool wrapper + progression token.
foreach (['{{warmup_minutes}}', '{{cooldown_minutes}}', '{{progression_instruction}}'] as $needle) {
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

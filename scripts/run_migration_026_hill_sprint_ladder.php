<?php
/**
 * Migration 026 runner — seed the hill_sprint_ladder archetype (FIX 6).
 *
 * Idempotent: INSERT IGNORE on the unique `code`, so re-running is a no-op once the
 * row exists. Reads the canonical definition from database/seeds/workout_archetypes.json
 * so it never drifts from the seeder.
 *
 *     php scripts/run_migration_026_hill_sprint_ladder.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

// Robust config loading: config.local.php lives in /home/public on production,
// but in the app root locally (mirrors scripts/regenerate_matthew.php).
foreach ([SCRIPT_ROOT . '/config/config.local.php', '/home/public/config/config.local.php'] as $cfg) {
    if (file_exists($cfg)) { require $cfg; break; }
}
defined('DB_HOST')    || define('DB_HOST',    'localhost');
defined('DB_NAME')    || define('DB_NAME',    'simplyrunfaster');
defined('DB_USER')    || define('DB_USER',    'root');
defined('DB_PASS')    || define('DB_PASS',    '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8');

$db = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$jsonPath = SCRIPT_ROOT . '/database/seeds/workout_archetypes.json';
$all = json_decode((string)file_get_contents($jsonPath), true);
if (!is_array($all)) { fwrite(STDERR, "Could not read {$jsonPath}\n"); exit(1); }

$a = null;
foreach ($all as $row) {
    if (($row['code'] ?? null) === 'hill_sprint_ladder') { $a = $row; break; }
}
if ($a === null) { fwrite(STDERR, "hill_sprint_ladder not found in seed JSON\n"); exit(1); }

$m = $a['metadata'];
$stmt = $db->prepare(
    'INSERT IGNORE INTO `workout_archetypes`
        (`code`, `version`, `status`, `name`, `workout_type`,
         `mapped_templates`, `description`,
         `selection`, `weights`, `generation`,
         `variants`, `parameters`, `structure_template`,
         `display`, `instance_signature`, `coach_notes`,
         `created_by`, `platform_wide`)
     VALUES
        (:code, :version, :status, :name, :workout_type,
         :mapped_templates, :description,
         :selection, :weights, :generation,
         :variants, :parameters, :structure_template,
         :display, :instance_signature, :coach_notes,
         NULL, 1)'
);
$stmt->execute([
    ':code'               => $a['code'],
    ':version'            => $a['version'] ?? 1,
    ':status'             => $a['status'] ?? 'active',
    ':name'               => $m['name'],
    ':workout_type'       => $m['workout_type'],
    ':mapped_templates'   => json_encode($m['mapped_templates'] ?? []),
    ':description'        => $m['description'] ?? '',
    ':selection'          => json_encode($a['selection']),
    ':weights'            => json_encode($a['weights']),
    ':generation'         => json_encode($a['generation']),
    ':variants'           => json_encode($a['variants']),
    ':parameters'         => json_encode($a['parameters']),
    ':structure_template' => json_encode($a['structure_template']),
    ':display'            => json_encode($a['display']),
    ':instance_signature' => json_encode($a['instance_signature']),
    ':coach_notes'        => json_encode($a['coach_notes']),
]);

$exists = $db->query("SELECT COUNT(*) FROM workout_archetypes WHERE code = 'hill_sprint_ladder'")->fetchColumn();
echo "hill_sprint_ladder present in workout_archetypes: " . ($exists ? 'yes' : 'NO') . "\n";
echo date('Y-m-d H:i:s') . " — migration 026 complete.\n";

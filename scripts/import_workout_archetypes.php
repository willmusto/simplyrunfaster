<?php
/**
 * Import/upsert workout archetypes from the seed JSON file.
 *
 * Usage:
 *   php scripts/import_workout_archetypes.php [--dry-run]
 *
 * - Reads:  database/seeds/workout_archetypes.json
 * - Upserts by code: inserts new, updates changed (incrementing version),
 *   status-only updates preserve version.
 * - Preserves active/inactive status from DB unless JSON explicitly differs.
 * - Safe to run multiple times.
 */

define('SCRIPT_ROOT', dirname(__DIR__));

// ── Bootstrap ─────────────────────────────────────────────────────────────────

foreach ([
    SCRIPT_ROOT . '/config/config.local.php',
    '/home/public/config/config.local.php',
] as $cfg) {
    if (file_exists($cfg)) { require $cfg; break; }
}
defined('DB_HOST')    || define('DB_HOST',    getenv('SRF_DB_HOST') ?: 'localhost');
defined('DB_NAME')    || define('DB_NAME',    getenv('SRF_DB_NAME') ?: 'simplyrunfaster');
defined('DB_USER')    || define('DB_USER',    getenv('SRF_DB_USER') ?: 'root');
defined('DB_PASS')    || define('DB_PASS',    getenv('SRF_DB_PASS') ?: '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8');

$dryRun = in_array('--dry-run', $argv ?? [], true);

$db = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo "SimplyRunFaster — Workout Archetype Importer\n";
echo "=============================================\n";
if ($dryRun) echo "[DRY RUN — no writes will occur]\n";
echo "\n";

// ── Load and parse seed file ──────────────────────────────────────────────────

$seedFile = SCRIPT_ROOT . '/database/seeds/workout_archetypes.json';

if (!file_exists($seedFile)) {
    die("ERROR: Seed file not found: {$seedFile}\n");
}

$rawJson = file_get_contents($seedFile);
if ($rawJson === false) {
    die("ERROR: Could not read seed file.\n");
}

$archetypes = json_decode($rawJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("ERROR: Malformed JSON in seed file — " . json_last_error_msg() . "\n");
}
if (!is_array($archetypes) || empty($archetypes)) {
    die("ERROR: Seed file must contain a non-empty JSON array.\n");
}

echo "Loaded " . count($archetypes) . " archetype(s) from seed file.\n\n";

// ── Validation helpers ────────────────────────────────────────────────────────

$requiredTopLevel = ['code', 'version', 'metadata', 'selection', 'weights', 'generation'];
$requiredMetadata = ['name', 'workout_type'];

function validateArchetype(array $a, int $idx): void
{
    global $requiredTopLevel, $requiredMetadata;

    foreach ($requiredTopLevel as $field) {
        if (!array_key_exists($field, $a)) {
            throw new InvalidArgumentException("Missing required field '{$field}' (item #{$idx})");
        }
    }
    if (!is_string($a['code']) || !preg_match('/^[a-z][a-z0-9_]{1,58}$/', $a['code'])) {
        throw new InvalidArgumentException("Invalid code '{$a['code']}' — must be snake_case, 2-60 chars");
    }
    if (!is_int($a['version']) || $a['version'] < 1) {
        throw new InvalidArgumentException("[{$a['code']}] version must be a positive integer");
    }
    if (!is_array($a['metadata'])) {
        throw new InvalidArgumentException("[{$a['code']}] metadata must be an object");
    }
    foreach ($requiredMetadata as $field) {
        if (empty($a['metadata'][$field])) {
            throw new InvalidArgumentException("[{$a['code']}] metadata.{$field} is required");
        }
    }
    if (!is_array($a['selection'])) {
        throw new InvalidArgumentException("[{$a['code']}] selection must be an object");
    }
    if (!is_array($a['weights'])) {
        throw new InvalidArgumentException("[{$a['code']}] weights must be an object");
    }
    if (!is_array($a['generation'])) {
        throw new InvalidArgumentException("[{$a['code']}] generation must be an object");
    }

    // Validate allowed status if present
    if (isset($a['status']) && !in_array($a['status'], ['active', 'inactive', 'draft'], true)) {
        throw new InvalidArgumentException("[{$a['code']}] status must be active, inactive, or draft");
    }
}

// ── Content hashing ───────────────────────────────────────────────────────────

/**
 * Hash the content fields of an archetype array (from JSON source).
 * Excludes version, status, created_at, updated_at — those change independently.
 */
function archetypeContentHash(array $a): string
{
    $fields = [
        'code'               => $a['code'] ?? '',
        'name'               => $a['metadata']['name'] ?? '',
        'workout_type'       => $a['metadata']['workout_type'] ?? '',
        'mapped_templates'   => $a['metadata']['mapped_templates'] ?? null,
        'description'        => $a['metadata']['description'] ?? '',
        'selection'          => $a['selection'] ?? null,
        'weights'            => $a['weights'] ?? null,
        'generation'         => $a['generation'] ?? null,
        'variants'           => $a['variants'] ?? null,
        'parameters'         => $a['parameters'] ?? null,
        'structure_template' => $a['structure_template'] ?? null,
        'display'            => $a['display'] ?? null,
        'instance_signature' => $a['instance_signature'] ?? null,
        'coach_notes'        => $a['coach_notes'] ?? null,
    ];
    // Sort keys for deterministic output; recursively sort arrays
    return md5(jsonSorted($fields));
}

/**
 * Hash the content fields from a DB row (JSON-decode LONGTEXT fields first).
 */
function rowContentHash(array $row): string
{
    $longTextFields = [
        'mapped_templates', 'selection', 'weights', 'generation',
        'variants', 'parameters', 'structure_template', 'display',
        'instance_signature', 'coach_notes',
    ];
    $decoded = $row;
    foreach ($longTextFields as $f) {
        if (isset($decoded[$f]) && is_string($decoded[$f])) {
            $decoded[$f] = json_decode($decoded[$f], true);
        }
    }
    $fields = [
        'code'               => $decoded['code'] ?? '',
        'name'               => $decoded['name'] ?? '',
        'workout_type'       => $decoded['workout_type'] ?? '',
        'mapped_templates'   => $decoded['mapped_templates'] ?? null,
        'description'        => $decoded['description'] ?? '',
        'selection'          => $decoded['selection'] ?? null,
        'weights'            => $decoded['weights'] ?? null,
        'generation'         => $decoded['generation'] ?? null,
        'variants'           => $decoded['variants'] ?? null,
        'parameters'         => $decoded['parameters'] ?? null,
        'structure_template' => $decoded['structure_template'] ?? null,
        'display'            => $decoded['display'] ?? null,
        'instance_signature' => $decoded['instance_signature'] ?? null,
        'coach_notes'        => $decoded['coach_notes'] ?? null,
    ];
    return md5(jsonSorted($fields));
}

/** JSON-encode with sorted keys at every level for stable hashing. */
function jsonSorted(mixed $value): string
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            return '[' . implode(',', array_map('jsonSorted', $value)) . ']';
        }
        ksort($value);
        $parts = [];
        foreach ($value as $k => $v) {
            $parts[] = json_encode($k) . ':' . jsonSorted($v);
        }
        return '{' . implode(',', $parts) . '}';
    }
    return json_encode($value);
}

// ── DB helpers ────────────────────────────────────────────────────────────────

function fetchByCode(PDO $db, string $code): ?array
{
    $stmt = $db->prepare('SELECT * FROM workout_archetypes WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

function insertArchetype(PDO $db, array $a): void
{
    $m = $a['metadata'];
    $db->prepare(
        'INSERT INTO workout_archetypes
         (code, version, status, name, workout_type, mapped_templates, description,
          selection, weights, generation, variants, parameters, structure_template,
          display, instance_signature, coach_notes, created_by, platform_wide, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, NOW())'
    )->execute([
        $a['code'],
        $a['version'],
        $a['status'] ?? 'active',
        $m['name'],
        $m['workout_type'],
        isset($m['mapped_templates'])   ? json_encode($m['mapped_templates'])   : null,
        $m['description'] ?? null,
        json_encode($a['selection']),
        json_encode($a['weights']),
        json_encode($a['generation']),
        isset($a['variants'])           ? json_encode($a['variants'])           : null,
        isset($a['parameters'])         ? json_encode($a['parameters'])         : null,
        isset($a['structure_template']) ? json_encode($a['structure_template']) : null,
        isset($a['display'])            ? json_encode($a['display'])            : null,
        isset($a['instance_signature']) ? json_encode($a['instance_signature']) : null,
        isset($a['coach_notes'])        ? json_encode($a['coach_notes'])        : null,
    ]);
}

function updateArchetype(PDO $db, array $a, int $id, int $newVersion, string $preserveStatus): void
{
    $m = $a['metadata'];
    $status = $a['status'] ?? $preserveStatus;

    $db->prepare(
        'UPDATE workout_archetypes SET
           version = ?, status = ?, name = ?, workout_type = ?, mapped_templates = ?,
           description = ?, selection = ?, weights = ?, generation = ?,
           variants = ?, parameters = ?, structure_template = ?,
           display = ?, instance_signature = ?, coach_notes = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute([
        $newVersion,
        $status,
        $m['name'],
        $m['workout_type'],
        isset($m['mapped_templates'])   ? json_encode($m['mapped_templates'])   : null,
        $m['description'] ?? null,
        json_encode($a['selection']),
        json_encode($a['weights']),
        json_encode($a['generation']),
        isset($a['variants'])           ? json_encode($a['variants'])           : null,
        isset($a['parameters'])         ? json_encode($a['parameters'])         : null,
        isset($a['structure_template']) ? json_encode($a['structure_template']) : null,
        isset($a['display'])            ? json_encode($a['display'])            : null,
        isset($a['instance_signature']) ? json_encode($a['instance_signature']) : null,
        isset($a['coach_notes'])        ? json_encode($a['coach_notes'])        : null,
        $id,
    ]);
}

function updateStatus(PDO $db, int $id, string $status): void
{
    $db->prepare('UPDATE workout_archetypes SET status = ?, updated_at = NOW() WHERE id = ?')
       ->execute([$status, $id]);
}

// ── Main import loop ──────────────────────────────────────────────────────────

$counts = ['created' => 0, 'content_updated' => 0, 'status_updated' => 0, 'unchanged' => 0, 'errors' => 0];

foreach ($archetypes as $idx => $archetype) {
    $code = $archetype['code'] ?? "(item #{$idx})";

    try {
        validateArchetype($archetype, $idx);

        $existing = fetchByCode($db, $archetype['code']);

        if (!$existing) {
            // ── New archetype ──────────────────────────────────────────────
            if (!$dryRun) insertArchetype($db, $archetype);
            $counts['created']++;
            echo "  + {$archetype['code']} — new (v{$archetype['version']})\n";

        } else {
            // ── Existing archetype — compare content ───────────────────────
            $newHash      = archetypeContentHash($archetype);
            $existingHash = rowContentHash($existing);

            if ($newHash !== $existingHash) {
                $newVersion = (int)$existing['version'] + 1;
                if (!$dryRun) updateArchetype($db, $archetype, (int)$existing['id'], $newVersion, $existing['status']);
                $counts['content_updated']++;
                echo "  ~ {$archetype['code']} — content changed, v{$existing['version']} → v{$newVersion}\n";

            } elseif (isset($archetype['status']) && $archetype['status'] !== $existing['status']) {
                if (!$dryRun) updateStatus($db, (int)$existing['id'], $archetype['status']);
                $counts['status_updated']++;
                echo "  ~ {$archetype['code']} — status: {$existing['status']} → {$archetype['status']}\n";

            } else {
                $counts['unchanged']++;
                echo "  · {$archetype['code']} — unchanged (v{$existing['version']})\n";
            }
        }

    } catch (InvalidArgumentException $e) {
        echo "  ERROR [{$code}]: {$e->getMessage()}\n";
        $counts['errors']++;
    } catch (PDOException $e) {
        echo "  DB ERROR [{$code}]: " . $e->getMessage() . "\n";
        $counts['errors']++;
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n";
echo "─────────────────────────────────────────────\n";
echo "  Created:         {$counts['created']}\n";
echo "  Content updated: {$counts['content_updated']}\n";
echo "  Status updated:  {$counts['status_updated']}\n";
echo "  Unchanged:       {$counts['unchanged']}\n";
echo "  Errors:          {$counts['errors']}\n";
echo "─────────────────────────────────────────────\n";
if ($dryRun) echo "[DRY RUN — no changes written]\n";
echo "Done.\n";

<?php
/**
 * Verify the plan-generation quality fixes (FIX 1-10).
 *
 * Regenerates the named athletes' plans and prints a report:
 *   - long-run weekly progression (FIX 7)
 *   - easy-run durations per week (FIX 8)
 *   - quality archetypes by phase, with rep durations + rep counts (FIX 1/2/3/6/10)
 *   - em-dash scan across all generated text (FIX 5)
 *   - races on file + any workouts in a suspect date window (FIX 9)
 *   - hill_sprint_ladder library + preview (FIX 6)
 *
 * Read-only except for the deliberate regeneration of each named athlete's plan.
 *
 *     php scripts/verify_plan_quality_fixes.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

foreach ([SCRIPT_ROOT . '/config/config.local.php', '/home/public/config/config.local.php'] as $cfg) {
    if (file_exists($cfg)) { require $cfg; break; }
}
defined('DB_HOST')    || define('DB_HOST',    'localhost');
defined('DB_NAME')    || define('DB_NAME',    'simplyrunfaster');
defined('DB_USER')    || define('DB_USER',    'root');
defined('DB_PASS')    || define('DB_PASS',    '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8');

require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Engine/TrainingLoad.php';
require_once SCRIPT_ROOT . '/src/Engine/PaceZones.php';
require_once SCRIPT_ROOT . '/src/Engine/ArchetypeSelector.php';
require_once SCRIPT_ROOT . '/src/Engine/PlanGenerator.php';

$db = Database::get();

const EMDASH = "\xE2\x80\x94"; // U+2014

function findAthlete(PDO $db, string $like): ?array {
    $s = $db->prepare("SELECT a.id AS athlete_id, u.name FROM athletes a JOIN users u ON u.id = a.user_id
                       WHERE u.name LIKE ? ORDER BY a.id LIMIT 1");
    $s->execute(['%' . $like . '%']);
    return $s->fetch() ?: null;
}

function dumpRaces(PDO $db, int $athleteId): void {
    $s = $db->prepare('SELECT id, race_name, race_distance, race_date, is_goal_race FROM races WHERE athlete_id = ? ORDER BY race_date');
    $s->execute([$athleteId]);
    $rows = $s->fetchAll();
    echo "  races on file (" . count($rows) . "):\n";
    foreach ($rows as $r) {
        echo "    #{$r['id']} {$r['race_date']} {$r['race_distance']} \"{$r['race_name']}\"" . ($r['is_goal_race'] ? ' [GOAL]' : '') . "\n";
    }
}

function verifyAthlete(PDO $db, string $like): void {
    echo "\n================ {$like} ================\n";
    $a = findAthlete($db, $like);
    if (!$a) { echo "  NOT FOUND\n"; return; }
    $athleteId = (int)$a['athlete_id'];
    echo "  {$a['name']} (athlete_id={$athleteId})\n";

    $prof = $db->prepare('SELECT plan_type, goal_race_distance, goal_race_date FROM athlete_profiles WHERE athlete_id = ? LIMIT 1');
    $prof->execute([$athleteId]);
    $p = $prof->fetch();
    echo "  plan_type={$p['plan_type']} goal={$p['goal_race_distance']} goal_date=" . ($p['goal_race_date'] ?? 'NULL') . "\n";

    dumpRaces($db, $athleteId);

    // Regenerate
    $pending = $db->prepare("SELECT id FROM training_plans WHERE athlete_id = ? AND status IN ('pending_approval','active')");
    $pending->execute([$athleteId]);
    foreach ($pending->fetchAll() as $pl) {
        $db->prepare('DELETE FROM plan_approval_queue WHERE plan_id = ?')->execute([$pl['id']]);
        $db->prepare('DELETE FROM planned_workouts WHERE plan_id = ?')->execute([$pl['id']]);
        $db->prepare('DELETE FROM training_plans WHERE id = ?')->execute([$pl['id']]);
    }
    $planId = PlanGenerator::generate($athleteId, 'coach_manual');
    if (!$planId) { echo "  generate() returned null\n"; return; }
    $plan = $db->prepare('SELECT plan_start_date, plan_end_date FROM training_plans WHERE id = ?');
    $plan->execute([$planId]);
    $pp = $plan->fetch();
    echo "  new plan #{$planId} {$pp['plan_start_date']} -> {$pp['plan_end_date']}\n";

    $start = strtotime($pp['plan_start_date']);
    $rows = $db->prepare('SELECT scheduled_date, workout_type, archetype_code, archetype_variant, archetype_params,
                                 target_duration, display_title, display_summary, athlete_instructions
                          FROM planned_workouts WHERE plan_id = ? ORDER BY scheduled_date');
    $rows->execute([$planId]);
    $all = $rows->fetchAll();

    // Weekly aggregation
    $weeks = [];
    $emdash = 0; $repTooLong = 0; $hillLadder = 0; $tempoCount = 0; $goalPace = 0;
    foreach ($all as $w) {
        $wk = (int)floor((strtotime($w['scheduled_date']) - $start) / (7 * 86400));
        $params = json_decode($w['archetype_params'] ?? '{}', true) ?: [];
        $text = trim(($w['display_title'] ?? '') . ' | ' . ($w['display_summary'] ?? '') . ' | ' . ($w['athlete_instructions'] ?? ''));
        if (strpos($text, EMDASH) !== false) { $emdash++; echo "  [EMDASH] {$w['scheduled_date']} {$w['archetype_code']}\n"; }
        if (($w['archetype_code'] ?? '') === 'sustained_hill_repeats') {
            $rd = (int)($params['rep_duration_seconds'] ?? 0);
            if ($rd > 90 || ($rd > 0 && $rd < 30)) { $repTooLong++; echo "  [REPDUR] {$w['scheduled_date']} rep_duration_seconds={$rd}\n"; }
        }
        if (($w['archetype_code'] ?? '') === 'hill_sprint_ladder') $hillLadder++;
        if (in_array($w['archetype_code'] ?? '', ['tempo_intervals', 'continuous_progression_tempo'], true)) $tempoCount++;
        if (($w['archetype_code'] ?? '') === 'goal_pace_long_segments') $goalPace++;

        if ($w['workout_type'] === 'long') {
            $weeks[$wk]['long'] = max($weeks[$wk]['long'] ?? 0, (int)$w['target_duration']);
        } elseif (in_array($w['workout_type'], ['easy'], true)) {
            $weeks[$wk]['easy'][] = (int)$w['target_duration'];
        }
        if (in_array($w['archetype_code'] ?? '', ['short_speed_repeats','equal_distance_repeats','mixed_distance_repeats'], true)) {
            $weeks[$wk]['reps'][] = ($w['archetype_code']) . ':' . (int)($params['rep_count'] ?? 0);
        }
    }

    echo "  week | long(min) | easy durations | speed/dist reps\n";
    ksort($weeks);
    foreach ($weeks as $wk => $d) {
        $easy = isset($d['easy']) ? implode(',', $d['easy']) : '-';
        $reps = isset($d['reps']) ? implode(' ', $d['reps']) : '';
        printf("   W%-2d |    %4d   | %-22s | %s\n", $wk + 1, $d['long'] ?? 0, $easy, $reps);
    }

    echo "  summary: emdash={$emdash} hillSprintLadder={$hillLadder} tempo/threshold={$tempoCount} goalPaceLong={$goalPace} repDurViolations={$repTooLong}\n";

    // FIX 9: workouts in a suspect window relative to any non-goal foreign date.
    echo "  workouts Aug 25 - Sep 5 (FIX 9 window):\n";
    foreach ($all as $w) {
        $mmdd = substr($w['scheduled_date'], 5);
        if ($mmdd >= '08-25' && $mmdd <= '09-05') {
            echo "    {$w['scheduled_date']} {$w['workout_type']} {$w['target_duration']}min :: " . substr((string)$w['display_summary'], 0, 40) . "\n";
        }
    }
}

verifyAthlete($db, 'Matthew Jenkins');
verifyAthlete($db, 'Liam');

// FIX 6: hill_sprint_ladder in the library + preview pipeline.
echo "\n================ hill_sprint_ladder library/preview ================\n";
foreach (['descending', 'pyramid', 'double_descending'] as $variant) {
    $prev = PlanGenerator::previewArchetype('hill_sprint_ladder', 'well_trained', 45, 'marathon', $variant);
    if (!$prev) { echo "  preview {$variant}: NULL\n"; continue; }
    $emd = strpos(($prev['display_title'] ?? '') . ($prev['athlete_instructions'] ?? ''), EMDASH) !== false ? ' [EMDASH!]' : '';
    echo "  {$variant}: \"{$prev['display_title']}\" / {$prev['target_duration']}min{$emd}\n";
    echo "    " . substr((string)$prev['athlete_instructions'], 0, 160) . "\n";
}

// FIX 4: wave vs linear continuous progression tempo descriptions.
echo "\n================ continuous_progression_tempo (FIX 4) ================\n";
foreach (['wave_progression', 'linear_progression'] as $variant) {
    $prev = PlanGenerator::previewArchetype('continuous_progression_tempo', 'well_trained', 50, 'marathon', $variant);
    echo "  {$variant}: " . substr((string)($prev['athlete_instructions'] ?? 'NULL'), 0, 260) . "\n";
}

// FIX 1: sustained_hill_repeats display format.
echo "\n================ sustained_hill_repeats display (FIX 1) ================\n";
$prev = PlanGenerator::previewArchetype('sustained_hill_repeats', 'well_trained', 45, 'marathon');
echo "  title: " . ($prev['display_title'] ?? 'NULL') . "\n";
echo "  instr: " . substr((string)($prev['athlete_instructions'] ?? ''), 0, 180) . "\n";

echo "\nDone.\n";

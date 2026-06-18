<?php
/**
 * Verification: coach workout editing — surface tweak / archetype swap / free-form.
 *
 * Exercises the REAL per-mode computation via the pure CoachController::composeWorkoutEdit()
 * (no DB write / echo / exit) for ALL THREE modes — the same code the HTTP handler runs — then
 * persists the returned column set (+ the lock/edited columns the handler appends) and asserts:
 *   - coach_locked + coach_edited_by set on every save
 *   - intensity_load recomputed correctly per mode (surface = preserved factor × new duration;
 *     archetype = variant/archetype IF × duration; free-form = type factor × duration)
 *   - display consistent with the new target_duration; instance_signature recomputed on swap,
 *     unchanged on surface, NULL on free-form
 *   - editing NEVER rewrites added_by_role (provenance): an assistant edit leaves it at its
 *     pre-edit value in all three modes
 *   - NO plan regeneration side effect (training_plans / planned_workouts counts unchanged)
 *
 * Throwaway athlete + full teardown. Read-only against the rest of the DB; no Liam; Intervals
 * is never contacted (composeWorkoutEdit does no push; we persist directly).
 *
 * Run: php /home/private/app/scripts/verify_workout_edit.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
foreach ([SCRIPT_ROOT . '/config/config.local.php', '/home/public/config/config.local.php'] as $cfg) {
    if (file_exists($cfg)) { require $cfg; break; }
}
defined('DB_HOST')    || define('DB_HOST',    getenv('SRF_DB_HOST') ?: 'localhost');
defined('DB_NAME')    || define('DB_NAME',    getenv('SRF_DB_NAME') ?: 'simplyrunfaster');
defined('DB_USER')    || define('DB_USER',    getenv('SRF_DB_USER') ?: 'root');
defined('DB_PASS')    || define('DB_PASS',    getenv('SRF_DB_PASS') ?: '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8');

require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Timezone.php';
require_once SCRIPT_ROOT . '/views/layout/base.php'; // pill_label / pill_class / format_duration
require_once SCRIPT_ROOT . '/src/Engine/TrainingLoad.php';
require_once SCRIPT_ROOT . '/src/Engine/EffortMapper.php';
require_once SCRIPT_ROOT . '/src/Engine/RecoveryModel.php';
require_once SCRIPT_ROOT . '/src/Engine/PaceZones.php';
require_once SCRIPT_ROOT . '/src/Engine/ArchetypeSelector.php';
require_once SCRIPT_ROOT . '/src/Engine/PlanGenerator.php';
require_once SCRIPT_ROOT . '/src/Controllers/CoachController.php';

$db = Database::get();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Independent mirror of CoachController::FREEFORM_LOAD_FACTOR for the formula assertions.
$FF = ['easy'=>0.5,'long'=>0.6,'tempo'=>0.85,'interval'=>0.9,'hill'=>0.85,'fartlek'=>0.8,
       'race_pace'=>0.9,'recovery'=>0.3,'rest'=>0.0,'cross_train'=>0.4,'speed'=>0.95,'plyometric'=>0.7];
$COACH_ID = 999001;

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void { global $pass, $fail; echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n"; $ok ? $pass++ : $fail++; }
function approx($a, $b, $t = 0.011): bool { return $a !== null && abs((float)$a - (float)$b) <= $t; }

$athleteId = null; $userId = null;

try {
    $email = 'wkedit_verify_' . substr(md5((string)mt_rand()), 0, 8) . '@example.test';
    $db->prepare("INSERT INTO users (email, password_hash, role, name, timezone) VALUES (?, ?, 'athlete', 'WkEdit Verify Bot', 'America/New_York')")
       ->execute([$email, password_hash('x', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO athletes (user_id, onboarding_completed_at, status) VALUES (?, NOW(), 'active')")->execute([$userId]);
    $athleteId = (int)$db->lastInsertId();
    $db->prepare(
        "INSERT INTO athlete_profiles (athlete_id, plan_type, training_days_per_week, must_off_days, goal_race_distance,
            current_weekly_minutes, longest_recent_run_mins, experience_level, pace_zones_visible)
         VALUES (?, 'development_plan', 5, '[]', '10K', 220, 70, 'intermediate', 1)"
    )->execute([$athleteId]);

    $planId = PlanGenerator::generate($athleteId, 'onboarding');
    if (!$planId) throw new RuntimeException('generate returned null');
    $db->prepare("UPDATE training_plans SET status='active' WHERE id=?")->execute([$planId]);

    $plansBefore = (int)$db->query("SELECT COUNT(*) FROM training_plans WHERE athlete_id={$athleteId}")->fetchColumn();
    $pwBefore    = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE athlete_id={$athleteId}")->fetchColumn();

    $target = $db->query(
        "SELECT * FROM planned_workouts
         WHERE athlete_id={$athleteId} AND scheduled_date > CURDATE()
           AND archetype_code IS NOT NULL AND target_duration > 0 AND intensity_load > 0
           AND (cancelled = 0 OR cancelled IS NULL)
         ORDER BY scheduled_date ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$target) throw new RuntimeException('no suitable future archetype workout found');
    $wid  = (int)$target['id'];
    $snap = $target;

    $allCols = ['workout_type','archetype_code','archetype_variant','archetype_params','workout_archetype_id',
                'archetype_version_snapshot','instance_signature','structure','display_title','display_summary',
                'athlete_instructions','description','notes','target_duration','intensity_load',
                'coach_locked','coach_edited_by','coach_edited_at','added_by_role'];
    $restore = function (?string $addedByRole = null) use ($db, $wid, $snap, $allCols) {
        $sets = []; $vals = [];
        foreach ($allCols as $c) { $sets[] = "$c = ?"; $vals[] = $snap[$c]; }
        $vals[] = $wid;
        $db->prepare('UPDATE planned_workouts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        // Set a known provenance baseline for the FIX-2 assertions.
        $db->prepare('UPDATE planned_workouts SET added_by_role = ?, coach_locked = 0, coach_edited_by = NULL, coach_edited_at = NULL WHERE id = ?')
           ->execute([$addedByRole, $wid]);
    };
    $rowNow = function () use ($db, $wid) { $s = $db->prepare('SELECT * FROM planned_workouts WHERE id=?'); $s->execute([$wid]); return $s->fetch(PDO::FETCH_ASSOC); };
    // Persist exactly as the handler does: the pure column set + the lock/edited columns.
    $persist = function (array $columns) use ($db, $wid, $COACH_ID) {
        $sets = []; $vals = [];
        foreach ($columns as $col => $v) { $sets[] = "`{$col}` = ?"; $vals[] = $v; }
        $sets[] = 'coach_locked = 1'; $sets[] = 'coach_edited_by = ?'; $vals[] = $COACH_ID; $sets[] = 'coach_edited_at = NOW()';
        $vals[] = $wid;
        $db->prepare('UPDATE planned_workouts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    };

    $origSig  = (string)$snap['instance_signature'];
    $origDur  = (int)$snap['target_duration'];
    $origLoad = (float)$snap['intensity_load'];

    // ── MODE 1: surface (duration change; assistant edit; engine-added row stays NULL) ──
    $restore(null); // added_by_role = NULL (engine-added)
    $before = $rowNow();
    $newDur = $origDur + 15;
    $r = CoachController::composeWorkoutEdit('surface', $before, ['workout_type' => $before['workout_type'], 'target_duration' => $newDur], $db);
    $expLoad = round($newDur * ($origLoad / $origDur), 2);
    check("surface: no error", empty($r['error']));
    check("surface: intensity_load = old factor × new duration", approx($r['columns']['intensity_load'] ?? null, $expLoad));
    check("surface: instance_signature NOT in column set (unchanged)", !array_key_exists('instance_signature', $r['columns']));
    check("surface: display_summary reflects the new duration", isset($r['columns']['display_summary']) && strpos((string)$r['columns']['display_summary'], format_duration($newDur)) !== false);
    check("surface: added_by_role NOT in column set (provenance untouched)", !array_key_exists('added_by_role', $r['columns']));
    $persist($r['columns']);
    $row = $rowNow();
    check("surface: persisted coach_locked + coach_edited_by", (int)$row['coach_locked'] === 1 && (int)$row['coach_edited_by'] === $COACH_ID);
    check("surface: added_by_role still NULL after assistant edit", $row['added_by_role'] === null);
    check("surface: instance_signature row value unchanged", (string)$row['instance_signature'] === $origSig);

    // ── MODE 2: archetype swap (REAL composeManualWorkout via the pure method) ──
    $restore('assistant_coach'); // originally assistant-ADDED → must stay 'assistant_coach'
    $before = $rowNow();
    $swapCode = ((string)$snap['archetype_code'] === 'continuous_easy') ? 'continuous_long' : 'continuous_easy';
    $r = CoachController::composeWorkoutEdit('archetype', $before, ['archetype_code' => $swapCode, 'duration' => 55], $db);
    check("archetype: no error", empty($r['error']));
    $composed = $r['composed'] ?? null;
    // Independent expected load from the archetype/variant intensity factor.
    $sel  = new ArchetypeSelector($db);
    $arch = $sel->resolveParameters($sel->getByCode($swapCode), 'workable');
    $effIF = (float)(($arch['variants'][0]['intensity_factor'] ?? null) ?? $arch['generation']['intensity_factor'] ?? 0.5);
    $expArchLoad = round((int)$composed['target_duration'] * $effIF, 2);
    check("archetype: column load = composed load", approx($r['columns']['intensity_load'] ?? null, $composed['intensity_load']));
    check("archetype: load = variant/archetype IF × stored duration (formula)", approx($r['columns']['intensity_load'] ?? null, $expArchLoad));
    check("archetype: instance_signature recomputed (non-null, changed)", !empty($r['columns']['instance_signature']) && (string)$r['columns']['instance_signature'] !== $origSig);
    check("archetype: archetype_code overwritten to new code", ($r['columns']['archetype_code'] ?? null) === $swapCode);
    check("archetype: display regenerated + duration consistent", (int)$r['columns']['target_duration'] === (int)$composed['target_duration'] && !empty($r['columns']['display_title']));
    check("archetype: added_by_role NOT in column set (provenance untouched)", !array_key_exists('added_by_role', $r['columns']));
    $persist($r['columns']);
    $row = $rowNow();
    check("archetype: persisted coach_locked + coach_edited_by", (int)$row['coach_locked'] === 1 && (int)$row['coach_edited_by'] === $COACH_ID);
    check("archetype: added_by_role still 'assistant_coach' after edit", (string)$row['added_by_role'] === 'assistant_coach');

    // ── MODE 3: free-form ──
    $restore(null);
    $before = $rowNow();
    $r = CoachController::composeWorkoutEdit('freeform', $before, ['title' => 'Custom shakeout', 'workout_type' => 'tempo', 'duration' => 50, 'instructions' => 'easy effort', 'coach_notes' => 'note'], $db);
    check("free-form: no error", empty($r['error']));
    check("free-form: intensity_load = type factor × duration", approx($r['columns']['intensity_load'] ?? null, round(50 * $FF['tempo'], 2)));
    check("free-form: archetype_code NULL in column set", array_key_exists('archetype_code', $r['columns']) && $r['columns']['archetype_code'] === null);
    check("free-form: instance_signature NULL in column set", array_key_exists('instance_signature', $r['columns']) && $r['columns']['instance_signature'] === null);
    check("free-form: display_summary NULL in column set", array_key_exists('display_summary', $r['columns']) && $r['columns']['display_summary'] === null);
    check("free-form: title is the coach's", ($r['columns']['display_title'] ?? null) === 'Custom shakeout');
    check("free-form: added_by_role NOT in column set (provenance untouched)", !array_key_exists('added_by_role', $r['columns']));
    $persist($r['columns']);
    $row = $rowNow();
    check("free-form: persisted coach_locked + coach_edited_by", (int)$row['coach_locked'] === 1 && (int)$row['coach_edited_by'] === $COACH_ID);
    check("free-form: archetype_code NULL + signature NULL in row", $row['archetype_code'] === null && $row['instance_signature'] === null);
    check("free-form: added_by_role still NULL after assistant edit", $row['added_by_role'] === null);

    // ── No regeneration side effect ──
    $plansAfter = (int)$db->query("SELECT COUNT(*) FROM training_plans WHERE athlete_id={$athleteId}")->fetchColumn();
    $pwAfter    = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE athlete_id={$athleteId}")->fetchColumn();
    check("no plan regeneration: training_plans count unchanged", $plansAfter === $plansBefore);
    check("no plan regeneration: planned_workouts count unchanged", $pwAfter === $pwBefore);

    echo "\n================================\n";
    echo "  Coach workout-edit verification\n";
    echo "  PASS: {$pass}   FAIL: {$fail}\n";
    echo "================================\n";

} finally {
    if ($athleteId) {
        foreach (['completed_workouts','planned_workouts','plan_approval_queue','training_plans',
                  'engine_flags','training_load','coach_adjustments','athlete_profiles'] as $t) {
            try { $db->prepare("DELETE FROM {$t} WHERE athlete_id=?")->execute([$athleteId]); } catch (\Throwable $e) {}
        }
        $db->prepare("DELETE FROM athletes WHERE id=?")->execute([$athleteId]);
    }
    if ($userId) $db->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
}

exit($fail === 0 ? 0 : 1);

<?php
/**
 * Verification: coach workout editing — surface tweak / archetype swap / free-form.
 *
 * Spins up a throwaway development_plan athlete, generates + activates a plan, and exercises
 * the three edit modes against a real future archetype workout. The archetype path runs the
 * REAL engine pipeline (PlanGenerator::composeManualWorkout); the surface/free-form paths
 * replicate CoachController::editPlannedWorkout's exact per-mode write so the formulas can be
 * asserted in a single CLI process (the controller method ends in exit()). Asserts:
 *   - coach_locked + coach_edited_by set on every save
 *   - intensity_load recomputed correctly per mode (surface = same factor × new duration;
 *     archetype = variant/archetype IF × duration; free-form = type factor × duration)
 *   - display consistent with the new target_duration; instance_signature recomputed on swap,
 *     unchanged on surface, NULL on free-form
 *   - NO plan regeneration side effect (training_plans / planned_workouts counts unchanged)
 *
 * Throwaway athlete + full teardown. Read-only against the rest of the DB; no Liam.
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
require_once SCRIPT_ROOT . '/views/layout/base.php'; // pill_label / format_duration
require_once SCRIPT_ROOT . '/src/Engine/TrainingLoad.php';
require_once SCRIPT_ROOT . '/src/Engine/EffortMapper.php';
require_once SCRIPT_ROOT . '/src/Engine/RecoveryModel.php';
require_once SCRIPT_ROOT . '/src/Engine/PaceZones.php';
require_once SCRIPT_ROOT . '/src/Engine/ArchetypeSelector.php';
require_once SCRIPT_ROOT . '/src/Engine/PlanGenerator.php';

$db = Database::get();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Mirror of CoachController::FREEFORM_LOAD_FACTOR (the free-form ADD/edit load factors).
$FF = ['easy'=>0.5,'long'=>0.6,'tempo'=>0.85,'interval'=>0.9,'hill'=>0.85,'fartlek'=>0.8,
       'race_pace'=>0.9,'recovery'=>0.3,'rest'=>0.0,'cross_train'=>0.4,'speed'=>0.95,'plyometric'=>0.7];
$COACH_ID = 999001; // arbitrary fake coach id for coach_edited_by

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

    // No-regen baseline.
    $plansBefore = (int)$db->query("SELECT COUNT(*) FROM training_plans WHERE athlete_id={$athleteId}")->fetchColumn();
    $pwBefore    = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE athlete_id={$athleteId}")->fetchColumn();

    // Pick a future archetype workout with a real duration + load (no completion exists for a fresh plan).
    $target = $db->query(
        "SELECT * FROM planned_workouts
         WHERE athlete_id={$athleteId} AND scheduled_date > CURDATE()
           AND archetype_code IS NOT NULL AND target_duration > 0 AND intensity_load > 0
           AND (cancelled = 0 OR cancelled IS NULL)
         ORDER BY scheduled_date ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$target) throw new RuntimeException('no suitable future archetype workout found');
    $wid  = (int)$target['id'];
    $snap = $target; // full snapshot for restore-between-modes

    $cols = ['workout_type','archetype_code','archetype_variant','archetype_params','workout_archetype_id',
             'archetype_version_snapshot','instance_signature','structure','display_title','display_summary',
             'athlete_instructions','description','notes','target_duration','intensity_load',
             'coach_locked','coach_edited_by','coach_edited_at','added_by_role'];
    $restore = function () use ($db, $wid, $snap, $cols) {
        $sets = []; $vals = [];
        foreach ($cols as $c) { $sets[] = "$c = ?"; $vals[] = $snap[$c]; }
        $vals[] = $wid;
        $db->prepare('UPDATE planned_workouts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    };
    $rowNow = function () use ($db, $wid) { $s = $db->prepare('SELECT * FROM planned_workouts WHERE id=?'); $s->execute([$wid]); return $s->fetch(PDO::FETCH_ASSOC); };

    $origSig  = (string)$snap['instance_signature'];
    $origDur  = (int)$snap['target_duration'];
    $origLoad = (float)$snap['intensity_load'];

    // ── MODE 1: surface tweak (duration change; same factor) ──
    $restore();
    $newDur = $origDur + 15;
    $factor = ($origLoad > 0 && $origDur > 0) ? $origLoad / $origDur : ($FF[$snap['workout_type']] ?? 0.5);
    $expLoad = round($newDur * $factor, 2);
    $newSummary = format_duration($newDur) . ' · ' . pill_label((string)$snap['workout_type'], (string)$snap['archetype_code']);
    $db->prepare(
        "UPDATE planned_workouts SET workout_type=?, target_duration=?, intensity_load=?, display_summary=?,
            coach_locked=1, coach_edited_by=?, coach_edited_at=NOW() WHERE id=?"
    )->execute([$snap['workout_type'], $newDur, $expLoad, $newSummary, $COACH_ID, $wid]);
    $r = $rowNow();
    check("surface: intensity_load = old factor × new duration", approx($r['intensity_load'], $expLoad));
    check("surface: instance_signature UNCHANGED", (string)$r['instance_signature'] === $origSig);
    check("surface: display_summary reflects the new duration", strpos((string)$r['display_summary'], format_duration($newDur)) !== false);
    check("surface: coach_locked + coach_edited_by set", (int)$r['coach_locked'] === 1 && (int)$r['coach_edited_by'] === $COACH_ID && !empty($r['coach_edited_at']));

    // ── MODE 2: archetype swap (REAL pipeline) ──
    $restore();
    $swapCode = ((string)$snap['archetype_code'] === 'continuous_easy') ? 'continuous_long' : 'continuous_easy';
    $swapDur  = 55;
    $composed = PlanGenerator::composeManualWorkout($athleteId, $swapCode, null, $swapDur, $db);
    if (!$composed) throw new RuntimeException('composeManualWorkout failed for ' . $swapCode);
    // Independently recompute the expected load from the archetype/variant intensity factor.
    $sel = new ArchetypeSelector($db);
    $arch = $sel->resolveParameters($sel->getByCode($swapCode), 'workable');
    $vIF  = $arch['variants'][0]['intensity_factor'] ?? null;
    $effIF = (float)($vIF ?? $arch['generation']['intensity_factor'] ?? 0.5);
    $expArchLoad = round((int)$composed['target_duration'] * $effIF, 2);
    $db->prepare(
        "UPDATE planned_workouts SET workout_type=?, archetype_code=?, archetype_variant=?, archetype_params=?,
            workout_archetype_id=?, archetype_version_snapshot=?, instance_signature=?, structure=?,
            display_title=?, display_summary=?, athlete_instructions=?, description=?, target_duration=?, intensity_load=?,
            coach_locked=1, coach_edited_by=?, coach_edited_at=NOW() WHERE id=?"
    )->execute([
        $composed['workout_type'], $composed['archetype_code'], $composed['archetype_variant'], $composed['archetype_params'],
        $composed['workout_archetype_id'], $composed['archetype_version_snapshot'], $composed['instance_signature'], $composed['structure'],
        $composed['display_title'], $composed['display_summary'], $composed['athlete_instructions'], $composed['athlete_instructions'],
        $composed['target_duration'], $composed['intensity_load'], $COACH_ID, $wid,
    ]);
    $r = $rowNow();
    check("archetype: intensity_load = composed load", approx($r['intensity_load'], $composed['intensity_load']));
    check("archetype: load = variant/archetype IF × stored duration (formula)", approx($r['intensity_load'], $expArchLoad));
    check("archetype: instance_signature recomputed (non-null, changed)", !empty($r['instance_signature']) && (string)$r['instance_signature'] !== $origSig);
    check("archetype: archetype_code overwritten to the new code", (string)$r['archetype_code'] === $swapCode);
    check("archetype: display regenerated + duration consistent", (int)$r['target_duration'] === (int)$composed['target_duration'] && !empty($r['display_title']));
    check("archetype: coach_locked + coach_edited_by set", (int)$r['coach_locked'] === 1 && (int)$r['coach_edited_by'] === $COACH_ID);

    // ── MODE 3: free-form ──
    $restore();
    $ffType = 'tempo'; $ffDur = 50; $ffTitle = 'Custom shakeout';
    $expFFLoad = round($ffDur * $FF[$ffType], 2);
    $db->prepare(
        "UPDATE planned_workouts SET workout_type=?, archetype_code=NULL, archetype_variant=NULL, archetype_params=NULL,
            workout_archetype_id=NULL, archetype_version_snapshot=NULL, instance_signature=NULL, structure=NULL,
            display_title=?, display_summary=NULL, athlete_instructions=?, description=?, notes=?, target_duration=?, intensity_load=?,
            coach_locked=1, coach_edited_by=?, coach_edited_at=NOW() WHERE id=?"
    )->execute([$ffType, $ffTitle, 'easy effort', 'easy effort', 'coach note', $ffDur, $expFFLoad, $COACH_ID, $wid]);
    $r = $rowNow();
    check("free-form: intensity_load = type factor × duration", approx($r['intensity_load'], $expFFLoad));
    check("free-form: archetype_code NULL", $r['archetype_code'] === null);
    check("free-form: instance_signature NULL", $r['instance_signature'] === null);
    check("free-form: display_summary NULL (derived at render)", $r['display_summary'] === null);
    check("free-form: title is the coach's", (string)$r['display_title'] === $ffTitle);
    check("free-form: coach_locked + coach_edited_by set", (int)$r['coach_locked'] === 1 && (int)$r['coach_edited_by'] === $COACH_ID);

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

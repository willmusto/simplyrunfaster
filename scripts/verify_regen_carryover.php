<?php
/**
 * Verification: regen carry-over of athlete-exposed weeks (default preserve) + full-wipe.
 *
 * Spins up a throwaway development_plan athlete, generates + activates a plan, simulates a
 * 10-day exposed window (visible_to_athlete=1) and fake Intervals event ids, locks one row
 * in the unexposed region, then regenerates and asserts:
 *   - every row in an exposed week is carried byte-identical (id + content + intervals_event_id),
 *     marked carried_over_from_plan_id/at, now in the new plan;
 *   - the coach_locked row in the regenerated region is preserved likewise;
 *   - carried dates contain NO freshly-generated row; the fresh remainder begins on the Monday
 *     of the first fully-unexposed week;
 *   - no carried instance_signature is duplicated by the fresh remainder (anti-repeat);
 *   - the fresh region is a real generated plan (structure intact);
 *   - full-wipe (fullWipe=true) carries nothing and rebuilds from scratch.
 *
 * Everything created is torn down in finally. Read-only against the rest of the DB; no Liam.
 *
 * Run: php /home/private/app/scripts/verify_regen_carryover.php
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
require_once SCRIPT_ROOT . '/src/Engine/TrainingLoad.php';
require_once SCRIPT_ROOT . '/src/Engine/EffortMapper.php';
require_once SCRIPT_ROOT . '/src/Engine/RecoveryModel.php';
require_once SCRIPT_ROOT . '/src/Engine/PaceZones.php';
require_once SCRIPT_ROOT . '/src/Engine/ArchetypeSelector.php';
require_once SCRIPT_ROOT . '/src/Engine/PlanGenerator.php';

$db = Database::get();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}
function mondayOf(string $d): string {
    $t = strtotime($d); $dow = (int)date('N', $t);
    return date('Y-m-d', $t - ($dow - 1) * 86400);
}
$CONTENT = ['archetype_code','archetype_variant','archetype_params','workout_archetype_id',
            'archetype_version_snapshot','instance_signature','structure','display_title',
            'display_summary','target_duration','intensity_load','intervals_event_id','coach_locked'];

$athleteId = null; $userId = null;

$setupAthlete = function () use ($db, &$athleteId, &$userId): int {
    $email = 'regen_verify_' . substr(md5((string)mt_rand()), 0, 8) . '@example.test';
    $db->prepare("INSERT INTO users (email, password_hash, role, name, timezone) VALUES (?, ?, 'athlete', 'Regen Verify Bot', 'America/New_York')")
       ->execute([$email, password_hash('x', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO athletes (user_id, onboarding_completed_at, status) VALUES (?, NOW(), 'active')")->execute([$userId]);
    $aid = (int)$db->lastInsertId();
    $db->prepare(
        "INSERT INTO athlete_profiles
            (athlete_id, plan_type, training_days_per_week, must_off_days, goal_race_distance,
             current_weekly_minutes, longest_recent_run_mins, experience_level, pace_zones_visible)
         VALUES (?, 'development_plan', 4, '[]', '10K', 180, 60, 'intermediate', 1)"
    )->execute([$aid]);
    $athleteId = $aid;
    return $aid;
};

try {
    // ════════ PRESERVE PATH ════════
    $athleteId = $setupAthlete();

    $planId0 = PlanGenerator::generate($athleteId, 'onboarding');
    if (!$planId0) throw new RuntimeException('initial generate returned null');
    $db->prepare("UPDATE training_plans SET status='active' WHERE id=?")->execute([$planId0]);

    // Simulate the 10-day visibility window + fake Intervals event ids on the prior plan.
    $start = (string)$db->query("SELECT MIN(scheduled_date) FROM planned_workouts WHERE plan_id={$planId0} AND scheduled_date >= CURDATE()")->fetchColumn();
    $horizon = date('Y-m-d', strtotime($start . ' +9 days'));
    $db->prepare("UPDATE planned_workouts SET visible_to_athlete=1 WHERE plan_id=? AND scheduled_date BETWEEN ? AND ?")
       ->execute([$planId0, $start, $horizon]);
    $db->prepare("UPDATE planned_workouts SET intervals_event_id = CONCAT('evt_', id) WHERE plan_id=? AND visible_to_athlete=1")
       ->execute([$planId0]);

    // Lock one row deep in the unexposed region (~35 days out) and give it an event id.
    $lockDate = date('Y-m-d', strtotime($start . ' +35 days'));
    $lockId = (int)$db->query("SELECT id FROM planned_workouts WHERE plan_id={$planId0} AND scheduled_date >= '{$lockDate}' ORDER BY scheduled_date ASC LIMIT 1")->fetchColumn();
    $db->prepare("UPDATE planned_workouts SET coach_locked=1, intervals_event_id=CONCAT('evt_', id) WHERE id=?")->execute([$lockId]);

    // Capture prior carried rows (exposed weeks + locked row) and their content.
    $visRows = $db->query("SELECT * FROM planned_workouts WHERE plan_id={$planId0} AND visible_to_athlete=1")->fetchAll(PDO::FETCH_ASSOC);
    $exposedMondays = [];
    foreach ($visRows as $r) $exposedMondays[mondayOf((string)$r['scheduled_date'])] = true;

    // Expected carried dates = whole Mon–Sun of each exposed week, >= plan start.
    $planStart = (string)$db->query("SELECT plan_start_date FROM training_plans WHERE id={$planId0}")->fetchColumn();
    $expectedCarriedDates = [];
    foreach (array_keys($exposedMondays) as $mon) {
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($mon . " +{$i} days"));
            if ($d >= $planStart) $expectedCarriedDates[$d] = true;
        }
    }
    $captured = $db->query(
        "SELECT * FROM planned_workouts WHERE plan_id={$planId0}
         AND (visible_to_athlete=1 OR coach_locked=1) AND scheduled_date >= '{$planStart}'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $capById = [];
    foreach ($captured as $r) $capById[(int)$r['id']] = $r;
    $lastExposedSunday = '';
    foreach (array_keys($exposedMondays) as $mon) {
        $sun = date('Y-m-d', strtotime($mon . ' +6 days'));
        if ($sun > $lastExposedSunday) $lastExposedSunday = $sun;
    }

    // ── Regenerate (default preserve) ──
    $planId1 = PlanGenerator::generate($athleteId, 'coach_manual');
    if (!$planId1 || $planId1 === $planId0) throw new RuntimeException('regen did not produce a new plan');

    // Carried rows: same id, now in new plan, marked, byte-identical content.
    $allByteIdentical = true; $allMarked = true; $allMoved = true;
    foreach ($capById as $id => $before) {
        $after = $db->query("SELECT * FROM planned_workouts WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$after) { $allMoved = false; continue; }
        if ((int)$after['plan_id'] !== $planId1) $allMoved = false;
        if ((int)$after['carried_over_from_plan_id'] !== $planId0 || empty($after['carried_over_at'])) $allMarked = false;
        foreach ($CONTENT as $c) {
            if ((string)($after[$c] ?? '') !== (string)($before[$c] ?? '')) { $allByteIdentical = false; break; }
        }
    }
    check("carried rows moved into the new plan (same ids)", $allMoved);
    check("carried rows marked carried_over_from_plan_id + carried_over_at", $allMarked);
    check("carried rows byte-identical (content + intervals_event_id intact)", $allByteIdentical);

    // The locked row specifically (set b — regenerated region).
    $lockAfter = $db->query("SELECT * FROM planned_workouts WHERE id={$lockId}")->fetch(PDO::FETCH_ASSOC);
    check("coach_locked row in regenerated region preserved (carried, still locked, event intact)",
        $lockAfter && (int)$lockAfter['plan_id'] === $planId1 && (int)$lockAfter['coach_locked'] === 1
        && (int)$lockAfter['carried_over_from_plan_id'] === $planId0
        && (string)$lockAfter['intervals_event_id'] === (string)$capById[$lockId]['intervals_event_id']);

    // Carried dates contain NO fresh row.
    $datesPlace = implode(',', array_fill(0, count($expectedCarriedDates), '?'));
    $freshOnCarried = (int)(function () use ($db, $planId1, $expectedCarriedDates, $datesPlace) {
        $st = $db->prepare("SELECT COUNT(*) FROM planned_workouts WHERE plan_id=? AND carried_over_from_plan_id IS NULL AND scheduled_date IN ($datesPlace)");
        $st->execute(array_merge([$planId1], array_keys($expectedCarriedDates)));
        return $st->fetchColumn();
    })();
    check("carried dates contain no freshly-generated row", $freshOnCarried === 0);

    // Fresh remainder begins at the Monday of the first fully-unexposed week.
    $firstFresh = (string)$db->query(
        "SELECT MIN(scheduled_date) FROM planned_workouts WHERE plan_id={$planId1} AND carried_over_from_plan_id IS NULL"
    )->fetchColumn();
    check("fresh remainder begins on a Monday", $firstFresh !== '' && (int)date('N', strtotime($firstFresh)) === 1);
    check("fresh remainder begins the day after the last exposed Sunday",
        $firstFresh === date('Y-m-d', strtotime($lastExposedSunday . ' +1 day')));

    // Anti-repeat: no carried signature duplicated in the fresh remainder.
    $carriedSigs = [];
    foreach ($capById as $r) if (!empty($r['instance_signature'])) $carriedSigs[(string)$r['instance_signature']] = true;
    $freshSigs = $db->query(
        "SELECT DISTINCT instance_signature FROM planned_workouts
         WHERE plan_id={$planId1} AND carried_over_from_plan_id IS NULL AND instance_signature IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN);
    $dupSig = false;
    foreach ($freshSigs as $s) if (isset($carriedSigs[(string)$s])) { $dupSig = true; break; }
    check("no carried instance_signature duplicated in the fresh remainder", !$dupSig);

    // Generation math intact: the fresh region is a real generated plan.
    $freshCount = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE plan_id={$planId1} AND carried_over_from_plan_id IS NULL")->fetchColumn();
    $freshLongs = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE plan_id={$planId1} AND carried_over_from_plan_id IS NULL AND workout_type='long'")->fetchColumn();
    check("fresh region generated (has workouts)", $freshCount > 0);
    check("fresh region has long runs (structure intact)", $freshLongs > 0);
    check("new plan is pending_approval", (string)$db->query("SELECT status FROM training_plans WHERE id={$planId1}")->fetchColumn() === 'pending_approval');

    // ════════ FULL-WIPE PATH ════════
    // New active plan with exposure, then regen with fullWipe=true → nothing carried.
    $db->prepare("UPDATE training_plans SET status='active' WHERE id=?")->execute([$planId1]);
    $db->prepare("UPDATE planned_workouts SET visible_to_athlete=1 WHERE plan_id=? AND scheduled_date BETWEEN ? AND ?")
       ->execute([$planId1, $firstFresh, date('Y-m-d', strtotime($firstFresh . ' +9 days'))]);

    $planId2 = PlanGenerator::generate($athleteId, 'coach_manual', true); // fullWipe
    check("full-wipe produced a new plan", $planId2 && $planId2 !== $planId1);
    $carriedInWipe = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE plan_id={$planId2} AND carried_over_from_plan_id IS NOT NULL")->fetchColumn();
    check("full-wipe carried nothing (no carried rows)", $carriedInWipe === 0);
    $wipeFresh = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE plan_id={$planId2}")->fetchColumn();
    check("full-wipe rebuilt from scratch (fresh rows exist)", $wipeFresh > 0);

    echo "\n================================\n";
    echo "  Regen carry-over verification\n";
    echo "  PASS: {$pass}   FAIL: {$fail}\n";
    echo "================================\n";

} finally {
    if ($athleteId) {
        foreach (['completed_workouts','planned_workouts','plan_approval_queue','training_plans',
                  'engine_flags','training_load','athlete_profiles'] as $t) {
            try { $db->prepare("DELETE FROM {$t} WHERE athlete_id=?")->execute([$athleteId]); } catch (\Throwable $e) {}
        }
        $db->prepare("DELETE FROM athletes WHERE id=?")->execute([$athleteId]);
    }
    if ($userId) $db->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
}

exit($fail === 0 ? 0 : 1);

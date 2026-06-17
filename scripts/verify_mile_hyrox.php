<?php
/**
 * Mile + Hyrox verification (mile spec). Generates a plan for a mile athlete and a
 * Hyrox athlete, asserts the spec's engine behaviour, then EXPLICITLY deletes the
 * throwaway data it created.
 *
 * NOTE: production tables are MyISAM, so transactions do NOT roll back — this script
 * cleans up by hand (see memory project_myisam_no_transactions). Test users use
 * @example.invalid emails so the cleanup is precisely scoped.
 *
 *     php scripts/verify_mile_hyrox.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Engine/PaceZones.php';
require_once SCRIPT_ROOT . '/src/Engine/ArchetypeSelector.php';
require_once SCRIPT_ROOT . '/src/Engine/PlanGenerator.php';

$db = Database::get();

$PACE_ZONES = json_encode([
    'source' => 'race_result', 'generated_at' => date('Y-m-d'),
    'easy' => ['min' => 480, 'max' => 540], 'long' => ['min' => 480, 'max' => 540],
    'marathon' => 420, 'half_marathon' => 400, '10K' => 372, '5K' => 354,
    'mile' => 300, '800' => 282, '400' => 270,
]);

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  [PASS] " : "  [FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

// Inline replica of goal_distance_label() (views/layout/base.php) to avoid loading the view layer.
$label = function (string $v, bool $hyrox, bool $coach): string {
    if (strtolower($v) === 'mile' && $hyrox) return $coach ? 'Hyrox (Mile training)' : 'Hyrox';
    return $v === 'mile' ? 'Mile / 1500m' : $v;
};

$created = [];
function buildAthlete(PDO $db, int $isHyrox, string $paceZones): int {
    global $created;
    $email = 'mile_verify_' . bin2hex(random_bytes(6)) . '@example.invalid';
    $db->prepare('INSERT INTO users (email, password_hash, role, name) VALUES (?, "x", "athlete", "Mile Test")')->execute([$email]);
    $userId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO athletes (user_id, status) VALUES (?, "active")')->execute([$userId]);
    $athleteId = (int)$db->lastInsertId();
    // Well-trained miler: 6 days, 200 min/wk, 45-min long; hills + pace zones available.
    $db->prepare(
        'INSERT INTO athlete_profiles
         (athlete_id, plan_type, goal_race_distance, is_hyrox, goal_race_date, current_weekly_minutes,
          longest_recent_run_mins, months_at_current_volume, training_days_per_week, must_off_days,
          hill_access, track_field_background, pace_zones, pace_zones_visible)
         VALUES (?, "race_cycle", "mile", ?, ?, 200, 45, 12, 6, "[]", 1, 1, ?, 1)'
    )->execute([$athleteId, $isHyrox, date('Y-m-d', strtotime('+112 days')), $paceZones]);
    $created[] = $email;
    return $athleteId;
}

function workouts(PDO $db, int $planId): array {
    $r = $db->prepare('SELECT scheduled_date, workout_type, archetype_code, target_duration, athlete_instructions
                       FROM planned_workouts WHERE plan_id = ? ORDER BY scheduled_date');
    $r->execute([$planId]);
    return $r->fetchAll(PDO::FETCH_ASSOC);
}
function planWeeks(PDO $db, int $planId): int {
    $r = $db->prepare('SELECT plan_start_date, plan_end_date FROM training_plans WHERE id = ?');
    $r->execute([$planId]);
    $row = $r->fetch(PDO::FETCH_ASSOC);
    // Calendar weeks covering [start, end] inclusive.
    return (int)ceil((strtotime($row['plan_end_date']) - strtotime($row['plan_start_date']) + 86400) / (7 * 86400));
}
function hasFlag(PDO $db, int $aid, string $type): bool {
    $r = $db->prepare('SELECT 1 FROM engine_flags WHERE athlete_id = ? AND flag_type = ? LIMIT 1');
    $r->execute([$aid, $type]); return (bool)$r->fetchColumn();
}
/** Per-week quality + strides counts. */
function weekStats(PDO $db, int $planId, array $ws): array {
    $r = $db->prepare('SELECT plan_start_date FROM training_plans WHERE id = ?');
    $r->execute([$planId]); $start = strtotime($r->fetchColumn());
    $b = [];
    foreach ($ws as $w) {
        $wk = (int)floor((strtotime($w['scheduled_date']) - $start) / (7 * 86400)) + 1;
        if (in_array($w['workout_type'], ['interval','tempo','hill','fartlek','speed','race_pace'], true)) $b[$wk]['q'] = ($b[$wk]['q'] ?? 0) + 1;
        if ($w['archetype_code'] === 'easy_with_strides') $b[$wk]['strides'] = ($b[$wk]['strides'] ?? 0) + 1;
    }
    return $b;
}

try {
    // ── Plan A: mile, is_hyrox=0, well-trained ──────────────────────────────
    echo "\n=== Mile (is_hyrox=0, well-trained) ===\n";
    $aid = buildAthlete($db, 0, $PACE_ZONES);
    $planId = PlanGenerator::generate($aid, 'onboarding');
    $ws = $planId ? workouts($db, $planId) : [];
    check('plan generated', (bool)$planId);
    check('cycle length 16 (got ' . ($planId ? planWeeks($db, $planId) : 0) . ')', $planId && planWeeks($db, $planId) === 16);

    $longs = array_filter($ws, fn($w) => $w['workout_type'] === 'long');
    $longPure = !array_filter($longs, fn($w) => !in_array($w['archetype_code'], ['continuous_long','continuous_easy'], true));
    $maxLong = $longs ? max(array_map(fn($w) => (int)$w['target_duration'], $longs)) : 0;
    check('long runs pure aerobic (continuous_long/easy)', $longs && $longPure);
    check("long runs 60–75 min (max {$maxLong})", $maxLong >= 60 && $maxLong <= 75);

    $speed = count(array_filter($ws, fn($w) => $w['archetype_code'] === 'short_speed_repeats'));
    check("short_speed_repeats appear (got {$speed})", $speed >= 1);

    $citesMilePace = count(array_filter($ws, fn($w) =>
        $w['archetype_code'] === 'short_speed_repeats' && strpos((string)$w['athlete_instructions'], '/mile') !== false));
    check("speed sessions cite pace (got {$citesMilePace})", $citesMilePace >= 1);

    $stats = weekStats($db, $planId, $ws);
    $maxQ = $stats ? max(array_map(fn($x) => $x['q'] ?? 0, $stats)) : 0;
    check("peak reaches 2–3 quality/week (max {$maxQ})", $maxQ >= 2);
    $maxStrides = $stats ? max(array_map(fn($x) => $x['strides'] ?? 0, $stats)) : 0;
    check("strides 3–4×/week in build/peak (max {$maxStrides})", $maxStrides >= 3);
    check('no display_generation_incomplete flag', !hasFlag($db, $aid, 'display_generation_incomplete'));

    echo "    wk  quality  strides\n";
    ksort($stats);
    foreach ($stats as $wk => $x) printf("    %2d  %5d  %7d\n", $wk, $x['q'] ?? 0, $x['strides'] ?? 0);

    // ── Plan B: mile, is_hyrox=1 (Hyrox facade) ─────────────────────────────
    echo "\n=== Hyrox (is_hyrox=1) ===\n";
    $aid2 = buildAthlete($db, 1, $PACE_ZONES);
    $planId2 = PlanGenerator::generate($aid2, 'onboarding');
    check('plan generated', (bool)$planId2);
    check('cycle length 16', $planId2 && planWeeks($db, $planId2) === 16);
    check('hyrox_supplement_reminder flag raised', hasFlag($db, $aid2, 'hyrox_supplement_reminder'));
    check('athlete label is "Hyrox"', $label('mile', true, false) === 'Hyrox');
    check('coach label is "Hyrox (Mile training)"', $label('mile', true, true) === 'Hyrox (Mile training)');
    check('mile (non-hyrox) label is "Mile / 1500m"', $label('mile', false, false) === 'Mile / 1500m');
    check('no display_generation_incomplete flag', !hasFlag($db, $aid2, 'display_generation_incomplete'));
} finally {
    // Explicit cleanup (MyISAM has no rollback).
    if ($created) {
        $in = implode(',', array_fill(0, count($created), '?'));
        $uids = $db->prepare("SELECT id FROM users WHERE email IN ($in)");
        $uids->execute($created);
        $userIds = $uids->fetchAll(PDO::FETCH_COLUMN);
        if ($userIds) {
            $uin = implode(',', array_map('intval', $userIds));
            $aids = $db->query("SELECT id FROM athletes WHERE user_id IN ($uin)")->fetchAll(PDO::FETCH_COLUMN);
            $ain = $aids ? implode(',', array_map('intval', $aids)) : '';
            if ($ain) {
                foreach (['planned_workouts','training_plans','plan_approval_queue','engine_flags','athlete_profiles','races'] as $t) {
                    try { $db->exec("DELETE FROM `$t` WHERE athlete_id IN ($ain)"); } catch (\Throwable $e) {}
                }
                $db->exec("DELETE FROM athletes WHERE id IN ($ain)");
            }
            $db->exec("DELETE FROM users WHERE id IN ($uin)");
            echo "\nCleaned up " . count($userIds) . " test user(s).\n";
        }
    }
}

echo "\n================ {$pass} passed, {$fail} failed ================\n";
exit($fail === 0 ? 0 : 1);

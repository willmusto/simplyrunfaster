<?php
/**
 * Ultra-distance plan verification (ultra spec). Generates a plan for each test
 * athlete inside a transaction that is ALWAYS rolled back, so nothing persists.
 * Prints a PASS/FAIL report against the spec's verification criteria plus a
 * weekly volume + long-run trace for manual inspection.
 *
 *     php scripts/verify_ultra_plans.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Engine/ArchetypeSelector.php';
require_once SCRIPT_ROOT . '/src/Engine/PlanGenerator.php';

$db = Database::get();

$PACE_ZONES = json_encode([
    'source' => 'race_result', 'generated_at' => date('Y-m-d'),
    'easy' => ['min' => 540, 'max' => 600], 'long' => ['min' => 540, 'max' => 600],
    'marathon' => 480, 'half_marathon' => 458, '10K' => 432, '5K' => 414,
    'mile' => 372, '800' => 354, '400' => 342,
]);

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  [PASS] " : "  [FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

/**
 * Create a throwaway athlete + profile, generate a race-cycle plan, return the
 * plan id and a fetched workout set. Caller runs inside a transaction.
 */
function buildAthlete(PDO $db, array $profile): int {
    $email = 'ultra_verify_' . bin2hex(random_bytes(6)) . '@example.invalid';
    $db->prepare('INSERT INTO users (email, password_hash, role, name) VALUES (?, "x", "athlete", "Ultra Test")')
       ->execute([$email]);
    $userId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO athletes (user_id, status) VALUES (?, "active")')->execute([$userId]);
    $athleteId = (int)$db->lastInsertId();

    $cols = array_keys($profile);
    $ph   = implode(', ', array_fill(0, count($cols), '?'));
    $names = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $db->prepare("INSERT INTO athlete_profiles (athlete_id, $names) VALUES (?, $ph)")
       ->execute(array_merge([$athleteId], array_values($profile)));
    return $athleteId;
}

function planWeeks(PDO $db, int $planId): int {
    $r = $db->prepare('SELECT plan_start_date, plan_end_date FROM training_plans WHERE id = ?');
    $r->execute([$planId]);
    $row = $r->fetch(PDO::FETCH_ASSOC);
    return (int)round((strtotime($row['plan_end_date']) - strtotime($row['plan_start_date'])) / (7 * 86400)) + 1;
}

function workouts(PDO $db, int $planId): array {
    $r = $db->prepare('SELECT scheduled_date, workout_type, archetype_code, target_duration, display_title, athlete_instructions
                       FROM planned_workouts WHERE plan_id = ? ORDER BY scheduled_date');
    $r->execute([$planId]);
    return $r->fetchAll(PDO::FETCH_ASSOC);
}

function hasFlag(PDO $db, int $athleteId, string $type): bool {
    $r = $db->prepare('SELECT 1 FROM engine_flags WHERE athlete_id = ? AND flag_type = ? LIMIT 1');
    $r->execute([$athleteId, $type]);
    return (bool)$r->fetchColumn();
}

function weeklyTrace(PDO $db, int $planId, array $ws): void {
    $r = $db->prepare('SELECT plan_start_date FROM training_plans WHERE id = ?');
    $r->execute([$planId]);
    $start = strtotime($r->fetchColumn());
    $buckets = [];
    foreach ($ws as $w) {
        $wk = (int)floor((strtotime($w['scheduled_date']) - $start) / (7 * 86400)) + 1;
        $buckets[$wk]['vol'] = ($buckets[$wk]['vol'] ?? 0) + (int)$w['target_duration'];
        if ($w['workout_type'] === 'long' && ($w['display_title'] !== 'Medium-Long Run')) {
            $buckets[$wk]['long'] = max($buckets[$wk]['long'] ?? 0, (int)$w['target_duration']);
        }
        if ($w['display_title'] === 'Medium-Long Run') $buckets[$wk]['btb'] = ($buckets[$wk]['btb'] ?? 0) + 1;
        if (in_array($w['workout_type'], ['interval','tempo','hill','fartlek','speed'], true)) {
            $buckets[$wk]['q'] = ($buckets[$wk]['q'] ?? 0) + 1;
        }
    }
    ksort($buckets);
    echo "    wk  vol  long  q  btb\n";
    foreach ($buckets as $wk => $b) {
        printf("    %2d  %4d  %4d  %d  %s\n", $wk, $b['vol'] ?? 0, $b['long'] ?? 0, $b['q'] ?? 0, !empty($b['btb']) ? 'B' : '-');
    }
}

$baseProfile = [
    'plan_type' => 'race_cycle', 'scheduling_preference' => 'flex',
    'must_off_days' => '[]', 'hill_access' => 1, 'track_field_background' => 1,
    'pace_zones' => $PACE_ZONES, 'pace_zones_visible' => 1, 'months_at_current_volume' => 12,
];

// ── Case 1: 50K trail (well trained), ~18 weeks out ─────────────────────────
echo "\n=== Case 1: 50K trail (well-trained) ===\n";
$db->beginTransaction();
try {
    $p = $baseProfile + [
        'goal_race_distance' => '50k', 'ultra_surface' => 'trail',
        'goal_race_date' => date('Y-m-d', strtotime('+126 days')),
        'current_weekly_minutes' => 400, 'longest_recent_run_mins' => 130, 'training_days_per_week' => 6,
    ];
    $aid = buildAthlete($db, $p);
    $planId = PlanGenerator::generate($aid, 'onboarding');
    $ws = $planId ? workouts($db, $planId) : [];
    check('plan generated', (bool)$planId);
    $w = $planId ? planWeeks($db, $planId) : 0;
    check("cycle length 16–20 (got {$w})", $w >= 16 && $w <= 20);
    $maxLong = 0; foreach ($ws as $x) if ($x['workout_type']==='long' && $x['display_title']!=='Medium-Long Run') $maxLong = max($maxLong, (int)$x['target_duration']);
    check("long run peak 180–210 (got {$maxLong})", $maxLong >= 180 && $maxLong <= 210);
    $btb = count(array_filter($ws, fn($x)=>$x['display_title']==='Medium-Long Run'));
    check("back-to-back present (got {$btb})", $btb >= 1);
    $trailCue = count(array_filter($ws, fn($x)=>$x['workout_type']==='long' && stripos((string)$x['athlete_instructions'],'time on feet')!==false));
    check("trail cue in long-run instructions (got {$trailCue})", $trailCue >= 1);
    check('ultra_surface_reminder flag raised', hasFlag($db, $aid, 'ultra_surface_reminder'));
    check('no display_generation_incomplete flag', !hasFlag($db, $aid, 'display_generation_incomplete'));
    $trailHills = count(array_filter($ws, fn($x)=>in_array($x['archetype_code'],['sustained_hill_repeats','hill_sprints'],true)));

    // road comparison
    $p2 = $baseProfile + ['goal_race_distance'=>'50k','ultra_surface'=>'road','goal_race_date'=>date('Y-m-d', strtotime('+126 days')),'current_weekly_minutes'=>400,'longest_recent_run_mins'=>130,'training_days_per_week'=>6];
    $aid2 = buildAthlete($db, $p2);
    $pid2 = PlanGenerator::generate($aid2,'onboarding');
    $ws2 = $pid2 ? workouts($db,$pid2) : [];
    $roadHills = count(array_filter($ws2, fn($x)=>in_array($x['archetype_code'],['sustained_hill_repeats','hill_sprints'],true)));
    check("trail hills ({$trailHills}) >= road hills ({$roadHills})", $trailHills >= $roadHills);
    weeklyTrace($db, $planId, $ws);
} finally { $db->rollBack(); }

// ── Case 2: 100 miler road, ~28 weeks out ───────────────────────────────────
echo "\n=== Case 2: 100 miler road ===\n";
$db->beginTransaction();
try {
    $p = $baseProfile + [
        'goal_race_distance' => '100_miler', 'ultra_surface' => 'road',
        'goal_race_date' => date('Y-m-d', strtotime('+196 days')),
        'current_weekly_minutes' => 600, 'longest_recent_run_mins' => 200, 'training_days_per_week' => 6,
    ];
    $aid = buildAthlete($db, $p);
    $planId = PlanGenerator::generate($aid, 'onboarding');
    $ws = $planId ? workouts($db, $planId) : [];
    check('plan generated', (bool)$planId);
    $w = $planId ? planWeeks($db, $planId) : 0;
    check("cycle length 24–32 (got {$w})", $w >= 24 && $w <= 32);
    $btb = count(array_filter($ws, fn($x)=>$x['display_title']==='Medium-Long Run'));
    check("back-to-back present (got {$btb})", $btb >= 1);
    $cites = count(array_filter($ws, fn($x)=>strpos((string)$x['athlete_instructions'],'/mile')!==false));
    check("no pace citations anywhere (got {$cites})", $cites === 0);
    // quality in last 20% of weeks should be ~0
    $r=$db->prepare('SELECT plan_start_date,plan_end_date FROM training_plans WHERE id=?'); $r->execute([$planId]); $row=$r->fetch();
    $peakCut = strtotime($row['plan_end_date']) - 21*86400; // last ~3 weeks
    $qPeak = count(array_filter($ws, fn($x)=>strtotime($x['scheduled_date'])>=$peakCut && in_array($x['workout_type'],['interval','tempo','hill','fartlek','speed'],true)));
    check("quality rare/absent in final weeks (got {$qPeak})", $qPeak === 0);
    check('no display_generation_incomplete flag', !hasFlag($db, $aid, 'display_generation_incomplete'));
    weeklyTrace($db, $planId, $ws);
} finally { $db->rollBack(); }

// ── Case 3: 50 miler trail ──────────────────────────────────────────────────
echo "\n=== Case 3: 50 miler trail ===\n";
$db->beginTransaction();
try {
    $p = $baseProfile + [
        'goal_race_distance' => '50_miler', 'ultra_surface' => 'trail',
        'goal_race_date' => date('Y-m-d', strtotime('+154 days')),
        'current_weekly_minutes' => 460, 'longest_recent_run_mins' => 150, 'training_days_per_week' => 6,
    ];
    $aid = buildAthlete($db, $p);
    $planId = PlanGenerator::generate($aid, 'onboarding');
    $ws = $planId ? workouts($db, $planId) : [];
    check('plan generated', (bool)$planId);
    $w = $planId ? planWeeks($db, $planId) : 0;
    check("cycle length 20–24 (got {$w})", $w >= 20 && $w <= 24);
    $ph = count(array_filter($ws, fn($x)=>$x['workout_type']==='long' && stripos((string)$x['athlete_instructions'],'power hike')!==false));
    check("power-hiking language in long runs (got {$ph})", $ph >= 1);
    check('no display_generation_incomplete flag', !hasFlag($db, $aid, 'display_generation_incomplete'));
    weeklyTrace($db, $planId, $ws);
} finally { $db->rollBack(); }

// ── Case 4: 100K road (cadence sanity) ──────────────────────────────────────
echo "\n=== Case 4: 100K road ===\n";
$db->beginTransaction();
try {
    $p = $baseProfile + [
        'goal_race_distance' => '100k', 'ultra_surface' => 'road',
        'goal_race_date' => date('Y-m-d', strtotime('+168 days')),
        'current_weekly_minutes' => 520, 'longest_recent_run_mins' => 170, 'training_days_per_week' => 6,
    ];
    $aid = buildAthlete($db, $p);
    $planId = PlanGenerator::generate($aid, 'onboarding');
    $ws = $planId ? workouts($db, $planId) : [];
    check('plan generated', (bool)$planId);
    $w = $planId ? planWeeks($db, $planId) : 0;
    check("cycle length 22–26 (got {$w})", $w >= 22 && $w <= 26);
    $cites = count(array_filter($ws, fn($x)=>strpos((string)$x['athlete_instructions'],'/mile')!==false));
    check("no pace citations (100K effort-only) (got {$cites})", $cites === 0);
    check('no display_generation_incomplete flag', !hasFlag($db, $aid, 'display_generation_incomplete'));
    weeklyTrace($db, $planId, $ws);
} finally { $db->rollBack(); }

echo "\n================ {$pass} passed, {$fail} failed ================\n";
exit($fail === 0 ? 0 : 1);

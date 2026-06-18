<?php
/**
 * One-time: regenerate Matthew Jenkins's plan with the fixed engine and verify the
 * three bug fixes (NULL base_classification, 7-day rest day, Saturday rest bias).
 * Run: php /home/private/app/scripts/regenerate_matthew.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
foreach ([SCRIPT_ROOT . '/config/config.local.php', '/home/public/config/config.local.php'] as $cfg) {
    if (file_exists($cfg)) { require $cfg; break; }
}
defined('DB_HOST')    || define('DB_HOST',    'localhost');
defined('DB_NAME')    || define('DB_NAME',    'simplyrunfaster');
defined('DB_USER')    || define('DB_USER',    'root');
defined('DB_PASS')    || define('DB_PASS',    '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8');

$db = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$m = $db->query("SELECT a.id AS athlete_id, u.name
                 FROM athletes a JOIN users u ON u.id = a.user_id
                 WHERE u.name LIKE '%Matthew Jenkins%' LIMIT 1")->fetch();
if (!$m) { echo "No athlete named Matthew Jenkins found.\n"; exit(1); }

$athleteId = (int)$m['athlete_id'];
echo "Found: {$m['name']} (athlete_id={$athleteId})\n\n";

$prof = $db->prepare('SELECT plan_type, goal_race_distance, goal_race_date, training_days_per_week,
                             scheduling_preference, must_off_days, long_run_day, primary_workout_day,
                             base_classification
                      FROM athlete_profiles WHERE athlete_id = ? LIMIT 1');
$prof->execute([$athleteId]);
$p = $prof->fetch();
echo "Profile:\n";
echo "  plan_type             = {$p['plan_type']}\n";
echo "  goal_race_distance    = {$p['goal_race_distance']}\n";
echo "  goal_race_date        = " . ($p['goal_race_date'] ?? 'NULL') . "\n";
echo "  training_days_per_week= {$p['training_days_per_week']}\n";
echo "  scheduling_preference = {$p['scheduling_preference']}\n";
echo "  must_off_days         = {$p['must_off_days']}\n";
echo "  long_run_day          = " . ($p['long_run_day'] ?? 'NULL') . "\n";
echo "  primary_workout_day   = " . ($p['primary_workout_day'] ?? 'NULL') . "\n";
echo "  base_classification   = " . ($p['base_classification'] ?? 'NULL') . "  (BEFORE)\n\n";

// Delete pending/active plans
$pending = $db->prepare("SELECT id, plan_type, status FROM training_plans
                         WHERE athlete_id = ? AND status IN ('pending_approval','active')");
$pending->execute([$athleteId]);
foreach ($pending->fetchAll() as $pl) {
    echo "  Deleting plan id={$pl['id']} ({$pl['plan_type']}, {$pl['status']})...\n";
    $db->prepare('DELETE FROM plan_approval_queue WHERE plan_id = ?')->execute([$pl['id']]);
    $db->prepare('DELETE FROM planned_workouts WHERE plan_id = ?')->execute([$pl['id']]);
    $db->prepare('DELETE FROM training_plans WHERE id = ?')->execute([$pl['id']]);
}
echo "\n";

require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Engine/TrainingLoad.php';
require_once SCRIPT_ROOT . '/src/Engine/ArchetypeSelector.php';
require_once SCRIPT_ROOT . '/src/Engine/PlanGenerator.php';

echo "Regenerating plan...\n";
$planId = PlanGenerator::generate($athleteId, 'coach_manual');
if (!$planId) { echo "PlanGenerator returned null — check athlete profile data.\n"; exit(1); }

// BUG 1 — base_classification populated?
$prof->execute([$athleteId]);
$after = $prof->fetch();
echo "\n=== BUG 1: base_classification ===\n";
echo "  base_classification = " . ($after['base_classification'] ?? 'NULL') . "  (AFTER)\n";

$plan = $db->prepare('SELECT plan_type, plan_start_date, plan_end_date FROM training_plans WHERE id = ? LIMIT 1');
$plan->execute([$planId]);
$pp = $plan->fetch();
echo "\nNew plan id={$planId} ({$pp['plan_type']}) {$pp['plan_start_date']} → {$pp['plan_end_date']}\n";

// BUG 2/3 — per-week day grid (Sun..Sat). Mark training vs rest; flag rest days.
$rows = $db->prepare('SELECT scheduled_date, workout_type FROM planned_workouts
                      WHERE plan_id = ? ORDER BY scheduled_date');
$rows->execute([$planId]);
$byDate = [];
foreach ($rows->fetchAll() as $r) { $byDate[$r['scheduled_date']] = $r['workout_type']; }

// Build weeks aligned to the first Monday of the plan (code-week grid).
$dayNames = ['Su','Mo','Tu','We','Th','Fr','Sa'];
$dates = array_keys($byDate);
sort($dates);
if (!$dates) { echo "No workouts.\n"; exit(1); }

echo "\n=== BUG 2/3: weekly day grid (rest day positions) ===\n";
echo "      " . implode('   ', $dayNames) . "\n";

$start    = strtotime($dates[0]);
$end      = strtotime(end($dates));
$satRest  = 0; $totalRestWeeks = 0; $weekNum = 0;
// Align to the Sunday on/before the first workout so columns line up to day-of-week.
$cur = strtotime('last sunday', $start + 86400); // sunday on/before start
for ($wkStart = $cur; $wkStart <= $end; $wkStart += 7 * 86400) {
    $weekNum++;
    $cells = []; $trainCount = 0; $restDays = [];
    for ($i = 0; $i < 7; $i++) {
        $d   = date('Y-m-d', $wkStart + $i * 86400);
        $wt  = $byDate[$d] ?? null;
        if ($wt === null) { $cells[] = ' . '; continue; }    // outside plan range
        if ($wt === 'rest') {
            $cells[] = 'RST';
            $restDays[] = $i;
            if ($i === 6) $satRest++;
        } else {
            $cells[] = 'run';
            $trainCount++;
        }
    }
    if ($restDays) $totalRestWeeks++;
    printf("W%-2d  %s   train=%d rest=[%s]\n", $weekNum,
        implode('   ', $cells), $trainCount,
        implode(',', array_map(fn($i) => $dayNames[$i], $restDays)));
}

echo "\nSummary:\n";
echo "  weeks with >=1 rest day        = {$totalRestWeeks}\n";
echo "  weeks whose rest day is Saturday = {$satRest}\n";
echo "  (Matthew requested {$p['training_days_per_week']} days/week; expect 7 train / 0 rest on full-volume weeks.)\n";
echo "\nPlan is in approval queue with status=pending.\n";

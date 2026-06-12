<?php
/**
 * One-time: delete Liam's pending/bad plans, regenerate with fixed engine.
 * Run: php /home/private/app/scripts/regenerate_liam.php
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

// Find Liam
$liam = $db->query("SELECT a.id as athlete_id, u.name
                    FROM athletes a JOIN users u ON u.id = a.user_id
                    WHERE u.name LIKE '%Liam%' LIMIT 1")->fetch();

if (!$liam) {
    echo "No athlete named Liam found.\n";
    exit(1);
}

$athleteId = (int)$liam['athlete_id'];
echo "Found: {$liam['name']} (athlete_id={$athleteId})\n\n";

// List plans to delete
$pending = $db->prepare(
    "SELECT tp.id, tp.plan_type, tp.status, COUNT(pw.id) as workout_count
     FROM training_plans tp
     LEFT JOIN planned_workouts pw ON pw.plan_id = tp.id
     WHERE tp.athlete_id = ? AND tp.status IN ('pending_approval','active')
     GROUP BY tp.id"
);
$pending->execute([$athleteId]);
$plans = $pending->fetchAll();

if (empty($plans)) {
    echo "No pending/active plans found. Nothing to delete.\n";
} else {
    foreach ($plans as $p) {
        echo "  Deleting plan id={$p['id']} ({$p['plan_type']}, {$p['workout_count']} workouts)...\n";
        $db->prepare('DELETE FROM plan_approval_queue WHERE plan_id = ?')->execute([$p['id']]);
        $db->prepare('DELETE FROM planned_workouts WHERE plan_id = ?')->execute([$p['id']]);
        $db->prepare('DELETE FROM training_plans WHERE id = ?')->execute([$p['id']]);
    }
    echo "Deleted " . count($plans) . " plan(s).\n\n";
}

// Load engine classes and regenerate
require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Engine/TrainingLoad.php';
require_once SCRIPT_ROOT . '/src/Engine/ArchetypeSelector.php';
require_once SCRIPT_ROOT . '/src/Engine/PlanGenerator.php';

echo "Regenerating plan...\n";
$planId = PlanGenerator::generate($athleteId, 'coach_manual');

if ($planId) {
    $plan = $db->prepare('SELECT plan_type, plan_start_date, plan_end_date FROM training_plans WHERE id = ? LIMIT 1');
    $plan->execute([$planId]);
    $p = $plan->fetch();

    $wc = $db->prepare('SELECT COUNT(*) FROM planned_workouts WHERE plan_id = ?');
    $wc->execute([$planId]);

    echo "  New plan id={$planId} ({$p['plan_type']}) {$p['plan_start_date']} → {$p['plan_end_date']}\n";
    echo "  Workouts: " . $wc->fetchColumn() . "\n\n";

    // Spot-check spacing — show first 3 weeks' training days
    $sample = $db->prepare(
        'SELECT scheduled_date, workout_type FROM planned_workouts
         WHERE plan_id = ? ORDER BY scheduled_date LIMIT 21'
    );
    $sample->execute([$planId]);
    echo "First 3 weeks (training days only):\n";
    foreach ($sample->fetchAll() as $row) {
        echo '  ' . date('D M j', strtotime($row['scheduled_date'])) . '  ' . $row['workout_type'] . "\n";
    }
    echo "\nPlan is in approval queue with status=pending.\n";

    // Spot-check display output for a sample of structured workouts
    $displaySample = $db->prepare(
        "SELECT archetype_code, display_title,
                LEFT(athlete_instructions, 120) AS instr_preview,
                display_summary
         FROM planned_workouts
         WHERE plan_id = ?
           AND archetype_code IS NOT NULL
           AND archetype_code NOT IN ('continuous_easy','continuous_long')
         ORDER BY scheduled_date
         LIMIT 10"
    );
    $displaySample->execute([$planId]);
    echo "\nSample structured workout display output:\n";
    foreach ($displaySample->fetchAll() as $w) {
        printf("  %-32s | %-28s\n  instr: %s\n",
            $w['display_title'] ?? '—',
            $w['display_summary'] ?? '—',
            $w['instr_preview'] ?? '—'
        );
        echo "\n";
    }
} else {
    echo "PlanGenerator returned null — check athlete profile data.\n";
    exit(1);
}

<?php
/**
 * Engine v1 Setup Script
 *
 * Run once after deploying Milestone 3 code:
 *   php /home/private/app/scripts/setup_engine_v1.php
 *
 * Idempotent — safe to run multiple times.
 * - Adds library_code column to workout_library
 * - Expands engine_flags.flag_type enum
 * - Seeds all 23 workout library templates
 */

define('SCRIPT_ROOT', dirname(__DIR__));

// Load config for DB credentials
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

$db = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo "SimplyRunFaster — Engine v1 Setup\n";
echo "===================================\n\n";

// ── Migrations ────────────────────────────────────────────────────────────────

echo "Migrations...\n";

$cols = $db->query("SHOW COLUMNS FROM workout_library LIKE 'library_code'")->fetchAll();
if (empty($cols)) {
    $db->exec("ALTER TABLE workout_library ADD COLUMN library_code VARCHAR(20) NULL");
    $db->exec("ALTER TABLE workout_library ADD UNIQUE INDEX idx_library_code (library_code)");
    echo "  ✓ Added workout_library.library_code\n";
} else { echo "  · workout_library.library_code exists\n"; }

// engine_flags.flag_type — add insufficient_base
try {
    $db->exec("ALTER TABLE engine_flags MODIFY COLUMN flag_type ENUM(
        'missed_workouts','hr_elevated','load_spike','compliance_low',
        'plan_rebuild_needed','compliance_trend','compliance_pattern',
        'excessive_fatigue','fitness_decline','taper_concern',
        'insufficient_base','return_to_running_discomfort',
        'limited_development_opportunity','display_generation_incomplete'
    )");
    echo "  ✓ Updated engine_flags.flag_type enum\n";
} catch (PDOException $e) {
    echo "  · engine_flags.flag_type: " . $e->getMessage() . "\n";
}

echo "\n";

// ── Workout library seed ──────────────────────────────────────────────────────

echo "Seeding workout library...\n";

/**
 * Templates array. Each entry:
 * [library_code, name, athlete_facing_name, workout_type,
 *  phase_tags (JSON), distance_tags (JSON), prescription_type,
 *  track_required, intensity_factor, coach_clearance_required,
 *  description, engine_notes]
 */
$templates = [
    [
        'code'        => 'WL-001',
        'name'        => 'Easy Run',
        'af_name'     => 'Easy run',
        'type'        => 'easy',
        'phases'      => ['base','build','peak','taper'],
        'distances'   => ['5K','10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.50,
        'clearance'   => 0,
        'description' => 'An easy, conversational effort. You should be able to speak in full sentences throughout. Don\'t watch your pace — run by feel and keep it relaxed. If you\'re running with someone, you should be able to hold a full conversation without gasping.',
        'notes'       => 'Default easy run template. Used to fill non-quality days. Duration scaled by engine based on weekly volume target and phase. HR compliance monitored passively — no RPE collected.',
    ],
    [
        'code'        => 'WL-002',
        'name'        => 'Easy Run with Strides',
        'af_name'     => 'Easy run with strides',
        'type'        => 'easy',
        'phases'      => ['base','build','peak','taper'],
        'distances'   => ['5K','10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.55,
        'clearance'   => 0,
        'description' => 'An easy run finishing with strides. Run the main portion at a comfortable, conversational effort. In the final 10 minutes, find a flat stretch and run 4–6 strides of 20–25 seconds each. A stride is a smooth, controlled acceleration to about 85–90% effort — fast but not sprinting, relaxed but not jogging. Walk or jog 60–90 seconds between each stride.',
        'notes'       => 'Preferred easy run template when speed stimulus is called for. Engine selects this over WL-001 for 1–2 easy runs per week. Stride count: 4 for workable base, 6 for well-trained.',
    ],
    [
        'code'        => 'WL-003',
        'name'        => 'Pure Aerobic Long Run',
        'af_name'     => 'Long run',
        'type'        => 'long',
        'phases'      => ['base','build','peak','taper'],
        'distances'   => ['5K','10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.60,
        'clearance'   => 0,
        'description' => 'A long easy effort. The goal is time on your feet at a comfortable, sustainable pace. This should feel genuinely easy — not moderate, not controlled hard, actually easy. Err on the side of going too slow rather than too fast. The purpose of this run is aerobic development and that happens best when you\'re relaxed and recovered enough to run again tomorrow.',
        'notes'       => 'Used for ~25-30% of long runs across a cycle. Always used within 7 days of any race. Also used for base phase long runs and athlete\'s first long run in a new cycle.',
    ],
    [
        'code'        => 'WL-004',
        'name'        => 'Progression Long Run',
        'af_name'     => 'Progression long run',
        'type'        => 'long',
        'phases'      => ['base','build'],
        'distances'   => ['10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.70,
        'clearance'   => 0,
        'description' => 'A long run that builds in effort over time. Start at a genuinely easy pace and let the effort increase naturally as you go. Think of it as three loosely defined sections: easy for the first third, comfortable but engaged for the middle third, and honest but sustainable for the final third. You should never feel like you\'re racing, but by the end you should feel like you\'ve run.',
        'notes'       => 'Primary long run template for base and early build phases. Introduces progressive effort without requiring pace discipline. HR data used to assess compliance.',
    ],
    [
        'code'        => 'WL-005',
        'name'        => 'Long Run with Goal Pace Segments',
        'af_name'     => 'Long run with goal pace segments',
        'type'        => 'long',
        'phases'      => ['build','peak'],
        'distances'   => ['half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.85,
        'clearance'   => 0,
        'description' => 'A long run with segments at your goal race pace built in. You\'ll run the opening and closing portions at easy effort, with one or more segments in the middle at the pace you\'re aiming to run on race day. The pace segments should feel honest but sustainable. Run the non-pace portions genuinely easy.',
        'notes'       => 'Core build and peak phase long run. Segment count and duration increase through the cycle. Engine pulls goal pace from athlete\'s pace zone profile. RPE collected post-activity.',
    ],
    [
        'code'        => 'WL-006',
        'name'        => 'Long Run with Cutdown Finish',
        'af_name'     => 'Long run with cutdown finish',
        'type'        => 'long',
        'phases'      => ['build','peak'],
        'distances'   => ['10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.85,
        'clearance'   => 0,
        'description' => 'A long run that finishes faster than it starts. Run the majority at easy effort, then in the final 20–30 minutes begin picking up the pace progressively. The closing miles should be noticeably faster than your opening miles — controlled acceleration: each mile a little faster, finishing at a pace that feels honest and strong but not all-out. The combination of fatigue and faster running in the final portion is the point.',
        'notes'       => 'Hanson-influenced — cutdown on tired legs is intentional training stress. Peak phase variant may have more aggressive final target pace. RPE collected post-activity.',
    ],
    [
        'code'        => 'WL-007',
        'name'        => 'Descending Time Fartlek',
        'af_name'     => 'Descending fartlek',
        'type'        => 'fartlek',
        'phases'      => ['base','build'],
        'distances'   => ['5K','10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.70,
        'clearance'   => 0,
        'description' => 'A fartlek session with two rounds of descending effort bursts. After a comfortable warmup, run hard for 90 seconds, jog easily for 3 minutes, run hard for 60 seconds, jog easily for 2 minutes, run hard for 30 seconds, jog easily for 1 minute — then repeat the whole sequence. The hard efforts should be at a pace you could sustain for several minutes. Finish with an easy cooldown. Total: approximately 50–60 minutes.',
        'notes'       => 'Drawn from coach\'s base phase training. Recovery ratio is double the effort duration. Base phase staple for all distances. Can serve as secondary workout for athletes on 5+ day schedules.',
    ],
    [
        'code'        => 'WL-008',
        'name'        => 'Track Intervals: 1000m Repeats',
        'af_name'     => '1000m repeats',
        'type'        => 'interval',
        'phases'      => ['build','peak'],
        'distances'   => ['5K','10K','half'],
        'presc'       => 'distance',
        'track'       => 'preferred',
        'factor'      => 1.00,
        'clearance'   => 0,
        'description' => '1000m repeats at your 5K effort. Each repeat should feel like a hard, controlled effort — the kind of pace you could race at for about 20 minutes. The rest between repeats is full recovery — take the full time even if you feel good, because the goal is to run each repeat at the same quality as the first. Warmup: 15–20 min easy + 4 strides. Main set: 5–8 × 1000m. Recovery: 2:30–3:00 jog. Cooldown: 10–15 min.',
        'notes'       => 'Rep count: 5 for workable/early build; 6–7 for build; 8 for peak/well-trained. RPE expected: Hard to Very Hard.',
    ],
    [
        'code'        => 'WL-009',
        'name'        => 'Track Intervals: Mixed Distance Session',
        'af_name'     => 'Mixed track session',
        'type'        => 'interval',
        'phases'      => ['build','peak'],
        'distances'   => ['5K','10K'],
        'presc'       => 'distance',
        'track'       => 'preferred',
        'factor'      => 1.00,
        'clearance'   => 0,
        'description' => 'A two-part track session combining longer repeats with short speed work. Warmup: 15–20 min easy + 4 strides. Part 1: 4 × 1000m at 5K effort / 3:00 recovery jog. Part 2: 4 × 200m at mile effort / 2:00 recovery. Cooldown: 10–15 min. The longer repeats build your ability to sustain race pace; the shorter efforts develop top-end speed.',
        'notes'       => 'Hart-influenced — short speed work after longer intervals develops the top-end ceiling. RPE expected: Very Hard.',
    ],
    [
        'code'        => 'WL-010',
        'name'        => 'Track Session: 1600m + 300m',
        'af_name'     => '1600m and 300m session',
        'type'        => 'interval',
        'phases'      => ['build','peak'],
        'distances'   => ['5K','10K','half'],
        'presc'       => 'distance',
        'track'       => 'preferred',
        'factor'      => 1.00,
        'clearance'   => 0,
        'description' => 'A two-part session: longer mile-effort repeats followed by short, fast 300m efforts. Warmup: 15–20 min easy + 4 strides. Part 1: 4 × 1600m at mile/5K effort / 2:30 recovery. Part 2: 2 × 300m at 800m effort / 2:00 recovery. Cooldown: 10–15 min. The mile repeats train sustained hard effort; the 300m efforts develop top-end speed.',
        'notes'       => 'Strong Hart influence in the 300m speed work. Particularly valuable for athletes targeting 5K–10K. RPE expected: Very Hard.',
    ],
    [
        'code'        => 'WL-011',
        'name'        => 'Sustained Hill Circuit',
        'af_name'     => 'Hill circuit',
        'type'        => 'hill',
        'phases'      => ['build','peak'],
        'distances'   => ['5K','10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.70,
        'clearance'   => 0,
        'description' => 'A hill circuit session — repeated climbs of a substantial hill with jog descents as recovery. Find a hill that takes 2–4 minutes to climb at a hard but sustainable effort. Run up at a strong, controlled pace — approximately 10K effort. Jog back down easily for recovery. Warmup: 15–20 min. Main set: 3–6 hill circuits. Cooldown: 10–15 min.',
        'notes'       => 'Circuit count: 3 for early build/workable; 4–5 for mid-build; 6 for peak/well-trained. RPE expected: Hard.',
    ],
    [
        'code'        => 'WL-012',
        'name'        => 'Hill Sprint Session',
        'af_name'     => 'Hill sprints',
        'type'        => 'hill',
        'phases'      => ['base','build'],
        'distances'   => ['5K','10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.70,
        'clearance'   => 0,
        'description' => 'Short, steep hill sprints. Find a short, steep hill (10–15 seconds to sprint up). Sprint up hard — close to all-out — focusing on driving your knees and pumping your arms. Walk back down completely. Warmup: 20 min easy. Main set: 8–12 × 10–15 second hill sprints, walk-back recovery. Cooldown: 10 min easy. These are neuromuscular — you should feel fast and springy, not tired and heavy.',
        'notes'       => 'Sprint count: 8 for base/workable; 10 for build; 12 for peak/well-trained. Full walk-back recovery essential. RPE: Hard to Very Hard on individual sprints, Easy overall session fatigue.',
    ],
    [
        'code'        => 'WL-013',
        'name'        => 'Hill Bounding and Skipping Circuits',
        'af_name'     => 'Hill bounding circuits',
        'type'        => 'hill',
        'phases'      => ['base','build'],
        'distances'   => ['5K','10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.75,
        'clearance'   => 1,
        'description' => 'Hill circuits combining bounding and skipping — plyometric work that builds explosive power and running economy. Your coach will walk you through the form before your first session. Warmup: 20 min easy including dynamic drills. Main set: 6 circuits — alternating 3 bounding circuits and 3 skipping circuits with jog descent recovery. Cooldown: 10–15 min easy.',
        'notes'       => 'Requires coach_clearance. Until cleared, engine substitutes WL-012 automatically. Onboarding question about track/field background sets clearance automatically. RPE collected post-activity.',
    ],
    [
        'code'        => 'WL-014',
        'name'        => 'Tempo Intervals',
        'af_name'     => 'Tempo intervals',
        'type'        => 'tempo',
        'phases'      => ['build','peak'],
        'distances'   => ['10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.80,
        'clearance'   => 0,
        'description' => 'Sustained tempo efforts with recovery between. Tempo pace is "comfortably hard" — a pace you could hold for about an hour in a race. You should be able to say a few words but not hold a conversation. Warmup: 15–20 min easy. Main set: 3–5 × 8–12 minutes at tempo effort / 3–4 minutes easy jog recovery. Cooldown: 10–15 min.',
        'notes'       => 'Rep/duration: 3 × 8 min for early build/workable; 4 × 10 min for mid-build; 5 × 12 min for peak/well-trained. RPE expected: Hard.',
    ],
    [
        'code'        => 'WL-015',
        'name'        => 'Steady State Progression Run',
        'af_name'     => 'Steady state progression',
        'type'        => 'tempo',
        'phases'      => ['base','build'],
        'distances'   => ['5K','10K','half'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.75,
        'clearance'   => 0,
        'description' => 'A continuous run that builds in effort from aerobic to threshold. Start at a comfortable aerobic pace and let the effort increase gradually over the run. By the final portion you should be running at a pace that requires focus and feels genuinely hard — but this isn\'t a race finish. Think of it as a controlled build where each mile is a little more honest than the last. Total: 25–45 minutes.',
        'notes'       => 'Simpler than tempo intervals — no recovery built in, continuous effort. Good transition workout between easy running and structured intervals. RPE expected: Moderate to Hard.',
    ],
    [
        'code'        => 'WL-016',
        'name'        => 'Long Run with Fartlek Pickups',
        'af_name'     => 'Long run with pickups',
        'type'        => 'long',
        'phases'      => ['base','build'],
        'distances'   => ['half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.75,
        'clearance'   => 0,
        'description' => 'A long run with short pickups scattered throughout. Run the majority at easy effort, but every 8–10 minutes insert a 60-second pickup at a noticeably faster pace — not a sprint, but a meaningful surge. After each pickup, return to easy effort and recover fully before the next one. The run should feel easy overall with brief moments of honest effort.',
        'notes'       => 'Introduces a speed stimulus within the aerobic long run without disrupting recovery. Pickup count scales with run duration. RPE collected post-activity.',
    ],
    [
        'code'        => 'WL-017',
        'name'        => 'Pre-Race Activation Run',
        'af_name'     => 'Pre-race shakeout',
        'type'        => 'easy',
        'phases'      => ['taper'],
        'distances'   => ['5K','10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.55,
        'clearance'   => 0,
        'description' => 'An easy shakeout run the day before your race with strides to wake up your legs. Keep the easy portion genuinely easy — this run has one job: make you feel loose and ready without tiring you out. Easy run: 20–30 minutes. Strides: 4–6 × 20 seconds at 85–90% effort / 60–90 seconds walk recovery. If anything feels off, cut it short. There is nothing to gain by pushing this run.',
        'notes'       => 'Prescribed the day before any race. Stride count reduced to 4 for marathon athletes, 6 for shorter distances. HR compliance not assessed.',
    ],
    [
        'code'        => 'WL-018',
        'name'        => 'Recovery Run',
        'af_name'     => 'Recovery run',
        'type'        => 'recovery',
        'phases'      => ['build','peak','taper'],
        'distances'   => ['5K','10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.30,
        'clearance'   => 0,
        'description' => 'An easy, short run at a genuinely relaxed effort. This run exists to promote recovery, not to add fitness. Run slower than you think you need to. If you feel like you\'re barely running, you\'re probably doing it right. No HR targets, no pace targets — just easy movement to flush the legs and stay loose. 20–30 minutes only.',
        'notes'       => 'Prescribed the day after a race or hard workout when ATL is high. Duration capped at 30 minutes. Prescribed reactively by rolling adjustment logic.',
    ],
    [
        'code'        => 'WL-019',
        'name'        => 'Beginner Stride Session',
        'af_name'     => 'Beginner speed session',
        'type'        => 'interval',
        'phases'      => ['base'],
        'distances'   => ['5K','10K'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.70,
        'clearance'   => 0,
        'description' => 'A structured speed session designed to introduce fast running. Warmup: 15 minutes easy jog. Main set: 10 × 20-second sprints at near-maximum effort / walk-back recovery (approximately 60–90 seconds). Cooldown: 10 minutes easy jog. Total: approximately 35–40 minutes. Focus on running tall, driving your knees, and relaxing your hands and face.',
        'notes'       => 'Beginner-appropriate substitute for WL-013 and WL-012. Prescribed for athletes classified as insufficient or early-workable base.',
    ],
    [
        'code'        => 'WL-020',
        'name'        => 'Descending Speed Fartlek',
        'af_name'     => 'Speed fartlek',
        'type'        => 'fartlek',
        'phases'      => ['build','peak'],
        'distances'   => ['5K','10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.70,
        'clearance'   => 0,
        'description' => 'A fartlek session with four rounds of descending, sharp efforts. Warmup: 15–20 min. Each round: 1 min hard (10K effort) / 1 min easy jog, then 30 sec harder (5K effort) / 1 min easy jog, then 15 sec near-sprint (mile effort or faster) / 1 min easy jog. Easy jog 2–3 min between rounds. Cooldown: 10–15 min. Total: approximately 55–65 minutes.',
        'notes'       => 'Harder and more speed-specific than WL-007. The 15-second efforts are explicit Hart-influenced top-end speed development. RPE expected: Hard to Very Hard.',
    ],
    [
        'code'        => 'WL-021',
        'name'        => 'High Volume Tempo Intervals (20×2/1)',
        'af_name'     => '20×2 tempo session',
        'type'        => 'interval',
        'phases'      => ['build','peak'],
        'distances'   => ['half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 1.00,
        'clearance'   => 0,
        'description' => 'Twenty rounds of 2 minutes hard followed by 1 minute easy. The 2-minute efforts should feel like a pace you could sustain for about 40 minutes in a race — not a sprint, but genuinely working. The 1-minute recovery is short enough that fatigue accumulates. Your later rounds will feel harder than your first ones. Warmup: 15–20 min. Main set: 20 × 2 min hard / 1 min easy jog (60 min total work). Cooldown: 10–15 min.',
        'notes'       => 'Hanson-influenced — cumulative fatigue is the stimulus. Reserved for well-trained athletes in build and peak phases. Rep count reducible to 15 for early build. RPE expected: Hard to Very Hard.',
    ],
    [
        'code'        => 'WL-022',
        'name'        => '⅛ Mile Road Repeats',
        'af_name'     => '⅛ mile repeats',
        'type'        => 'interval',
        'phases'      => ['base','build','peak'],
        'distances'   => ['5K','10K','half','marathon'],
        'presc'       => 'time',
        'track'       => 'no',
        'factor'      => 0.80,
        'clearance'   => 0,
        'description' => 'Short, fast ⅛ mile (200m) repeats on the road. Each repeat should be run at close to your top controlled speed — fast and powerful but not falling apart. Recovery jog is roughly double the effort duration. Warmup: 20 min easy. Main set: 20–25 × ⅛ mile at near-sprint effort / ~90 second easy jog recovery. Cooldown: 10–15 min. Total: approximately 60–70 minutes.',
        'notes'       => 'Pure Hart-influenced top-end speed development on roads. Suitable for all distances and phases. Rep count: 15–18 for base/workable; 20–22 for build; 23–25 for peak/well-trained. RPE expected: Hard to Very Hard.',
    ],
    [
        'code'        => 'WL-023',
        'name'        => 'Quarters and Eighths',
        'af_name'     => 'Quarters and eighths',
        'type'        => 'interval',
        'phases'      => ['build','peak'],
        'distances'   => ['5K','10K','half'],
        'presc'       => 'distance',
        'track'       => 'no',
        'factor'      => 1.00,
        'clearance'   => 0,
        'description' => 'Alternating 400m efforts and ⅛ mile recovery jogs. The 400m efforts should be run at close to your best controlled speed for that distance — fast, but not all-out where your form collapses. The ⅛ mile jogs between efforts are short; you won\'t fully recover. Warmup: 15–20 min easy + 4 strides. Main set: 10–16 × 400m at near-sprint effort / ⅛ mile easy jog. Cooldown: 10–15 min.',
        'notes'       => 'Rep count: 10 for early build/workable; 12–14 for mid-build/peak; 16 for well-trained peak phase. Distinct from WL-008 — shorter efforts with shorter recovery, more top-end speed focus. RPE expected: Very Hard.',
    ],
];

$insertStmt = $db->prepare(
    'INSERT INTO workout_library
     (name, athlete_facing_name, workout_type, phase_tags, distance_tags,
      prescription_type, track_required, intensity_factor, coach_clearance_required,
      description, engine_notes, library_code, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
     ON DUPLICATE KEY UPDATE
       name=VALUES(name), athlete_facing_name=VALUES(athlete_facing_name),
       workout_type=VALUES(workout_type), phase_tags=VALUES(phase_tags),
       distance_tags=VALUES(distance_tags), prescription_type=VALUES(prescription_type),
       track_required=VALUES(track_required), intensity_factor=VALUES(intensity_factor),
       coach_clearance_required=VALUES(coach_clearance_required),
       description=VALUES(description), engine_notes=VALUES(engine_notes)'
);

$seeded = 0;
$updated = 0;

foreach ($templates as $t) {
    // Check if already exists by library_code
    $existing = $db->prepare('SELECT id FROM workout_library WHERE library_code = ? LIMIT 1');
    $existing->execute([$t['code']]);
    $row = $existing->fetch();

    $phaseTags    = json_encode($t['phases']);
    $distanceTags = json_encode($t['distances']);

    if ($row) {
        // Update existing
        $db->prepare(
            'UPDATE workout_library SET
               name=?, athlete_facing_name=?, workout_type=?, phase_tags=?, distance_tags=?,
               prescription_type=?, track_required=?, intensity_factor=?,
               coach_clearance_required=?, description=?, engine_notes=?
             WHERE library_code=?'
        )->execute([
            $t['name'], $t['af_name'], $t['type'], $phaseTags, $distanceTags,
            $t['presc'], $t['track'], $t['factor'], $t['clearance'],
            $t['description'], $t['notes'], $t['code'],
        ]);
        echo "  · Updated {$t['code']}: {$t['name']}\n";
        $updated++;
    } else {
        // Insert new
        $db->prepare(
            'INSERT INTO workout_library
             (name, athlete_facing_name, workout_type, phase_tags, distance_tags,
              prescription_type, track_required, intensity_factor, coach_clearance_required,
              description, engine_notes, library_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $t['name'], $t['af_name'], $t['type'], $phaseTags, $distanceTags,
            $t['presc'], $t['track'], $t['factor'], $t['clearance'],
            $t['description'], $t['notes'], $t['code'],
        ]);
        echo "  ✓ Seeded  {$t['code']}: {$t['name']}\n";
        $seeded++;
    }
}

echo "\nDone. Seeded: {$seeded}, Updated: {$updated}.\n";
echo "Total templates with library_code: ";
echo $db->query("SELECT COUNT(*) FROM workout_library WHERE library_code IS NOT NULL")->fetchColumn();
echo "\n\nSetup complete.\n";

<?php
/**
 * LIVE verification for the athlete-facing prescription LEAD LINE + the shared recovery
 * resolver. For each templated archetype it generates a workout, confirms the description
 * LEADS with "N × length at effort, recovery", and confirms the recovery stated in the
 * description equals the recovery the watch pushes (both call RecoveryModel::resolveSeconds /
 * PaceZones::estimateRepSeconds). Also checks a structured edit updates the lead. Throwaway
 * athlete with visible pace zones; full teardown.
 *
 * Run from /home/private/app: php scripts/verify_prescription_lead.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Timezone.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
require_once __DIR__ . '/../src/Engine/RecoveryModel.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';
require_once __DIR__ . '/../src/IntervalsService.php';

$db = Database::get();
$fails = [];
function check(bool $c, string $m): void { global $fails; if (!$c) $fails[] = $m; }

// Parse a watch duration token ("2m45s" / "45s" / "2m") to seconds.
function watchSecs(string $t): int {
    if (preg_match('/^(\d+)m(\d+)s$/', $t, $m)) return (int)$m[1]*60 + (int)$m[2];
    if (preg_match('/^(\d+)m$/', $t, $m))       return (int)$m[1]*60;
    if (preg_match('/^(\d+)s$/', $t, $m))       return (int)$m[1];
    return -1;
}
// Parse a description recovery label ("2 min 45 sec" / "90 sec" / "2 min") to seconds.
function descSecs(string $t): int {
    $sec = 0; $ok = false;
    if (preg_match('/(\d+)\s*min/', $t, $m)) { $sec += (int)$m[1]*60; $ok = true; }
    if (preg_match('/(\d+)\s*sec/', $t, $m)) { $sec += (int)$m[1];    $ok = true; }
    return $ok ? $sec : -1;
}
// The recovery seconds the watch emits: the "- <dur> easy" line INSIDE the Main Set
// block (not the Warmup "- 15m easy" line).
function watchRecovery(string $text): int {
    if (preg_match('/Main Set.*$/s', $text, $mm)) {
        $block = preg_split('/\n(?:Cooldown|Warmup)/', $mm[0])[0];
        if (preg_match('/- (\S+) easy/', $block, $m)) return watchSecs($m[1]);
    }
    return -1;
}
// The recovery seconds stated in the description lead: the "~<label> easy|full" clause.
function descRecovery(string $desc): int {
    if (preg_match('/~([0-9a-z ]+?) (?:easy jog|full recovery)/', $desc, $m)) return descSecs(trim($m[1]));
    return -1;
}

$zones = ['source'=>'race_result','5K'=>414,'10K'=>432,'mile'=>372,'800'=>354,'400'=>342,
          'half_marathon'=>458,'marathon'=>480,'easy'=>['min'=>540,'max'=>600],'long'=>['min'=>540,'max'=>600]];
$zonesJson = json_encode($zones);

$sfx = 'plead_' . substr(md5(uniqid('', true)), 0, 8);
$db->prepare("INSERT INTO users (email,password_hash,role,name) VALUES (?,'x','athlete','PLead')")->execute(["{$sfx}@example.invalid"]);
$uid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO athletes (user_id,status,onboarding_completed_at) VALUES (?, 'active', NOW())")->execute([$uid]);
$aid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO athlete_profiles (athlete_id, plan_type, goal_race_distance, training_days_per_week, current_weekly_minutes, longest_recent_run_mins, peak_volume_ceiling_mins, months_at_current_volume, pace_zones, pace_zones_visible) VALUES (?, 'development_plan', '10K', 6, 360, 120, 504, 24, ?, 1)")->execute([$aid, $zonesJson]);
$db->prepare("INSERT INTO training_plans (athlete_id, plan_type, plan_start_date, plan_end_date, status, generation_trigger) VALUES (?, 'development_plan', CURDATE(), CURDATE()+INTERVAL 84 DAY, 'pending_approval', 'coach_manual')")->execute([$aid]);
$planId = (int)$db->lastInsertId();

$cleanup = function () use ($db, $aid, $uid) {
    foreach (['planned_workouts','training_plans','athlete_profiles','engine_flags','plan_approval_queue'] as $t) { try { $db->prepare("DELETE FROM `$t` WHERE athlete_id=?")->execute([$aid]); } catch (\Throwable $e) {} }
    try { $db->prepare("DELETE FROM athletes WHERE id=?")->execute([$aid]); } catch (\Throwable $e) {}
    try { $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]); } catch (\Throwable $e) {}
};

try {
    echo "\n=== Prescription lead line + shared recovery resolver ===\n";

    $cases = [
        ['tempo_intervals',              'time_based',  75, true ],
        ['equal_distance_repeats',       null,          70, true ],
        ['short_speed_repeats',          null,          55, true ],
        ['high_volume_time_intervals',   null,          70, true ],
        ['sustained_hill_repeats',       null,          60, false], // recovery is a cue, no number
    ];

    foreach ($cases as [$code, $variant, $dur, $numericRec]) {
        $w = PlanGenerator::composeManualWorkout($aid, $code, $variant, $dur, $db);
        if (!$w) { check(false, "$code: composeManualWorkout returned null"); continue; }
        $desc = (string)$w['athlete_instructions'];
        echo "\n[$code]\n  lead: " . mb_substr($desc, 0, 110) . "\n";

        check((bool)preg_match('/^\d+ × /u', $desc), "$code: description does not LEAD with 'N × ...'");
        check(strpos($desc, 'between reps.') !== false, "$code: lead missing 'between reps.'");
        check(strpos($desc, '{{') === false, "$code: literal {{ in description");
        check(!preg_match('/\x{2014}/u', $desc), "$code: em dash in description");

        // Watch text from the SAME generated structure/params.
        $watch = IntervalsService::generateWorkoutText(
            ['structure'=>$w['structure'],'archetype_params'=>$w['archetype_params'],'athlete_instructions'=>$desc,'archetype_code'=>$code,'push_text_only'=>0],
            ['archetype_code'=>$code, 'pace_zones'=>$zones, 'goal_distance'=>'10K']
        );

        if ($numericRec) {
            $dRec = descRecovery($desc);
            $wRec = watchRecovery($watch);
            printf("  recovery: description=%ds  watch=%ds  %s\n", $dRec, $wRec, ($dRec === $wRec && $dRec > 0) ? 'AGREE' : 'MISMATCH');
            check($dRec > 0, "$code: could not parse description recovery");
            check($wRec > 0, "$code: could not parse watch recovery");
            check($dRec === $wRec, "$code: description recovery ($dRec) != watch recovery ($wRec)");
        } else {
            check(strpos($desc, 'jog back down') !== false, "$code: hill recovery cue missing");
            echo "  recovery: hill cue 'jog back down' (concrete, no number)\n";
        }
    }

    // ── structured edit updates the lead (tempo 4x14 -> 6x10) ──
    echo "\n[structured edit] tempo lead updates with the edit:\n";
    $tw = PlanGenerator::composeManualWorkout($aid, 'tempo_intervals', 'time_based', 75, $db);
    $db->prepare("INSERT INTO planned_workouts (plan_id, athlete_id, scheduled_date, workout_type, archetype_code, archetype_variant, archetype_params, structure, display_title, athlete_instructions, target_duration, visible_to_athlete) VALUES (?,?,CURDATE()+INTERVAL 3 DAY,?,?,?,?,?,?,?,?,1)")
       ->execute([$planId, $aid, $tw['workout_type'], 'tempo_intervals', $tw['archetype_variant'], $tw['archetype_params'], $tw['structure'], $tw['display_title'], $tw['athlete_instructions'], $tw['target_duration']]);
    $tid = (int)$db->lastInsertId();
    $edited = PlanGenerator::composeStructuredEdit($tid, ['rep_count'=>6,'rep_duration_minutes'=>10], $db);
    $eDesc = (string)$edited['athlete_instructions'];
    echo "  lead: " . mb_substr($eDesc, 0, 70) . "\n";
    check((bool)preg_match('/^6 × 10 min /u', $eDesc), "structured edit: lead did not update to '6 × 10 min'");

    // ── mixed already leads with its ladder (not re-led) ──
    $mw = PlanGenerator::composeManualWorkout($aid, 'mixed_distance_repeats', 'long_to_short', 75, $db);
    $mDesc = (string)$mw['athlete_instructions'];
    echo "\n[mixed_distance_repeats] " . mb_substr($mDesc, 0, 90) . "\n";
    check(strpos($mDesc, 'ladder:') !== false && strpos($mDesc, 'm') !== false, "mixed: ladder sequence missing from description");
    check(strpos($mDesc, '{{') === false, "mixed: literal {{");

} finally {
    echo "\n=== Teardown ===\n";
    $cleanup();
    $resid = (int)$db->query("SELECT (SELECT COUNT(*) FROM planned_workouts WHERE athlete_id=$aid)+(SELECT COUNT(*) FROM athletes WHERE id=$aid)+(SELECT COUNT(*) FROM users WHERE id=$uid)")->fetchColumn();
    echo $resid===0 ? "0 residual rows.\n" : "RESIDUAL: $resid\n";
    if ($resid!==0) $fails[]='residual';
}

echo "\n=== Verdict ===\n";
if (empty($fails)) { echo "PASS\n\n"; exit(0); }
echo "FAIL (".count($fails)."):\n"; foreach ($fails as $f) echo "  - $f\n"; echo "\n"; exit(1);

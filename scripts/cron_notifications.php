<?php
/**
 * Daily cron: scheduled notifications (Section 28, Part 5).
 *
 * Jobs: tomorrow_plan, weekly_summary, pre_race_reminder, rpe_prompt,
 * weekly_athlete_digest. Each respects per-user preferences and quiet hours
 * (evaluated in the user's own timezone) via Notifications::send().
 *
 * NFSN scheduler: daily, hour 13 UTC.
 *     php /home/private/app/scripts/cron_notifications.php
 *
 * NOTE on timing: this is a once-daily run, so day-level conditions gate each
 * job (a workout exists tomorrow; today == preferred_day; a race is 7/3/1 days
 * out; an RPE is 20–28h overdue). The stored preferred_time is honored only to
 * the granularity of the run cadence — deliver-at-exact-local-time would require
 * scheduling this script hourly (the queries already read preferred_time/day).
 *
 * Flags: pass `verbose` as an argument to print per-send detail.
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

if (is_file(SCRIPT_ROOT . '/vendor/autoload.php')) {
    require SCRIPT_ROOT . '/vendor/autoload.php';
}
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Timezone.php';
require_once SCRIPT_ROOT . '/src/Mailer.php';
require_once SCRIPT_ROOT . '/src/EmailTemplates.php';
require_once SCRIPT_ROOT . '/src/Notifications.php';
require_once SCRIPT_ROOT . '/src/CoachAdjustments.php';
require_once SCRIPT_ROOT . '/src/CoachingIntelligence.php';
require_once SCRIPT_ROOT . '/src/PredictiveConstants.php';
require_once SCRIPT_ROOT . '/src/ResponseProfiler.php';
require_once SCRIPT_ROOT . '/src/PredictiveFlags.php';

$verbose = in_array('verbose', $argv ?? [], true);
$db      = Database::get();
$counts  = ['tomorrow_plan' => 0, 'weekly_summary' => 0, 'pre_race_reminder' => 0, 'rpe_prompt' => 0, 'weekly_athlete_digest' => 0, 'flag_digest' => 0];

function vlog(bool $verbose, string $msg): void { if ($verbose) echo "  $msg\n"; }

/** Active athletes with an active plan: [athlete_id, user_id, tz]. */
function activeAthletes(PDO $db): array
{
    return $db->query(
        "SELECT a.id AS athlete_id, a.user_id, a.coach_id,
                COALESCE(u.timezone, 'America/New_York') AS tz, u.name
         FROM athletes a
         JOIN users u ON u.id = a.user_id
         JOIN training_plans tp ON tp.athlete_id = a.id AND tp.status = 'active'
         WHERE a.status = 'active'
         GROUP BY a.id, a.user_id, a.coach_id, tz, u.name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

$athletes = activeAthletes($db);

// ── Job 1: tomorrow_plan ─────────────────────────────────────────────────────
echo "Job: tomorrow_plan\n";
$pwTomorrow = $db->prepare(
    "SELECT workout_type, description, target_duration
     FROM planned_workouts
     WHERE athlete_id = ? AND scheduled_date = ? AND visible_to_athlete = 1
       AND (cancelled = 0 OR cancelled IS NULL)
       AND plan_id = (SELECT id FROM training_plans WHERE athlete_id = ? AND status='active' ORDER BY id DESC LIMIT 1)
     ORDER BY (workout_type='rest') ASC LIMIT 1"
);
$raceTomorrow = $db->prepare(
    "SELECT race_name FROM races WHERE athlete_id = ? AND race_date = ? AND is_goal_race = 1 LIMIT 1"
);
foreach ($athletes as $a) {
    $tomorrow = Timezone::dateInZone($a['tz'], '+1 day');
    $pwTomorrow->execute([$a['athlete_id'], $tomorrow, $a['athlete_id']]);
    $w = $pwTomorrow->fetch(PDO::FETCH_ASSOC);
    if (!$w || $w['workout_type'] === 'rest') continue; // rest days skipped (default off)

    $raceTomorrow->execute([$a['athlete_id'], $tomorrow]);
    $isRaceEve = (bool)$raceTomorrow->fetchColumn();

    $title = ucfirst(str_replace('_', ' ', $w['workout_type']));
    $dur   = $w['target_duration'] ? ((int)$w['target_duration'] . ' min') : '';
    $res = Notifications::send((int)$a['user_id'], 'tomorrow_plan', [
        'workout_title' => $title,
        'duration'      => $dur,
        'bypass_quiet'  => $isRaceEve, // race-day-eve always comes through
    ]);
    if ($res['push'] || $res['email']) $counts['tomorrow_plan']++;
    vlog($verbose, $a['name'] . ' → ' . $title . ($res['suppressed'] ? " [{$res['suppressed']}]" : ' [sent]'));
}

// ── Job 2: weekly_summary ────────────────────────────────────────────────────
echo "Job: weekly_summary\n";
foreach ($athletes as $a) {
    $pref = Notifications::getPref((int)$a['user_id'], 'weekly_summary');
    $dow  = (int)Timezone::dateInZone($a['tz'], 'now', 'w'); // 0=Sun..6=Sat
    $wantDay = $pref['preferred_day'] !== null ? (int)$pref['preferred_day'] : 0;
    if ($dow !== $wantDay) continue;

    $today = Timezone::dateInZone($a['tz'], 'now');
    $weekAgo = Timezone::dateInZone($a['tz'], '-6 days');

    $planned = (int)$db->query("SELECT COUNT(*) FROM planned_workouts WHERE athlete_id={$a['athlete_id']} AND workout_type<>'rest' AND (cancelled=0 OR cancelled IS NULL) AND scheduled_date BETWEEN " . $db->quote($weekAgo) . " AND " . $db->quote($today))->fetchColumn();
    $completed = (int)$db->query("SELECT COUNT(*) FROM completed_workouts WHERE athlete_id={$a['athlete_id']} AND activity_date BETWEEN " . $db->quote($weekAgo) . " AND " . $db->quote($today))->fetchColumn();
    $nextStart = Timezone::dateInZone($a['tz'], '+1 day', 'M j');

    $res = Notifications::send((int)$a['user_id'], 'weekly_summary', [
        'completed'       => $completed,
        'planned'         => $planned,
        'next_week_start' => $nextStart,
    ]);
    if ($res['push'] || $res['email']) $counts['weekly_summary']++;
    vlog($verbose, $a['name'] . " → {$completed}/{$planned}" . ($res['suppressed'] ? " [{$res['suppressed']}]" : ' [sent]'));
}

// ── Job 3: pre_race_reminder ─────────────────────────────────────────────────
echo "Job: pre_race_reminder\n";
$races = $db->query(
    "SELECT r.athlete_id, r.race_name, r.race_distance, r.race_date, a.user_id,
            COALESCE(u.timezone,'America/New_York') AS tz
     FROM races r
     JOIN athletes a ON a.id = r.athlete_id AND a.status='active'
     JOIN users u ON u.id = a.user_id
     WHERE r.is_goal_race = 1 AND r.race_date >= CURDATE()"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($races as $r) {
    $today = new DateTime(Timezone::dateInZone($r['tz'], 'now'));
    $race  = new DateTime($r['race_date']);
    $daysOut = (int)$today->diff($race)->format('%r%a');
    if (!in_array($daysOut, [7, 3, 1], true)) continue;

    $res = Notifications::send((int)$r['user_id'], 'pre_race_reminder', [
        'days_out'     => $daysOut,
        'race_name'    => $r['race_name'] . ' (' . $r['race_distance'] . ')',
        'bypass_quiet' => $daysOut <= 1, // 1-day reminder always comes through
    ]);
    if ($res['push'] || $res['email']) $counts['pre_race_reminder']++;
    vlog($verbose, $r['race_name'] . " {$daysOut}d" . ($res['suppressed'] ? " [{$res['suppressed']}]" : ' [sent]'));
}

// ── Job 4: rpe_prompt ────────────────────────────────────────────────────────
// Quality sessions / long runs with no RPE, completed 20–28h ago (synced_at is
// the completion timestamp). 20h floor avoids same-day spam; 28h ceiling avoids
// stale prompts on a daily run.
echo "Job: rpe_prompt\n";
$rpe = $db->query(
    "SELECT cw.id, cw.workout_type, cw.activity_date, a.user_id, a.status
     FROM completed_workouts cw
     JOIN athletes a ON a.id = cw.athlete_id AND a.status='active'
     WHERE cw.rpe IS NULL
       AND cw.workout_type IN ('interval','tempo','hill','fartlek','speed','race_pace','long')
       AND cw.synced_at < (NOW() - INTERVAL 20 HOUR)
       AND cw.synced_at > (NOW() - INTERVAL 28 HOUR)"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rpe as $c) {
    $label = ucfirst(str_replace('_', ' ', $c['workout_type'])) . ' on ' . $c['activity_date'];
    $res = Notifications::send((int)$c['user_id'], 'rpe_prompt', ['workout_name' => $label]);
    if ($res['push'] || $res['email']) $counts['rpe_prompt']++;
    vlog($verbose, $label . ($res['suppressed'] ? " [{$res['suppressed']}]" : ' [sent]'));
}

// ── Job 5: weekly_athlete_digest ─────────────────────────────────────────────
echo "Job: weekly_athlete_digest\n";
$coaches = $db->query(
    "SELECT u.id AS user_id, COALESCE(u.timezone,'America/New_York') AS tz, u.name
     FROM users u
     WHERE u.id IN (SELECT DISTINCT coach_id FROM athletes WHERE coach_id IS NOT NULL AND status='active')"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($coaches as $c) {
    $pref = Notifications::getPref((int)$c['user_id'], 'weekly_athlete_digest');
    $dow  = (int)Timezone::dateInZone($c['tz'], 'now', 'w');
    $wantDay = $pref['preferred_day'] !== null ? (int)$pref['preferred_day'] : 1;
    if ($dow !== $wantDay) continue;

    $cid = (int)$c['user_id'];
    $athleteCount = (int)$db->query("SELECT COUNT(*) FROM athletes WHERE coach_id={$cid} AND status='active'")->fetchColumn();
    $openFlags = (int)$db->query("SELECT COUNT(*) FROM engine_flags ef JOIN athletes a ON a.id=ef.athlete_id WHERE a.coach_id={$cid} AND ef.status='open'")->fetchColumn();
    $races = (int)$db->query("SELECT COUNT(*) FROM races r JOIN athletes a ON a.id=r.athlete_id WHERE a.coach_id={$cid} AND r.race_date>=CURDATE()")->fetchColumn();

    $res = Notifications::send($cid, 'weekly_athlete_digest', [
        'athletes'       => $athleteCount,
        'open_flags'     => $openFlags,
        'upcoming_races' => $races,
    ]);
    if ($res['push'] || $res['email']) $counts['weekly_athlete_digest']++;
    vlog($verbose, $c['name'] . " → {$athleteCount} athletes" . ($res['suppressed'] ? " [{$res['suppressed']}]" : ' [sent]'));
}

// ── Job 6: flag_digest ───────────────────────────────────────────────────────
// Once-daily batch of warning/info engine flags for coaches who set those types
// to delivery=daily_digest (Notifications::notifyFlag held the immediate sends).
// critical flags are never digested; they always dispatched immediately.
// Guarded so a pre-migration-036 run (no delivery column) is a clean no-op.
echo "Job: flag_digest\n";
try {
    $sevByType = ['warning_flag' => 'warning', 'info_flag' => 'info'];
    foreach ($coaches as $c) {
        $cid = (int)$c['user_id'];

        // Which severities has this coach batched (and left enabled)?
        $severities = [];
        foreach ($sevByType as $type => $sev) {
            $pref = Notifications::getPref($cid, $type);
            if ((int)$pref['enabled'] === 1 && ($pref['delivery'] ?? 'immediate') === 'daily_digest') {
                $severities[] = $sev;
            }
        }
        if (empty($severities)) continue;

        $ph = implode(',', array_fill(0, count($severities), '?'));
        $stmt = $db->prepare(
            "SELECT ef.severity, ef.message, u.name AS athlete_name
             FROM engine_flags ef
             JOIN athletes a ON a.id = ef.athlete_id AND a.coach_id = ? AND a.status = 'active'
             JOIN users u ON u.id = a.user_id
             WHERE ef.status = 'open'
               AND ef.severity IN ($ph)
               AND ef.created_at >= (NOW() - INTERVAL 1 DAY)
             ORDER BY ef.created_at DESC"
        );
        $stmt->execute(array_merge([$cid], $severities));
        $flags = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($flags)) { vlog($verbose, $c['name'] . ' → no digestable flags'); continue; }

        // Push summary: per-athlete counts. Email detail: the first 10 messages.
        $byAthlete = [];
        foreach ($flags as $f) {
            $byAthlete[$f['athlete_name']] = ($byAthlete[$f['athlete_name']] ?? 0) + 1;
        }
        $parts = [];
        foreach (array_slice($byAthlete, 0, 4, true) as $n => $cnt) {
            $parts[] = $n . ' (' . $cnt . ')';
        }
        if (count($byAthlete) > 4) $parts[] = '+' . (count($byAthlete) - 4) . ' more';

        $detail = '<ul style="margin:8px 0;padding-left:18px;color:#444;">';
        foreach (array_slice($flags, 0, 10) as $f) {
            $detail .= '<li style="margin-bottom:6px;">' . htmlspecialchars($f['message'], ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $detail .= '</ul>';
        if (count($flags) > 10) {
            $detail .= '<p>And ' . (count($flags) - 10) . ' more in the app.</p>';
        }

        $res = Notifications::send($cid, 'flag_digest', [
            'count'       => count($flags),
            'summary'     => implode(', ', $parts),
            'detail_html' => $detail,
        ]);
        if ($res['push'] || $res['email']) $counts['flag_digest']++;
        vlog($verbose, $c['name'] . ' → ' . count($flags) . ' flag(s)' . ($res['suppressed'] ? " [{$res['suppressed']}]" : ' [sent]'));
    }
} catch (\Throwable $e) {
    echo '  flag_digest skipped: ' . $e->getMessage() . "\n";
}

echo date('Y-m-d H:i:s') . ' — cron_notifications complete. Delivered: ' . json_encode($counts) . "\n";

// ── Coaching Intelligence: daily behavior logging + pattern flags (Parts 4 & 5) ──
// Guarded so a pre-migration run is a clean no-op.
echo "Job: coaching_intelligence\n";
try {
    $hasTbl = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'athlete_behavior_log'"
    )->fetchColumn() > 0;
    if ($hasTbl) {
        $ci = CoachingIntelligence::run($db, $verbose);
        echo date('Y-m-d H:i:s') . ' — coaching_intelligence complete. '
            . 'Behavior rows: ' . $ci['behavior'] . ', flags raised: ' . $ci['flags'] . "\n";

        // Phase 2: cross-athlete roster insights, after individual-athlete flags.
        // Guarded so a pre-migration-028 run is a clean no-op.
        $hasInsights = (int)$db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coach_roster_insights'"
        )->fetchColumn() > 0;
        if ($hasInsights) {
            $insightCoaches = $db->query(
                "SELECT DISTINCT coach_id FROM athletes WHERE coach_id IS NOT NULL AND status = 'active'"
            )->fetchAll(PDO::FETCH_COLUMN);
            $insightTotal = 0;
            foreach ($insightCoaches as $cid) {
                $insightTotal += CoachingIntelligence::generateRosterInsights((int)$cid, $db);
            }
            echo date('Y-m-d H:i:s') . " — roster insights complete. Created {$insightTotal}.\n";
        } else {
            echo "  coach_roster_insights missing (migration_028 not run yet) — skipping insights.\n";
        }

        // Phase 3: recompute response profiles + predictive flags daily.
        // Guarded so a pre-migration-029 run is a clean no-op.
        $hasProfiles = (int)$db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'athlete_response_profiles'"
        )->fetchColumn() > 0;
        if ($hasProfiles) {
            $pf = PredictiveFlags::run($db, $verbose);
            echo date('Y-m-d H:i:s') . " — predictive flags complete. Profiles: {$pf['profiles']}, "
                . "opened/refreshed: {$pf['open']}, resolved: {$pf['resolved']}.\n";
        } else {
            echo "  athlete_response_profiles missing (migration_029 not run yet) — skipping predictions.\n";
        }
    } else {
        echo "  athlete_behavior_log missing (migration_027 not run yet) — skipping.\n";
    }
} catch (\Throwable $e) {
    echo '  coaching_intelligence error: ' . $e->getMessage() . "\n";
    error_log('cron coaching_intelligence failed: ' . $e->getMessage());
}

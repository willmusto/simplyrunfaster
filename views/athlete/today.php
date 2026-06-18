<?php
// Variables from AthleteController::today():
//  $athlete, $profile, $plan, $todayWorkout, $weekWorkouts, $runStats, $unreadMessages
$userName = h(explode(' ', $athlete['name'])[0]);
$tz       = $athlete['timezone'] ?? Auth::timezone();
$today    = Timezone::dateInZone($tz, 'now');
$localHour = (int)Timezone::dateInZone($tz, 'now', 'G');
?>
<div class="page-content">

    <!-- Greeting + context -->
    <div style="margin-bottom:16px;">
        <div class="page-heading">Good <?= $localHour < 12 ? 'morning' : ($localHour < 17 ? 'afternoon' : 'evening') ?>, <?= $userName ?>.</div>
        <?php if ($plan): ?>
        <?php
        $planStartTs = strtotime($plan['plan_start_date']);
        $planEndTs   = strtotime($plan['plan_end_date'] ?? $plan['plan_start_date']) ?: $planStartTs;
        $todayTs     = strtotime($today);
        $planTypeKey = str_replace(' ', '_', strtolower((string)($plan['plan_type'] ?? '')));
        $codeWeekStartTs = $planStartTs;
        $hasCalendarAlignedCodeWeeks = in_array($planTypeKey, ['development_plan', 'maintenance_plan', 'recovery_block'], true)
            && ((int)date('N', $planStartTs) === 1 || (int)date('N', $planEndTs) === 7);
        if ($hasCalendarAlignedCodeWeeks) {
            $offsetToMonday = (8 - (int)date('N', $planStartTs)) % 7;
            $codeWeekStartTs = strtotime('+' . $offsetToMonday . ' days', $planStartTs);
            if ($codeWeekStartTs > $planEndTs) {
                $codeWeekStartTs = $planStartTs;
            }
        }
        $codeTotalWeeks = max(1, (int)ceil(max(1, $planEndTs - $codeWeekStartTs + 86400) / 604800));
        $isLeadIn = $todayTs >= $planStartTs && $todayTs < $codeWeekStartTs;
        $codeWeekNumber = max(1, (int)floor(($todayTs - $codeWeekStartTs) / 604800) + 1);
        ?>
        <p class="body-text" style="margin-top:4px;">
            <?php if ($isLeadIn): ?>
            Lead-in
            <?php else: ?>
            Week <?= min($codeWeekNumber, $codeTotalWeeks) ?> of <?= $codeTotalWeeks ?>
            <?php endif; ?>
            · <?= h(ucfirst($plan['plan_type'] ?? 'Training')) ?>
        </p>
        <?php else: ?>
        <p class="body-text" style="margin-top:4px;color:var(--text-muted);">
            Your coach is building your plan. It will appear here once approved.
        </p>
        <?php endif; ?>
    </div>

    <?php if (!empty($success)): ?>
    <div class="flash flash-success" style="margin-bottom:16px;"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
    <div class="flash flash-error" style="margin-bottom:16px;"><?= h($error) ?></div>
    <?php endif; ?>

    <?php // Post-race result prompt (§26 / Part 6): most recent unlogged race in last 14 days. ?>
    <?php if (!empty($pendingRace)): ?>
    <div class="card" style="border-left:3px solid #C0392B;margin-bottom:16px;">
        <div style="font-size:14px;font-weight:600;">How did <?= h($pendingRace['race_name']) ?> go?</div>
        <p class="body-text" style="margin:2px 0 10px;font-size:13px;">
            <?= h(RaceController::distanceLabel($pendingRace['race_distance'])) ?> ·
            <?= date('M j', strtotime($pendingRace['race_date'])) ?> · Log your result.
        </p>
        <form method="POST" action="/app/athlete/race/result">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="race_id" value="<?= (int)$pendingRace['id'] ?>">
            <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                <div class="form-group" style="margin-bottom:0;flex:1;min-width:140px;">
                    <label class="form-label" for="pr_time">Finish time (HH:MM:SS)</label>
                    <input type="text" id="pr_time" name="result_time" class="form-input"
                           placeholder="0:48:30" pattern="\d{1,2}(:\d{1,2}){0,2}" required>
                </div>
                <button type="submit" class="btn btn-primary">Log result</button>
            </div>
            <div class="form-group" style="margin:10px 0 0;">
                <input type="text" name="result_notes" class="form-input" placeholder="Notes (optional)">
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php
    // Pace-data prompt: no zones, no race result, no typical easy pace on file.
    $needsPaceData = $profile
        && !PaceZones::isPopulated($profile['pace_zones'] ?? null)
        && empty($profile['most_recent_race_time'])
        && empty($profile['typical_easy_pace_min']);
    ?>
    <?php if ($needsPaceData): ?>
    <a href="/app/settings/training#typical_easy_pace_min" class="card"
       style="display:flex;align-items:center;justify-content:space-between;gap:10px;text-decoration:none;
              color:inherit;border-left:3px solid var(--accent-mid);margin-bottom:16px;">
        <div>
            <div style="font-size:14px;font-weight:600;">Add your pace info</div>
            <p class="body-text" style="margin:2px 0 0;font-size:13px;">
                To calculate pace assignments, add a recent race result or your typical training pace.
            </p>
        </div>
        <span style="color:var(--text-muted);font-size:20px;">›</span>
    </a>
    <?php endif; ?>

    <?php if ($plan && $plan['plan_start_date'] && $plan['plan_end_date']): ?>
    <!-- Phase progress bar -->
    <?php
    $planStart  = strtotime($plan['plan_start_date']);
    $planEnd    = strtotime($plan['plan_end_date']);
    $now        = time();
    $totalDays  = max(1, ($planEnd - $planStart) / 86400);
    $elapsed    = max(0, min($totalDays, ($now - $planStart) / 86400));
    $pct        = round(($elapsed / $totalDays) * 100);
    ?>
    <div class="phase-bar-wrap">
        <div class="phase-bar-track">
            <div class="phase-bar-fill" style="width:<?= $pct ?>%;"></div>
        </div>
        <div class="phase-bar-labels">
            <span>Base</span><span>Build</span><span>Peak</span><span>Taper</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- TODAY section -->
    <div class="section-label" style="margin-top:20px;">TODAY</div>

    <?php if ($todayWorkout): ?>
    <div class="card card-next-up">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
            <span class="pill <?= pill_class($todayWorkout['workout_type'], $todayWorkout['archetype_code'] ?? null) ?>">
                <?= pill_label($todayWorkout['workout_type'], $todayWorkout['archetype_code'] ?? null) ?>
            </span>
            <?php if ($todayWorkout['target_duration']): ?>
            <span class="text-muted" style="font-size:13px;">
                <?= format_duration((int)$todayWorkout['target_duration']) ?>
            </span>
            <?php endif; ?>
            <?php if ($todayWorkout['pushed_to_watch']): ?>
            <span class="watch-badge">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="5" y="2" width="14" height="20" rx="3"/>
                    <line x1="12" y1="18" x2="12" y2="18"/>
                </svg>
                On watch
            </span>
            <?php endif; ?>
        </div>
        <?php if ($todayWorkout['description']): ?>
        <p class="body-text" style="margin-bottom:12px;">
            <?= nl2br(h(mb_substr($todayWorkout['description'], 0, 200))) ?>
            <?php if (mb_strlen($todayWorkout['description']) > 200): ?>&hellip;<?php endif; ?>
        </p>
        <?php elseif ($todayWorkout['workout_type'] === 'rest'): ?>
        <p class="body-text" style="margin-bottom:12px;">
            Rest day. Take it easy. Recovery is training too.
        </p>
        <?php endif; ?>
        <a href="/log" class="btn btn-primary btn-sm">Log this workout</a>
    </div>

    <?php elseif (!$plan): ?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-state-icon">⏳</div>
            <div class="empty-state-title">Plan pending coach approval</div>
            <p class="body-text">Your coach is reviewing your profile and will have a plan ready soon.</p>
        </div>
    </div>

    <?php else: ?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-state-icon">✅</div>
            <div class="empty-state-title">Nothing scheduled today</div>
            <p class="body-text">Rest day or no workout has been pushed to your window yet.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- THIS WEEK section — tappable accordion rows (CSS-only toggle) -->
    <?php if (!empty($weekWorkouts)): ?>
    <style>
        .week-acc { border-bottom: var(--card-border); }
        .week-acc:last-child { border-bottom: none; }
        /* The divider now lives on .week-acc, so the inner row carries none. */
        .week-acc .week-row { border-bottom: none; cursor: pointer; user-select: none; }
        .week-acc-toggle { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
        .week-chevron { flex-shrink: 0; color: var(--text-muted); transition: transform 200ms ease; }
        .week-acc-toggle:checked ~ .week-row .week-chevron { transform: rotate(180deg); }
        .week-acc-detail { max-height: 0; overflow: hidden; transition: max-height 200ms ease; }
        .week-acc-toggle:checked ~ .week-acc-detail { max-height: 1000px; }
        .week-acc-panel {
            margin: 2px 16px 14px;
            padding: 12px 14px;
            background: var(--recessed-bg);
            border: var(--card-border);
            border-radius: var(--radius-card);
        }
        .week-acc-title { font-size: 14px; font-weight: 600; margin-bottom: 2px; }
        .week-acc-summary { font-size: 12px; color: var(--text-muted); margin-bottom: 10px; }
        .week-acc-instr { font-size: 14px; line-height: 1.55; color: var(--text-primary); margin: 0 0 12px; }
        .week-acc-panel .btn:last-child { margin-bottom: 0; }
    </style>
    <div class="section-label" style="margin-top:24px;">THIS WEEK</div>
    <div class="card" style="padding:0 0;">
        <?php
        $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        foreach ($weekWorkouts as $w):
            $isToday = $w['scheduled_date'] === $today;
            $dow     = (int)date('w', strtotime($w['scheduled_date']));
            $wid     = (int)$w['id'];
            $instr   = $w['athlete_instructions'] ?: ($w['description'] ?? '');
        ?>
        <div class="week-acc">
            <input type="checkbox" id="wk-<?= $wid ?>" class="week-acc-toggle">
            <label for="wk-<?= $wid ?>" class="week-row <?= $isToday ? 'week-row-today' : '' ?>" style="padding:10px 20px;">
                <span class="week-row-day"><?= $dayNames[$dow] ?></span>
                <div class="week-row-body">
                    <span class="pill <?= pill_class($w['workout_type'], $w['archetype_code'] ?? null) ?>">
                        <?= pill_label($w['workout_type'], $w['archetype_code'] ?? null) ?>
                    </span>
                </div>
                <span class="week-row-duration">
                    <?= $w['target_duration'] ? format_duration((int)$w['target_duration']) : '' ?>
                </span>
                <svg class="week-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </label>
            <div class="week-acc-detail">
                <div class="week-acc-panel">
                    <?php if (!empty($w['display_title'])): ?>
                    <div class="week-acc-title"><?= h($w['display_title']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($w['display_summary'])): ?>
                    <div class="week-acc-summary"><?= h($w['display_summary']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($instr)): ?>
                    <p class="week-acc-instr"><?= nl2br(h($instr)) ?></p>
                    <?php endif; ?>
                    <?php if ($isToday): ?>
                    <a href="/log" class="btn btn-primary btn-sm">Log this workout →</a>
                    <?php endif; ?>
                    <div><?= render_workout_thread_button($wid, ($threadState[$wid] ?? null)) ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- YOUR STATS — last 30 days of completed running workouts -->
    <?php
    $hasRuns    = !empty($runStats['run_count']);
    $daysRun    = (int)($runStats['days_run'] ?? 0);
    $totalMin   = (int)($runStats['total_minutes'] ?? 0);
    $totalMiles = isset($runStats['total_distance']) ? (float)$runStats['total_distance'] : 0.0;
    $isMetric   = ($profile['units'] ?? 'miles') === 'km';
    $volume     = $isMetric ? $totalMiles * 1.60934 : $totalMiles;
    $volSuffix  = $isMetric ? 'km' : 'mi';
    ?>
    <div class="section-label" style="margin-top:24px;">YOUR STATS <span class="text-muted">Last 30 days</span></div>
    <div class="metric-grid">
        <div class="metric-tile">
            <div class="metric-label">Days Run</div>
            <div class="metric-value"><?= $hasRuns ? $daysRun : '–' ?></div>
        </div>
        <div class="metric-tile">
            <div class="metric-label">Time Running</div>
            <div class="metric-value"><?= $hasRuns ? sprintf('%d:%02d', intdiv($totalMin, 60), $totalMin % 60) : '–' ?></div>
        </div>
        <div class="metric-tile">
            <div class="metric-label">Volume</div>
            <div class="metric-value"><?= $hasRuns ? number_format(round($volume, 1), 1) . ' ' . $volSuffix : '–' ?></div>
        </div>
    </div>

    <?php if ($profile && $profile['goal_race_date']): ?>
    <div class="section-label" style="margin-top:24px;">RACE COUNTDOWN</div>
    <div class="card" style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="card-title"><?= h(goal_distance_label($profile['goal_race_distance'] ?? '', !empty($profile['is_hyrox']))) ?></div>
            <div class="card-subtitle"><?= date('M j, Y', strtotime($profile['goal_race_date'])) ?></div>
        </div>
        <div class="metric-tile" style="min-width:72px;text-align:center;">
            <div class="metric-value" style="font-size:28px;">
                <?= max(0, (int)ceil((strtotime($profile['goal_race_date']) - time()) / 86400)) ?>
            </div>
            <div class="metric-label">days</div>
        </div>
    </div>
    <?php endif; ?>

</div>

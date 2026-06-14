<?php
// $athlete, $profile, $activePlan, $allWorkouts, $athleteFlags, $loadSnapshot, $pbs, $nextRace
$today = date('Y-m-d');

$macroWeeks = [];
if ($activePlan && !empty($allWorkouts)) {
    $planStart = (string)$activePlan['plan_start_date'];
    $planEnd   = (string)$activePlan['plan_end_date'];
    $planStartTs = strtotime($planStart);
    $planEndTs   = strtotime($planEnd);

    $normalizeDistance = function (?string $distance): string {
        $d = strtolower(trim((string)$distance));
        return match (true) {
            in_array($d, ['marathon','m','42k','full','full marathon'], true) => 'marathon',
            in_array($d, ['half','hm','half marathon','21k'], true) => 'half',
            in_array($d, ['10k','10km','10 km'], true) => '10K',
            default => '5K',
        };
    };

    $classifyProfile = function (array $p, string $distance): string {
        $thresholds = [
            '5K' => [
                'well_trained' => ['runs_per_week' => 4, 'weekly_minutes' => 180, 'long_run_minutes' => 60],
                'workable'     => ['runs_per_week' => 3, 'weekly_minutes' => 120, 'long_run_minutes' => 45],
            ],
            '10K' => [
                'well_trained' => ['runs_per_week' => 4, 'weekly_minutes' => 210, 'long_run_minutes' => 70],
                'workable'     => ['runs_per_week' => 3, 'weekly_minutes' => 150, 'long_run_minutes' => 50],
            ],
            'half' => [
                'well_trained' => ['runs_per_week' => 5, 'weekly_minutes' => 270, 'long_run_minutes' => 90],
                'workable'     => ['runs_per_week' => 4, 'weekly_minutes' => 180, 'long_run_minutes' => 60],
            ],
            'marathon' => [
                'well_trained' => ['runs_per_week' => 5, 'weekly_minutes' => 360, 'long_run_minutes' => 105],
                'workable'     => ['runs_per_week' => 4, 'weekly_minutes' => 240, 'long_run_minutes' => 75],
            ],
        ];
        $runsPerWeek = (int)($p['training_days_per_week'] ?? 0);
        $weekly      = (int)($p['current_weekly_minutes'] ?? 0);
        $longRun     = (int)($p['longest_recent_run_mins'] ?? 0);
        $rules       = $thresholds[$distance] ?? $thresholds['5K'];

        foreach (['well_trained', 'workable'] as $level) {
            $r = $rules[$level];
            if ($runsPerWeek >= $r['runs_per_week'] && $weekly >= $r['weekly_minutes'] && $longRun >= $r['long_run_minutes']) {
                return $level;
            }
        }
        return 'insufficient';
    };

    $phaseForWeek = function (string $planType, int $week, int $totalWeeks) use ($profile, $normalizeDistance, $classifyProfile): string {
        if ($planType === 'development_plan') return 'Base';
        if ($planType === 'maintenance_plan') return 'Build';
        if ($planType === 'return_to_running') return 'Return';
        if ($planType === 'recovery_block') return 'Recovery';
        if ($planType !== 'race_cycle') return ucfirst(str_replace('_', ' ', $planType));

        if ($week >= $totalWeeks - 1) return 'Taper';

        $propsByClass = [
            'well_trained' => ['base' => 0.20, 'build' => 0.40, 'peak' => 0.20, 'taper' => 0.15],
            'workable'     => ['base' => 0.30, 'build' => 0.30, 'peak' => 0.20, 'taper' => 0.15],
            'insufficient' => ['base' => 0.40, 'build' => 0.25, 'peak' => 0.20, 'taper' => 0.15],
        ];
        $distance = $normalizeDistance($profile['goal_race_distance'] ?? '5K');
        $class    = $classifyProfile($profile ?? [], $distance);
        $props    = $propsByClass[$class] ?? $propsByClass['workable'];
        $props['base'] += max(0.0, 1.0 - array_sum($props));

        $cursor = 1;
        foreach (['base', 'build', 'peak', 'taper'] as $phase) {
            $len = max(1, (int)round($totalWeeks * ($props[$phase] ?? 0)));
            $end = $cursor + $len - 1;
            if ($week >= $cursor && $week <= $end) {
                return ucfirst($phase);
            }
            $cursor = $end + 1;
        }
        return 'Base';
    };

    $isCutbackWeek = function (string $planType, int $week, string $phase): bool {
        if ($week <= 1) return false;
        if ($planType === 'development_plan') return $week % 4 === 0;
        if ($planType === 'race_cycle') return $week % 4 === 0 && strtolower($phase) !== 'taper';
        return false;
    };

    $workoutsByDate = [];
    foreach ($allWorkouts as $w) {
        $workoutsByDate[$w['scheduled_date']][] = $w;
    }

    $firstDow = (int)date('N', $planStartTs);
    $lastDow  = (int)date('N', $planEndTs);
    $firstMondayTs = strtotime('-' . ($firstDow - 1) . ' days', $planStartTs);
    $lastSundayTs  = strtotime('+' . (7 - $lastDow) . ' days', $planEndTs);
    $macroTotalWeeks = (int)floor(($lastSundayTs - $firstMondayTs) / (7 * 86400)) + 1;
    $internalTotalWeeks = max(1, (int)ceil(($planEndTs - $planStartTs + 86400) / (7 * 86400)));
    $planType = (string)($activePlan['plan_type'] ?? '');

    for ($weekIndex = 1, $weekStartTs = $firstMondayTs; $weekStartTs <= $lastSundayTs; $weekIndex++, $weekStartTs = strtotime('+7 days', $weekStartTs)) {
        $days = [];
        $internalWeeks = [];
        for ($iso = 1; $iso <= 7; $iso++) {
            $dayTs = strtotime('+' . ($iso - 1) . ' days', $weekStartTs);
            $date  = date('Y-m-d', $dayTs);
            $insidePlan = $dayTs >= $planStartTs && $dayTs <= $planEndTs;
            if ($insidePlan) {
                $internalWeeks[] = max(1, (int)floor(($dayTs - $planStartTs) / (7 * 86400)) + 1);
            }
            $days[] = [
                'date' => $date,
                'inside_plan' => $insidePlan,
                'workouts' => $insidePlan ? ($workoutsByDate[$date] ?? []) : [],
            ];
        }
        $phaseWeek = $internalWeeks ? min($internalWeeks) : $weekIndex;
        $phase = $phaseForWeek($planType, $phaseWeek, $internalTotalWeeks);
        $cutback = false;
        foreach (array_unique($internalWeeks) as $internalWeek) {
            if ($isCutbackWeek($planType, (int)$internalWeek, $phaseForWeek($planType, (int)$internalWeek, $internalTotalWeeks))) {
                $cutback = true;
                break;
            }
        }

        $macroWeeks[] = [
            'number' => $weekIndex,
            'total' => $macroTotalWeeks,
            'phase' => $phase,
            'cutback' => $cutback,
            'days' => $days,
        ];
    }
}
?>
<div class="page-content">
    <style>
    .coach-workout-details {
        margin: 6px 0 0;
        font-size: 12px;
    }
    .coach-workout-details summary {
        cursor: pointer;
        color: var(--text-secondary);
        line-height: 1.5;
    }
    .coach-workout-details summary::marker {
        color: var(--text-muted);
    }
    .coach-workout-details[open] .coach-workout-preview,
    .coach-workout-details:not([open]) .coach-workout-toggle-less {
        display: none;
    }
    .coach-workout-details[open] .coach-workout-toggle-more {
        display: none;
    }
    .coach-workout-toggle {
        color: var(--accent-mid);
        font-weight: 600;
        white-space: nowrap;
    }
    .macro-plan-list {
        /* Flows naturally in the page — no nested scroll container.
           Horizontal overflow per week is handled by .macro-week-wrap. */
    }
    .macro-week {
        margin-bottom: 14px;
    }
    .macro-week-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 8px;
        font-size: 11px;
        font-weight: 600;
        color: var(--text-muted);
        letter-spacing: .06em;
        text-transform: uppercase;
    }
    .macro-week-grid {
        display: grid;
        grid-template-columns: repeat(7, minmax(112px, 1fr));
        gap: 6px;
        min-width: 820px;
    }
    .macro-week-wrap {
        overflow-x: auto;
        padding-bottom: 2px;
    }
    .macro-day {
        min-height: 112px;
        border: var(--card-border);
        border-radius: var(--radius-sm);
        background: var(--card-bg);
        padding: 9px;
    }
    .macro-day-empty {
        background: var(--recessed-bg);
        color: var(--text-muted);
    }
    .macro-day-outside {
        background: transparent;
        border-color: transparent;
    }
    .macro-day-date {
        font-size: 10px;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: .05em;
        margin-bottom: 6px;
    }
    /* Day-cell workout is a button that opens the detail popout — no inline
       expansion, so the grid row never changes height when clicked. */
    .macro-workout {
        display: block;
        width: 100%;
        margin-top: 6px;
        padding: 4px;
        border: none;
        border-radius: var(--radius-sm);
        background: none;
        text-align: left;
        font: inherit;
        cursor: pointer;
    }
    .macro-workout:hover {
        background: var(--recessed-bg);
    }
    .macro-workout-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 5px;
    }
    .macro-duration {
        font-size: 11px;
        color: var(--text-muted);
    }
    .macro-lock {
        font-size: 12px;
        line-height: 1;
    }
    .macro-compliance {
        margin-left: auto;
        align-self: center;
    }
    @media (max-width: 767px) {
        .macro-week-grid {
            min-width: 760px;
        }
    }

    /* Workout detail popout — mirrors the #calWD modal in
       views/partials/calendar_week.php (view-only here). */
    #mwd {
        display: none;
        position: fixed; inset: 0; z-index: 9999;
        align-items: center; justify-content: center;
    }
    #mwd.is-open { display: flex; }
    #mwd-bd {
        position: absolute; inset: 0;
        background: rgba(0,0,0,.45);
    }
    #mwd-sheet {
        position: relative; z-index: 1;
        width: min(480px, calc(100vw - 32px));
        max-height: 88vh; overflow-y: auto;
        background: var(--card-bg);
        border: var(--card-border);
        border-radius: var(--radius-card);
        padding: 20px 20px 24px;
        box-shadow: 0 20px 60px rgba(0,0,0,.25);
    }
    #mwd-close {
        position: absolute; top: 12px; right: 14px;
        background: none; border: none; cursor: pointer;
        font-size: 22px; line-height: 1; padding: 2px 4px;
        color: var(--text-muted);
    }
    #mwd-close:hover { color: var(--text-primary); }
    </style>

    <?php if (!empty($flashSuccess)): ?>
    <div class="alert alert-success" style="margin-bottom:16px;padding:10px 14px;border-radius:var(--radius-card);
         background:var(--color-success-bg,#d1fae5);color:var(--color-success-text,#065f46);font-size:13px;">
        <?= h($flashSuccess) ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
    <div class="alert alert-error" style="margin-bottom:16px;padding:10px 14px;border-radius:var(--radius-card);
         background:#fdecea;color:#991b1b;font-size:13px;">
        <?= h($flashError) ?>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
        <a href="/app/coach/athletes" style="color:var(--text-muted);text-decoration:none;font-size:20px;">←</a>
        <div class="athlete-avatar"><?= h(avatar_initials($athlete['name'])) ?></div>
        <div>
            <div class="page-heading" style="margin-bottom:0;"><?= h($athlete['name']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);"><?= h($athlete['email']) ?></div>
        </div>
    </div>

    <div class="av-grid">

    <!-- Left: Plan + workouts -->
    <div>

        <?php if (!empty($athleteFlags)): ?>
        <div class="section-label" style="color:var(--color-danger);">OPEN ALERTS</div>
        <?php foreach ($athleteFlags as $flag): ?>
        <div class="roster-row <?= $flag['severity'] === 'critical' ? 'severity-critical' : 'severity-warning' ?>"
             style="margin-bottom:8px;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div>
                    <span class="pill"
                          style="font-size:10px;background:<?= $flag['severity'] === 'critical' ? '#FDECEA' : '#FEF9C3' ?>;
                                 color:<?= $flag['severity'] === 'critical' ? '#991B1B' : '#92400E' ?>;">
                        <?= h(ucfirst($flag['severity'])) ?>
                    </span>
                    <span style="font-size:13px;font-weight:500;margin-left:6px;"><?= h($flag['flag_type']) ?></span>
                </div>
                <form method="POST" action="/app/coach/flags/<?= (int)$flag['id'] ?>/dismiss">
                    <?= Auth::csrfField() ?>
                    <button type="submit" class="btn btn-sm"
                            style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
                </form>
            </div>
            <?php if ($flag['message']): ?>
            <p class="body-text" style="margin:6px 0 0;"><?= h($flag['message']) ?></p>
            <?php endif; ?>
            <?php if (($flag['flag_type'] ?? '') === 'profile_updated'): ?>
            <?= render_profile_diff($flag['details'] ?? null) ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Active plan -->
        <?php if ($activePlan): ?>
        <div class="section-label" style="margin-top:<?= !empty($athleteFlags) ? '24px' : '0' ?>;">CURRENT PLAN</div>
        <div class="card" style="margin-bottom:16px;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
                <div>
                    <div style="font-size:14px;font-weight:600;">
                        <?= h(ucfirst(str_replace('_', ' ', $activePlan['plan_type']))) ?>
                    </div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                        <?= date('M j, Y', strtotime($activePlan['plan_start_date'])) ?>
                        –
                        <?= date('M j, Y', strtotime($activePlan['plan_end_date'])) ?>
                    </div>
                </div>
                <?php
                $planStatus = (string)($activePlan['status'] ?? '');
                $planStatusClass = $planStatus === 'active' ? 'pill-active' : 'pill-pending';
                ?>
                <span class="pill <?= h($planStatusClass) ?>"><?= h(ucfirst(str_replace('_', ' ', $planStatus))) ?></span>
            </div>
            <?php
            $planStart    = strtotime($activePlan['plan_start_date']);
            $planEnd      = strtotime($activePlan['plan_end_date']);
            $totalDays    = max($planEnd - $planStart, 1);
            $elapsed      = max(0, min(time() - $planStart, $totalDays));
            $pct          = round(($elapsed / $totalDays) * 100);
            ?>
            <div class="phase-progress-bar" style="margin-top:12px;">
                <div class="phase-progress-fill" style="width:<?= $pct ?>%;"></div>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= $pct ?>% complete</div>
        </div>

        <!-- Upcoming workouts: rolling 7-day window (today … +6 days), mirroring the
             athlete Plan tab's rolling list. The full plan is in the macro view below. -->
        <div class="section-label">NEXT 7 DAYS</div>
        <?php
        $windowEnd = date('Y-m-d', strtotime($today . ' +6 days'));
        $upcoming = array_values(array_filter(
            $allWorkouts,
            fn($w) => $w['scheduled_date'] >= $today && $w['scheduled_date'] <= $windowEnd
        ));
        ?>
        <?php if (empty($upcoming)): ?>
        <div class="card" style="margin-bottom:16px;">
            <p class="body-text" style="margin:0;font-size:13px;color:var(--text-muted);">
                No workouts scheduled in the next 7 days.
            </p>
        </div>
        <?php else: ?>
        <div style="margin-bottom:16px;">
            <?php foreach ($upcoming as $w):
                $isPast  = $w['scheduled_date'] < $today;
                $isToday = $w['scheduled_date'] === $today;
                $description = (string)($w['description'] ?? '');
                $isLongDescription = mb_strlen($description) > 160;
                $descriptionPreview = mb_substr($description, 0, 160);
            ?>
            <div class="card" style="margin-bottom:6px;<?= $isPast ? 'opacity:.65;' : '' ?>
                                     <?= $isToday ? 'border-left:3px solid var(--accent-mid);' : '' ?>">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span style="font-size:11px;color:var(--text-muted);min-width:60px;">
                        <?= date('D M j', strtotime($w['scheduled_date'])) ?>
                    </span>
                    <span class="pill <?= pill_class($w['workout_type']) ?>">
                        <?= pill_label($w['workout_type']) ?>
                    </span>
                    <?php if ($w['target_duration']): ?>
                    <span style="font-size:12px;color:var(--text-muted);"><?= format_duration((int)$w['target_duration']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($w['coach_locked'])): ?>
                    <span title="Coach-locked" style="font-size:14px;">🔒</span>
                    <?php endif; ?>
                    <?php if ($w['visible_to_athlete']): ?>
                    <span class="pill" style="background:var(--recessed-bg);color:var(--text-muted);font-size:10px;">visible</span>
                    <?php endif; ?>
                </div>
                <?php if ($description !== ''): ?>
                <?php if ($isLongDescription): ?>
                <details class="coach-workout-details">
                    <summary>
                        <span class="coach-workout-preview"><?= nl2br(h($descriptionPreview)) ?>&hellip; </span>
                        <span class="coach-workout-toggle coach-workout-toggle-more">Show more</span>
                        <span class="coach-workout-toggle coach-workout-toggle-less">Show less</span>
                    </summary>
                    <p class="body-text" style="margin:6px 0 0;font-size:12px;">
                        <?= nl2br(h($description)) ?>
                    </p>
                </details>
                <?php else: ?>
                <p class="body-text" style="margin:6px 0 0;font-size:12px;">
                    <?= nl2br(h($description)) ?>
                </p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($macroWeeks)): ?>
        <div class="section-label" style="margin-top:24px;">FULL MACRO PLAN</div>
        <div class="macro-plan-list">
            <?php foreach ($macroWeeks as $macroWeek): ?>
            <section class="macro-week">
                <div class="macro-week-header">
                    <span>
                        Week <?= (int)$macroWeek['number'] ?> of <?= (int)$macroWeek['total'] ?>
                        &middot;
                        <?= h($macroWeek['phase']) ?>
                    </span>
                    <?php if (!empty($macroWeek['cutback'])): ?>
                    <span class="pill pill-warning" style="font-size:10px;">Cutback</span>
                    <?php endif; ?>
                </div>
                <div class="macro-week-wrap">
                    <div class="macro-week-grid">
                        <?php foreach ($macroWeek['days'] as $day):
                            $date = $day['date'];
                            $insidePlan = !empty($day['inside_plan']);
                            $dayWorkouts = $day['workouts'] ?? [];
                            $dayClass = $insidePlan
                                ? (empty($dayWorkouts) ? ' macro-day-empty' : '')
                                : ' macro-day-outside';
                        ?>
                        <div class="macro-day<?= $dayClass ?>">
                            <?php if ($insidePlan): ?>
                            <div class="macro-day-date"><?= date('D M j', strtotime($date)) ?></div>
                            <?php if (empty($dayWorkouts)): ?>
                            <div style="font-size:12px;color:var(--text-muted);">Rest</div>
                            <?php else: ?>
                            <?php foreach ($dayWorkouts as $w):
                                $score = isset($w['compliance_score']) && $w['compliance_score'] !== null
                                    ? (float)$w['compliance_score']
                                    : null;
                                $isPastWorkout = $date < $today;
                                $complianceClass = 'compliance-none';
                                $complianceTitle = 'No compliance logged';
                                if ($score !== null) {
                                    if ($score >= 0.85) {
                                        $complianceClass = 'compliance-good';
                                        $complianceTitle = 'Compliance ' . round($score * 100) . '%';
                                    } elseif ($score >= 0.70) {
                                        $complianceClass = 'compliance-ok';
                                        $complianceTitle = 'Compliance ' . round($score * 100) . '%';
                                    } else {
                                        $complianceClass = 'compliance-poor';
                                        $complianceTitle = 'Compliance ' . round($score * 100) . '%';
                                    }
                                }
                                $title = $w['display_title'] ?: ($w['template_name'] ?: pill_label($w['workout_type']));
                                $description = (string)($w['description'] ?? '');
                            ?>
                            <?php
                            $mwData = htmlspecialchars(json_encode([
                                'type_label'      => pill_label($w['workout_type']),
                                'type_class'      => pill_class($w['workout_type']),
                                'title'           => (string)$title,
                                'date'            => (string)$date,
                                'target_duration' => (int)($w['target_duration'] ?? 0),
                                'summary'         => (string)($w['display_summary'] ?? ''),
                                'description'     => $description,
                            ]), ENT_QUOTES, 'UTF-8');
                            ?>
                            <button type="button" class="macro-workout" data-mw="<?= $mwData ?>">
                                <div class="macro-workout-row">
                                    <span class="pill <?= pill_class($w['workout_type']) ?>">
                                        <?= pill_label($w['workout_type']) ?>
                                    </span>
                                    <?php if ($w['target_duration']): ?>
                                    <span class="macro-duration"><?= format_duration((int)$w['target_duration']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($w['coach_locked'])): ?>
                                    <span class="macro-lock" title="Coach-locked">&#128274;</span>
                                    <?php endif; ?>
                                    <?php if ($isPastWorkout): ?>
                                    <span class="compliance-dot <?= $complianceClass ?> macro-compliance"
                                          title="<?= h($complianceTitle) ?>"></span>
                                    <?php endif; ?>
                                </div>
                            </button>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="card" style="margin-bottom:16px;">
            <div class="empty-state" style="padding:24px 0;">
                <div class="empty-state-title">No active plan</div>
                <p class="body-text">This athlete doesn't have an active training plan yet.</p>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /left -->

    <!-- Right sidebar -->
    <div>

        <!-- Generate plan -->
        <div style="margin-bottom:16px;">
            <form method="POST" action="/app/coach/athlete/<?= (int)$athlete['id'] ?>/generate-plan"
                  onsubmit="return confirm('Generate a new plan for <?= h(addslashes($athlete['name'])) ?>? Any pending plan in the queue will be replaced.');">
                <?= Auth::csrfField() ?>
                <button type="submit" class="btn btn-primary btn-full">
                    Generate Plan
                </button>
            </form>
            <a href="/app/coach/athlete/<?= (int)$athlete['id'] ?>/edit"
               class="btn btn-secondary btn-full" style="margin-top:8px;">
                Edit Training Profile
            </a>
        </div>

        <!-- Training load -->
        <div class="section-label">TRAINING LOAD</div>
        <div class="card" style="margin-bottom:16px;">
            <?php if ($loadSnapshot): ?>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
                <?php foreach (['atl' => 'ATL', 'ctl' => 'CTL', 'tsb' => 'TSB'] as $key => $label):
                    $val = round((float)($loadSnapshot[$key] ?? 0), 1);
                    $color = $key === 'tsb'
                        ? ($val >= 0 ? 'var(--color-success)' : 'var(--color-danger)')
                        : 'var(--text-primary)';
                ?>
                <div class="metric-tile" style="text-align:center;">
                    <div style="font-size:18px;font-weight:600;color:<?= $color ?>;"><?= $val ?></div>
                    <div class="metric-label"><?= $label ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="body-text">No training load data yet.</p>
            <?php endif; ?>
        </div>

        <!-- Athlete profile -->
        <?php if ($profile): ?>
        <div class="section-label">PROFILE</div>
        <div class="card" style="margin-bottom:16px;">
            <?php $fields = [
                'Experience'       => $profile['experience_level'] ?? null,
                'Weekly volume'    => $profile['current_weekly_minutes'] ? format_duration((int)$profile['current_weekly_minutes']) . '/wk' : null,
                'Training days'    => $profile['training_days_per_week'] ? $profile['training_days_per_week'] . ' days/week' : null,
                'Units'            => $profile['units'] ?? null,
                'Plan type'        => $profile['plan_type'] ? ucfirst(str_replace('_', ' ', $profile['plan_type'])) : null,
            ]; ?>
            <?php foreach ($fields as $label => $value):
                if (!$value) continue; ?>
            <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0;
                        border-bottom:1px solid var(--divider);">
                <span style="color:var(--text-muted);"><?= h($label) ?></span>
                <span style="font-weight:500;"><?= h($value) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Next race -->
        <?php if ($nextRace): ?>
        <div class="section-label">NEXT RACE</div>
        <div class="card" style="margin-bottom:16px;">
            <div style="font-size:14px;font-weight:600;">
                <?= h($nextRace['race_name'] ?? ucfirst($nextRace['race_distance'])) ?>
            </div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                <?= date('M j, Y', strtotime($nextRace['race_date'])) ?>
            </div>
            <?php $days = (int)ceil((strtotime($nextRace['race_date']) - time()) / 86400); ?>
            <div style="font-size:20px;font-weight:700;color:var(--accent-mid);margin-top:8px;">
                <?= $days ?> day<?= $days !== 1 ? 's' : '' ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Personal bests -->
        <?php if (!empty($pbs)): ?>
        <div class="section-label">PERSONAL BESTS</div>
        <div class="card" style="margin-bottom:16px;">
            <?php foreach ($pbs as $pb):
                $secs = (int)$pb['time_seconds'];
                $t = $secs < 3600
                    ? sprintf('%d:%02d', intdiv($secs, 60), $secs % 60)
                    : sprintf('%d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
            ?>
            <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0;
                        border-bottom:1px solid var(--divider);">
                <span style="color:var(--text-muted);"><?= h($pb['distance']) ?></span>
                <span style="font-weight:600;"><?= h($t) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Messages -->
        <div class="section-label">MESSAGES</div>
        <div class="card">
            <?php if ($lastMessage): ?>
            <div style="font-size:12px;color:var(--text-secondary);margin-bottom:10px;
                        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <strong><?= $lastMessage['sender_role'] === 'athlete' ? h($athlete['name']) : 'You' ?>:</strong>
                <?= h(mb_substr($lastMessage['body'], 0, 70)) ?>
            </div>
            <?php endif; ?>
            <?php if ($unreadAthleteMessages > 0): ?>
            <div style="margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                <span class="unread-badge" style="background:var(--color-info);"><?= $unreadAthleteMessages ?></span>
                <span style="font-size:12px;color:var(--text-secondary);">
                    unread message<?= $unreadAthleteMessages !== 1 ? 's' : '' ?>
                </span>
            </div>
            <?php endif; ?>
            <a href="/app/coach/athlete/<?= (int)$athlete['id'] ?>/messages"
               class="btn btn-secondary btn-sm btn-full">
                <?= $unreadAthleteMessages > 0 ? 'Reply →' : ($lastMessage ? 'View thread →' : 'Start conversation →') ?>
            </a>
        </div>

    </div><!-- /sidebar -->

    </div><!-- /grid -->

    <!-- Workout detail popout (view-only) — mirrors the #calWD modal in
         views/partials/calendar_week.php for visual consistency. -->
    <div id="mwd" role="dialog" aria-modal="true" aria-label="Workout detail">
        <div id="mwd-bd"></div>
        <div id="mwd-sheet">
            <button id="mwd-close" aria-label="Close">×</button>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;padding-right:28px;">
                <span id="mwd-type" class="pill"></span>
                <span id="mwd-date" style="font-size:12px;color:var(--text-muted);"></span>
            </div>
            <div id="mwd-name"
                 style="font-size:15px;font-weight:600;color:var(--text-primary);margin-bottom:10px;"></div>
            <div id="mwd-dur-wrap" style="margin-bottom:14px;">
                <div style="font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
                            color:var(--text-muted);margin-bottom:2px;">Target duration</div>
                <div id="mwd-dur" style="font-size:14px;font-weight:500;color:var(--text-primary);"></div>
            </div>
            <div id="mwd-summary" style="font-size:13px;color:var(--text-muted);margin-bottom:10px;"></div>
            <div id="mwd-desc"
                 style="font-size:13px;color:var(--text-secondary);line-height:1.6;white-space:pre-line;"></div>
        </div>
    </div>

    <script>
    (function () {
        function $id(id) { return document.getElementById(id); }
        function fmtDur(m) {
            m = parseInt(m, 10);
            if (!m) return '—';
            if (m < 60) return m + ' min';
            var h = Math.floor(m / 60), r = m % 60;
            return r ? h + 'h ' + r + 'min' : h + 'h';
        }
        function setBlock(id, val) {
            $id(id).textContent = val || '';
            $id(id).style.display = val ? '' : 'none';
        }
        function openModal(el) {
            var raw = el.getAttribute('data-mw');
            if (!raw) return;
            var d;
            try { d = JSON.parse(raw); } catch (e) { return; }
            $id('mwd-type').textContent = d.type_label || '';
            $id('mwd-type').className   = 'pill ' + (d.type_class || '');
            $id('mwd-date').textContent = d.date
                ? new Date(d.date + 'T00:00:00').toLocaleDateString('en-US', {weekday:'long', month:'short', day:'numeric'})
                : '';
            $id('mwd-name').textContent = d.title || '';
            $id('mwd-dur').textContent  = fmtDur(d.target_duration);
            $id('mwd-dur-wrap').style.display = d.target_duration ? '' : 'none';
            setBlock('mwd-summary', d.summary);
            setBlock('mwd-desc', d.description);
            $id('mwd').classList.add('is-open');
            document.body.style.overflow = 'hidden';
        }
        function closeModal() {
            $id('mwd').classList.remove('is-open');
            document.body.style.overflow = '';
        }
        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-mw]');
            if (el) { openModal(el); return; }
            if (e.target.id === 'mwd-bd' || e.target.id === 'mwd-close') { closeModal(); return; }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && $id('mwd').classList.contains('is-open')) closeModal();
        });
    })();
    </script>
</div>

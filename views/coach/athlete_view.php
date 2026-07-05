<?php
// $athlete, $profile, $activePlan, $allWorkouts, $athleteFlags, $loadSnapshot, $pbs, $nextRace
// Plan dates belong to the athlete, so "today"/window are anchored on the ATHLETE's timezone.
$athleteTz   = $athlete['timezone'] ?? Timezone::DEFAULT_TZ;
$today       = Timezone::dateInZone($athleteTz, 'now');
$tzDiffers   = $athleteTz !== Auth::timezone();
$tzNote      = $tzDiffers ? ('Times shown in the athlete\'s timezone: ' . Timezone::label($athleteTz)) : '';

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
            in_array($d, ['50k','50km','50 km','50k ultra'], true) => '50k',
            in_array($d, ['50_miler','50 miler','50-mile ultra','50 mile','50mi'], true) => '50_miler',
            in_array($d, ['100k','100km','100 km','100k ultra'], true) => '100k',
            in_array($d, ['100_miler','100 miler','100-mile ultra','100 mile','100mi'], true) => '100_miler',
            in_array($d, ['mile','1 mile','1mile','1500','1500m','mile / 1500m','hyrox'], true) => 'mile',
            default => '5K',
        };
    };

    // True when a (normalized) distance is one of the four ultra distances.
    $isUltraDistance = fn (string $d): bool => in_array($d, ['50k','50_miler','100k','100_miler'], true);

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
            '50k' => [
                'well_trained' => ['runs_per_week' => 5, 'weekly_minutes' => 360, 'long_run_minutes' => 105],
                'workable'     => ['runs_per_week' => 4, 'weekly_minutes' => 240, 'long_run_minutes' => 75],
            ],
            '50_miler' => [
                'well_trained' => ['runs_per_week' => 5, 'weekly_minutes' => 420, 'long_run_minutes' => 120],
                'workable'     => ['runs_per_week' => 4, 'weekly_minutes' => 300, 'long_run_minutes' => 90],
            ],
            '100k' => [
                'well_trained' => ['runs_per_week' => 6, 'weekly_minutes' => 480, 'long_run_minutes' => 150],
                'workable'     => ['runs_per_week' => 5, 'weekly_minutes' => 360, 'long_run_minutes' => 105],
            ],
            '100_miler' => [
                'well_trained' => ['runs_per_week' => 6, 'weekly_minutes' => 600, 'long_run_minutes' => 180],
                'workable'     => ['runs_per_week' => 5, 'weekly_minutes' => 420, 'long_run_minutes' => 120],
            ],
            'mile' => [
                'well_trained' => ['runs_per_week' => 4, 'weekly_minutes' => 180, 'long_run_minutes' => 45],
                'workable'     => ['runs_per_week' => 3, 'weekly_minutes' => 120, 'long_run_minutes' => 30],
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

    $phaseForWeek = function (string $planType, int $week, int $totalWeeks) use ($profile, $normalizeDistance, $classifyProfile, $isUltraDistance): string {
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
        // 100-miler expands base/shortens taper (ultra Part 5); mile is speed-weighted (mile Part 5).
        if ($distance === '100_miler') {
            $props = ['base' => 0.35, 'build' => 0.30, 'peak' => 0.20, 'taper' => 0.10];
        } elseif ($distance === 'mile') {
            $props = ['base' => 0.25, 'build' => 0.35, 'peak' => 0.25, 'taper' => 0.15];
        } else {
            $props = $propsByClass[$class] ?? $propsByClass['workable'];
        }
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

    $isCutbackWeek = function (string $planType, int $week, string $phase) use ($profile, $normalizeDistance, $isUltraDistance): bool {
        if ($week <= 1) return false;
        if ($planType === 'development_plan') return $week % 4 === 0;
        if ($planType === 'race_cycle') {
            if (strtolower($phase) === 'taper') return false;
            $distance = $normalizeDistance($profile['goal_race_distance'] ?? '5K');
            // Mirror PlanGenerator::isCutbackWeek for ultra cadences.
            if ($distance === '100_miler') return $week % 3 === 0;
            if (in_array($distance, ['50_miler','100k'], true)) {
                $cut = 4; $gapThree = true;
                while ($cut < $week) { $cut += $gapThree ? 3 : 4; $gapThree = !$gapThree; }
                return $cut === $week;
            }
            return $week % 4 === 0;
        }
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
    $planType = (string)($activePlan['plan_type'] ?? '');
    // Return-to-running has no code-week phase structure; it is labeled by sequential
    // calendar week. Code-week numbering (min overlapping code-week) would repeat
    // "Week 1" across calendar weeks for a mid-week-start plan.
    $isRtrPlan = $planType === 'return_to_running';
    $calendarAlignedTypes = ['development_plan', 'maintenance_plan', 'recovery_block'];
    $codeWeekStartTs = $planStartTs;

    $hasCalendarAlignedCodeWeeks = in_array($planType, $calendarAlignedTypes, true)
        && ($firstDow === 1 || $lastDow === 7);

    if ($hasCalendarAlignedCodeWeeks) {
        $offsetToMonday = (8 - $firstDow) % 7;
        $codeWeekStartTs = strtotime('+' . $offsetToMonday . ' days', $planStartTs);
        if ($codeWeekStartTs > $planEndTs) {
            $codeWeekStartTs = $planStartTs;
        }
    }

    $codeTotalWeeks = max(1, (int)ceil(max(1, $planEndTs - $codeWeekStartTs + 86400) / (7 * 86400)));

    for ($weekIndex = 1, $weekStartTs = $firstMondayTs; $weekStartTs <= $lastSundayTs; $weekIndex++, $weekStartTs = strtotime('+7 days', $weekStartTs)) {
        $days = [];
        $codeWeeks = [];
        for ($iso = 1; $iso <= 7; $iso++) {
            $dayTs = strtotime('+' . ($iso - 1) . ' days', $weekStartTs);
            $date  = date('Y-m-d', $dayTs);
            $insidePlan = $dayTs >= $planStartTs && $dayTs <= $planEndTs;
            if ($insidePlan && $dayTs >= $codeWeekStartTs) {
                $codeWeeks[] = max(1, (int)floor(($dayTs - $codeWeekStartTs) / (7 * 86400)) + 1);
            }
            $days[] = [
                'date' => $date,
                'inside_plan' => $insidePlan,
                'workouts' => $insidePlan ? ($workoutsByDate[$date] ?? []) : [],
            ];
        }
        $phaseWeek = $codeWeeks ? min($codeWeeks) : null;
        $phase = $phaseWeek === null ? 'Lead-in' : $phaseForWeek($planType, $phaseWeek, $codeTotalWeeks);
        $cutback = false;
        foreach (array_unique($codeWeeks) as $codeWeek) {
            if ($isCutbackWeek($planType, (int)$codeWeek, $phaseForWeek($planType, (int)$codeWeek, $codeTotalWeeks))) {
                $cutback = true;
                break;
            }
        }

        $macroWeeks[] = [
            // RTR: sequential calendar-week numbering out of the calendar total.
            'number' => $isRtrPlan ? $weekIndex : ($phaseWeek ?? $weekIndex),
            'calendar_number' => $weekIndex,
            'total' => $isRtrPlan ? $macroTotalWeeks : $codeTotalWeeks,
            'calendar_total' => $macroTotalWeeks,
            'phase' => $phase,
            'cutback' => $cutback,
            'lead_in' => $isRtrPlan ? false : ($phaseWeek === null),
            'days' => $days,
        ];
    }
}
?>
<?php
// Race-management (§26): races keyed by date + conflict precompute for the macro grid.
$racesByDate   = $racesByDate ?? [];
$raceDates     = array_keys($racesByDate);
$qualityTypesRM = ['interval','tempo','hill','fartlek','speed','race_pace'];
// Nearest upcoming race within 7 days of a given date → 'red' (≤3d), 'yellow' (≤7d), or ''.
$raceConflictClass = function (string $date) use ($raceDates): string {
    $best = 999;
    foreach ($raceDates as $rd) {
        if ($rd <= $date) continue;
        $dd = (int)round((strtotime($rd) - strtotime($date)) / 86400);
        if ($dd <= 7 && $dd < $best) $best = $dd;
    }
    if ($best <= 3) return 'red';
    if ($best <= 7) return 'yellow';
    return '';
};
?>
<div class="page-content">
    <?php if (!empty($flashSuccess)): ?>
    <div class="flash flash-success" style="margin-bottom:16px;"><?= h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
    <div class="flash flash-error" style="margin-bottom:16px;"><?= h($flashError) ?></div>
    <?php endif; ?>
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
    .macro-moved-badge {
        font-size: 11px;
        line-height: 1;
        color: var(--color-info);
        font-weight: 700;
    }
    .macro-compliance {
        margin-left: auto;
        align-self: center;
    }

    /* Profile / key-value rows (desktop: label left, value right). */
    .av-kv {
        display: flex;
        justify-content: space-between;
        gap: 8px 16px;
        font-size: 12px;
        padding: 4px 0;
        border-bottom: 1px solid var(--divider);
    }
    .av-kv > span:first-child { color: var(--text-muted); }
    .av-kv > span:last-child  { font-weight: 500; text-align: right; }

    /* Messages preview (desktop: single-line ellipsis). */
    .av-msg-preview {
        font-size: 12px;
        color: var(--text-secondary);
        margin-bottom: 10px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Header text block must be able to shrink so a long email wraps. */
    .av-header-text { min-width: 0; }
    .av-header-text .page-heading { overflow-wrap: anywhere; }
    .av-header-email { overflow-wrap: anywhere; }

    /* ── Mobile (≤768px): single full-width column, no horizontal overflow ── */
    @media (max-width: 767px) {
        /* Macro plan: stack each week's days into full-width rows. */
        .macro-week-wrap {
            overflow-x: visible;
            padding-bottom: 0;
        }
        .macro-week-grid {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }
        /* Calendar-alignment padding days carry no info in a stacked list.
           Compound selector so it beats the equal-specificity `.macro-day`
           rule below (source order would otherwise re-show it as flex). */
        .macro-day.macro-day-outside { display: none; }
        .macro-day {
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 12px;
        }
        .macro-day-date {
            margin-bottom: 0;
            flex: 0 0 auto;
        }
        .macro-day-body {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            min-width: 0;
        }
        /* Rest days: muted, lighter visual weight than workout days. */
        .macro-day-empty { opacity: .7; }
        .macro-rest {
            font-size: 12px;
            color: var(--text-muted);
        }
        .macro-workout {
            width: auto;
            margin-top: 0;
            padding: 4px 0;
            min-height: 44px;
            display: flex;
            align-items: center;
        }
        .macro-workout:hover { background: none; }
        .macro-workout-row {
            flex-wrap: nowrap;
            justify-content: flex-end;
        }

        /* Profile rows: stack label above value so nothing clips. */
        .av-kv {
            flex-direction: column;
            align-items: flex-start;
            gap: 2px;
        }
        .av-kv > span:last-child { text-align: left; }

        /* Message preview wraps instead of truncating to one line. */
        .av-msg-preview { white-space: normal; }

        /* Alert cards stack their header above the message text. */
        .av-grid .roster-row {
            flex-direction: column;
            align-items: stretch;
        }

        /* Comfortable touch targets for all action/dismiss buttons. */
        .av-grid .btn { min-height: 44px; }
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
    /* Flag-for-review toggle — sits just left of the close button in the modal header. */
    #mwd-flag {
        position: absolute; top: 12px; right: 44px;
        background: none; border: none; cursor: pointer;
        padding: 2px 4px; line-height: 0;
        color: var(--text-muted);
    }
    #mwd-flag svg { display: block; }
    #mwd-flag:hover { color: var(--text-secondary); }
    #mwd-flag.is-flagged { color: var(--accent-mid); }
    #mwd-flag.is-flagged svg { fill: var(--accent-mid); stroke: var(--accent-mid); }

    /* ── Plan management: drag-to-reschedule, add, remove ── */
    .macro-workout[draggable="true"] { cursor: grab; }
    .macro-workout.is-dragging { opacity: .4; }
    .macro-day-drop.drop-target {
        outline: 2px dashed var(--accent-mid);
        outline-offset: -2px;
        background: var(--recessed-bg);
    }
    .macro-add-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-top: 6px;
        padding: 3px 6px;
        border: 1px dashed var(--border-strong);
        border-radius: var(--radius-sm);
        background: none;
        color: var(--text-muted);
        font-size: 11px;
        cursor: pointer;
        opacity: 0;
        transition: opacity .12s;
    }
    .macro-day:hover .macro-add-btn,
    .macro-add-btn:focus-visible { opacity: 1; }
    .macro-add-btn:hover { color: var(--accent-mid); border-color: var(--accent-mid); }
    .macro-removed-marker {
        font-size: 10px;
        color: var(--text-muted);
        opacity: .6;
        font-style: italic;
    }
    @media (max-width: 767px) {
        /* Touch can't hover — keep the add button visible on mobile. */
        .macro-add-btn { opacity: .8; margin-top: 0; }
    }

    /* ── Add-workout modal ── */
    #awd { display: none; position: fixed; inset: 0; z-index: 9999;
           align-items: center; justify-content: center; }
    #awd.is-open { display: flex; }
    #awd-bd { position: absolute; inset: 0; background: rgba(0,0,0,.45); }
    #awd-sheet {
        position: relative; z-index: 1;
        width: min(540px, calc(100vw - 32px));
        max-height: 90vh; overflow-y: auto;
        background: var(--card-bg);
        border: var(--card-border);
        border-radius: var(--radius-card);
        padding: 20px 20px 24px;
        box-shadow: 0 20px 60px rgba(0,0,0,.25);
    }
    #awd-close {
        position: absolute; top: 12px; right: 14px;
        background: none; border: none; cursor: pointer;
        font-size: 22px; line-height: 1; padding: 2px 4px; color: var(--text-muted);
    }
    #awd-close:hover { color: var(--text-primary); }
    .awd-tabs { display: flex; gap: 6px; margin: 4px 0 16px; }
    .awd-tab {
        flex: 1; padding: 8px; border: var(--card-border); border-radius: var(--radius-sm);
        background: var(--recessed-bg); color: var(--text-secondary);
        font-size: 13px; font-weight: 600; cursor: pointer;
    }
    .awd-tab.is-active { background: var(--accent-mid); color: #fff; border-color: var(--accent-mid); }
    .awd-filter { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
    .awd-filter-btn {
        padding: 4px 10px; border: var(--card-border); border-radius: 999px;
        background: var(--card-bg); color: var(--text-secondary); font-size: 12px; cursor: pointer;
    }
    .awd-filter-btn.is-active { background: var(--accent-mid); color: #fff; border-color: var(--accent-mid); }
    .awd-arch-list { max-height: 260px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; }
    .awd-arch {
        text-align: left; width: 100%; padding: 10px 12px;
        border: var(--card-border); border-radius: var(--radius-sm);
        background: var(--card-bg); cursor: pointer;
    }
    .awd-arch.is-selected { border-color: var(--accent-mid); background: var(--recessed-bg); }
    .awd-arch-name { font-size: 13px; font-weight: 600; color: var(--text-primary); }
    .awd-arch-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
    .awd-preview {
        border: var(--card-border); border-radius: var(--radius-sm);
        background: var(--recessed-bg); padding: 12px; margin-top: 4px;
    }
    .awd-preview-title { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
    .awd-preview-summary { font-size: 12px; color: var(--text-muted); margin-bottom: 8px; }
    .awd-preview-instr { font-size: 13px; color: var(--text-secondary); line-height: 1.6; white-space: pre-line; }
    .awd-err { display: none; font-size: 12px; color: var(--color-danger); margin-top: 8px; }
    .awd-actions { display: flex; gap: 8px; align-items: center; margin-top: 16px; }
    #mwd-remove {
        display: block; width: 100%; margin-top: 18px; padding: 8px;
        border: 1px solid var(--danger-border); border-radius: var(--radius-sm);
        background: none; color: var(--color-danger); font-size: 13px; cursor: pointer;
    }
    #mwd-remove:hover { background: var(--danger-fill); }
    /* Edit-workout modal reuses the .awd-* element styles; only its container differs. */
    #ewd { display: none; position: fixed; inset: 0; z-index: 9999; align-items: center; justify-content: center; }
    #ewd.is-open { display: flex; }
    #ewd-bd { position: absolute; inset: 0; background: rgba(0,0,0,.45); }
    #ewd-sheet {
        position: relative; z-index: 1; width: min(540px, calc(100vw - 32px)); max-height: 90vh; overflow-y: auto;
        background: var(--card-bg); border: var(--card-border); border-radius: var(--radius-card);
        padding: 20px 20px 24px; box-shadow: 0 20px 60px rgba(0,0,0,.25);
    }
    #ewd-close {
        position: absolute; top: 12px; right: 14px; background: none; border: none; cursor: pointer;
        font-size: 22px; line-height: 1; padding: 2px 4px; color: var(--text-muted);
    }
    #ewd-close:hover { color: var(--text-primary); }
    </style>

    <?php /* Flash banners render once at the top of .page-content (see the
             .flash blocks above); the duplicate .alert copy was removed (F5). */ ?>

    <!-- Shared chrome: back + header + sub-nav tab strip -->
    <?php include __DIR__ . '/partials/athlete_chrome.php'; ?>

    <div class="av-grid">

    <!-- Left: Plan + workouts -->
    <div>

        <?php if (!empty($athleteFlags)): ?>
        <div class="section-label" style="color:var(--color-danger);">OPEN ALERTS</div>
        <?php foreach ($athleteFlags as $flag):
            $fCrit      = $flag['severity'] === 'critical';
            $fIsProfile = ($flag['flag_type'] ?? '') === 'profile_updated';
        ?>
        <div class="flag-card <?= $fCrit ? 'flag-card-critical' : 'flag-card-warning' ?>" style="margin-bottom:8px;">
            <div class="flag-body">
                <div class="flag-card-head">
                    <div class="flag-card-titlewrap">
                        <span class="pill <?= $fCrit ? 'pill-critical' : 'pill-warning' ?>" style="font-size:10px;"><?= h(ucfirst($flag['severity'])) ?></span>
                        <span class="flag-card-title"><?= h($flag['flag_type']) ?></span>
                    </div>
                    <form method="POST" action="/app/coach/flags/<?= (int)$flag['id'] ?>/dismiss" style="margin-left:auto;">
                        <?= Auth::csrfField() ?>
                        <button type="submit" class="btn btn-secondary btn-sm">Dismiss</button>
                    </form>
                </div>
                <?php // profile_updated shows the structured diff only — no prose restatement. ?>
                <?php if (!$fIsProfile && $flag['message']): ?>
                <p class="flag-card-msg"><?= h($flag['message']) ?></p>
                <?php endif; ?>
                <?php if ($fIsProfile): ?>
                <?= render_profile_diff($flag['details'] ?? null) ?>
                <?php endif; ?>
            </div>
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
                    <?php $goalDist = $profile['goal_race_distance'] ?? ''; if ($goalDist !== ''): ?>
                    <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;">
                        Goal: <?= h(goal_distance_label($goalDist, !empty($profile['is_hyrox']), true)) ?>
                    </div>
                    <?php endif; ?>
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
        <?php if ($tzNote): ?>
        <div style="font-size:11px;color:var(--text-muted);margin:-4px 0 8px;"><?= h($tzNote) ?></div>
        <?php endif; ?>
        <?php
        $windowEnd = Timezone::dateInZone($athleteTz, '+6 days');
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
                    <span class="pill <?= pill_class($w['workout_type'], $w['archetype_code'] ?? null) ?>">
                        <?= pill_label($w['workout_type'], $w['archetype_code'] ?? null) ?>
                    </span>
                    <?php if ($w['target_duration']): ?>
                    <span style="font-size:12px;color:var(--text-muted);"><?= format_duration((int)$w['target_duration']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($w['coach_locked'])): ?>
                    <span title="Coach-locked" style="font-size:14px;">🔒</span>
                    <?php endif; ?>
                    <?php if (($w['added_by_role'] ?? '') === 'assistant_coach'): ?>
                    <span class="pill" title="Added by assistant coach (not shown to the athlete)"
                          style="background:var(--recessed-bg);color:var(--text-secondary);font-size:10px;font-weight:600;">AC</span>
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
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:24px;">
            <div class="section-label" style="margin:0;">FULL MACRO PLAN</div>
            <button type="button" class="btn btn-secondary btn-sm" data-coach-race-add>+ Add race</button>
        </div>
        <div class="macro-plan-list">
            <?php foreach ($macroWeeks as $macroWeek): ?>
            <section class="macro-week">
                <div class="macro-week-header">
                    <span>
                        <?php if (!empty($macroWeek['lead_in'])): ?>
                        Lead-in
                        <?php else: ?>
                        Week <?= (int)$macroWeek['number'] ?> of <?= (int)$macroWeek['total'] ?>
                        &middot;
                        <?= h($macroWeek['phase']) ?>
                        <?php endif; ?>
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
                            $isEmptyDay = $insidePlan && empty($dayWorkouts);
                            $isRemoved  = $isEmptyDay && in_array($date, $cancelledDates, true);
                            $dayClass = $insidePlan
                                ? ($isEmptyDay ? ' macro-day-empty' : '')
                                : ' macro-day-outside';
                            if ($insidePlan) $dayClass .= ' macro-day-drop';
                        ?>
                        <div class="macro-day<?= $dayClass ?>"<?= $insidePlan ? ' data-date="' . h($date) . '"' : '' ?>>
                            <?php if ($insidePlan): ?>
                            <div class="macro-day-date"><?= date('D M j', strtotime($date)) ?></div>
                            <div class="macro-day-body">
                            <?php foreach (($racesByDate[$date] ?? []) as $r):
                                $rLabel = RaceController::distanceLabel($r['race_distance']); ?>
                            <button type="button" class="macro-race-pill" data-coach-race
                                    data-race-name="<?= h($r['race_name']) ?>"
                                    data-race-date="<?= h($r['race_date']) ?>"
                                    data-race-distance="<?= h($rLabel) ?>"
                                    data-race-goal="<?= (int)$r['is_goal_race'] ?>"
                                    data-race-result="<?= $r['result_time'] !== null ? h(RaceController::secondsToHms((int)$r['result_time'])) : '' ?>"
                                    data-race-notes="<?= h((string)($r['notes'] ?? '')) ?>">
                                <?= $r['is_goal_race'] ? 'GOAL: ' . h($rLabel) : h($rLabel) . ' · ' . h($r['race_name']) ?>
                            </button>
                            <?php endforeach; ?>
                            <?php if ($isEmptyDay): ?>
                            <div class="macro-rest" style="font-size:12px;color:var(--text-muted);">Rest</div>
                            <?php if ($isRemoved): ?>
                            <div class="macro-removed-marker" title="A workout was removed from this day">Removed</div>
                            <?php endif; ?>
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
                                $title = $w['display_title'] ?: ($w['template_name'] ?: pill_label($w['workout_type'], $w['archetype_code'] ?? null));
                                $description = (string)($w['description'] ?? '');
                                // Race conflict border: quality session close to an upcoming race.
                                $conflictRM = in_array($w['workout_type'], $qualityTypesRM, true)
                                    ? $raceConflictClass($date) : '';
                            ?>
                            <?php
                            // Structured-editor pre-fill, read from archetype_params. null otherwise.
                            // The 5 uniform-rep archetypes carry count + size + recovery; mixed_distance
                            // carries an ordered interval_distances ladder (Phase 2 rung editor).
                            $structuredFields = null;
                            $sCode = (string)($w['archetype_code'] ?? '');
                            if (in_array($sCode, ['tempo_intervals','sustained_hill_repeats','equal_distance_repeats','short_speed_repeats','high_volume_time_intervals'], true)) {
                                $apFields = json_decode((string)($w['archetype_params'] ?? '{}'), true) ?: [];
                                $structuredFields = [];
                                foreach (['rep_count','rep_duration_minutes','rep_duration_seconds','rep_distance_meters','work_duration_seconds','recovery_duration_seconds'] as $sf) {
                                    $structuredFields[$sf] = isset($apFields[$sf]) ? (int)$apFields[$sf] : null;
                                }
                            } elseif ($sCode === 'mixed_distance_repeats') {
                                $apFields = json_decode((string)($w['archetype_params'] ?? '{}'), true) ?: [];
                                $rungs = array_values(array_filter(array_map('intval', (array)($apFields['interval_distances'] ?? [])), fn($m) => $m > 0));
                                $structuredFields = ['interval_distances' => $rungs];
                            }
                            $mwData = htmlspecialchars(json_encode([
                                'id'              => (int)$w['id'],
                                'structured'      => $structuredFields,
                                'workout_type'    => (string)$w['workout_type'],
                                'type_label'      => pill_label($w['workout_type'], $w['archetype_code'] ?? null),
                                'type_class'      => pill_class($w['workout_type'], $w['archetype_code'] ?? null),
                                'title'           => (string)$title,
                                'date'            => (string)$date,
                                'target_duration' => (int)($w['target_duration'] ?? 0),
                                'summary'         => (string)($w['display_summary'] ?? ''),
                                'description'     => $description,
                                'coach_locked'    => !empty($w['coach_locked']) ? 1 : 0,
                                'completed_workout_id' => (int)($w['completed_workout_id'] ?? 0),
                                'flagged'         => !empty($w['flagged_for_review']) ? 1 : 0,
                                // Edit affordance: future + uncompleted only.
                                'editable'        => ($date >= $today && empty($w['completed_workout_id'])) ? 1 : 0,
                                'archetype_code'  => $w['archetype_code'] !== null ? (string)$w['archetype_code'] : '',
                                'archetype_variant' => $w['archetype_variant'] !== null ? (string)$w['archetype_variant'] : '',
                                'instructions'    => (string)($w['athlete_instructions'] ?? ''),
                                'coach_notes'     => (string)($w['notes'] ?? ''),
                                'athlete_moved'   => !empty($w['athlete_moved']) ? 1 : 0,
                                'moved_from'      => !empty($w['original_scheduled_date']) ? (string)$w['original_scheduled_date'] : '',
                            ]), ENT_QUOTES, 'UTF-8');
                            ?>
                            <button type="button" class="macro-workout<?= $conflictRM ? ' macro-conflict-' . $conflictRM : '' ?>"
                                    draggable="true"
                                    <?= $conflictRM ? 'title="' . ($conflictRM === 'red' ? 'Quality session within 3 days of a race' : 'Quality session within 7 days of a race') . '" ' : '' ?>
                                    data-workout-id="<?= (int)$w['id'] ?>" data-mw="<?= $mwData ?>">
                                <div class="macro-workout-row">
                                    <span class="pill <?= pill_class($w['workout_type'], $w['archetype_code'] ?? null) ?>">
                                        <?= pill_label($w['workout_type'], $w['archetype_code'] ?? null) ?>
                                    </span>
                                    <?php if ($w['target_duration']): ?>
                                    <span class="macro-duration"><?= format_duration((int)$w['target_duration']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($w['coach_locked'])): ?>
                                    <span class="macro-lock" title="Coach-locked">&#128274;</span>
                                    <?php endif; ?>
                                    <?php if (($w['added_by_role'] ?? '') === 'assistant_coach'): ?>
                                    <span class="macro-ac-badge" title="Added by assistant coach (not shown to the athlete)">AC</span>
                                    <?php endif; ?>
                                    <?php if (!empty($w['carried_over_from_plan_id'])): ?>
                                    <span class="macro-carried-badge" title="Carried over from the prior plan (week already seen by the athlete)">↻</span>
                                    <?php endif; ?>
                                    <?php if (!empty($w['athlete_moved'])): ?>
                                    <span class="macro-moved-badge" title="Moved by the athlete<?= !empty($w['original_scheduled_date']) ? ' (from ' . date('M j', strtotime((string)$w['original_scheduled_date'])) . ')' : '' ?>">⇄</span>
                                    <?php endif; ?>
                                    <?php if ($isPastWorkout): ?>
                                    <span class="compliance-dot <?= $complianceClass ?> macro-compliance"
                                          title="<?= h($complianceTitle) ?>"></span>
                                    <?php endif; ?>
                                </div>
                            </button>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($isEmptyDay): ?>
                            <button type="button" class="macro-add-btn" data-add-date="<?= h($date) ?>">+ Add workout</button>
                            <?php endif; ?>
                            </div><!-- /macro-day-body -->
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

        <!-- Plan actions -->
        <div style="margin-bottom:16px;">
            <?php if (($viewerRole ?? '') === 'assistant_coach'): ?>
            <form method="POST" action="/app/coach/athlete/<?= (int)$athlete['id'] ?>/request-regeneration"
                  onsubmit="return confirm('Request a plan regeneration for <?= h(addslashes($athlete['name'])) ?>? Your head coach will review it.');">
                <?= Auth::csrfField() ?>
                <button type="submit" class="btn btn-primary btn-full">
                    Request plan regeneration
                </button>
            </form>
            <?php else: ?>
            <?php
            // Stage B warning: set by generatePlan when engine-critical fields are missing.
            // Shown once; the form below then carries ack_missing=1 so "Generate anyway" proceeds.
            $genWarn = (!empty($_SESSION['generate_warning'])
                && (int)($_SESSION['generate_warning']['athlete_id'] ?? 0) === (int)$athlete['id'])
                ? $_SESSION['generate_warning'] : null;
            if ($genWarn) unset($_SESSION['generate_warning']);
            ?>
            <?php if ($genWarn): ?>
            <div class="card" style="margin-bottom:12px;border:1px solid var(--color-warning);">
                <div style="font-size:13px;font-weight:600;margin-bottom:6px;">Incomplete profile data</div>
                <p class="body-text" style="margin:0 0 8px;font-size:13px;"><?= h($genWarn['message']) ?></p>
                <a href="/app/coach/athlete/<?= (int)$athlete['id'] ?>/edit" class="btn btn-secondary btn-sm">Set these in Edit Profile →</a>
            </div>
            <?php endif; ?>
            <form method="POST" action="/app/coach/athlete/<?= (int)$athlete['id'] ?>/generate-plan"
                  onsubmit="return confirm('Generate a new plan for <?= h(addslashes($athlete['name'])) ?>? Any pending plan in the queue will be replaced.');">
                <?= Auth::csrfField() ?>
                <?php if ($genWarn): ?><input type="hidden" name="ack_missing" value="1"><?php endif; ?>
                <label style="display:flex;gap:8px;align-items:flex-start;font-size:12px;color:var(--text-secondary);margin-bottom:8px;line-height:1.35;">
                    <input type="checkbox" name="full_wipe" value="1" style="margin-top:2px;flex-shrink:0;">
                    <span>Regenerate entire plan including days that have been exposed to the athlete.</span>
                </label>
                <button type="submit" class="btn btn-primary btn-full">
                    <?= $genWarn ? 'Generate anyway' : 'Generate Plan' ?>
                </button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (!empty($pendingRegen)): ?>
        <!-- Pending regeneration request (head coach / admin) -->
        <div class="card" style="margin-bottom:16px;border:1px solid var(--color-warning);">
            <div style="font-size:13px;margin-bottom:8px;">
                Pending regeneration request from
                <strong><?= h($pendingRegen['requester_name'] ?? 'an assistant coach') ?></strong>
                &middot; <?= h(date('M j', strtotime((string)$pendingRegen['requested_at']))) ?>
            </div>
            <form method="POST" action="/app/coach/regeneration/<?= (int)$pendingRegen['id'] ?>/approve"
                  onsubmit="return confirm('Approve and regenerate this plan now? Any pending plan in the queue will be replaced.');">
                <?= Auth::csrfField() ?>
                <label style="display:flex;gap:8px;align-items:flex-start;font-size:12px;color:var(--text-secondary);margin-bottom:8px;line-height:1.35;">
                    <input type="checkbox" name="full_wipe" value="1" style="margin-top:2px;flex-shrink:0;">
                    <span>Regenerate entire plan including days that have been exposed to the athlete.</span>
                </label>
                <button type="submit" class="btn btn-primary btn-full btn-sm">Approve &amp; regenerate</button>
            </form>
            <form method="POST" action="/app/coach/regeneration/<?= (int)$pendingRegen['id'] ?>/dismiss" style="margin-top:8px;">
                <?= Auth::csrfField() ?>
                <button type="submit" class="btn btn-secondary btn-full btn-sm">Dismiss</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if (!empty($isHeadOrAdmin)): ?>
        <!-- Assistant coach assignment (head coach / admin) -->
        <div class="section-label">ASSISTANT COACH</div>
        <div class="card" style="margin-bottom:16px;">
            <form method="POST" action="/app/coach/athlete/<?= (int)$athlete['id'] ?>/assistant"
                  style="display:flex;gap:8px;align-items:center;">
                <?= Auth::csrfField() ?>
                <select name="assistant_coach_id" class="form-select" style="flex:1;">
                    <option value="">None</option>
                    <?php foreach (($assistantOptions ?? []) as $ac): ?>
                    <option value="<?= (int)$ac['id'] ?>" <?= (int)($currentAssistant ?? 0) === (int)$ac['id'] ? 'selected' : '' ?>>
                        <?= h($ac['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">Save</button>
            </form>
            <?php if (empty($assistantOptions)): ?>
            <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">
                No assistant coaches available<?= ($viewerRole ?? '') === 'admin' ? '' : ' under your account' ?>.
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

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

        <!-- Pace zones (Screen 3 vitals: read-only at-a-glance; editing lives on the Profile tab) -->
        <?php
        $vzZones = ($profile && PaceZones::isPopulated($profile['pace_zones'] ?? null))
            ? (json_decode((string)$profile['pace_zones'], true) ?: []) : [];
        $vzFmt = static function ($secs): string {
            $s = (int)$secs;
            return $s > 0 ? sprintf('%d:%02d', intdiv($s, 60), $s % 60) : '?';
        };
        ?>
        <?php if ($vzZones): ?>
        <div class="section-label">PACE ZONES</div>
        <div class="card" style="margin-bottom:16px;">
            <?php if (isset($vzZones['easy']['min'], $vzZones['easy']['max'])): ?>
            <div class="av-kv"><span>Easy</span><span><?= $vzFmt($vzZones['easy']['min']) ?>-<?= $vzFmt($vzZones['easy']['max']) ?> /mi</span></div>
            <?php endif; ?>
            <?php foreach (['marathon' => 'Marathon', 'half_marathon' => 'Half marathon', '10K' => '10K', '5K' => '5K', 'mile' => 'Mile'] as $zk => $zl): ?>
                <?php if (isset($vzZones[$zk]) && is_numeric($vzZones[$zk])): ?>
                <div class="av-kv"><span><?= $zl ?></span><span><?= $vzFmt($vzZones[$zk]) ?> /mi</span></div>
                <?php endif; ?>
            <?php endforeach; ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:8px;">
                <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--text-secondary);">
                    <?php
                    $vzSource = $profile['pace_zones_source'] ?? null;
                    echo $vzSource === 'race_result' ? 'Verified: race result'
                        : ($vzSource === 'easy_pace_estimate' ? 'Estimated: easy pace'
                        : ($vzSource === 'manual' ? 'Manual: coach set' : 'Set'));
                    ?>
                </span>
                <?php if (isset($profile['pace_zones_visible']) && (int)$profile['pace_zones_visible'] === 0): ?>
                <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--color-warning);">Hidden from athlete</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Predictions + response profile (Coaching Intelligence Phase 3) -->
        <?php require_once __DIR__ . '/../partials/predictive.php'; ?>
        <?php $predFlags = array_values(array_filter($predictiveFlags ?? [], static fn($f) => pf_is_predictive((string)$f['flag_type']))); ?>
        <?php if (!empty($predFlags)): ?>
        <div class="section-label">PREDICTIONS</div>
        <div class="card" style="margin-bottom:16px;">
            <?php foreach ($predFlags as $i => $pf): ?>
            <div style="padding:8px 0;<?= $i ? 'border-top:1px solid var(--recessed-bg);' : '' ?>">
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">
                    <span style="font-size:13px;font-weight:600;"><?= h($pf['title']) ?></span>
                    <?= pf_confidence_badge($pf['confidence'] ?? null, $pf['prediction_horizon_days'] ?? null) ?>
                </div>
                <p class="body-text" style="margin:0;font-size:12px;color:var(--text-secondary);"><?= h($pf['detail']) ?></p>
                <?php if (($pf['flag_type'] ?? '') === 'adaptation_ahead'): ?>
                <form method="POST" action="/app/coach/intelligence/flag/<?= (int)$pf['id'] ?>/adapt-accept" style="margin-top:8px;">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="from" value="athlete">
                    <button type="submit" class="btn btn-primary btn-sm">Accept &amp; request plan</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="section-label">RESPONSE PROFILE</div>
        <div class="card" style="margin-bottom:16px;">
            <?= pf_response_profile_html($responseProfile ?? null) ?>
        </div>

        <!-- Athlete profile -->
        <?php if ($profile): ?>
        <div class="section-label">PROFILE</div>
        <div class="card" style="margin-bottom:16px;">
            <?php $fields = [
                'Weekly volume'    => $profile['current_weekly_minutes'] ? format_duration((int)$profile['current_weekly_minutes']) . '/wk' : null,
                'Training days'    => $profile['training_days_per_week'] ? $profile['training_days_per_week'] . ' days/week' : null,
                'Units'            => $profile['units'] ?? null,
                'Plan type'        => $profile['plan_type'] ? ucfirst(str_replace('_', ' ', $profile['plan_type'])) : null,
            ]; ?>
            <?php foreach ($fields as $label => $value):
                if (!$value) continue; ?>
            <div class="av-kv">
                <span><?= h($label) ?></span>
                <span><?= h($value) ?></span>
            </div>
            <?php endforeach; ?>
            <?php $vzSub = $athlete['subscription_status'] ?? null; if ($vzSub):
                $vzSubColor = in_array($vzSub, ['active', 'comped'], true) ? 'var(--color-success)'
                    : ($vzSub === 'trialing' ? 'var(--color-info)'
                    : ($vzSub === 'past_due' ? 'var(--color-warning)' : 'var(--color-danger)'));
            ?>
            <div class="av-kv">
                <span>Billing</span>
                <span style="color:<?= $vzSubColor ?>;font-weight:600;"><?= h(ucfirst(str_replace('_', ' ', (string)$vzSub))) ?></span>
            </div>
            <?php endif; ?>
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
            <div class="av-msg-preview">
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
            <button type="button" id="mwd-flag" aria-label="Flag for review" title="Flag this workout for review" aria-pressed="false">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/>
                    <line x1="4" y1="22" x2="4" y2="15"/>
                </svg>
            </button>
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
            <div id="mwd-moved-wrap" style="display:none;margin-bottom:14px;font-size:12px;color:var(--text-secondary);">
                <span>Moved by the athlete from <strong id="mwd-moved-from"></strong>.</span>
                <button type="button" id="mwd-revert" class="btn btn-secondary btn-sm" style="margin-left:8px;">Revert to original day</button>
            </div>
            <div id="mwd-summary" style="font-size:13px;color:var(--text-muted);margin-bottom:10px;"></div>
            <div id="mwd-desc"
                 style="font-size:13px;color:var(--text-secondary);line-height:1.6;white-space:pre-line;"></div>

            <!-- Comment on session — shown only when the workout has a logged completion -->
            <div id="mwd-comment-wrap" style="display:none;margin-bottom:14px;">
                <button type="button" id="mwd-comment-btn" class="btn btn-secondary btn-sm">Comment on session</button>
                <form id="mwd-comment-form" method="POST"
                      action="/app/coach/athlete/<?= (int)$athlete['id'] ?>/session-note"
                      style="display:none;margin-top:10px;">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="completed_workout_id" id="mwd-comment-cwid" value="">
                    <textarea name="body" class="form-textarea" rows="3" maxlength="1000"
                              placeholder="Comment on this session…" style="font-size:13px;"></textarea>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button type="submit" class="btn btn-primary btn-sm">Send comment</button>
                        <button type="button" id="mwd-comment-cancel" class="btn btn-secondary btn-sm">Cancel</button>
                    </div>
                </form>
            </div>

            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" id="mwd-edit" class="btn btn-secondary btn-sm" style="display:none;">Edit workout</button>
                <button type="button" id="mwd-remove">Remove workout</button>
            </div>
        </div>
    </div>

    <!-- Add-workout modal (coach plan management) -->
    <div id="awd" role="dialog" aria-modal="true" aria-label="Add workout">
        <div id="awd-bd"></div>
        <div id="awd-sheet">
            <button id="awd-close" aria-label="Close">×</button>
            <div style="font-size:15px;font-weight:600;margin-bottom:2px;padding-right:28px;">Add workout</div>
            <div id="awd-date-label" style="font-size:12px;color:var(--text-muted);margin-bottom:12px;"></div>

            <div class="awd-tabs">
                <button type="button" class="awd-tab is-active" data-awd-tab="archetype">Choose from library</button>
                <button type="button" class="awd-tab" data-awd-tab="freeform">Custom workout</button>
            </div>

            <!-- PATH A — archetype picker -->
            <div id="awd-pane-archetype">
                <div class="awd-filter" id="awd-filter">
                    <button type="button" class="awd-filter-btn is-active" data-cat="all">All</button>
                    <button type="button" class="awd-filter-btn" data-cat="easy">Easy run</button>
                    <button type="button" class="awd-filter-btn" data-cat="long">Long run</button>
                    <button type="button" class="awd-filter-btn" data-cat="quality">Quality</button>
                    <button type="button" class="awd-filter-btn" data-cat="recovery">Recovery</button>
                </div>
                <div class="awd-arch-list" id="awd-arch-list"></div>

                <div id="awd-config" style="display:none;margin-top:14px;">
                    <div class="form-group" id="awd-variant-group" style="display:none;">
                        <label class="form-label" for="awd-variant">Variant</label>
                        <select id="awd-variant" class="form-select"></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="awd-duration">Duration (minutes)</label>
                        <input type="number" id="awd-duration" class="form-input" min="1" max="600" style="max-width:120px;">
                    </div>
                    <button type="button" id="awd-preview-btn" class="btn btn-secondary btn-sm">Preview</button>
                </div>

                <div id="awd-preview-wrap" style="display:none;margin-top:14px;">
                    <div class="awd-preview">
                        <div class="awd-preview-title" id="awd-preview-title"></div>
                        <div class="awd-preview-summary" id="awd-preview-summary"></div>
                        <div class="awd-preview-instr" id="awd-preview-instr"></div>
                    </div>
                    <div class="awd-actions">
                        <button type="button" id="awd-add-arch" class="btn btn-primary btn-sm">Add to plan</button>
                    </div>
                </div>
            </div>

            <!-- PATH B — free-form -->
            <div id="awd-pane-freeform" style="display:none;">
                <div class="form-group">
                    <label class="form-label" for="awd-ff-title">Title</label>
                    <input type="text" id="awd-ff-title" class="form-input" maxlength="255" placeholder="e.g. Easy shakeout">
                </div>
                <div class="form-group">
                    <label class="form-label" for="awd-ff-type">Workout type</label>
                    <select id="awd-ff-type" class="form-select">
                        <option value="easy">Easy run</option>
                        <option value="long">Long run</option>
                        <option value="tempo">Tempo</option>
                        <option value="interval">Workout</option>
                        <option value="recovery">Recovery</option>
                        <option value="race_pace">Race pace</option>
                        <option value="hill">Hill session</option>
                        <option value="fartlek">Fartlek</option>
                        <option value="speed">Speed</option>
                        <option value="rest">Rest</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="awd-ff-dur">Duration (minutes)</label>
                    <input type="number" id="awd-ff-dur" class="form-input" min="1" max="600" style="max-width:120px;">
                </div>
                <div class="form-group">
                    <label class="form-label" for="awd-ff-instr">Instructions <span style="font-weight:400;color:var(--text-muted);">(shown to athlete)</span></label>
                    <textarea id="awd-ff-instr" class="form-textarea" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="awd-ff-notes">Coach notes <span style="font-weight:400;color:var(--text-muted);">(coach-only)</span></label>
                    <textarea id="awd-ff-notes" class="form-textarea" rows="2"></textarea>
                </div>
                <div class="awd-actions">
                    <button type="button" id="awd-add-ff" class="btn btn-primary btn-sm">Add to plan</button>
                </div>
            </div>

            <div class="awd-err" id="awd-err"></div>
        </div>
    </div>

    <!-- Edit-workout modal (coach plan management) — mirrors #awd, three modes -->
    <div id="ewd" role="dialog" aria-modal="true" aria-label="Edit workout">
        <div id="ewd-bd"></div>
        <div id="ewd-sheet">
            <button id="ewd-close" aria-label="Close">×</button>
            <div style="font-size:15px;font-weight:600;margin-bottom:2px;padding-right:28px;">Edit workout</div>
            <div id="ewd-date-label" style="font-size:12px;color:var(--text-muted);margin-bottom:12px;"></div>

            <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">How do you want to edit this?</div>
            <div class="awd-tabs">
                <button type="button" class="awd-tab is-active" data-ewd-tab="surface">Tweak</button>
                <button type="button" class="awd-tab" data-ewd-tab="structure" id="ewd-tab-structure" style="display:none;">Structure</button>
                <button type="button" class="awd-tab" data-ewd-tab="archetype">Replace from library</button>
                <button type="button" class="awd-tab" data-ewd-tab="freeform" id="ewd-tab-freeform">Replace (custom)</button>
            </div>

            <!-- MODE 1 — surface tweak -->
            <div id="ewd-pane-surface">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Adjust the title, instructions, or notes. The workout type and duration come from its structure (change those in Structure or Replace).</div>
                <div class="form-group">
                    <label class="form-label" for="ewd-s-title">Title</label>
                    <input type="text" id="ewd-s-title" class="form-input" maxlength="255">
                </div>
                <div class="form-group" id="ewd-s-type-group">
                    <label class="form-label" for="ewd-s-type">Workout type</label>
                    <select id="ewd-s-type" class="form-select">
                        <option value="easy">Easy run</option><option value="long">Long run</option>
                        <option value="tempo">Tempo</option><option value="interval">Workout</option>
                        <option value="recovery">Recovery</option><option value="race_pace">Race pace</option>
                        <option value="hill">Hill session</option><option value="fartlek">Fartlek</option>
                        <option value="speed">Speed</option><option value="cross_train">Cross-train</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="ewd-s-dur">Duration (minutes)</label>
                    <input type="number" id="ewd-s-dur" class="form-input" min="1" max="600" style="max-width:120px;">
                    <div id="ewd-s-dur-hint" style="display:none;font-size:12px;color:var(--text-muted);margin-top:4px;">Set by the workout's structure. To change it, edit the reps in the Structure tab.</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="ewd-s-instr">Instructions <span style="font-weight:400;color:var(--text-muted);">(shown to athlete)</span></label>
                    <textarea id="ewd-s-instr" class="form-textarea" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="ewd-s-notes">Coach notes <span style="font-weight:400;color:var(--text-muted);">(coach-only)</span></label>
                    <textarea id="ewd-s-notes" class="form-textarea" rows="2"></textarea>
                </div>
                <div class="awd-actions"><button type="button" id="ewd-save-surface" class="btn btn-primary btn-sm">Save changes</button></div>
            </div>

            <!-- MODE 1b — structured field editor (5 uniform-rep archetypes) -->
            <div id="ewd-pane-structure" style="display:none;">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Edit the workout's structure. These push to the watch as real steps, and the description updates to match. You can override the usual rules.</div>
                <div id="ewd-st-fields"></div>
                <div class="awd-err" id="ewd-st-warn" style="display:none;background:var(--warning-fill);color:var(--color-warning);"></div>
                <div class="awd-actions"><button type="button" id="ewd-save-structured" class="btn btn-primary btn-sm">Save structure</button></div>
            </div>

            <!-- MODE 2 — archetype picker -->
            <div id="ewd-pane-archetype" style="display:none;">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Swap this workout for a different type from the library.</div>
                <div class="awd-filter" id="ewd-filter">
                    <button type="button" class="awd-filter-btn is-active" data-ecat="all">All</button>
                    <button type="button" class="awd-filter-btn" data-ecat="easy">Easy run</button>
                    <button type="button" class="awd-filter-btn" data-ecat="long">Long run</button>
                    <button type="button" class="awd-filter-btn" data-ecat="quality">Quality</button>
                    <button type="button" class="awd-filter-btn" data-ecat="recovery">Recovery</button>
                </div>
                <div class="awd-arch-list" id="ewd-arch-list"></div>
                <div id="ewd-config" style="display:none;margin-top:14px;">
                    <div class="form-group" id="ewd-variant-group" style="display:none;">
                        <label class="form-label" for="ewd-variant">Variant</label>
                        <select id="ewd-variant" class="form-select"></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ewd-duration">Duration (minutes)</label>
                        <input type="number" id="ewd-duration" class="form-input" min="1" max="600" style="max-width:120px;">
                    </div>
                    <button type="button" id="ewd-preview-btn" class="btn btn-secondary btn-sm">Preview</button>
                </div>
                <div id="ewd-preview-wrap" style="display:none;margin-top:14px;">
                    <div class="awd-preview">
                        <div class="awd-preview-title" id="ewd-preview-title"></div>
                        <div class="awd-preview-summary" id="ewd-preview-summary"></div>
                        <div class="awd-preview-instr" id="ewd-preview-instr"></div>
                    </div>
                    <div class="awd-actions"><button type="button" id="ewd-save-arch" class="btn btn-primary btn-sm">Replace workout</button></div>
                </div>
            </div>

            <!-- MODE 3 — free-form -->
            <div id="ewd-pane-freeform" style="display:none;">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Replace this with a custom workout you write yourself.</div>
                <div class="form-group">
                    <label class="form-label" for="ewd-ff-title">Title</label>
                    <input type="text" id="ewd-ff-title" class="form-input" maxlength="255">
                </div>
                <div class="form-group">
                    <label class="form-label" for="ewd-ff-type">Workout type</label>
                    <select id="ewd-ff-type" class="form-select">
                        <option value="easy">Easy run</option><option value="long">Long run</option>
                        <option value="tempo">Tempo</option><option value="interval">Workout</option>
                        <option value="recovery">Recovery</option><option value="race_pace">Race pace</option>
                        <option value="hill">Hill session</option><option value="fartlek">Fartlek</option>
                        <option value="speed">Speed</option><option value="rest">Rest</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="ewd-ff-dur">Duration (minutes)</label>
                    <input type="number" id="ewd-ff-dur" class="form-input" min="1" max="600" style="max-width:120px;">
                </div>
                <div class="form-group">
                    <label class="form-label" for="ewd-ff-instr">Instructions <span style="font-weight:400;color:var(--text-muted);">(shown to athlete)</span></label>
                    <textarea id="ewd-ff-instr" class="form-textarea" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="ewd-ff-notes">Coach notes <span style="font-weight:400;color:var(--text-muted);">(coach-only)</span></label>
                    <textarea id="ewd-ff-notes" class="form-textarea" rows="2"></textarea>
                </div>
                <div class="awd-actions"><button type="button" id="ewd-save-ff" class="btn btn-primary btn-sm">Replace workout</button></div>
            </div>

            <div class="awd-err" id="ewd-err"></div>
        </div>
    </div>

    <script>
    (function () {
        var CFG = {
            athleteId:  <?= (int)$athlete['id'] ?>,
            archetypes: <?= json_encode($archetypeLibrary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            isAssistant: <?= (Auth::role() === 'assistant_coach') ? 'true' : 'false' ?>,
            csrf:       <?= json_encode(Auth::csrfToken()) ?>
        };

        function $id(id) { return document.getElementById(id); }
        function fmtDur(m) {
            m = parseInt(m, 10);
            if (!m) return '–';
            if (m < 60) return m + ' min';
            var h = Math.floor(m / 60), r = m % 60;
            return r ? h + 'h ' + r + 'min' : h + 'h';
        }
        function dateLabel(d) {
            return new Date(d + 'T00:00:00').toLocaleDateString('en-US', {weekday:'long', month:'short', day:'numeric'});
        }
        function post(url, body) {
            return fetch(url, {
                method:  'POST',
                headers: {'Content-Type':'application/json', 'X-CSRF-Token':CFG.csrf, 'X-Requested-With':'fetch'},
                body:    JSON.stringify(body)
            }).then(function (r) { return r.json(); });
        }

        // ── Workout detail popout (view + remove) ──
        var mwdData = null;
        function setBlock(id, val) { $id(id).textContent = val || ''; $id(id).style.display = val ? '' : 'none'; }
        function openMwd(el) {
            var raw = el.getAttribute('data-mw'); if (!raw) return;
            var d; try { d = JSON.parse(raw); } catch (e) { return; }
            mwdData = d;
            $id('mwd-type').textContent = d.type_label || '';
            $id('mwd-type').className   = 'pill ' + (d.type_class || '');
            $id('mwd-date').textContent = d.date ? dateLabel(d.date) : '';
            $id('mwd-name').textContent = d.title || '';
            $id('mwd-dur').textContent  = fmtDur(d.target_duration);
            $id('mwd-dur-wrap').style.display = d.target_duration ? '' : 'none';
            setBlock('mwd-summary', d.summary);
            setBlock('mwd-desc', d.description);

            // Athlete-moved indicator + revert action (future, uncompleted workouts only).
            var showMoved = !!(d.athlete_moved && d.moved_from && d.editable);
            $id('mwd-moved-wrap').style.display = showMoved ? '' : 'none';
            if (showMoved) $id('mwd-moved-from').textContent = dateLabel(d.moved_from);

            // "Comment on session" appears only when this workout has a logged completion.
            var cwid  = d.completed_workout_id || 0;
            var cwrap = $id('mwd-comment-wrap');
            if (cwrap) {
                if (cwid) {
                    $id('mwd-comment-cwid').value   = cwid;
                    $id('mwd-comment-form').style.display = 'none';
                    $id('mwd-comment-btn').style.display  = '';
                    cwrap.style.display = '';
                } else {
                    cwrap.style.display = 'none';
                }
            }

            // Flag-for-review toggle: only meaningful for a real planned workout (has id).
            var flagBtn = $id('mwd-flag');
            if (flagBtn) {
                if (d.id) { flagBtn.style.display = ''; setFlagBtn(flagBtn, !!d.flagged); }
                else { flagBtn.style.display = 'none'; }
            }

            // Edit affordance: future + uncompleted workouts only.
            var editBtn = $id('mwd-edit');
            if (editBtn) editBtn.style.display = (d.id && d.editable) ? '' : 'none';

            $id('mwd').classList.add('is-open');
            document.body.style.overflow = 'hidden';
        }
        function closeMwd() { $id('mwd').classList.remove('is-open'); document.body.style.overflow = ''; mwdData = null; }

        // ── Flag for review (Coaching Intelligence Layer) ──
        function setFlagBtn(btn, on) {
            btn.classList.toggle('is-flagged', !!on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        }
        function toggleFlag() {
            if (!mwdData || !mwdData.id) return;
            var btn  = $id('mwd-flag');
            var next = !mwdData.flagged;
            post('/app/coach/workout/flag', {planned_workout_id: mwdData.id, flagged: next})
              .then(function (res) {
                  if (!res || !res.success) { alert((res && res.message) || 'Could not update the flag.'); return; }
                  mwdData.flagged = next ? 1 : 0;
                  setFlagBtn(btn, next);
                  // Keep the calendar button's data-mw in sync so reopening shows the right state.
                  var wbtn = document.querySelector('.macro-workout[data-workout-id="' + mwdData.id + '"]');
                  if (wbtn) { try { var dd = JSON.parse(wbtn.getAttribute('data-mw')); dd.flagged = next ? 1 : 0; wbtn.setAttribute('data-mw', JSON.stringify(dd)); } catch (e) {} }
              })
              .catch(function () { alert('Network error. Please try again.'); });
        }
        (function () { var fb = document.getElementById('mwd-flag'); if (fb) fb.addEventListener('click', toggleFlag); })();

        function removeWorkout() {
            if (!mwdData || !mwdData.id) return;
            if (!confirm('Remove this workout? The day will become a rest day. This cannot be undone.')) return;
            var id = mwdData.id;
            post('/app/coach/athlete/' + CFG.athleteId + '/workout/remove', {workout_id: id})
              .then(function (res) {
                  if (!res || !res.success) { alert((res && res.message) || 'Could not remove the workout.'); return; }
                  var btn = document.querySelector('.macro-workout[data-workout-id="' + id + '"]');
                  if (btn) { var cell = btn.closest('.macro-day'); btn.remove(); if (cell) renderEmpty(cell, true); }
                  closeMwd();
              })
              .catch(function () { alert('Network error. Please try again.'); });
        }

        function revertMove() {
            if (!mwdData || !mwdData.id) return;
            post('/app/coach/athlete/' + CFG.athleteId + '/workout/revert-move', {workout_id: mwdData.id})
              .then(function (res) {
                  if (res && res.success) { window.location.reload(); return; }
                  alert((res && res.message) ? res.message : 'Could not revert this workout.');
              })
              .catch(function () { alert('Network error. Please try again.'); });
        }

        // ── Edit-workout modal (surface / archetype / free-form) ──
        var editData = null, selectedEArch = null;
        function setSelect(id, val) { var s = $id(id); if (!s) return; for (var i = 0; i < s.options.length; i++) { if (s.options[i].value === val) { s.selectedIndex = i; return; } } }
        function showEErr(m) { var e = $id('ewd-err'); e.textContent = m; e.style.display = ''; }
        function hideEErr() { $id('ewd-err').style.display = 'none'; }
        function closeEwd() { $id('ewd').classList.remove('is-open'); document.body.style.overflow = ''; editData = null; }

        function openEwd() {
            if (!mwdData || !mwdData.id || !mwdData.editable) return;
            editData = mwdData;
            $id('ewd-date-label').textContent = editData.date ? dateLabel(editData.date) : '';
            $id('ewd-tab-freeform').style.display = CFG.isAssistant ? 'none' : '';
            // Prefill surface + free-form panes from the current workout.
            $id('ewd-s-title').value = editData.title || '';
            setSelect('ewd-s-type', editData.workout_type);
            $id('ewd-s-dur').value = editData.target_duration || '';
            $id('ewd-s-instr').value = editData.instructions || '';
            $id('ewd-s-notes').value = editData.coach_notes || '';
            // Generated (archetype-backed) workouts: type is implied by the archetype (change it via
            // "Replace from library") and duration is derived from the structure (change it via the
            // Structure tab). Hide the type picker and make duration read-only so a surface tweak can't
            // desync the badge/duration from the actual content. Free-form workouts (no archetype) keep
            // both editable — there, type and duration are the only source of truth.
            var ewdGenerated = !!(editData.archetype_code && editData.archetype_code !== '');
            $id('ewd-s-type-group').style.display = ewdGenerated ? 'none' : '';
            var ewdDur = $id('ewd-s-dur');
            ewdDur.readOnly = ewdGenerated;
            ewdDur.tabIndex = ewdGenerated ? -1 : 0;
            ewdDur.style.opacity = ewdGenerated ? '0.6' : '';
            $id('ewd-s-dur-hint').style.display = ewdGenerated ? '' : 'none';
            $id('ewd-ff-title').value = editData.title || '';
            setSelect('ewd-ff-type', editData.workout_type);
            $id('ewd-ff-dur').value = editData.target_duration || '';
            $id('ewd-ff-instr').value = editData.instructions || '';
            $id('ewd-ff-notes').value = editData.coach_notes || '';
            buildStructuredEditor();
            setEwdTab('surface');
            renderEArchList('all');
            hideEErr();
            closeMwd();
            $id('ewd').classList.add('is-open'); document.body.style.overflow = 'hidden';
        }
        function setEwdTab(tab) {
            document.querySelectorAll('#ewd .awd-tab').forEach(function (t) { t.classList.toggle('is-active', t.getAttribute('data-ewd-tab') === tab); });
            $id('ewd-pane-surface').style.display   = tab === 'surface'   ? '' : 'none';
            $id('ewd-pane-structure').style.display = tab === 'structure' ? '' : 'none';
            $id('ewd-pane-archetype').style.display = tab === 'archetype' ? '' : 'none';
            $id('ewd-pane-freeform').style.display  = tab === 'freeform'  ? '' : 'none';
            hideEErr();
        }
        function renderEArchList(cat) {
            document.querySelectorAll('#ewd-filter .awd-filter-btn').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-ecat') === cat); });
            var list = $id('ewd-arch-list'); list.innerHTML = '';
            CFG.archetypes.filter(function (a) { return cat === 'all' || a.category === cat; }).forEach(function (a) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'awd-arch'; b.setAttribute('data-earch-code', a.code);
                b.innerHTML = '<div class="awd-arch-name"></div><div class="awd-arch-desc"></div>';
                b.querySelector('.awd-arch-name').textContent = a.name;
                b.querySelector('.awd-arch-desc').textContent = a.description || '';
                list.appendChild(b);
            });
            $id('ewd-config').style.display = 'none';
            $id('ewd-preview-wrap').style.display = 'none';
            selectedEArch = null;
        }
        function selectEArch(code) {
            selectedEArch = CFG.archetypes.filter(function (a) { return a.code === code; })[0] || null;
            document.querySelectorAll('#ewd-arch-list .awd-arch').forEach(function (b) { b.classList.toggle('is-selected', b.getAttribute('data-earch-code') === code); });
            if (!selectedEArch) return;
            var vg = $id('ewd-variant-group'), vs = $id('ewd-variant');
            if (selectedEArch.variants && selectedEArch.variants.length > 1) {
                vs.innerHTML = '';
                selectedEArch.variants.forEach(function (v) { var o = document.createElement('option'); o.value = v.code; o.textContent = v.name; vs.appendChild(o); });
                vg.style.display = '';
            } else { vg.style.display = 'none'; vs.innerHTML = ''; }
            $id('ewd-duration').value = editData && editData.target_duration ? editData.target_duration : (selectedEArch.default_duration || 45);
            $id('ewd-config').style.display = '';
            $id('ewd-preview-wrap').style.display = 'none';
            hideEErr();
        }
        function chosenEVariant() {
            if ($id('ewd-variant-group').style.display !== 'none') return $id('ewd-variant').value;
            return (selectedEArch && selectedEArch.variants && selectedEArch.variants[0]) ? selectedEArch.variants[0].code : null;
        }
        function chosenEDuration() { return parseInt($id('ewd-duration').value, 10) || (selectedEArch && selectedEArch.default_duration) || 45; }
        function editUrl() { return '/app/coach/workouts/' + editData.id + '/edit'; }
        function previewEArch() {
            if (!selectedEArch) return;
            hideEErr();
            post(editUrl(), { mode: 'archetype', preview: true, archetype_code: selectedEArch.code, archetype_variant: chosenEVariant(), duration: chosenEDuration() })
              .then(function (res) {
                  if (!res || !res.ok) { showEErr((res && res.message) || 'Could not build preview.'); return; }
                  var p = res.preview;
                  $id('ewd-preview-title').textContent = p.display_title || '';
                  setBlock('ewd-preview-summary', p.display_summary);
                  $id('ewd-preview-instr').textContent = p.athlete_instructions || '';
                  $id('ewd-preview-wrap').style.display = '';
              }).catch(function () { showEErr('Network error. Please try again.'); });
        }
        function saveEdit(body, btn) {
            if (btn) btn.disabled = true;
            post(editUrl(), body)
              .then(function (res) { if (btn) btn.disabled = false; onEdited(res); })
              .catch(function () { if (btn) btn.disabled = false; showEErr('Network error. Please try again.'); });
        }
        function onEdited(res) {
            if (!res || !res.ok) { showEErr((res && res.message) || 'Could not save the workout.'); return; }
            applyEditResult(res.workout);
            closeEwd();
        }
        function applyEditResult(w) {
            if (!w) return;
            var btn = document.querySelector('.macro-workout[data-workout-id="' + w.id + '"]');
            if (btn) {
                var row  = btn.querySelector('.macro-workout-row');
                var pill = btn.querySelector('.pill');
                if (pill) { pill.className = 'pill ' + (w.type_class || ''); pill.textContent = w.type_label || ''; }
                var dur = btn.querySelector('.macro-duration');
                if (w.target_duration) {
                    if (!dur && pill) { dur = document.createElement('span'); dur.className = 'macro-duration'; pill.parentNode.insertBefore(dur, pill.nextSibling); }
                    if (dur) dur.textContent = w.duration_label || fmtDur(w.target_duration);
                } else if (dur) { dur.remove(); }
                if (row && !row.querySelector('.macro-lock')) {
                    var lk = document.createElement('span'); lk.className = 'macro-lock'; lk.title = 'Coach-locked'; lk.innerHTML = '&#128274;'; row.appendChild(lk);
                }
                try {
                    var d = JSON.parse(btn.getAttribute('data-mw'));
                    d.workout_type = w.workout_type; d.type_label = w.type_label; d.type_class = w.type_class;
                    d.title = w.title; d.target_duration = w.target_duration; d.summary = w.summary || ''; d.description = w.description || '';
                    d.coach_locked = 1; d.instructions = w.athlete_instructions || ''; d.coach_notes = w.coach_notes || '';
                    d.archetype_code = w.archetype_code || ''; d.archetype_variant = w.archetype_variant || '';
                    // After a structured edit, refresh the cached field values so reopening shows them.
                    if (pendingStructured && d.structured) { Object.keys(pendingStructured).forEach(function (k) { d.structured[k] = pendingStructured[k]; }); }
                    pendingStructured = null;
                    btn.setAttribute('data-mw', JSON.stringify(d));
                } catch (e) {}
            }
        }

        // ── Structured field editor (5 uniform-rep archetypes) ──
        var STRUCT_SPEC = {
            tempo_intervals: [
                {key:'rep_count', label:'Reps', type:'num', min:1, max:40},
                {key:'rep_duration_minutes', label:'Rep length (min)', type:'num', min:1, max:60},
                {key:'recovery_duration_seconds', label:'Recovery (sec)', type:'num', min:5, max:1200}
            ],
            equal_distance_repeats: [
                {key:'rep_count', label:'Reps', type:'num', min:1, max:40},
                {key:'rep_distance_meters', label:'Rep distance', type:'sel', options:[400,600,800,1000,1200,1600]},
                {key:'recovery_duration_seconds', label:'Recovery (sec)', type:'num', min:5, max:1200}
            ],
            short_speed_repeats: [
                {key:'rep_count', label:'Reps', type:'num', min:1, max:40},
                {key:'rep_distance_meters', label:'Rep distance', type:'sel', options:[60,80,100,150,200,300,400]},
                {key:'recovery_duration_seconds', label:'Recovery (sec)', type:'num', min:5, max:1200}
            ],
            sustained_hill_repeats: [
                {key:'rep_count', label:'Reps', type:'num', min:1, max:40},
                {key:'rep_duration_seconds', label:'Rep length (sec)', type:'num', min:10, max:1800},
                {key:'recovery_duration_seconds', label:'Recovery (sec)', type:'num', min:5, max:1200}
            ],
            high_volume_time_intervals: [
                {key:'rep_count', label:'Reps', type:'num', min:1, max:40},
                {key:'work_duration_seconds', label:'On (sec)', type:'num', min:10, max:1800},
                {key:'recovery_duration_seconds', label:'Off (sec)', type:'num', min:5, max:1200}
            ]
        };
        var pendingStructured = null;
        var MIXED_TRACK = [200, 300, 400, 600, 800, 1000, 1200, 1600];
        var mixedRungs = [];
        function buildStructuredEditor() {
            var tab = $id('ewd-tab-structure');
            var st = editData && editData.structured;
            if (!st) { tab.style.display = 'none'; return; }
            if (editData.archetype_code === 'mixed_distance_repeats') { tab.style.display = ''; buildMixedRungEditor(); return; }
            var spec = STRUCT_SPEC[editData.archetype_code];
            if (!spec) { tab.style.display = 'none'; return; }
            tab.style.display = '';
            var wrap = $id('ewd-st-fields'); wrap.innerHTML = '';
            spec.forEach(function (f) {
                var cur = editData.structured[f.key];
                var grp = document.createElement('div'); grp.className = 'form-group';
                var lab = document.createElement('label'); lab.className = 'form-label'; lab.setAttribute('for', 'ewd-st-' + f.key); lab.textContent = f.label;
                grp.appendChild(lab);
                var input;
                if (f.type === 'sel') {
                    input = document.createElement('select'); input.className = 'form-select'; input.style.maxWidth = '160px';
                    f.options.forEach(function (o) { var op = document.createElement('option'); op.value = o; op.textContent = o + ' m'; if (cur != null && parseInt(cur, 10) === o) op.selected = true; input.appendChild(op); });
                } else {
                    input = document.createElement('input'); input.type = 'number'; input.className = 'form-input'; input.style.maxWidth = '120px';
                    if (f.min != null) input.min = f.min; if (f.max != null) input.max = f.max;
                    if (cur != null) input.value = cur;
                }
                input.id = 'ewd-st-' + f.key; input.setAttribute('data-stkey', f.key);
                grp.appendChild(input); wrap.appendChild(grp);
            });
            $id('ewd-st-warn').style.display = 'none';
        }
        // ── mixed_distance_repeats rung editor (Phase 2: an ordered ladder) ──
        function buildMixedRungEditor() {
            mixedRungs = ((editData.structured && editData.structured.interval_distances) || []).slice();
            if (!mixedRungs.length) mixedRungs = [800];
            $id('ewd-st-fields').innerHTML =
                '<div class="form-label" style="margin-bottom:6px;">Ladder (top to bottom is the order run)</div>'
                + '<div id="ewd-rungs"></div>'
                + '<button type="button" id="ewd-rung-add" class="btn btn-secondary btn-sm" style="margin-top:6px;">+ Add rung</button>';
            renderMixedRungs();
            $id('ewd-st-warn').style.display = 'none';
        }
        function renderMixedRungs() {
            var box = $id('ewd-rungs'); if (!box) return;
            box.innerHTML = '';
            mixedRungs.forEach(function (m, i) {
                var row = document.createElement('div'); row.className = 'ewd-rung'; row.style.cssText = 'display:flex;align-items:center;gap:6px;margin-bottom:6px;';
                var num = document.createElement('span'); num.style.cssText = 'width:18px;color:var(--text-muted);font-size:12px;'; num.textContent = (i + 1) + '.';
                var sel = document.createElement('select'); sel.className = 'form-select'; sel.style.maxWidth = '120px'; sel.setAttribute('data-rung-idx', i);
                MIXED_TRACK.forEach(function (o) { var op = document.createElement('option'); op.value = o; op.textContent = o + ' m'; if (parseInt(m, 10) === o) op.selected = true; sel.appendChild(op); });
                row.appendChild(num); row.appendChild(sel);
                [['up', '↑'], ['down', '↓'], ['remove', '×']].forEach(function (a) {
                    var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-secondary btn-sm'; b.style.cssText = 'padding:2px 8px;';
                    b.setAttribute('data-rung-act', a[0]); b.textContent = a[1];
                    if (a[0] === 'up' && i === 0) b.disabled = true;
                    if (a[0] === 'down' && i === mixedRungs.length - 1) b.disabled = true;
                    if (a[0] === 'remove' && mixedRungs.length <= 1) b.disabled = true;
                    row.appendChild(b);
                });
                box.appendChild(row);
            });
        }
        function syncRungsFromDom() {
            var arr = [];
            document.querySelectorAll('#ewd-rungs select[data-rung-idx]').forEach(function (s) { arr.push(parseInt(s.value, 10) || 0); });
            if (arr.length) mixedRungs = arr;
        }
        function mixedRungAction(act, i) {
            syncRungsFromDom();
            if (act === 'remove') { if (mixedRungs.length > 1) mixedRungs.splice(i, 1); }
            else if (act === 'up' && i > 0) { var t = mixedRungs[i - 1]; mixedRungs[i - 1] = mixedRungs[i]; mixedRungs[i] = t; }
            else if (act === 'down' && i < mixedRungs.length - 1) { var u = mixedRungs[i + 1]; mixedRungs[i + 1] = mixedRungs[i]; mixedRungs[i] = u; }
            renderMixedRungs();
        }
        function mixedAddRung() { syncRungsFromDom(); mixedRungs.push(800); renderMixedRungs(); }

        function collectStructured() {
            if (editData && editData.archetype_code === 'mixed_distance_repeats') {
                syncRungsFromDom();
                return { interval_distances: mixedRungs.slice() };
            }
            var out = {};
            document.querySelectorAll('#ewd-st-fields [data-stkey]').forEach(function (el) {
                var v = parseInt(el.value, 10); if (!isNaN(v)) out[el.getAttribute('data-stkey')] = v;
            });
            return out;
        }
        function structuredWarnings(v) {
            var w = [];
            if (v.interval_distances) {
                var r = v.interval_distances;
                if (r.length < 2) w.push('A mixed ladder needs at least 2 rungs.');
                if (r.length > 12) w.push('A ladder of ' + r.length + ' rungs is unusual.');
                var tot = r.reduce(function (a, b) { return a + b; }, 0);
                if (tot > 12000) w.push('A total ladder distance of about ' + tot + ' m is very large.');
                return w;
            }
            if (v.rep_count != null && (v.rep_count < 1 || v.rep_count > 30)) w.push('Rep count of ' + v.rep_count + ' is unusual.');
            var durS = v.rep_duration_seconds != null ? v.rep_duration_seconds
                     : (v.rep_duration_minutes != null ? v.rep_duration_minutes * 60
                     : (v.work_duration_seconds != null ? v.work_duration_seconds : null));
            if (durS != null && (durS < 30 || durS > 1800)) w.push('A single rep of ' + (durS >= 60 ? Math.round(durS / 60) + ' min' : durS + ' sec') + ' is unusual.');
            if (v.rep_distance_meters != null && (v.rep_distance_meters < 100 || v.rep_distance_meters > 5000)) w.push('A rep distance of ' + v.rep_distance_meters + ' m is unusual.');
            var per = durS || (v.rep_distance_meters ? Math.round(v.rep_distance_meters / 1609.34 * 390) : 0);
            if ((v.rep_count || 0) > 0 && per > 0 && (v.rep_count * per) > 5400) w.push('Total work of about ' + Math.round(v.rep_count * per / 60) + ' min is very large.');
            return w;
        }
        function saveStructured(btn) {
            if (!editData || !editData.structured) return;
            hideEErr();
            var vals = collectStructured();
            var warns = structuredWarnings(vals);
            if (warns.length && !confirm('This is unusual:\n\n' + warns.join('\n') + '\n\nSave anyway?')) return;
            pendingStructured = vals;
            var body = {}; Object.keys(vals).forEach(function (k) { body[k] = vals[k]; }); body.mode = 'structured';
            saveEdit(body, btn);
        }

        // ── Calendar DOM helpers ──
        function cellForDate(date) { return document.querySelector('.macro-day[data-date="' + date + '"]'); }
        function bodyOf(cell) { return cell ? cell.querySelector('.macro-day-body') : null; }
        function hasWorkout(cell) { return cell && cell.querySelector('.macro-workout'); }
        function addBtnOf(cell) { var b = bodyOf(cell); return b ? b.querySelector('.macro-add-btn') : null; }

        function ensureAddBtn(cell) {
            var body = bodyOf(cell); if (!body) return;
            if (!body.querySelector('.macro-add-btn')) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'macro-add-btn';
                b.setAttribute('data-add-date', cell.getAttribute('data-date'));
                b.textContent = '+ Add workout';
                body.appendChild(b);
            }
        }
        // Render a cell as an empty/rest day (after a move-out or remove).
        function renderEmpty(cell, removed) {
            if (!cell || hasWorkout(cell)) return;
            cell.classList.add('macro-day-empty');
            var body = bodyOf(cell); if (!body) return;
            if (!body.querySelector('.macro-rest')) {
                var r = document.createElement('div'); r.className = 'macro-rest';
                r.style.cssText = 'font-size:12px;color:var(--text-muted);'; r.textContent = 'Rest';
                body.insertBefore(r, body.firstChild);
            }
            if (removed && !body.querySelector('.macro-removed-marker')) {
                var rm = document.createElement('div'); rm.className = 'macro-removed-marker';
                rm.title = 'A workout was removed from this day'; rm.textContent = 'Removed';
                var rest = body.querySelector('.macro-rest');
                body.insertBefore(rm, rest ? rest.nextSibling : body.firstChild);
            }
            ensureAddBtn(cell);
        }
        // Render a cell as occupied (after a move-in or add).
        function renderOccupied(cell) {
            if (!cell) return;
            cell.classList.remove('macro-day-empty');
            var body = bodyOf(cell); if (!body) return;
            ['.macro-rest', '.macro-add-btn', '.macro-removed-marker'].forEach(function (sel) {
                var el = body.querySelector(sel); if (el) el.remove();
            });
        }
        function setMwDate(btn, newDate) {
            try { var d = JSON.parse(btn.getAttribute('data-mw')); d.date = newDate; btn.setAttribute('data-mw', JSON.stringify(d)); } catch (e) {}
        }

        function buildWorkoutButton(w) {
            var btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'macro-workout'; btn.setAttribute('draggable', 'true');
            btn.setAttribute('data-workout-id', w.id);
            btn.setAttribute('data-mw', JSON.stringify({
                id: w.id, workout_type: w.workout_type, type_label: w.type_label, type_class: w.type_class,
                title: w.title, date: w.date, target_duration: w.target_duration,
                summary: w.summary || '', description: w.description || '', coach_locked: w.coach_locked ? 1 : 0
            }));
            var row = document.createElement('div'); row.className = 'macro-workout-row';
            var pill = document.createElement('span'); pill.className = 'pill ' + w.type_class; pill.textContent = w.type_label;
            row.appendChild(pill);
            if (w.target_duration) {
                var du = document.createElement('span'); du.className = 'macro-duration';
                du.textContent = w.duration_label || fmtDur(w.target_duration); row.appendChild(du);
            }
            if (w.coach_locked) {
                var lk = document.createElement('span'); lk.className = 'macro-lock'; lk.title = 'Coach-locked'; lk.innerHTML = '&#128274;';
                row.appendChild(lk);
            }
            btn.appendChild(row);
            return btn;
        }

        // ── Drag-to-reschedule ──
        var dragBtn = null, dragFromCell = null;
        document.addEventListener('dragstart', function (e) {
            var btn = e.target.closest('.macro-workout'); if (!btn) return;
            dragBtn = btn; dragFromCell = btn.closest('.macro-day');
            btn.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', btn.getAttribute('data-workout-id') || ''); } catch (err) {}
        });
        document.addEventListener('dragend', function () {
            if (dragBtn) dragBtn.classList.remove('is-dragging');
            document.querySelectorAll('.drop-target').forEach(function (c) { c.classList.remove('drop-target'); });
            dragBtn = null; dragFromCell = null;
        });
        document.addEventListener('dragover', function (e) {
            var cell = e.target.closest('.macro-day-drop'); if (!cell || !dragBtn) return;
            e.preventDefault(); e.dataTransfer.dropEffect = 'move';
            if (!cell.classList.contains('drop-target')) {
                document.querySelectorAll('.drop-target').forEach(function (c) { c.classList.remove('drop-target'); });
                cell.classList.add('drop-target');
            }
        });
        document.addEventListener('drop', function (e) {
            var cell = e.target.closest('.macro-day-drop'); if (!cell || !dragBtn) return;
            e.preventDefault();
            cell.classList.remove('drop-target');
            var newDate = cell.getAttribute('data-date');
            var fromCell = dragFromCell, btn = dragBtn;
            if (!newDate || cell === fromCell) return;
            doReschedule(parseInt(btn.getAttribute('data-workout-id'), 10), newDate, btn, fromCell, cell, false);
        });

        function doReschedule(id, newDate, btn, fromCell, toCell, force) {
            post('/app/coach/athlete/' + CFG.athleteId + '/workout/reschedule', {workout_id: id, new_date: newDate, force: force})
              .then(function (res) {
                  if (res && res.success) {
                      renderOccupied(toCell);
                      setMwDate(btn, newDate);
                      bodyOf(toCell).insertBefore(btn, addBtnOf(toCell));
                      renderEmpty(fromCell, false);
                      return;
                  }
                  if (res && res.error === 'must_off') {
                      if (confirm('This is a must-off day for this athlete. Schedule anyway?')) {
                          doReschedule(id, newDate, btn, fromCell, toCell, true);
                      }
                      return;
                  }
                  if (res && res.error === 'soft_warning') {
                      if (confirm((res.message || 'This move creates a scheduling concern.') + ' Move it anyway?')) {
                          doReschedule(id, newDate, btn, fromCell, toCell, true);
                      }
                      return;
                  }
                  if (res && res.error === 'conflict' && res.existing_workout) {
                      var ex = res.existing_workout;
                      var label = ex.display_title || (ex.workout_type ? ex.workout_type.replace('_', ' ') : 'a workout');
                      if (confirm('This day already has ' + label + '. Swap the two workouts?')) {
                          doSwap(id, newDate, ex.id, btn, fromCell, toCell);
                      }
                      return;
                  }
                  alert((res && res.message) || 'Could not reschedule. Try again.');
              })
              .catch(function () { alert('Network error. Please try again.'); });
        }

        function doSwap(id, newDate, otherId, btn, fromCell, toCell) {
            post('/app/coach/athlete/' + CFG.athleteId + '/workout/reschedule', {workout_id: id, new_date: newDate, swap_with: otherId})
              .then(function (res) {
                  if (!res || !res.success) { alert((res && res.message) || 'Could not swap. Try again.'); return; }
                  var otherBtn = document.querySelector('.macro-workout[data-workout-id="' + otherId + '"]');
                  var oldDate  = fromCell.getAttribute('data-date');
                  renderOccupied(toCell); renderOccupied(fromCell);
                  if (otherBtn) { bodyOf(fromCell).insertBefore(otherBtn, addBtnOf(fromCell)); setMwDate(otherBtn, oldDate); }
                  bodyOf(toCell).insertBefore(btn, addBtnOf(toCell)); setMwDate(btn, newDate);
              })
              .catch(function () { alert('Network error. Please try again.'); });
        }

        // ── Add-workout modal ──
        var addDate = null, selectedArch = null;
        function openAwd(date) {
            addDate = date; selectedArch = null;
            $id('awd-date-label').textContent = dateLabel(date);
            setAwdTab('archetype');
            renderArchList('all');
            $id('awd-ff-title').value = ''; $id('awd-ff-dur').value = '';
            $id('awd-ff-instr').value = ''; $id('awd-ff-notes').value = ''; $id('awd-ff-type').value = 'easy';
            hideErr();
            $id('awd').classList.add('is-open'); document.body.style.overflow = 'hidden';
        }
        function closeAwd() { $id('awd').classList.remove('is-open'); document.body.style.overflow = ''; }
        function setAwdTab(tab) {
            document.querySelectorAll('.awd-tab').forEach(function (t) { t.classList.toggle('is-active', t.getAttribute('data-awd-tab') === tab); });
            $id('awd-pane-archetype').style.display = tab === 'archetype' ? '' : 'none';
            $id('awd-pane-freeform').style.display  = tab === 'freeform'  ? '' : 'none';
            hideErr();
        }
        function renderArchList(cat) {
            document.querySelectorAll('.awd-filter-btn').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-cat') === cat); });
            var list = $id('awd-arch-list'); list.innerHTML = '';
            CFG.archetypes.filter(function (a) { return cat === 'all' || a.category === cat; }).forEach(function (a) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'awd-arch'; b.setAttribute('data-arch-code', a.code);
                b.innerHTML = '<div class="awd-arch-name"></div><div class="awd-arch-desc"></div>';
                b.querySelector('.awd-arch-name').textContent = a.name;
                b.querySelector('.awd-arch-desc').textContent = a.description || '';
                list.appendChild(b);
            });
            $id('awd-config').style.display = 'none';
            $id('awd-preview-wrap').style.display = 'none';
            selectedArch = null;
        }
        function archByCode(code) {
            var m = CFG.archetypes.filter(function (a) { return a.code === code; });
            return m.length ? m[0] : null;
        }
        function selectArch(code) {
            selectedArch = archByCode(code);
            document.querySelectorAll('.awd-arch').forEach(function (b) { b.classList.toggle('is-selected', b.getAttribute('data-arch-code') === code); });
            if (!selectedArch) return;
            var vg = $id('awd-variant-group'), vs = $id('awd-variant');
            if (selectedArch.variants && selectedArch.variants.length > 1) {
                vs.innerHTML = '';
                selectedArch.variants.forEach(function (v) { var o = document.createElement('option'); o.value = v.code; o.textContent = v.name; vs.appendChild(o); });
                vg.style.display = '';
            } else { vg.style.display = 'none'; vs.innerHTML = ''; }
            $id('awd-duration').value = selectedArch.default_duration || 45;
            $id('awd-config').style.display = '';
            $id('awd-preview-wrap').style.display = 'none';
            hideErr();
        }
        function chosenVariant() {
            if ($id('awd-variant-group').style.display !== 'none') return $id('awd-variant').value;
            return (selectedArch && selectedArch.variants && selectedArch.variants[0]) ? selectedArch.variants[0].code : null;
        }
        function chosenDuration() {
            return parseInt($id('awd-duration').value, 10) || (selectedArch && selectedArch.default_duration) || 45;
        }
        function previewArch() {
            if (!selectedArch) return;
            hideErr();
            post('/app/coach/athlete/' + CFG.athleteId + '/workout/add', {
                type: 'archetype', preview: true, scheduled_date: addDate,
                archetype_code: selectedArch.code, archetype_variant: chosenVariant(), duration: chosenDuration()
            }).then(function (res) {
                if (!res || !res.success) { showErr((res && res.message) || 'Could not build preview.'); return; }
                var p = res.preview;
                $id('awd-preview-title').textContent = p.display_title || '';
                setBlock('awd-preview-summary', p.display_summary);
                $id('awd-preview-instr').textContent = p.athlete_instructions || '';
                $id('awd-preview-wrap').style.display = '';
            }).catch(function () { showErr('Network error. Please try again.'); });
        }
        function addArch() {
            if (!selectedArch) return;
            var btn = $id('awd-add-arch'); btn.disabled = true;
            post('/app/coach/athlete/' + CFG.athleteId + '/workout/add', {
                type: 'archetype', scheduled_date: addDate,
                archetype_code: selectedArch.code, archetype_variant: chosenVariant(), duration: chosenDuration()
            }).then(function (res) { btn.disabled = false; onAdded(res); })
              .catch(function () { btn.disabled = false; showErr('Network error. Please try again.'); });
        }
        function addFreeform() {
            var title = $id('awd-ff-title').value.trim();
            var dur   = parseInt($id('awd-ff-dur').value, 10) || 0;
            if (!title || dur < 1) { showErr('Title and a duration of at least 1 minute are required.'); return; }
            var btn = $id('awd-add-ff'); btn.disabled = true;
            post('/app/coach/athlete/' + CFG.athleteId + '/workout/add', {
                type: 'freeform', scheduled_date: addDate, title: title,
                workout_type: $id('awd-ff-type').value, duration: dur,
                instructions: $id('awd-ff-instr').value.trim(), coach_notes: $id('awd-ff-notes').value.trim()
            }).then(function (res) { btn.disabled = false; onAdded(res); })
              .catch(function () { btn.disabled = false; showErr('Network error. Please try again.'); });
        }
        function onAdded(res) {
            if (!res || !res.success) { showErr((res && res.message) || 'Could not add the workout.'); return; }
            var cell = cellForDate(addDate);
            if (cell) { renderOccupied(cell); bodyOf(cell).appendChild(buildWorkoutButton(res.workout)); }
            closeAwd();
        }
        function showErr(m) { var e = $id('awd-err'); e.textContent = m; e.style.display = ''; }
        function hideErr() { $id('awd-err').style.display = 'none'; }

        // ── Event wiring ──
        document.addEventListener('click', function (e) {
            var wbtn = e.target.closest('.macro-workout');
            if (wbtn) { openMwd(wbtn); return; }
            if (e.target.id === 'mwd-bd' || e.target.id === 'mwd-close') { closeMwd(); return; }
            if (e.target.id === 'mwd-remove') { removeWorkout(); return; }
            if (e.target.id === 'mwd-revert') { revertMove(); return; }
            if (e.target.id === 'mwd-edit') { openEwd(); return; }
            if (e.target.id === 'mwd-comment-btn') {
                $id('mwd-comment-btn').style.display  = 'none';
                $id('mwd-comment-form').style.display = '';
                var cta = $id('mwd-comment-form').querySelector('textarea'); if (cta) cta.focus();
                return;
            }
            if (e.target.id === 'mwd-comment-cancel') {
                $id('mwd-comment-form').style.display = 'none';
                $id('mwd-comment-btn').style.display  = '';
                return;
            }

            var addBtn = e.target.closest('.macro-add-btn');
            if (addBtn) { openAwd(addBtn.getAttribute('data-add-date')); return; }

            if (e.target.id === 'awd-bd' || e.target.id === 'awd-close') { closeAwd(); return; }
            var tab = e.target.closest('#awd .awd-tab'); if (tab) { setAwdTab(tab.getAttribute('data-awd-tab')); return; }
            var fb = e.target.closest('#awd .awd-filter-btn'); if (fb) { renderArchList(fb.getAttribute('data-cat')); return; }
            var arch = e.target.closest('#awd .awd-arch'); if (arch) { selectArch(arch.getAttribute('data-arch-code')); return; }
            if (e.target.id === 'awd-preview-btn') { previewArch(); return; }
            if (e.target.id === 'awd-add-arch') { addArch(); return; }
            if (e.target.id === 'awd-add-ff') { addFreeform(); return; }

            // Edit-workout modal.
            if (e.target.id === 'ewd-bd' || e.target.id === 'ewd-close') { closeEwd(); return; }
            var etab = e.target.closest('#ewd .awd-tab'); if (etab) { setEwdTab(etab.getAttribute('data-ewd-tab')); return; }
            var efb = e.target.closest('#ewd-filter .awd-filter-btn'); if (efb) { renderEArchList(efb.getAttribute('data-ecat')); return; }
            var earch = e.target.closest('#ewd-arch-list .awd-arch'); if (earch) { selectEArch(earch.getAttribute('data-earch-code')); return; }
            if (e.target.id === 'ewd-preview-btn') { previewEArch(); return; }
            if (e.target.id === 'ewd-save-arch') {
                if (!selectedEArch) { showEErr('Choose a workout from the library first.'); return; }
                saveEdit({ mode: 'archetype', archetype_code: selectedEArch.code, archetype_variant: chosenEVariant(), duration: chosenEDuration() }, e.target);
                return;
            }
            if (e.target.id === 'ewd-save-surface') {
                saveEdit({
                    mode: 'surface', workout_type: $id('ewd-s-type').value,
                    target_duration: parseInt($id('ewd-s-dur').value, 10) || 0,
                    title: $id('ewd-s-title').value.trim(),
                    athlete_instructions: $id('ewd-s-instr').value.trim(),
                    coach_notes: $id('ewd-s-notes').value.trim()
                }, e.target);
                return;
            }
            if (e.target.id === 'ewd-save-ff') {
                var ft = $id('ewd-ff-title').value.trim(), fd = parseInt($id('ewd-ff-dur').value, 10) || 0;
                if (!ft || fd < 1) { showEErr('Title and a duration of at least 1 minute are required.'); return; }
                saveEdit({
                    mode: 'freeform', title: ft, workout_type: $id('ewd-ff-type').value, duration: fd,
                    instructions: $id('ewd-ff-instr').value.trim(), coach_notes: $id('ewd-ff-notes').value.trim()
                }, e.target);
                return;
            }
            var rungBtn = e.target.closest('#ewd-rungs [data-rung-act]');
            if (rungBtn) { var rr = rungBtn.closest('.ewd-rung'); mixedRungAction(rungBtn.getAttribute('data-rung-act'), Array.prototype.indexOf.call(rr.parentNode.children, rr)); return; }
            if (e.target.id === 'ewd-rung-add') { mixedAddRung(); return; }
            if (e.target.id === 'ewd-save-structured') { saveStructured(e.target); return; }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if ($id('mwd').classList.contains('is-open')) closeMwd();
            if ($id('awd').classList.contains('is-open')) closeAwd();
            if ($id('ewd').classList.contains('is-open')) closeEwd();
        });
    })();
    </script>

    <!-- Race management (§26) -->
    <style>
    .macro-race-pill { display:block; width:100%; margin:0 0 6px; padding:5px 9px; border-radius:10px;
        background:var(--color-danger); color:#fff; border:none; font:inherit; font-size:11px; font-weight:600;
        text-align:left; cursor:pointer; }
    .macro-conflict-yellow { box-shadow:0 0 0 2px var(--color-warning) inset; border-radius:6px; }
    .macro-conflict-red    { box-shadow:0 0 0 2px var(--color-danger) inset; border-radius:6px; }
    .srf-race-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1000;
        display:flex; align-items:center; justify-content:center; padding:16px; }
    .srf-race-overlay[hidden] { display:none; }
    .srf-race-sheet { background:var(--card-bg); color:var(--text-primary);
        width:100%; max-width:460px; border-radius:16px; padding:18px; max-height:88vh; overflow-y:auto;
        box-shadow:0 8px 32px rgba(0,0,0,0.25); }
    .srf-race-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .srf-race-title { font-size:16px; font-weight:600; }
    .srf-race-close { background:none; border:none; font-size:26px; line-height:1; cursor:pointer; color:var(--text-muted); }
    .srf-race-conflict { background:var(--warning-fill); color:var(--color-warning); border-radius:8px; padding:8px 10px; font-size:12px; margin-top:8px; }
    </style>

    <!-- Coach add-race modal -->
    <div data-coach-race-modal class="srf-race-overlay" hidden>
        <div class="srf-race-sheet" role="dialog" aria-modal="true" aria-label="Add a race">
            <div class="srf-race-head">
                <div class="srf-race-title">Add a race</div>
                <button type="button" class="srf-race-close" data-coach-race-close aria-label="Close">&times;</button>
            </div>
            <form method="POST" action="/app/coach/athlete/<?= (int)$athlete['id'] ?>/race/add">
                <?= Auth::csrfField() ?>
                <div class="form-group">
                    <label class="form-label" for="cr_name">Race name</label>
                    <input type="text" id="cr_name" name="race_name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Distance</label>
                    <div class="pill-choices">
                        <?php foreach ([
                            '5K'=>'5K','10K'=>'10K','15K'=>'15K','half'=>'Half Marathon','marathon'=>'Marathon',
                            '50k'=>'50K','50_miler'=>'50 Miler','100k'=>'100K','100_miler'=>'100 Miler','other'=>'Other',
                        ] as $val => $label): ?>
                        <label class="pill-choice">
                            <input type="radio" name="race_distance" value="<?= h($val) ?>" data-cr-dist required>
                            <?= h($label) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group" data-cr-custom style="display:none;">
                    <label class="form-label" for="cr_custom">Distance</label>
                    <div style="display:flex;gap:8px;">
                        <input type="number" step="0.1" min="0" id="cr_custom" name="custom_distance" class="form-input" style="flex:1;">
                        <select name="custom_distance_unit" class="form-select" style="max-width:110px;">
                            <option value="miles">miles</option>
                            <option value="km">km</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="cr_date">Date</label>
                    <input type="date" id="cr_date" name="race_date" class="form-input" required
                           min="<?= date('Y-m-d', strtotime('+1 day')) ?>" data-cr-date>
                </div>
                <div data-cr-conflicts></div>
                <div class="form-group">
                    <label class="toggle-wrap" style="cursor:pointer;">
                        <span>Is this the goal race?</span>
                        <input type="checkbox" name="is_goal_race" value="1" style="width:18px;height:18px;">
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label" for="cr_notes">Internal notes (coach only)</label>
                    <textarea id="cr_notes" name="coach_notes" class="form-input" rows="2"
                              placeholder="Not shown to the athlete."></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Save race</button>
            </form>
        </div>
    </div>

    <!-- Coach race detail modal -->
    <div data-coach-race-detail class="srf-race-overlay" hidden>
        <div class="srf-race-sheet" role="dialog" aria-modal="true" aria-label="Race detail">
            <div class="srf-race-head">
                <div class="srf-race-title" data-crd-title>Race</div>
                <button type="button" class="srf-race-close" data-coach-race-close aria-label="Close">&times;</button>
            </div>
            <p class="body-text" data-crd-meta style="margin-bottom:8px;"></p>
            <p class="body-text" data-crd-result style="margin:0 0 8px;display:none;"></p>
            <p class="body-text" data-crd-notes style="margin:0;color:var(--text-muted);font-size:13px;display:none;"></p>
        </div>
    </div>

    <script>
    (function () {
        var addModal = document.querySelector('[data-coach-race-modal]');
        var detModal = document.querySelector('[data-coach-race-detail]');
        var athleteId = <?= (int)$athlete['id'] ?>;

        function open(m){ if(m){ m.hidden=false; document.body.style.overflow='hidden'; } }
        function close(m){ if(m){ m.hidden=true; document.body.style.overflow=''; } }

        document.querySelectorAll('[data-coach-race-add]').forEach(function(b){
            b.addEventListener('click', function(){ open(addModal); });
        });
        document.querySelectorAll('[data-coach-race-close]').forEach(function(b){
            b.addEventListener('click', function(){ close(addModal); close(detModal); });
        });
        [addModal, detModal].forEach(function(m){
            if(!m) return; m.addEventListener('click', function(e){ if(e.target===m) close(m); });
        });
        document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ close(addModal); close(detModal); } });

        // Custom-distance toggle.
        var customBox = addModal ? addModal.querySelector('[data-cr-custom]') : null;
        document.querySelectorAll('[data-cr-dist]').forEach(function(r){
            r.addEventListener('change', function(){
                if(customBox) customBox.style.display = (r.checked && r.value==='other') ? '' : 'none';
            });
        });

        // Inline conflict warnings on date change (warn only).
        var dateInput = addModal ? addModal.querySelector('[data-cr-date]') : null;
        var conflictBox = addModal ? addModal.querySelector('[data-cr-conflicts]') : null;
        if (dateInput && conflictBox) {
            dateInput.addEventListener('change', function(){
                conflictBox.innerHTML = '';
                var d = dateInput.value;
                if(!d) return;
                fetch('/app/coach/athlete/' + athleteId + '/race-conflicts?date=' + encodeURIComponent(d))
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        (res.conflicts || []).forEach(function(c){
                            var div = document.createElement('div');
                            div.className = 'srf-race-conflict';
                            div.textContent = c;
                            conflictBox.appendChild(div);
                        });
                    }).catch(function(){});
            });
        }

        // Detail modal population.
        document.querySelectorAll('[data-coach-race]').forEach(function(p){
            p.addEventListener('click', function(){
                if(!detModal) return;
                var goal = p.getAttribute('data-race-goal')==='1';
                detModal.querySelector('[data-crd-title]').textContent =
                    (goal?'GOAL · ':'') + p.getAttribute('data-race-distance');
                detModal.querySelector('[data-crd-meta]').textContent =
                    p.getAttribute('data-race-name') + ' · ' + p.getAttribute('data-race-date');
                var resEl = detModal.querySelector('[data-crd-result]');
                var notesEl = detModal.querySelector('[data-crd-notes]');
                var result = p.getAttribute('data-race-result');
                var notes = p.getAttribute('data-race-notes');
                if(result){ resEl.textContent = 'Result: ' + result; resEl.style.display=''; }
                else { resEl.style.display='none'; }
                if(notes){ notesEl.textContent = 'Notes: ' + notes; notesEl.style.display=''; }
                else { notesEl.style.display='none'; }
                open(detModal);
            });
        });
    })();
    </script>
</div>

<?php
/**
 * Weekly training calendar partial — Garmin Connect-style bubble view.
 *
 * Caller sets in scope before including:
 *   $calWorkouts  — flat array of workout rows (planned_workouts or completed_workouts)
 *   $calMode      — 'preview' (uses target_duration) | 'log' (uses actual_duration)
 *
 * Each row needs: scheduled_date (or activity_date), workout_type,
 *                 target_duration / actual_duration, template_name (optional).
 */

$_durKey = ($calMode === 'log') ? 'actual_duration' : 'target_duration';

// Group by ISO week (Mon=1…Sun=7), slot by day-of-week
$_weekMap = [];
foreach ($calWorkouts as $_w) {
    $_ds  = $_w['scheduled_date'] ?? $_w['activity_date'] ?? null;
    if (!$_ds) continue;
    $_ts  = strtotime($_ds);
    $_dow = (int)date('N', $_ts);                          // 1=Mon … 7=Sun
    $_mon = date('Y-m-d', $_ts - ($_dow - 1) * 86400);    // Monday of week
    $_weekMap[$_mon][$_dow] = $_w;
}
ksort($_weekMap);

// Bubble colours — match pill values in app.css exactly
$_bubbleColor = [
    'easy'        => ['#E1F5EE', '#085041'],
    'long'        => ['#0F6E56', '#FFFFFF'],
    'interval'    => ['#042C53', '#FFFFFF'],
    'tempo'       => ['#185FA5', '#FFFFFF'],
    'hill'        => ['#3B6D11', '#FFFFFF'],
    'fartlek'     => ['#639922', '#FFFFFF'],
    'recovery'    => ['#D3D1C7', '#444441'],
    'race'        => ['#993C1D', '#FFFFFF'],
    'cross_train' => ['#4A3B8A', '#FFFFFF'],
];

// Compact label for inside the bubble
$_bubLabel = function(int $m): string {
    if ($m <= 0) return '';
    if ($m < 60) return (string)$m;
    $h = intdiv($m, 60); $r = $m % 60;
    return $r ? "{$h}h{$r}" : "{$h}h";
};

// Bubble diameter in px (desktop). Uses CSS min() to shrink in narrow columns.
$_bubSize = function(int $m): int {
    if ($m <= 0) return 0;
    return max(30, min(52, (int)round(28 + ($m / 90.0) * 24)));
};
?>
<?php if (!defined('_CAL_WEEK_STYLES')): define('_CAL_WEEK_STYLES', true); ?>
<style>
.cal-outer     { overflow-x: hidden; }
.cal-hdr-row,
.cal-data-row  { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
.cal-hdr-row   { margin-bottom: 6px; }
.cal-wk-lbl    { width: 72px; flex-shrink: 0; font-size: 10px;
                 color: var(--text-muted); line-height: 1.4; }
.cal-days-grid { display: grid; grid-template-columns: repeat(7,1fr);
                 gap: 4px; flex: 1; min-width: 0; }
.cal-day-col   { display: flex; flex-direction: column; align-items: center; gap: 3px; min-width: 0; }
.cal-day-hdr   { font-size: 9px; font-weight: 600; letter-spacing: .05em;
                 color: var(--text-muted); text-transform: uppercase; }
.cal-bubble    { border-radius: 50%; display: flex; align-items: center;
                 justify-content: center; flex-shrink: 0; position: relative;
                 transition: transform .12s; }
.cal-bubble:hover { transform: scale(1.1); }
.cal-bub-lbl   { font-size: 10px; font-weight: 700; line-height: 1;
                 user-select: none; pointer-events: none; }
.cal-rest-dot  { width: 8px; height: 8px; border-radius: 50%;
                 background: var(--border-strong); flex-shrink: 0; }
.cal-vol-lbl   { width: 52px; flex-shrink: 0; text-align: right; font-size: 11px;
                 font-weight: 500; color: var(--text-secondary); white-space: nowrap; }
@media (max-width: 600px) {
    .cal-wk-lbl  { width: 40px; font-size: 9px; }
    .cal-vol-lbl { width: 38px; font-size: 10px; }
    .cal-bub-lbl { font-size: 9px; }
    .cal-day-hdr { font-size: 8px; }
}
</style>
<?php endif; ?>

<div class="cal-outer">

    <!-- Column headers -->
    <div class="cal-hdr-row">
        <div class="cal-wk-lbl"></div>
        <div class="cal-days-grid">
            <?php foreach (['M','T','W','T','F','S','S'] as $_lbl): ?>
            <div class="cal-day-col">
                <span class="cal-day-hdr"><?= $_lbl ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="cal-vol-lbl"></div>
    </div>

    <!-- Week rows -->
    <?php $_wn = 0; foreach ($_weekMap as $_monDate => $_slots): $_wn++; ?>
    <?php
        $_weekTotal = 0;
        foreach ($_slots as $_slot) {
            $_weekTotal += max(0, (int)($_slot[$_durKey] ?? 0));
        }
        $_weekLabel = 'Wk ' . $_wn . "\n" . date('M j', strtotime($_monDate));
    ?>
    <div class="cal-data-row">

        <div class="cal-wk-lbl" style="white-space:pre-line;"><?= h($_weekLabel) ?></div>

        <div class="cal-days-grid">
            <?php for ($_iso = 1; $_iso <= 7; $_iso++):
                $_slot = $_slots[$_iso] ?? null;
                $_dur  = $_slot ? max(0, (int)($_slot[$_durKey] ?? 0)) : 0;
                $_type = $_slot['workout_type'] ?? null;
                $_sz   = $_bubSize($_dur);
                $_clr  = $_type ? ($_bubbleColor[$_type] ?? ['var(--recessed-bg)', 'var(--text-secondary)']) : null;
                $_name = $_slot ? ($_slot['template_name'] ?? ucfirst(str_replace('_', ' ', $_type ?? ''))) : '';
                $_title = $_name ? h($_name . ' · ' . ($_dur > 0 ? $_dur . ' min' : '')) : '';
            ?>
            <div class="cal-day-col">
                <?php if ($_slot && $_sz > 0): ?>
                <div class="cal-bubble"
                     style="width:min(<?= $_sz ?>px,100%);aspect-ratio:1;
                            background:<?= $_clr[0] ?>;color:<?= $_clr[1] ?>;"
                     title="<?= $_title ?>">
                    <span class="cal-bub-lbl"><?= $_bubLabel($_dur) ?></span>
                </div>
                <?php else: ?>
                <div class="cal-rest-dot" title="Rest"></div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>

        <div class="cal-vol-lbl">
            <?= $_weekTotal > 0 ? format_duration($_weekTotal) : '' ?>
        </div>

    </div>
    <?php endforeach; ?>

</div>

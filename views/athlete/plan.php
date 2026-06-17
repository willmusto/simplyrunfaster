<?php
// $workouts = array of planned_workouts for rolling 10-day window
$tz       = $athlete['timezone'] ?? Auth::timezone();
$today    = Timezone::dateInZone($tz, 'now');
$dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
?>
<div class="page-content">

    <div class="page-heading" style="margin-bottom:4px;">Your Plan</div>
    <p class="body-text" style="margin-bottom:20px;">
        The next <?= ATHLETE_WINDOW_DAYS ?> days of training.
    </p>

    <?php if (empty($workouts)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">📋</div>
        <div class="empty-state-title">No workouts in your window yet</div>
        <p class="body-text">Your coach is reviewing your plan. Check back soon.</p>
    </div>

    <?php else: ?>

    <?php
    // Group by date
    $byDate = [];
    foreach ($workouts as $w) {
        $byDate[$w['scheduled_date']][] = $w;
    }

    for ($i = 0; $i < ATHLETE_WINDOW_DAYS; $i++):
        $date     = Timezone::dateInZone($tz, "+$i days");
        $dow      = (int)date('w', strtotime($date));
        $isToday  = $date === $today;
        $dayWkts  = $byDate[$date] ?? [];
    ?>
    <div style="margin-bottom:12px;">
        <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:6px;">
            <span style="font-size:13px;font-weight:600;color:<?= $isToday ? 'var(--accent-mid)' : 'var(--text-secondary)' ?>;">
                <?= $isToday ? 'Today' : $dayNames[$dow] ?>
            </span>
            <span style="font-size:11px;color:var(--text-muted);">
                <?= date('M j', strtotime($date)) ?>
            </span>
        </div>

        <?php if (empty($dayWkts)): ?>
        <div class="card" style="color:var(--text-muted);font-size:13px;">
            No workout scheduled
        </div>
        <?php else: ?>
        <?php foreach ($dayWkts as $w): ?>
        <div class="card <?= $isToday ? 'card-next-up' : '' ?>">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                <span class="pill <?= pill_class($w['workout_type']) ?>">
                    <?= pill_label($w['workout_type']) ?>
                </span>
                <?php if ($w['target_duration']): ?>
                <span style="font-size:12px;color:var(--text-muted);">
                    <?= format_duration((int)$w['target_duration']) ?>
                </span>
                <?php endif; ?>
                <?php if ($w['coach_locked']): ?>
                <span title="Coach-locked" style="font-size:16px;">🔒</span>
                <?php endif; ?>
                <?php if ($w['athlete_moved']): ?>
                <span class="pill" style="background:var(--recessed-bg);color:var(--text-muted);font-size:10px;">
                    moved from <?= date('M j', strtotime($w['original_scheduled_date'])) ?>
                </span>
                <?php endif; ?>
            </div>

            <?php if ($w['description']): ?>
            <p class="body-text"><?= nl2br(h($w['description'])) ?></p>
            <?php endif; ?>

            <?php if ($w['target_pace_min'] && $w['target_pace_max']): ?>
            <div style="margin-top:8px;font-size:12px;color:var(--text-muted);">
                Target pace: <?= format_pace($w['target_pace_min']) ?> – <?= format_pace($w['target_pace_max']) ?>
            </div>
            <?php endif; ?>

            <?php if ($w['pushed_to_watch']): ?>
            <div class="watch-badge" style="margin-top:8px;display:inline-flex;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="5" y="2" width="14" height="20" rx="3"/>
                </svg>
                On your watch
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php endfor; ?>
    <?php endif; ?>
</div>

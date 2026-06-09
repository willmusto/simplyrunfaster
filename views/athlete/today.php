<?php
// Variables from AthleteController::today():
//  $athlete, $profile, $plan, $todayWorkout, $weekWorkouts, $loadData, $unreadMessages
$userName = h(explode(' ', $athlete['name'])[0]);
$today    = date('Y-m-d');
?>
<div class="page-content">

    <!-- Greeting + context -->
    <div style="margin-bottom:16px;">
        <div class="page-heading">Good <?= date('G') < 12 ? 'morning' : (date('G') < 17 ? 'afternoon' : 'evening') ?>, <?= $userName ?>.</div>
        <?php if ($plan): ?>
        <p class="body-text" style="margin-top:4px;">
            Week <?= max(1, (int)ceil((strtotime($today) - strtotime($plan['plan_start_date'])) / 604800)) ?>
            <?php if ($plan['plan_end_date']): ?>
            of <?= (int)ceil((strtotime($plan['plan_end_date']) - strtotime($plan['plan_start_date'])) / 604800) ?>
            <?php endif; ?>
            · <?= h(ucfirst($plan['plan_type'] ?? 'Training')) ?>
        </p>
        <?php else: ?>
        <p class="body-text" style="margin-top:4px;color:var(--text-muted);">
            Your coach is building your plan. It will appear here once approved.
        </p>
        <?php endif; ?>
    </div>

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
            <span class="pill <?= pill_class($todayWorkout['workout_type']) ?>">
                <?= pill_label($todayWorkout['workout_type']) ?>
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

    <!-- THIS WEEK section -->
    <?php if (!empty($weekWorkouts)): ?>
    <div class="section-label" style="margin-top:24px;">THIS WEEK</div>
    <div class="card" style="padding:0 0;">
        <?php
        $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        foreach ($weekWorkouts as $w):
            $isToday = $w['scheduled_date'] === $today;
            $dow     = (int)date('w', strtotime($w['scheduled_date']));
        ?>
        <div class="week-row <?= $isToday ? 'week-row-today' : '' ?>" style="padding:10px 20px;">
            <span class="week-row-day"><?= $dayNames[$dow] ?></span>
            <div class="week-row-body">
                <span class="pill <?= pill_class($w['workout_type']) ?>">
                    <?= pill_label($w['workout_type']) ?>
                </span>
            </div>
            <span class="week-row-duration">
                <?= $w['target_duration'] ? format_duration((int)$w['target_duration']) : '' ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- YOUR STATS -->
    <?php if ($loadData): ?>
    <div class="section-label" style="margin-top:24px;">YOUR STATS</div>
    <div class="metric-grid">
        <div class="metric-tile">
            <div class="metric-label">Fitness (CTL)</div>
            <div class="metric-value"><?= number_format((float)($loadData['ctl'] ?? 0), 0) ?></div>
        </div>
        <div class="metric-tile">
            <div class="metric-label">Fatigue (ATL)</div>
            <div class="metric-value"><?= number_format((float)($loadData['atl'] ?? 0), 0) ?></div>
        </div>
        <div class="metric-tile">
            <div class="metric-label">Form (TSB)</div>
            <?php $tsb = (float)($loadData['tsb'] ?? 0); ?>
            <div class="metric-value" style="color:<?= $tsb > 5 ? 'var(--color-success)' : ($tsb < -20 ? 'var(--color-danger)' : 'var(--text-primary)') ?>">
                <?= $tsb >= 0 ? '+' : '' ?><?= number_format($tsb, 0) ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($profile && $profile['goal_race_date']): ?>
    <div class="section-label" style="margin-top:24px;">RACE COUNTDOWN</div>
    <div class="card" style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="card-title"><?= h($profile['goal_race_distance'] ?? '') ?></div>
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

<?php
// $athlete, $profile, $activePlan, $allWorkouts, $athleteFlags, $loadSnapshot, $pbs, $nextRace
$today = date('Y-m-d');
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
                <span class="pill pill-active"><?= h(ucfirst($activePlan['status'])) ?></span>
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

        <!-- Workout list -->
        <div class="section-label">WORKOUTS</div>
        <?php
        // Group by week
        $byWeek = [];
        foreach ($allWorkouts as $w) {
            $week = date('W', strtotime($w['scheduled_date']));
            $byWeek[$week][] = $w;
        }
        $weekNum = 0;
        foreach ($byWeek as $week => $workouts):
            $weekNum++;
            $startDate = reset($workouts)['scheduled_date'];
        ?>
        <div style="margin-bottom:16px;">
            <div style="font-size:11px;font-weight:600;color:var(--text-muted);letter-spacing:.06em;
                        text-transform:uppercase;margin-bottom:8px;">
                Week <?= $weekNum ?> · <?= date('M j', strtotime($startDate)) ?>
            </div>
            <?php foreach ($workouts as $w):
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
        <?php endforeach; ?>

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
</div>

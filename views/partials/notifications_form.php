<?php
/**
 * Reusable notification-preferences UI.
 *
 * Expects:
 *   $notifAudience  'athlete' | 'coach'
 *   $notifAction    POST url for AJAX saves (e.g. /app/settings/notifications)
 *   $prefs          map: notification_type => row (enabled, channel_push,
 *                   channel_email, preferred_time, preferred_day)
 *   $quiet          ['start' => 'HH:MM:SS', 'end' => 'HH:MM:SS']
 *
 * Each control carries data-notif-type + data-notif-field; app.js POSTs the
 * change immediately (no Save button). SMS is shown disabled ("Coming soon").
 */

$pref = function (string $type) use ($prefs) {
    return $prefs[$type] ?? Notifications::defaultFor($type);
};

/** Render a locked, always-on row (display only). */
$alwaysOn = function (string $title, string $sub) {
    ?>
    <div class="notif-row">
        <div class="notif-row-head">
            <div>
                <div class="notif-row-title"><?= h($title) ?></div>
                <div class="notif-row-sub"><?= h($sub) ?></div>
            </div>
            <span class="notif-row-lock" title="Always on">🔒 Required</span>
        </div>
    </div>
    <?php
};

/** Render a controllable row with master toggle + inline channel/timing detail. */
$row = function (string $type, string $title, string $sub, array $opts = []) use ($pref) {
    $p        = $pref($type);
    $enabled  = (int)$p['enabled'] === 1;
    $time     = $p['preferred_time'] ? substr($p['preferred_time'], 0, 5) : ($opts['default_time'] ?? '');
    $day      = $p['preferred_day'];
    $delivery = ($p['delivery'] ?? 'immediate') === 'daily_digest' ? 'daily_digest' : 'immediate';
    ?>
    <div class="notif-row <?= $enabled ? '' : 'is-off' ?>">
        <div class="notif-row-head">
            <div>
                <div class="notif-row-title"><?= h($title) ?></div>
                <div class="notif-row-sub"><?= h($sub) ?></div>
            </div>
            <label class="toggle">
                <input type="checkbox" data-notif-type="<?= h($type) ?>" data-notif-field="enabled" <?= $enabled ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>
        <div class="notif-detail">
            <?php if (!empty($opts['delivery'])): ?>
            <div>
                <div class="notif-field-label">Delivery</div>
                <select class="form-select" style="font-size:13px;max-width:180px;"
                        data-notif-type="<?= h($type) ?>" data-notif-field="delivery">
                    <option value="immediate" <?= $delivery === 'immediate' ? 'selected' : '' ?>>As they happen</option>
                    <option value="daily_digest" <?= $delivery === 'daily_digest' ? 'selected' : '' ?>>Daily digest</option>
                </select>
            </div>
            <?php endif; ?>

            <?php if (!empty($opts['day'])): ?>
            <div>
                <div class="notif-field-label">Day</div>
                <div class="day-picker" data-notif-daypicker data-notif-type="<?= h($type) ?>">
                    <?php $labels = [0 => 'S', 1 => 'M', 2 => 'T', 3 => 'W', 4 => 'T', 5 => 'F', 6 => 'S'];
                    foreach ($labels as $dnum => $lbl): ?>
                        <button type="button" class="day-btn <?= (string)$day === (string)$dnum ? 'selected' : '' ?>" data-day="<?= $dnum ?>"><?= $lbl ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($opts['time'])): ?>
            <div>
                <div class="notif-field-label">Delivery time</div>
                <input type="time" class="notif-time-input" step="1800"
                       data-notif-type="<?= h($type) ?>" data-notif-field="preferred_time"
                       value="<?= h($time) ?>">
            </div>
            <?php endif; ?>

            <div class="notif-channel">
                <span class="notif-channel-label">Push</span>
                <label class="toggle">
                    <input type="checkbox" data-notif-type="<?= h($type) ?>" data-notif-field="channel_push" <?= (int)$p['channel_push'] === 1 ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="notif-channel">
                <span class="notif-channel-label">Email</span>
                <label class="toggle">
                    <input type="checkbox" data-notif-type="<?= h($type) ?>" data-notif-field="channel_email" <?= (int)$p['channel_email'] === 1 ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="notif-channel is-disabled">
                <span class="notif-channel-label">SMS</span>
                <label class="toggle"><input type="checkbox" disabled><span class="toggle-slider"></span></label>
            </div>
        </div>
    </div>
    <?php
};

$qStart = isset($quiet['start']) ? substr($quiet['start'], 0, 5) : '22:00';
$qEnd   = isset($quiet['end'])   ? substr($quiet['end'], 0, 5)   : '07:00';
?>

<div data-notif-form data-notif-action="<?= h($notifAction) ?>">
    <div class="section-label" style="display:flex;align-items:center;">
        ALWAYS ON <span class="notif-status" data-notif-status></span>
    </div>
    <div class="card" style="margin-bottom:16px;">
        <?php if ($notifAudience === 'athlete'): ?>
            <?php $alwaysOn('Plan approved', 'When your coach approves a new training plan'); ?>
            <?php $alwaysOn('Message from coach', 'When your coach sends you a message'); ?>
        <?php else: ?>
            <?php $alwaysOn('Plan pending approval', 'When an athlete\'s plan is ready for your review'); ?>
            <?php $alwaysOn('Critical engine flag', 'Urgent issues that need your attention'); ?>
            <?php $alwaysOn('Message from athlete', 'When an athlete sends you a message'); ?>
        <?php endif; ?>
    </div>

    <?php if ($notifAudience === 'athlete'): ?>
        <div class="section-label">TRAINING</div>
        <div class="card" style="margin-bottom:16px;">
            <?php $row('tomorrow_plan', "Tomorrow's plan", 'A heads-up the evening before each session', ['time' => true]); ?>
            <?php $row('rpe_prompt', 'RPE prompt', 'A reminder to log how a hard session felt'); ?>
            <?php $row('pre_race_reminder', 'Pre-race reminders', 'Countdown reminders before a goal race'); ?>
            <?php $row('long_run_reminder', 'Long run reminder', 'A heads-up the night before a long run'); ?>
        </div>

        <div class="section-label">COACH &amp; PROGRESS</div>
        <div class="card" style="margin-bottom:16px;">
            <?php $row('coach_session_comment', 'Coach session comment', 'When your coach comments on a workout'); ?>
            <?php $row('weekly_summary', 'Weekly summary', 'Your week in review', ['day' => true, 'time' => true]); ?>
        </div>
    <?php else: ?>
        <div class="section-label">FLAGS &amp; ALERTS</div>
        <div class="card" style="margin-bottom:16px;">
            <?php $row('warning_flag', 'Warning-level flags', 'Non-urgent issues worth a look. Deliver each as it happens, or batched once a day.', ['delivery' => true]); ?>
            <?php $row('info_flag', 'Info-level flags', 'Low-priority informational flags. Deliver each as it happens, or batched once a day.', ['delivery' => true]); ?>
        </div>

        <div class="section-label">ATHLETE ACTIVITY</div>
        <div class="card" style="margin-bottom:16px;">
            <?php $row('athlete_session_note', 'Athlete session note', 'When an athlete adds a note to a session'); ?>
            <?php $row('athlete_manual_log', 'Athlete manual log', 'When an athlete logs a workout manually'); ?>
            <?php $row('athlete_day_swap', 'Athlete day swap', 'When an athlete moves a workout to another day'); ?>
        </div>

        <div class="section-label">SUMMARIES</div>
        <div class="card" style="margin-bottom:16px;">
            <?php $row('weekly_athlete_digest', 'Weekly athlete digest', 'A roster-wide summary', ['day' => true, 'time' => true]); ?>
            <?php $row('individual_athlete_summary', 'Individual athlete summaries', 'Per-athlete weekly summaries'); ?>
        </div>
    <?php endif; ?>

    <div class="section-label">QUIET HOURS</div>
    <div class="card" style="margin-bottom:16px;">
        <div class="notif-row-sub" style="margin-bottom:12px;">
            Notifications are held during these hours (your local time). Race-day reminders always come through.
        </div>
        <div style="display:flex;gap:16px;">
            <div>
                <div class="notif-field-label">From</div>
                <input type="time" class="notif-time-input" step="1800"
                       data-notif-type="quiet_hours" data-notif-field="quiet_hours_start" value="<?= h($qStart) ?>">
            </div>
            <div>
                <div class="notif-field-label">To</div>
                <input type="time" class="notif-time-input" step="1800"
                       data-notif-type="quiet_hours" data-notif-field="quiet_hours_end" value="<?= h($qEnd) ?>">
            </div>
        </div>
    </div>
</div>

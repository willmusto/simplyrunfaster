<?php
// $workouts = array of planned_workouts for the two-week swap window (first
// ATHLETE_WINDOW_DAYS are rendered; the day-swap picker uses all $swapWindowDays).
$tz             = $athlete['timezone'] ?? Auth::timezone();
$today          = Timezone::dateInZone($tz, 'now');
$dayNames       = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$mustOffDays    = $mustOffDays ?? [];
$swapWindowDays = $swapWindowDays ?? 14;
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

    // Day-swap picker dataset: every day in the two-week window with its current
    // content. The first workout on a day (athletes have ≤1/day in practice) drives
    // the badge + swap target. Embedded as JSON for the picker JS below.
    $pickerDays = [];
    for ($i = 0; $i < $swapWindowDays; $i++) {
        $pd  = Timezone::dateInZone($tz, "+$i days");
        $pdw = (int)date('w', strtotime($pd));
        $pwk = $byDate[$pd][0] ?? null;
        $pickerDays[] = [
            'date'       => $pd,
            'day_name'   => $i === 0 ? 'Today' : $dayNames[$pdw],
            'date_label' => date('M j', strtotime($pd)),
            'must_off'   => in_array($pdw, $mustOffDays, true),
            'label'      => $pwk ? pill_label($pwk['workout_type']) : null,
            'locked'     => $pwk ? (bool)$pwk['coach_locked'] : false,
        ];
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

                <?php if (empty($w['coach_locked'])): ?>
                <button type="button" class="btn btn-secondary btn-sm" data-move
                        style="margin-left:auto;"
                        data-workout-id="<?= (int)$w['id'] ?>"
                        data-workout-date="<?= h($w['scheduled_date']) ?>"
                        data-workout-label="<?= h(pill_label($w['workout_type'])) ?>">
                    Move
                </button>
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

    <!-- Day-swap picker modal (Section 12) -->
    <div data-move-modal class="srf-move-overlay" hidden>
        <div class="srf-move-sheet" role="dialog" aria-modal="true" aria-label="Move workout">
            <div class="srf-move-head">
                <div class="srf-move-title">Move <span data-move-label>workout</span></div>
                <button type="button" class="srf-move-close" data-move-cancel aria-label="Close">&times;</button>
            </div>
            <p class="srf-move-sub">Pick a day in the next two weeks.</p>
            <div data-move-days class="srf-move-days"></div>
            <div data-move-error class="srf-move-error" hidden></div>
        </div>
    </div>

    <script>window.SRF_SWAP_DAYS = <?= json_encode($pickerDays, JSON_UNESCAPED_SLASHES) ?>;</script>
    <script>
    (function () {
        var overlay = document.querySelector('[data-move-modal]');
        if (!overlay) return;
        var days   = window.SRF_SWAP_DAYS || [];
        var meta   = document.querySelector('meta[name="csrf-token"]');
        var csrf   = meta ? meta.content : '';
        var listEl = overlay.querySelector('[data-move-days]');
        var labelEl= overlay.querySelector('[data-move-label]');
        var errEl  = overlay.querySelector('[data-move-error]');
        var current = null; // { id, date, label }
        var busy = false;

        function openModal(id, date, label) {
            current = { id: id, date: date, label: label };
            labelEl.textContent = label || 'workout';
            showError('');
            renderDays();
            overlay.hidden = false;
            document.body.style.overflow = 'hidden';
        }
        function closeModal() {
            overlay.hidden = true;
            document.body.style.overflow = '';
            current = null;
        }
        function showError(msg) {
            errEl.textContent = msg || '';
            errEl.hidden = !msg;
        }

        function renderDays() {
            listEl.innerHTML = '';
            days.forEach(function (d) {
                var isCurrent = current && d.date === current.date;
                var row = document.createElement('button');
                row.type = 'button';
                row.className = 'srf-move-day'
                    + (isCurrent ? ' is-current' : '')
                    + (d.must_off ? ' is-mustoff' : '');
                row.disabled = isCurrent;

                var left = document.createElement('div');
                left.className = 'srf-move-day-when';
                left.innerHTML = '<span class="srf-move-day-name">' + esc(d.day_name) + '</span>'
                    + '<span class="srf-move-day-date">' + esc(d.date_label) + '</span>';

                var right = document.createElement('div');
                right.className = 'srf-move-day-content';
                if (d.must_off) {
                    right.innerHTML = '<span class="srf-move-lock">🔒</span> Must-off';
                } else if (isCurrent) {
                    right.textContent = (d.label || 'Rest') + ' · current';
                } else if (d.label) {
                    right.innerHTML = '<span class="srf-move-badge">' + esc(d.label) + '</span>';
                } else {
                    right.textContent = 'Rest';
                }

                row.appendChild(left);
                row.appendChild(right);
                if (!isCurrent) {
                    row.addEventListener('click', function () { onPick(d); });
                }
                listEl.appendChild(row);
            });
        }

        function onPick(d) {
            if (busy || !current) return;
            // Must-off day → soft warning, proceeds with force.
            if (d.must_off) {
                if (confirm('This day is marked as a must-off day in your schedule. '
                          + 'Are you sure you want to move your workout here?')) {
                    submit(d.date, true);
                }
                return;
            }
            // Day already has a workout → swap confirmation.
            if (d.label) {
                if (confirm('This will swap ' + current.label + ' and ' + d.label + '. Continue?')) {
                    submit(d.date, false);
                }
                return;
            }
            // Rest day → no confirmation.
            submit(d.date, false);
        }

        function submit(targetDate, force) {
            if (busy || !current) return;
            busy = true;
            showError('');
            fetch('/app/athlete/workout/swap', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body:    JSON.stringify({ workout_id: current.id, target_date: targetDate, force: !!force }),
            }).then(function (r) { return r.json(); })
              .then(function (res) {
                  if (res && res.success) {
                      window.location.reload();
                  } else {
                      busy = false;
                      showError((res && res.message) ? res.message : 'Could not move the workout. Please try again.');
                  }
              })
              .catch(function () {
                  busy = false;
                  showError('Could not move the workout. Please try again.');
              });
        }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
            });
        }

        document.querySelectorAll('[data-move]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModal(
                    parseInt(btn.getAttribute('data-workout-id'), 10),
                    btn.getAttribute('data-workout-date'),
                    btn.getAttribute('data-workout-label')
                );
            });
        });
        overlay.querySelectorAll('[data-move-cancel]').forEach(function (el) {
            el.addEventListener('click', closeModal);
        });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) closeModal(); });
    })();
    </script>

    <style>
    .srf-move-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1000;
        display:flex; align-items:flex-end; justify-content:center; }
    /* The class selector above outranks the UA [hidden] rule, so the attribute alone
       won't hide the overlay — restore it explicitly (also makes closeModal work). */
    .srf-move-overlay[hidden] { display:none; }
    .srf-move-sheet { background:var(--surface-bg,var(--card-bg,#fff)); color:var(--text-primary,#111);
        width:100%; max-width:480px; border-radius:16px 16px 0 0; padding:18px 18px 28px;
        max-height:85vh; overflow-y:auto; box-shadow:0 -8px 32px rgba(0,0,0,0.25); }
    @media (min-width:520px) { .srf-move-overlay { align-items:center; }
        .srf-move-sheet { border-radius:16px; } }
    .srf-move-head { display:flex; align-items:center; justify-content:space-between; }
    .srf-move-title { font-size:16px; font-weight:600; }
    .srf-move-close { background:none; border:none; font-size:26px; line-height:1; cursor:pointer;
        color:var(--text-muted); padding:0 4px; }
    .srf-move-sub { font-size:13px; color:var(--text-muted); margin:4px 0 14px; }
    .srf-move-days { display:flex; flex-direction:column; gap:8px; }
    .srf-move-day { display:flex; align-items:center; justify-content:space-between; gap:12px;
        width:100%; text-align:left; padding:12px 14px; border:1px solid var(--border-color,rgba(0,0,0,0.1));
        border-radius:10px; background:var(--card-bg,#fff); color:inherit; cursor:pointer; font:inherit; }
    .srf-move-day:hover:not(:disabled) { border-color:var(--accent-mid,#4a7); }
    .srf-move-day:disabled { cursor:default; opacity:0.55; }
    .srf-move-day.is-current { border-style:dashed; }
    .srf-move-day.is-mustoff { opacity:0.7; }
    .srf-move-day-when { display:flex; flex-direction:column; }
    .srf-move-day-name { font-size:14px; font-weight:600; }
    .srf-move-day-date { font-size:11px; color:var(--text-muted); }
    .srf-move-day-content { font-size:12px; color:var(--text-muted); text-align:right; }
    .srf-move-badge { display:inline-block; padding:2px 8px; border-radius:10px;
        background:var(--recessed-bg,rgba(0,0,0,0.06)); color:var(--text-secondary,#444); font-size:11px; }
    .srf-move-lock { font-size:12px; }
    .srf-move-error { margin-top:12px; font-size:13px; color:#C0392B; }
    </style>

    <?php endif; ?>
</div>

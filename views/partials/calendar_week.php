<?php
/**
 * Weekly training calendar partial — Garmin Connect-style bubble view.
 *
 * Caller sets in scope before including:
 *   $calWorkouts  — flat array of workout rows (planned_workouts or completed_workouts)
 *   $calMode      — 'preview' (uses target_duration) | 'log' (uses actual_duration)
 */

$_durKey = ($calMode === 'log') ? 'actual_duration' : 'target_duration';

// Group by ISO week (Mon=1…Sun=7), slot by day-of-week
$_weekMap = [];
foreach ($calWorkouts as $_w) {
    $_ds  = $_w['scheduled_date'] ?? $_w['activity_date'] ?? null;
    if (!$_ds) continue;
    $_ts  = strtotime($_ds);
    $_dow = (int)date('N', $_ts);
    $_mon = date('Y-m-d', $_ts - ($_dow - 1) * 86400);
    $_weekMap[$_mon][$_dow] = $_w;
}
ksort($_weekMap);

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

$_legend = [
    'easy'       => 'Easy run',
    'long'       => 'Long run',
    'interval'   => 'Workout',
    'tempo'      => 'Tempo',
    'hill'       => 'Hill session',
    'fartlek'    => 'Fartlek',
    'recovery'   => 'Recovery',
];

$_bubLabel = function(int $m): string {
    if ($m <= 0) return '';
    if ($m < 60) return (string)$m;
    $h = intdiv($m, 60); $r = $m % 60;
    return $r ? "{$h}h{$r}" : "{$h}h";
};

$_bubSize = function(int $m): int {
    if ($m <= 0) return 0;
    return max(30, min(52, (int)round(28 + ($m / 90.0) * 24)));
};
?>
<?php if (!defined('_CAL_WEEK_STYLES')): define('_CAL_WEEK_STYLES', true); ?>
<style>
/* ── Calendar grid ───────────────────────────────────────────── */
.cal-outer     { overflow-x: hidden; }
.cal-hdr-row,
.cal-data-row  { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
.cal-hdr-row   { margin-bottom: 6px; }
.cal-wk-lbl    { width: 72px; flex-shrink: 0; font-size: 10px;
                 color: var(--text-muted); line-height: 1.4; }
.cal-days-grid { display: grid; grid-template-columns: repeat(7,1fr);
                 gap: 4px; flex: 1; min-width: 0; }
.cal-day-col   { display: flex; flex-direction: column; align-items: center;
                 gap: 3px; min-width: 0; }
.cal-day-hdr   { font-size: 9px; font-weight: 600; letter-spacing: .05em;
                 color: var(--text-muted); text-transform: uppercase; }
.cal-bubble    { border-radius: 50%; display: flex; align-items: center;
                 justify-content: center; flex-shrink: 0; position: relative;
                 transition: transform .12s; }
.cal-bubble[data-workout] { cursor: pointer; }
.cal-bubble[data-workout]:hover { transform: scale(1.1); }
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

/* ── Workout detail modal ────────────────────────────────────── */
#calWD {
    display: none;
    position: fixed; inset: 0; z-index: 9999;
    align-items: center; justify-content: center;
}
#calWD.is-open { display: flex; }
#calWD-bd {
    position: absolute; inset: 0;
    background: rgba(0,0,0,.45);
}
#calWD-sheet {
    position: relative; z-index: 1;
    width: min(480px, calc(100vw - 32px));
    max-height: 88vh; overflow-y: auto;
    background: var(--card-bg);
    border: var(--card-border);
    border-radius: var(--radius-card);
    padding: 20px 20px 24px;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}
#calWD-close {
    position: absolute; top: 12px; right: 14px;
    background: none; border: none; cursor: pointer;
    font-size: 22px; line-height: 1; padding: 2px 4px;
    color: var(--text-muted);
}
#calWD-close:hover { color: var(--text-primary); }
</style>

<!-- Workout detail modal (rendered once per page) -->
<div id="calWD" role="dialog" aria-modal="true" aria-label="Workout detail">
    <?= Auth::csrfField() ?>
    <div id="calWD-bd"></div>
    <div id="calWD-sheet">
        <button id="calWD-close" aria-label="Close">×</button>

        <!-- View mode -->
        <div id="calWD-view">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;padding-right:28px;">
                <span id="calWD-type-pill" class="pill"></span>
                <span id="calWD-date" style="font-size:12px;color:var(--text-muted);"></span>
            </div>
            <div id="calWD-name"
                 style="font-size:15px;font-weight:600;color:var(--text-primary);margin-bottom:8px;"></div>
            <div id="calWD-desc"
                 style="font-size:13px;color:var(--text-secondary);line-height:1.6;
                        margin-bottom:14px;white-space:pre-line;"></div>
            <div style="font-size:10px;font-weight:600;letter-spacing:.06em;
                        text-transform:uppercase;color:var(--text-muted);margin-bottom:2px;">
                Target duration
            </div>
            <div id="calWD-dur"
                 style="font-size:14px;font-weight:500;color:var(--text-primary);
                        margin-bottom:18px;"></div>
            <button id="calWD-btn-edit" class="btn btn-secondary btn-sm">Edit workout</button>
        </div>

        <!-- Edit mode (hidden until coach taps Edit) -->
        <div id="calWD-edit" style="display:none;">
            <div style="font-size:15px;font-weight:600;margin-bottom:16px;">Edit workout</div>

            <div class="form-group">
                <div class="form-label" style="margin-bottom:6px;">Type</div>
                <div class="pill-choices" id="calWD-e-types" style="flex-wrap:wrap;"></div>
            </div>

            <div class="form-group">
                <label class="form-label" for="calWD-e-dur">Duration (minutes)</label>
                <input type="number" id="calWD-e-dur" class="form-input"
                       min="1" max="600" style="max-width:110px;">
            </div>

            <div class="form-group">
                <label class="form-label" for="calWD-e-desc">Description</label>
                <textarea id="calWD-e-desc" class="form-textarea" rows="5"
                          style="font-size:13px;"></textarea>
            </div>

            <div style="display:flex;gap:8px;align-items:center;">
                <button id="calWD-btn-save"   class="btn btn-primary btn-sm">Save</button>
                <button id="calWD-btn-cancel" class="btn btn-secondary btn-sm">Cancel</button>
                <span id="calWD-err"
                      style="display:none;font-size:12px;color:var(--color-danger);"></span>
            </div>
        </div>
    </div>
</div>

<script>
(function () {

var _activeBubble = null;
var _activeData   = null;

function $id(id) { return document.getElementById(id); }

var TYPE_META = [
    {v:'easy',        l:'Easy'},
    {v:'long',        l:'Long run'},
    {v:'interval',    l:'Workout'},
    {v:'tempo',       l:'Tempo'},
    {v:'hill',        l:'Hill'},
    {v:'fartlek',     l:'Fartlek'},
    {v:'recovery',    l:'Recovery'},
    {v:'cross_train', l:'Cross-train'},
    {v:'race',        l:'Race'},
];
var TYPE_BG = {
    easy:'#E1F5EE', long:'#0F6E56',  interval:'#042C53', tempo:'#185FA5',
    hill:'#3B6D11', fartlek:'#639922',recovery:'#D3D1C7', race:'#993C1D',
    cross_train:'#4A3B8A'
};
var TYPE_FG = {
    easy:'#085041', long:'#fff', interval:'#fff', tempo:'#fff',
    hill:'#fff',    fartlek:'#fff', recovery:'#444441', race:'#fff',
    cross_train:'#fff'
};
var TYPE_LABEL = {
    easy:'Easy run', long:'Long run', interval:'Workout', tempo:'Tempo',
    hill:'Hill session', fartlek:'Fartlek', recovery:'Recovery',
    race:'Race', cross_train:'Cross-train'
};

function fmtDur(m) {
    m = parseInt(m, 10);
    if (!m) return '—';
    if (m < 60) return m + ' min';
    var h = Math.floor(m/60), r = m%60;
    return r ? h + 'h ' + r + 'min' : h + 'h';
}

function bubLabel(m) {
    m = parseInt(m, 10);
    if (!m || m <= 0) return '';
    if (m < 60) return String(m);
    var h = Math.floor(m/60), r = m%60;
    return r ? h + 'h' + r : h + 'h';
}

function bubSize(m) {
    m = parseInt(m, 10);
    if (!m || m <= 0) return 0;
    return Math.max(30, Math.min(52, Math.round(28 + (m/90)*24)));
}

/* ── Modal open / close ── */
function openModal(bubble) {
    var raw = bubble.getAttribute('data-workout');
    if (!raw) return;
    var data;
    try { data = JSON.parse(raw); } catch(e) { return; }
    _activeBubble = bubble;
    _activeData   = data;

    var mode = (bubble.closest('[data-calmode]') || {dataset:{}}).dataset.calmode || 'preview';

    $id('calWD-type-pill').textContent = TYPE_LABEL[data.workout_type] || data.workout_type;
    $id('calWD-type-pill').className   = 'pill pill-' + data.workout_type;
    $id('calWD-date').textContent      = data.scheduled_date
        ? new Date(data.scheduled_date + 'T00:00:00').toLocaleDateString('en-US', {weekday:'short',month:'short',day:'numeric'})
        : '';
    $id('calWD-name').textContent      = data.display_title || data.template_name || TYPE_LABEL[data.workout_type] || '';
    $id('calWD-desc').textContent      = data.athlete_instructions || data.description || '';
    $id('calWD-dur').textContent       = fmtDur(data.target_duration);
    $id('calWD-btn-edit').style.display = (mode === 'preview') ? '' : 'none';

    showView();
    $id('calWD').classList.add('is-open');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    $id('calWD').classList.remove('is-open');
    document.body.style.overflow = '';
    _activeBubble = null;
    _activeData   = null;
}

function showView() {
    $id('calWD-view').style.display = '';
    $id('calWD-edit').style.display = 'none';
    $id('calWD-err').style.display  = 'none';
}

function showEdit() {
    var data = _activeData;
    if (!data) return;

    /* Type radios */
    var html = '';
    TYPE_META.forEach(function(t) {
        var chk = (t.v === data.workout_type) ? ' checked' : '';
        html += '<label class="pill-choice" style="margin-bottom:4px;">'
              + '<input type="radio" name="calWD_type" value="' + t.v + '"' + chk + '> '
              + t.l + '</label>';
    });
    $id('calWD-e-types').innerHTML = html;

    /* Duration */
    $id('calWD-e-dur').value = data.target_duration || '';

    /* Description — populated from athlete_instructions (archetype-generated) */
    $id('calWD-e-desc').value = data.athlete_instructions || data.description || '';

    $id('calWD-err').style.display  = 'none';
    $id('calWD-view').style.display = 'none';
    $id('calWD-edit').style.display = '';
}

/* ── Save ── */
function saveEdit() {
    var data = _activeData;
    if (!data || !data.id) return;

    var checkedType = $id('calWD-e-types').querySelector('input[name="calWD_type"]:checked');
    var type = checkedType ? checkedType.value : data.workout_type;
    var dur  = parseInt($id('calWD-e-dur').value, 10) || data.target_duration;
    var desc = $id('calWD-e-desc').value.trim();

    var csrf = '';
    var tokenEl = document.querySelector('input[name="srf_csrf"]');
    if (tokenEl) csrf = tokenEl.value;

    var body = new URLSearchParams();
    body.set('srf_csrf',            csrf);
    body.set('workout_type',        type);
    body.set('target_duration',     dur);
    body.set('athlete_instructions', desc);

    var btn = $id('calWD-btn-save');
    btn.disabled = true;
    btn.textContent = 'Saving…';
    $id('calWD-err').style.display = 'none';

    fetch('/app/coach/workouts/' + data.id + '/edit', {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    body.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled    = false;
        btn.textContent = 'Save';
        if (!res.ok) {
            $id('calWD-err').textContent   = res.error || 'Save failed.';
            $id('calWD-err').style.display = '';
            return;
        }
        /* Update in-memory snapshot */
        var w = res.workout;
        _activeData.workout_type        = w.workout_type;
        _activeData.target_duration     = parseInt(w.target_duration, 10);
        _activeData.athlete_instructions= w.athlete_instructions || '';
        _activeData.description         = w.description || '';
        _activeData.display_title       = w.display_title || '';
        _activeData.template_name       = w.template_name || _activeData.template_name;

        /* Update bubble DOM */
        if (_activeBubble) {
            _activeBubble.setAttribute('data-workout', JSON.stringify(_activeData));
            _activeBubble.style.background = TYPE_BG[w.workout_type] || 'var(--recessed-bg)';
            _activeBubble.style.color      = TYPE_FG[w.workout_type] || 'var(--text-secondary)';
            var sz = bubSize(_activeData.target_duration);
            _activeBubble.style.width      = 'min(' + sz + 'px, 100%)';
            var lbl = _activeBubble.querySelector('.cal-bub-lbl');
            if (lbl) lbl.textContent = bubLabel(_activeData.target_duration);
        }
        closeModal();
    })
    .catch(function() {
        btn.disabled    = false;
        btn.textContent = 'Save';
        $id('calWD-err').textContent   = 'Network error — please try again.';
        $id('calWD-err').style.display = '';
    });
}

/* ── Event delegation ── */
document.addEventListener('click', function(e) {
    var bubble = e.target.closest('[data-workout]');
    if (bubble) { openModal(bubble); return; }

    var id = e.target.id;
    if (id === 'calWD-bd')         { closeModal(); return; }
    if (id === 'calWD-close')      { closeModal(); return; }
    if (id === 'calWD-btn-edit')   { showEdit();   return; }
    if (id === 'calWD-btn-save')   { saveEdit();   return; }
    if (id === 'calWD-btn-cancel') { showView();   return; }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && $id('calWD').classList.contains('is-open')) {
        closeModal();
    }
});

})();
</script>
<?php endif; /* _CAL_WEEK_STYLES */ ?>

<?php /* ── Per-include output: legend + grid ── */ ?>

<!-- Color legend -->
<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:12px;">
    <?php foreach ($_legend as $_type => $_label): ?>
    <span class="pill pill-<?= h($_type) ?>" style="font-size:11px;"><?= h($_label) ?></span>
    <?php endforeach; ?>
    <span style="display:inline-flex;align-items:center;gap:5px;
                 font-size:11px;color:var(--text-muted);">
        <span style="width:8px;height:8px;border-radius:50%;
                     background:var(--border-strong);display:inline-block;flex-shrink:0;"></span>
        Rest
    </span>
</div>

<div class="cal-outer" data-calmode="<?= h($calMode ?? 'preview') ?>">

    <!-- Day-of-week column headers -->
    <div class="cal-hdr-row">
        <div class="cal-wk-lbl"></div>
        <div class="cal-days-grid">
            <?php foreach (['M','T','W','T','F','S','S'] as $_lbl): ?>
            <div class="cal-day-col"><span class="cal-day-hdr"><?= $_lbl ?></span></div>
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
    ?>
    <div class="cal-data-row">

        <div class="cal-wk-lbl" style="white-space:pre-line;"><?= 'Wk ' . $_wn . "\n" . date('M j', strtotime($_monDate)) ?></div>

        <div class="cal-days-grid">
            <?php for ($_iso = 1; $_iso <= 7; $_iso++):
                $_slot = $_slots[$_iso] ?? null;
                $_dur  = $_slot ? max(0, (int)($_slot[$_durKey] ?? 0)) : 0;
                $_type = $_slot['workout_type'] ?? null;
                $_sz   = $_bubSize($_dur);
                $_clr  = $_type ? ($_bubbleColor[$_type] ?? ['var(--recessed-bg)', 'var(--text-secondary)']) : null;

                // Data for modal
                $_wjson = null;
                if ($_slot && isset($_slot['id'])) {
                    $_wjson = htmlspecialchars(json_encode([
                        'id'                   => (int)$_slot['id'],
                        'workout_type'         => (string)($_slot['workout_type'] ?? ''),
                        'target_duration'      => (int)($_slot[$_durKey] ?? 0),
                        'display_title'        => (string)($_slot['display_title'] ?? ''),
                        'template_name'        => (string)($_slot['template_name'] ?? ''),
                        'description'          => (string)($_slot['description'] ?? ''),
                        'athlete_instructions' => (string)($_slot['athlete_instructions'] ?? ''),
                        'scheduled_date'       => (string)($_slot['scheduled_date'] ?? ''),
                    ]), ENT_QUOTES, 'UTF-8');
                }
            ?>
            <div class="cal-day-col">
                <?php if ($_slot && $_sz > 0): ?>
                <div class="cal-bubble"
                     style="width:min(<?= $_sz ?>px,100%);aspect-ratio:1;
                            background:<?= $_clr[0] ?>;color:<?= $_clr[1] ?>;"
                     <?= $_wjson !== null ? 'data-workout="' . $_wjson . '"' : '' ?>
                     title="<?= h(($_slot['display_title'] ?? $_slot['template_name'] ?? ucfirst(str_replace('_', ' ', $_type ?? ''))) . ($_dur > 0 ? ' · ' . $_dur . ' min' : '')) ?>">
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

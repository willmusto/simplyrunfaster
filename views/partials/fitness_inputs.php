<?php
/**
 * Shared dual-path fitness inputs: weekly volume + longest recent run.
 * Both methods DERIVE the canonical columns (current_weekly_minutes,
 * longest_recent_run_mins) — there is no second stored field.
 *
 * Expects: $p (array of current values; may be empty). Rendered inside a <form>.
 * Optional: $unitLabel ('mi'|'km') — defaults to the athlete's stored units.
 *
 * Method components posted (server derives via ProfileForm::derive*):
 *   weekly_volume_method = time|distance
 *     time:     weekly_time_hours, weekly_time_minutes
 *     distance: weekly_distance, weekly_pace (mm:ss per unit)
 *   longest_method = time|distance
 *     time:     longest_time_minutes
 *     distance: longest_distance, longest_pace (mm:ss per unit)
 */
$p         = $p ?? ($profile ?? []);
$unitLabel = $unitLabel ?? ((($p['units'] ?? 'miles') === 'km') ? 'km' : 'mi');
$wkMin     = (int)($p['current_weekly_minutes'] ?? 0);
$wkH       = intdiv($wkMin, 60);
$wkM       = $wkMin % 60;
$lrMin     = (int)($p['longest_recent_run_mins'] ?? 0);
?>
<!-- WEEKLY VOLUME (dual-path) -->
<div class="form-group" data-fitness-volume>
    <label class="form-label">Weekly running volume <span style="color:var(--color-danger);">*</span></label>
    <div class="pill-choices" style="margin-bottom:10px;">
        <label class="pill-choice selected" data-vol-toggle="time">
            <input type="radio" name="weekly_volume_method" value="time" checked> By time
        </label>
        <label class="pill-choice" data-vol-toggle="distance">
            <input type="radio" name="weekly_volume_method" value="distance"> By distance + pace
        </label>
    </div>

    <div data-vol-panel="time" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div>
            <label class="form-label" for="weekly_time_hours" style="font-size:12px;">Hours / week</label>
            <input type="number" id="weekly_time_hours" name="weekly_time_hours" class="form-input"
                   min="0" max="30" placeholder="e.g. 4" value="<?= $wkMin ? (int)$wkH : '' ?>" data-vol-calc>
        </div>
        <div>
            <label class="form-label" for="weekly_time_minutes" style="font-size:12px;">Minutes / week</label>
            <input type="number" id="weekly_time_minutes" name="weekly_time_minutes" class="form-input"
                   min="0" max="59" placeholder="e.g. 10" value="<?= $wkMin ? (int)$wkM : '' ?>" data-vol-calc>
        </div>
    </div>

    <div data-vol-panel="distance" style="display:none;grid-template-columns:1fr 1fr;gap:12px;">
        <div>
            <label class="form-label" for="weekly_distance" style="font-size:12px;">Weekly distance (<?= h($unitLabel) ?>)</label>
            <input type="number" id="weekly_distance" name="weekly_distance" class="form-input"
                   min="0" max="300" step="0.1" placeholder="e.g. 20" data-vol-calc>
        </div>
        <div>
            <label class="form-label" for="weekly_pace" style="font-size:12px;">Average pace (mm:ss / <?= h($unitLabel) ?>)</label>
            <input type="text" id="weekly_pace" name="weekly_pace" class="form-input"
                   placeholder="e.g. 9:30" data-vol-calc>
        </div>
    </div>
    <div class="form-hint" data-vol-preview style="margin-top:8px;">Include all running, warm-ups and cool-downs.</div>
</div>

<!-- LONGEST RECENT RUN (dual-path) -->
<div class="form-group" data-fitness-longest>
    <label class="form-label">Longest run in the last 30 days <span style="color:var(--color-danger);">*</span></label>
    <div class="pill-choices" style="margin-bottom:10px;">
        <label class="pill-choice selected" data-lr-toggle="time">
            <input type="radio" name="longest_method" value="time" checked> By time
        </label>
        <label class="pill-choice" data-lr-toggle="distance">
            <input type="radio" name="longest_method" value="distance"> By distance + pace
        </label>
    </div>

    <div data-lr-panel="time">
        <label class="form-label" for="longest_time_minutes" style="font-size:12px;">Minutes</label>
        <input type="number" id="longest_time_minutes" name="longest_time_minutes" class="form-input"
               min="0" max="600" placeholder="e.g. 75" value="<?= $lrMin ?: '' ?>" data-lr-calc>
    </div>

    <div data-lr-panel="distance" style="display:none;grid-template-columns:1fr 1fr;gap:12px;">
        <div>
            <label class="form-label" for="longest_distance" style="font-size:12px;">Distance (<?= h($unitLabel) ?>)</label>
            <input type="number" id="longest_distance" name="longest_distance" class="form-input"
                   min="0" max="200" step="0.1" placeholder="e.g. 8" data-lr-calc>
        </div>
        <div>
            <label class="form-label" for="longest_pace" style="font-size:12px;">Pace (mm:ss / <?= h($unitLabel) ?>)</label>
            <input type="text" id="longest_pace" name="longest_pace" class="form-input"
                   placeholder="e.g. 9:30" data-lr-calc>
        </div>
    </div>
    <div class="form-hint" data-lr-preview style="margin-top:8px;">A single continuous run, your longest recent effort.</div>
    <div class="form-hint" data-fitness-warn style="display:none;margin-top:8px;color:var(--color-warning);"></div>
</div>

<script>
(function () {
    var root = document.currentScript.parentNode;

    function parsePace(v) {
        v = (v || '').trim();
        var m = v.match(/^(\d{1,2}):([0-5]?\d)$/);
        if (m) return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
        if (/^\d+$/.test(v)) return parseInt(v, 10);
        return null;
    }
    function val(sel) { var el = root.querySelector(sel); return el ? el.value : ''; }
    function num(sel) { return parseFloat(val(sel)) || 0; }

    function deriveWeekly() {
        if (val('input[name="weekly_volume_method"]:checked') === 'distance') {
            var d = num('#weekly_distance'), p = parsePace(val('#weekly_pace'));
            return (d > 0 && p) ? Math.round(d * p / 60) : null;
        }
        var t = (parseInt(val('#weekly_time_hours'), 10) || 0) * 60 + (parseInt(val('#weekly_time_minutes'), 10) || 0);
        return t > 0 ? t : null;
    }
    function deriveLongest() {
        if (val('input[name="longest_method"]:checked') === 'distance') {
            var d = num('#longest_distance'), p = parsePace(val('#longest_pace'));
            return (d > 0 && p) ? Math.round(d * p / 60) : null;
        }
        var m = parseInt(val('#longest_time_minutes'), 10) || 0;
        return m > 0 ? m : null;
    }
    function days() {
        var el = root.querySelector('input[name="training_days_per_week"]:checked')
              || document.querySelector('input[name="training_days_per_week"]:checked')
              || document.querySelector('[name="training_days_per_week"]');
        return el ? (parseInt(el.value, 10) || 0) : 0;
    }

    function showPanel(group, method) {
        root.querySelectorAll('[data-' + group + '-panel]').forEach(function (panel) {
            var on = panel.getAttribute('data-' + group + '-panel') === method;
            panel.style.display = on ? (panel.children.length > 1 ? 'grid' : 'block') : 'none';
        });
        root.querySelectorAll('[data-' + group + '-toggle]').forEach(function (t) {
            t.classList.toggle('selected', t.getAttribute('data-' + group + '-toggle') === method);
        });
    }

    function refresh() {
        var w = deriveWeekly(), l = deriveLongest(), d = days();
        var wp = root.querySelector('[data-vol-preview]');
        if (wp) wp.innerHTML = w ? ('≈ <strong>' + w + ' min/week</strong>') : 'Include all running, warm-ups and cool-downs.';
        var lp = root.querySelector('[data-lr-preview]');
        if (lp) lp.innerHTML = l ? ('≈ <strong>' + l + ' min</strong>') : 'A single continuous run, your longest recent effort.';

        // Prefill longest-run pace from the weekly average pace (convenience), when blank.
        var lpace = root.querySelector('#longest_pace'), wpace = root.querySelector('#weekly_pace');
        if (lpace && wpace && !lpace.value && wpace.value
            && val('input[name="longest_method"]:checked') === 'distance') {
            lpace.value = wpace.value;
        }

        // Client soft/hard sanity hint (server is authoritative).
        var warn = root.querySelector('[data-fitness-warn]'), msg = '';
        if (w && l && l > w) msg = "Longest run can't exceed your weekly volume — please re-check.";
        else if (w >= 150 && l > 0 && l <= 25) msg = 'High weekly volume with a very short longest run — double-check.';
        else if (d > 0 && l > 0 && l < (w / d) * 0.5) msg = 'Longest run is shorter than your average run — double-check.';
        if (warn) { warn.style.display = msg ? '' : 'none'; warn.textContent = msg; }
    }

    root.querySelectorAll('input[name="weekly_volume_method"]').forEach(function (r) {
        r.addEventListener('change', function () { showPanel('vol', r.value); refresh(); });
    });
    root.querySelectorAll('input[name="longest_method"]').forEach(function (r) {
        r.addEventListener('change', function () { showPanel('lr', r.value); refresh(); });
    });
    root.querySelectorAll('[data-vol-calc],[data-lr-calc]').forEach(function (el) {
        el.addEventListener('input', refresh);
    });
    document.querySelectorAll('input[name="training_days_per_week"]').forEach(function (el) {
        el.addEventListener('change', refresh);
    });
    refresh();
})();
</script>

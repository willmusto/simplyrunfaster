<?php
/**
 * Shared training-profile form fields.
 * Expects: $profile (array, may be empty). Rendered inside a <form>.
 * Used by views/athlete/training_settings.php and views/coach/edit_profile.php.
 */
$p          = $profile ?? [];
$days       = ProfileForm::DAYS;
$mustOff    = json_decode($p['must_off_days'] ?? '[]', true);
if (!is_array($mustOff)) $mustOff = [];
$mustOffNorm = ProfileForm::normalizeDays($p['must_off_days'] ?? '[]');
$sched      = $p['scheduling_preference'] ?? 'flex';
$hasRace    = !empty($p['most_recent_race_time']);
?>

<!-- GOAL -->
<div class="section-label">GOAL</div>
<div class="card" style="margin-bottom:16px;">
    <div class="form-group">
        <label class="form-label">Goal race distance</label>
        <div class="pill-choices">
            <?php foreach (ProfileForm::RACE_DISTANCES as $dist): ?>
            <label class="pill-choice <?= ($p['goal_race_distance'] ?? '') === $dist ? 'selected' : '' ?>">
                <input type="radio" name="goal_race_distance" value="<?= h($dist) ?>"
                       <?= ($p['goal_race_distance'] ?? '') === $dist ? 'checked' : '' ?>>
                <?= h($dist) ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="goal_race_date">Goal race date</label>
            <input type="date" id="goal_race_date" name="goal_race_date" class="form-input"
                   value="<?= h($p['goal_race_date'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="goal_finish_time">Goal finish time</label>
            <input type="text" id="goal_finish_time" name="goal_finish_time" class="form-input"
                   placeholder="e.g. 1:45:00" value="<?= h($p['goal_finish_time'] ?? '') ?>">
        </div>
    </div>
</div>

<!-- CURRENT FITNESS -->
<div class="section-label">CURRENT FITNESS</div>
<div class="card" style="margin-bottom:16px;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group">
            <label class="form-label" for="current_weekly_minutes">Weekly volume (minutes)</label>
            <input type="number" id="current_weekly_minutes" name="current_weekly_minutes" class="form-input"
                   min="0" max="1200" placeholder="e.g. 180" value="<?= h($p['current_weekly_minutes'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="peak_weekly_minutes">Highest-ever weekly volume (minutes)</label>
            <input type="number" id="peak_weekly_minutes" name="peak_weekly_minutes" class="form-input"
                   min="0" max="2000" placeholder="e.g. 300" value="<?= h($p['peak_weekly_minutes'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="years_running">Years running</label>
            <input type="number" id="years_running" name="years_running" class="form-input"
                   min="0" max="80" step="0.5" placeholder="e.g. 4" value="<?= h($p['years_running'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="months_at_current_volume">Months at current volume</label>
            <input type="number" id="months_at_current_volume" name="months_at_current_volume" class="form-input"
                   min="0" max="600" placeholder="e.g. 3" value="<?= h($p['months_at_current_volume'] ?? '') ?>">
        </div>
    </div>
</div>

<!-- TYPICAL EASY PACE -->
<div class="section-label">TYPICAL EASY PACE</div>
<div class="card" style="margin-bottom:16px;">
    <p class="body-text" style="margin:0 0 12px;font-size:13px;color:var(--text-muted);">
        Roughly what pace do you run your easy days? A range is fine. We use this to estimate your
        pace assignments until a race result is on file.
        <?php if ($hasRace): ?>
        <br><strong>You already have a race result on file</strong>, so your pace zones come from that.
        This is kept for reference and used if your race data ages out.
        <?php endif; ?>
    </p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="typical_easy_pace_min">Faster end (min/mile)</label>
            <input type="text" id="typical_easy_pace_min" name="typical_easy_pace_min" class="form-input"
                   placeholder="e.g. 9:00"
                   value="<?= h(ProfileForm::formatPaceSecs(isset($p['typical_easy_pace_min']) ? (int)$p['typical_easy_pace_min'] : null)) ?>">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="typical_easy_pace_max">Slower end (min/mile)</label>
            <input type="text" id="typical_easy_pace_max" name="typical_easy_pace_max" class="form-input"
                   placeholder="e.g. 10:00"
                   value="<?= h(ProfileForm::formatPaceSecs(isset($p['typical_easy_pace_max']) ? (int)$p['typical_easy_pace_max'] : null)) ?>">
        </div>
    </div>
</div>

<!-- AVAILABILITY -->
<div class="section-label">AVAILABILITY</div>
<div class="card" style="margin-bottom:16px;">
    <div class="form-group">
        <label class="form-label">Days per week running</label>
        <div class="pill-choices">
            <?php for ($n = 3; $n <= 7; $n++): ?>
            <label class="pill-choice <?= ((int)($p['training_days_per_week'] ?? 0)) === $n ? 'selected' : '' ?>">
                <input type="radio" name="training_days_per_week" value="<?= $n ?>"
                       <?= ((int)($p['training_days_per_week'] ?? 0)) === $n ? 'checked' : '' ?>>
                <?= $n ?> days
            </label>
            <?php endfor; ?>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Must-off days</label>
        <div class="form-hint" style="margin-bottom:8px;">Tap any days you can never train.</div>
        <div class="day-picker" id="mustOffPicker">
            <?php foreach ($days as $i => $label): ?>
            <button type="button" class="day-btn <?= in_array((string)$i, array_map('strval', $mustOff), true) ? 'selected must-off' : '' ?>"
                    data-day="<?= $i ?>"><?= h(substr($label, 0, 2)) ?></button>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="must_off_days" id="mustOffInput" value="<?= h($mustOffNorm) ?>">
    </div>

    <div class="form-group">
        <label class="form-label">Scheduling preference</label>
        <div class="pill-choices">
            <label class="pill-choice <?= $sched === 'fixed' ? 'selected' : '' ?>">
                <input type="radio" name="scheduling_preference" value="fixed" <?= $sched === 'fixed' ? 'checked' : '' ?>>
                Fixed days
            </label>
            <label class="pill-choice <?= $sched !== 'fixed' ? 'selected' : '' ?>">
                <input type="radio" name="scheduling_preference" value="flex" <?= $sched !== 'fixed' ? 'checked' : '' ?>>
                Flexible
            </label>
        </div>
    </div>

    <div id="fixedDayFields" style="<?= $sched === 'fixed' ? '' : 'display:none;' ?>margin-bottom:0;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Long run day</label>
                <select name="long_run_day" class="form-select">
                    <?php foreach ($days as $i => $label): ?>
                    <option value="<?= $i ?>" <?= (isset($p['long_run_day']) && (int)$p['long_run_day'] === $i) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Primary workout day</label>
                <select name="primary_workout_day" class="form-select">
                    <?php foreach ($days as $i => $label): ?>
                    <option value="<?= $i ?>" <?= (isset($p['primary_workout_day']) && (int)$p['primary_workout_day'] === $i) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- HISTORY -->
<div class="section-label">HISTORY</div>
<div class="card" style="margin-bottom:16px;">
    <div class="form-group">
        <label class="form-label" for="injury_history">Injury history</label>
        <textarea id="injury_history" name="injury_history" class="form-input" rows="3"
                  placeholder="Anything we should know about (past or recurring injuries)."><?= h($p['injury_history'] ?? '') ?></textarea>
    </div>
    <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">Track access</label>
        <div class="pill-choices">
            <?php $trackOpts = ['yes' => 'Yes', 'no' => 'No', 'road_reps_ok' => 'Road reps OK']; ?>
            <?php foreach ($trackOpts as $val => $label): ?>
            <label class="pill-choice <?= ($p['track_access'] ?? 'road_reps_ok') === $val ? 'selected' : '' ?>">
                <input type="radio" name="track_access" value="<?= h($val) ?>"
                       <?= ($p['track_access'] ?? 'road_reps_ok') === $val ? 'checked' : '' ?>>
                <?= h($label) ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- CROSS-TRAINING -->
<div class="section-label">CROSS-TRAINING EQUIPMENT</div>
<div class="card" style="margin-bottom:16px;">
    <div class="form-group">
        <label class="form-label">Bike</label>
        <div class="pill-choices">
            <?php $bikeOpts = ['none' => 'None', 'stationary' => 'Stationary / trainer', 'road_gravel' => 'Road / gravel']; ?>
            <?php foreach ($bikeOpts as $val => $label): ?>
            <label class="pill-choice <?= ($p['cross_training_bike'] ?? 'none') === $val ? 'selected' : '' ?>">
                <input type="radio" name="cross_training_bike" value="<?= h($val) ?>"
                       <?= ($p['cross_training_bike'] ?? 'none') === $val ? 'checked' : '' ?>>
                <?= h($label) ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="form-group">
        <label class="form-label">Elliptical</label>
        <div class="pill-choices">
            <?php $elOpts = ['none' => 'None', 'gym' => 'Gym access', 'home' => 'Home elliptical']; ?>
            <?php foreach ($elOpts as $val => $label): ?>
            <label class="pill-choice <?= ($p['cross_training_elliptical'] ?? 'none') === $val ? 'selected' : '' ?>">
                <input type="radio" name="cross_training_elliptical" value="<?= h($val) ?>"
                       <?= ($p['cross_training_elliptical'] ?? 'none') === $val ? 'checked' : '' ?>>
                <?= h($label) ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="form-group">
        <label class="toggle-wrap" style="cursor:pointer;">
            <span>Pool access (pool running or swimming)</span>
            <div class="toggle">
                <input type="checkbox" name="cross_training_pool" value="1" <?= !empty($p['cross_training_pool']) ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </div>
        </label>
    </div>
    <div class="form-group" style="margin-bottom:0;">
        <label class="form-label" for="cross_training_other">Other equipment / notes</label>
        <input type="text" id="cross_training_other" name="cross_training_other" class="form-input"
               placeholder="e.g. rowing machine, aqua jogger"
               value="<?= h($p['cross_training_other'] ?? '') ?>">
    </div>
</div>

<script>
(function () {
    var picker = document.getElementById('mustOffPicker');
    var input  = document.getElementById('mustOffInput');
    if (picker && input) {
        picker.querySelectorAll('.day-btn').forEach(function (btn) {
            // app.js owns the .selected toggle and the hidden-input sync for every
            // .day-picker; here we only add the cosmetic .must-off modifier in sync
            // (matching the onboarding day picker). Toggling .selected here too would
            // double-toggle against app.js and silently revert the selection.
            btn.addEventListener('click', function () {
                btn.classList.toggle('must-off');
            });
        });
    }
    var schedRad = document.querySelectorAll('input[name="scheduling_preference"]');
    var fixedDiv = document.getElementById('fixedDayFields');
    function updateFixed() {
        var v = document.querySelector('input[name="scheduling_preference"]:checked');
        if (fixedDiv) fixedDiv.style.display = (v && v.value === 'fixed') ? '' : 'none';
    }
    schedRad.forEach(function (r) { r.addEventListener('change', updateFixed); });
    updateFixed();
})();
</script>

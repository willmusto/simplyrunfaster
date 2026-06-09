<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle   = 'Availability';
$currentStep = 4;
$totalSteps  = 6;
$d           = $_SESSION['onboarding_data'] ?? [];
$mustOff     = json_decode($d['must_off_days'] ?? '[]', true) ?: [];
$days        = ['Su','Mo','Tu','We','Th','Fr','Sa'];
include __DIR__ . '/../../views/layout/html_open.php';
?>
<div class="onboarding-page">
    <div class="onboarding-progress">
        <div class="onboarding-steps">
            <?php for ($i = 1; $i <= $totalSteps; $i++): ?>
            <div class="onboarding-step-dot <?= $i < $currentStep ? 'done' : ($i === $currentStep ? 'current' : '') ?>"></div>
            <?php endfor; ?>
        </div>
        <div class="onboarding-step-label">Step <?= $currentStep ?> of <?= $totalSteps ?></div>
    </div>

    <form method="POST" action="/app/onboarding/4" class="onboarding-content" id="step4Form">
        <?= Auth::csrfField() ?>

        <?php if ($error): ?>
        <div class="flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <h1 class="onboarding-heading">Your schedule</h1>
        <p class="onboarding-subheading">
            The engine builds your plan around your life, not the other way around.
        </p>

        <div class="form-group">
            <label class="form-label">How many days per week are you running?</label>
            <div class="pill-choices">
                <?php for ($n = 3; $n <= 7; $n++): ?>
                <label class="pill-choice <?= ((int)($d['training_days_per_week'] ?? 0)) === $n ? 'selected' : '' ?>">
                    <input type="radio" name="training_days_per_week" value="<?= $n ?>"
                           <?= ((int)($d['training_days_per_week'] ?? 0)) === $n ? 'checked' : '' ?> required>
                    <?= $n ?> days
                </label>
                <?php endfor; ?>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Which days are absolute rest days?</label>
            <div class="form-hint" style="margin-bottom:8px;">Tap to mark a day as must-off. You can override these later, but they're off by default.</div>
            <div class="day-picker" id="mustOffPicker">
                <?php foreach ($days as $i => $label): ?>
                <button type="button" class="day-btn <?= in_array((string)$i, $mustOff) ? 'selected must-off' : '' ?>"
                        data-day="<?= $i ?>"><?= $label ?></button>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="must_off_days" id="mustOffInput"
                   value="<?= h($d['must_off_days'] ?? '[]') ?>">
        </div>

        <div class="divider"></div>

        <div class="form-group">
            <label class="form-label">Scheduling preference</label>
            <div class="pill-choices">
                <label class="pill-choice <?= ($d['scheduling_preference'] ?? 'flex') === 'fixed' ? 'selected' : '' ?>">
                    <input type="radio" name="scheduling_preference" value="fixed"
                           <?= ($d['scheduling_preference'] ?? '') === 'fixed' ? 'checked' : '' ?>>
                    Fixed days (I want the same days each week)
                </label>
                <label class="pill-choice <?= ($d['scheduling_preference'] ?? 'flex') === 'flex' ? 'selected' : '' ?>">
                    <input type="radio" name="scheduling_preference" value="flex"
                           <?= ($d['scheduling_preference'] ?? 'flex') === 'flex' ? 'checked' : '' ?>>
                    Flexible (engine picks the best days)
                </label>
            </div>
        </div>

        <div id="fixedDayFields" style="display:none;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label class="form-label">Long run day</label>
                    <select name="long_run_day" class="form-select">
                        <?php foreach ($days as $i => $label): ?>
                        <option value="<?= $i ?>" <?= ((int)($d['long_run_day'] ?? -1)) === $i ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Primary workout day</label>
                    <select name="primary_workout_day" class="form-select">
                        <?php foreach ($days as $i => $label): ?>
                        <option value="<?= $i ?>" <?= ((int)($d['primary_workout_day'] ?? -1)) === $i ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Track access</label>
            <div class="pill-choices">
                <?php $trackOpts = ['yes' => 'Yes, I have track access', 'road_reps_ok' => 'Road repeats work fine', 'no' => 'No track or road repeats']; ?>
                <?php foreach ($trackOpts as $val => $label): ?>
                <label class="pill-choice <?= ($d['track_access'] ?? 'road_reps_ok') === $val ? 'selected' : '' ?>">
                    <input type="radio" name="track_access" value="<?= h($val) ?>"
                           <?= ($d['track_access'] ?? 'road_reps_ok') === $val ? 'checked' : '' ?>>
                    <?= h($label) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </form>

    <div class="onboarding-footer">
        <a href="/app/onboarding/3" class="btn btn-secondary">← Back</a>
        <button type="submit" form="step4Form" class="btn btn-primary">Continue →</button>
    </div>
</div>

<script>
(function () {
    var picker   = document.getElementById('mustOffPicker');
    var input    = document.getElementById('mustOffInput');
    var schedRad = document.querySelectorAll('input[name="scheduling_preference"]');
    var fixedDiv = document.getElementById('fixedDayFields');

    // Override day-picker to also toggle must-off class
    picker.querySelectorAll('.day-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            btn.classList.toggle('must-off');
            var selected = Array.from(picker.querySelectorAll('.day-btn.selected')).map(b => b.dataset.day);
            input.value  = JSON.stringify(selected);
        });
    });

    function updateFixed() {
        var val = document.querySelector('input[name="scheduling_preference"]:checked');
        fixedDiv.style.display = (val && val.value === 'fixed') ? '' : 'none';
    }
    schedRad.forEach(function(r) { r.addEventListener('change', updateFixed); });
    updateFixed();
})();
</script>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

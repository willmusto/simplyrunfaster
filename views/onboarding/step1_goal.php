<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle   = 'Your goal';
$currentStep = 1;
$totalSteps  = 6;
$d           = $_SESSION['onboarding_data'] ?? [];
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

    <form method="POST" action="/onboarding/1" class="onboarding-content" id="step1Form">
        <?= Auth::csrfField() ?>

        <?php if ($error): ?>
        <div class="flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <h1 class="onboarding-heading">What's your goal?</h1>
        <p class="onboarding-subheading">This shapes your entire training plan.</p>

        <div class="goal-card-group" style="margin-bottom:20px;">
            <label class="goal-card <?= ($d['plan_type'] ?? '') === 'race_cycle' ? 'selected' : '' ?>">
                <input type="radio" name="plan_type" value="race_cycle"
                       <?= ($d['plan_type'] ?? '') === 'race_cycle' ? 'checked' : '' ?> required>
                <span class="goal-card-icon">🏁</span>
                <div>
                    <div class="goal-card-title">Training for a race</div>
                    <div class="goal-card-desc">I have a specific goal race in mind</div>
                </div>
            </label>

            <label class="goal-card <?= ($d['plan_type'] ?? '') === 'development_plan' ? 'selected' : '' ?>">
                <input type="radio" name="plan_type" value="development_plan"
                       <?= ($d['plan_type'] ?? '') === 'development_plan' ? 'checked' : '' ?>>
                <span class="goal-card-icon">📈</span>
                <div>
                    <div class="goal-card-title">Get fitter and run consistently</div>
                    <div class="goal-card-desc">No race planned — I want to build fitness</div>
                </div>
            </label>

            <label class="goal-card <?= ($d['plan_type'] ?? '') === 'return_to_running' ? 'selected' : '' ?>">
                <input type="radio" name="plan_type" value="return_to_running"
                       <?= ($d['plan_type'] ?? '') === 'return_to_running' ? 'checked' : '' ?>>
                <span class="goal-card-icon">🔄</span>
                <div>
                    <div class="goal-card-title">Returning from injury or time off</div>
                    <div class="goal-card-desc">I've been away from running and want to get back</div>
                </div>
            </label>
        </div>

        <!-- Race-cycle additional fields (shown/hidden by JS) -->
        <div id="raceFields" style="display:none;">
            <div class="divider"></div>
            <h2 style="font-size:16px;font-weight:500;margin-bottom:16px;">Tell me about your race</h2>

            <div class="form-group">
                <label class="form-label">Race distance</label>
                <div class="pill-choices">
                    <?php foreach (['5K','10K','15K','Half Marathon','Marathon'] as $dist): ?>
                    <label class="pill-choice <?= ($d['goal_race_distance'] ?? '') === $dist ? 'selected' : '' ?>">
                        <input type="radio" name="goal_race_distance" value="<?= h($dist) ?>"
                               <?= ($d['goal_race_distance'] ?? '') === $dist ? 'checked' : '' ?>>
                        <?= h($dist) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="goal_race_date">Race date</label>
                <input type="date" id="goal_race_date" name="goal_race_date" class="form-input"
                       value="<?= h($d['goal_race_date'] ?? '') ?>"
                       min="<?= date('Y-m-d', strtotime('+4 weeks')) ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="goal_finish_time">
                    Goal finish time
                    <span style="font-weight:400;color:var(--text-muted);"> — optional</span>
                </label>
                <input type="text" id="goal_finish_time" name="goal_finish_time" class="form-input"
                       placeholder="e.g. 1:45:00"
                       value="<?= h($d['goal_finish_time'] ?? '') ?>">
                <div class="form-hint">Leave blank if your goal is to finish, not a specific time.</div>
            </div>
        </div>

        <!-- Return-to-running fields -->
        <div id="returnFields" style="display:none;">
            <div class="divider"></div>
            <h2 style="font-size:16px;font-weight:500;margin-bottom:16px;">Time away from running</h2>

            <div class="form-group">
                <label class="form-label">How long have you been away?</label>
                <div class="pill-choices" style="flex-direction:column;">
                    <?php $bands = [
                        '1_2_weeks'     => 'Less than 2 weeks',
                        '2_6_weeks'     => '2–6 weeks',
                        '6_16_weeks'    => '6–16 weeks',
                        '4_12_months'   => '4–12 months',
                        '12_plus_months'=> 'More than a year',
                    ]; ?>
                    <?php foreach ($bands as $val => $label): ?>
                    <label class="pill-choice <?= ($d['return_time_off_band'] ?? '') === $val ? 'selected' : '' ?>">
                        <input type="radio" name="time_off_band" value="<?= h($val) ?>"
                               <?= ($d['return_time_off_band'] ?? '') === $val ? 'checked' : '' ?>>
                        <?= h($label) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="divider"></div>
            <h2 style="font-size:15px;font-weight:500;margin-bottom:12px;">Before we begin</h2>
            <div style="background:var(--recessed-bg);border-radius:var(--radius-sm);padding:14px;margin-bottom:16px;">
                <label class="toggle-wrap" style="cursor:pointer;margin-bottom:10px;">
                    <span style="font-size:13px;line-height:1.5;flex:1;">
                        I have been cleared by a medical professional to return to running, OR I am returning
                        from a non-injury break and do not require medical clearance.
                    </span>
                    <input type="checkbox" name="medical_clearance_1" id="mc1"
                           <?= !empty($d['medical_clearance_confirmed']) ? 'checked' : '' ?>
                           style="width:18px;height:18px;flex-shrink:0;">
                </label>
                <label class="toggle-wrap" style="cursor:pointer;">
                    <span style="font-size:13px;line-height:1.5;flex:1;">
                        I understand that this plan is conservative by design and I will communicate with my coach
                        if I experience any pain or discomfort.
                    </span>
                    <input type="checkbox" name="medical_clearance_2" id="mc2"
                           <?= !empty($d['medical_clearance_confirmed']) ? 'checked' : '' ?>
                           style="width:18px;height:18px;flex-shrink:0;">
                </label>
            </div>
        </div>
    </form>

    <div class="onboarding-footer">
        <div></div>
        <button type="submit" form="step1Form" class="btn btn-primary">
            Continue →
        </button>
    </div>
</div>

<script>
(function () {
    var radios = document.querySelectorAll('input[name="plan_type"]');
    var raceFields   = document.getElementById('raceFields');
    var returnFields = document.getElementById('returnFields');

    function update() {
        var val = document.querySelector('input[name="plan_type"]:checked');
        raceFields.style.display   = (val && val.value === 'race_cycle')         ? '' : 'none';
        returnFields.style.display = (val && val.value === 'return_to_running')  ? '' : 'none';
    }

    radios.forEach(function(r) { r.addEventListener('change', update); });
    update();
})();
</script>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

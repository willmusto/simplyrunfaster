<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle   = 'Watch setup';
$currentStep = 5;
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

    <form method="POST" action="/app/onboarding/5" class="onboarding-content" id="step5Form">
        <?= Auth::csrfField() ?>

        <h1 class="onboarding-heading">Watch setup</h1>
        <p class="onboarding-subheading">
            A watch improves data quality but the platform works fully without one.
            <strong>This step is completely optional.</strong>
        </p>

        <div class="form-group">
            <label class="form-label">Do you have a GPS watch?</label>
            <div class="pill-choices">
                <?php $platforms = [
                    'garmin' => 'Garmin',
                    'polar'  => 'Polar',
                    'apple'  => 'Apple Watch',
                    'wahoo'  => 'Wahoo',
                    'none'   => 'No watch / manual logging',
                ]; ?>
                <?php foreach ($platforms as $val => $label): ?>
                <label class="pill-choice <?= ($d['watch_platform'] ?? 'none') === $val ? 'selected' : '' ?>">
                    <input type="radio" name="watch_platform" value="<?= h($val) ?>"
                           <?= ($d['watch_platform'] ?? 'none') === $val ? 'checked' : '' ?>>
                    <?= h($label) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="watchConnectSection" style="display:none;">
            <div class="card" style="margin-bottom:16px;">
                <div class="card-title" style="margin-bottom:6px;">Connect your watch</div>
                <p class="body-text" style="margin-bottom:12px;">
                    Watch connection happens in your account settings after onboarding.
                    Your coach will be notified once it's connected.
                </p>
                <span class="pill pill-info">Connection available in Settings after setup</span>
            </div>
        </div>

        <div class="divider"></div>

        <h2 style="font-size:15px;font-weight:500;margin-bottom:12px;">Cross-training equipment</h2>
        <p class="body-text" style="margin-bottom:16px;">
            Used to fill recovery days and return-to-running off days.
        </p>

        <div class="form-group">
            <label class="form-label">Bike</label>
            <div class="pill-choices">
                <?php $bikeOpts = ['none' => 'None', 'stationary' => 'Stationary / trainer', 'road_gravel' => 'Road or gravel bike']; ?>
                <?php foreach ($bikeOpts as $val => $label): ?>
                <label class="pill-choice <?= ($d['cross_training_bike'] ?? 'none') === $val ? 'selected' : '' ?>">
                    <input type="radio" name="cross_training_bike" value="<?= h($val) ?>"
                           <?= ($d['cross_training_bike'] ?? 'none') === $val ? 'checked' : '' ?>>
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
                <label class="pill-choice <?= ($d['cross_training_elliptical'] ?? 'none') === $val ? 'selected' : '' ?>">
                    <input type="radio" name="cross_training_elliptical" value="<?= h($val) ?>"
                           <?= ($d['cross_training_elliptical'] ?? 'none') === $val ? 'checked' : '' ?>>
                    <?= h($label) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label class="toggle-wrap" style="cursor:pointer;">
                <span>Pool access (for pool running or swimming)</span>
                <div class="toggle">
                    <input type="checkbox" name="cross_training_pool" value="1"
                           <?= !empty($d['cross_training_pool']) ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </div>
            </label>
        </div>
    </form>

    <div class="onboarding-footer">
        <a href="/app/onboarding/4" class="btn btn-secondary">← Back</a>
        <button type="submit" form="step5Form" class="btn btn-primary">Continue →</button>
    </div>
</div>

<script>
(function () {
    var radios  = document.querySelectorAll('input[name="watch_platform"]');
    var section = document.getElementById('watchConnectSection');

    function update() {
        var val = document.querySelector('input[name="watch_platform"]:checked');
        section.style.display = (val && val.value !== 'none') ? '' : 'none';
    }

    radios.forEach(function(r) { r.addEventListener('change', update); });
    update();
})();
</script>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

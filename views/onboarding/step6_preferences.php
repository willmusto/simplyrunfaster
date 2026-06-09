<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle   = 'Preferences';
$currentStep = 6;
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

    <form method="POST" action="/app/onboarding/6" class="onboarding-content" id="step6Form">
        <?= Auth::csrfField() ?>

        <h1 class="onboarding-heading">Almost done</h1>
        <p class="onboarding-subheading">
            A couple of final preferences, then your coach takes over.
        </p>

        <div class="form-group">
            <label class="form-label">Distance units</label>
            <div class="pill-choices">
                <label class="pill-choice <?= ($d['units'] ?? 'miles') === 'miles' ? 'selected' : '' ?>">
                    <input type="radio" name="units" value="miles"
                           <?= ($d['units'] ?? 'miles') === 'miles' ? 'checked' : '' ?>>
                    Miles
                </label>
                <label class="pill-choice <?= ($d['units'] ?? 'miles') === 'km' ? 'selected' : '' ?>">
                    <input type="radio" name="units" value="km"
                           <?= ($d['units'] ?? 'miles') === 'km' ? 'checked' : '' ?>>
                    Kilometers
                </label>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Display theme</label>
            <div class="pill-choices">
                <?php $themes = ['system' => 'System default', 'light' => 'Light', 'dark' => 'Dark']; ?>
                <?php foreach ($themes as $val => $label): ?>
                <label class="pill-choice <?= ($d['theme'] ?? 'system') === $val ? 'selected' : '' ?>">
                    <input type="radio" name="theme" value="<?= h($val) ?>"
                           <?= ($d['theme'] ?? 'system') === $val ? 'checked' : '' ?>>
                    <?= h($label) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="form-hint">You can change this anytime from Settings.</div>
        </div>

        <div class="card" style="margin-top:24px;background:var(--recessed-bg);">
            <div class="section-label">WHAT HAPPENS NEXT</div>
            <div class="body-text">
                <p style="margin-bottom:8px;">
                    Your coach has been notified. They'll review your profile and build your
                    training plan. Nothing appears in your account until your coach approves it.
                </p>
                <p>
                    Your coach will be in touch to schedule a short onboarding call. That's where
                    you can ask questions and they can fill in anything the form missed.
                </p>
            </div>
        </div>
    </form>

    <div class="onboarding-footer">
        <a href="/app/onboarding/5" class="btn btn-secondary">← Back</a>
        <button type="submit" form="step6Form" class="btn btn-primary">
            Finish setup
        </button>
    </div>
</div>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

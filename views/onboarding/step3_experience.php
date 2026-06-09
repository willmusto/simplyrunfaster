<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle   = 'Experience';
$currentStep = 3;
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

    <form method="POST" action="/onboarding/3" class="onboarding-content" id="step3Form">
        <?= Auth::csrfField() ?>

        <?php if ($error): ?>
        <div class="flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <h1 class="onboarding-heading">Your running history</h1>
        <p class="onboarding-subheading">
            Context for your coach and the engine. This doesn't change your plan goal —
            it shapes how we get you there.
        </p>

        <div class="form-group">
            <label class="form-label" for="years_running">How many years have you been running?</label>
            <input type="number" id="years_running" name="years_running" class="form-input"
                   placeholder="e.g. 3" step="0.5" min="0" max="60" required
                   value="<?= h($d['years_running'] ?? '') ?>">
            <div class="form-hint">Estimate is fine — 0.5 = about 6 months.</div>
        </div>

        <div class="form-group">
            <label class="form-label" for="peak_weekly_minutes">
                What's the most you've ever trained in a week (minutes)?
                <span style="font-weight:400;color:var(--text-muted);"> — optional</span>
            </label>
            <input type="number" id="peak_weekly_minutes" name="peak_weekly_minutes"
                   class="form-input" placeholder="e.g. 300" min="0" max="1800"
                   value="<?= h($d['peak_weekly_minutes'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label class="form-label" for="injury_history">
                Any injury history?
                <span style="font-weight:400;color:var(--text-muted);"> — optional</span>
            </label>
            <textarea id="injury_history" name="injury_history" class="form-textarea"
                      placeholder="e.g. IT band issues in 2022, nothing recent. Or: none."
                      rows="3"><?= h($d['injury_history'] ?? '') ?></textarea>
            <div class="form-hint">Your coach reads this. Be specific if there's anything ongoing.</div>
        </div>

        <div class="form-group">
            <label class="form-label">How would you describe your running background?</label>
            <div class="pill-choices">
                <?php $levels = [
                    'beginner'     => 'Beginner',
                    'intermediate' => 'Intermediate',
                    'advanced'     => 'Advanced / competitive',
                ]; ?>
                <?php foreach ($levels as $val => $label): ?>
                <label class="pill-choice <?= ($d['experience_level'] ?? 'intermediate') === $val ? 'selected' : '' ?>">
                    <input type="radio" name="experience_level" value="<?= h($val) ?>"
                           <?= ($d['experience_level'] ?? 'intermediate') === $val ? 'checked' : '' ?>>
                    <?= h($label) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </form>

    <div class="onboarding-footer">
        <a href="/onboarding/2" class="btn btn-secondary">← Back</a>
        <button type="submit" form="step3Form" class="btn btn-primary">Continue →</button>
    </div>
</div>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

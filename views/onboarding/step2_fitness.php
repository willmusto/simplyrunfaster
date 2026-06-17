<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle   = 'Current fitness';
$currentStep = 2;
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

    <form method="POST" action="/app/onboarding/2" class="onboarding-content" id="step2Form">
        <?= Auth::csrfField() ?>

        <?php if ($error): ?>
        <div class="flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <h1 class="onboarding-heading">Your current fitness</h1>
        <p class="onboarding-subheading">
            This tells the engine where to start. Be honest: starting conservatively keeps you healthy.
        </p>

        <?php if (!empty($d['is_hyrox'])): ?>
        <div class="card" style="margin-bottom:16px;border-left:3px solid var(--accent-mid);">
            <div style="font-size:14px;font-weight:600;margin-bottom:4px;">Training for Hyrox</div>
            <p class="body-text" style="margin:0;font-size:13px;">
                SimplyRunFaster will build your running fitness for Hyrox. For the functional fitness
                stations (rowing, sleds, burpees, sandbags, wall balls, and lunges), we recommend joining
                a CrossFit box or functional fitness gym to supplement your running training.
            </p>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label" for="current_weekly_minutes">
                How many minutes per week are you running right now?
            </label>
            <input type="number" id="current_weekly_minutes" name="current_weekly_minutes"
                   class="form-input" placeholder="e.g. 180" min="0" max="1200" required
                   value="<?= h($d['current_weekly_minutes'] ?? '') ?>">
            <div class="form-hint">Include all running, including warm-ups and cool-downs.</div>
        </div>

        <div class="form-group">
            <label class="form-label" for="longest_recent_run_mins">
                What's the longest run you've done in the last 30 days (minutes)?
            </label>
            <input type="number" id="longest_recent_run_mins" name="longest_recent_run_mins"
                   class="form-input" placeholder="e.g. 75" min="0" max="600" required
                   value="<?= h($d['longest_recent_run_mins'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label class="form-label" for="months_at_current_volume">
                How long have you been training at roughly this volume (months)?
            </label>
            <input type="number" id="months_at_current_volume" name="months_at_current_volume"
                   class="form-input" placeholder="e.g. 3" min="0" max="120" required
                   value="<?= h($d['months_at_current_volume'] ?? '') ?>">
        </div>

        <div class="divider"></div>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">
            Optional. Helps set your starting pace zones.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label class="form-label">Most recent race distance</label>
                <select name="recent_race_distance" class="form-select">
                    <option value="">-- skip --</option>
                    <?php foreach (['5K','10K','15K','Half Marathon','Marathon'] as $d2): ?>
                    <option value="<?= h($d2) ?>" <?= ($d['most_recent_race_distance'] ?? '') === $d2 ? 'selected' : '' ?>>
                        <?= h($d2) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Finish time (H:MM:SS)</label>
                <input type="text" name="recent_race_time" class="form-input"
                       placeholder="e.g. 0:23:15"
                       value="<?php
                           $secs = $d['most_recent_race_time'] ?? 0;
                           if ($secs) {
                               printf('%d:%02d:%02d', intdiv($secs,3600), intdiv($secs%3600,60), $secs%60);
                           }
                       ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="recent_race_date">Race date</label>
            <input type="date" id="recent_race_date" name="recent_race_date" class="form-input"
                   value="<?= h($d['most_recent_race_date'] ?? '') ?>">
        </div>

        <!-- Easy-pace fallback: shown only when no race result is provided -->
        <?php
        $easyMin = isset($d['typical_easy_pace_min']) ? (int)$d['typical_easy_pace_min'] : null;
        $easyMax = isset($d['typical_easy_pace_max']) ? (int)$d['typical_easy_pace_max'] : null;
        $fmtPace = fn($s) => $s ? sprintf('%d:%02d', intdiv($s, 60), $s % 60) : '';
        $hasRaceData = !empty($d['most_recent_race_distance']);
        ?>
        <div id="easyPaceSection" style="<?= $hasRaceData ? 'display:none;' : '' ?>">
            <div class="divider"></div>
            <div class="form-group">
                <label class="form-label">Roughly what pace do you run your easy days?</label>
                <div class="form-hint" style="margin-bottom:8px;">
                    A range is fine. This lets us estimate your pace assignments until you log a race result.
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <input type="text" name="typical_easy_pace_min" class="form-input"
                           placeholder="Faster, e.g. 9:00" value="<?= h($fmtPace($easyMin)) ?>">
                    <input type="text" name="typical_easy_pace_max" class="form-input"
                           placeholder="Slower, e.g. 10:00" value="<?= h($fmtPace($easyMax)) ?>">
                </div>
            </div>
        </div>
    </form>

    <div class="onboarding-footer">
        <a href="/app/onboarding/1" class="btn btn-secondary">← Back</a>
        <button type="submit" form="step2Form" class="btn btn-primary">Continue →</button>
    </div>
</div>

<script>
(function () {
    var distSel = document.querySelector('select[name="recent_race_distance"]');
    var section = document.getElementById('easyPaceSection');
    function update() {
        if (!section) return;
        section.style.display = (distSel && distSel.value) ? 'none' : '';
    }
    if (distSel) distSel.addEventListener('change', update);
    update();
})();
</script>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

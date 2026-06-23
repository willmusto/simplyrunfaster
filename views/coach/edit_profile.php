<?php
// $athlete, $profile, $success, $error, $formAction, $cancelUrl
$pzSource   = $profile['pace_zones_source'] ?? null;
$pzHasZones = PaceZones::isPopulated($profile['pace_zones'] ?? null);
$pzVisible  = !isset($profile['pace_zones_visible']) || (int)$profile['pace_zones_visible'] === 1;
?>
<div class="page-content">
    <?php if (!empty($chrome)): ?>
    <!-- Shared chrome: back + header + sub-nav tab strip -->
    <?php include __DIR__ . '/partials/athlete_chrome.php'; ?>
    <?php else: ?>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
        <a href="<?= h($cancelUrl) ?>" style="color:var(--text-muted);text-decoration:none;font-size:20px;">←</a>
        <div class="page-heading" style="margin-bottom:0;">Edit Profile: <?= h($athlete['name']) ?></div>
    </div>
    <?php endif; ?>
    <p class="body-text" style="margin:-6px 0 20px;color:var(--text-muted);">
        Editing saves the athlete's profile for the next plan generation. It does not rebuild the active plan.
    </p>

    <?php if ($success): ?>
    <div class="flash flash-success"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= h($formAction) ?>" data-dirty-watch>
        <?= Auth::csrfField() ?>
        <?php include __DIR__ . '/../partials/profile_form_fields.php'; ?>

        <!-- COACH-ONLY -->
        <div class="section-label">COACH CONTROLS</div>
        <div class="card" style="margin-bottom:16px;">
            <div class="form-group">
                <label class="form-label" for="plan_type">Plan type</label>
                <?php
                $planOpts = [
                    'race_cycle'        => 'Race cycle',
                    'development_plan'  => 'Development',
                    'maintenance_plan'  => 'Maintenance',
                    'return_to_running' => 'Return to running',
                ];
                $curPlan = $profile['plan_type'] ?? '';
                // recovery_block is engine-managed; surface it only if currently set so
                // opening the form never silently changes it.
                if ($curPlan === 'recovery_block') $planOpts['recovery_block'] = 'Recovery block (engine-managed)';
                ?>
                <select id="plan_type" name="plan_type" class="form-select" data-plan-type>
                    <?php foreach ($planOpts as $val => $label): ?>
                    <option value="<?= h($val) ?>" <?= $curPlan === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint">Takes effect on the next plan generation — does not rebuild the active plan.</div>
            </div>

            <div class="form-group">
                <label class="toggle-wrap" style="cursor:pointer;">
                    <span>Plyometric clearance</span>
                    <div class="toggle">
                        <input type="checkbox" name="plyometric_clearance" value="1" <?= !empty($profile['plyometric_clearance']) ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </div>
                </label>
                <div class="form-hint" style="margin-top:6px;">Cleared for bounding / plyometric work — a higher-injury-risk readiness gate; required before any plyometric archetype can be prescribed.</div>
            </div>

            <div class="form-group">
                <label class="form-label" for="peak_volume_ceiling_mins">Peak volume ceiling (minutes/week)</label>
                <input type="number" id="peak_volume_ceiling_mins" name="peak_volume_ceiling_mins" class="form-input"
                       min="0" max="2000" placeholder="engine never exceeds this"
                       value="<?= h($profile['peak_volume_ceiling_mins'] ?? '') ?>">
                <div class="form-hint">The engine never schedules above this weekly ceiling.</div>
            </div>

            <div class="form-group">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <span style="font-size:12px;color:var(--text-muted);">Pace-zone basis</span>
                    <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--text-secondary);">
                        <?php
                        if (!$pzHasZones) {
                            echo 'No zones yet';
                        } elseif ($pzSource === 'race_result') {
                            echo 'Verified: race result';
                        } elseif ($pzSource === 'easy_pace_estimate') {
                            echo 'Estimated: easy pace';
                        } elseif ($pzSource === 'manual') {
                            echo 'Manual: coach set';
                        } else {
                            echo 'Set';
                        }
                        ?>
                    </span>
                </div>
                <?php if ($pzHasZones && $pzSource === 'easy_pace_estimate'): ?>
                <div class="form-hint" style="margin-top:6px;">
                    Zones are <strong>estimated</strong> from the athlete's typical easy pace. They become verified
                    automatically when a race result is logged and recalibration is approved.
                </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="toggle-wrap" style="cursor:pointer;">
                    <span>Pace zones visible to athlete</span>
                    <div class="toggle">
                        <input type="checkbox" name="pace_zones_visible" id="pzVisibleToggle" value="1" <?= $pzVisible ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </div>
                </label>
            </div>

            <div class="form-group" id="pzHiddenReasonWrap" style="margin-bottom:16px;<?= $pzVisible ? 'display:none;' : '' ?>">
                <label class="form-label" for="pace_zones_hidden_reason">Reason for hiding zones (internal, never shown to athlete)</label>
                <textarea id="pace_zones_hidden_reason" name="pace_zones_hidden_reason" class="form-input" rows="2"
                          placeholder="e.g. stale zones pending recalibration; athlete benefits from effort-based training"><?= h($profile['pace_zones_hidden_reason'] ?? '') ?></textarea>
            </div>

            <?php
            $selectedTz   = $athlete['timezone'] ?? Timezone::DEFAULT_TZ;
            $tzFieldLabel = 'Athlete timezone';
            $tzFieldHint  = 'Governs the athlete\'s plan dates and the day a new plan starts. Overrides the athlete\'s own setting.';
            include __DIR__ . '/../partials/timezone_field.php';
            ?>
        </div>

        <div style="display:flex;gap:10px;margin-top:8px;">
            <button type="submit" class="btn btn-primary" data-dirty-save>Save changes</button>
            <a href="<?= h($cancelUrl) ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    var toggle = document.getElementById('pzVisibleToggle');
    var wrap   = document.getElementById('pzHiddenReasonWrap');
    function update() { if (wrap) wrap.style.display = (toggle && toggle.checked) ? 'none' : ''; }
    if (toggle) toggle.addEventListener('change', update);
    update();

    // plan_type is coach-only and lives here; toggle the Return-to-running block
    // (rendered by the shared partial) as the coach changes the plan type.
    var planSel   = document.querySelector('[data-plan-type]');
    var rtrBlocks = document.querySelectorAll('[data-rtr-block]');
    function updateRtr() {
        var on = !!planSel && planSel.value === 'return_to_running';
        rtrBlocks.forEach(function (el) { el.style.display = on ? '' : 'none'; });
    }
    if (planSel) { planSel.addEventListener('change', updateRtr); updateRtr(); }
})();
</script>

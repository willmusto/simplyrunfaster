<?php
// $recentLog = array of completed_workouts (last 30)
?>
<div class="page-content">
    <div class="page-heading" style="margin-bottom:4px;">Training Log</div>
    <p class="body-text" style="margin-bottom:20px;">What you planned vs. what you did.</p>

    <!-- Manual log CTA -->
    <div style="margin-bottom:20px;">
        <button class="btn btn-primary" onclick="document.getElementById('manualLogForm').style.display='block';this.style.display='none';">
            + Log a workout
        </button>
    </div>

    <!-- Manual log form (hidden by default) -->
    <div id="manualLogForm" style="display:none;" class="card" style="margin-bottom:20px;">
        <div class="card-title" style="margin-bottom:16px;">Log a workout</div>
        <form method="POST" action="/app/log/manual">
            <?= Auth::csrfField() ?>
            <div class="form-group">
                <label class="form-label" for="activity_date">Date</label>
                <input type="date" id="activity_date" name="activity_date" class="form-input"
                       value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">What type of run?</label>
                <div class="pill-choices" style="flex-wrap:wrap;">
                    <?php $types = ['easy','long','interval','hill','fartlek','tempo','race','recovery','cross_train']; ?>
                    <?php foreach ($types as $t): ?>
                    <label class="pill-choice">
                        <input type="radio" name="workout_type" value="<?= h($t) ?>" <?= $t === 'easy' ? 'checked' : '' ?>>
                        <?= pill_label($t) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="actual_duration">Duration (minutes)</label>
                <input type="number" id="actual_duration" name="actual_duration" class="form-input"
                       placeholder="e.g. 45" min="1" max="600" required>
            </div>

            <div class="form-group" id="completionGroup">
                <label class="form-label">Did you complete the workout?</label>
                <div class="pill-choices">
                    <label class="pill-choice selected"><input type="radio" name="completion_status" value="full" checked> Yes</label>
                    <label class="pill-choice"><input type="radio" name="completion_status" value="partial"> Partially</label>
                    <label class="pill-choice"><input type="radio" name="completion_status" value="no"> No</label>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">How did it feel?</label>
                <div class="pill-choices" style="flex-wrap:wrap;">
                    <label class="pill-choice"><input type="radio" name="effort_descriptor" value="easy"> Easy</label>
                    <label class="pill-choice selected"><input type="radio" name="effort_descriptor" value="moderate" checked> Moderate</label>
                    <label class="pill-choice"><input type="radio" name="effort_descriptor" value="hard"> Hard</label>
                    <label class="pill-choice"><input type="radio" name="effort_descriptor" value="very_hard"> Very Hard</label>
                    <?php if (!empty($rtrActive)): ?>
                    <label class="pill-choice"><input type="radio" name="effort_descriptor" value="discomfort"> I felt some discomfort</label>
                    <?php endif; ?>
                </div>
                <?php if (!empty($rtrActive)): ?>
                <div class="form-hint">
                    Returning from a break? If anything hurt or felt off, choose “I felt some discomfort.”
                    We’ll ease your progression back a step and let your coach know.
                </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Notes
                    <span style="font-weight:400;color:var(--text-muted);"> (optional)</span>
                </label>
                <textarea id="notes" name="notes" class="form-textarea" rows="2"
                          placeholder="How it went, how you felt, anything notable..."
                          maxlength="1000"></textarea>
                <div class="form-hint" id="charCount">0 / 500</div>
            </div>

            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary"
                        onclick="document.getElementById('manualLogForm').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>

    <!-- Recent log -->
    <?php if (empty($recentLog)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">🏃</div>
        <div class="empty-state-title">No logged workouts yet</div>
        <p class="body-text">Once you complete workouts, they'll appear here with planned vs. actual comparison.</p>
    </div>
    <?php else: ?>

    <div class="section-label">RECENT ACTIVITY</div>

    <?php foreach ($recentLog as $entry):
        $dateStr = date('D, M j', strtotime($entry['activity_date']));
    ?>
    <div class="card" style="margin-bottom:8px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:8px;">
            <div>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                    <span class="pill <?= pill_class($entry['workout_type'] ?? 'easy') ?>">
                        <?= pill_label($entry['workout_type'] ?? 'easy') ?>
                    </span>
                    <span style="font-size:12px;color:var(--text-muted);"><?= h($dateStr) ?></span>
                    <span class="pill" style="background:var(--recessed-bg);color:var(--text-muted);font-size:10px;">
                        <?= h(ucfirst($entry['source'] ?? 'manual')) ?>
                    </span>
                </div>

                <?php if ($entry['actual_duration']): ?>
                <div style="font-size:13px;color:var(--text-secondary);">
                    <?= format_duration((int)$entry['actual_duration']) ?>
                    <?php if ($entry['actual_distance']): ?>
                    · <?= number_format($entry['actual_distance'], 1) ?> mi
                    <?php endif; ?>
                    <?php if ($entry['avg_hr']): ?>
                    · <?= (int)$entry['avg_hr'] ?> bpm avg
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($entry['compliance_score'] !== null): ?>
            <div class="metric-tile" style="min-width:56px;text-align:center;padding:8px;">
                <div style="font-size:16px;font-weight:600;color:<?=
                    $entry['compliance_score'] >= 0.85 ? 'var(--color-success)' :
                    ($entry['compliance_score'] >= 0.70 ? 'var(--color-warning)' : 'var(--color-danger)')
                ?>;"><?= round($entry['compliance_score'] * 100) ?>%</div>
                <div class="metric-label">compliance</div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($entry['effort_descriptor']): ?>
        <div style="font-size:12px;color:var(--text-muted);">
            Felt: <strong><?= h(ucfirst(str_replace('_', ' ', $entry['effort_descriptor']))) ?></strong>
        </div>
        <?php endif; ?>

        <!-- Session note -->
        <div style="margin-top:10px;padding-top:10px;border-top:var(--card-border);">
            <button class="btn btn-sm btn-secondary"
                    onclick="this.style.display='none';document.getElementById('note-<?= (int)$entry['id'] ?>').style.display='block'">
                + Add note
            </button>
            <div id="note-<?= (int)$entry['id'] ?>" style="display:none;">
                <form method="POST" action="/app/log/note">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="completed_workout_id" value="<?= (int)$entry['id'] ?>">
                    <textarea name="body" class="form-textarea" rows="2" maxlength="1000"
                              placeholder="How did it go? Anything notable…"
                              style="margin-bottom:8px;font-size:13px;min-height:60px;"></textarea>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn btn-primary btn-sm">Save note</button>
                        <button type="button" class="btn btn-sm"
                                style="background:var(--recessed-bg);color:var(--text-muted);"
                                onclick="var f=this.closest('div[id]');f.style.display='none';f.previousElementSibling.style.display=''">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<script>
(function () {
    var notesEl   = document.getElementById('notes');
    var countEl   = document.getElementById('charCount');
    if (!notesEl) return;

    notesEl.addEventListener('input', function () {
        var len = notesEl.value.length;
        countEl.textContent = len + ' / 500';
        countEl.style.color = len > 500 ? 'var(--color-warning)' : '';
    });
})();
</script>

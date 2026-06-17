<?php
// $session = completed_workout row + planned fields; $notes = session_notes thread;
// $coachName, $athlete, $success
$cwId      = (int)$session['id'];
$wname     = trim((string)($session['display_title'] ?? '')) ?: pill_label($session['workout_type'] ?? 'easy');
$dateLabel = date('l · M j', strtotime((string)$session['activity_date']));
$hasPlan   = !empty($session['planned_workout_id']);

$hasCoachReply = false;
foreach ($notes as $n) {
    if (($n['author_role'] ?? 'athlete') !== 'athlete') { $hasCoachReply = true; break; }
}
?>
<div class="page-content">

    <a href="/app/log" style="font-size:13px;color:var(--text-muted);text-decoration:none;">← Back to log</a>

    <div style="display:flex;align-items:center;gap:8px;margin:10px 0 2px;">
        <span class="pill <?= pill_class($session['workout_type'] ?? 'easy') ?>">
            <?= pill_label($session['workout_type'] ?? 'easy') ?>
        </span>
        <span style="font-size:12px;color:var(--text-muted);"><?= h($dateLabel) ?></span>
    </div>
    <div class="page-heading" style="margin-bottom:16px;"><?= h($wname) ?></div>

    <?php if (!empty($success)): ?>
    <div class="flash flash-success"><?= h($success) ?></div>
    <?php endif; ?>

    <!-- ── Planned vs. actual ─────────────────────────────────── -->
    <div class="section-label">SESSION</div>
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;gap:12px;">
            <?php if ($hasPlan): ?>
            <div style="flex:1;">
                <div class="metric-label" style="margin-bottom:6px;">Planned</div>
                <div style="font-size:13px;color:var(--text-secondary);line-height:1.7;">
                    <?php if ($session['planned_duration']): ?>
                    <div><?= format_duration((int)$session['planned_duration']) ?></div>
                    <?php endif; ?>
                    <?php if ($session['planned_distance']): ?>
                    <div><?= number_format((float)$session['planned_distance'], 1) ?> mi</div>
                    <?php endif; ?>
                    <?php if ($session['target_pace_min'] && $session['target_pace_max']): ?>
                    <div><?= format_pace($session['target_pace_min']) ?>–<?= format_pace($session['target_pace_max']) ?>/mi</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div style="flex:1;">
                <div class="metric-label" style="margin-bottom:6px;"><?= $hasPlan ? 'Actual' : 'Logged' ?></div>
                <div style="font-size:13px;color:var(--text-secondary);line-height:1.7;">
                    <?php if ($session['actual_duration']): ?>
                    <div><?= format_duration((int)$session['actual_duration']) ?></div>
                    <?php endif; ?>
                    <?php if ($session['actual_distance']): ?>
                    <div><?= number_format((float)$session['actual_distance'], 1) ?> mi</div>
                    <?php endif; ?>
                    <?php if ($session['avg_hr']): ?>
                    <div><?= (int)$session['avg_hr'] ?> bpm avg</div>
                    <?php endif; ?>
                    <?php if ($session['effort_descriptor']): ?>
                    <div>Felt: <strong><?= h(ucfirst(str_replace('_', ' ', $session['effort_descriptor']))) ?></strong></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($session['compliance_score'] !== null): ?>
            <div class="metric-tile" style="min-width:60px;text-align:center;padding:8px;align-self:flex-start;">
                <div style="font-size:16px;font-weight:600;color:<?=
                    $session['compliance_score'] >= 0.85 ? 'var(--color-success)' :
                    ($session['compliance_score'] >= 0.70 ? 'var(--color-warning)' : 'var(--color-danger)')
                ?>;"><?= round($session['compliance_score'] * 100) ?>%</div>
                <div class="metric-label">compliance</div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($session['planned_desc'])): ?>
        <div style="margin-top:12px;padding-top:12px;border-top:var(--card-border);font-size:13px;color:var(--text-muted);">
            <?= nl2br(h($session['planned_desc'])) ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Session notes ──────────────────────────────────────── -->
    <div class="section-label">SESSION NOTES</div>

    <?php if (empty($notes)): ?>
    <!-- No note yet -->
    <div class="card" style="margin-bottom:16px;" data-note-block>
        <button type="button" class="btn btn-secondary btn-sm" data-note-add-btn>+ Add a note</button>
        <form method="POST" action="/app/log/note" class="note-form" data-note-form hidden style="margin-top:10px;">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="completed_workout_id" value="<?= $cwId ?>">
            <textarea name="body" class="form-textarea" rows="3" maxlength="1000" data-note-input
                      placeholder="How did this session feel? What did you notice?"></textarea>
            <div class="note-counter" data-note-counter hidden></div>
            <div style="display:flex;gap:8px;margin-top:8px;">
                <button type="submit" class="btn btn-primary btn-sm" data-note-save disabled>Save note</button>
                <button type="button" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);"
                        data-note-cancel>Cancel</button>
            </div>
        </form>
    </div>

    <?php else: ?>
    <!-- Thread: athlete note first, coach replies after -->
    <div class="card" style="margin-bottom:16px;">
        <?php foreach ($notes as $n):
            $mine = ($n['author_role'] ?? 'athlete') === 'athlete';
            $when = Timezone::toLocal($n['created_at'])->format('M j · g:ia');
        ?>
        <div class="note-post <?= $mine ? 'note-mine' : 'note-coach' ?>" data-note-id="<?= (int)$n['id'] ?>">
            <div class="note-post-head">
                <span class="note-author"><?= $mine ? 'You' : h($n['author_name'] ?: $coachName) ?></span>
                <span class="note-time"><?= h($when) ?></span>
            </div>
            <div class="note-body" data-note-body><?= nl2br(h($n['body'])) ?></div>
            <?php if ($mine): ?>
            <button type="button" class="note-edit-btn" data-note-edit-btn>Edit</button>
            <form method="POST" action="/app/log/note/edit" class="note-form" data-note-form hidden style="margin-top:8px;">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>">
                <textarea name="body" class="form-textarea" rows="3" maxlength="1000" data-note-input><?= h($n['body']) ?></textarea>
                <div class="note-counter" data-note-counter hidden></div>
                <div style="display:flex;gap:8px;margin-top:8px;">
                    <button type="submit" class="btn btn-primary btn-sm" data-note-save>Save</button>
                    <button type="button" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);"
                            data-note-cancel>Cancel</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($hasCoachReply): ?>
        <!-- Reply to coach -->
        <form method="POST" action="/app/log/note" class="note-form" data-note-form
              style="margin-top:14px;border-top:var(--card-border);padding-top:14px;">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="completed_workout_id" value="<?= $cwId ?>">
            <label class="form-label" style="margin-bottom:6px;">Reply to <?= h($coachName) ?></label>
            <textarea name="body" class="form-textarea" rows="2" maxlength="1000" data-note-input
                      placeholder="Write a reply…"></textarea>
            <div class="note-counter" data-note-counter hidden></div>
            <button type="submit" class="btn btn-primary btn-sm" data-note-save disabled style="margin-top:8px;">
                Send reply
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.note-post { padding:12px 0; border-bottom:var(--card-border); }
.note-post:last-of-type { border-bottom:none; }
.note-post-head { display:flex; align-items:baseline; gap:8px; margin-bottom:4px; }
.note-author { font-size:13px; font-weight:600; color:var(--text-primary); }
.note-coach .note-author { color:var(--accent-mid); }
.note-time { font-size:11px; color:var(--text-muted); }
.note-body { font-size:14px; color:var(--text-secondary); line-height:1.6; white-space:pre-line; }
.note-edit-btn { margin-top:6px; background:none; border:none; padding:0; cursor:pointer;
    font-size:12px; font-weight:600; color:var(--accent-mid); }
.note-counter { margin-top:6px; font-size:11px; color:var(--text-muted); text-align:right; }
.note-counter.is-amber { color:var(--color-warning); }
</style>

<script>
(function () {
    var SOFT = 500, HARD = 1000;
    var forms = document.querySelectorAll('[data-note-form]');
    if (!forms.length) return;

    forms.forEach(function (form) {
        var input   = form.querySelector('[data-note-input]');
        var counter = form.querySelector('[data-note-counter]');
        var saveBtn = form.querySelector('[data-note-save]');
        if (!input) return;

        function refresh() {
            var len = input.value.length;
            if (saveBtn) saveBtn.disabled = input.value.trim().length === 0;
            if (counter) {
                if (len >= 400) {
                    counter.hidden = false;
                    counter.textContent = len + ' / ' + HARD;
                    counter.classList.toggle('is-amber', len > SOFT);
                } else {
                    counter.hidden = true;
                }
            }
        }
        input.addEventListener('input', refresh);
        refresh(); // initialise (edit forms start pre-filled)

        form.addEventListener('submit', function (e) {
            if (input.value.trim().length === 0) { e.preventDefault(); return; }
            if (input.value.length > SOFT &&
                !confirm('Your note is quite long. Are you sure?')) {
                e.preventDefault();
            }
        });
    });

    // Add-note toggle
    document.querySelectorAll('[data-note-add-btn]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.parentNode.querySelector('[data-note-form]');
            if (!form) return;
            btn.hidden = true;
            form.hidden = false;
            var ta = form.querySelector('[data-note-input]');
            if (ta) ta.focus();
        });
    });

    // Edit toggle (per athlete note)
    document.querySelectorAll('[data-note-edit-btn]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var post = btn.closest('[data-note-id]');
            var form = post ? post.querySelector('[data-note-form]') : null;
            var body = post ? post.querySelector('[data-note-body]') : null;
            if (!form) return;
            if (body) body.hidden = true;
            btn.hidden = true;
            form.hidden = false;
            var ta = form.querySelector('[data-note-input]');
            if (ta) { ta.focus(); }
        });
    });

    // Cancel collapses the field and restores the prior view
    document.querySelectorAll('[data-note-cancel]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('[data-note-form]');
            if (!form) return;
            form.reset();
            form.hidden = true;
            var post = btn.closest('[data-note-id]');
            if (post) { // edit form → restore body + edit button
                var body = post.querySelector('[data-note-body]');
                var edit = post.querySelector('[data-note-edit-btn]');
                if (body) body.hidden = false;
                if (edit) edit.hidden = false;
            } else {     // add form → restore add button
                var add = form.parentNode.querySelector('[data-note-add-btn]');
                if (add) add.hidden = false;
            }
            var c = form.querySelector('[data-note-counter]');
            if (c) c.hidden = true;
        });
    });
})();
</script>

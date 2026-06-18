<?php
/**
 * Coach weekly review (Coaching Intelligence Layer, Phase 2).
 * A focused single-page flow: proposed decisions, roster insights, flagged
 * adjustments, upcoming races, and a complete-review footer.
 *
 * Vars: $proposedDecisions, $rosterInsights, $flaggedAdjustments, $upcomingRaces,
 *       $rosterNames (athlete_id=>name), $review (weekly_review_log row|null),
 *       $estMinutes, $itemsCount, $weekStart, $flashSuccess, $flashError.
 */

$borderFor = static function (string $sev): string {
    return match ($sev) {
        'warning'     => 'var(--color-warning)',
        'opportunity' => '#1D9E75',
        default       => 'var(--text-muted)',
    };
};

// Plain-English summary of a decision's trigger + action.
$decisionSummary = static function (array $d): string {
    $trig = json_decode((string)($d['trigger_json'] ?? ''), true) ?: [];
    $act  = json_decode((string)($d['action_json'] ?? ''), true) ?: [];

    $dists  = $trig['goal_distance'] ?? [];
    $phases = $trig['phase'] ?? [];
    $scope  = 'When generating plans';
    if ($dists)  $scope .= ' for ' . implode('/', (array)$dists) . ' athletes';
    if ($phases) $scope .= ($dists ? '' : ' for athletes') . ' in ' . implode('/', (array)$phases) . ' phase';

    $actStr = [];
    if (!empty($act['exclude_archetypes'])) {
        $actStr[] = 'exclude ' . implode(', ', (array)$act['exclude_archetypes']);
    }
    if (!empty($act['weight_multipliers']) && is_array($act['weight_multipliers'])) {
        foreach ($act['weight_multipliers'] as $code => $mult) {
            $actStr[] = 'weight ' . $code . ' ' . $mult . 'x';
        }
    }
    if (isset($act['duration_adjustment'])) {
        $delta = (int)$act['duration_adjustment'];
        $actStr[] = 'adjust duration by ' . ($delta >= 0 ? '+' : '') . $delta . ' min';
    }
    if (!empty($act['force_archetype'])) {
        $actStr[] = 'force ' . $act['force_archetype'];
    }
    if (isset($act['max_quality_per_week'])) {
        $actStr[] = 'cap quality at ' . (int)$act['max_quality_per_week'] . '/week';
    }
    if (!$actStr) $actStr[] = 'review and define the action before approving';

    return $scope . ': ' . implode(', ', $actStr) . '.';
};

$changeLabel = [
    'day_swap'               => 'Workout rescheduled',
    'archetype_substitution' => 'Archetype changed',
    'duration_change'        => 'Duration changed',
    'workout_removed'        => 'Workout removed',
    'workout_added'          => 'Workout added',
    'instructions_edited'    => 'Instructions edited',
    'pace_zone_edit'         => 'Pace zones edited',
];

$adjSummary = static function (array $a): string {
    $arrow = ' → ';
    switch ((string)$a['change_type']) {
        case 'day_swap':
            return h((string)($a['before_scheduled_date'] ?? '?')) . $arrow . h((string)($a['after_scheduled_date'] ?? '?'));
        case 'duration_change':
            return h(($a['before_duration_mins'] ?? '?') . ' min') . $arrow . h(($a['after_duration_mins'] ?? '?') . ' min');
        case 'archetype_substitution':
            return h((string)($a['before_archetype_code'] ?? '—')) . $arrow . h((string)($a['after_archetype_code'] ?? '—'));
        case 'workout_removed':
            return 'Removed ' . h((string)($a['before_workout_type'] ?? 'workout')) . ' on ' . h((string)($a['before_scheduled_date'] ?? '?'));
        case 'workout_added':
            return 'Added ' . h((string)($a['after_archetype_code'] ?: ($a['after_workout_type'] ?? 'workout'))) . ' on ' . h((string)($a['after_scheduled_date'] ?? '?'));
        case 'instructions_edited':
            return 'Instructions updated';
        case 'pace_zone_edit':
            return 'Pace zones updated';
        default:
            return '';
    }
};

$ruleTitle = static function (array $a): string {
    $phase = (string)($a['ctx_phase'] ?? '');
    $dist  = (string)($a['ctx_goal_distance'] ?? '');
    $wt    = (string)($a['before_workout_type'] ?? $a['after_workout_type'] ?? 'workout');
    switch ((string)$a['change_type']) {
        case 'day_swap':
            return trim("Reschedule {$wt}" . ($phase ? " in {$phase}" : ''));
        case 'archetype_substitution':
            return trim('Replace ' . (string)($a['before_archetype_code'] ?? 'archetype')
                . ' with ' . (string)($a['after_archetype_code'] ?? 'archetype')
                . ($dist ? " for {$dist}" : '') . ($phase ? " in {$phase}" : ''));
        case 'duration_change':
            return trim("Adjust {$wt} duration" . ($phase ? " in {$phase}" : ''));
        default:
            return 'Coaching rule';
    }
};

$reviewDone = !empty($review) && !empty($review['completed_at']);
?>
<div class="page-content">

    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:4px;">
        <div class="page-heading" style="margin:0;">Weekly review</div>
        <a href="/app/coach/intelligence" class="btn btn-secondary btn-sm">← Back to Intelligence</a>
    </div>
    <p class="body-text" style="margin-bottom:8px;">
        Week of <?= h(date('M j, Y', strtotime($weekStart))) ?>.
        <span style="color:var(--text-muted);">Est. <?= (int)$estMinutes ?> min</span>
    </p>

    <?php if ($reviewDone): ?>
    <div class="card" style="border-left:3px solid #1D9E75;margin-bottom:16px;">
        You completed this week's review <?= h(Timezone::format($review['completed_at'], 'l \a\t g:i A')) ?>.
        You can keep reviewing — marking complete again updates the record.
    </div>
    <?php endif; ?>

    <?php if (!empty($flashSuccess)): ?>
    <div class="card" style="border-left:3px solid #1D9E75;margin-bottom:16px;"><?= h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
    <div class="card" style="border-left:3px solid var(--color-danger);margin-bottom:16px;"><?= h($flashError) ?></div>
    <?php endif; ?>

    <!-- ════════ SECTION 1 — PROPOSED DECISIONS ════════ -->
    <div class="section-label" style="margin-top:8px;">PROPOSED DECISIONS</div>

    <?php if (empty($proposedDecisions)): ?>
    <div class="card" style="margin-bottom:24px;">
        <p class="body-text" style="margin:0;">No proposed rules right now. The system drafts rules when it sees the same kind of adjustment repeated across your athletes.</p>
    </div>
    <?php else: ?>
    <?php foreach ($proposedDecisions as $d):
        $n  = (int)$d['proposed_from_count'];
        $sd = json_decode((string)($d['scope_distances'] ?? ''), true) ?: [];
        $sp = json_decode((string)($d['scope_phases'] ?? ''), true) ?: [];
    ?>
    <div class="roster-row" style="margin-bottom:8px;border-left:3px solid #d99100;">
        <div style="font-size:11px;font-weight:600;letter-spacing:.04em;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;">
            Proposed rule — based on <?= $n ?> similar adjustment<?= $n === 1 ? '' : 's' ?>
        </div>

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
            <?php foreach (array_merge($sd, $sp) as $pill): ?>
            <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--text-secondary);"><?= h((string)$pill) ?></span>
            <?php endforeach; ?>
        </div>

        <p class="body-text" style="margin:0 0 10px;font-size:13px;color:var(--text-secondary);"><?= h($decisionSummary($d)) ?></p>

        <form method="POST" action="/app/coach/intelligence/decision/<?= (int)$d['id'] ?>/approve" style="margin:0;">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="from" value="review">
            <input type="hidden" name="require_reason" value="1">
            <div class="form-group" style="margin-bottom:8px;">
                <label class="form-label" for="ptitle-<?= (int)$d['id'] ?>">Rule title</label>
                <input type="text" id="ptitle-<?= (int)$d['id'] ?>" name="title" class="form-input" maxlength="255"
                       value="<?= h((string)$d['title']) ?>" required>
            </div>
            <div class="form-group" style="margin-bottom:8px;">
                <label class="form-label" for="preason-<?= (int)$d['id'] ?>">Why this rule? <span style="color:var(--text-muted);font-weight:400;">(required to approve)</span></label>
                <textarea id="preason-<?= (int)$d['id'] ?>" name="reason" class="form-textarea" rows="2" required
                          placeholder="In your own words, why should the engine apply this?"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Approve</button>
        </form>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
            <button type="button" class="btn btn-secondary btn-sm js-edit-decision"
                    data-decision-id="<?= (int)$d['id'] ?>"
                    data-title="<?= h((string)$d['title']) ?>"
                    data-distances="<?= h(implode(',', array_map('strval', $sd))) ?>"
                    data-phases="<?= h(implode(',', array_map('strval', $sp))) ?>">Modify</button>
            <form method="POST" action="/app/coach/intelligence/decision/<?= (int)$d['id'] ?>/dismiss" style="margin:0;display:inline;">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="from" value="review">
                <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ════════ SECTION 2 — ROSTER INSIGHTS ════════ -->
    <div class="section-label" style="margin-top:28px;">ROSTER INSIGHTS</div>

    <?php if (empty($rosterInsights)): ?>
    <div class="card" style="margin-bottom:24px;">
        <p class="body-text" style="margin:0;">No roster-wide patterns this week.</p>
    </div>
    <?php else: ?>
    <?php foreach ($rosterInsights as $ins):
        $sev = (string)$ins['severity'];
        $ids = json_decode((string)($ins['athlete_ids'] ?? ''), true) ?: [];
    ?>
    <div class="roster-row" style="margin-bottom:8px;border-left:3px solid <?= $borderFor($sev) ?>;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <div style="font-size:14px;font-weight:600;margin-bottom:4px;"><?= h($ins['title']) ?></div>
                <p class="body-text" style="margin:0 0 8px;white-space:pre-line;"><?= h($ins['detail']) ?></p>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <?php foreach ($ids as $aid): if (empty($rosterNames[(int)$aid])) continue; ?>
                    <a href="/app/coach/athlete/<?= (int)$aid ?>" class="pill"
                       style="font-size:11px;background:var(--recessed-bg);color:var(--text-secondary);text-decoration:none;"><?= h($rosterNames[(int)$aid]) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <form method="POST" action="/app/coach/intelligence/insight/<?= (int)$ins['id'] ?>/dismiss" style="flex-shrink:0;">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="from" value="review">
                <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ════════ SECTION 3 — FLAGGED ADJUSTMENTS ════════ -->
    <div class="section-label" style="margin-top:28px;">FLAGGED ADJUSTMENTS</div>

    <?php if (empty($flaggedAdjustments)): ?>
    <div class="card" style="margin-bottom:24px;">
        <p class="body-text" style="margin:0;">No plan adjustments are flagged for review.</p>
    </div>
    <?php else: ?>
    <?php foreach ($flaggedAdjustments as $a): ?>
    <div class="roster-row" style="margin-bottom:8px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                    <span style="font-size:14px;font-weight:600;"><?= h($a['athlete_name']) ?></span>
                    <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--text-secondary);">
                        <?= h($changeLabel[$a['change_type']] ?? $a['change_type']) ?>
                    </span>
                </div>
                <p class="body-text" style="margin:0 0 6px;font-size:13px;"><?= $adjSummary($a) ?></p>
                <div style="font-size:11px;color:var(--text-muted);"><?= h(Timezone::format($a['adjusted_at'], 'M j, Y')) ?></div>
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <button type="button" class="btn btn-primary btn-sm js-add-rule"
                        data-adj-id="<?= (int)$a['id'] ?>"
                        data-title="<?= h($ruleTitle($a)) ?>"
                        data-distance="<?= h((string)($a['ctx_goal_distance'] ?? '')) ?>"
                        data-phase="<?= h((string)($a['ctx_phase'] ?? '')) ?>">Add as rule</button>
                <form method="POST" action="/app/coach/intelligence/adjustment/<?= (int)$a['id'] ?>/dismiss">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="from" value="review">
                    <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ════════ SECTION 4 — UPCOMING RACES ════════ -->
    <div class="section-label" style="margin-top:28px;">UPCOMING RACES</div>

    <?php if (empty($upcomingRaces)): ?>
    <div class="card" style="margin-bottom:24px;">
        <p class="body-text" style="margin:0;">No athletes racing in the next 14 days.</p>
    </div>
    <?php else: ?>
    <div class="card" style="margin-bottom:24px;">
        <?php foreach ($upcomingRaces as $r):
            $days = (int)floor((strtotime((string)$r['race_date']) - strtotime('today')) / 86400);
            $until = $days <= 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days");
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--recessed-bg);flex-wrap:wrap;">
            <div style="font-size:13px;">
                <a href="/app/coach/athlete/<?= (int)$r['athlete_id'] ?>" style="font-weight:600;text-decoration:none;color:inherit;"><?= h($r['athlete_name']) ?></a>
                · <?= h($r['race_name']) ?>
                · <span style="color:var(--text-muted);"><?= h((string)$r['race_distance']) ?></span>
                · <?= h(date('M j', strtotime((string)$r['race_date']))) ?>
            </div>
            <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--text-secondary);"><?= h($until) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ════════ SECTION 5 — COMPLETE REVIEW ════════ -->
    <div class="section-label" style="margin-top:28px;">FINISH</div>
    <div class="card" style="margin-bottom:24px;">
        <p class="body-text" style="margin:0 0 12px;">
            <?= (int)$itemsCount ?> item<?= $itemsCount === 1 ? '' : 's' ?> in this review.
            Marking complete records the week so the prompt clears on your Intelligence page.
        </p>
        <form method="POST" action="/app/coach/intelligence/review/complete" style="margin:0;">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="items_reviewed" value="<?= (int)$itemsCount ?>">
            <button type="submit" class="btn btn-primary">Mark review complete</button>
        </form>
    </div>

</div>

<!-- ════════ ADD-AS-RULE MODAL (flagged adjustments) ════════ -->
<div id="rule-modal" role="dialog" aria-modal="true" aria-label="Add coaching rule">
    <div id="rule-bd"></div>
    <div id="rule-sheet">
        <button type="button" id="rule-close" aria-label="Close">×</button>
        <div style="font-size:15px;font-weight:600;margin-bottom:12px;padding-right:28px;">Add coaching rule</div>
        <form method="POST" id="rule-form" action="">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="from" value="review">
            <div class="form-group">
                <label class="form-label" for="rule-title">Title</label>
                <input type="text" id="rule-title" name="title" class="form-input" maxlength="255" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="rule-reason">Why this rule? <span style="color:var(--text-muted);font-weight:400;">(required)</span></label>
                <textarea id="rule-reason" name="reason" class="form-textarea" rows="3" required
                          placeholder="In your own words, why should the engine apply this?"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Distance</label>
                <div id="rule-distance-wrap" style="font-size:13px;color:var(--text-secondary);"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Phases</label>
                <div style="display:flex;gap:14px;flex-wrap:wrap;">
                    <?php foreach (['base','build','peak','taper'] as $ph): ?>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                        <input type="checkbox" name="phases[]" value="<?= $ph ?>" class="rule-phase" data-phase="<?= $ph ?>">
                        <?= ucfirst($ph) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:16px;">
                <button type="submit" class="btn btn-primary btn-sm">Save rule</button>
                <button type="button" id="rule-cancel" class="btn btn-secondary btn-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ════════ MODIFY PROPOSED DECISION MODAL ════════ -->
<div id="edit-modal" role="dialog" aria-modal="true" aria-label="Modify coaching rule">
    <div id="edit-bd"></div>
    <div id="edit-sheet">
        <button type="button" id="edit-close" aria-label="Close">×</button>
        <div style="font-size:15px;font-weight:600;margin-bottom:12px;padding-right:28px;">Modify coaching rule</div>
        <form method="POST" id="edit-form" action="">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="from" value="review">
            <div class="form-group">
                <label class="form-label" for="edit-title">Title</label>
                <input type="text" id="edit-title" name="title" class="form-input" maxlength="255" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="edit-reason">Why this rule? <span style="color:var(--text-muted);font-weight:400;">(required)</span></label>
                <textarea id="edit-reason" name="reason" class="form-textarea" rows="3" required
                          placeholder="In your own words, why should the engine apply this?"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Distances</label>
                <div id="edit-distance-wrap" style="display:flex;gap:14px;flex-wrap:wrap;font-size:13px;color:var(--text-secondary);"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Phases</label>
                <div style="display:flex;gap:14px;flex-wrap:wrap;">
                    <?php foreach (['base','build','peak','taper'] as $ph): ?>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                        <input type="checkbox" name="phases[]" value="<?= $ph ?>" class="edit-phase" data-phase="<?= $ph ?>">
                        <?= ucfirst($ph) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:16px;">
                <button type="submit" class="btn btn-primary btn-sm">Save & activate</button>
                <button type="button" id="edit-cancel" class="btn btn-secondary btn-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
#rule-modal, #edit-modal { display:none; position:fixed; inset:0; z-index:9999; align-items:center; justify-content:center; }
#rule-modal.is-open, #edit-modal.is-open { display:flex; }
#rule-bd, #edit-bd { position:absolute; inset:0; background:rgba(0,0,0,.45); }
#rule-sheet, #edit-sheet { position:relative; z-index:1; width:min(460px, calc(100vw - 32px)); max-height:88vh; overflow-y:auto;
    background:var(--card-bg); border:var(--card-border); border-radius:var(--radius-card); padding:20px 20px 24px; box-shadow:0 20px 60px rgba(0,0,0,.25); }
#rule-close, #edit-close { position:absolute; top:12px; right:14px; background:none; border:none; cursor:pointer; font-size:22px; line-height:1; color:var(--text-muted); }
</style>

<script>
(function () {
    'use strict';

    // ── Add-as-rule modal (flagged adjustments) ──
    var modal = document.getElementById('rule-modal');
    if (modal) {
        var form = document.getElementById('rule-form');
        var titleI = document.getElementById('rule-title');
        var reasonI = document.getElementById('rule-reason');
        var distWrap = document.getElementById('rule-distance-wrap');

        var openRule = function (btn) {
            form.action = '/app/coach/intelligence/adjustment/' + btn.getAttribute('data-adj-id') + '/rule';
            titleI.value = btn.getAttribute('data-title') || '';
            reasonI.value = '';
            var dist = btn.getAttribute('data-distance') || '';
            distWrap.innerHTML = '';
            if (dist) {
                var lbl = document.createElement('label');
                lbl.style.cssText = 'display:flex;align-items:center;gap:6px;';
                var cb = document.createElement('input');
                cb.type = 'checkbox'; cb.name = 'distances[]'; cb.value = dist; cb.checked = true;
                lbl.appendChild(cb);
                lbl.appendChild(document.createTextNode(dist));
                distWrap.appendChild(lbl);
            } else {
                distWrap.textContent = 'No distance captured — rule applies to all distances.';
            }
            var phase = btn.getAttribute('data-phase') || '';
            document.querySelectorAll('.rule-phase').forEach(function (cb) {
                cb.checked = (cb.getAttribute('data-phase') === phase);
            });
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            titleI.focus();
        };
        var closeRule = function () { modal.classList.remove('is-open'); document.body.style.overflow = ''; };

        document.querySelectorAll('.js-add-rule').forEach(function (btn) {
            btn.addEventListener('click', function () { openRule(btn); });
        });
        document.getElementById('rule-close').addEventListener('click', closeRule);
        document.getElementById('rule-cancel').addEventListener('click', closeRule);
        modal.addEventListener('click', function (e) { if (e.target.id === 'rule-bd') closeRule(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('is-open')) closeRule(); });
    }

    // ── Modify proposed-decision modal ──
    var em = document.getElementById('edit-modal');
    if (em) {
        var eform = document.getElementById('edit-form');
        var etitle = document.getElementById('edit-title');
        var ereason = document.getElementById('edit-reason');
        var edistWrap = document.getElementById('edit-distance-wrap');

        var openEdit = function (btn) {
            eform.action = '/app/coach/intelligence/decision/' + btn.getAttribute('data-decision-id') + '/modify';
            etitle.value = btn.getAttribute('data-title') || '';
            ereason.value = '';
            var dists = (btn.getAttribute('data-distances') || '').split(',').filter(Boolean);
            edistWrap.innerHTML = '';
            if (dists.length) {
                dists.forEach(function (d) {
                    var lbl = document.createElement('label');
                    lbl.style.cssText = 'display:flex;align-items:center;gap:6px;';
                    var cb = document.createElement('input');
                    cb.type = 'checkbox'; cb.name = 'distances[]'; cb.value = d; cb.checked = true;
                    lbl.appendChild(cb);
                    lbl.appendChild(document.createTextNode(d));
                    edistWrap.appendChild(lbl);
                });
            } else {
                edistWrap.textContent = 'No distance scope — applies to all distances.';
            }
            var phases = (btn.getAttribute('data-phases') || '').split(',').filter(Boolean);
            document.querySelectorAll('.edit-phase').forEach(function (cb) {
                cb.checked = (phases.indexOf(cb.getAttribute('data-phase')) !== -1);
            });
            em.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            etitle.focus();
        };
        var closeEdit = function () { em.classList.remove('is-open'); document.body.style.overflow = ''; };

        document.querySelectorAll('.js-edit-decision').forEach(function (btn) {
            btn.addEventListener('click', function () { openEdit(btn); });
        });
        document.getElementById('edit-close').addEventListener('click', closeEdit);
        document.getElementById('edit-cancel').addEventListener('click', closeEdit);
        em.addEventListener('click', function (e) { if (e.target.id === 'edit-bd') closeEdit(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && em.classList.contains('is-open')) closeEdit(); });
    }
})();
</script>

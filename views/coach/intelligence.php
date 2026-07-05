<?php
/**
 * Coach Intelligence page (Coaching Intelligence Layer, Part 6).
 * Sections: Athlete Flags, Flagged for Review, Decision Library.
 *
 * Vars: $intelFlags, $engineFlags (engine flags, pace_recalibration enriched with 'recal'),
 *       $flaggedAdjustments, $decisions, $flashSuccess, $flashError.
 */

require_once __DIR__ . '/../partials/predictive.php';

// ── Section 1: merge intelligence + engine flags, severity-ordered ──
$rank = ['critical' => 0, 'warning' => 1, 'opportunity' => 2, 'info' => 3];
$entries = [];
foreach (($intelFlags ?? []) as $f) {
    $entries[] = ['source' => 'intel', 'rank' => $rank[$f['severity']] ?? 3, 'created_at' => (string)$f['created_at'], 'data' => $f];
}
foreach (($engineFlags ?? []) as $f) {
    $entries[] = ['source' => 'engine', 'rank' => $rank[$f['severity']] ?? 3, 'created_at' => (string)$f['created_at'], 'data' => $f];
}
usort($entries, static fn($a, $b) => ($a['rank'] <=> $b['rank']) ?: strcmp($b['created_at'], $a['created_at']));

$borderFor = static function (string $sev): string {
    return match ($sev) {
        'critical'    => 'var(--color-danger)',
        'warning'     => 'var(--color-warning)',
        'opportunity' => 'var(--accent-mid)',
        default       => 'var(--text-muted)',
    };
};

// Contextual [Action] target for an intelligence flag.
$intelAction = static function (array $f): array {
    $aid = (int)$f['athlete_id'];
    return match ((string)$f['flag_type']) {
        'dropout_risk', 'engagement_dropping' => ['/app/coach/athlete/' . $aid . '/messages', 'Message athlete'],
        default                               => ['/app/coach/athlete/' . $aid, 'View plan'],
    };
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

// Before → after summary for a flagged adjustment.
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

// Suggested rule title prefill.
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
?>
<div class="page-content">

    <div class="page-heading" style="margin-bottom:4px;">Intelligence</div>
    <p class="body-text" style="margin-bottom:20px;">
        Athlete patterns, plan adjustments flagged for review, and your coaching rule library.
    </p>

    <?php if (!empty($flashSuccess)): ?>
    <div class="card" style="border-left:3px solid var(--accent-mid);margin-bottom:16px;"><?= h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
    <div class="card" style="border-left:3px solid var(--color-danger);margin-bottom:16px;"><?= h($flashError) ?></div>
    <?php endif; ?>

    <!-- ════════ WEEKLY REVIEW PROMPT ════════ -->
    <?php $reviewDone = !empty($review) && !empty($review['completed_at']); ?>
    <?php if (!$reviewDone): ?>
    <div class="card" style="border-left:3px solid var(--accent-mid);margin-bottom:20px;background:rgba(29,158,117,0.06);">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
                <div style="font-weight:600;margin-bottom:2px;">Your weekly coaching review is ready.</div>
                <div class="body-text" style="margin:0;font-size:13px;color:var(--text-secondary);">
                    <?= (int)($reviewItemCount ?? 0) ?> item<?= ($reviewItemCount ?? 0) === 1 ? '' : 's' ?> to review · Est. <?= (int)($reviewEstMinutes ?? 2) ?> min
                </div>
            </div>
            <a href="/app/coach/intelligence/review" class="btn btn-primary btn-sm">Start weekly review →</a>
        </div>
    </div>
    <?php else: ?>
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:20px;">
        Weekly review completed <?= h(Timezone::format($review['completed_at'], 'l \a\t g:i A')) ?>.
    </div>
    <?php endif; ?>

    <!-- ════════ ROSTER INSIGHTS ════════ -->
    <?php if (!empty($rosterInsights)): $insTotal = count($rosterInsights); ?>
    <div class="section-label" style="margin-top:8px;">ROSTER INSIGHTS</div>
    <?php foreach ($rosterInsights as $i => $ins):
        $sev    = (string)$ins['severity'];
        $insIds = json_decode((string)($ins['athlete_ids'] ?? ''), true) ?: [];
        $hidden = $i >= 3;
    ?>
    <div class="roster-row ri-row<?= $hidden ? ' ri-extra' : '' ?>" style="margin-bottom:8px;border-left:3px solid <?= $borderFor($sev) ?>;<?= $hidden ? 'display:none;' : '' ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <div style="font-size:14px;font-weight:600;margin-bottom:4px;"><?= h($ins['title']) ?></div>
                <p class="body-text" style="margin:0 0 8px;white-space:pre-line;"><?= h($ins['detail']) ?></p>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <?php foreach ($insIds as $aid): if (empty($rosterNames[(int)$aid])) continue; ?>
                    <a href="/app/coach/athlete/<?= (int)$aid ?>" class="pill"
                       style="font-size:11px;background:var(--recessed-bg);color:var(--text-secondary);text-decoration:none;"><?= h($rosterNames[(int)$aid]) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <form method="POST" action="/app/coach/intelligence/insight/<?= (int)$ins['id'] ?>/dismiss" style="flex-shrink:0;">
                <?= Auth::csrfField() ?>
                <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if ($insTotal > 3): ?>
    <button type="button" id="ri-toggle" class="btn btn-secondary btn-sm" style="margin:4px 0 24px;">View all <?= $insTotal ?> →</button>
    <?php else: ?>
    <div style="margin-bottom:16px;"></div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ════════ SECTION 1 — ATHLETE FLAGS ════════ -->
    <div class="section-label" style="margin-top:8px;">ATHLETE FLAGS</div>

    <?php if (empty($entries)): ?>
    <div class="card" style="border-left:3px solid var(--accent-mid);margin-bottom:24px;">
        <div class="empty-state" style="padding:20px 0;">
            <div class="empty-state-title">No open flags</div>
            <p class="body-text">All athletes are on track. New patterns appear here after the daily intelligence run.</p>
        </div>
    </div>
    <?php else: ?>
    <?php foreach ($entries as $entry):
        $f   = $entry['data'];
        $sev = (string)$f['severity'];
    ?>
    <?php if ($entry['source'] === 'intel'): ?>
        <?php [$actUrl, $actLabel] = $intelAction($f); ?>
        <div class="roster-row" style="margin-bottom:8px;border-left:3px solid <?= $borderFor($sev) ?>;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                        <span style="font-size:14px;font-weight:600;"><?= h($f['athlete_name']) ?></span>
                        <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:<?= $borderFor($sev) ?>;"><?= h(strtoupper($sev)) ?></span>
                        <?php if (!empty($f['confidence'])): ?><?= pf_confidence_badge($f['confidence'] ?? null, $f['prediction_horizon_days'] ?? null) ?><?php endif; ?>
                    </div>
                    <div style="font-size:14px;font-weight:600;margin-bottom:4px;"><?= h($f['title']) ?></div>
                    <p class="body-text" style="margin:0 0 6px;"><?= h($f['detail']) ?></p>
                    <?php if (!empty($f['suggested_action'])): ?>
                    <p class="body-text" style="margin:0;font-size:13px;color:var(--text-secondary);">
                        <strong>Suggested:</strong> <?= h($f['suggested_action']) ?>
                    </p>
                    <?php endif; ?>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">
                        Raised <?= h(Timezone::format($f['created_at'], 'M j, Y')) ?>
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-shrink:0;">
                    <?php if (($f['flag_type'] ?? '') === 'adaptation_ahead'): ?>
                    <form method="POST" action="/app/coach/intelligence/flag/<?= (int)$f['id'] ?>/adapt-accept">
                        <?= Auth::csrfField() ?>
                        <button type="submit" class="btn btn-primary btn-sm">Accept &amp; request plan</button>
                    </form>
                    <?php else: ?>
                    <a href="<?= h($actUrl) ?>" class="btn btn-secondary btn-sm"><?= h($actLabel) ?></a>
                    <?php endif; ?>
                    <form method="POST" action="/app/coach/intelligence/flag/<?= (int)$f['id'] ?>/dismiss">
                        <?= Auth::csrfField() ?>
                        <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
                    </form>
                </div>
            </div>
        </div>
    <?php else: /* engine flag — preserve existing Alerts behavior + recal/profile cards */ ?>
        <div class="roster-row" style="margin-bottom:8px;border-left:3px solid <?= $borderFor($sev) ?>;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                        <span style="font-size:14px;font-weight:600;"><?= h($f['athlete_name']) ?></span>
                        <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:<?= $borderFor($sev) ?>;"><?= h($f['flag_type']) ?></span>
                    </div>
                    <?php if (!empty($f['message'])): ?>
                    <p class="body-text" style="margin:0 0 8px;"><?= h($f['message']) ?></p>
                    <?php endif; ?>
                    <?php if (($f['flag_type'] ?? '') === 'profile_updated'): ?>
                    <?= render_profile_diff($f['details'] ?? null) ?>
                    <?php endif; ?>
                    <?php if (($f['flag_type'] ?? '') === 'pace_recalibration' && !empty($f['recal'])):
                        $recal   = $f['recal'];
                        $cur     = json_decode((string)($recal['current_pace_zones'] ?? ''), true) ?: [];
                        $prop    = json_decode((string)($recal['proposed_pace_zones'] ?? ''), true) ?: [];
                        $fmt     = fn($s) => (is_numeric($s) ? PaceZones::formatPace((int)$s) : '—');
                        $fmtEasy = function ($z) {
                            if (!empty($z['easy']['min']) && !empty($z['easy']['max'])) {
                                return PaceZones::formatRange((int)$z['easy']['min'], (int)$z['easy']['max']);
                            }
                            return '—';
                        };
                    ?>
                    <div class="srf-recal" data-recal>
                        <div style="font-weight:600;margin-bottom:6px;">⚡ Pace Zone Update Available</div>
                        <div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:4px 14px;font-size:13px;align-items:center;">
                            <div></div>
                            <div style="font-weight:600;color:var(--text-muted);">Current</div>
                            <div style="font-weight:600;">Proposed</div>
                            <div>Easy</div><div><?= h($fmtEasy($cur)) ?></div><div><?= h($fmtEasy($prop)) ?></div>
                            <div>Tempo</div><div><?= h($fmt($cur['half_marathon'] ?? null)) ?></div><div><?= h($fmt($prop['half_marathon'] ?? null)) ?></div>
                            <div>10K</div><div><?= h($fmt($cur['10K'] ?? null)) ?></div><div><?= h($fmt($prop['10K'] ?? null)) ?></div>
                            <div>5K</div><div><?= h($fmt($cur['5K'] ?? null)) ?></div><div><?= h($fmt($prop['5K'] ?? null)) ?></div>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
                            <?php if (!empty($prop)): ?>
                            <form method="POST" action="/app/coach/races/<?= (int)$recal['id'] ?>/recalibrate/approve" style="display:inline;">
                                <?= Auth::csrfField() ?>
                                <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" action="/app/coach/races/<?= (int)$recal['id'] ?>/recalibrate/dismiss" style="display:inline;">
                                <?= Auth::csrfField() ?>
                                <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">
                        Raised <?= h(Timezone::format($f['created_at'], 'M j, Y')) ?>
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-shrink:0;">
                    <a href="/app/coach/athlete/<?= (int)$f['athlete_id'] ?>" class="btn btn-secondary btn-sm">View</a>
                    <form method="POST" action="/app/coach/flags/<?= (int)$f['id'] ?>/dismiss">
                        <?= Auth::csrfField() ?>
                        <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ════════ PROPOSED DECISIONS ════════ -->
    <?php if (!empty($proposedDecisions)): $propTotal = count($proposedDecisions); ?>
    <div class="section-label" style="margin-top:28px;">
        PROPOSED DECISIONS
        <span class="pill" style="font-size:10px;background:rgba(217,145,0,0.15);color:var(--color-warning);"><?= $propTotal ?></span>
    </div>
    <?php foreach (array_slice($proposedDecisions, 0, 2) as $d): ?>
    <div class="roster-row" style="margin-bottom:8px;border-left:3px solid var(--color-warning);">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <div style="font-size:14px;font-weight:600;margin-bottom:2px;"><?= h($d['title']) ?></div>
                <div style="font-size:12px;color:var(--text-muted);">Based on <?= (int)$d['proposed_from_count'] ?> adjustment<?= (int)$d['proposed_from_count'] === 1 ? '' : 's' ?></div>
            </div>
            <a href="/app/coach/intelligence/review" class="btn btn-secondary btn-sm" style="flex-shrink:0;">Review</a>
        </div>
    </div>
    <?php endforeach; ?>
    <a href="/app/coach/intelligence/review" class="body-text" style="display:inline-block;margin:4px 0 8px;font-size:13px;color:var(--accent-mid);text-decoration:none;font-weight:600;">Review all <?= $propTotal ?> proposed rule<?= $propTotal === 1 ? '' : 's' ?> →</a>
    <?php endif; ?>

    <!-- ════════ SECTION 2 — FLAGGED FOR REVIEW ════════ -->
    <div class="section-label" style="margin-top:28px;">FLAGGED FOR REVIEW</div>

    <?php if (empty($flaggedAdjustments)): ?>
    <div class="card" style="margin-bottom:24px;">
        <p class="body-text" style="margin:0;">No plan adjustments are flagged for review. Flag a workout from an athlete's plan to capture it here.</p>
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
                <div style="font-size:11px;color:var(--text-muted);">
                    <?= h(Timezone::format($a['adjusted_at'], 'M j, Y')) ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <button type="button" class="btn btn-primary btn-sm js-add-rule"
                        data-adj-id="<?= (int)$a['id'] ?>"
                        data-title="<?= h($ruleTitle($a)) ?>"
                        data-distance="<?= h((string)($a['ctx_goal_distance'] ?? '')) ?>"
                        data-phase="<?= h((string)($a['ctx_phase'] ?? '')) ?>">Add as rule</button>
                <form method="POST" action="/app/coach/intelligence/adjustment/<?= (int)$a['id'] ?>/dismiss">
                    <?= Auth::csrfField() ?>
                    <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ════════ ASSISTANT PROPOSALS (Phase 4 — head coach review) ════════ -->
    <?php if (!empty($assistantProposals)): ?>
    <div class="section-label" style="margin-top:28px;">ASSISTANT PROPOSALS</div>
    <?php foreach ($assistantProposals as $p): ?>
    <div class="roster-row" style="margin-bottom:8px;border-left:3px solid var(--color-warning);">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <div style="font-size:14px;font-weight:600;margin-bottom:2px;"><?= h($p['title']) ?></div>
                <div style="font-size:12px;color:var(--text-muted);">Proposed by <?= h($p['author_name'] ?? 'assistant coach') ?></div>
                <?php if (!empty($p['reason'])): ?>
                <p class="body-text" style="margin:4px 0 0;font-size:13px;color:var(--text-secondary);"><?= h($p['reason']) ?></p>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <form method="POST" action="/app/coach/intelligence/proposal/<?= (int)$p['id'] ?>/approve" style="margin:0;">
                    <?= Auth::csrfField() ?>
                    <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                </form>
                <form method="POST" action="/app/coach/intelligence/proposal/<?= (int)$p['id'] ?>/dismiss" style="margin:0;">
                    <?= Auth::csrfField() ?>
                    <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ════════ SECTION 3 — DECISION LIBRARY ════════ -->
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:28px;">
        <div class="section-label" style="margin:0;">DECISION LIBRARY</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if (!empty($canImportPlaybook)): ?>
            <form method="POST" action="/app/coach/intelligence/import-playbook" style="margin:0;">
                <?= Auth::csrfField() ?>
                <button type="submit" class="btn btn-secondary btn-sm">Import founding coach's rules</button>
            </form>
            <?php endif; ?>
            <a href="/app/coach/intelligence/philosophy" class="btn btn-secondary btn-sm">Export philosophy</a>
        </div>
    </div>

    <?php if (empty($decisions)): ?>
    <div class="card" style="margin-bottom:24px;">
        <p class="body-text" style="margin:0;">No coaching rules yet. Flag plan adjustments for review to start building your coaching rule library.</p>
    </div>
    <?php else: ?>
    <div class="card" style="margin-bottom:24px;overflow-x:auto;">
        <div class="decision-grid">
            <div class="decision-head">Title</div>
            <div class="decision-head">Scope</div>
            <div class="decision-head">Fired</div>
            <div class="decision-head">Last fired</div>
            <div class="decision-head">Status</div>
            <?php foreach ($decisions as $d):
                $sd = json_decode((string)($d['scope_distances'] ?? ''), true) ?: [];
                $sp = json_decode((string)($d['scope_phases'] ?? ''), true) ?: [];
                $scopeBits = array_merge($sd, $sp);
                $scope = $scopeBits ? implode(', ', $scopeBits) : 'All';
                $isActive   = ($d['status'] === 'active');
                $isProposed = ($d['status'] === 'proposed');
                $isAsstProp = ($d['status'] === 'proposed_by_assistant');
                $isShared   = !empty($d['shared']);
                $canShare   = !empty($multiCoach) && !empty($isHeadCoach);
            ?>
            <div class="decision-cell" style="font-weight:600;">
                <?= h($d['title']) ?>
                <?php if ($isProposed): ?>
                <div style="font-weight:400;font-size:11px;color:var(--text-muted);">Based on <?= (int)$d['proposed_from_count'] ?> adjustment<?= (int)$d['proposed_from_count'] === 1 ? '' : 's' ?></div>
                <?php endif; ?>
            </div>
            <div class="decision-cell" style="color:var(--text-muted);font-size:12px;"><?= h($scope) ?></div>
            <div class="decision-cell"><?= (int)$d['times_fired'] ?></div>
            <div class="decision-cell" style="font-size:12px;color:var(--text-muted);">
                <?= $d['last_fired_at'] ? h(Timezone::format($d['last_fired_at'], 'M j')) : '—' ?>
            </div>
            <div class="decision-cell">
                <?php if ($isProposed): ?>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <span class="pill" style="font-size:10px;background:rgba(217,145,0,0.15);color:var(--color-warning);">Proposed</span>
                    <form method="POST" action="/app/coach/intelligence/decision/<?= (int)$d['id'] ?>/approve" style="margin:0;">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="from" value="library">
                        <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                    </form>
                    <form method="POST" action="/app/coach/intelligence/decision/<?= (int)$d['id'] ?>/dismiss" style="margin:0;">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="from" value="library">
                        <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
                    </form>
                </div>
                <?php elseif ($isAsstProp): ?>
                <span class="pill" style="font-size:10px;background:rgba(217,145,0,0.15);color:var(--color-warning);">Awaiting head coach</span>
                <?php else: ?>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <form method="POST" action="/app/coach/intelligence/decision/<?= (int)$d['id'] ?>/toggle" style="margin:0;">
                        <?= Auth::csrfField() ?>
                        <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-primary' : '' ?>"
                                style="<?= $isActive ? '' : 'background:var(--recessed-bg);color:var(--text-muted);' ?>">
                            <?= $isActive ? 'Active' : 'Inactive' ?>
                        </button>
                    </form>
                    <?php if ($isActive && $canShare): ?>
                    <form method="POST" action="/app/coach/intelligence/decision/<?= (int)$d['id'] ?>/share" style="margin:0;" title="Share this rule across the whole roster">
                        <?= Auth::csrfField() ?>
                        <button type="submit" class="btn btn-sm" style="<?= $isShared ? 'background:var(--success-fill);color:var(--accent-mid);' : 'background:var(--recessed-bg);color:var(--text-muted);' ?>">
                            <?= $isShared ? 'Shared' : 'Share' ?>
                        </button>
                    </form>
                    <?php elseif ($isShared): ?>
                    <span class="pill" style="font-size:10px;background:var(--success-fill);color:var(--accent-mid);">Shared</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ════════ ADD-AS-RULE MODAL ════════ -->
<div id="rule-modal" role="dialog" aria-modal="true" aria-label="Add coaching rule">
    <div id="rule-bd"></div>
    <div id="rule-sheet">
        <button type="button" id="rule-close" aria-label="Close">×</button>
        <div style="font-size:15px;font-weight:600;margin-bottom:12px;padding-right:28px;">Add coaching rule</div>
        <form method="POST" id="rule-form" action="">
            <?= Auth::csrfField() ?>
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
                <div id="rule-distance-wrap" style="font-size:13px;color:var(--text-secondary);">
                    <!-- one pre-checked distance from the captured context -->
                </div>
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

<style>
.srf-recal { background:var(--recessed-bg,rgba(0,0,0,0.04)); border-radius:10px; padding:12px; margin:8px 0; }
.decision-grid { display:grid; grid-template-columns: 2fr 1.4fr .6fr .9fr .9fr; gap:8px 14px; min-width:560px; align-items:center; }
.decision-head { font-size:10px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted); border-bottom:var(--card-border); padding-bottom:6px; }
.decision-cell { font-size:13px; padding:6px 0; border-bottom:1px solid var(--recessed-bg); }
#rule-modal { display:none; position:fixed; inset:0; z-index:9999; align-items:center; justify-content:center; }
#rule-modal.is-open { display:flex; }
#rule-bd { position:absolute; inset:0; background:rgba(0,0,0,.45); }
#rule-sheet { position:relative; z-index:1; width:min(460px, calc(100vw - 32px)); max-height:88vh; overflow-y:auto;
    background:var(--card-bg); border:var(--card-border); border-radius:var(--radius-card); padding:20px 20px 24px; box-shadow:0 20px 60px rgba(0,0,0,.25); }
#rule-close { position:absolute; top:12px; right:14px; background:none; border:none; cursor:pointer; font-size:22px; line-height:1; color:var(--text-muted); }
</style>

<script>
(function () {
    'use strict';
    var modal = document.getElementById('rule-modal');
    if (!modal) return;
    var form   = document.getElementById('rule-form');
    var titleI = document.getElementById('rule-title');
    var reasonI= document.getElementById('rule-reason');
    var distWrap = document.getElementById('rule-distance-wrap');

    function open(btn) {
        form.action = '/app/coach/intelligence/adjustment/' + btn.getAttribute('data-adj-id') + '/rule';
        titleI.value = btn.getAttribute('data-title') || '';
        reasonI.value = '';
        // Distance: single captured value, pre-checked (hidden input + label).
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
        // Phases: pre-check the captured phase.
        var phase = btn.getAttribute('data-phase') || '';
        document.querySelectorAll('.rule-phase').forEach(function (cb) {
            cb.checked = (cb.getAttribute('data-phase') === phase);
        });
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        titleI.focus();
    }
    function close() { modal.classList.remove('is-open'); document.body.style.overflow = ''; }

    document.querySelectorAll('.js-add-rule').forEach(function (btn) {
        btn.addEventListener('click', function () { open(btn); });
    });
    document.getElementById('rule-close').addEventListener('click', close);
    document.getElementById('rule-cancel').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target.id === 'rule-bd') close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('is-open')) close(); });
})();

// Roster insights: reveal the rows beyond the first three inline.
(function () {
    'use strict';
    var toggle = document.getElementById('ri-toggle');
    if (!toggle) return;
    toggle.addEventListener('click', function () {
        document.querySelectorAll('.ri-extra').forEach(function (el) { el.style.display = ''; });
        toggle.style.display = 'none';
    });
})();
</script>

<?php
// $criticalFlags, $warningFlags, $pendingPlans, $upcomingRaces, $unreadMessages
// Design-language prototype (Phase 2 reference screen): same data, same endpoints,
// reorganized presentation. The dash-* classes below are the candidate shared
// patterns; once the language is approved they graduate into app.css and propagate.
$totalFlags  = count($criticalFlags) + count($warningFlags);
$unreadCount = count($unreadMessages ?? []);
?>
<style>
    /* Prototype pattern classes (dashboard only until the language is approved). */
    .dash-header { margin-bottom: var(--space-6); }
    .dash-date { font-size: var(--text-sm); color: var(--text-muted); margin-top: 2px; }

    /* Triage strip: the day at a glance. Neutral unless something needs action. */
    .dash-triage { display: flex; gap: var(--space-2); flex-wrap: wrap; margin-top: var(--space-3); }
    .dash-chip {
        display: inline-flex; align-items: baseline; gap: 6px;
        padding: 6px 12px; border-radius: var(--radius-sm);
        background: var(--card-bg); border: var(--card-border);
        font-size: var(--text-sm); color: var(--text-secondary);
        text-decoration: none;
    }
    .dash-chip .n { font-size: var(--text-lg); font-weight: 700; color: var(--text-primary); font-variant-numeric: tabular-nums; }
    .dash-chip.is-alert .n { color: var(--color-danger); }
    .dash-chip.is-action .n { color: var(--accent-mid); }
    .dash-chip.is-zero { color: var(--text-muted); }
    .dash-chip.is-zero .n { color: var(--text-muted); font-weight: 500; }
    .dash-chip[href]:hover { background: var(--elevated-bg); }

    .dash-section { margin-bottom: var(--space-6); }
    .dash-section-head {
        display: flex; align-items: baseline; justify-content: space-between;
        gap: var(--space-2); margin-bottom: var(--space-3);
    }
    .dash-section-head .section-label { margin: 0; }

    /* List-in-card: one surface per section, hairline-divided rows. */
    .dash-list { background: var(--card-bg); border: var(--card-border); border-radius: var(--radius-card); }
    .dash-row {
        display: flex; align-items: center; justify-content: space-between;
        gap: var(--space-3); padding: var(--space-3) var(--space-4);
    }
    .dash-row + .dash-row { border-top: 1px solid var(--divider); }
    a.dash-row { text-decoration: none; color: var(--text-primary); }
    a.dash-row:hover { background: var(--elevated-bg); }
    .dash-row:first-child { border-radius: var(--radius-card) var(--radius-card) 0 0; }
    .dash-row:last-child { border-radius: 0 0 var(--radius-card) var(--radius-card); }
    .dash-row-main { min-width: 0; flex: 1; }
    .dash-name { font-size: var(--text-md); font-weight: 600; }
    .dash-meta { font-size: var(--text-sm); color: var(--text-muted); margin-top: 1px; }
    .dash-preview {
        font-size: var(--text-base); color: var(--text-secondary); margin-top: 2px;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .dash-quietlink { font-size: var(--text-base); font-weight: 500; color: var(--accent-strong); text-decoration: none; white-space: nowrap; }
    .dash-quietlink:hover { text-decoration: underline; }

    .dash-days { text-align: right; }
    .dash-days .n { font-size: var(--text-lg); font-weight: 700; color: var(--text-primary); font-variant-numeric: tabular-nums; }
    .dash-days .when { font-size: var(--text-xs); color: var(--text-muted); }
    .dash-days.is-imminent .n { color: var(--accent-mid); }

    .dash-allclear {
        display: flex; align-items: center; gap: var(--space-2);
        background: var(--card-bg); border: var(--card-border);
        border-left: 3px solid var(--color-success);
        border-radius: var(--radius-card); padding: var(--space-3) var(--space-4);
        font-size: var(--text-base); color: var(--text-secondary);
    }

    /* Flag cards keep their app.css base; the dashboard tightens the action row. */
    .dash-flag-actions { display: flex; gap: var(--space-2); align-items: center; margin-top: var(--space-2); }
    .dash-dismiss {
        background: none; border: none; cursor: pointer; padding: 6px 8px;
        font-size: var(--text-sm); font-weight: 500; color: var(--text-muted);
        border-radius: var(--radius-xs);
    }
    .dash-dismiss:hover { color: var(--text-secondary); background: var(--recessed-bg); }

    .dash-badge-new {
        font-size: 10px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase;
        color: var(--color-info); background: var(--info-fill);
        border-radius: 999px; padding: 1px 8px; flex-shrink: 0;
    }
</style>

<div class="page-content">

    <!-- Header + triage strip: the whole day at a glance, color only where action lives -->
    <div class="dash-header">
        <div class="page-heading" style="margin-bottom:0;">Dashboard</div>
        <div class="dash-date"><?= date('l, F j') ?></div>
        <div class="dash-triage">
            <a class="dash-chip <?= $totalFlags > 0 ? 'is-alert' : 'is-zero' ?>" href="/app/coach/flags">
                <span class="n"><?= $totalFlags ?></span> open alert<?= $totalFlags !== 1 ? 's' : '' ?>
            </a>
            <a class="dash-chip <?= $pendingApprovals > 0 ? 'is-action' : 'is-zero' ?>" href="/app/coach/approvals">
                <span class="n"><?= (int)$pendingApprovals ?></span> plan<?= (int)$pendingApprovals !== 1 ? 's' : '' ?> to review
            </a>
            <a class="dash-chip <?= $unreadCount > 0 ? '' : 'is-zero' ?>" href="/app/coach/messages">
                <span class="n"><?= $unreadCount ?></span> unread message<?= $unreadCount !== 1 ? 's' : '' ?>
            </a>
        </div>
    </div>

    <!-- PRIMARY: who needs attention. One prioritized group, critical first;
         severity carried by the card edge + pill, not competing headings. -->
    <div class="dash-section">
        <div class="dash-section-head">
            <div class="section-label" style="<?= !empty($criticalFlags) ? 'color:var(--color-danger);' : '' ?>">NEEDS ATTENTION</div>
            <?php if ($totalFlags > 0): ?>
            <a class="dash-quietlink" href="/app/coach/flags">All flags &rarr;</a>
            <?php endif; ?>
        </div>

        <?php if ($totalFlags === 0): ?>
        <div class="dash-allclear">All athletes are on track. No open alerts.</div>
        <?php else: ?>
        <?php foreach (array_merge(
            array_map(fn($f) => $f + ['_sev' => 'critical'], $criticalFlags),
            array_map(fn($f) => $f + ['_sev' => 'warning'],  $warningFlags)
        ) as $flag): ?>
        <div class="flag-card flag-card-<?= $flag['_sev'] ?>" style="margin-bottom:var(--space-2);">
            <div class="flag-body">
                <div class="flag-card-head">
                    <div class="flag-card-titlewrap">
                        <span class="flag-card-title" style="font-size:var(--text-md);font-weight:600;"><?= h($flag['athlete_name']) ?></span>
                        <span class="pill pill-<?= $flag['_sev'] ?>" style="font-size:10px;"><?= $flag['_sev'] === 'critical' ? 'Critical' : 'Warning' ?></span>
                    </div>
                </div>
                <div class="flag-card-msg">
                    <?= h($flag['flag_type']) ?>
                    <?php if ($flag['message']): ?>
                    <p style="margin:4px 0 0;"><?= h($flag['message']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="dash-flag-actions">
                    <a href="/app/coach/athlete/<?= (int)$flag['athlete_id'] ?>" class="btn btn-secondary btn-sm">View athlete</a>
                    <form method="POST" action="/app/coach/flags/<?= (int)$flag['id'] ?>/dismiss" style="display:inline;">
                        <?= Auth::csrfField() ?>
                        <button type="submit" class="dash-dismiss">Dismiss</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- SECONDARY: plans waiting on the coach. The screen's one primary button. -->
    <?php if (!empty($pendingPlans)): ?>
    <div class="dash-section">
        <div class="dash-section-head">
            <div class="section-label">APPROVALS PENDING</div>
            <a href="/app/coach/approvals" class="btn btn-primary btn-sm">Review plans</a>
        </div>
        <div class="dash-list">
            <?php foreach ($pendingPlans as $plan): ?>
            <div class="dash-row">
                <div class="dash-row-main">
                    <div class="dash-name"><?= h($plan['athlete_name']) ?></div>
                    <div class="dash-meta">
                        <?= h(ucfirst(str_replace('_', ' ', $plan['plan_type']))) ?>
                        &middot; <?= date('M j', strtotime($plan['plan_start_date'])) ?> &ndash; <?= date('M j, Y', strtotime($plan['plan_end_date'])) ?>
                    </div>
                </div>
                <a class="dash-quietlink" href="/app/coach/approvals">Review &rarr;</a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- TERTIARY: context, quiet rows in single surfaces -->
    <?php if (!empty($upcomingRaces)): ?>
    <div class="dash-section">
        <div class="dash-section-head">
            <div class="section-label">UPCOMING RACES <span style="font-weight:400;color:var(--text-muted);">(14 days)</span></div>
        </div>
        <div class="dash-list">
            <?php foreach ($upcomingRaces as $race): ?>
            <?php $days = (int)ceil((strtotime($race['race_date']) - time()) / 86400); ?>
            <div class="dash-row">
                <div class="dash-row-main">
                    <div class="dash-name"><?= h($race['athlete_name']) ?></div>
                    <div class="dash-meta"><?= h($race['race_name'] ?? ucfirst($race['race_distance'])) ?></div>
                </div>
                <div class="dash-days <?= $days <= 7 ? 'is-imminent' : '' ?>">
                    <div class="n"><?= $days ?> day<?= $days !== 1 ? 's' : '' ?></div>
                    <div class="when"><?= date('M j', strtotime($race['race_date'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($unreadMessages)): ?>
    <div class="dash-section">
        <div class="dash-section-head">
            <div class="section-label">MESSAGES</div>
            <a class="dash-quietlink" href="/app/coach/messages">All messages &rarr;</a>
        </div>
        <div class="dash-list">
            <?php foreach ($unreadMessages as $msg): ?>
            <a class="dash-row" href="/app/coach/athlete/<?= (int)$msg['athlete_id'] ?>/messages">
                <div class="dash-row-main">
                    <div style="display:flex;align-items:center;gap:var(--space-2);">
                        <span class="dash-name"><?= h($msg['athlete_name']) ?></span>
                        <span class="dash-badge-new">new</span>
                    </div>
                    <div class="dash-preview"><?= h($msg['body']) ?></div>
                </div>
                <span class="dash-meta" style="white-space:nowrap;flex-shrink:0;"><?= h(Timezone::format($msg['sent_at'], 'M j')) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php
// $criticalFlags, $warningFlags, $pendingPlans, $upcomingRaces, $unreadMessages
// Reference screen for the design-language kit (app.css "Design-language kit"
// section): triage strip, list-in-card, quiet links, ghost actions. Same data
// and endpoints as always; presentation follows the approved language.
$totalFlags  = count($criticalFlags) + count($warningFlags);
$unreadCount = count($unreadMessages ?? []);
?>
<div class="page-content">

    <!-- Header + triage strip: the whole day at a glance, color only where action lives -->
    <div style="margin-bottom:var(--space-6);">
        <div class="page-heading" style="margin-bottom:0;">Dashboard</div>
        <div style="font-size:var(--text-sm);color:var(--text-muted);margin-top:2px;"><?= date('l, F j') ?></div>
        <div class="stat-strip" style="margin-top:var(--space-3);">
            <a class="stat-chip <?= $totalFlags > 0 ? 'is-alert' : 'is-zero' ?>" href="/app/coach/flags">
                <span class="n"><?= $totalFlags ?></span> open alert<?= $totalFlags !== 1 ? 's' : '' ?>
            </a>
            <a class="stat-chip <?= $pendingApprovals > 0 ? 'is-action' : 'is-zero' ?>" href="/app/coach/approvals">
                <span class="n"><?= (int)$pendingApprovals ?></span> plan<?= (int)$pendingApprovals !== 1 ? 's' : '' ?> to review
            </a>
            <a class="stat-chip <?= $unreadCount > 0 ? '' : 'is-zero' ?>" href="/app/coach/messages">
                <span class="n"><?= $unreadCount ?></span> unread message<?= $unreadCount !== 1 ? 's' : '' ?>
            </a>
        </div>
    </div>

    <!-- PRIMARY: who needs attention. One prioritized group, critical first. -->
    <div class="section-block">
        <div class="section-head">
            <div class="section-label" style="<?= !empty($criticalFlags) ? 'color:var(--color-danger);' : '' ?>">NEEDS ATTENTION</div>
            <?php if ($totalFlags > 0): ?>
            <a class="link-quiet" href="/app/coach/flags">All flags &rarr;</a>
            <?php endif; ?>
        </div>

        <?php if ($totalFlags === 0): ?>
        <div class="card-allclear">All athletes are on track. No open alerts.</div>
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
                <div class="row-actions">
                    <a href="/app/coach/athlete/<?= (int)$flag['athlete_id'] ?>" class="btn btn-secondary btn-sm">View athlete</a>
                    <form method="POST" action="/app/coach/flags/<?= (int)$flag['id'] ?>/dismiss" style="display:inline;">
                        <?= Auth::csrfField() ?>
                        <button type="submit" class="btn-ghost">Dismiss</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- SECONDARY: plans waiting on the coach. The screen's one primary button. -->
    <?php if (!empty($pendingPlans)): ?>
    <div class="section-block">
        <div class="section-head">
            <div class="section-label">APPROVALS PENDING</div>
            <a href="/app/coach/approvals" class="btn btn-primary btn-sm">Review plans</a>
        </div>
        <div class="list-card">
            <?php foreach ($pendingPlans as $plan): ?>
            <div class="list-row">
                <div class="row-main">
                    <div class="row-name"><?= h($plan['athlete_name']) ?></div>
                    <div class="row-meta">
                        <?= h(ucfirst(str_replace('_', ' ', $plan['plan_type']))) ?>
                        &middot; <?= date('M j', strtotime($plan['plan_start_date'])) ?> &ndash; <?= date('M j, Y', strtotime($plan['plan_end_date'])) ?>
                    </div>
                </div>
                <a class="link-quiet" href="/app/coach/approvals">Review &rarr;</a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- TERTIARY: context, quiet rows in single surfaces -->
    <?php if (!empty($upcomingRaces)): ?>
    <div class="section-block">
        <div class="section-head">
            <div class="section-label">UPCOMING RACES <span style="font-weight:400;color:var(--text-muted);">(14 days)</span></div>
        </div>
        <div class="list-card">
            <?php foreach ($upcomingRaces as $race): ?>
            <?php $days = (int)ceil((strtotime($race['race_date']) - time()) / 86400); ?>
            <div class="list-row">
                <div class="row-main">
                    <div class="row-name"><?= h($race['athlete_name']) ?></div>
                    <div class="row-meta"><?= h($race['race_name'] ?? ucfirst($race['race_distance'])) ?></div>
                </div>
                <div class="count-cell <?= $days <= 7 ? 'is-accent' : '' ?>">
                    <div class="n"><?= $days ?> day<?= $days !== 1 ? 's' : '' ?></div>
                    <div class="sub"><?= date('M j', strtotime($race['race_date'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($unreadMessages)): ?>
    <div class="section-block">
        <div class="section-head">
            <div class="section-label">MESSAGES</div>
            <a class="link-quiet" href="/app/coach/messages">All messages &rarr;</a>
        </div>
        <div class="list-card">
            <?php foreach ($unreadMessages as $msg): ?>
            <a class="list-row" href="/app/coach/athlete/<?= (int)$msg['athlete_id'] ?>/messages">
                <div class="row-main">
                    <div style="display:flex;align-items:center;gap:var(--space-2);">
                        <span class="row-name"><?= h($msg['athlete_name']) ?></span>
                        <span class="badge-new">new</span>
                    </div>
                    <div class="row-preview"><?= h($msg['body']) ?></div>
                </div>
                <span class="row-meta" style="white-space:nowrap;flex-shrink:0;"><?= h(Timezone::format($msg['sent_at'], 'M j')) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

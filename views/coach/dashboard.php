<?php
// $criticalFlags, $warningFlags, $pendingPlans, $upcomingRaces, $unreadMessages
$totalFlags = count($criticalFlags) + count($warningFlags);
?>
<div class="page-content">

    <div class="page-heading" style="margin-bottom:4px;">Dashboard</div>
    <p class="body-text" style="margin-bottom:12px;">
        <?= date('l, F j') ?>
        <?php if ($totalFlags > 0 || $pendingApprovals > 0): ?>
        - <?php $parts = [];
            if ($totalFlags)        $parts[] = $totalFlags . ' alert' . ($totalFlags !== 1 ? 's' : '');
            if ($pendingApprovals)  $parts[] = $pendingApprovals . ' plan' . ($pendingApprovals !== 1 ? 's' : '') . ' pending review';
            echo implode(', ', $parts); ?>
        <?php endif; ?>
    </p>

    <?php if (!empty($criticalFlags)): ?>
    <div class="section-label" style="color:var(--color-danger);">NEEDS ATTENTION</div>
    <?php foreach ($criticalFlags as $flag): ?>
    <div class="roster-row severity-critical" style="margin-bottom:8px;">
        <div class="roster-row-meta">
            <span style="font-size:13px;font-weight:600;"><?= h($flag['athlete_name']) ?></span>
            <span class="pill" style="background:#FDECEA;color:#991B1B;font-size:10px;margin-left:8px;">Critical</span>
        </div>
        <div class="roster-row-body">
            <span style="font-size:13px;color:var(--text-secondary);"><?= h($flag['flag_type']) ?></span>
            <?php if ($flag['flag_message']): ?>
            <p class="body-text" style="margin:4px 0 0;"><?= h($flag['flag_message']) ?></p>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;margin-top:10px;">
            <a href="/app/coach/athlete/<?= (int)$flag['athlete_id'] ?>" class="btn btn-secondary btn-sm">View athlete</a>
            <form method="POST" action="/app/coach/flags/<?= (int)$flag['id'] ?>/dismiss" style="display:inline;">
                <?= Auth::csrfField() ?>
                <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($warningFlags)): ?>
    <div class="section-label" style="margin-top:<?= !empty($criticalFlags) ? '24px' : '0' ?>;color:var(--color-warning);">WARNINGS</div>
    <?php foreach ($warningFlags as $flag): ?>
    <div class="roster-row severity-warning" style="margin-bottom:8px;">
        <div class="roster-row-meta">
            <span style="font-size:13px;font-weight:600;"><?= h($flag['athlete_name']) ?></span>
            <span class="pill" style="background:#FEF9C3;color:#92400E;font-size:10px;margin-left:8px;">Warning</span>
        </div>
        <div class="roster-row-body">
            <span style="font-size:13px;color:var(--text-secondary);"><?= h($flag['flag_type']) ?></span>
            <?php if ($flag['flag_message']): ?>
            <p class="body-text" style="margin:4px 0 0;"><?= h($flag['flag_message']) ?></p>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;margin-top:10px;">
            <a href="/app/coach/athlete/<?= (int)$flag['athlete_id'] ?>" class="btn btn-secondary btn-sm">View athlete</a>
            <form method="POST" action="/app/coach/flags/<?= (int)$flag['id'] ?>/dismiss" style="display:inline;">
                <?= Auth::csrfField() ?>
                <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (empty($criticalFlags) && empty($warningFlags)): ?>
    <div class="card" style="border-left:3px solid var(--color-success);">
        <span style="font-size:13px;color:var(--text-secondary);">All athletes are on track. No open alerts.</span>
    </div>
    <?php endif; ?>

    <?php if (!empty($pendingPlans)): ?>
    <div class="section-label" style="margin-top:16px;">APPROVALS PENDING</div>
    <?php foreach ($pendingPlans as $plan): ?>
    <div class="card" style="margin-bottom:8px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <div>
                <div style="font-size:13px;font-weight:600;"><?= h($plan['athlete_name']) ?></div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                    <?= h(ucfirst(str_replace('_', ' ', $plan['plan_type']))) ?>
                    · <?= date('M j', strtotime($plan['plan_start_date'])) ?> – <?= date('M j, Y', strtotime($plan['plan_end_date'])) ?>
                </div>
            </div>
            <a href="/app/coach/approvals" class="btn btn-primary btn-sm">Review</a>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($upcomingRaces)): ?>
    <div class="section-label" style="margin-top:16px;">UPCOMING RACES (14 days)</div>
    <?php foreach ($upcomingRaces as $race): ?>
    <div class="card" style="margin-bottom:8px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
            <div>
                <div style="font-size:13px;font-weight:600;"><?= h($race['athlete_name']) ?></div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                    <?= h($race['race_name'] ?? ucfirst($race['race_distance'])) ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:15px;font-weight:600;color:var(--accent-mid);">
                    <?php $days = (int)ceil((strtotime($race['race_date']) - time()) / 86400); ?>
                    <?= $days ?> day<?= $days !== 1 ? 's' : '' ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted);"><?= date('M j', strtotime($race['race_date'])) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($unreadMessages)): ?>
    <div class="section-label" style="margin-top:16px;">MESSAGES</div>
    <?php foreach ($unreadMessages as $msg): ?>
    <div class="card" style="margin-bottom:8px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
            <div>
                <div style="font-size:13px;font-weight:600;"><?= h($msg['athlete_name']) ?></div>
                <div style="font-size:12px;color:var(--text-secondary);margin-top:2px;
                            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:240px;">
                    <?= h($msg['body']) ?>
                </div>
            </div>
            <span style="font-size:11px;color:var(--text-muted);white-space:nowrap;">
                <?= date('M j', strtotime($msg['sent_at'])) ?>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

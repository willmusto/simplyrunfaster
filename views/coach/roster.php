<?php
// $athletes = roster array (sorted by $sort)
// Design-language propagation (screen 2 after the dashboard reference): same
// data, same links, presentation on the shared kit. The roster rows finally use
// their own .roster-row list-in-card contract (contiguous rows in one surface)
// instead of floating cards.
$sort = $_GET['sort'] ?? 'alerts';
?>
<div class="page-content">

    <div class="section-head" style="margin-bottom:var(--space-3);">
        <div class="page-heading">Athletes
            <span style="font-size:var(--text-md);font-weight:400;color:var(--text-muted);margin-left:6px;">
                (<?= count($athletes) ?>)
            </span>
        </div>
        <a href="/app/coach/invites" class="btn btn-secondary btn-sm">+ Invite athlete</a>
    </div>

    <!-- Sort bar: quiet filter chips, active carries the accent fill -->
    <div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-3);flex-wrap:wrap;">
        <?php foreach ([
            'alerts'     => 'Alerts',
            'compliance' => 'Compliance',
            'race_date'  => 'Race date',
            'name'       => 'Name',
        ] as $key => $label): ?>
        <a href="?sort=<?= h($key) ?>" class="filter-chip <?= $sort === $key ? 'is-active' : '' ?>">
            <?= h($label) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($athletes)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">👟</div>
        <div class="empty-state-title">No athletes yet</div>
        <p class="body-text">Share your invite link to bring athletes onto the platform.</p>
    </div>
    <?php else: ?>

    <div class="list-card">
        <?php foreach ($athletes as $a):
            $hasCritical = ($a['open_critical'] ?? 0) > 0;
            $hasWarning  = ($a['open_warnings'] ?? 0) > 0;
            $severityClass = $hasCritical ? 'severity-critical' : ($hasWarning ? 'severity-warning' : '');

            $compliance = $a['avg_compliance'] !== null ? (float)$a['avg_compliance'] : null;
            $complianceColor = $compliance === null
                ? 'var(--text-muted)'
                : ($compliance >= 0.85
                    ? 'var(--color-success)'
                    : ($compliance >= 0.70 ? 'var(--color-warning)' : 'var(--color-danger)'));
        ?>
        <a href="/app/coach/athlete/<?= (int)$a['id'] ?>" class="list-row <?= $severityClass ?>">

            <!-- Avatar -->
            <div class="athlete-avatar" style="flex-shrink:0;">
                <?= h(avatar_initials($a['name'])) ?>
            </div>

            <!-- Main info -->
            <div class="row-main">
                <div style="display:flex;align-items:center;gap:var(--space-2);flex-wrap:wrap;">
                    <span class="row-name"><?= h($a['name']) ?></span>
                    <?php if ($hasCritical): ?>
                    <span class="pill" style="background:var(--danger-fill);color:var(--color-danger);font-size:10px;">
                        <?= (int)$a['open_critical'] ?> critical
                    </span>
                    <?php elseif ($hasWarning): ?>
                    <span class="pill" style="background:var(--warning-fill);color:var(--color-warning);font-size:10px;">
                        <?= (int)$a['open_warnings'] ?> warning<?= $a['open_warnings'] > 1 ? 's' : '' ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($a['unread_messages'])): ?>
                    <span class="roster-msg-badge" title="<?= (int)$a['unread_messages'] ?> unread">
                        <?= (int)$a['unread_messages'] > 9 ? '9+' : (int)$a['unread_messages'] ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($a['pending_regen'])): ?>
                    <span class="pill" style="background:var(--warning-fill);color:var(--color-warning);font-size:10px;"
                          title="Pending plan regeneration request">Regen request</span>
                    <?php endif; ?>
                </div>
                <div class="row-meta">
                    <?php if ($a['plan_type']): ?>
                    <?= h(ucfirst(str_replace('_', ' ', $a['plan_type']))) ?>
                    <?php else: ?>
                    No active plan
                    <?php endif; ?>
                    <?php if ($a['next_race_date']): ?>
                    &middot; <?= h(ucfirst($a['next_race_distance'] ?? '')) ?> <?= date('M j', strtotime($a['next_race_date'])) ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Compliance + affordance -->
            <div style="display:flex;align-items:center;gap:var(--space-3);flex-shrink:0;">
                <?php if ($compliance !== null): ?>
                <div class="count-cell">
                    <div class="n" style="color:<?= $complianceColor ?>;"><?= round($compliance * 100) ?>%</div>
                    <div class="sub">28d compliance</div>
                </div>
                <?php endif; ?>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" style="color:var(--text-muted);flex-shrink:0;">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </div>

        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

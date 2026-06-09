<?php
// $athletes = roster array (sorted by $sort)
$sort = $_GET['sort'] ?? 'alerts';
?>
<div class="page-content">

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
        <div class="page-heading">Athletes
            <span style="font-size:14px;font-weight:400;color:var(--text-muted);margin-left:6px;">
                (<?= count($athletes) ?>)
            </span>
        </div>
    </div>

    <!-- Sort bar -->
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
        <?php foreach ([
            'alerts'     => 'Alerts',
            'compliance' => 'Compliance',
            'race_date'  => 'Race date',
            'name'       => 'Name',
        ] as $key => $label): ?>
        <a href="?sort=<?= h($key) ?>"
           style="font-size:12px;padding:5px 12px;border-radius:20px;
                  background:<?= $sort === $key ? 'var(--accent-mid)' : 'var(--recessed-bg)' ?>;
                  color:<?= $sort === $key ? '#fff' : 'var(--text-secondary)' ?>;
                  text-decoration:none;white-space:nowrap;">
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
    <a href="/coach/athlete/<?= (int)$a['id'] ?>" class="roster-row <?= $severityClass ?>"
       style="text-decoration:none;display:block;margin-bottom:8px;">
        <div style="display:flex;align-items:center;gap:10px;">

            <!-- Avatar -->
            <div class="athlete-avatar" style="flex-shrink:0;">
                <?= h(avatar_initials($a['name'])) ?>
            </div>

            <!-- Main info -->
            <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span style="font-size:14px;font-weight:600;"><?= h($a['name']) ?></span>
                    <?php if ($hasCritical): ?>
                    <span class="pill" style="background:#FDECEA;color:#991B1B;font-size:10px;">
                        <?= (int)$a['open_critical'] ?> critical
                    </span>
                    <?php elseif ($hasWarning): ?>
                    <span class="pill" style="background:#FEF9C3;color:#92400E;font-size:10px;">
                        <?= (int)$a['open_warnings'] ?> warning<?= $a['open_warnings'] > 1 ? 's' : '' ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                    <?php if ($a['plan_type']): ?>
                    <?= h(ucfirst(str_replace('_', ' ', $a['plan_type']))) ?>
                    <?php else: ?>
                    No active plan
                    <?php endif; ?>
                    <?php if ($a['next_race_date']): ?>
                    · <?= h(ucfirst($a['next_race_distance'] ?? '')) ?> <?= date('M j', strtotime($a['next_race_date'])) ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Compliance + arrow -->
            <div style="display:flex;align-items:center;gap:12px;flex-shrink:0;">
                <?php if ($compliance !== null): ?>
                <div style="text-align:right;">
                    <div style="font-size:15px;font-weight:600;color:<?= $complianceColor ?>;">
                        <?= round($compliance * 100) ?>%
                    </div>
                    <div style="font-size:10px;color:var(--text-muted);">28d compliance</div>
                </div>
                <?php endif; ?>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" style="color:var(--text-muted);flex-shrink:0;">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </div>

        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

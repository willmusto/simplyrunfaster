<?php
// $flags
$bySeverity = ['critical' => [], 'warning' => [], 'info' => []];
foreach ($flags as $f) {
    $bySeverity[$f['severity'] ?? 'info'][] = $f;
}
?>
<div class="page-content">

    <div class="page-heading" style="margin-bottom:4px;">Alerts</div>
    <p class="body-text" style="margin-bottom:24px;">
        Engine-generated flags that need your attention.
    </p>

    <?php if (empty($flags)): ?>
    <div class="card" style="border-left:3px solid var(--color-success);">
        <div class="empty-state" style="padding:24px 0;">
            <div class="empty-state-title">No open alerts</div>
            <p class="body-text">All athletes are on track. Check back after the next plan engine run.</p>
        </div>
    </div>
    <?php else: ?>

    <?php foreach ([
        'critical' => ['CRITICAL', '#FDECEA', '#991B1B', 'severity-critical'],
        'warning'  => ['WARNINGS', '#FEF9C3', '#92400E', 'severity-warning'],
        'info'     => ['INFO',     'var(--recessed-bg)', 'var(--text-secondary)', ''],
    ] as $sev => [$label, $pillBg, $pillColor, $rowClass]):
        if (empty($bySeverity[$sev])) continue;
    ?>
    <div class="section-label" style="color:<?= $pillColor ?>;margin-top:20px;"><?= $label ?></div>
    <?php foreach ($bySeverity[$sev] as $flag): ?>
    <div class="roster-row <?= $rowClass ?>" style="margin-bottom:8px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <div>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                    <span style="font-size:14px;font-weight:600;"><?= h($flag['athlete_name']) ?></span>
                    <span class="pill" style="font-size:10px;background:<?= $pillBg ?>;color:<?= $pillColor ?>;">
                        <?= h($flag['flag_type']) ?>
                    </span>
                </div>
                <?php if ($flag['message']): ?>
                <p class="body-text" style="margin:0 0 8px;"><?= h($flag['message']) ?></p>
                <?php endif; ?>
                <?php if (($flag['flag_type'] ?? '') === 'profile_updated'): ?>
                <?= render_profile_diff($flag['details'] ?? null) ?>
                <?php endif; ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">
                    Raised <?= h(Timezone::format($flag['created_at'], 'M j, Y')) ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <a href="/app/coach/athlete/<?= (int)$flag['athlete_id'] ?>" class="btn btn-secondary btn-sm">View</a>
                <form method="POST" action="/app/coach/flags/<?= (int)$flag['id'] ?>/dismiss">
                    <?= Auth::csrfField() ?>
                    <button type="submit" class="btn btn-sm"
                            style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

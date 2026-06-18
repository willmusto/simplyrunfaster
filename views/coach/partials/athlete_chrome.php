<?php
/**
 * Shared coach athlete-view chrome: back link, athlete header (name, phase · week · race meta,
 * on-track dot), and the sub-nav tab strip. Included at the top of every athlete sub-page so the
 * header + strip are identical and the active tab reflects the current page.
 *
 * Vars: $athlete, $chromeActive, $chrome (from CoachController::athleteChromeData()).
 */
$aid  = (int)$athlete['id'];
$meta = $chrome ?? [];

$bits = [];
if (!empty($meta['phase'])) {
    $p = h((string)$meta['phase']);
    if (!empty($meta['week'])) {
        $p .= ' · Week ' . (int)$meta['week'] . (!empty($meta['total_weeks']) ? ' of ' . (int)$meta['total_weeks'] : '');
    }
    $bits[] = $p;
}
if (!empty($meta['race'])) {
    $r = $meta['race'];
    $bits[] = h((string)$r['race_name']) . ' · ' . h(date('M j', strtotime((string)$r['race_date'])));
}
$dot      = $meta['on_track'] ?? 'green';
$dotColor = $dot === 'red' ? 'var(--color-danger)' : ($dot === 'amber' ? '#F59E0B' : '#1D9E75');
$dotLabel = $dot === 'red' ? 'Open critical flags' : ($dot === 'amber' ? 'Open warnings' : 'On track');
?>
<div class="av-chrome">
    <a href="/app/coach/athletes" class="av-chrome-back">← Athletes</a>
    <div class="av-chrome-head">
        <div class="athlete-avatar"><?= h(avatar_initials($athlete['name'])) ?></div>
        <div class="av-chrome-id">
            <div class="av-chrome-name">
                <?= h($athlete['name']) ?>
                <span class="av-dot" title="<?= h($dotLabel) ?>" style="background:<?= $dotColor ?>;"></span>
            </div>
            <div class="av-chrome-meta"><?= $bits ? implode(' &nbsp;·&nbsp; ', $bits) : 'No active plan' ?></div>
        </div>
    </div>
    <?php include __DIR__ . '/athlete_tabs.php'; ?>
</div>

<?php
/**
 * Flags tab — the athlete's FULL flag record (read-only). Two sections: Open (the flags currently
 * needing attention, also actionable on the Plan / Intelligence views) and Resolved (the history,
 * each with its resolution stamp: dismissed / acted on / auto-resolved, when, by whom, and the
 * dismissal reason when recorded). Acting on flags stays on the Plan view — no controls here.
 *
 * Vars: $athlete, $flagRecord (['open'=>[], 'resolved'=>[]] from athleteFlagRecord), $chrome,
 *       $chromeActive='flags'.
 */
$sevColor = static function (string $sev): array {
    return match ($sev) {
        'critical'    => ['#FDECEA', '#991B1B'],
        'warning'     => ['#FEF9C3', '#92400E'],
        'opportunity' => ['#eef7f2', '#0F6E56'],
        default       => ['var(--recessed-bg)', 'var(--text-muted)'], // info
    };
};

$card = static function (array $f) use ($sevColor): void {
    [$bg, $fg] = $sevColor((string)$f['severity']);
    $resolved  = empty($f['is_open']);
    ?>
    <div class="roster-row" style="margin-bottom:8px;<?= $resolved ? 'opacity:.85;border-left:3px solid var(--text-muted);' : 'border-left:3px solid ' . ($f['severity'] === 'critical' ? 'var(--color-danger)' : ($f['severity'] === 'opportunity' ? '#1D9E75' : 'var(--color-warning)')) . ';' ?>">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span class="pill" style="font-size:10px;background:<?= $bg ?>;color:<?= $fg ?>;"><?= h(ucfirst((string)$f['severity'])) ?></span>
            <span style="font-size:13px;font-weight:500;"><?= h((string)$f['title']) ?></span>
            <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--text-muted);"><?= $f['source'] === 'intel' ? 'Intelligence' : 'Engine' ?></span>
            <span style="font-size:11px;color:var(--text-muted);margin-left:auto;">
                Raised <?= h(Timezone::format($f['created_at'], 'M j, Y')) ?>
            </span>
        </div>
        <?php if (!empty($f['message'])): ?>
        <p class="body-text" style="margin:6px 0 0;"><?= h((string)$f['message']) ?></p>
        <?php endif; ?>
        <?php if (($f['flag_type'] ?? '') === 'profile_updated' && !empty($f['details'])): ?>
        <?= render_profile_diff($f['details']) ?>
        <?php endif; ?>
        <?php if ($resolved && !empty($f['resolution'])): $r = $f['resolution']; ?>
        <div style="font-size:11px;color:var(--text-muted);margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--text-secondary);"><?= h((string)$r['label']) ?></span>
            <?php if (!empty($r['at'])): ?><span><?= h(Timezone::format($r['at'], 'M j, Y')) ?></span><?php endif; ?>
            <?php if (!empty($r['by'])): ?><span>· by <?= h((string)$r['by']) ?></span><?php endif; ?>
            <?php if (!empty($r['reason'])): ?><span>· <?= h((string)$r['reason']) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
};

$open     = $flagRecord['open'] ?? [];
$resolved = $flagRecord['resolved'] ?? [];
?>
<div class="page-content">

    <!-- Shared chrome: back + header + sub-nav tab strip -->
    <?php include __DIR__ . '/partials/athlete_chrome.php'; ?>

    <p class="body-text" style="margin:-6px 0 18px;color:var(--text-muted);font-size:13px;">
        The full flag record for this athlete — open and resolved. Read-only; review and act from the plan view.
    </p>

    <!-- ════════ OPEN ════════ -->
    <div class="section-label">OPEN</div>
    <?php if (empty($open)): ?>
    <div class="card" style="border-left:3px solid #1D9E75;margin-bottom:24px;">
        <div class="empty-state" style="padding:18px 0;">
            <div class="empty-state-title">No open flags</div>
            <p class="body-text">This athlete is on track right now.</p>
        </div>
    </div>
    <?php else: ?>
    <?php foreach ($open as $f) { $card($f); } ?>
    <div style="margin-bottom:16px;"></div>
    <?php endif; ?>

    <!-- ════════ RESOLVED ════════ -->
    <div class="section-label" style="margin-top:12px;">RESOLVED</div>
    <?php if (empty($resolved)): ?>
    <div class="card" style="margin-bottom:24px;">
        <p class="body-text" style="margin:0;">No resolved flags in the last 90 days.</p>
    </div>
    <?php else: ?>
    <?php foreach ($resolved as $f) { $card($f); } ?>
    <?php endif; ?>

</div>

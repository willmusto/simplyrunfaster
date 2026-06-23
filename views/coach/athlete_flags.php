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
// One canonical flag-card layout for every card here (open + resolved): pills + title
// top-left, resolution badge / raised-date top-right, message body, then the structured
// profile diff (its own full-width region). No per-instance hardcoded severity colors.
$card = static function (array $f): void {
    $sev       = (string)($f['severity'] ?? 'info');
    $resolved  = empty($f['is_open']);
    $isProfile = ($f['flag_type'] ?? '') === 'profile_updated';

    // Severity → .flag-card-* rail modifier (resolved overrides to the muted rail).
    $railClass = $resolved ? 'flag-card-resolved' : match ($sev) {
        'critical'    => 'flag-card-critical',
        'warning'     => 'flag-card-warning',
        'opportunity' => 'flag-card-opportunity',
        default       => 'flag-card-info',
    };
    // Severity pill: critical/warning use the canonical .pill-* classes; opportunity/info
    // (no canonical class) use theme tokens — never hardcoded hex.
    $sevPillClass = match ($sev) {
        'critical' => 'pill-critical',
        'warning'  => 'pill-warning',
        default    => '',
    };
    $sevPillStyle = $sevPillClass === ''
        ? ($sev === 'opportunity'
            ? 'background:var(--accent-fill);color:var(--accent-strong);'
            : 'background:var(--recessed-bg);color:var(--text-secondary);')
        : '';
    ?>
    <div class="flag-card <?= $railClass ?>" style="margin-bottom:8px;">
        <div class="flag-body">
            <div class="flag-card-head">
                <div class="flag-card-titlewrap">
                    <span class="pill <?= $sevPillClass ?>" style="font-size:10px;<?= $sevPillStyle ?>"><?= h(ucfirst($sev)) ?></span>
                    <span class="flag-card-title"><?= h((string)$f['title']) ?></span>
                    <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--text-muted);"><?= $f['source'] === 'intel' ? 'Intelligence' : 'Engine' ?></span>
                </div>
                <div class="flag-card-aside">
                    <?php if ($resolved && !empty($f['resolution'])): $r = $f['resolution']; ?>
                    <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--text-secondary);"><?= h((string)$r['label']) ?></span>
                    <?php if (!empty($r['at'])): ?><span><?= h(Timezone::format($r['at'], 'M j, Y')) ?></span><?php endif; ?>
                    <?php else: ?>
                    <span>Raised <?= h(Timezone::format($f['created_at'], 'M j, Y')) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php // profile_updated shows the structured diff only — no prose restatement. ?>
            <?php if (!$isProfile && !empty($f['message'])): ?>
            <p class="flag-card-msg"><?= h((string)$f['message']) ?></p>
            <?php endif; ?>

            <?php if ($isProfile && !empty($f['details'])): ?>
            <?= render_profile_diff($f['details']) ?>
            <?php endif; ?>

            <?php if ($resolved && !empty($f['resolution'])): $r = $f['resolution']; ?>
            <div class="flag-card-foot">
                Raised <?= h(Timezone::format($f['created_at'], 'M j, Y')) ?>
                <?php if (!empty($r['by'])): ?> · by <?= h((string)$r['by']) ?><?php endif; ?>
                <?php if (!empty($r['reason'])): ?> · <?= h((string)$r['reason']) ?><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
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

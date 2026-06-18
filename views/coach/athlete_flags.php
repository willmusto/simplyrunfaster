<?php
/**
 * Flags tab — read-only surface of this athlete's open engine flags. Reuses the same flag-card
 * rendering as the Plan view's OPEN ALERTS, minus the dismiss/act controls (acting on flags
 * stays on the Plan view / Intelligence page). No new flag logic.
 *
 * Vars: $athlete, $athleteFlags (CoachController::getAthleteFlags), $chrome, $chromeActive='flags'.
 */
?>
<div class="page-content">

    <!-- Shared chrome: back + header + sub-nav tab strip -->
    <?php include __DIR__ . '/partials/athlete_chrome.php'; ?>

    <p class="body-text" style="margin:-6px 0 18px;color:var(--text-muted);font-size:13px;">
        Open flags for this athlete. Read-only — review and act from the plan view.
    </p>

    <?php if (empty($athleteFlags)): ?>
    <div class="card" style="border-left:3px solid #1D9E75;margin-bottom:16px;">
        <div class="empty-state" style="padding:20px 0;">
            <div class="empty-state-title">No open flags</div>
            <p class="body-text">This athlete is on track. Flags appear here when the engine raises one.</p>
        </div>
    </div>
    <?php else: ?>
    <?php foreach ($athleteFlags as $flag): ?>
    <div class="roster-row <?= $flag['severity'] === 'critical' ? 'severity-critical' : 'severity-warning' ?>" style="margin-bottom:8px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span class="pill" style="font-size:10px;background:<?= $flag['severity'] === 'critical' ? '#FDECEA' : '#FEF9C3' ?>;
                         color:<?= $flag['severity'] === 'critical' ? '#991B1B' : '#92400E' ?>;">
                <?= h(ucfirst($flag['severity'])) ?>
            </span>
            <span style="font-size:13px;font-weight:500;"><?= h($flag['flag_type']) ?></span>
            <span style="font-size:11px;color:var(--text-muted);margin-left:auto;">
                <?= h(Timezone::format($flag['created_at'], 'M j, Y')) ?>
            </span>
        </div>
        <?php if (!empty($flag['message'])): ?>
        <p class="body-text" style="margin:6px 0 0;"><?= h($flag['message']) ?></p>
        <?php endif; ?>
        <?php if (($flag['flag_type'] ?? '') === 'profile_updated'): ?>
        <?= render_profile_diff($flag['details'] ?? null) ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

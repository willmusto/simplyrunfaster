<?php
/**
 * Admin coach analytics (CIL Phase 4, B1). Read-only per-coach aggregations.
 * Vars: $rows, $multiCoach, $flashSuccess, $flashError.
 */
$pct = static fn($v) => $v === null ? '—' : round((float)$v * 100) . '%';
?>
<div class="page-content">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:4px;">
        <div class="page-heading" style="margin-bottom:0;">Coach analytics</div>
        <a href="/app/admin/users" style="color:var(--text-muted);text-decoration:none;font-size:13px;">← Users</a>
    </div>
    <p class="body-text" style="margin-bottom:16px;color:var(--text-muted);font-size:13px;">
        Compliance: mean completed compliance over the last 28 days. Flag resolution: mean hours from
        raise to a coach action (actioned/dismissed) over the last 90 days — auto-resolved (superseded)
        flags are excluded. Retention: active ÷ all-time-assigned athletes.
    </p>

    <?php if (!$multiCoach): ?>
    <div class="card" style="margin-bottom:24px;">
        <p class="body-text" style="margin:0;">Coach analytics appear once there are at least two coaching accounts.
        With a single coach there is nothing to compare.</p>
    </div>
    <?php elseif (empty($rows)): ?>
    <div class="card" style="margin-bottom:24px;">
        <p class="body-text" style="margin:0;">No coaching accounts to report on yet.</p>
    </div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="text-align:left;color:var(--text-muted);border-bottom:1px solid var(--border-color);">
                    <th style="padding:10px 12px;">Coach</th>
                    <th style="padding:10px 12px;">Role</th>
                    <th style="padding:10px 12px;">Active athletes</th>
                    <th style="padding:10px 12px;">Avg compliance (28d)</th>
                    <th style="padding:10px 12px;">Avg flag resolution</th>
                    <th style="padding:10px 12px;">Resolved (90d)</th>
                    <th style="padding:10px 12px;">Retention</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr style="border-bottom:1px solid var(--recessed-bg);">
                    <td style="padding:10px 12px;font-weight:600;"><?= h($r['name']) ?></td>
                    <td style="padding:10px 12px;color:var(--text-muted);"><?= $r['role'] === 'coach' ? 'Head coach' : 'Assistant' ?></td>
                    <td style="padding:10px 12px;"><?= (int)$r['active_athletes'] ?> / <?= (int)$r['total_athletes'] ?></td>
                    <td style="padding:10px 12px;"><?= $pct($r['avg_compliance']) ?></td>
                    <td style="padding:10px 12px;"><?= $r['resolve_hours'] === null ? '—' : round((float)$r['resolve_hours'], 1) . ' h' ?></td>
                    <td style="padding:10px 12px;">
                        <?= (int)$r['resolved_count'] ?>
                        <?php if ((int)$r['superseded_count'] > 0): ?>
                        <span style="color:var(--text-muted);font-size:11px;">(+<?= (int)$r['superseded_count'] ?> auto)</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 12px;"><?= $pct($r['retention']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
/**
 * Admin billing overview. Vars: $rows, $filter, $counts, $statuses.
 */
$statusColor = [
    'active'   => 'var(--color-success)',
    'comped'   => 'var(--color-success)',
    'trialing' => 'var(--color-success)',
    'past_due' => 'var(--color-warning)',
    'canceled' => 'var(--color-danger)',
    'none'     => 'var(--text-muted)',
];
?>
<div class="page-content">
    <div class="page-heading" style="margin-bottom:12px;">Billing overview</div>

    <?php if (!empty($flashSuccess)): ?>
    <div class="alert alert-success" style="margin-bottom:16px;padding:10px 14px;border-radius:var(--radius-card);
         background:var(--color-success-bg,#d1fae5);color:var(--color-success-text,#065f46);font-size:13px;">
        <?= h($flashSuccess) ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
    <div class="alert alert-error" style="margin-bottom:16px;padding:10px 14px;border-radius:var(--radius-card);
         background:#fdecea;color:#991b1b;font-size:13px;">
        <?= h($flashError) ?>
    </div>
    <?php endif; ?>

    <!-- Status filter -->
    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
        <a href="/app/admin/billing"
           style="font-size:12px;padding:5px 12px;border-radius:20px;text-decoration:none;
                  background:<?= $filter === null ? 'var(--accent-mid)' : 'var(--recessed-bg)' ?>;
                  color:<?= $filter === null ? '#fff' : 'var(--text-secondary)' ?>;">All</a>
        <?php foreach (['active','past_due','canceled','comped','trialing','none'] as $s): ?>
        <a href="?status=<?= h($s) ?>"
           style="font-size:12px;padding:5px 12px;border-radius:20px;text-decoration:none;
                  background:<?= $filter === $s ? 'var(--accent-mid)' : 'var(--recessed-bg)' ?>;
                  color:<?= $filter === $s ? '#fff' : 'var(--text-secondary)' ?>;">
            <?= h(Billing::statusLabel($s)) ?> (<?= (int)($counts[$s] ?? 0) ?>)
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($rows)): ?>
    <div class="card"><p class="body-text" style="margin:0;">No athletes match this filter.</p></div>
    <?php else: ?>
    <div class="card" style="overflow-x:auto;padding:0;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="text-align:left;color:var(--text-muted);">
                    <th style="padding:10px 12px;">Name</th>
                    <th style="padding:10px 12px;">Email</th>
                    <th style="padding:10px 12px;">Status</th>
                    <th style="padding:10px 12px;">Interval</th>
                    <th style="padding:10px 12px;">Ends / Grace</th>
                    <th style="padding:10px 12px;">Discount</th>
                    <th style="padding:10px 12px;">Stripe</th>
                    <th style="padding:10px 12px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $st = $r['subscription_status'];
                    $ends = $r['subscription_end_date'] ?: $r['grace_period_ends'];
                    $disc = (int)($r['discount_percent'] ?? 0);
                ?>
                <tr style="border-top:1px solid var(--divider);">
                    <td style="padding:10px 12px;font-weight:600;"><?= h($r['name']) ?></td>
                    <td style="padding:10px 12px;color:var(--text-muted);"><?= h($r['email']) ?></td>
                    <td style="padding:10px 12px;">
                        <span style="color:<?= $statusColor[$st] ?? 'var(--text-muted)' ?>;font-weight:600;">
                            <?= h(Billing::statusLabel($st)) ?>
                        </span>
                    </td>
                    <td style="padding:10px 12px;"><?= h($r['billing_interval'] ? ucfirst($r['billing_interval']) : '–') ?></td>
                    <td style="padding:10px 12px;"><?= $ends ? h(date('M j, Y', strtotime($ends))) : '–' ?></td>
                    <td style="padding:10px 12px;">
                        <?= $disc > 0 ? $disc . '%' . ($r['discount_duration'] ? ' / ' . h($r['discount_duration']) : '') : '–' ?>
                    </td>
                    <td style="padding:10px 12px;">
                        <?php if (!empty($r['stripe_customer_id'])): ?>
                        <a href="https://dashboard.stripe.com/customers/<?= h($r['stripe_customer_id']) ?>"
                           target="_blank" rel="noopener" style="color:var(--accent-mid);">View in Stripe →</a>
                        <?php else: ?>–<?php endif; ?>
                    </td>
                    <td style="padding:10px 12px;text-align:right;">
                        <?php if ($st === 'comped'): ?>
                        <span style="color:var(--text-muted);font-size:12px;">Comped</span>
                        <?php else: ?>
                        <form method="post" action="/app/admin/billing/comp" style="margin:0;"
                              onsubmit="return confirm('Comp this athlete? This cancels any active Stripe subscription immediately.');">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit"
                                    style="font-size:12px;padding:5px 12px;border-radius:6px;border:1px solid var(--divider);
                                           background:var(--recessed-bg);color:var(--text-secondary);cursor:pointer;">Comp</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

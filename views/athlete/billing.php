<?php
/**
 * Athlete billing page. Vars: $billing (Billing::athleteBillingView), $success, $error.
 */
$status   = $billing['status'];
$interval = $billing['interval'];
$planLine = Billing::intervalLabel($interval);
$statusLabel = Billing::statusLabel($status);

$statusColor = [
    'active'   => 'var(--color-success)',
    'comped'   => 'var(--color-success)',
    'trialing' => 'var(--color-success)',
    'past_due' => 'var(--color-warning)',
    'canceled' => 'var(--color-danger)',
    'none'     => 'var(--text-muted)',
][$status] ?? 'var(--text-muted)';

$hideNext = in_array($status, ['comped','canceled','none'], true);
?>
<div class="page-content">
    <div class="page-heading" style="margin-bottom:20px;">Billing</div>

    <?php if ($success): ?><div class="flash flash-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>

    <div class="section-label">SUBSCRIPTION</div>
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;">
            <div style="font-size:15px;font-weight:600;">SimplyRunFaster Coaching</div>
            <span class="pill" style="background:var(--recessed-bg);color:<?= $statusColor ?>;font-weight:600;">
                <?= h($statusLabel) ?>
            </span>
        </div>
        <?php if ($status !== 'none'): ?>
        <div style="font-size:13px;color:var(--text-muted);margin-top:4px;"><?= h($planLine) ?></div>
        <?php endif; ?>

        <?php if ($status === 'comped'): ?>
        <div style="font-size:13px;color:var(--text-muted);margin-top:8px;">Your access is complimentary. No charges apply.</div>
        <?php endif; ?>

        <?php if (!$hideNext && $billing['next_billing_date']): ?>
        <div style="font-size:13px;color:var(--text-muted);margin-top:8px;">
            <?= $billing['cancel_at_period_end'] ? 'Access until' : 'Next billing date' ?>:
            <strong><?= h(date('M j, Y', strtotime($billing['next_billing_date']))) ?></strong>
        </div>
        <?php endif; ?>

        <?php if ($status === 'canceled' && $billing['subscription_end_date']): ?>
        <div style="font-size:13px;color:var(--text-muted);margin-top:8px;">
            You have access until <strong><?= h(date('M j, Y', strtotime($billing['subscription_end_date']))) ?></strong>.
        </div>
        <?php endif; ?>

        <?php if ($status === 'past_due' && $billing['grace_period_ends']): ?>
        <div style="font-size:13px;color:var(--color-warning);margin-top:8px;">
            Payment failed. Please update your payment method by
            <strong><?= h(date('M j, Y', strtotime($billing['grace_period_ends']))) ?></strong> to keep your access.
        </div>
        <?php endif; ?>
    </div>

    <?php if ($status === 'none'): ?>
    <div class="card" style="margin-bottom:16px;">
        <p class="body-text" style="margin:0;">You don't have an active subscription yet.</p>
        <?php if ($billing['can_manage']): ?>
        <a href="/app/billing/portal" class="btn btn-primary btn-sm" style="margin-top:12px;">Manage billing</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($billing['payment_method']): ?>
    <div class="section-label">PAYMENT METHOD</div>
    <div class="card" style="margin-bottom:16px;">
        <div style="font-size:14px;">
            <?= h(ucfirst($billing['payment_method']['brand'])) ?> ending in <?= h($billing['payment_method']['last4']) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($billing['can_manage']): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
        <a href="/app/billing/portal" class="btn btn-secondary btn-sm">Update payment method</a>
        <?php if (in_array($status, ['active','trialing'], true) && !$billing['cancel_at_period_end']): ?>
        <form method="POST" action="/app/billing/cancel"
              onsubmit="return confirm('Cancel your subscription? You\'ll keep access until the end of your current billing period.');"
              style="display:inline;">
            <?= Auth::csrfField() ?>
            <button type="submit" class="btn btn-danger btn-sm">Cancel subscription</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($billing['invoices'])): ?>
    <div class="section-label">INVOICE HISTORY</div>
    <div class="card">
        <?php foreach ($billing['invoices'] as $inv): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;
                    padding:8px 0;border-bottom:1px solid var(--divider);">
            <div style="font-size:13px;">
                <?= h(date('M j, Y', strtotime($inv['date']))) ?>
                <span style="color:var(--text-muted);">· <?= h($inv['status']) ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span style="font-size:13px;font-weight:600;">$<?= h($inv['amount']) ?></span>
                <?php if ($inv['pdf']): ?>
                <a href="<?= h($inv['pdf']) ?>" target="_blank" rel="noopener"
                   style="font-size:12px;color:var(--accent-mid);">PDF</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="margin-top:20px;">
        <a href="/app/settings" style="font-size:13px;color:var(--text-muted);">← Back to settings</a>
    </div>
</div>

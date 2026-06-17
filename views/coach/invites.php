<?php
/**
 * Coach invite-link generation. Vars: $links, $newLink, $success, $error, $stripeReady.
 */
?>
<div class="page-content">
    <div class="page-heading" style="margin-bottom:16px;">Invite athletes</div>

    <?php if ($success): ?><div class="flash flash-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>

    <?php if ($newLink): ?>
    <div class="card" style="margin-bottom:16px;border:1px solid var(--accent-mid);">
        <div style="font-size:13px;font-weight:600;margin-bottom:6px;">New invite link</div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="text" readonly value="<?= h($newLink) ?>" class="form-input"
                   style="flex:1;min-width:200px;font-size:13px;" onclick="this.select();">
            <button type="button" class="btn btn-secondary btn-sm"
                    onclick="navigator.clipboard.writeText('<?= h($newLink) ?>');this.textContent='Copied';">
                Copy
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$stripeReady): ?>
    <div class="flash" style="background:var(--recessed-bg);color:var(--text-muted);">
        Stripe is not configured in this environment. Links still generate, but discounts won't create
        Stripe coupons and athletes won't be sent to checkout.
    </div>
    <?php endif; ?>

    <div class="section-label">CREATE A LINK</div>
    <form method="POST" action="/app/coach/invites" class="card" style="margin-bottom:24px;">
        <?= Auth::csrfField() ?>

        <div class="form-group">
            <label class="form-label" for="notes">Label (optional)</label>
            <input type="text" id="notes" name="notes" class="form-input" maxlength="200"
                   placeholder="e.g. Spring cohort, referral from Sam">
        </div>

        <div class="form-group">
            <label class="form-label">Discount</label>
            <div class="pill-choices" id="discount-choices" style="margin-top:6px;">
                <?php foreach (['0'=>'None','25'=>'25% off','50'=>'50% off','100'=>'100% (comped)'] as $v=>$l): ?>
                <label class="pill-choice <?= $v === '0' ? 'selected' : '' ?>">
                    <input type="radio" name="discount_percent" value="<?= h($v) ?>" <?= $v === '0' ? 'checked' : '' ?>>
                    <?= h($l) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group" id="duration-group" style="display:none;">
            <label class="form-label">Discount duration</label>
            <div class="pill-choices" style="margin-top:6px;">
                <?php foreach (['30d'=>'30 days','60d'=>'60 days','90d'=>'90 days','120d'=>'120 days','365d'=>'1 year','forever'=>'Forever'] as $v=>$l): ?>
                <label class="pill-choice <?= $v === '30d' ? 'selected' : '' ?>">
                    <input type="radio" name="discount_duration" value="<?= h($v) ?>" <?= $v === '30d' ? 'checked' : '' ?>>
                    <?= h($l) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Billing interval offered</label>
            <div class="pill-choices" style="margin-top:6px;">
                <label class="pill-choice selected">
                    <input type="radio" name="billing_interval" value="monthly" checked>
                    Monthly <?= h(STRIPE_PRICE_MONTHLY_DISPLAY) ?>
                </label>
                <label class="pill-choice">
                    <input type="radio" name="billing_interval" value="annual">
                    Annual <?= h(STRIPE_PRICE_ANNUAL_DISPLAY) ?>
                </label>
            </div>
        </div>

        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div class="form-group" style="flex:1;min-width:120px;">
                <label class="form-label" for="expiry_days">Expires in (days)</label>
                <input type="number" id="expiry_days" name="expiry_days" class="form-input"
                       value="<?= (int)INVITE_DEFAULT_EXPIRY_DAYS ?>" min="1" max="90">
            </div>
            <div class="form-group" style="flex:1;min-width:120px;">
                <label class="form-label" for="max_uses">Max uses</label>
                <input type="number" id="max_uses" name="max_uses" class="form-input"
                       value="<?= (int)INVITE_DEFAULT_MAX_USES ?>" min="1" max="100">
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top:8px;">Generate link</button>
    </form>

    <div class="section-label">RECENT LINKS</div>
    <?php if (empty($links)): ?>
    <div class="card"><p class="body-text" style="margin:0;">No invite links yet.</p></div>
    <?php else: ?>
    <?php foreach ($links as $l):
        $deactivated = !empty($l['deactivated_at']);
        $used    = (int)$l['use_count'] >= (int)$l['max_uses'];
        $expired = strtotime($l['expires_at']) < time();
        $active  = !$deactivated && !$used && !$expired;
        $state   = $deactivated ? 'Inactive' : ($used ? 'Used' : ($expired ? 'Expired' : 'Active'));
        $stateColor = $active ? 'var(--color-success)' : ($expired ? 'var(--color-danger)' : 'var(--text-muted)');
        $url = rtrim(APP_URL, '/') . '/invite/' . $l['code'];
        $disc = (int)($l['discount_percent'] ?? 0);
    ?>
    <div class="card" style="margin-bottom:8px;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;">
            <div style="font-size:13px;font-weight:600;"><?= h($l['notes'] ?: 'Invite link') ?></div>
            <span style="font-size:11px;font-weight:600;color:<?= $stateColor ?>;"><?= h($state) ?></span>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
            <?php if ($disc > 0): ?><?= $disc ?>% off<?= $l['discount_duration'] ? ' · ' . h($l['discount_duration']) : '' ?> · <?php endif; ?>
            <?= h(ucfirst($l['billing_interval'] ?? 'monthly')) ?> ·
            <?= (int)$l['use_count'] ?>/<?= (int)$l['max_uses'] ?> used ·
            expires <?= h(date('M j', strtotime($l['expires_at']))) ?>
        </div>
        <?php if ($active): ?>
        <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
            <input type="text" readonly value="<?= h($url) ?>" class="form-input"
                   style="flex:1;min-width:160px;font-size:12px;" onclick="this.select();">
            <button type="button" class="btn btn-secondary btn-sm"
                    onclick="navigator.clipboard.writeText('<?= h($url) ?>');this.textContent='Copied';">Copy</button>
            <form method="POST" action="/app/coach/invites/deactivate" style="margin:0;"
                  onsubmit="return confirm('Deactivate this invite link? Anyone with this link will no longer be able to use it.');">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="invite_id" value="<?= (int)$l['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm">Deactivate</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
// Show the duration selector only when a discount is chosen, and keep the
// pill-choice "selected" styling in sync with the radios.
(function () {
    var group = document.getElementById('duration-group');
    document.querySelectorAll('#discount-choices input[name="discount_percent"]').forEach(function (r) {
        r.addEventListener('change', function () {
            group.style.display = (this.value !== '0') ? 'block' : 'none';
        });
    });
    document.querySelectorAll('.pill-choices').forEach(function (grp) {
        grp.addEventListener('change', function (e) {
            if (e.target.type !== 'radio') return;
            grp.querySelectorAll('.pill-choice').forEach(function (p) { p.classList.remove('selected'); });
            if (e.target.closest('.pill-choice')) e.target.closest('.pill-choice').classList.add('selected');
        });
    });
})();
</script>

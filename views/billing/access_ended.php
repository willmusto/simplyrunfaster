<?php
/**
 * Standalone "access has ended" page — shown by Billing::enforceAthleteAccess
 * when a lapsed athlete hits a gated route.
 * Vars: $reason ('past_due'|'canceled'|'inactive'), $canPortal (bool), $portalUrl.
 */
$theme = Auth::theme();
$headline = $reason === 'past_due' ? 'Your access has ended'
    : ($reason === 'canceled' ? 'Your subscription has ended' : 'Subscription required');
$blurb = $reason === 'past_due'
    ? 'We were unable to process your payment and the grace period has passed. Reactivate your subscription to get back to training.'
    : ($reason === 'canceled'
        ? 'Your subscription has ended. Reactivate any time to pick up where you left off.'
        : 'An active subscription is required to use SimplyRunFaster.');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= h($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access ended | SimplyRunFaster</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/app.css') ?>">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;">
    <div style="text-align:center;max-width:380px;">
        <div style="font-size:40px;margin-bottom:12px;">🔒</div>
        <div style="font-size:20px;font-weight:600;margin-bottom:8px;"><?= h($headline) ?></div>
        <p style="font-size:14px;color:var(--text-muted);margin-bottom:24px;"><?= h($blurb) ?></p>

        <?php if ($canPortal): ?>
        <a href="<?= h($portalUrl) ?>"
           style="display:inline-block;padding:10px 24px;background:var(--accent-mid);
                  color:#fff;border:none;border-radius:8px;font-weight:500;text-decoration:none;">
            Reactivate subscription
        </a>
        <?php else: ?>
        <p style="font-size:13px;color:var(--text-muted);">
            Please contact your coach or <a href="mailto:hello@simplyrunfaster.com">hello@simplyrunfaster.com</a> to reactivate.
        </p>
        <?php endif; ?>

        <div style="margin-top:20px;">
            <a href="/app/logout" style="font-size:13px;color:var(--text-muted);">Sign out</a>
        </div>
    </div>
</div>
</body>
</html>

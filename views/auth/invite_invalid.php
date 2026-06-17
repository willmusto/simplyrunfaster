<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle = 'Invite expired';
// Callers may pass a more specific title/message (e.g. a deactivated link);
// otherwise fall back to the generic expired/used/incorrect copy.
$inviteTitle   = $inviteTitle   ?? 'This invite link is no longer valid';
$inviteMessage = $inviteMessage ?? 'It may have expired, already been used, or the link is incorrect. Ask your coach to send a new invite link.';
include __DIR__ . '/../../views/layout/html_open.php';
?>
<div class="auth-page">
    <div class="auth-card" style="text-align:center;">
        <div class="auth-logo">Simply<span>Run</span>Faster</div>
        <div class="empty-state">
            <div class="empty-state-icon">⏳</div>
            <div class="empty-state-title"><?= h($inviteTitle) ?></div>
            <p class="body-text" style="margin-top:8px;margin-bottom:20px;">
                <?= h($inviteMessage) ?>
            </p>
            <a href="/app/login" class="btn btn-secondary">Back to sign in</a>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

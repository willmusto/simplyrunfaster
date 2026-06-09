<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle = 'Set new password';
include __DIR__ . '/../../views/layout/html_open.php';
?>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">Simply<span>Run</span>Faster</div>

        <h1 style="font-size:18px;font-weight:500;margin-bottom:6px;">Set a new password</h1>
        <p class="body-text" style="margin-bottom:20px;">Choose a new password for your account.</p>

        <?php if ($error): ?>
        <div class="flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/reset-password">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">

            <div class="form-group">
                <label class="form-label" for="password">New password</label>
                <input type="password" id="password" name="password" class="form-input"
                       placeholder="At least 8 characters" required autocomplete="new-password"
                       minlength="<?= PASSWORD_MIN_LENGTH ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="password_confirm">Confirm new password</label>
                <input type="password" id="password_confirm" name="password_confirm" class="form-input"
                       placeholder="Repeat password" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary btn-full" style="margin-top:8px;">
                Set new password
            </button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

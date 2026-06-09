<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle = 'You\'ve been invited';
include __DIR__ . '/../../views/layout/html_open.php';
?>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">Simply<span>Run</span>Faster</div>

        <?php if (!empty($coach)): ?>
        <h1 style="font-size:18px;font-weight:500;margin-bottom:6px;">
            You've been invited to train with <?= h($coach['name']) ?>
        </h1>
        <?php else: ?>
        <h1 style="font-size:18px;font-weight:500;margin-bottom:6px;">You've been invited</h1>
        <?php endif; ?>

        <p class="body-text" style="margin-bottom:20px;">
            Create your account to get started.
        </p>

        <?php if ($error): ?>
        <div class="flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/invite/<?= h($invite['code']) ?>">
            <?= Auth::csrfField() ?>

            <div class="form-group">
                <label class="form-label" for="name">Full name</label>
                <input type="text" id="name" name="name" class="form-input"
                       placeholder="Your name" required autocomplete="name">
            </div>

            <div class="form-group">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" name="email" class="form-input"
                       placeholder="you@example.com" required autocomplete="email">
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input type="password" id="password" name="password" class="form-input"
                       placeholder="At least 8 characters" required autocomplete="new-password"
                       minlength="<?= PASSWORD_MIN_LENGTH ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="password_confirm">Confirm password</label>
                <input type="password" id="password_confirm" name="password_confirm" class="form-input"
                       placeholder="Repeat password" required autocomplete="new-password">
            </div>

            <?php if (!empty($invite['coupon_code'])): ?>
            <div class="flash flash-success" style="margin-bottom:16px;">
                A special pricing offer has been applied to your account.
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-full">Create account &amp; continue</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

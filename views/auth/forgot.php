<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle = 'Forgot password';
include __DIR__ . '/../../views/layout/html_open.php';
?>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">Simply<span>Run</span>Faster</div>

        <h1 style="font-size:18px;font-weight:500;margin-bottom:6px;">Reset your password</h1>
        <p class="body-text" style="margin-bottom:20px;">Enter your email and we'll send you a reset link.</p>

        <?php if ($error): ?>
        <div class="flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="flash flash-success"><?= h($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="/app/forgot-password">
            <?= Auth::csrfField() ?>

            <div class="form-group">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" name="email" class="form-input"
                       placeholder="you@example.com" required autocomplete="email">
            </div>

            <button type="submit" class="btn btn-primary btn-full" style="margin-top:8px;">
                Send reset link
            </button>
        </form>

        <p style="text-align:center;font-size:13px;color:var(--text-muted);margin-top:20px;">
            Remembered it?
            <a href="/app/login" style="color:var(--accent-mid);">Sign in</a>
        </p>
    </div>
</div>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle = 'Sign in';
include __DIR__ . '/../../views/layout/html_open.php';
?>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">Simply<span>Run</span>Faster</div>

        <h1 style="font-size:18px;font-weight:500;margin-bottom:6px;">Welcome back</h1>
        <p class="body-text" style="margin-bottom:20px;">Sign in to your account.</p>

        <?php if ($error): ?>
        <div class="flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/login">
            <?= Auth::csrfField() ?>

            <div class="form-group">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" name="email" class="form-input"
                       placeholder="you@example.com" required autocomplete="email">
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input type="password" id="password" name="password" class="form-input"
                       placeholder="Your password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary btn-full" style="margin-top:8px;">
                Sign in
            </button>
        </form>

        <div class="auth-divider" style="margin-top:24px;">or</div>

        <p style="text-align:center;font-size:13px;color:var(--text-muted);margin-top:16px;">
            Don't have an account?
            <a href="/register" style="color:var(--accent-mid);">Create one</a>
        </p>
    </div>
</div>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

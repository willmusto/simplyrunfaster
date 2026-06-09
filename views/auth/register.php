<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle = 'Create account';
include __DIR__ . '/../../views/layout/html_open.php';
?>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">Simply<span>Run</span>Faster</div>

        <h1 style="font-size:18px;font-weight:500;margin-bottom:6px;">Create your account</h1>
        <p class="body-text" style="margin-bottom:20px;">Get a coach. Get faster. Simply.</p>

        <?php if ($error): ?>
        <div class="flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/app/register">
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

            <button type="submit" class="btn btn-primary btn-full" style="margin-top:8px;">
                Create account
            </button>
        </form>

        <p style="text-align:center;font-size:13px;color:var(--text-muted);margin-top:20px;">
            Already have an account?
            <a href="/app/login" style="color:var(--accent-mid);">Sign in</a>
        </p>
    </div>
</div>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

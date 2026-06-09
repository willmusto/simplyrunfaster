<?php
require_once __DIR__ . '/../../views/layout/base.php';
$pageTitle = 'Link expired';
include __DIR__ . '/../../views/layout/html_open.php';
?>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">Simply<span>Run</span>Faster</div>

        <h1 style="font-size:18px;font-weight:500;margin-bottom:6px;">Link expired</h1>
        <p class="body-text" style="margin-bottom:24px;">
            This password reset link is invalid or has expired.
            Reset links are valid for 1 hour.
        </p>

        <a href="/app/forgot-password" class="btn btn-primary btn-full">
            Request a new link
        </a>

        <p style="text-align:center;font-size:13px;color:var(--text-muted);margin-top:20px;">
            <a href="/app/login" style="color:var(--accent-mid);">Back to sign in</a>
        </p>
    </div>
</div>
<?php include __DIR__ . '/../../views/layout/html_close.php'; ?>

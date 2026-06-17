<!DOCTYPE html>
<html lang="en" data-theme="system">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Session expired | SimplyRunFaster</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/app.css') ?>">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;">
    <div style="text-align:center;max-width:360px;">
        <div style="font-size:44px;margin-bottom:8px;">🔒</div>
        <div style="font-size:20px;font-weight:600;margin-bottom:8px;">Session expired</div>
        <p style="font-size:14px;color:var(--text-muted);margin-bottom:24px;">
            Your session expired. Please refresh the page and try again.
        </p>
        <a href="/app/login" style="display:inline-block;padding:10px 24px;background:var(--accent-mid);
                                    color:#fff;text-decoration:none;border-radius:8px;font-weight:500;">
            Back to login
        </a>
    </div>
</div>
</body>
</html>

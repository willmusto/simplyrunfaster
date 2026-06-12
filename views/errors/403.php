<!DOCTYPE html>
<html lang="en" data-theme="system">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied | SimplyRunFaster</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/app.css') ?>">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;">
    <div style="text-align:center;max-width:360px;">
        <div style="font-size:48px;font-weight:700;color:var(--accent-mid);">403</div>
        <div style="font-size:20px;font-weight:600;margin-bottom:8px;">Access denied</div>
        <p style="font-size:14px;color:var(--text-muted);margin-bottom:24px;">
            You don't have permission to view this page.
        </p>
        <a href="/" style="display:inline-block;padding:10px 24px;background:var(--accent-mid);
                           color:#fff;text-decoration:none;border-radius:8px;font-weight:500;">
            Go home
        </a>
    </div>
</div>
</body>
</html>

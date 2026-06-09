<!DOCTYPE html>
<html lang="en" data-theme="system">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline — SimplyRunFaster</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;">
    <div style="text-align:center;max-width:360px;">
        <div style="font-size:40px;margin-bottom:12px;">📡</div>
        <div style="font-size:20px;font-weight:600;margin-bottom:8px;">You're offline</div>
        <p style="font-size:14px;color:var(--text-muted);margin-bottom:24px;">
            No internet connection. Your last viewed plan is available if it was cached.
        </p>
        <button onclick="window.location.reload()"
                style="display:inline-block;padding:10px 24px;background:var(--accent-mid);
                       color:#fff;border:none;border-radius:8px;font-weight:500;cursor:pointer;">
            Try again
        </button>
    </div>
</div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>SimplyRunFaster</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #F5F3EF;
            color: #1a1a1a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            text-align: center;
            max-width: 400px;
            width: 100%;
        }
        .logo {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 16px;
        }
        .logo span { color: #1D9E75; }
        .tagline {
            font-size: 16px;
            color: #555;
            margin-bottom: 32px;
            line-height: 1.5;
        }
        .btn {
            display: inline-block;
            padding: 12px 28px;
            background: #1D9E75;
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
        }
        .btn:hover { background: #189065; }
        .footer-link {
            margin-top: 28px;
            font-size: 12px;
        }
        .footer-link a { color: #888; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">Simply<span>Run</span>Faster</div>
        <p class="tagline">Real running coaching is coming.<br>A coach. A plan. You getting faster.</p>
        <a href="/app/login" class="btn">Sign in</a>
        <div class="footer-link"><a href="/app/privacy">Privacy Policy</a></div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="en" data-theme="<?= h(Auth::theme()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1D9E75">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="csrf-token" content="<?= h(Auth::csrfToken()) ?>">

    <title><?= h($pageTitle ?? 'SimplyRunFaster') ?> | SimplyRunFaster</title>

    <link rel="manifest" href="/manifest.json">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/icon-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/app.css') ?>">

    <?php if (!empty($extraCss)): ?>
        <?= $extraCss ?>
    <?php endif; ?>
</head>
<body class="<?= h(trim(($bodyClass ?? '') . (isset($activeNav) ? ' coach-page' : ''))) ?>">

<div class="offline-banner">You're offline. Your plan is still available.</div>

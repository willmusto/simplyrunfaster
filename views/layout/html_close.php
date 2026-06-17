
<footer class="app-footer">
    <a href="/app/privacy">Privacy Policy</a>
    <span>&middot;</span>
    <a href="/app/terms">Terms of Service</a>
    <span>&middot; &copy; <?= date('Y') ?> SimplyRunFaster</span>
</footer>

<?php if (Auth::role() === 'athlete'): ?>
<!-- PWA install encouragement banner (athlete only; JS hides it when already installed or dismissed) -->
<div id="srf-install-banner" class="install-banner" hidden>
    <div class="install-banner-content">
        <span class="install-banner-text">Get the full experience. Install the app.</span>
        <button id="srf-install-btn" class="btn btn-teal install-banner-cta">Install app</button>
    </div>
    <button id="srf-install-dismiss" class="install-banner-close" aria-label="Dismiss">&times;</button>
</div>
<?php endif; ?>

<script src="/assets/js/app.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/app.js') ?>"></script>
<?php if (!empty($extraJs)): echo $extraJs; endif; ?>
</body>
</html>

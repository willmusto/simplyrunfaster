
<footer class="app-footer">
    <a href="/app/privacy">Privacy Policy</a>
    <span>&middot;</span>
    <a href="/app/terms">Terms of Service</a>
    <span>&middot; &copy; <?= date('Y') ?> SimplyRunFaster</span>
</footer>

<script src="/assets/js/app.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/app.js') ?>"></script>
<?php if (!empty($extraJs)): echo $extraJs; endif; ?>
</body>
</html>

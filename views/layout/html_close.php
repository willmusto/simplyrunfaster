
<script src="/assets/js/app.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/app.js') ?>"></script>
<?php if (!empty($extraJs)): echo $extraJs; endif; ?>
</body>
</html>

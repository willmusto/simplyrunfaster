<?php
/**
 * Athlete-view sub-nav tab strip: Plan · Log · Messages · Profile · Flags.
 * Each tab links to its existing route; the active tab is $chromeActive. The Flags tab
 * shows an amber count badge only when the athlete has open flags.
 *
 * Vars: $athlete, $chromeActive ('plan'|'log'|'messages'|'profile'|'flags'), $chrome (meta).
 * This is the athlete-view SUB-nav — distinct from the app's mobile bottom tab bar.
 */
$aid       = (int)$athlete['id'];
$active    = $chromeActive ?? '';
$flagCount = (int)($chrome['flag_count'] ?? 0);
$tabs = [
    'plan'     => ['Plan',     '/app/coach/athlete/' . $aid],
    'log'      => ['Log',      '/app/coach/athlete/' . $aid . '/log'],
    'messages' => ['Messages', '/app/coach/athlete/' . $aid . '/messages'],
    'profile'  => ['Profile',  '/app/coach/athlete/' . $aid . '/edit'],
    'flags'    => ['Flags',    '/app/coach/athlete/' . $aid . '/flags'],
];
?>
<nav class="av-tabs" aria-label="Athlete sections">
    <?php foreach ($tabs as $key => [$label, $href]): $is = ($key === $active); ?>
    <a href="<?= h($href) ?>" class="av-tab<?= $is ? ' is-active' : '' ?>"<?= $is ? ' aria-current="page"' : '' ?>>
        <?= h($label) ?><?php if ($key === 'flags' && $flagCount > 0): ?><span class="av-tab-badge"><?= $flagCount ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</nav>

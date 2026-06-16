<?php
// $athlete, $profile, $success, $error, $formAction, $cancelUrl
?>
<div class="page-content">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
        <a href="<?= h($cancelUrl) ?>" style="color:var(--text-muted);text-decoration:none;font-size:20px;">←</a>
        <div class="page-heading" style="margin-bottom:0;">Training Settings</div>
    </div>
    <p class="body-text" style="margin-bottom:20px;color:var(--text-muted);">
        Keep your goal, fitness, and availability current. Changes are saved for your next plan update —
        they don't rebuild your current plan automatically.
    </p>

    <?php if ($success): ?>
    <div class="flash flash-success"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= h($formAction) ?>" data-dirty-watch>
        <?= Auth::csrfField() ?>
        <?php include __DIR__ . '/../partials/profile_form_fields.php'; ?>

        <div style="display:flex;gap:10px;margin-top:8px;">
            <button type="submit" class="btn btn-primary" data-dirty-save>Save changes</button>
            <a href="<?= h($cancelUrl) ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

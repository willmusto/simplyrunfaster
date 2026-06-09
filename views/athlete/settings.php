<?php
// $athlete, $profile, $success
?>
<div class="page-content">
    <div class="page-heading" style="margin-bottom:20px;">Settings</div>

    <?php if ($success): ?>
    <div class="flash flash-success"><?= h($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="/settings">
        <?= Auth::csrfField() ?>

        <div class="section-label">DISPLAY</div>
        <div class="card" style="margin-bottom:16px;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Theme</label>
                <div class="pill-choices" style="margin-top:8px;">
                    <?php foreach (['system' => 'System default', 'light' => 'Light', 'dark' => 'Dark'] as $v => $l): ?>
                    <label class="pill-choice <?= ($athlete['theme_preference'] ?? 'system') === $v ? 'selected' : '' ?>">
                        <input type="radio" name="theme_preference" value="<?= h($v) ?>"
                               <?= ($athlete['theme_preference'] ?? 'system') === $v ? 'checked' : '' ?>>
                        <?= h($l) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($profile): ?>
        <div class="section-label">UNITS</div>
        <div class="card" style="margin-bottom:16px;">
            <div class="form-group" style="margin-bottom:0;">
                <div class="pill-choices">
                    <label class="pill-choice <?= ($profile['units'] ?? 'miles') === 'miles' ? 'selected' : '' ?>">
                        <input type="radio" name="units" value="miles"
                               <?= ($profile['units'] ?? 'miles') === 'miles' ? 'checked' : '' ?>>
                        Miles
                    </label>
                    <label class="pill-choice <?= ($profile['units'] ?? 'miles') === 'km' ? 'selected' : '' ?>">
                        <input type="radio" name="units" value="km"
                               <?= ($profile['units'] ?? 'miles') === 'km' ? 'checked' : '' ?>>
                        Kilometers
                    </label>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="section-label">NOTIFICATIONS</div>
        <div class="card" style="margin-bottom:16px;">
            <p class="body-text">Notification preferences are available in Milestone 2.</p>
        </div>

        <button type="submit" class="btn btn-primary">Save settings</button>
    </form>

    <div class="divider" style="margin:24px 0;"></div>

    <div class="section-label">ACCOUNT</div>
    <div class="card">
        <div style="font-size:14px;font-weight:500;"><?= h($athlete['name']) ?></div>
        <div style="font-size:13px;color:var(--text-muted);margin-top:2px;"><?= h($athlete['email']) ?></div>
        <div class="divider" style="margin:12px 0;"></div>
        <a href="/logout" class="btn btn-danger btn-sm">Sign out</a>
    </div>
</div>

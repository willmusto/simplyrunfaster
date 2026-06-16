<?php
// $athlete, $profile, $success
?>
<div class="page-content">
    <div class="page-heading" style="margin-bottom:20px;">Settings</div>

    <?php if ($success): ?>
    <div class="flash flash-success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/app/settings" data-dirty-watch>
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

        <div class="section-label">TIMEZONE</div>
        <div class="card" style="margin-bottom:16px;">
            <?php
            $selectedTz   = $athlete['timezone'] ?? Timezone::DEFAULT_TZ;
            $tzFieldLabel = 'Your timezone';
            $tzFieldHint  = 'Workout dates and times are shown in this timezone, and new plans start "tomorrow" in your local time.';
            include __DIR__ . '/../partials/timezone_field.php';
            ?>
        </div>

        <button type="submit" class="btn btn-primary" data-dirty-save>Save settings</button>
    </form>

    <div class="section-label" style="margin-top:24px;">NOTIFICATIONS</div>
    <a href="/app/settings/notifications" class="card" style="display:flex;align-items:center;justify-content:space-between;
       text-decoration:none;color:inherit;margin-bottom:16px;">
        <div>
            <div style="font-size:14px;font-weight:500;">Notification preferences</div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:2px;">
                Push &amp; email, delivery times, quiet hours
            </div>
        </div>
        <span style="color:var(--text-muted);font-size:20px;">›</span>
    </a>

    <div class="section-label" style="margin-top:24px;">BILLING</div>
    <a href="/app/billing" class="card" style="display:flex;align-items:center;justify-content:space-between;
       text-decoration:none;color:inherit;margin-bottom:16px;">
        <div>
            <div style="font-size:14px;font-weight:500;">Subscription &amp; billing</div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:2px;">
                Plan, payment method, invoices
            </div>
        </div>
        <span style="color:var(--text-muted);font-size:20px;">›</span>
    </a>

    <div class="divider" style="margin:24px 0;"></div>

    <div class="section-label">TRAINING</div>
    <a href="/app/settings/training" class="card" style="display:flex;align-items:center;justify-content:space-between;
       text-decoration:none;color:inherit;margin-bottom:16px;">
        <div>
            <div style="font-size:14px;font-weight:500;">Training profile</div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:2px;">
                Goal, fitness, availability, easy pace, cross-training
            </div>
        </div>
        <span style="color:var(--text-muted);font-size:20px;">›</span>
    </a>

    <div class="section-label">SECURITY</div>
    <div class="card" style="margin-bottom:16px;">
        <form method="POST" action="/app/settings/password">
            <?= Auth::csrfField() ?>

            <div class="form-group">
                <label class="form-label" for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" class="form-input"
                       placeholder="Your current password" required autocomplete="current-password">
            </div>

            <div class="form-group">
                <label class="form-label" for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" class="form-input"
                       placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters" required autocomplete="new-password"
                       minlength="<?= PASSWORD_MIN_LENGTH ?>">
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="new_password_confirm">Confirm new password</label>
                <input type="password" id="new_password_confirm" name="new_password_confirm" class="form-input"
                       placeholder="Repeat new password" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-secondary btn-sm" style="margin-top:16px;">
                Change password
            </button>
        </form>
    </div>

    <div class="divider" style="margin:24px 0;"></div>

    <div class="section-label">ACCOUNT</div>
    <div class="card">
        <div style="font-size:14px;font-weight:500;"><?= h($athlete['name']) ?></div>
        <div style="font-size:13px;color:var(--text-muted);margin-top:2px;"><?= h($athlete['email']) ?></div>
        <div class="divider" style="margin:12px 0;"></div>
        <a href="/app/logout" class="btn btn-danger btn-sm">Sign out</a>
    </div>
</div>

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

    <?php
    $deviceNotify        = $deviceNotify ?? [];
    $intervalsConnected  = $intervalsConnected ?? false;
    $intervalsLastSynced = $intervalsLastSynced ?? null;
    ?>
    <div class="section-label" style="margin-top:24px;">CONNECTED DEVICES</div>
    <div class="card" style="margin-bottom:16px;" data-device-form>

        <div class="device-row">
            <span class="device-name">
                Intervals.icu
                <?php if ($intervalsConnected): ?>
                <span style="display:inline-block;margin-left:6px;font-size:11px;font-weight:600;color:var(--accent-mid);
                             background:rgba(29,158,117,0.12);border-radius:10px;padding:2px 8px;vertical-align:middle;">Connected</span>
                <?php endif; ?>
                <div style="font-size:12px;color:var(--text-muted);margin-top:3px;font-weight:400;">
                    <?php if ($intervalsConnected): ?>
                        <?= $intervalsLastSynced ? 'Last synced: ' . h($intervalsLastSynced) : 'Connected. Waiting for first sync.' ?>
                        <a href="/app/integrations/intervals/guide">How does this work? →</a>
                    <?php else: ?>
                        Sync workouts to your Garmin, COROS, Polar, Suunto, Wahoo, Amazfit, Apple Watch, or Huawei device.
                        <a href="/app/integrations/intervals/guide">How does this work? →</a>
                    <?php endif; ?>
                </div>
                <?php if ($intervalsConnected): ?>
                <div style="margin-top:8px;">
                    <button type="button" class="btn btn-secondary btn-sm" data-intervals-sync>Sync workouts now</button>
                    <div data-intervals-sync-error style="display:none;font-size:12px;color:var(--color-danger);margin-top:6px;"></div>
                </div>
                <?php endif; ?>
            </span>
            <div class="device-row-right">
                <?php if ($intervalsConnected): ?>
                <form method="POST" action="/app/integrations/intervals/disconnect" style="margin:0;">
                    <?= Auth::csrfField() ?>
                    <button type="submit" class="btn btn-secondary btn-sm">Disconnect</button>
                </form>
                <?php else: ?>
                <a href="/app/integrations/intervals/connect" class="btn btn-primary btn-sm">Connect</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$intervalsConnected): // brand rows are irrelevant once syncing via Intervals.icu ?>
        <?php foreach (['Garmin', 'COROS', 'Polar', 'Suunto'] as $brandName): ?>
        <div class="device-row">
            <span class="device-name"><?= h($brandName) ?></span>
            <div class="device-row-right">
                <span class="badge-soon">Coming soon</span>
            </div>
        </div>
        <?php endforeach; ?>

        <?php $notifyAll = !empty($deviceNotify['all']); ?>
        <div style="font-size:12px;color:var(--text-muted);padding:10px 2px 2px;">
            Want to be notified when direct device integrations are available?
            <a href="#" data-notify-reveal style="<?= $notifyAll ? 'display:none;' : '' ?>">Notify me →</a>
            <label data-notify-row style="align-items:center;gap:8px;margin-top:8px;cursor:pointer;display:<?= $notifyAll ? 'flex' : 'none' ?>;">
                <input type="checkbox" data-notify-all <?= $notifyAll ? 'checked' : '' ?>>
                <span>Notify me when direct watch integrations launch</span>
            </label>
        </div>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        var cb = document.querySelector('[data-notify-all]');
        if (!cb) return; // only present in the disconnected state
        var meta   = document.querySelector('meta[name="csrf-token"]');
        var csrf   = meta ? meta.content : '';
        var reveal = document.querySelector('[data-notify-reveal]');
        var row    = document.querySelector('[data-notify-row]');

        if (reveal && row) {
            reveal.addEventListener('click', function (e) {
                e.preventDefault();
                reveal.style.display = 'none';
                row.style.display = 'flex';
            });
        }

        cb.addEventListener('change', function () {
            var enabled = cb.checked;
            cb.disabled = true;
            fetch('/app/settings/devices/notify', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body:    JSON.stringify({ brand: 'all', enabled: enabled }),
            }).then(function (r) { return r.json(); })
              .then(function (res) { if (!res || !res.success) cb.checked = !enabled; })
              .catch(function () { cb.checked = !enabled; })
              .finally(function () { cb.disabled = false; });
        });
    })();
    </script>

    <script>
    (function () {
        var btn = document.querySelector('[data-intervals-sync]');
        if (!btn) return;
        var errEl = document.querySelector('[data-intervals-sync-error]');
        var meta  = document.querySelector('meta[name="csrf-token"]');
        var csrf  = meta ? meta.content : '';
        var LABEL = 'Sync workouts now';

        btn.addEventListener('click', function () {
            if (btn.disabled) return;
            if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
            btn.disabled = true;
            btn.textContent = 'Syncing…';

            fetch('/app/integrations/intervals/sync-athlete', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            }).then(function (r) { return r.json(); })
              .then(function (res) {
                  if (res && res.success) {
                      var n = res.pushed || 0;
                      btn.textContent = n === 0 ? '✓ Up to date' : '✓ Synced (' + n + ' workout' + (n === 1 ? '' : 's') + ')';
                      btn.style.color = 'var(--accent-mid)';
                      // Re-enable after 60s with the original label.
                      setTimeout(function () {
                          btn.disabled = false;
                          btn.textContent = LABEL;
                          btn.style.color = '';
                      }, 60000);
                  } else {
                      btn.disabled = false;
                      btn.textContent = LABEL;
                      if (errEl) {
                          errEl.textContent = (res && res.message) ? res.message : 'Sync failed. Please try again';
                          errEl.style.display = 'block';
                      }
                  }
              })
              .catch(function () {
                  btn.disabled = false;
                  btn.textContent = LABEL;
                  if (errEl) {
                      errEl.textContent = 'Sync failed. Please try again';
                      errEl.style.display = 'block';
                  }
              });
        });
    })();
    </script>

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

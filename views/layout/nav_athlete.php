<?php
// $activeTab should be set to: today | plan | log | messages | progress | settings
$activeTab      = $activeTab ?? 'today';
$theme          = Auth::theme();
$userName       = Auth::name();
$unreadMessages = $unreadMessages ?? 0;
?>
<nav class="top-nav">
    <div class="logo">Simply<span>Run</span>Faster</div>
    <div class="athlete-nav-links">
        <a href="/app" class="athlete-nav-link <?= $activeTab === 'today'    ? 'active' : '' ?>">Today</a>
        <a href="/app/plan" class="athlete-nav-link <?= $activeTab === 'plan'  ? 'active' : '' ?>">Plan</a>
        <a href="/app/log" class="athlete-nav-link <?= $activeTab === 'log'   ? 'active' : '' ?>">Log</a>
        <a href="/app/messages" class="athlete-nav-link <?= $activeTab === 'messages' ? 'active' : '' ?>">
            Messages<?php if ($unreadMessages > 0): ?><span class="top-nav-unread-badge"><?= $unreadMessages > 9 ? '9+' : $unreadMessages ?></span><?php endif; ?>
        </a>
        <a href="/app/progress" class="athlete-nav-link <?= $activeTab === 'progress' ? 'active' : '' ?>">Progress</a>
        <a href="/app/settings" class="athlete-nav-link <?= $activeTab === 'settings' ? 'active' : '' ?>">Settings</a>
    </div>
    <div class="top-nav-actions">
        <!-- Notification bell (Milestone 2) -->
        <button class="btn-icon" title="Notifications" aria-label="Notifications">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
        </button>
        <!-- Theme toggle -->
        <form method="POST" action="/app/theme" class="theme-toggle-form">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="theme"
                   value="<?= $theme === 'dark' ? 'light' : 'dark' ?>">
            <button type="submit" class="theme-toggle-btn" title="Toggle dark mode">
                <?php if ($theme === 'dark'): ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="5"/>
                    <line x1="12" y1="1" x2="12" y2="3"/>
                    <line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/>
                    <line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
                <?php else: ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
                <?php endif; ?>
            </button>
        </form>
    </div>
</nav>

<?php $srfCancelBanner = Billing::cancellationBanner(Auth::userId()); ?>
<?php if ($srfCancelBanner): ?>
<div id="srf-cancel-banner" style="display:none;border:1px solid var(--accent-mid);background:var(--recessed-bg);
     border-radius:8px;padding:10px 14px;margin:10px 16px;font-size:13px;display:flex;align-items:center;
     justify-content:space-between;gap:10px;flex-wrap:wrap;">
    <span>
        Your subscription is cancelled. You have access until
        <strong><?= h(date('M j, Y', strtotime($srfCancelBanner['end_date']))) ?></strong>.
        <a href="/app/billing/portal" style="color:var(--accent-mid);font-weight:600;">Reactivate</a>
    </span>
    <button type="button" aria-label="Dismiss"
            onclick="sessionStorage.setItem('srfCancelDismissed','1');this.parentElement.style.display='none';"
            style="background:none;border:none;color:var(--text-muted);font-size:18px;cursor:pointer;line-height:1;">×</button>
</div>
<script>
(function () {
    if (sessionStorage.getItem('srfCancelDismissed') !== '1') {
        var b = document.getElementById('srf-cancel-banner');
        if (b) b.style.display = 'flex';
    }
})();
</script>
<?php endif; ?>

<nav class="bottom-nav">
    <a href="/app" class="bottom-nav-item <?= $activeTab === 'today' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>
        </svg>
        Today
    </a>
    <a href="/app/plan" class="bottom-nav-item <?= $activeTab === 'plan' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>
            <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        Plan
    </a>
    <a href="/app/log" class="bottom-nav-item <?= $activeTab === 'log' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>
            <line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
        </svg>
        Log
    </a>
    <a href="/app/messages" class="bottom-nav-item <?= $activeTab === 'messages' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        <?php if ($unreadMessages > 0): ?>
        <span class="nav-unread-badge"><?= $unreadMessages > 9 ? '9+' : $unreadMessages ?></span>
        <?php endif; ?>
        Messages
    </a>
    <a href="/app/progress" class="bottom-nav-item <?= $activeTab === 'progress' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
        </svg>
        Progress
    </a>
    <a href="/app/settings" class="bottom-nav-item <?= $activeTab === 'settings' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
        Settings
    </a>
</nav>

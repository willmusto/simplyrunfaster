<?php
$activeNav = $activeNav ?? 'dashboard';
$theme     = Auth::theme();
$navUnread = class_exists('CoachController') ? CoachController::navUnreadCount() : 0;
?>
<nav class="top-nav">
    <div class="logo">Simply<span>Run</span>Faster</div>
    <div class="top-nav-actions">
        <?php if (!empty($pendingApprovals) && $pendingApprovals > 0): ?>
        <span class="pill pill-warning" style="font-size:11px;"><?= (int)$pendingApprovals ?> pending</span>
        <?php endif; ?>
        <form method="POST" action="/app/theme" class="theme-toggle-form">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="theme" value="<?= $theme === 'dark' ? 'light' : 'dark' ?>">
            <button type="submit" class="theme-toggle-btn" title="Toggle dark mode">
                <?php if ($theme === 'dark'): ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/>
                    <line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/>
                    <line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
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
        <a href="/app/logout" class="btn btn-secondary btn-sm">Sign out</a>
    </div>
</nav>

<!-- Desktop sidebar -->
<nav class="sidebar-nav">
    <div class="sidebar-logo">Simply<span>Run</span>Faster</div>

    <a href="/app/coach" class="sidebar-nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>
        </svg>
        Dashboard
    </a>
    <a href="/app/coach/athletes" class="sidebar-nav-item <?= $activeNav === 'athletes' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        Athletes
    </a>
    <a href="/app/coach/messages" class="sidebar-nav-item <?= $activeNav === 'messages' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        Messages
        <span class="pill pill-critical js-nav-msg-badge" style="margin-left:auto;<?= $navUnread > 0 ? '' : 'display:none;' ?>"><?= (int)$navUnread ?></span>
    </a>
    <a href="/app/coach/approvals" class="sidebar-nav-item <?= $activeNav === 'approvals' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 11 12 14 22 4"/>
            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
        Approvals
        <?php if (!empty($pendingApprovals) && $pendingApprovals > 0): ?>
        <span class="pill pill-warning" style="margin-left:auto;"><?= (int)$pendingApprovals ?></span>
        <?php endif; ?>
    </a>
    <a href="/app/coach/flags" class="sidebar-nav-item <?= $activeNav === 'flags' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Alerts
        <?php if (!empty($openFlags) && $openFlags > 0): ?>
        <span class="pill pill-critical" style="margin-left:auto;"><?= (int)$openFlags ?></span>
        <?php endif; ?>
    </a>
    <a href="/app/coach/library" class="sidebar-nav-item <?= $activeNav === 'library' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
        </svg>
        Library
    </a>
    <a href="/app/coach/settings" class="sidebar-nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
        Settings
    </a>
    <?php if (Auth::isAdmin()): ?>
    <a href="/app/admin/users" class="sidebar-nav-item <?= $activeNav === 'admin_users' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        Users
    </a>
    <a href="/app/admin/billing" class="sidebar-nav-item <?= $activeNav === 'admin_billing' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
        </svg>
        Billing
    </a>
    <?php endif; ?>

    <?php if (!empty($athletes)): ?>
    <div class="sidebar-roster">
        <div class="sidebar-roster-label">Athletes</div>
        <?php foreach ($athletes as $a): ?>
        <a href="/app/coach/athlete/<?= (int)$a['id'] ?>" class="sidebar-roster-item">
            <span style="width:6px;height:6px;border-radius:50%;background:<?=
                ($a['open_critical'] ?? 0) > 0 ? 'var(--color-danger)' :
                (($a['open_warnings'] ?? 0) > 0 ? 'var(--color-warning)' : 'var(--color-success)')
            ?>;flex-shrink:0;"></span>
            <?= h($a['name']) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="padding:12px 20px;border-top:var(--card-border);margin-top:auto;">
        <div style="font-size:13px;color:var(--text-muted);"><?= h(Auth::name()) ?></div>
        <a href="/app/logout" style="font-size:12px;color:var(--text-muted);">Sign out</a>
    </div>
</nav>

<!-- Mobile bottom nav for coach -->
<nav class="bottom-nav">
    <a href="/app/coach" class="bottom-nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>
        </svg>
        Home
    </a>
    <a href="/app/coach/athletes" class="bottom-nav-item <?= $activeNav === 'athletes' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        Athletes
    </a>
    <a href="/app/coach/messages" class="bottom-nav-item <?= $activeNav === 'messages' ? 'active' : '' ?>" style="position:relative;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        Messages
        <span class="js-nav-msg-badge" style="position:absolute;top:4px;right:50%;transform:translateX(18px);min-width:16px;height:16px;padding:0 4px;border-radius:8px;background:var(--color-danger);color:#fff;font-size:10px;line-height:16px;text-align:center;<?= $navUnread > 0 ? '' : 'display:none;' ?>"><?= (int)$navUnread ?></span>
    </a>
    <a href="/app/coach/approvals" class="bottom-nav-item <?= $activeNav === 'approvals' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 11 12 14 22 4"/>
            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
        Approvals
    </a>
    <a href="/app/coach/flags" class="bottom-nav-item <?= $activeNav === 'flags' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Alerts
    </a>
    <a href="/app/coach/settings" class="bottom-nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
        Settings
    </a>
</nav>

<script>
(function () {
    'use strict';
    window.SRF = window.SRF || {};
    function setNavMsgBadge(n) {
        document.querySelectorAll('.js-nav-msg-badge').forEach(function (el) {
            if (n > 0) { el.textContent = n; el.style.display = ''; }
            else { el.style.display = 'none'; }
        });
    }
    window.SRF.setNavMsgBadge = setNavMsgBadge;

    // Poll the unread count on every coach page so the badge stays live.
    function poll() {
        fetch('/app/coach/messages/unread-count', { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && typeof d.count === 'number') setNavMsgBadge(d.count); })
            .catch(function () {});
    }
    setInterval(poll, 10000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
</script>

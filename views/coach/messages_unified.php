<?php
// Coach unified inbox. Vars: $threads (list rows), and optionally $athlete /
// $messages / $planPhase / $backUrl for a preselected right-panel thread.
$preselectId = !empty($athlete) ? (int)$athlete['id'] : 0;
?>
<style>
    .cm-layout {
        position: fixed; top: 0; bottom: 0; left: var(--sidebar-width); right: 0;
        display: flex; background: var(--page-bg);
    }
    .cm-list {
        width: 320px; flex-shrink: 0; display: flex; flex-direction: column;
        border-right: var(--card-border); background: var(--card-bg);
    }
    .cm-search { padding: 12px; border-bottom: var(--card-border); flex-shrink: 0; }
    .cm-search input { width: 100%; }
    .cm-rows { flex: 1 1 auto; overflow-y: auto; }
    .cm-row {
        display: flex; gap: 10px; align-items: center; padding: 11px 14px;
        border-bottom: 1px solid var(--divider); text-decoration: none; color: inherit; cursor: pointer;
    }
    .cm-row:hover { background: var(--recessed-bg); }
    .cm-row.is-active { background: var(--accent-fill); }
    .cm-avatar {
        width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: 14px; font-weight: 600; background: var(--recessed-bg); color: var(--text-secondary);
    }
    .cm-row.unread .cm-avatar { background: var(--accent-mid); color: #fff; }
    .cm-row-main { flex: 1 1 auto; min-width: 0; }
    .cm-row-top { display: flex; justify-content: space-between; gap: 8px; align-items: baseline; }
    .cm-name { font-size: 14px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cm-row.unread .cm-name { font-weight: 700; }
    .cm-time { font-size: 11px; color: var(--text-muted); flex-shrink: 0; }
    .cm-row-bottom { display: flex; justify-content: space-between; gap: 8px; align-items: center; margin-top: 2px; }
    .cm-preview { font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cm-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--accent-mid); flex-shrink: 0; }
    .cm-list-empty { padding: 28px 16px; text-align: center; color: var(--text-muted); font-size: 13px; }

    .cm-thread-panel { position: relative; flex: 1 1 auto; min-width: 0; }
    .cm-placeholder {
        position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
        color: var(--text-muted); font-size: 14px; padding: 20px; text-align: center;
    }
    /* The reused thread component (.msg-screen) is position:fixed for the standalone
       coach chat page; inside the unified layout it must fill the right panel only. */
    body.coach-page .cm-thread-panel .msg-screen { position: absolute; top: 0; left: 0; right: 0; bottom: 0; }

    @media (max-width: 767px) {
        .cm-layout { position: static; left: 0; display: block; }
        .cm-list { width: 100%; border-right: none; }
        .cm-rows { padding-bottom: calc(var(--nav-height-bottom) + 12px); }
        .cm-thread-panel { display: none; }
    }
</style>

<div class="cm-layout">
    <aside class="cm-list">
        <div class="cm-search">
            <input type="text" id="cmSearch" class="form-input" placeholder="Search athletes…" autocomplete="off">
        </div>
        <div class="cm-rows" id="cmRows">
            <?php if (empty($threads)): ?>
            <div class="cm-list-empty">No athletes yet.</div>
            <?php else: ?>
            <?php foreach ($threads as $t): ?>
            <a class="cm-row<?= $t['unread'] ? ' unread' : '' ?><?= $preselectId === $t['id'] ? ' is-active' : '' ?>"
               href="/app/coach/messages/<?= (int)$t['id'] ?>"
               data-athlete-id="<?= (int)$t['id'] ?>"
               data-name="<?= h(mb_strtolower($t['name'])) ?>"
               data-unread="<?= (int)$t['unread_count'] ?>">
                <div class="cm-avatar"><?= $t['initials'] ?></div>
                <div class="cm-row-main">
                    <div class="cm-row-top">
                        <span class="cm-name"><?= h($t['name']) ?></span>
                        <span class="cm-time"><?= h($t['time_label']) ?></span>
                    </div>
                    <div class="cm-row-bottom">
                        <span class="cm-preview"><?= h($t['preview']) ?></span>
                        <?php if ($t['unread']): ?><span class="cm-dot"></span><?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <section class="cm-thread-panel" id="threadPanel" data-active-id="<?= $preselectId ?>">
        <?php if (!empty($athlete)): ?>
        <?php include __DIR__ . '/messages.php'; ?>
        <?php else: ?>
        <div class="cm-placeholder" id="cmPlaceholder">Select an athlete to view messages</div>
        <?php endif; ?>
    </section>
</div>

<script>
(function () {
    'use strict';
    var rows    = document.getElementById('cmRows');
    var panel   = document.getElementById('threadPanel');
    var search  = document.getElementById('cmSearch');
    var desktop = window.matchMedia('(min-width: 768px)');
    var activeId = parseInt(panel.getAttribute('data-active-id'), 10) || 0;

    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

    // ── Badge recompute from the current list rows ──
    function recomputeBadge() {
        var total = 0;
        rows.querySelectorAll('.cm-row').forEach(function (r) { total += parseInt(r.getAttribute('data-unread'), 10) || 0; });
        if (window.SRF && window.SRF.setNavMsgBadge) window.SRF.setNavMsgBadge(total);
    }

    // ── Search filter ──
    function applyFilter() {
        var q = (search.value || '').trim().toLowerCase();
        rows.querySelectorAll('.cm-row').forEach(function (r) {
            r.style.display = (!q || (r.getAttribute('data-name') || '').indexOf(q) !== -1) ? '' : 'none';
        });
    }
    search.addEventListener('input', applyFilter);

    // ── Mark a row read in the DOM ──
    function markRowRead(id) {
        var row = rows.querySelector('.cm-row[data-athlete-id="' + id + '"]');
        if (!row) return;
        row.setAttribute('data-unread', '0');
        row.classList.remove('unread');
        var dot = row.querySelector('.cm-dot');
        if (dot) dot.remove();
        recomputeBadge();
    }

    // ── Desktop: load a thread into the right panel without a page reload ──
    function loadThread(id) {
        fetch('/app/coach/messages/' + id + '/panel', { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                panel.innerHTML = html;
                panel.setAttribute('data-active-id', id);
                activeId = id;
                if (window.SRF && window.SRF.initMessaging) window.SRF.initMessaging();
                rows.querySelectorAll('.cm-row.is-active').forEach(function (r) { r.classList.remove('is-active'); });
                var row = rows.querySelector('.cm-row[data-athlete-id="' + id + '"]');
                if (row) row.classList.add('is-active');
                markRowRead(id);
            }).catch(function () {});
    }

    rows.addEventListener('click', function (e) {
        var row = e.target.closest ? e.target.closest('.cm-row') : null;
        if (!row) return;
        if (!desktop.matches) return;          // mobile: let the <a> navigate to the full page
        e.preventDefault();
        var id = parseInt(row.getAttribute('data-athlete-id'), 10) || 0;
        if (id && id !== activeId) loadThread(id);
    });

    // ── List refresh poll (every 10s) — re-render rows, keep search + selection ──
    function renderRow(t) {
        var a = document.createElement('a');
        a.className = 'cm-row' + (t.unread ? ' unread' : '') + (t.id === activeId ? ' is-active' : '');
        a.href = '/app/coach/messages/' + t.id;
        a.setAttribute('data-athlete-id', t.id);
        a.setAttribute('data-name', (t.name || '').toLowerCase());
        a.setAttribute('data-unread', t.unread_count);
        a.innerHTML =
            '<div class="cm-avatar">' + esc(t.initials) + '</div>' +
            '<div class="cm-row-main">' +
                '<div class="cm-row-top"><span class="cm-name">' + esc(t.name) + '</span>' +
                    '<span class="cm-time">' + esc(t.time_label) + '</span></div>' +
                '<div class="cm-row-bottom"><span class="cm-preview">' + esc(t.preview) + '</span>' +
                    (t.unread ? '<span class="cm-dot"></span>' : '') + '</div>' +
            '</div>';
        return a;
    }
    function refreshList() {
        fetch('/app/coach/messages/threads', { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !Array.isArray(d.threads)) return;
                // The active thread is being read live; don't resurrect its unread state.
                rows.innerHTML = '';
                d.threads.forEach(function (t) {
                    if (t.id === activeId) { t.unread = false; t.unread_count = 0; }
                    rows.appendChild(renderRow(t));
                });
                applyFilter();
                recomputeBadge();
            }).catch(function () {});
    }
    setInterval(refreshList, 10000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshList(); });

    // If a thread is preselected (deep link), bind messaging + clear its unread.
    if (activeId) markRowRead(activeId);
})();
</script>

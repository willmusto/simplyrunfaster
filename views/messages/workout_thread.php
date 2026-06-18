<?php
/**
 * Focused thread for a single workout (shared by athlete + coach).
 * Expects:
 *   $workout        — planned_workouts row (display_title, display_summary, scheduled_date, workout_type, target_duration, id)
 *   $notes          — session_notes rows (body, author_role, author_id, author_name, created_at)
 *   $viewerId       — current user id
 *   $viewerRole     — 'athlete' | 'coach'
 *   $otherPartyName — name shown in the compose placeholder
 *   $composeAction  — POST url
 *   $backUrl        — where the back link goes
 */
$wTitle = trim((string)($workout['display_title'] ?? ''))
    ?: ucfirst(str_replace('_', ' ', (string)($workout['workout_type'] ?? 'Workout')));
$wDate  = !empty($workout['scheduled_date']) ? date('D, M j', strtotime($workout['scheduled_date'])) : '';
$lastNoteId = $notes ? (int)end($notes)['id'] : 0;
?>
<div class="page-content" style="padding-bottom:96px;">

    <a href="<?= h($backUrl) ?>" class="body-text" style="display:inline-block;margin-bottom:12px;color:var(--text-muted);">&larr; Back</a>

    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
            <span class="pill <?= pill_class($workout['workout_type'] ?? 'easy') ?>"><?= pill_label($workout['workout_type'] ?? 'easy') ?></span>
            <span style="font-size:15px;font-weight:600;"><?= h($wTitle) ?></span>
        </div>
        <?php if ($wDate): ?>
        <div style="font-size:12px;color:var(--text-muted);"><?= h($wDate) ?></div>
        <?php endif; ?>
        <?php if (!empty($workout['display_summary'])): ?>
        <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;"><?= h($workout['display_summary']) ?></div>
        <?php endif; ?>
    </div>

    <div class="msg-thread" style="margin-bottom:8px;" id="wtThread"
         data-poll-url="<?= h($pollUrl ?? '') ?>" data-last-id="<?= (int)$lastNoteId ?>">
        <?php if (empty($notes)): ?>
        <div class="empty-state" style="padding:24px 0;">
            <div class="empty-state-title">No messages yet</div>
            <p class="body-text" style="margin-top:6px;">
                Ask your coach a question about this workout, or share how it is going.
            </p>
        </div>
        <?php else: ?>
        <?php foreach ($notes as $n):
            $mine     = ((int)$n['author_id'] === (int)$viewerId);
            $isCoach  = in_array($n['author_role'] ?? '', ['coach','assistant_coach'], true);
            $rowClass = $mine ? 'athlete' : 'coach';
            $when     = !empty($n['created_at']) ? Timezone::toLocal($n['created_at'])->format('M j · g:ia') : '';
        ?>
        <div class="msg-row <?= $rowClass ?>" style="margin-bottom:10px;" data-note-id="<?= (int)$n['id'] ?>">
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;">
                <?= h($mine ? 'You' : ($n['author_name'] ?? ($isCoach ? 'Coach' : 'Athlete'))) ?>
                <?php if ($when): ?> · <?= h($when) ?><?php endif; ?>
            </div>
            <div class="msg-bubble"><?= nl2br(h($n['body'])) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="msg-compose-wrap">
        <div class="msg-compose-inner">
            <form method="POST" action="<?= h($composeAction) ?>">
                <?= Auth::csrfField() ?>
                <div class="msg-compose">
                    <textarea name="body" class="msg-compose-input" rows="1" maxlength="1000"
                              placeholder="Message <?= h($otherPartyName) ?> about this workout…" required></textarea>
                    <button type="submit" class="msg-compose-send" title="Send">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round">
                            <line x1="22" y1="2" x2="11" y2="13"/>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
(function () {
    'use strict';
    var thread = document.getElementById('wtThread');
    if (!thread) return;
    var form  = document.querySelector('.msg-compose-wrap form');
    var input = form ? form.querySelector('.msg-compose-input') : null;

    // ── Bug 1: Enter-to-send (Shift+Enter = newline) — mirrors the main thread. ──
    if (form && input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            }
        });
    }

    // ── Bug 2: 10s polling, scoped to this planned_workout_id. ──
    var pollUrl = thread.getAttribute('data-poll-url');
    if (!pollUrl) return;
    var lastId  = parseInt(thread.getAttribute('data-last-id'), 10) || 0;
    var POLL_MS = 10000;
    var timer   = null;

    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
    function nearBottom() { return (document.documentElement.scrollHeight - window.scrollY - window.innerHeight) < 120; }
    function scrollBottom() { window.scrollTo(0, document.documentElement.scrollHeight); }

    function makeRow(n) {
        var row = document.createElement('div');
        row.className = 'msg-row ' + (n.mine ? 'athlete' : 'coach');
        row.style.marginBottom = '10px';
        row.setAttribute('data-note-id', n.id);
        row.innerHTML =
            '<div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;">' +
                esc(n.author) + (n.when ? ' · ' + esc(n.when) : '') +
            '</div>' +
            '<div class="msg-bubble">' + esc(n.body).replace(/\n/g, '<br>') + '</div>';
        return row;
    }

    function poll() {
        fetch(pollUrl + '?after=' + lastId, { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (list) {
                if (!Array.isArray(list) || !list.length) return;
                var stick = nearBottom();
                var empty = thread.querySelector('.empty-state');
                var added = false;
                list.forEach(function (n) {
                    if (!n || !n.id) return;
                    if (thread.querySelector('[data-note-id="' + n.id + '"]')) return;
                    if (empty) { empty.remove(); empty = null; }
                    thread.appendChild(makeRow(n));
                    if (n.id > lastId) lastId = n.id;
                    added = true;
                });
                if (added && stick) scrollBottom();
            }).catch(function () {});
    }

    function start() { if (!timer) timer = setInterval(poll, POLL_MS); }
    function stop()  { if (timer) { clearInterval(timer); timer = null; } }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stop(); } else { poll(); start(); }
    });
    window.addEventListener('pagehide', stop);
    start();
})();
</script>

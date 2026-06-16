<?php
// $messages = array of messages with session_type, session_date, sender_name
// $coachName = string
// $athlete = athlete record
?>
<div class="page-content has-fixed-compose">

    <div class="page-heading" style="margin-bottom:2px;">Messages</div>
    <p class="body-text" style="margin-bottom:16px;"><?= h($coachName) ?></p>

    <?php if (empty($messages)): ?>
    <div class="empty-state" style="padding-top:60px;">
        <div class="empty-state-icon" style="font-size:40px;">💬</div>
        <div class="empty-state-title">No messages yet</div>
        <p class="body-text" style="margin-top:6px;">
            Say hello to your coach below, or add a note to a completed workout from your log.
        </p>
    </div>
    <?php else: ?>
    <div class="msg-thread" id="msgThreadList">
        <?php
        $prevTime = null;
        foreach ($messages as $msg):
            $sentDt        = Timezone::toLocal($msg['sent_at']);   // viewer's local time
            $sentAt        = $sentDt->getTimestamp();
            $role          = $msg['sender_role'];
            $isSessionNote = in_array($msg['message_type'], ['session_note', 'session_note_reply'], true);

            if ($prevTime === null || ($sentAt - $prevTime) > 3600):
        ?>
        <div class="msg-timestamp">
            <?= h($sentDt->format('M j · g:ia')) ?>
        </div>
        <?php endif; ?>

        <?php if ($isSessionNote): ?>
        <div class="msg-row <?= h($role) ?>">
            <div class="msg-session-card">
                <div class="msg-session-card-header">
                    📍 <?= h(ucfirst(str_replace('_', ' ', $msg['session_type'] ?? 'workout'))) ?>
                    <?php if ($msg['session_date']): ?>
                    · <?= date('M j', strtotime($msg['session_date'])) ?>
                    <?php endif; ?>
                </div>
                <div class="msg-session-card-body">
                    <?= h(mb_substr($msg['body'], 0, 200) . (mb_strlen($msg['body']) > 200 ? '…' : '')) ?>
                </div>
                <a href="/app/log" class="msg-session-link">View in log →</a>
            </div>
            <div class="msg-meta"><?= h($sentDt->format('g:ia')) ?></div>
        </div>
        <?php else: ?>
        <div class="msg-row <?= h($role) ?>">
            <div class="msg-bubble"><?= nl2br(h($msg['body'])) ?></div>
            <div class="msg-meta"><?= h($sentDt->format('g:ia')) ?></div>
        </div>
        <?php endif; ?>

        <?php
            $prevTime = $sentAt;
        endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Desktop-only inline compose (hidden on mobile via CSS) -->
    <div class="msg-compose-wrap" style="display:none;" id="desktopCompose">
        <form method="POST" action="/app/messages/send">
            <?= Auth::csrfField() ?>
            <div class="msg-compose">
                <textarea name="body" class="msg-compose-input" rows="1" maxlength="2000"
                          placeholder="Message <?= h($coachName) ?>…" required></textarea>
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

<!-- Mobile fixed compose bar (hidden on desktop via CSS) -->
<div class="msg-compose-wrap" id="mobileCompose">
    <form method="POST" action="/app/messages/send">
        <?= Auth::csrfField() ?>
        <div class="msg-compose">
            <textarea name="body" class="msg-compose-input" rows="1" maxlength="2000"
                      placeholder="Message <?= h($coachName) ?>…" required></textarea>
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

<script>
(function () {
    // Scroll to bottom of thread
    window.scrollTo(0, document.body.scrollHeight);

    // Show the right compose bar based on viewport
    function layoutCompose() {
        var desktop = document.getElementById('desktopCompose');
        var mobile  = document.getElementById('mobileCompose');
        if (!desktop || !mobile) return;
        if (window.innerWidth >= 1024) {
            desktop.style.display = 'block';
            mobile.style.display  = 'none';
        } else {
            desktop.style.display = 'none';
            mobile.style.display  = '';
        }
    }
    layoutCompose();
    window.addEventListener('resize', layoutCompose);

    // Auto-grow + Enter-to-send (Shift+Enter = newline)
    document.querySelectorAll('.msg-compose-input').forEach(function (ta) {
        ta.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
        ta.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                var form = this.closest('form');
                if (form && this.value.trim()) form.submit();
            }
        });
    });
})();
</script>

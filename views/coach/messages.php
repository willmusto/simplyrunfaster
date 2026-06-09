<?php
// $athlete = athlete record (name, email, id)
// $messages = array of messages with session_type, session_date, sender_name
?>
<div class="page-content">

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
        <a href="/app/coach/athlete/<?= (int)$athlete['id'] ?>"
           style="color:var(--text-muted);text-decoration:none;font-size:20px;line-height:1;">←</a>
        <div class="athlete-avatar"><?= h(avatar_initials($athlete['name'])) ?></div>
        <div>
            <div class="page-heading" style="margin-bottom:0;"><?= h($athlete['name']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);">Messages</div>
        </div>
    </div>

    <?php if (empty($messages)): ?>
    <div class="empty-state" style="max-width:500px;">
        <div class="empty-state-icon" style="font-size:40px;">💬</div>
        <div class="empty-state-title">No messages yet</div>
        <p class="body-text" style="margin-top:6px;">Start the conversation with <?= h($athlete['name']) ?>.</p>
    </div>
    <?php else: ?>
    <div class="msg-thread" style="max-width:600px;">
        <?php
        $prevTime = null;
        foreach ($messages as $msg):
            $sentAt        = strtotime($msg['sent_at']);
            $isSessionNote = in_array($msg['message_type'], ['session_note', 'session_note_reply'], true);
            $rowClass      = ((int)$msg['sender_id'] === (int)Auth::userId()) ? 'athlete' : 'coach';

            if ($prevTime === null || ($sentAt - $prevTime) > 3600):
        ?>
        <div class="msg-timestamp"><?= date('M j · g:ia', $sentAt) ?></div>
        <?php endif; ?>

        <?php if ($isSessionNote): ?>
        <div class="msg-row <?= h($rowClass) ?>">
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
            </div>
            <div class="msg-meta"><?= date('g:ia', $sentAt) ?></div>
        </div>
        <?php else: ?>
        <div class="msg-row <?= h($rowClass) ?>">
            <div class="msg-bubble"><?= nl2br(h($msg['body'])) ?></div>
            <div class="msg-meta"><?= date('g:ia', $sentAt) ?></div>
        </div>
        <?php endif; ?>

        <?php
            $prevTime = $sentAt;
        endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Compose -->
    <div class="msg-compose-wrap" style="max-width:600px;">
        <form method="POST" action="/app/coach/athlete/<?= (int)$athlete['id'] ?>/messages/send">
            <?= Auth::csrfField() ?>
            <div class="msg-compose"
                 style="border:1px solid var(--border-strong);border-radius:var(--radius-card);padding:12px 14px;">
                <textarea name="body" class="msg-compose-input" rows="2" maxlength="2000"
                          placeholder="Message <?= h($athlete['name']) ?>…" required></textarea>
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

<script>
(function () {
    window.scrollTo(0, document.body.scrollHeight);
    var ta = document.querySelector('.msg-compose-input');
    if (ta) {
        ta.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
    }
})();
</script>

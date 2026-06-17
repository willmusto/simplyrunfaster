<?php
// $messages  = array of messages with session_type, session_date, sender_name
// $coachName = string
// $athlete   = athlete record
$viewerId      = (int)Auth::userId();
$lastMessageId = $messages ? (int)end($messages)['id'] : 0;
?>
<div class="msg-screen" id="msgScreen"
     data-role="athlete"
     data-poll-url="/app/messages/poll"
     data-send-url="/app/messages/send"
     data-last-id="<?= $lastMessageId ?>">

    <div class="msg-header">
        <div class="msg-header-inner">
            <div>
                <div class="msg-header-title">Messages</div>
                <div class="msg-header-sub"><?= h($coachName) ?></div>
            </div>
        </div>
    </div>

    <div class="msg-thread-scroll" id="msgScroll">
        <?php if (empty($messages)): ?>
        <div class="empty-state" style="padding-top:60px;">
            <div class="empty-state-icon" style="font-size:40px;">💬</div>
            <div class="empty-state-title">No messages yet</div>
            <p class="body-text" style="margin-top:6px;">
                Say hello to your coach below, or add a note to a completed workout from your log.
            </p>
        </div>
        <?php endif; ?>

        <div class="msg-thread" id="msgThread">
            <?php
            $prevTime = null;
            $prevMine = null;
            foreach ($messages as $msg):
                $sentDt        = Timezone::toLocal($msg['sent_at']);   // viewer's local time
                $sentAt        = $sentDt->getTimestamp();
                $mine          = ((int)$msg['sender_id'] === $viewerId);
                $rowClass      = $mine ? 'athlete' : 'coach';
                $isNote        = $msg['message_type'] === 'session_note';
                $isReply       = $msg['message_type'] === 'session_note_reply';
                $cwId          = (int)($msg['completed_workout_id'] ?? 0);
                $sessionName   = trim((string)($msg['session_title'] ?? ''))
                    ?: (!empty($msg['session_type']) ? ucfirst(str_replace('_', ' ', $msg['session_type'])) : 'Session note');
                $replyCount    = (int)($msg['reply_count'] ?? 0);
                $switch        = ($prevMine !== null && $prevMine !== $mine) ? ' sender-switch' : '';

                if ($prevTime === null || ($sentAt - $prevTime) > 3600):
            ?>
            <div class="msg-time-sep"><?= h($sentDt->format('M j · g:ia')) ?></div>
            <?php endif; ?>

            <div class="msg-row <?= $rowClass . $switch ?>"
                 data-msg-id="<?= (int)$msg['id'] ?>" data-ts="<?= $sentAt ?>" data-mine="<?= $mine ? '1' : '0' ?>">
                <?php if ($isNote): ?>
                <div class="msg-session-card">
                    <div class="msg-session-card-header">
                        📍 <?= h($sessionName) ?>
                        <?php if ($msg['session_date']): ?>
                        · <?= date('M j', strtotime($msg['session_date'])) ?>
                        <?php endif; ?>
                    </div>
                    <div class="msg-session-card-body">
                        <?= h(mb_substr($msg['body'], 0, 120) . (mb_strlen($msg['body']) > 120 ? '…' : '')) ?>
                    </div>
                    <?php if ($replyCount > 0): ?>
                    <div class="msg-session-replies" style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                        <?= $replyCount ?> <?= $replyCount === 1 ? 'reply' : 'replies' ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($cwId): ?>
                    <a href="/app/log/<?= $cwId ?>" class="msg-session-link">View session →</a>
                    <?php endif; ?>
                </div>
                <?php elseif ($isReply): ?>
                <?php if ($cwId): ?>
                <div class="msg-reply-label" style="font-size:11px;color:var(--text-muted);margin-bottom:3px;">
                    Re: <?= h($sessionName) ?>
                </div>
                <?php endif; ?>
                <div class="msg-bubble"><?= nl2br(h($msg['body'])) ?></div>
                <?php else: ?>
                <div class="msg-bubble"><?= nl2br(h($msg['body'])) ?></div>
                <?php endif; ?>
            </div>

            <?php
                $prevTime = $sentAt;
                $prevMine = $mine;
            endforeach; ?>
        </div>
    </div>

    <div class="msg-compose-wrap">
        <div class="msg-compose-inner">
            <form method="POST" action="/app/messages/send" id="msgForm">
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

</div>

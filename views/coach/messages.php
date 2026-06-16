<?php
// $athlete   = athlete record (name, email, id)
// $messages  = array of messages with session_type, session_date, sender_name
// $planPhase = string|null — current plan phase label for the context header
$viewerId      = (int)Auth::userId();
$athleteId     = (int)$athlete['id'];
$lastMessageId = $messages ? (int)end($messages)['id'] : 0;
?>
<div class="msg-screen" id="msgScreen"
     data-role="coach"
     data-poll-url="/app/coach/athlete/<?= $athleteId ?>/messages/poll"
     data-send-url="/app/coach/athlete/<?= $athleteId ?>/messages/send"
     data-last-id="<?= $lastMessageId ?>">

    <div class="msg-header">
        <div class="msg-header-inner">
            <a href="/app/coach/athlete/<?= $athleteId ?>" class="msg-header-back" title="Back">←</a>
            <div class="athlete-avatar"><?= h(avatar_initials($athlete['name'])) ?></div>
            <div>
                <div class="msg-header-title"><?= h($athlete['name']) ?></div>
                <div class="msg-header-sub">
                    <?php if (!empty($planPhase)): ?>
                    <span class="msg-phase-chip"><?= h($planPhase) ?></span>
                    <?php else: ?>
                    No active plan
                    <?php endif; ?>
                </div>
            </div>
            <a href="/app/coach/athlete/<?= $athleteId ?>" class="msg-header-link">Profile →</a>
        </div>
    </div>

    <div class="msg-thread-scroll" id="msgScroll">
        <?php if (empty($messages)): ?>
        <div class="empty-state" style="padding-top:60px;">
            <div class="empty-state-icon" style="font-size:40px;">💬</div>
            <div class="empty-state-title">No messages yet</div>
            <p class="body-text" style="margin-top:6px;">Start the conversation with <?= h($athlete['name']) ?>.</p>
        </div>
        <?php endif; ?>

        <div class="msg-thread" id="msgThread">
            <?php
            $prevTime = null;
            $prevMine = null;
            foreach ($messages as $msg):
                $sentDt        = Timezone::toLocal($msg['sent_at']);   // coach's local time
                $sentAt        = $sentDt->getTimestamp();
                $mine          = ((int)$msg['sender_id'] === $viewerId);
                $rowClass      = $mine ? 'athlete' : 'coach';
                $isSessionNote = in_array($msg['message_type'], ['session_note', 'session_note_reply'], true);
                $switch        = ($prevMine !== null && $prevMine !== $mine) ? ' sender-switch' : '';

                if ($prevTime === null || ($sentAt - $prevTime) > 3600):
            ?>
            <div class="msg-time-sep"><?= h($sentDt->format('M j · g:ia')) ?></div>
            <?php endif; ?>

            <div class="msg-row <?= $rowClass . $switch ?>"
                 data-msg-id="<?= (int)$msg['id'] ?>" data-ts="<?= $sentAt ?>" data-mine="<?= $mine ? '1' : '0' ?>">
                <?php if ($isSessionNote): ?>
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
                    <?php if (!empty($msg['completed_workout_id'])): ?>
                    <button type="button" class="msg-session-comment-toggle"
                            onclick="this.style.display='none';document.getElementById('sc-<?= (int)$msg['id'] ?>').style.display='block'">
                        + Comment on this session
                    </button>
                    <div id="sc-<?= (int)$msg['id'] ?>" class="msg-session-comment-form" style="display:none;">
                        <form method="POST" action="/app/coach/athlete/<?= $athleteId ?>/session-note">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="completed_workout_id" value="<?= (int)$msg['completed_workout_id'] ?>">
                            <textarea name="body" class="form-textarea" rows="2" maxlength="1000" required
                                      placeholder="Comment on this session…"></textarea>
                            <button type="submit" class="btn btn-primary btn-sm">Save comment</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
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
            <form method="POST" action="/app/coach/athlete/<?= $athleteId ?>/messages/send" id="msgForm">
                <?= Auth::csrfField() ?>
                <div class="msg-compose">
                    <textarea name="body" class="msg-compose-input" rows="1" maxlength="2000"
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

</div>

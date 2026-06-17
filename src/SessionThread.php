<?php
/**
 * SessionThread — message-thread representation of a session-note conversation.
 *
 * The messages table carries exactly ONE "session card" row per completed workout
 * (message_type='session_note', completed_workout_id set). The first comment inserts
 * it; every later comment — athlete note or coach reply — re-floats that single card
 * to the bottom of the thread (sent_at = NOW()), bumps reply_count, refreshes the
 * preview to the latest comment, and re-attributes it to the latest commenter so
 * unread state resolves for the recipient. The full per-comment text still lives in
 * session_notes (the session-detail thread); this only manages the one card row.
 */
class SessionThread
{
    /**
     * Upsert the session card for a completed workout. Returns the card message id.
     *
     * @param string $senderRole one of the messages.sender_role enum
     *                           ('athlete','coach','assistant_coach')
     */
    public static function recordComment(PDO $db, int $athleteId, int $senderId, string $senderRole, int $cwId, string $body): int
    {
        $find = $db->prepare(
            "SELECT id FROM messages
             WHERE completed_workout_id = ? AND message_type = 'session_note'
             ORDER BY id ASC LIMIT 1"
        );
        $find->execute([$cwId]);
        $cardId = (int)($find->fetchColumn() ?: 0);

        if ($cardId > 0) {
            // Re-float: newest comment wins the preview + sort position, and the card
            // is re-attributed to the commenter so the *recipient* sees it as unread.
            $db->prepare(
                "UPDATE messages
                 SET body = ?, sender_id = ?, sender_role = ?, sent_at = NOW(),
                     reply_count = reply_count + 1, read_at = NULL
                 WHERE id = ?"
            )->execute([$body, $senderId, $senderRole, $cardId]);
            return $cardId;
        }

        $db->prepare(
            "INSERT INTO messages
                (athlete_id, sender_id, sender_role, body, message_type, completed_workout_id, reply_count)
             VALUES (?, ?, ?, ?, 'session_note', ?, 0)"
        )->execute([$athleteId, $senderId, $senderRole, $body, $cwId]);
        return (int)$db->lastInsertId();
    }

    /**
     * Refresh the card preview to the latest session note for a workout, without
     * re-floating or bumping the count (used after an in-place note edit). No-op when
     * there is no card or no notes.
     */
    public static function refreshCardPreview(PDO $db, int $cwId): void
    {
        $latest = $db->prepare(
            'SELECT body FROM session_notes WHERE completed_workout_id = ?
             ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $latest->execute([$cwId]);
        $body = $latest->fetchColumn();
        if ($body === false) return;

        $db->prepare(
            "UPDATE messages SET body = ?
             WHERE completed_workout_id = ? AND message_type = 'session_note'
             ORDER BY id ASC LIMIT 1"
        )->execute([$body, $cwId]);
    }
}

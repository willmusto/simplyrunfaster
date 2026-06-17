<?php
/**
 * SessionThread — message-thread representation of a session-note conversation.
 *
 * The messages table carries exactly ONE "session card" row per workout
 * (message_type='session_note'). Per-comment text lives in session_notes. As of
 * migration_022 the card and the notes are keyed on planned_workout_id so a thread
 * can exist BEFORE the workout is completed; completed_workout_id is filled in too
 * once a completion exists. The first comment inserts the card; every later comment
 * (athlete note or coach reply) re-floats that single card to the bottom of the
 * thread (sent_at = NOW()), bumps reply_count, refreshes the preview, and
 * re-attributes it to the latest commenter so unread state resolves for the recipient.
 */
class SessionThread
{
    /**
     * Upsert the session card for a COMPLETED workout. Resolves the planned workout
     * from the completion so the card is keyed consistently across the lifecycle.
     * Returns the card message id.
     */
    public static function recordComment(PDO $db, int $athleteId, int $senderId, string $senderRole, int $cwId, string $body): int
    {
        $pw = $db->prepare('SELECT planned_workout_id FROM completed_workouts WHERE id = ? LIMIT 1');
        $pw->execute([$cwId]);
        $pwId = (int)($pw->fetchColumn() ?: 0) ?: null;
        return self::upsertCard($db, $athleteId, $senderId, $senderRole, $pwId, $cwId, $body);
    }

    /**
     * Upsert the session card for a PLANNED workout (pre- or post-completion).
     * $cwId is the completed workout when one exists, else null.
     */
    public static function recordCommentPlanned(PDO $db, int $athleteId, int $senderId, string $senderRole, int $pwId, ?int $cwId, string $body): int
    {
        return self::upsertCard($db, $athleteId, $senderId, $senderRole, $pwId ?: null, $cwId, $body);
    }

    /**
     * Find (by planned_workout_id first, then completed_workout_id) and re-float the
     * single session card, or create it. Keeps both id columns populated as they
     * become known (completed_workout_id may appear after the pre-completion card).
     */
    private static function upsertCard(PDO $db, int $athleteId, int $senderId, string $senderRole, ?int $pwId, ?int $cwId, string $body): int
    {
        $cardId = 0;
        if ($pwId) {
            $f = $db->prepare("SELECT id FROM messages WHERE planned_workout_id = ? AND message_type = 'session_note' ORDER BY id ASC LIMIT 1");
            $f->execute([$pwId]);
            $cardId = (int)($f->fetchColumn() ?: 0);
        }
        if ($cardId === 0 && $cwId) {
            $f = $db->prepare("SELECT id FROM messages WHERE completed_workout_id = ? AND message_type = 'session_note' ORDER BY id ASC LIMIT 1");
            $f->execute([$cwId]);
            $cardId = (int)($f->fetchColumn() ?: 0);
        }

        if ($cardId > 0) {
            $db->prepare(
                "UPDATE messages
                 SET body = ?, sender_id = ?, sender_role = ?, sent_at = NOW(),
                     reply_count = reply_count + 1, read_at = NULL,
                     planned_workout_id   = COALESCE(?, planned_workout_id),
                     completed_workout_id = COALESCE(?, completed_workout_id)
                 WHERE id = ?"
            )->execute([$body, $senderId, $senderRole, $pwId, $cwId, $cardId]);
            return $cardId;
        }

        $db->prepare(
            "INSERT INTO messages
                (athlete_id, sender_id, sender_role, body, message_type, completed_workout_id, planned_workout_id, reply_count)
             VALUES (?, ?, ?, ?, 'session_note', ?, ?, 0)"
        )->execute([$athleteId, $senderId, $senderRole, $body, $cwId, $pwId]);
        return (int)$db->lastInsertId();
    }

    /**
     * Refresh the card preview to the latest session note for a completed workout,
     * without re-floating or bumping the count (used after an in-place note edit).
     * Keyed by the planned workout when resolvable, else the completed workout.
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

        $pw = $db->prepare('SELECT planned_workout_id FROM completed_workouts WHERE id = ? LIMIT 1');
        $pw->execute([$cwId]);
        $pwId = (int)($pw->fetchColumn() ?: 0);

        $db->prepare(
            "UPDATE messages SET body = ?
             WHERE message_type = 'session_note' AND (completed_workout_id = ? OR (planned_workout_id = ? AND ? > 0))
             ORDER BY id ASC LIMIT 1"
        )->execute([$body, $cwId, $pwId, $pwId]);
    }
}

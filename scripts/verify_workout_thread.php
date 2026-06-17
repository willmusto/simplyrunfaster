<?php
/**
 * Workout-thread verification (migration_022). Exercises the planned-workout-keyed
 * session thread model end to end, then EXPLICITLY deletes the throwaway data.
 *
 * MyISAM has no rollback (see memory project_myisam_no_transactions), so this
 * cleans up by hand; test rows use @example.invalid emails.
 *
 *     php scripts/verify_workout_thread.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/SessionThread.php';
require_once SCRIPT_ROOT . '/src/Timezone.php';
require_once SCRIPT_ROOT . '/src/Controllers/AthleteController.php';

$db = Database::get();

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  [PASS] " : "  [FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

$emails = [];
$pwId = $cwId = $athleteId = 0;

try {
    // Throwaway athlete + coach users.
    $ae = 'thread_ath_' . bin2hex(random_bytes(5)) . '@example.invalid';
    $ce = 'thread_coach_' . bin2hex(random_bytes(5)) . '@example.invalid';
    $emails = [$ae, $ce];
    $db->prepare('INSERT INTO users (email, password_hash, role, name) VALUES (?, "x", "coach", "Thread Coach")')->execute([$ce]);
    $coachUserId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO users (email, password_hash, role, name) VALUES (?, "x", "athlete", "Thread Athlete")')->execute([$ae]);
    $athUserId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO athletes (user_id, status, coach_id) VALUES (?, "active", ?)')->execute([$athUserId, $coachUserId]);
    $athleteId = (int)$db->lastInsertId();

    // A plan + one planned workout (the thread target).
    $db->prepare('INSERT INTO training_plans (athlete_id, status, plan_start_date, plan_end_date, generated_at, plan_type)
                  VALUES (?, "active", CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), NOW(), "development_plan")')->execute([$athleteId]);
    $planId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO planned_workouts (plan_id, athlete_id, scheduled_date, workout_type, display_title, visible_to_athlete)
                  VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 2 DAY), "interval", "Mile Repeats", 1)')->execute([$planId, $athleteId]);
    $pwId = (int)$db->lastInsertId();

    // ── Pre-completion: athlete asks a question ─────────────────────────────
    $db->prepare('INSERT INTO session_notes (completed_workout_id, planned_workout_id, athlete_id, author_id, author_role, body)
                  VALUES (NULL, ?, ?, ?, "athlete", "How hard should the reps feel?")')->execute([$pwId, $athleteId, $athUserId]);
    SessionThread::recordCommentPlanned($db, $athleteId, $athUserId, 'athlete', $pwId, null, 'How hard should the reps feel?');

    $card = $db->prepare("SELECT * FROM messages WHERE planned_workout_id = ? AND message_type='session_note'");
    $card->execute([$pwId]);
    $cards = $card->fetchAll(PDO::FETCH_ASSOC);
    check('one session card created for the planned workout', count($cards) === 1);
    check('card has planned_workout_id, completed_workout_id NULL pre-completion',
        $cards && (int)$cards[0]['planned_workout_id'] === $pwId && $cards[0]['completed_workout_id'] === null);
    check('card reply_count 0 + athlete sender on first comment',
        $cards && (int)$cards[0]['reply_count'] === 0 && $cards[0]['sender_role'] === 'athlete');

    // ── Coach replies (pre-completion) ──────────────────────────────────────
    $db->prepare('INSERT INTO session_notes (completed_workout_id, planned_workout_id, athlete_id, author_id, author_role, body)
                  VALUES (NULL, ?, ?, ?, "coach", "Controlled and strong — about 5K effort.")')->execute([$pwId, $athleteId, $coachUserId]);
    SessionThread::recordCommentPlanned($db, $athleteId, $coachUserId, 'coach', $pwId, null, 'Controlled and strong — about 5K effort.');

    $card->execute([$pwId]); $cards = $card->fetchAll(PDO::FETCH_ASSOC);
    check('still one card after coach reply (re-floated, not duplicated)', count($cards) === 1);
    check('reply_count 1 + re-attributed to coach', $cards && (int)$cards[0]['reply_count'] === 1 && $cards[0]['sender_role'] === 'coach');

    // Thread reads chronologically.
    $notes = AthleteController::loadWorkoutThreadNotes($db, $pwId);
    check('thread returns 2 notes chronological', count($notes) === 2
        && $notes[0]['author_role'] === 'athlete' && $notes[1]['author_role'] === 'coach');

    // Thread-state (reply_count for the Today/Plan button).
    $st = $db->prepare("SELECT reply_count FROM messages WHERE athlete_id=? AND message_type='session_note' AND planned_workout_id=?");
    $st->execute([$athleteId, $pwId]);
    check('thread-state reply_count visible to button logic', (int)$st->fetchColumn() === 1);

    // ── Workout gets completed; post-completion note keeps the SAME card ─────
    $db->prepare('INSERT INTO completed_workouts (athlete_id, planned_workout_id, source, activity_date, workout_type)
                  VALUES (?, ?, "manual", CURDATE(), "interval")')->execute([$athleteId, $pwId]);
    $cwId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO session_notes (completed_workout_id, planned_workout_id, athlete_id, author_id, author_role, body)
                  VALUES (?, ?, ?, ?, "athlete", "Done — felt good!")')->execute([$cwId, $pwId, $athleteId, $athUserId]);
    SessionThread::recordComment($db, $athleteId, $athUserId, 'athlete', $cwId, 'Done — felt good!');

    $card->execute([$pwId]); $cards = $card->fetchAll(PDO::FETCH_ASSOC);
    check('post-completion note reuses the same single card', count($cards) === 1);
    check('card now carries completed_workout_id too', $cards && (int)$cards[0]['completed_workout_id'] === $cwId);
    check('reply_count 2 after third comment', $cards && (int)$cards[0]['reply_count'] === 2);

    $notes = AthleteController::loadWorkoutThreadNotes($db, $pwId);
    check('thread shows all 3 messages (pre + post) chronologically', count($notes) === 3);
} finally {
    // Explicit cleanup.
    if ($pwId) {
        foreach (['session_notes' => 'planned_workout_id', 'messages' => 'planned_workout_id',
                  'planned_workouts' => 'id', 'completed_workouts' => 'planned_workout_id'] as $t => $col) {
            try { $db->prepare("DELETE FROM `$t` WHERE `$col` = ?")->execute([$pwId]); } catch (\Throwable $e) {}
        }
    }
    if ($athleteId) {
        try { $db->prepare('DELETE FROM training_plans WHERE athlete_id = ?')->execute([$athleteId]); } catch (\Throwable $e) {}
        try { $db->prepare('DELETE FROM messages WHERE athlete_id = ?')->execute([$athleteId]); } catch (\Throwable $e) {}
        try { $db->prepare('DELETE FROM athletes WHERE id = ?')->execute([$athleteId]); } catch (\Throwable $e) {}
    }
    if ($emails) {
        $in = implode(',', array_fill(0, count($emails), '?'));
        try { $db->prepare("DELETE FROM users WHERE email IN ($in)")->execute($emails); } catch (\Throwable $e) {}
    }
    echo "\nCleaned up test data.\n";
}

echo "\n================ {$pass} passed, {$fail} failed ================\n";
exit($fail === 0 ? 0 : 1);

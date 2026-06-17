<?php
/**
 * Cron: deliver scheduled coach messages (currently the onboarding welcome note).
 *
 * Posts every scheduled_messages row whose send_after has passed into the message
 * thread as a coach message, fires the message_from_coach notification, and marks
 * the row sent. Idempotent per row via the sent flag.
 *
 * This is deliberately a SEPARATE, lightweight cron from cron_notifications.php:
 * that one runs day-cadence jobs (tomorrow_plan, digests, the 20–28h rpe window)
 * that would misfire if run frequently, so it must stay daily. This script is safe
 * to run often.
 *
 * NFSN scheduler: every 15 minutes (or hourly as a fallback if minute-level
 * scheduling is unavailable). A row is delivered on the first run after its
 * send_after, so the effective delay is 12 min to ~one run interval.
 *     php /home/private/app/scripts/cron_scheduled_messages.php
 *
 * Flags: pass `verbose` as an argument to print per-send detail.
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

if (is_file(SCRIPT_ROOT . '/vendor/autoload.php')) {
    require SCRIPT_ROOT . '/vendor/autoload.php';
}
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Timezone.php';
require_once SCRIPT_ROOT . '/src/Mailer.php';
require_once SCRIPT_ROOT . '/src/EmailTemplates.php';
require_once SCRIPT_ROOT . '/src/Notifications.php';

$verbose = in_array('verbose', $argv ?? [], true);
$db      = Database::get();

$due = $db->query(
    "SELECT sm.id, sm.athlete_id, sm.sender_id, sm.body,
            a.user_id AS athlete_user_id, cu.name AS coach_name
     FROM scheduled_messages sm
     JOIN athletes a ON a.id = sm.athlete_id
     LEFT JOIN users cu ON cu.id = sm.sender_id
     WHERE sm.sent = 0 AND sm.send_after <= NOW()
     ORDER BY sm.id ASC
     LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

// Claim each row (sent=1) BEFORE posting, so an overlapping run can't double-send
// (MyISAM has no transactions; the sent=0 guard + rowCount is the lock).
$claim     = $db->prepare('UPDATE scheduled_messages SET sent = 1, sent_at = NOW() WHERE id = ? AND sent = 0');
$insertMsg = $db->prepare(
    'INSERT INTO messages (athlete_id, sender_id, sender_role, body, message_type)
     VALUES (?, ?, "coach", ?, "message")'
);

$delivered = 0;
foreach ($due as $m) {
    $claim->execute([(int)$m['id']]);
    if ($claim->rowCount() === 0) {
        continue; // already claimed/sent by another run
    }

    $insertMsg->execute([(int)$m['athlete_id'], (int)$m['sender_id'], $m['body']]);

    if (!empty($m['athlete_user_id'])) {
        Notifications::send((int)$m['athlete_user_id'], 'message_from_coach', [
            'sender_name'    => $m['coach_name'] ?: 'Your coach',
            'message'        => $m['body'],
            'email_fallback' => true,
        ]);
    }

    $delivered++;
    if ($verbose) {
        echo "  delivered scheduled_message #{$m['id']} to athlete {$m['athlete_id']}\n";
    }
}

echo date('Y-m-d H:i:s') . " — cron_scheduled_messages complete. Delivered: {$delivered}\n";

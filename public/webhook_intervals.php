<?php
/**
 * Intervals.icu webhook handler.
 *
 * Included from index.php for POST /webhook/intervals (public, pre-auth). The
 * front controller has already loaded config + classes; this guard stops the file
 * from doing anything if it is somehow requested directly.
 *
 * Flow: verify the shared secret (constant-time) → log EVERY event to
 * intervals_webhook_log (status='received') → dispatch by event type → update the
 * log row's final status. Always returns 200 for handled-but-skipped/unknown events
 * so Intervals.icu doesn't retry-storm us; only a bad secret returns 401.
 */

if (!class_exists('IntervalsService') || !defined('INTERVALS_WEBHOOK_SECRET')) {
    http_response_code(404);
    exit;
}

// ── Verify shared secret (constant-time) ──────────────────────────────────
$provided = $_SERVER['HTTP_X_INTERVALS_WEBHOOK_SECRET'] ?? '';
if (INTERVALS_WEBHOOK_SECRET === '' || !hash_equals((string)INTERVALS_WEBHOOK_SECRET, (string)$provided)) {
    http_response_code(401);
    exit;
}

$raw     = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = [];

$eventType  = strtoupper((string)($payload['type'] ?? $payload['event_type'] ?? $payload['event'] ?? ''));
$icuAthlete = (string)($payload['athlete_id'] ?? $payload['athleteId'] ?? $payload['icu_athlete_id'] ?? '');
$activityId = (string)($payload['activity_id'] ?? $payload['activityId'] ?? $payload['id'] ?? '');

$db = Database::get();

// ── Log immediately on receipt ────────────────────────────────────────────
$db->prepare(
    'INSERT INTO intervals_webhook_log (event_type, athlete_id, payload, received_at, status)
     VALUES (?, ?, ?, NOW(), "received")'
)->execute([$eventType ?: 'UNKNOWN', $icuAthlete, $raw]);
$logId = (int)$db->lastInsertId();

/** Finalize the webhook_log row. */
$finish = function (string $status, ?string $error = null) use ($db, $logId): void {
    $db->prepare('UPDATE intervals_webhook_log SET status = ?, error_message = ?, processed_at = NOW() WHERE id = ?')
       ->execute([$status, $error, $logId]);
    http_response_code(200);
    echo 'ok';
    exit;
};

try {
    switch ($eventType) {
        case 'ACTIVITY_UPLOADED':
        case 'ACTIVITY_ANALYZED':
            if ($icuAthlete === '' || $activityId === '') {
                $finish('skipped', 'missing athlete_id or activity_id');
            }

            // Resolve the SRF user from the Intervals athlete id.
            $stmt = $db->prepare('SELECT user_id FROM intervals_connections WHERE intervals_athlete_id = ? LIMIT 1');
            $stmt->execute([$icuAthlete]);
            $userId = $stmt->fetchColumn();
            if (!$userId) {
                $finish('skipped', 'no connection for athlete ' . $icuAthlete);
            }

            // Skip if we've already imported this activity (idempotency safety net;
            // the (source, external_activity_id) unique key is the real guard).
            $dup = $db->prepare(
                'SELECT 1 FROM completed_workouts WHERE source = "intervals" AND external_activity_id = ? LIMIT 1'
            );
            $dup->execute([$activityId]);
            if ($dup->fetchColumn()) {
                $finish('skipped', 'activity already imported');
            }

            IntervalsService::pullActivity((int)$userId, $activityId, $db);
            $finish('processed');
            break;

        case 'CONNECTED_SERVICE':
            $finish('processed'); // log only
            break;

        default:
            $finish('skipped', 'unhandled event type'); // 200, no retry noise
    }
} catch (\Throwable $e) {
    error_log('webhook_intervals: ' . $e->getMessage());
    $db->prepare('UPDATE intervals_webhook_log SET status = "failed", error_message = ?, processed_at = NOW() WHERE id = ?')
       ->execute([$e->getMessage(), $logId]);
    http_response_code(200); // swallow so Intervals doesn't retry-storm; we logged it
    echo 'ok';
    exit;
}

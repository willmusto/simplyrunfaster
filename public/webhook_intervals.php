<?php
/**
 * Intervals.icu webhook handler.
 *
 * Included from index.php for POST /webhook/intervals (public, pre-auth). The
 * front controller has already loaded config + classes; this guard stops the file
 * from doing anything if it is somehow requested directly.
 *
 * Payload shape (confirmed from a real delivery — intervals_webhook_log id=6):
 *   { "secret": "<shared secret>", "events": [ { "athlete_id": "...", "type": "...",
 *     "timestamp": "...", <activity-id field, name TBD> }, ... ] }
 * Intervals BATCHES events and NESTS the per-event fields under events[]; the secret
 * is a top-level scalar. So we: log the raw body BEFORE auth (a rejected delivery is
 * still visible) → verify the top-level payload['secret'] (constant-time; the
 * X-Intervals-Webhook-Secret header is kept only as a fallback) → iterate events[] and
 * dispatch EACH event by its own type/athlete → record a per-event outcome summary on
 * the log row. Always returns 200 for handled events (even skipped) so Intervals.icu
 * doesn't retry-storm us; only a bad secret returns 401.
 */

if (!class_exists('IntervalsService') || !defined('INTERVALS_WEBHOOK_SECRET')) {
    http_response_code(404);
    exit;
}

$raw     = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = [];

// Events are nested under events[]; defensively fall back to treating the whole body
// as a single flat event if Intervals ever sends one un-batched.
$events = (isset($payload['events']) && is_array($payload['events'])) ? $payload['events'] : [];
if (!$events && (isset($payload['type']) || isset($payload['event_type']) || isset($payload['event']))) {
    $events = [$payload];
}

/** Read an event's type (UPPER) / athlete id from the candidate field names. */
$eventType = static function (array $e): string {
    return strtoupper((string)($e['type'] ?? $e['event_type'] ?? $e['event'] ?? ''));
};
$eventAthlete = static function (array $e): string {
    return (string)($e['athlete_id'] ?? $e['athleteId'] ?? $e['icu_athlete_id'] ?? '');
};

$db = Database::get();

// ── Log immediately on receipt, BEFORE auth ───────────────────────────────
// So a 401'd delivery (e.g. a secret mismatch) is still recorded and inspectable. The
// raw body holds every event (incl. the still-unconfirmed activity-id field name); the
// columns carry the first event's type/athlete as a quick index.
$first       = is_array($events[0] ?? null) ? $events[0] : [];
$firstType   = $eventType($first);
$firstAth    = $eventAthlete($first);
$db->prepare(
    'INSERT INTO intervals_webhook_log (event_type, athlete_id, payload, received_at, status)
     VALUES (?, ?, ?, NOW(), "received")'
)->execute([$firstType ?: 'UNKNOWN', $firstAth, $raw]);
$logId = (int)$db->lastInsertId();

/** Finalize the webhook_log row with an aggregate status + summary, then return 200. */
$finish = function (string $status, ?string $summary = null) use ($db, $logId): void {
    $db->prepare('UPDATE intervals_webhook_log SET status = ?, error_message = ?, processed_at = NOW() WHERE id = ?')
       ->execute([$status, $summary !== null ? substr($summary, 0, 1000) : null, $logId]);
    http_response_code(200);
    echo 'ok';
    exit;
};

// ── Verify the shared secret (constant-time) ──────────────────────────────
// Intervals.icu delivers the secret in the top-level payload['secret'] (its separate
// Authorization-header field is blank). Match that named field constant-time; keep the
// X-Intervals-Webhook-Secret header only as a fallback in case it is ever populated.
$secret = (string)INTERVALS_WEBHOOK_SECRET;
$authed = false;
if ($secret !== '') {
    $given = (string)($payload['secret'] ?? '');
    $hdr   = (string)($_SERVER['HTTP_X_INTERVALS_WEBHOOK_SECRET'] ?? '');
    if ($given !== '' && hash_equals($secret, $given)) {
        $authed = true;
    } elseif ($hdr !== '' && hash_equals($secret, $hdr)) {
        $authed = true;
    }
}
if (!$authed) {
    // 'failed' is the closest valid intervals_webhook_log.status ENUM value (no 'rejected'
    // member); the error_message marks it as an auth rejection. The row is still logged.
    $db->prepare('UPDATE intervals_webhook_log SET status = "failed", error_message = ?, processed_at = NOW() WHERE id = ?')
       ->execute(['rejected: secret mismatch (no matching payload[\'secret\'] or header)', $logId]);
    http_response_code(401);
    echo 'unauthorized';
    exit;
}

// ── Dispatch every event in the batch ─────────────────────────────────────
// One outcome line per event so a skipped/unmatched event is visible in the log, not
// silent. The downstream run-filter / pullActivity / completed_workouts upsert /
// post-completion pipeline are UNCHANGED — this only extracts per-event fields correctly.
$processed = 0;
$failed    = 0;
$outcomes  = [];

if (!$events) {
    $finish('skipped', 'no events[] in payload');
}

foreach ($events as $i => $event) {
    if (!is_array($event)) { $outcomes[] = "[{$i}] (non-object event) → skipped"; continue; }

    $type    = $eventType($event);
    $athlete = $eventAthlete($event);

    try {
        switch ($type) {
            case 'ACTIVITY_UPLOADED':
            case 'ACTIVITY_ANALYZED':
                // Log the full event object so the FIRST real activity delivery reveals
                // the true in-event activity-id field name (TEST events carry none, so it
                // is still unconfirmed — read it defensively from the likely candidates).
                error_log('webhook_intervals activity event: ' . json_encode($event));

                $activityId = '';
                $activityKey = '';
                foreach (['activity_id', 'id', 'activityId', 'activity'] as $k) {
                    if (isset($event[$k]) && is_scalar($event[$k]) && (string)$event[$k] !== '') {
                        $activityId  = (string)$event[$k];
                        $activityKey = $k;
                        break;
                    }
                }

                if ($athlete === '' || $activityId === '') {
                    $outcomes[] = "[{$i}] {$type} athlete={$athlete} → skipped: missing athlete or activity id"
                        . ($activityKey === '' ? ' (no activity-id field found)' : '');
                    continue 2;
                }

                // Resolve the SRF user from the Intervals athlete id.
                $stmt = $db->prepare('SELECT user_id FROM intervals_connections WHERE intervals_athlete_id = ? LIMIT 1');
                $stmt->execute([$athlete]);
                $userId = $stmt->fetchColumn();
                if (!$userId) {
                    // Record the unmatched athlete id so the i616163-vs-i617822 question is
                    // diagnosable from the log rather than invisible.
                    $outcomes[] = "[{$i}] {$type} → skipped: no connection for athlete {$athlete}";
                    continue 2;
                }

                // Skip if we've already imported this activity (idempotency safety net;
                // the (source, external_activity_id) unique key is the real guard).
                $dup = $db->prepare(
                    'SELECT 1 FROM completed_workouts WHERE source = "intervals" AND external_activity_id = ? LIMIT 1'
                );
                $dup->execute([$activityId]);
                if ($dup->fetchColumn()) {
                    $outcomes[] = "[{$i}] {$type} activity={$activityId} → skipped: already imported";
                    continue 2;
                }

                IntervalsService::pullActivity((int)$userId, $activityId, $db);
                $processed++;
                $outcomes[] = "[{$i}] {$type} athlete={$athlete} activity={$activityId} (via '{$activityKey}') → processed";
                break;

            case 'CONNECTED_SERVICE':
                $processed++;
                $outcomes[] = "[{$i}] CONNECTED_SERVICE → processed (log only)";
                break;

            default:
                $outcomes[] = "[{$i}] " . ($type ?: 'UNKNOWN') . " athlete={$athlete} → skipped: non-activity event";
        }
    } catch (\Throwable $e) {
        $failed++;
        error_log('webhook_intervals event ' . $i . ': ' . $e->getMessage());
        $outcomes[] = "[{$i}] " . ($type ?: 'UNKNOWN') . " → failed: " . $e->getMessage();
    }
}

// Aggregate status: processed if anything imported, else failed if any event errored,
// else skipped. Always 200 so Intervals doesn't retry-storm us (the secret was valid).
$status = $processed > 0 ? 'processed' : ($failed > 0 ? 'failed' : 'skipped');
$finish($status, implode(' | ', $outcomes));

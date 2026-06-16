<?php
/**
 * StripeWebhook — verifies and processes Stripe webhook events.
 *
 * Reached via POST /webhook/stripe (wired in index.php BEFORE the /app router
 * so it is publicly accessible with no session). Every event is signature-
 * verified with STRIPE_WEBHOOK_SECRET and logged to stripe_webhook_log;
 * duplicate event_ids are acknowledged without reprocessing (idempotent).
 *
 * Register the endpoint in the Stripe dashboard:
 *   https://simplyrunfaster.com/webhook/stripe
 */
class StripeWebhook
{
    public static function handle(): void
    {
        $payload   = file_get_contents('php://input') ?: '';
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $secret    = defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '';

        if (!class_exists('Stripe\\Webhook') || $secret === '') {
            http_response_code(500);
            error_log('StripeWebhook: SDK missing or STRIPE_WEBHOOK_SECRET unset; event rejected.');
            echo 'webhook not configured';
            return;
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Throwable $e) {
            http_response_code(400);
            error_log('StripeWebhook: signature verification failed: ' . $e->getMessage());
            echo 'invalid signature';
            return;
        }

        $db      = Database::get();
        $eventId = $event['id'] ?? '';
        $type    = $event['type'] ?? '';

        // Log first; UNIQUE(event_id) gives us idempotency.
        $ins = $db->prepare(
            'INSERT IGNORE INTO stripe_webhook_log (event_id, event_type, payload, received_at, processed)
             VALUES (?, ?, ?, NOW(), 0)'
        );
        $ins->execute([$eventId, $type, $payload]);
        if ($ins->rowCount() === 0) {
            http_response_code(200);
            echo 'duplicate';
            return; // already seen this event
        }

        try {
            self::dispatch($type, $event['data']['object'] ?? [], $db);
            $db->prepare('UPDATE stripe_webhook_log SET processed = 1 WHERE event_id = ?')->execute([$eventId]);
        } catch (\Throwable $e) {
            error_log('StripeWebhook: handler error for ' . $type . ' (' . $eventId . '): ' . $e->getMessage());
            // Leave processed=0 and 200 the response so Stripe doesn't hammer
            // retries for a non-transient bug; the log row flags it for review.
        }

        http_response_code(200);
        echo 'ok';
    }

    private static function dispatch(string $type, $object, PDO $db): void
    {
        switch ($type) {
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                $userId = self::userIdForCustomer($object['customer'] ?? '', $db);
                if ($userId) Billing::syncSubscription($object, $userId, $db);
                break;

            case 'customer.subscription.deleted':
                $userId = self::userIdForCustomer($object['customer'] ?? '', $db);
                if ($userId) {
                    $endDate = !empty($object['current_period_end'])
                        ? gmdate('Y-m-d', (int)$object['current_period_end'])
                        : null;
                    $db->prepare(
                        'UPDATE users SET subscription_status = \'canceled\', subscription_end_date = ? WHERE id = ?'
                    )->execute([$endDate, $userId]);
                }
                break;

            case 'invoice.payment_failed':
                self::handlePaymentFailed($object, $db);
                break;

            case 'invoice.payment_succeeded':
                self::handlePaymentSucceeded($object, $db);
                break;

            case 'customer.subscription.trial_will_end':
                // No trials in v1 — intentionally no action.
                break;
        }
    }

    private static function handlePaymentFailed($invoice, PDO $db): void
    {
        $userId = self::userIdForCustomer($invoice['customer'] ?? '', $db);
        if (!$userId) return;

        // Only set the grace window on the first failure (don't keep extending it).
        $row = Billing::userBillingRow($userId, $db);
        $graceDays = defined('BILLING_GRACE_DAYS') ? BILLING_GRACE_DAYS : 7;
        if (empty($row['grace_period_ends'])) {
            $grace = gmdate('Y-m-d', strtotime('+' . (int)$graceDays . ' days'));
            $db->prepare(
                'UPDATE users SET subscription_status = \'past_due\', grace_period_ends = ? WHERE id = ?'
            )->execute([$grace, $userId]);
        } else {
            $db->prepare('UPDATE users SET subscription_status = \'past_due\' WHERE id = ?')->execute([$userId]);
        }

        // Notify athlete (always-on) and their coach.
        $name = self::userName($userId, $db);
        try {
            Notifications::send($userId, 'payment_failed_athlete', []);
        } catch (\Throwable $e) {
            error_log('StripeWebhook: payment_failed_athlete notify failed: ' . $e->getMessage());
        }
        $coachUserId = self::coachUserIdForAthleteUser($userId, $db);
        if ($coachUserId) {
            try {
                Notifications::send($coachUserId, 'payment_failed_coach', [
                    'athlete_name' => $name,
                    'athlete_id'   => self::athleteIdForUser($userId, $db),
                ]);
            } catch (\Throwable $e) {
                error_log('StripeWebhook: payment_failed_coach notify failed: ' . $e->getMessage());
            }
        }
    }

    private static function handlePaymentSucceeded($invoice, PDO $db): void
    {
        $userId = self::userIdForCustomer($invoice['customer'] ?? '', $db);
        if (!$userId) return;

        $row = Billing::userBillingRow($userId, $db);
        $wasPastDue = ($row['subscription_status'] ?? '') === 'past_due';

        // Pull the live subscription so the row reflects the true Stripe state.
        $subId  = $invoice['subscription'] ?? '';
        $client = Billing::client();
        if ($subId && $client) {
            try {
                $sub = $client->subscriptions->retrieve($subId, []);
                Billing::syncSubscription($sub, $userId, $db);
            } catch (\Throwable $e) {
                error_log('StripeWebhook: subscription retrieve failed: ' . $e->getMessage());
            }
        }

        if ($wasPastDue) {
            $db->prepare(
                'UPDATE users SET subscription_status = \'active\', grace_period_ends = NULL WHERE id = ?'
            )->execute([$userId]);
        }
    }

    // ── lookups ──────────────────────────────────────────────────────────────

    private static function userIdForCustomer(string $customerId, PDO $db): ?int
    {
        if ($customerId === '') return null;
        $stmt = $db->prepare('SELECT id FROM users WHERE stripe_customer_id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private static function userName(int $userId, PDO $db): string
    {
        $stmt = $db->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return (string)($stmt->fetchColumn() ?: 'Your athlete');
    }

    private static function athleteIdForUser(int $userId, PDO $db): int
    {
        $stmt = $db->prepare('SELECT id FROM athletes WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private static function coachUserIdForAthleteUser(int $userId, PDO $db): ?int
    {
        $stmt = $db->prepare('SELECT coach_id FROM athletes WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $cid = $stmt->fetchColumn();
        return $cid ? (int)$cid : null;
    }
}

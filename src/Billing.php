<?php
/**
 * Billing — Stripe integration + subscription access control (Milestone 8).
 *
 * Two responsibilities live here:
 *   1. A thin wrapper over the stripe/stripe-php SDK (customers, coupons,
 *      Checkout sessions, the Billing Portal, and subscription sync).
 *   2. The app-side subscription gate that decides whether an athlete may use
 *      the app, plus the cancellation banner.
 *
 * The canonical subscription state lives on the USERS row:
 *   stripe_customer_id, subscription_status, subscription_end_date,
 *   billing_interval, grace_period_ends.
 *
 * Everything degrades gracefully when the SDK or the Stripe keys are absent
 * (mirroring Mailer/Notifications): API calls return null and the gate falls
 * back to "allow" so a Stripe-less environment is never bricked.
 *
 * NOTE on naming: the SDK class is `\Stripe\StripeClient` (namespaced); this
 * service is deliberately named `Billing` to avoid any confusion with it.
 */
class Billing
{
    /** Stripe status string → our users.subscription_status enum. */
    private const STATUS_MAP = [
        'trialing'           => 'trialing',
        'active'             => 'active',
        'past_due'           => 'past_due',
        'unpaid'             => 'past_due',
        'canceled'           => 'canceled',
        'incomplete'         => 'none',
        'incomplete_expired' => 'canceled',
        'paused'             => 'canceled',
    ];

    // ── SDK plumbing ─────────────────────────────────────────────────────────

    public static function isConfigured(): bool
    {
        return defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== ''
            && class_exists('Stripe\\StripeClient');
    }

    /** Configured Stripe client, or null when the SDK/key is unavailable. */
    public static function client(): ?\Stripe\StripeClient
    {
        if (!self::isConfigured()) {
            error_log('Billing: Stripe not configured (missing SDK or STRIPE_SECRET_KEY).');
            return null;
        }
        return new \Stripe\StripeClient(STRIPE_SECRET_KEY);
    }

    private static function priceFor(string $interval): string
    {
        return $interval === 'annual' ? STRIPE_PRICE_ANNUAL : STRIPE_PRICE_MONTHLY;
    }

    private static function baseUrl(): string
    {
        return defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://simplyrunfaster.com/app';
    }

    // ── Stripe API wrappers ──────────────────────────────────────────────────

    /**
     * Create a Stripe customer for a user and store the id on the users row.
     * Returns the customer object, or null on failure / no Stripe.
     */
    public static function createCustomer(int $userId, string $email, string $name, PDO $db)
    {
        $client = self::client();
        if (!$client) return null;
        try {
            $customer = $client->customers->create([
                'email'    => $email,
                'name'     => $name,
                'metadata' => ['srf_user_id' => (string)$userId],
            ]);
            $db->prepare('UPDATE users SET stripe_customer_id = ? WHERE id = ?')
               ->execute([$customer->id, $userId]);
            return $customer;
        } catch (\Throwable $e) {
            error_log('Billing::createCustomer failed for user ' . $userId . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Existing customer id for a user, creating one if needed. */
    public static function ensureCustomer(int $userId, PDO $db): ?string
    {
        $stmt = $db->prepare('SELECT stripe_customer_id, email, name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!empty($row['stripe_customer_id'])) return $row['stripe_customer_id'];

        $customer = self::createCustomer($userId, $row['email'], $row['name'], $db);
        return $customer ? $customer->id : null;
    }

    /**
     * Create a Stripe coupon from an invite-link discount.
     *   'forever'                       → duration=forever
     *   '30d'/'60d'/'90d'/'120d'/'365d' → duration=repeating, duration_in_months
     * Returns the coupon id, or null on failure / no Stripe.
     */
    public static function createCoupon(int $discountPercent, string $duration): ?string
    {
        $client = self::client();
        if (!$client) return null;
        if ($discountPercent <= 0 || $discountPercent > 100) return null;

        $params = ['percent_off' => $discountPercent, 'name' => $discountPercent . '% off (' . $duration . ')'];
        if ($duration === 'forever') {
            $params['duration'] = 'forever';
        } else {
            $days = (int)rtrim($duration, 'd');
            if ($days < 1) return null;
            $params['duration']           = 'repeating';
            $params['duration_in_months'] = max(1, (int)round($days / 30));
        }

        try {
            return $client->coupons->create($params)->id;
        } catch (\Throwable $e) {
            error_log('Billing::createCoupon failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a subscription Checkout session and return its URL (or null).
     */
    public static function createCheckoutSession(int $userId, string $billingInterval, ?string $couponId, PDO $db): ?string
    {
        $client = self::client();
        if (!$client) return null;

        $customerId = self::ensureCustomer($userId, $db);
        if (!$customerId) return null;

        $interval = $billingInterval === 'annual' ? 'annual' : 'monthly';
        $price    = self::priceFor($interval);
        if ($price === '') {
            error_log('Billing::createCheckoutSession: no price id for interval ' . $interval);
            return null;
        }

        $base = self::baseUrl();
        $params = [
            'mode'        => 'subscription',
            'customer'    => $customerId,
            'line_items'  => [['price' => $price, 'quantity' => 1]],
            'success_url' => $base . '/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $base . '/billing/cancel',
            'subscription_data' => ['metadata' => ['srf_user_id' => (string)$userId]],
            'metadata'    => ['srf_user_id' => (string)$userId],
        ];
        if ($couponId) {
            $params['discounts'] = [['coupon' => $couponId]];
        }

        try {
            $session = $client->checkout->sessions->create($params);
            return $session->url;
        } catch (\Throwable $e) {
            error_log('Billing::createCheckoutSession failed for user ' . $userId . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Create a Billing Portal session and return its URL (or null). */
    public static function createBillingPortalSession(int $userId, PDO $db): ?string
    {
        $client = self::client();
        if (!$client) return null;

        $customerId = self::ensureCustomer($userId, $db);
        if (!$customerId) return null;

        try {
            $session = $client->billingPortal->sessions->create([
                'customer'   => $customerId,
                'return_url' => self::baseUrl() . '/billing',
            ]);
            return $session->url;
        } catch (\Throwable $e) {
            error_log('Billing::createBillingPortalSession failed for user ' . $userId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update a users row from a Stripe Subscription object (or array).
     * Reads via array access so it works with both StripeObject and plain arrays.
     */
    public static function syncSubscription($subscription, int $userId, PDO $db): void
    {
        $stripeStatus = $subscription['status'] ?? 'none';
        $status       = self::STATUS_MAP[$stripeStatus] ?? 'none';

        $interval = $subscription['items']['data'][0]['price']['recurring']['interval'] ?? null;
        $billingInterval = $interval === 'year' ? 'annual' : ($interval === 'month' ? 'monthly' : null);

        $cancelAtPeriodEnd = !empty($subscription['cancel_at_period_end']);
        $periodEnd         = (int)($subscription['current_period_end'] ?? 0);

        // subscription_end_date: when canceled or set to cancel at period end.
        $endDate = null;
        if (($status === 'canceled' || $cancelAtPeriodEnd) && $periodEnd > 0) {
            $endDate = gmdate('Y-m-d', $periodEnd);
            if ($cancelAtPeriodEnd && $status !== 'canceled') {
                // Still active but scheduled to cancel — surface the access-until
                // date and reflect the canceled state for banner/gate purposes.
                $status = 'canceled';
            }
        }

        // grace_period_ends is cleared whenever we return to active.
        $clearGrace = $status === 'active' || $status === 'trialing';

        $sql = 'UPDATE users SET subscription_status = ?, subscription_end_date = ?, billing_interval = COALESCE(?, billing_interval)';
        $args = [$status, $endDate, $billingInterval];
        if ($clearGrace) {
            $sql .= ', grace_period_ends = NULL';
        }
        $sql .= ' WHERE id = ?';
        $args[] = $userId;

        $db->prepare($sql)->execute($args);
    }

    /**
     * After a successful Checkout return, pull the subscription from the session
     * and sync the users row so access is granted immediately (not only on the
     * async webhook).
     */
    public static function syncFromCheckoutSession(string $sessionId, int $userId, PDO $db): void
    {
        $client = self::client();
        if (!$client || $sessionId === '') return;
        try {
            $session = $client->checkout->sessions->retrieve($sessionId, ['expand' => ['subscription']]);
            $sub = $session->subscription ?? null;
            if (is_string($sub)) {
                $sub = $client->subscriptions->retrieve($sub, []);
            }
            if ($sub) self::syncSubscription($sub, $userId, $db);
        } catch (\Throwable $e) {
            error_log('Billing::syncFromCheckoutSession failed for user ' . $userId . ': ' . $e->getMessage());
        }
    }

    /** Cancel the user's active subscription at period end. Returns success. */
    public static function cancelAtPeriodEnd(int $userId, PDO $db): bool
    {
        $client = self::client();
        if (!$client) return false;
        $row = self::userBillingRow($userId, $db);
        if (empty($row['stripe_customer_id'])) return false;
        try {
            $subs = $client->subscriptions->all([
                'customer' => $row['stripe_customer_id'], 'status' => 'active', 'limit' => 1,
            ]);
            $sub = $subs->data[0] ?? null;
            if (!$sub) return false;
            $updated = $client->subscriptions->update($sub->id, ['cancel_at_period_end' => true]);
            self::syncSubscription($updated, $userId, $db);
            return true;
        } catch (\Throwable $e) {
            error_log('Billing::cancelAtPeriodEnd failed for user ' . $userId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Everything the athlete billing page needs: the stored status plus live
     * Stripe details (next billing date, default card, recent invoices).
     * Degrades to just the stored row when Stripe is unavailable.
     */
    public static function athleteBillingView(int $userId, PDO $db): array
    {
        $row  = self::userBillingRow($userId, $db) ?? [];
        $view = [
            'status'                => $row['subscription_status'] ?? 'none',
            'interval'              => $row['billing_interval'] ?? null,
            'subscription_end_date' => $row['subscription_end_date'] ?? null,
            'grace_period_ends'     => $row['grace_period_ends'] ?? null,
            'next_billing_date'     => null,
            'cancel_at_period_end'  => false,
            'payment_method'        => null,
            'invoices'              => [],
            'subscription_id'       => null,
            'can_manage'            => self::isConfigured() && !empty($row['stripe_customer_id']),
        ];

        $client = self::client();
        if (!$client || empty($row['stripe_customer_id'])) return $view;

        try {
            $subs = $client->subscriptions->all([
                'customer' => $row['stripe_customer_id'], 'status' => 'all', 'limit' => 1,
            ]);
            $sub = $subs->data[0] ?? null;
            if ($sub) {
                $view['subscription_id']      = $sub->id;
                $view['cancel_at_period_end'] = (bool)($sub->cancel_at_period_end ?? false);
                if (!empty($sub->current_period_end)) {
                    $view['next_billing_date'] = gmdate('Y-m-d', (int)$sub->current_period_end);
                }
                $intv = $sub->items->data[0]->price->recurring->interval ?? null;
                if ($intv === 'year')  $view['interval'] = 'annual';
                if ($intv === 'month') $view['interval'] = 'monthly';
            }

            $cust = $client->customers->retrieve(
                $row['stripe_customer_id'],
                ['expand' => ['invoice_settings.default_payment_method']]
            );
            $pm = $cust->invoice_settings->default_payment_method ?? null;
            if ($pm && isset($pm->card)) {
                $view['payment_method'] = ['brand' => $pm->card->brand, 'last4' => $pm->card->last4];
            }

            $invs = $client->invoices->all(['customer' => $row['stripe_customer_id'], 'limit' => 12]);
            foreach ($invs->data as $inv) {
                $cents = $inv->amount_paid ?? ($inv->amount_due ?? 0);
                $view['invoices'][] = [
                    'date'     => gmdate('Y-m-d', (int)($inv->created ?? 0)),
                    'amount'   => number_format($cents / 100, 2),
                    'currency' => strtoupper($inv->currency ?? 'usd'),
                    'status'   => $inv->status ?? '',
                    'pdf'      => $inv->invoice_pdf ?? ($inv->hosted_invoice_url ?? null),
                ];
            }
        } catch (\Throwable $e) {
            error_log('Billing::athleteBillingView failed for user ' . $userId . ': ' . $e->getMessage());
        }

        return $view;
    }

    // ── Subscription state / access gate ─────────────────────────────────────

    /** Billing fields for a user (or null). */
    public static function userBillingRow(int $userId, PDO $db): ?array
    {
        $stmt = $db->prepare(
            'SELECT id, role, stripe_customer_id, subscription_status,
                    subscription_end_date, billing_interval, grace_period_ends
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Does this users row grant app access today?
     *   active / trialing / comped → yes
     *   past_due  → only within the grace window
     *   canceled  → only until subscription_end_date (banner shown meanwhile)
     *   none      → yes, unless BILLING_GATE_STRICT (grandfathered / pre-checkout)
     */
    public static function hasAccess(array $row): bool
    {
        $today  = gmdate('Y-m-d');
        $status = $row['subscription_status'] ?? 'none';

        switch ($status) {
            case 'active':
            case 'trialing':
            case 'comped':
                return true;
            case 'past_due':
                return !empty($row['grace_period_ends']) && $row['grace_period_ends'] >= $today;
            case 'canceled':
                return !empty($row['subscription_end_date']) && $row['subscription_end_date'] >= $today;
            case 'none':
            default:
                return !(defined('BILLING_GATE_STRICT') && BILLING_GATE_STRICT);
        }
    }

    /** Reason an athlete is locked out, for the access-ended page. */
    public static function lockoutReason(array $row): string
    {
        $status = $row['subscription_status'] ?? 'none';
        if ($status === 'past_due')  return 'past_due';
        if ($status === 'canceled')  return 'canceled';
        return 'inactive';
    }

    /**
     * Central athlete subscription gate, invoked from index.php for any
     * logged-in athlete. $fullUri is the request path including the /app prefix.
     * Allowlisted areas (onboarding, billing, logout, offline) always pass so a
     * lapsed athlete can still reach the billing/reactivation flow.
     */
    public static function enforceAthleteAccess(string $fullUri): void
    {
        $path = $fullUri;
        if (str_starts_with($path, '/app')) {
            $path = substr($path, 4);
        }
        $path = '/' . ltrim($path, '/');

        foreach (['/onboarding', '/billing', '/logout', '/offline', '/privacy'] as $allow) {
            if ($path === $allow || str_starts_with($path, $allow . '/')) {
                return;
            }
        }

        $db  = Database::get();
        $row = self::userBillingRow(Auth::userId(), $db);
        if (!$row || self::hasAccess($row)) {
            return;
        }

        self::renderAccessEnded($row, $db);
    }

    /** Render the standalone "access has ended" page and exit. */
    private static function renderAccessEnded(array $row, PDO $db): void
    {
        http_response_code(402); // Payment Required
        $reason     = self::lockoutReason($row);
        $canPortal  = self::isConfigured() && !empty($row['stripe_customer_id']);
        $portalUrl  = '/app/billing/portal';
        require __DIR__ . '/../views/billing/access_ended.php';
        exit;
    }

    /**
     * Cancellation banner payload for an athlete still inside their access
     * window, else null. Shown on every athlete page (dismissible per session).
     */
    public static function cancellationBanner(int $userId): ?array
    {
        $db  = Database::get();
        $row = self::userBillingRow($userId, $db);
        if (!$row || ($row['subscription_status'] ?? '') !== 'canceled') return null;
        $end = $row['subscription_end_date'] ?? null;
        if (!$end || $end < gmdate('Y-m-d')) return null;
        return ['end_date' => $end];
    }

    // ── Display helpers ──────────────────────────────────────────────────────

    public static function statusLabel(string $status): string
    {
        return [
            'none'     => 'No subscription',
            'trialing' => 'Trialing',
            'active'   => 'Active',
            'past_due' => 'Past due',
            'canceled' => 'Canceled',
            'comped'   => 'Comped',
        ][$status] ?? ucfirst($status);
    }

    public static function intervalLabel(?string $interval): string
    {
        if ($interval === 'monthly') return STRIPE_PRICE_MONTHLY_DISPLAY;
        if ($interval === 'annual')  return STRIPE_PRICE_ANNUAL_DISPLAY;
        return '—';
    }
}

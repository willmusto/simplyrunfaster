<?php
/**
 * AdminController — admin-only overviews (Milestone 8: billing).
 */
class AdminController
{
    /** GET /app/admin/billing — read-only billing overview across all athletes. */
    public static function billing(): void
    {
        Auth::requireRole('admin');
        require_once __DIR__ . '/../../views/layout/base.php';

        $db = Database::get();

        $statuses = ['active','past_due','canceled','comped','trialing','none'];
        $filter   = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : null;

        $sql =
            'SELECT u.id, u.name, u.email, u.subscription_status, u.billing_interval,
                    u.subscription_end_date, u.grace_period_ends, u.stripe_customer_id,
                    il.discount_percent, il.discount_duration
             FROM users u
             JOIN athletes a ON a.user_id = u.id
             LEFT JOIN invite_links il ON il.code = u.invite_code';
        $args = [];
        if ($filter !== null) {
            $sql .= ' WHERE u.subscription_status = ?';
            $args[] = $filter;
        }
        $sql .= ' ORDER BY u.name';

        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Counts per status for the filter chips.
        $counts = [];
        foreach ($db->query(
            'SELECT u.subscription_status AS s, COUNT(*) AS c
             FROM users u JOIN athletes a ON a.user_id = u.id
             GROUP BY u.subscription_status'
        )->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $counts[$c['s']] = (int)$c['c'];
        }

        // Coach-shell nav needs these.
        $coachId          = Auth::userId();
        $openFlags        = 0;
        $pendingApprovals = 0;
        $athletes         = [];

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $pageTitle = 'Billing overview';
        $activeNav = 'settings';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/admin/billing.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /**
     * POST /app/admin/billing/comp — comp an athlete: mark their users row
     * comped (clearing any grace / end date) and cancel any live Stripe
     * subscription immediately. Admin only.
     */
    public static function comp(): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $_SESSION['flash_error'] = 'No athlete specified.';
            header('Location: /app/admin/billing');
            exit;
        }

        $db = Database::get();

        // Confirm the target is an athlete and grab their Stripe customer id.
        $stmt = $db->prepare(
            'SELECT u.id, u.name, u.stripe_customer_id
             FROM users u JOIN athletes a ON a.user_id = u.id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $_SESSION['flash_error'] = 'Athlete not found.';
            header('Location: /app/admin/billing');
            exit;
        }

        // Cancel any live Stripe subscription immediately so we stop billing.
        if (!empty($row['stripe_customer_id'])) {
            $client = Billing::client();
            if ($client) {
                try {
                    $subs = $client->subscriptions->all([
                        'customer' => $row['stripe_customer_id'], 'status' => 'all', 'limit' => 10,
                    ]);
                    foreach ($subs->data as $sub) {
                        if (in_array($sub->status, ['canceled', 'incomplete_expired'], true)) continue;
                        $client->subscriptions->cancel($sub->id);
                    }
                } catch (\Throwable $e) {
                    error_log('AdminController::comp Stripe cancel failed for user ' . $userId . ': ' . $e->getMessage());
                    $_SESSION['flash_error'] = 'Marked comped, but the Stripe subscription could not be canceled. Check Stripe.';
                }
            }
        }

        $db->prepare(
            'UPDATE users
             SET subscription_status = ?, grace_period_ends = NULL, subscription_end_date = NULL
             WHERE id = ?'
        )->execute(['comped', $userId]);

        if (empty($_SESSION['flash_error'])) {
            $_SESSION['flash_success'] = $row['name'] . ' is now comped.';
        }
        header('Location: /app/admin/billing');
        exit;
    }
}

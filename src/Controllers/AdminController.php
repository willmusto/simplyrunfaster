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

        $pageTitle = 'Billing overview';
        $activeNav = 'settings';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/admin/billing.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }
}

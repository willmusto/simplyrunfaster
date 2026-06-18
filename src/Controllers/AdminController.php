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
        $activeNav = 'admin_billing';
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

    // ── User management ────────────────────────────────────────

    /** GET /app/admin/users — list every user with role/status/actions. */
    public static function users(): void
    {
        Auth::requireRole('admin');
        require_once __DIR__ . '/../../views/layout/base.php';

        $db = Database::get();
        $users = $db->query(
            'SELECT u.id, u.name, u.email, u.role, u.active, u.managed_by, u.created_at,
                    m.name AS manager_name
             FROM users u
             LEFT JOIN users m ON m.id = u.managed_by
             ORDER BY FIELD(u.role, "admin","coach","assistant_coach","athlete"), u.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $coaches = self::coachOptions($db);

        self::renderShell('admin_users', 'User management', 'users', ['users' => $users, 'coaches' => $coaches]);
    }

    /**
     * GET /app/admin/coaches — read-only per-coach performance analytics (Phase 4, B1).
     * Windows: compliance = mean completed compliance_score over the last 28 days across the
     * coach's active athletes; flag resolution = mean hours from raise to a genuine COACH
     * action (actioned/dismissed, EXCLUDING auto-'superseded') over flags raised in the last
     * 90 days; retention = active ÷ all-time-assigned athletes. Dormancy-gated (≥2 coaches).
     */
    public static function coaches(): void
    {
        Auth::requireRole('admin');
        require_once __DIR__ . '/../../views/layout/base.php';
        require_once __DIR__ . '/../CoachAssignments.php';

        $db = Database::get();
        $multiCoach = CoachAssignments::multiCoach($db);
        $rows = [];

        if ($multiCoach) {
            $coaches = $db->query(
                "SELECT id, name, role FROM users WHERE active = 1 AND role IN ('coach','assistant_coach')
                 ORDER BY FIELD(role,'coach','assistant_coach'), name"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($coaches as $c) {
                $cid = (int)$c['id'];

                $activeAthletes = (int)self::scalar($db, "SELECT COUNT(*) FROM athletes WHERE coach_id = ? AND status = 'active'", [$cid]);
                $totalAthletes  = (int)self::scalar($db, "SELECT COUNT(*) FROM athletes WHERE coach_id = ?", [$cid]);

                $avgCompliance = self::scalar($db,
                    "SELECT AVG(cw.compliance_score) FROM completed_workouts cw
                     JOIN athletes a ON a.id = cw.athlete_id AND a.coach_id = ?
                     WHERE cw.compliance_score IS NOT NULL AND cw.activity_date >= (CURDATE() - INTERVAL 28 DAY)",
                    [$cid]);

                // Genuine coach actions only: actioned/dismissed, NOT superseded auto-resolutions.
                $resolveHours = self::scalar($db,
                    "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, COALESCE(actioned_at, dismissed_at)))
                     FROM coaching_intelligence_flags
                     WHERE coach_id = ? AND status IN ('actioned','dismissed')
                       AND created_at >= (NOW() - INTERVAL 90 DAY)",
                    [$cid]);
                $resolvedCount = (int)self::scalar($db,
                    "SELECT COUNT(*) FROM coaching_intelligence_flags
                     WHERE coach_id = ? AND status IN ('actioned','dismissed') AND created_at >= (NOW() - INTERVAL 90 DAY)",
                    [$cid]);
                $supersededCount = (int)self::scalar($db,
                    "SELECT COUNT(*) FROM coaching_intelligence_flags
                     WHERE coach_id = ? AND status = 'superseded' AND created_at >= (NOW() - INTERVAL 90 DAY)",
                    [$cid]);

                $rows[] = [
                    'name' => $c['name'],
                    'role' => $c['role'],
                    'active_athletes' => $activeAthletes,
                    'total_athletes'  => $totalAthletes,
                    'avg_compliance'  => ($avgCompliance === null) ? null : (float)$avgCompliance,
                    'resolve_hours'   => ($resolveHours === null) ? null : (float)$resolveHours,
                    'resolved_count'  => $resolvedCount,
                    'superseded_count'=> $supersededCount,
                    'retention'       => $totalAthletes > 0 ? $activeAthletes / $totalAthletes : null,
                ];
            }
        }

        self::renderShell('users', 'Coach analytics', 'admin_coaches', [
            'rows' => $rows, 'multiCoach' => $multiCoach,
        ]);
    }

    private static function scalar(PDO $db, string $sql, array $params)
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** GET /app/admin/users/create — new coach / assistant-coach form. */
    public static function createUserForm(): void
    {
        Auth::requireRole('admin');
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $coaches = self::coachOptions($db);

        self::renderShell('admin_users', 'Create user', 'user_create', ['coaches' => $coaches]);
    }

    /** POST /app/admin/users/create — create the account, email a temp password. */
    public static function createUserSubmit(): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();

        $db    = Database::get();
        $name  = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role  = in_array($_POST['role'] ?? '', ['coach', 'assistant_coach'], true) ? $_POST['role'] : '';
        $managedBy = $role === 'assistant_coach' ? (int)($_POST['managed_by'] ?? 0) : null;

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$role) {
            $_SESSION['flash_error'] = 'Name, a valid email, and a role are required.';
            header('Location: /app/admin/users/create');
            exit;
        }
        if ($role === 'assistant_coach' && !self::isHeadCoach($managedBy, $db)) {
            $_SESSION['flash_error'] = 'Assistant coaches must be assigned to a head coach.';
            header('Location: /app/admin/users/create');
            exit;
        }
        $exists = $db->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $exists->execute([$email]);
        if ($exists->fetchColumn()) {
            $_SESSION['flash_error'] = 'An account with that email already exists.';
            header('Location: /app/admin/users/create');
            exit;
        }

        $tempPassword = self::generateTempPassword();
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

        $db->prepare(
            'INSERT INTO users (email, password_hash, must_change_password, active, role, managed_by, name, signup_source, theme_preference)
             VALUES (?, ?, 1, 1, ?, ?, ?, "other", "system")'
        )->execute([$email, $hash, $role, $managedBy, $name]);
        $userId = (int)$db->lastInsertId();

        // Seed notification preferences for the new coach/assistant.
        Notifications::ensureUserDefaults($userId, $role);

        $sent = self::sendWelcomeEmail($email, $name, $tempPassword);

        $_SESSION['flash_success'] = $name . ' created as ' . self::roleLabel($role) . '.'
            . ($sent ? ' A welcome email with a temporary password was sent.' : ' Welcome email could not be sent; check the mailer.');
        header('Location: /app/admin/users');
        exit;
    }

    /** POST /app/admin/users/role — promote/demote (coach / assistant_coach / athlete). */
    public static function updateRole(): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();

        $db     = Database::get();
        $userId = (int)($_POST['user_id'] ?? 0);
        $role   = in_array($_POST['role'] ?? '', ['coach', 'assistant_coach', 'athlete'], true) ? $_POST['role'] : '';

        $target = self::userRow($userId, $db);
        if (!$target || !$role) {
            $_SESSION['flash_error'] = 'Invalid user or role.';
            header('Location: /app/admin/users');
            exit;
        }
        // Admins are never demoted here, and the admin role is not assignable.
        if ($target['role'] === 'admin' || $userId === (int)Auth::userId()) {
            $_SESSION['flash_error'] = 'Admin accounts cannot be changed here.';
            header('Location: /app/admin/users');
            exit;
        }

        $managedBy = null;
        if ($role === 'assistant_coach') {
            $managedBy = (int)($_POST['managed_by'] ?? 0);
            if (!self::isHeadCoach($managedBy, $db)) {
                $_SESSION['flash_error'] = 'Assistant coaches must be assigned to a head coach.';
                header('Location: /app/admin/users');
                exit;
            }
        }

        $db->prepare('UPDATE users SET role = ?, managed_by = ? WHERE id = ?')
           ->execute([$role, $managedBy, $userId]);

        // Demotion to athlete needs an athletes row so the portal works.
        if ($role === 'athlete') {
            $has = $db->prepare('SELECT 1 FROM athletes WHERE user_id = ? LIMIT 1');
            $has->execute([$userId]);
            if (!$has->fetchColumn()) {
                $db->prepare('INSERT INTO athletes (user_id, billing_status) VALUES (?, "trialing")')->execute([$userId]);
                $athleteId = (int)$db->lastInsertId();
                $db->prepare('INSERT IGNORE INTO athlete_profiles (athlete_id) VALUES (?)')->execute([$athleteId]);
            }
        }

        $_SESSION['flash_success'] = $target['name'] . ' is now ' . self::roleLabel($role) . '.';
        header('Location: /app/admin/users');
        exit;
    }

    /** POST /app/admin/users/deactivate — block login (active = 0). */
    public static function deactivateUser(): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();

        $db     = Database::get();
        $userId = (int)($_POST['user_id'] ?? 0);
        $target = self::userRow($userId, $db);

        if (!$target || $target['role'] === 'admin' || $userId === (int)Auth::userId()) {
            $_SESSION['flash_error'] = 'That account cannot be deactivated.';
            header('Location: /app/admin/users');
            exit;
        }

        $db->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([$userId]);
        $_SESSION['flash_success'] = $target['name'] . ' has been deactivated and can no longer log in.';
        header('Location: /app/admin/users');
        exit;
    }

    /** GET /app/admin/athletes — reassign athletes between head coaches. */
    public static function athletes(): void
    {
        Auth::requireRole('admin');
        require_once __DIR__ . '/../../views/layout/base.php';

        $db = Database::get();
        $athletes = $db->query(
            'SELECT a.id, u.name, u.email, ca.coach_id, c.name AS coach_name
             FROM athletes a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN coach_assignments ca ON ca.athlete_id = a.id
             LEFT JOIN users c ON c.id = ca.coach_id
             ORDER BY u.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $coaches = self::coachOptions($db);

        self::renderShell('admin_users', 'Athlete assignments', 'athletes', ['athleteRows' => $athletes, 'coaches' => $coaches]);
    }

    /** POST /app/admin/athletes/reassign — change an athlete's head coach. */
    public static function reassignAthlete(): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();

        $db        = Database::get();
        $athleteId = (int)($_POST['athlete_id'] ?? 0);
        $coachId   = (int)($_POST['coach_id'] ?? 0);

        if (!$athleteId || !self::isHeadCoach($coachId, $db)) {
            $_SESSION['flash_error'] = 'Pick a valid head coach.';
            header('Location: /app/admin/athletes');
            exit;
        }

        CoachAssignments::assignCoach($athleteId, $coachId, (int)Auth::userId(), $db);
        $_SESSION['flash_success'] = 'Athlete reassigned.';
        header('Location: /app/admin/athletes');
        exit;
    }

    // ── Helpers ────────────────────────────────────────────────

    /** Render an admin page inside the coach shell. */
    private static function renderShell(string $activeNav, string $pageTitle, string $view, array $vars): void
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        // Coach-shell nav expects these; admins manage via this section, not a roster.
        $athletes = [];
        $openFlags = 0;
        $pendingApprovals = 0;

        extract($vars);
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/admin/' . $view . '.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /** Users eligible to be a head coach (coach or admin). */
    private static function coachOptions(PDO $db): array
    {
        return $db->query(
            'SELECT id, name, role FROM users WHERE role IN ("coach","admin") AND active = 1 ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function isHeadCoach(?int $userId, PDO $db): bool
    {
        if (!$userId) return false;
        $stmt = $db->prepare('SELECT 1 FROM users WHERE id = ? AND role IN ("coach","admin") AND active = 1 LIMIT 1');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    }

    private static function userRow(int $userId, PDO $db): ?array
    {
        if (!$userId) return null;
        $stmt = $db->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            'coach'           => 'a head coach',
            'assistant_coach' => 'an assistant coach',
            'athlete'         => 'an athlete',
            'admin'           => 'an admin',
            default           => $role,
        };
    }

    /** A readable temporary password (no ambiguous characters). */
    private static function generateTempPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * Welcome email for a newly created coach/assistant account. Plain copy with
     * NO em dashes anywhere (house style for this email).
     */
    private static function sendWelcomeEmail(string $email, string $name, string $tempPassword): bool
    {
        $loginUrl = 'simplyrunfaster.com/app/login';
        $first    = explode(' ', trim($name))[0] ?: 'there';

        $subject = "You've been added to SimplyRunFaster";
        $text = "Hi {$first}, your coaching account has been created. "
              . "Log in at {$loginUrl} with your email and temporary password: {$tempPassword}. "
              . "You'll be prompted to change it on first login.";

        $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#333;max-width:520px;margin:0 auto;">'
              . '<p>Hi ' . $h($first) . ', your coaching account has been created.</p>'
              . '<p>Log in at <a href="https://' . $loginUrl . '" style="color:#1D9E75;">' . $loginUrl . '</a> '
              . 'with your email and this temporary password:</p>'
              . '<p style="font-size:18px;font-weight:700;letter-spacing:1px;background:#f4f6f5;border-radius:8px;padding:12px 16px;text-align:center;">'
              . $h($tempPassword) . '</p>'
              . '<p>You will be prompted to change it on first login.</p>'
              . '</div>';

        return Mailer::send($email, $subject, $html, $text);
    }
}

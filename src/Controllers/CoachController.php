<?php
class CoachController
{
    public static function dashboard(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $coachId = Auth::userId();

        $athletes          = self::getRosterAthletes($coachId, $db);
        $openFlags         = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals  = self::getPendingApprovalsCount($coachId, $db);
        $criticalFlags     = self::getOpenFlags($coachId, $db, 'critical', 5);
        $warningFlags      = self::getOpenFlags($coachId, $db, 'warning', 5);
        $pendingPlans      = self::getPendingPlans($coachId, $db, 5);
        $upcomingRaces     = self::getUpcomingRaces($coachId, $db, 14);
        $unreadMessages    = self::getUnreadMessageThreads($coachId, $db);

        $pageTitle  = 'Dashboard';
        $activeNav  = 'dashboard';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/dashboard.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function roster(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $coachId = Auth::userId();

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        // Filter and sort
        $sort = $_GET['sort'] ?? 'alerts';
        usort($athletes, function ($a, $b) use ($sort) {
            return match($sort) {
                'compliance' => ($a['avg_compliance'] ?? 1) <=> ($b['avg_compliance'] ?? 1),
                'race_date'  => ($a['next_race_date'] ?? '9999') <=> ($b['next_race_date'] ?? '9999'),
                'name'       => $a['name'] <=> $b['name'],
                default      => // alerts: critical → warning → none
                    (($b['open_critical'] ?? 0) - ($a['open_critical'] ?? 0)) ?:
                    (($b['open_warnings'] ?? 0) - ($a['open_warnings'] ?? 0)),
            };
        });

        $pageTitle = 'Athletes';
        $activeNav = 'athletes';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/roster.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function athleteView(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db        = Database::get();
        $coachId   = Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);

        $athlete  = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) {
            http_response_code(404);
            include __DIR__ . '/../../views/errors/404.php';
            return;
        }

        $profile       = Auth::getAthleteProfile($athleteId);
        $activePlan    = self::getActivePlanDetail($athleteId, $db);
        $allWorkouts   = $activePlan ? self::getPlanWorkouts((int)$activePlan['id'], $db) : [];
        $athleteFlags  = self::getAthleteFlags($athleteId, $db, 10);
        $loadSnapshot  = self::getLoadSnapshot($athleteId, $db);
        $pbs           = self::getPersonalBests($athleteId, $db);
        $nextRace      = self::getNextRace($athleteId, $db);

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $pageTitle = h($athlete['name']);
        $activeNav = 'athletes';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/athlete_view.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function approvals(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $coachId = Auth::userId();

        $pendingPlans     = self::getPendingPlans($coachId, $db, 50);
        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = count($pendingPlans);

        $pageTitle = 'Plan Approvals';
        $activeNav = 'approvals';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/approvals.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function approvePlan(array $params): void
    {
        Auth::requireRole(['coach','admin']);
        Auth::verifyCsrf();

        $planId   = (int)($params['planId'] ?? 0);
        $coachId  = Auth::userId();
        $db       = Database::get();
        $notes    = trim($_POST['coach_notes'] ?? '');

        // Verify plan belongs to one of this coach's athletes
        $stmt = $db->prepare(
            'SELECT paq.id, paq.athlete_id
             FROM plan_approval_queue paq
             JOIN athletes a ON a.id = paq.athlete_id AND a.coach_id = ?
             WHERE paq.plan_id = ? AND paq.status = "pending"
             LIMIT 1'
        );
        $stmt->execute([$coachId, $planId]);
        $queue = $stmt->fetch();

        if ($queue) {
            $db->prepare(
                'UPDATE plan_approval_queue SET status="approved", reviewed_by=?, reviewed_at=NOW(), coach_notes=? WHERE id=?'
            )->execute([$coachId, $notes, $queue['id']]);

            $db->prepare(
                'UPDATE training_plans SET status="active", approved_by=?, approved_at=NOW() WHERE id=?'
            )->execute([$coachId, $planId]);
        }

        header('Location: /app/coach/approvals');
        exit;
    }

    public static function rejectPlan(array $params): void
    {
        Auth::requireRole(['coach','admin']);
        Auth::verifyCsrf();

        $planId  = (int)($params['planId'] ?? 0);
        $coachId = Auth::userId();
        $db      = Database::get();
        $notes   = trim($_POST['coach_notes'] ?? '');

        $stmt = $db->prepare(
            'SELECT paq.id FROM plan_approval_queue paq
             JOIN athletes a ON a.id = paq.athlete_id AND a.coach_id = ?
             WHERE paq.plan_id = ? AND paq.status = "pending" LIMIT 1'
        );
        $stmt->execute([$coachId, $planId]);
        $queue = $stmt->fetch();

        if ($queue) {
            $db->prepare(
                'UPDATE plan_approval_queue SET status="rejected", reviewed_by=?, reviewed_at=NOW(), coach_notes=? WHERE id=?'
            )->execute([$coachId, $notes, $queue['id']]);

            $db->prepare('UPDATE training_plans SET status="archived" WHERE id=?')->execute([$planId]);
        }

        header('Location: /app/coach/approvals');
        exit;
    }

    public static function flags(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $coachId = Auth::userId();

        $flags            = self::getOpenFlags($coachId, $db, null, 100);
        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = count($flags);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $pageTitle = 'Alerts';
        $activeNav = 'flags';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/flags.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function dismissFlag(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $flagId  = (int)($params['id'] ?? 0);
        $coachId = Auth::userId();
        $db      = Database::get();
        $reason  = trim($_POST['dismiss_reason'] ?? '');

        // Verify flag belongs to a coach's athlete
        $stmt = $db->prepare(
            'SELECT ef.id, ef.severity FROM engine_flags ef
             JOIN athletes a ON a.id = ef.athlete_id AND a.coach_id = ?
             WHERE ef.id = ? LIMIT 1'
        );
        $stmt->execute([$coachId, $flagId]);
        $flag = $stmt->fetch();

        if ($flag) {
            $db->prepare(
                'UPDATE engine_flags SET status="dismissed", reviewed_by=?, reviewed_at=NOW(), dismiss_reason=? WHERE id=?'
            )->execute([$coachId, $reason ?: null, $flagId]);
        }

        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/app/coach/flags'));
        exit;
    }

    public static function library(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $coachId = Auth::userId();

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $success = $_SESSION['flash_success'] ?? null;
        $error   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        // Filter params
        $filterType     = $_GET['type']     ?? '';
        $filterPhase    = $_GET['phase']    ?? '';
        $filterDistance = $_GET['distance'] ?? '';
        $filterSearch   = trim($_GET['q']   ?? '');

        $sql    = 'SELECT * FROM workout_library WHERE 1=1';
        $params = [];

        if ($filterType) {
            $sql     .= ' AND workout_type = ?';
            $params[] = $filterType;
        }
        if ($filterPhase) {
            $sql     .= ' AND phase_tags LIKE ?';
            $params[] = '%' . $filterPhase . '%';
        }
        if ($filterDistance) {
            $sql     .= ' AND distance_tags LIKE ?';
            $params[] = '%' . $filterDistance . '%';
        }
        if ($filterSearch) {
            $sql     .= ' AND (name LIKE ? OR athlete_facing_name LIKE ? OR description LIKE ?)';
            $like     = '%' . $filterSearch . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY workout_type ASC, name ASC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $templates = $stmt->fetchAll();

        $pageTitle = 'Workout Library';
        $activeNav = 'library';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/library.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function libraryAddTemplate(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $db   = Database::get();
        $name = trim($_POST['name'] ?? '');

        if (!$name) {
            $_SESSION['flash_error'] = 'Template name is required.';
            header('Location: /app/coach/library');
            exit;
        }

        $validTypes = ['easy','long','tempo','interval','hill','fartlek','race_pace','recovery','rest','cross_train'];
        $type = in_array($_POST['workout_type'] ?? '', $validTypes, true) ? $_POST['workout_type'] : 'easy';

        $phaseTags    = json_encode(array_filter(array_map('trim', explode(',', $_POST['phase_tags'] ?? ''))));
        $distanceTags = json_encode(array_filter(array_map('trim', explode(',', $_POST['distance_tags'] ?? ''))));

        $prescType  = in_array($_POST['prescription_type'] ?? '', ['time','distance','count'], true) ? $_POST['prescription_type'] : 'time';
        $trackReq   = in_array($_POST['track_required'] ?? '', ['yes','no','preferred'], true) ? $_POST['track_required'] : 'no';
        $intensity  = max(0.0, min(1.0, (float)($_POST['intensity_factor'] ?? 0.5)));
        $clearance  = isset($_POST['coach_clearance_required']) ? 1 : 0;
        $desc       = trim($_POST['description'] ?? '');
        $engineNotes = trim($_POST['engine_notes'] ?? '');
        $athleteName = trim($_POST['athlete_facing_name'] ?? '');

        $db->prepare(
            'INSERT INTO workout_library
             (name, athlete_facing_name, workout_type, phase_tags, distance_tags, prescription_type,
              track_required, intensity_factor, coach_clearance_required, description, engine_notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $name, $athleteName ?: null, $type, $phaseTags, $distanceTags, $prescType,
            $trackReq, $intensity, $clearance, $desc ?: null, $engineNotes ?: null, Auth::userId(),
        ]);

        $_SESSION['flash_success'] = 'Template "' . htmlspecialchars($name, ENT_QUOTES) . '" added to library.';
        header('Location: /app/coach/library');
        exit;
    }

    public static function settings(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $success          = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_success']);
        $db               = Database::get();
        $coachId          = Auth::userId();
        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $user = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $user->execute([$coachId]);
        $coachUser = $user->fetch();

        $pageTitle = 'Settings';
        $activeNav = 'settings';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/settings.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function settingsSave(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $db      = Database::get();
        $coachId = Auth::userId();
        $theme   = in_array($_POST['theme_preference'] ?? '', ['light','dark','system'], true)
            ? $_POST['theme_preference'] : 'system';

        $db->prepare('UPDATE users SET theme_preference = ? WHERE id = ?')->execute([$theme, $coachId]);
        $_SESSION['theme']         = $theme;
        $_SESSION['flash_success'] = 'Settings saved.';
        header('Location: /app/coach/settings');
        exit;
    }

    // ── Data helpers ───────────────────────────────────────────

    private static function getRosterAthletes(int $coachId, PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT
                a.id, a.user_id, a.billing_status,
                u.name, u.email,
                ap.goal_race_date, ap.goal_race_distance, ap.plan_type,
                (SELECT COUNT(*) FROM engine_flags ef WHERE ef.athlete_id=a.id AND ef.status="open" AND ef.severity="critical") as open_critical,
                (SELECT COUNT(*) FROM engine_flags ef WHERE ef.athlete_id=a.id AND ef.status="open" AND ef.severity="warning")  as open_warnings,
                (SELECT race_date FROM races r WHERE r.athlete_id=a.id AND r.race_date >= CURDATE() ORDER BY race_date LIMIT 1) as next_race_date,
                (SELECT race_distance FROM races r WHERE r.athlete_id=a.id AND r.race_date >= CURDATE() ORDER BY race_date LIMIT 1) as next_race_distance,
                (SELECT AVG(cw.compliance_score) FROM completed_workouts cw WHERE cw.athlete_id=a.id AND cw.compliance_score IS NOT NULL AND cw.activity_date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)) as avg_compliance
             FROM athletes a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN athlete_profiles ap ON ap.athlete_id = a.id
             WHERE a.coach_id = ? AND a.status = "active"
             ORDER BY u.name ASC'
        );
        $stmt->execute([$coachId]);
        return $stmt->fetchAll();
    }

    private static function getOpenFlagsCount(int $coachId, PDO $db): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM engine_flags ef
             JOIN athletes a ON a.id = ef.athlete_id AND a.coach_id = ?
             WHERE ef.status = "open"'
        );
        $stmt->execute([$coachId]);
        return (int)$stmt->fetchColumn();
    }

    private static function getPendingApprovalsCount(int $coachId, PDO $db): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM plan_approval_queue paq
             JOIN athletes a ON a.id = paq.athlete_id AND a.coach_id = ?
             WHERE paq.status = "pending"'
        );
        $stmt->execute([$coachId]);
        return (int)$stmt->fetchColumn();
    }

    private static function getOpenFlags(int $coachId, PDO $db, ?string $severity = null, int $limit = 20): array
    {
        $sql = 'SELECT ef.*, u.name as athlete_name
                FROM engine_flags ef
                JOIN athletes a ON a.id = ef.athlete_id AND a.coach_id = ?
                JOIN users u ON u.id = a.user_id
                WHERE ef.status = "open"';
        $params = [$coachId];
        if ($severity) {
            $sql    .= ' AND ef.severity = ?';
            $params[] = $severity;
        }
        $sql .= ' ORDER BY FIELD(ef.severity,"critical","warning","info"), ef.created_at DESC LIMIT ' . $limit;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private static function getPendingPlans(int $coachId, PDO $db, int $limit = 10): array
    {
        $stmt = $db->prepare(
            'SELECT paq.*, tp.plan_type, tp.plan_start_date, tp.plan_end_date, u.name as athlete_name
             FROM plan_approval_queue paq
             JOIN training_plans tp ON tp.id = paq.plan_id
             JOIN athletes a ON a.id = paq.athlete_id AND a.coach_id = ?
             JOIN users u ON u.id = a.user_id
             WHERE paq.status = "pending"
             ORDER BY paq.requested_at ASC
             LIMIT ' . $limit
        );
        $stmt->execute([$coachId]);
        return $stmt->fetchAll();
    }

    private static function getUpcomingRaces(int $coachId, PDO $db, int $days): array
    {
        $stmt = $db->prepare(
            'SELECT r.*, u.name as athlete_name
             FROM races r
             JOIN athletes a ON a.id = r.athlete_id AND a.coach_id = ?
             JOIN users u ON u.id = a.user_id
             WHERE r.race_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY r.race_date ASC'
        );
        $stmt->execute([$coachId, $days]);
        return $stmt->fetchAll();
    }

    private static function getUnreadMessageThreads(int $coachId, PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT m.athlete_id, u.name as athlete_name, m.body, m.sent_at
             FROM messages m
             JOIN athletes a ON a.id = m.athlete_id AND a.coach_id = ?
             JOIN users u ON u.id = a.user_id
             WHERE m.sender_role = "athlete" AND m.read_at IS NULL
             GROUP BY m.athlete_id
             ORDER BY m.sent_at DESC
             LIMIT 10'
        );
        $stmt->execute([$coachId]);
        return $stmt->fetchAll();
    }

    private static function getAthleteForCoach(int $athleteId, int $coachId, PDO $db): ?array
    {
        $stmt = $db->prepare(
            'SELECT a.*, u.name, u.email, u.theme_preference
             FROM athletes a JOIN users u ON u.id = a.user_id
             WHERE a.id = ? AND (a.coach_id = ? OR ? IN (SELECT id FROM users WHERE role = "admin"))
             LIMIT 1'
        );
        $stmt->execute([$athleteId, $coachId, $coachId]);
        return $stmt->fetch() ?: null;
    }

    private static function getActivePlanDetail(int $athleteId, PDO $db): ?array
    {
        $stmt = $db->prepare(
            'SELECT * FROM training_plans WHERE athlete_id = ? AND status = "active" ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$athleteId]);
        return $stmt->fetch() ?: null;
    }

    private static function getPlanWorkouts(int $planId, PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT pw.*, wl.name as template_name
             FROM planned_workouts pw
             LEFT JOIN workout_library wl ON wl.id = pw.workout_template_id
             WHERE pw.plan_id = ?
             ORDER BY pw.scheduled_date ASC'
        );
        $stmt->execute([$planId]);
        return $stmt->fetchAll();
    }

    private static function getAthleteFlags(int $athleteId, PDO $db, int $limit = 10): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM engine_flags WHERE athlete_id = ? AND status = "open"
             ORDER BY FIELD(severity,"critical","warning","info"), created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$athleteId]);
        return $stmt->fetchAll();
    }

    private static function getLoadSnapshot(int $athleteId, PDO $db): ?array
    {
        $stmt = $db->prepare(
            'SELECT atl, ctl, tsb FROM training_load WHERE athlete_id = ? ORDER BY date DESC LIMIT 1'
        );
        $stmt->execute([$athleteId]);
        return $stmt->fetch() ?: null;
    }

    private static function getPersonalBests(int $athleteId, PDO $db): array
    {
        $stmt = $db->prepare('SELECT * FROM personal_bests WHERE athlete_id = ?');
        $stmt->execute([$athleteId]);
        return $stmt->fetchAll();
    }

    private static function getNextRace(int $athleteId, PDO $db): ?array
    {
        $stmt = $db->prepare(
            'SELECT * FROM races WHERE athlete_id = ? AND race_date >= CURDATE() ORDER BY race_date LIMIT 1'
        );
        $stmt->execute([$athleteId]);
        return $stmt->fetch() ?: null;
    }
}

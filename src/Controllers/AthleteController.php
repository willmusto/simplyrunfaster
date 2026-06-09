<?php
class AthleteController
{
    public static function today(): void
    {
        Auth::requireRole('athlete');
        require_once __DIR__ . '/../../views/layout/base.php';

        $athlete  = Auth::getAthlete();
        $profile  = $athlete ? Auth::getAthleteProfile((int)$athlete['id']) : null;

        // Redirect to onboarding if not complete
        if (!$athlete || !$athlete['onboarding_completed_at']) {
            header('Location: /app/onboarding');
            exit;
        }

        $db = Database::get();

        // Active plan
        $plan = self::getActivePlan((int)$athlete['id'], $db);

        // Today's workout and this week's schedule (visible window)
        $today      = date('Y-m-d');
        $windowEnd  = date('Y-m-d', strtotime("+{$_ENV['ATHLETE_WINDOW_DAYS']} days") ?: strtotime('+10 days'));
        $workouts   = self::getVisibleWorkouts((int)$athlete['id'], $today, $windowEnd, $db);

        $todayWorkout   = null;
        $weekWorkouts   = [];
        foreach ($workouts as $w) {
            if ($w['scheduled_date'] === $today) {
                $todayWorkout = $w;
            }
            if ($w['scheduled_date'] >= $today && $w['scheduled_date'] <= date('Y-m-d', strtotime('+6 days'))) {
                $weekWorkouts[] = $w;
            }
        }

        // Training load snapshot
        $load = $db->prepare(
            'SELECT atl, ctl, tsb FROM training_load WHERE athlete_id = ? ORDER BY date DESC LIMIT 1'
        );
        $load->execute([(int)$athlete['id']]);
        $loadData = $load->fetch();

        // Unread messages count
        $msgStmt = $db->prepare(
            'SELECT COUNT(*) FROM messages WHERE athlete_id = ? AND sender_role = "coach" AND read_at IS NULL'
        );
        $msgStmt->execute([(int)$athlete['id']]);
        $unreadMessages = (int)$msgStmt->fetchColumn();

        $pageTitle = 'Today';
        $activeTab = 'today';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/today.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function plan(): void
    {
        Auth::requireRole('athlete');
        require_once __DIR__ . '/../../views/layout/base.php';

        $athlete = Auth::getAthlete();
        if (!$athlete || !$athlete['onboarding_completed_at']) {
            header('Location: /app/onboarding');
            exit;
        }

        $db      = Database::get();
        $today   = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+10 days'));
        $workouts = self::getVisibleWorkouts((int)$athlete['id'], $today, $endDate, $db);

        $pageTitle = 'My Plan';
        $activeTab = 'plan';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/plan.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function log(): void
    {
        Auth::requireRole('athlete');
        require_once __DIR__ . '/../../views/layout/base.php';

        $athlete = Auth::getAthlete();
        if (!$athlete || !$athlete['onboarding_completed_at']) {
            header('Location: /app/onboarding');
            exit;
        }

        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT cw.*, pw.workout_type as planned_type, pw.target_duration, pw.description as planned_desc
             FROM completed_workouts cw
             LEFT JOIN planned_workouts pw ON pw.id = cw.planned_workout_id
             WHERE cw.athlete_id = ?
             ORDER BY cw.activity_date DESC
             LIMIT 30'
        );
        $stmt->execute([(int)$athlete['id']]);
        $recentLog = $stmt->fetchAll();

        $pageTitle = 'Training Log';
        $activeTab = 'log';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/log.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function manualLog(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();

        $athlete = Auth::getAthlete();
        if (!$athlete) {
            header('Location: /app');
            exit;
        }

        $db          = Database::get();
        $athleteId   = (int)$athlete['id'];
        $type        = in_array($_POST['workout_type'] ?? '', ['easy','long','interval','hill','fartlek','tempo','race','recovery','cross_train','other'], true)
            ? $_POST['workout_type'] : 'easy';
        $duration    = max(0, (int)($_POST['actual_duration'] ?? 0));
        $rpeMap      = ['easy' => 2, 'moderate' => 4, 'hard' => 7, 'very_hard' => 9, 'discomfort' => 5];
        $effortDesc  = $_POST['effort_descriptor'] ?? 'moderate';
        $rpe         = $rpeMap[$effortDesc] ?? 4;
        $notes       = substr(trim($_POST['notes'] ?? ''), 0, 1000);
        $actDate     = $_POST['activity_date'] ?? date('Y-m-d');
        $complStatus = in_array($_POST['completion_status'] ?? '', ['full','partial','no'], true)
            ? $_POST['completion_status'] : 'full';

        // Find matching planned workout for today
        $planned = $db->prepare(
            'SELECT id FROM planned_workouts WHERE athlete_id = ? AND scheduled_date = ? LIMIT 1'
        );
        $planned->execute([$athleteId, $actDate]);
        $plannedRow     = $planned->fetch();
        $plannedId      = $plannedRow ? $plannedRow['id'] : null;

        $stmt = $db->prepare(
            'INSERT INTO completed_workouts
             (athlete_id, planned_workout_id, source, activity_date, workout_type,
              actual_duration, completion_status, rpe, effort_descriptor, synced_at)
             VALUES (?, ?, "manual", ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$athleteId, $plannedId, $actDate, $type, $duration, $complStatus, $rpe, $effortDesc]);

        header('Location: /app/log');
        exit;
    }

    public static function progress(): void
    {
        Auth::requireRole('athlete');
        require_once __DIR__ . '/../../views/layout/base.php';

        $athlete = Auth::getAthlete();
        if (!$athlete || !$athlete['onboarding_completed_at']) {
            header('Location: /app/onboarding');
            exit;
        }

        $db = Database::get();

        // Weekly load trend (last 8 weeks)
        $stmt = $db->prepare(
            'SELECT YEARWEEK(date, 1) as wk, SUM(daily_stress) as weekly_stress, MAX(ctl) as ctl
             FROM training_load WHERE athlete_id = ?
             GROUP BY wk ORDER BY wk DESC LIMIT 8'
        );
        $stmt->execute([(int)$athlete['id']]);
        $weeklyTrend = array_reverse($stmt->fetchAll());

        $pageTitle = 'Progress';
        $activeTab = 'progress';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/progress.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function settings(): void
    {
        Auth::requireRole('athlete');
        require_once __DIR__ . '/../../views/layout/base.php';

        $athlete = Auth::getAthlete();
        $profile = $athlete ? Auth::getAthleteProfile((int)$athlete['id']) : null;

        $success = $_SESSION['flash_success'] ?? null;
        $error   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $pageTitle = 'Settings';
        $activeTab = 'settings';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/settings.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function changePasswordSubmit(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();

        $current = $_POST['current_password']     ?? '';
        $new     = $_POST['new_password']          ?? '';
        $confirm = $_POST['new_password_confirm']  ?? '';

        $db   = Database::get();
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([Auth::userId()]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current, $user['password_hash'])) {
            $_SESSION['flash_error'] = 'Current password is incorrect.';
            header('Location: /app/settings');
            exit;
        }

        if (strlen($new) < PASSWORD_MIN_LENGTH) {
            $_SESSION['flash_error'] = 'New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
            header('Location: /app/settings');
            exit;
        }

        if ($new !== $confirm) {
            $_SESSION['flash_error'] = 'New passwords do not match.';
            header('Location: /app/settings');
            exit;
        }

        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
           ->execute([password_hash($new, PASSWORD_DEFAULT), Auth::userId()]);

        $_SESSION['flash_success'] = 'Password changed successfully.';
        header('Location: /settings');
        exit;
    }

    public static function settingsSave(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();

        $db     = Database::get();
        $userId = Auth::userId();

        // Theme preference (also updates session)
        $theme = in_array($_POST['theme_preference'] ?? '', ['light','dark','system'], true)
            ? $_POST['theme_preference'] : 'system';
        $db->prepare('UPDATE users SET theme_preference = ? WHERE id = ?')->execute([$theme, $userId]);
        $_SESSION['theme'] = $theme;

        // Units
        $athlete = Auth::getAthlete();
        if ($athlete) {
            $units = in_array($_POST['units'] ?? '', ['miles','km'], true) ? $_POST['units'] : 'miles';
            $db->prepare('UPDATE athlete_profiles SET units = ? WHERE athlete_id = ?')
               ->execute([$units, (int)$athlete['id']]);
        }

        $_SESSION['flash_success'] = 'Settings saved.';
        header('Location: /settings');
        exit;
    }

    // ── Helpers ────────────────────────────────────────────────

    private static function getActivePlan(int $athleteId, PDO $db): ?array
    {
        $stmt = $db->prepare(
            'SELECT * FROM training_plans WHERE athlete_id = ? AND status = "active" ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$athleteId]);
        return $stmt->fetch() ?: null;
    }

    private static function getVisibleWorkouts(int $athleteId, string $start, string $end, PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT pw.*, wl.name as template_name
             FROM planned_workouts pw
             LEFT JOIN workout_library wl ON wl.id = pw.workout_template_id
             WHERE pw.athlete_id = ?
               AND pw.scheduled_date BETWEEN ? AND ?
               AND pw.visible_to_athlete = 1
             ORDER BY pw.scheduled_date ASC'
        );
        $stmt->execute([$athleteId, $start, $end]);
        return $stmt->fetchAll();
    }
}

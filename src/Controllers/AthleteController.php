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

        // Today's workout and this week's schedule (visible window) — dates in the athlete's tz
        $tz         = $athlete['timezone'] ?? Timezone::DEFAULT_TZ;
        $today      = Timezone::dateInZone($tz, 'now');
        $windowEnd  = Timezone::dateInZone($tz, '+' . (int)($_ENV['ATHLETE_WINDOW_DAYS'] ?? ATHLETE_WINDOW_DAYS) . ' days');
        $weekEnd    = Timezone::dateInZone($tz, '+6 days');
        $workouts   = self::getVisibleWorkouts((int)$athlete['id'], $today, $windowEnd, $db);

        $todayWorkout   = null;
        $weekWorkouts   = [];
        foreach ($workouts as $w) {
            if ($w['scheduled_date'] === $today) {
                $todayWorkout = $w;
            }
            if ($w['scheduled_date'] >= $today && $w['scheduled_date'] <= $weekEnd) {
                $weekWorkouts[] = $w;
            }
        }

        // Training load snapshot
        $load = $db->prepare(
            'SELECT atl, ctl, tsb FROM training_load WHERE athlete_id = ? ORDER BY date DESC LIMIT 1'
        );
        $load->execute([(int)$athlete['id']]);
        $loadData = $load->fetch();

        $unreadMessages = self::getUnreadCount((int)$athlete['id'], $db);

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
        $tz      = $athlete['timezone'] ?? Timezone::DEFAULT_TZ;
        $today   = Timezone::dateInZone($tz, 'now');
        $endDate = Timezone::dateInZone($tz, '+' . (int)ATHLETE_WINDOW_DAYS . ' days');
        $workouts = self::getVisibleWorkouts((int)$athlete['id'], $today, $endDate, $db);

        $unreadMessages = self::getUnreadCount((int)$athlete['id'], $db);

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

        $unreadMessages = self::getUnreadCount((int)$athlete['id'], $db);

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
        $actDate     = $_POST['activity_date'] ?? Timezone::dateInZone($athlete['timezone'] ?? Timezone::DEFAULT_TZ, 'now');
        $complStatus = in_array($_POST['completion_status'] ?? '', ['full','partial','no'], true)
            ? $_POST['completion_status'] : 'full';

        // Find matching planned workout for today
        $planned = $db->prepare(
            'SELECT id FROM planned_workouts WHERE athlete_id = ? AND scheduled_date = ? LIMIT 1'
        );
        $planned->execute([$athleteId, $actDate]);
        $plannedRow     = $planned->fetch();
        $plannedId      = $plannedRow ? $plannedRow['id'] : null;

        // Compliance score: actual vs target duration (capped at 1.0); 0.75 for unplanned
        $compliance = null;
        if ($plannedId) {
            $tgt = $db->prepare('SELECT target_duration FROM planned_workouts WHERE id = ? LIMIT 1');
            $tgt->execute([$plannedId]);
            $targetRow = $tgt->fetch();
            if ($targetRow && $targetRow['target_duration'] > 0) {
                $compliance = min(1.0, round($duration / $targetRow['target_duration'], 3));
            }
        }
        if ($compliance === null) {
            $compliance = 0.75;
        }

        $stmt = $db->prepare(
            'INSERT INTO completed_workouts
             (athlete_id, planned_workout_id, source, activity_date, workout_type,
              actual_duration, completion_status, rpe, effort_descriptor, compliance_score, synced_at)
             VALUES (?, ?, "manual", ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$athleteId, $plannedId, $actDate, $type, $duration, $complStatus, $rpe, $effortDesc, $compliance]);
        $cwId = (int)$db->lastInsertId();

        // Save notes to session_notes + messages thread
        if ($notes !== '' && $cwId) {
            $db->prepare(
                'INSERT INTO session_notes (completed_workout_id, athlete_id, author_id, author_role, body)
                 VALUES (?, ?, ?, "athlete", ?)'
            )->execute([$cwId, $athleteId, Auth::userId(), $notes]);

            $db->prepare(
                'INSERT INTO messages (athlete_id, sender_id, sender_role, body, message_type, completed_workout_id)
                 VALUES (?, ?, "athlete", ?, "session_note", ?)'
            )->execute([$athleteId, Auth::userId(), $notes, $cwId]);
        }

        // Recompute training load
        try {
            TrainingLoad::recompute($athleteId);
        } catch (Throwable $e) {
            error_log('TrainingLoad::recompute failed for athlete ' . $athleteId . ': ' . $e->getMessage());
        }

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

        $unreadMessages = self::getUnreadCount((int)$athlete['id'], $db);

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

        $db = Database::get();
        $unreadMessages = $athlete ? self::getUnreadCount((int)$athlete['id'], $db) : 0;

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

        // Timezone (users column; athlete sets their own). Invalid values fall back silently.
        if (array_key_exists('timezone', $_POST)) {
            $tz = Timezone::isValid($_POST['timezone']) ? $_POST['timezone'] : Timezone::DEFAULT_TZ;
            $db->prepare('UPDATE users SET timezone = ? WHERE id = ?')->execute([$tz, $userId]);
            $_SESSION['timezone'] = $tz;
            Timezone::clearCache($userId);
        }

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

    public static function trainingSettings(): void
    {
        Auth::requireRole('athlete');
        require_once __DIR__ . '/../../views/layout/base.php';

        $athlete = Auth::getAthlete();
        if (!$athlete || !$athlete['onboarding_completed_at']) {
            header('Location: /app/onboarding');
            exit;
        }
        $profile = Auth::getAthleteProfile((int)$athlete['id']) ?? [];

        $success = $_SESSION['flash_success'] ?? null;
        $error   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $db = Database::get();
        $unreadMessages = self::getUnreadCount((int)$athlete['id'], $db);

        $isCoach   = false;
        $formAction = '/app/settings/training';
        $cancelUrl  = '/app/settings';

        $pageTitle = 'Training Settings';
        $activeTab = 'settings';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/training_settings.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function trainingSettingsSave(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();

        $athlete = Auth::getAthlete();
        if (!$athlete) {
            header('Location: /app/settings');
            exit;
        }

        $db        = Database::get();
        $athleteId = (int)$athlete['id'];
        $old       = Auth::getAthleteProfile($athleteId) ?? [];
        $new       = ProfileForm::sanitize($_POST, false);

        ProfileForm::save($athleteId, $old, $new, [
            'actor_role'   => 'athlete',
            'athlete_name' => $athlete['name'],
        ], $db);

        $_SESSION['flash_success'] = 'Training profile saved.';
        header('Location: /app/settings/training');
        exit;
    }

    public static function messages(): void
    {
        Auth::requireRole('athlete');
        require_once __DIR__ . '/../../views/layout/base.php';

        $athlete = Auth::getAthlete();
        if (!$athlete || !$athlete['onboarding_completed_at']) {
            header('Location: /app/onboarding');
            exit;
        }

        $db        = Database::get();
        $athleteId = (int)$athlete['id'];

        // Mark all coach messages as read
        $db->prepare(
            'UPDATE messages SET read_at = NOW() WHERE athlete_id = ? AND sender_role = "coach" AND read_at IS NULL'
        )->execute([$athleteId]);

        // Fetch thread (oldest first)
        $stmt = $db->prepare(
            'SELECT m.*, cw.workout_type AS session_type, cw.activity_date AS session_date,
                    u.name AS sender_name
             FROM messages m
             LEFT JOIN completed_workouts cw ON cw.id = m.completed_workout_id
             LEFT JOIN users u ON u.id = m.sender_id
             WHERE m.athlete_id = ?
             ORDER BY m.sent_at ASC
             LIMIT 200'
        );
        $stmt->execute([$athleteId]);
        $messages = $stmt->fetchAll();

        // Coach name
        $coachName = 'Your coach';
        if (!empty($athlete['coach_id'])) {
            $cs = $db->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
            $cs->execute([(int)$athlete['coach_id']]);
            $coachName = $cs->fetchColumn() ?: 'Your coach';
        }

        $unreadMessages = 0; // already marked read above

        $pageTitle = 'Messages';
        $activeTab = 'messages';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/messages.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function messagesSend(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();

        $athlete = Auth::getAthlete();
        if (!$athlete) {
            header('Location: /app/messages');
            exit;
        }

        $body = trim($_POST['body'] ?? '');
        if (!$body || mb_strlen($body) > 2000) {
            header('Location: /app/messages');
            exit;
        }

        $db = Database::get();
        $db->prepare(
            'INSERT INTO messages (athlete_id, sender_id, sender_role, body, message_type)
             VALUES (?, ?, "athlete", ?, "message")'
        )->execute([(int)$athlete['id'], Auth::userId(), $body]);

        header('Location: /app/messages');
        exit;
    }

    public static function sessionNoteSave(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();

        $athlete = Auth::getAthlete();
        if (!$athlete) {
            header('Location: /app/log');
            exit;
        }

        $db        = Database::get();
        $athleteId = (int)$athlete['id'];
        $userId    = Auth::userId();
        $cwId      = (int)($_POST['completed_workout_id'] ?? 0);
        $body      = trim($_POST['body'] ?? '');

        if (!$cwId || !$body || mb_strlen($body) > 1000) {
            header('Location: /app/log');
            exit;
        }

        // Verify workout belongs to this athlete
        $check = $db->prepare('SELECT id FROM completed_workouts WHERE id = ? AND athlete_id = ? LIMIT 1');
        $check->execute([$cwId, $athleteId]);
        if (!$check->fetch()) {
            header('Location: /app/log');
            exit;
        }

        // Save session note
        $db->prepare(
            'INSERT INTO session_notes (completed_workout_id, athlete_id, author_id, author_role, body)
             VALUES (?, ?, ?, "athlete", ?)'
        )->execute([$cwId, $athleteId, $userId, $body]);

        // Auto-post to messages thread as session card
        $db->prepare(
            'INSERT INTO messages (athlete_id, sender_id, sender_role, body, message_type, completed_workout_id)
             VALUES (?, ?, "athlete", ?, "session_note", ?)'
        )->execute([$athleteId, $userId, $body, $cwId]);

        header('Location: /app/log');
        exit;
    }

    // ── Helpers ────────────────────────────────────────────────

    private static function getUnreadCount(int $athleteId, PDO $db): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM messages WHERE athlete_id = ? AND sender_role = "coach" AND read_at IS NULL'
        );
        $stmt->execute([$athleteId]);
        return (int)$stmt->fetchColumn();
    }

    private static function getActivePlan(int $athleteId, PDO $db): ?array
    {
        $stmt = $db->prepare(
            'SELECT * FROM training_plans WHERE athlete_id = ? AND status = "active" ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$athleteId]);
        $plan = $stmt->fetch() ?: null;
        if ($plan && isset($plan['plan_type'])) {
            $plan['plan_type'] = str_replace('_', ' ', $plan['plan_type']);
        }
        return $plan;
    }

    private static function getVisibleWorkouts(int $athleteId, string $start, string $end, PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT pw.*,
                    COALESCE(pw.athlete_instructions, pw.description) AS description
             FROM planned_workouts pw
             WHERE pw.athlete_id = ?
               AND pw.scheduled_date BETWEEN ? AND ?
               AND pw.visible_to_athlete = 1
               AND pw.plan_id = (
                   SELECT id FROM training_plans
                   WHERE athlete_id = ? AND status = "active"
                   ORDER BY id DESC LIMIT 1
               )
             ORDER BY pw.scheduled_date ASC'
        );
        $stmt->execute([$athleteId, $start, $end, $athleteId]);
        return $stmt->fetchAll();
    }
}

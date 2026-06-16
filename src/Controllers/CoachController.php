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

        // Message summary for sidebar
        $lastMsgStmt = $db->prepare(
            'SELECT body, sent_at, sender_role FROM messages WHERE athlete_id = ? ORDER BY sent_at DESC LIMIT 1'
        );
        $lastMsgStmt->execute([$athleteId]);
        $lastMessage = $lastMsgStmt->fetch() ?: null;

        $unreadAthleteMessages = 0;
        $unreadMsgStmt = $db->prepare(
            'SELECT COUNT(*) FROM messages WHERE athlete_id = ? AND sender_role = "athlete" AND read_at IS NULL'
        );
        $unreadMsgStmt->execute([$athleteId]);
        $unreadAthleteMessages = (int)$unreadMsgStmt->fetchColumn();

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $pageTitle = h($athlete['name']);
        $activeNav = 'athletes';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/athlete_view.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function editProfile(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db        = Database::get();
        $coachId   = Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) {
            http_response_code(404);
            include __DIR__ . '/../../views/errors/404.php';
            return;
        }

        $profile = Auth::getAthleteProfile($athleteId) ?? [];

        $success = $_SESSION['flash_success'] ?? null;
        $error   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $isCoach    = true;
        $formAction = '/app/coach/athlete/' . $athleteId . '/edit';
        $cancelUrl  = '/app/coach/athlete/' . $athleteId;

        $pageTitle = h($athlete['name']) . ' — Edit Profile';
        $activeNav = 'athletes';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/edit_profile.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function editProfileSave(array $params): void
    {
        Auth::requireRole(['coach','admin']);
        Auth::verifyCsrf();

        $db        = Database::get();
        $coachId   = Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) {
            header('Location: /app/coach/athletes');
            exit;
        }

        $old = Auth::getAthleteProfile($athleteId) ?? [];
        $new = ProfileForm::sanitize($_POST, true);

        ProfileForm::save($athleteId, $old, $new, [
            'actor_role'   => 'coach',
            'athlete_name' => $athlete['name'],
        ], $db);

        // Coach override of the athlete's timezone (users column on the athlete's account).
        if (array_key_exists('timezone', $_POST)) {
            $tz = Timezone::isValid($_POST['timezone']) ? $_POST['timezone'] : Timezone::DEFAULT_TZ;
            $db->prepare('UPDATE users SET timezone = ? WHERE id = ?')->execute([$tz, (int)$athlete['user_id']]);
            Timezone::clearCache((int)$athlete['user_id']);
        }

        $_SESSION['flash_success'] = 'Training profile updated.';
        header('Location: /app/coach/athlete/' . $athleteId . '/edit');
        exit;
    }

    public static function generatePlan(array $params): void
    {
        Auth::requireRole(['coach','admin']);
        Auth::verifyCsrf();

        $coachId   = Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);
        $db        = Database::get();

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) {
            header('Location: /app/coach/athletes');
            exit;
        }

        try {
            $planId = PlanGenerator::generate($athleteId, 'coach_manual');
            if ($planId) {
                $_SESSION['flash_success'] = 'New plan generated and added to the approval queue.';
            } else {
                $_SESSION['flash_error'] = 'Plan generation returned no result. Check athlete profile data.';
            }
        } catch (Throwable $e) {
            error_log('PlanGenerator::generate failed (coach_manual) for athlete ' . $athleteId . ': ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Plan generation failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }

        header('Location: /app/coach/athlete/' . $athleteId);
        exit;
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

        // Fetch workouts for each pending plan, keyed by plan_id
        $planWorkouts = [];
        foreach ($pendingPlans as $plan) {
            $planWorkouts[(int)$plan['plan_id']] = self::getPlanWorkouts((int)$plan['plan_id'], $db);
        }

        $pageTitle = 'Plan Approvals';
        $activeNav = 'approvals';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/approvals.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function editPlannedWorkout(array $params): void
    {
        Auth::requireRole(['coach', 'admin']);
        Auth::verifyCsrf();

        $workoutId = (int)($params['id'] ?? 0);
        $coachId   = Auth::userId();
        $db        = Database::get();

        // Verify workout belongs to one of this coach's athletes
        $check = $db->prepare(
            'SELECT pw.id FROM planned_workouts pw
             JOIN athletes a ON a.id = pw.athlete_id AND a.coach_id = ?
             WHERE pw.id = ? LIMIT 1'
        );
        $check->execute([$coachId, $workoutId]);
        if (!$check->fetch()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not found or not authorized']);
            exit;
        }

        $validTypes   = ['easy','long','interval','hill','fartlek','tempo','race','race_pace','speed','plyometric','recovery','cross_train'];
        $type         = in_array($_POST['workout_type'] ?? '', $validTypes, true) ? $_POST['workout_type'] : null;
        $duration     = isset($_POST['target_duration']) && (int)$_POST['target_duration'] > 0
            ? (int)$_POST['target_duration'] : null;
        $instructions = array_key_exists('athlete_instructions', $_POST)
            ? (trim($_POST['athlete_instructions']) ?: null) : false;

        $sets = [];
        $vals = [];
        if ($type !== null)        { $sets[] = 'workout_type = ?';        $vals[] = $type; }
        if ($duration !== null)    { $sets[] = 'target_duration = ?';     $vals[] = $duration; }
        if ($instructions !== false) { $sets[] = 'athlete_instructions = ?'; $vals[] = $instructions; }

        if (!empty($sets)) {
            $sets[] = 'coach_locked = 1';
            $sets[] = 'coach_edited_by = ?';
            $sets[] = 'coach_edited_at = NOW()';
            $vals[] = $coachId;
            $vals[] = $workoutId;
            $db->prepare('UPDATE planned_workouts SET ' . implode(', ', $sets) . ' WHERE id = ?')
               ->execute($vals);
        }

        $stmt = $db->prepare(
            'SELECT id, workout_type, target_duration, scheduled_date,
                    display_title, display_summary, athlete_instructions,
                    display_title                                           AS template_name,
                    COALESCE(athlete_instructions, description, display_summary, \'\') AS description,
                    coach_locked
             FROM planned_workouts
             WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$workoutId]);
        $updated = $stmt->fetch();

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'workout' => $updated]);
        exit;
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

            // Open initial 10-day visibility window immediately on approval
            $horizon = date('Y-m-d', strtotime('+10 days'));
            $db->prepare(
                'UPDATE planned_workouts SET visible_to_athlete = 1
                 WHERE plan_id = ? AND scheduled_date BETWEEN CURDATE() AND ?
                   AND visible_to_athlete = 0'
            )->execute([$planId, $horizon]);

            // Notify the athlete their plan is ready (always-on: push + email).
            $ctx = Notifications::athleteContext((int)$queue['athlete_id']);
            if ($ctx['athlete_user_id']) {
                Notifications::send($ctx['athlete_user_id'], 'plan_approved', []);
            }
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

    public static function notifications(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db               = Database::get();
        $coachId          = Auth::userId();
        Notifications::ensureUserDefaults($coachId, Auth::role());

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);
        [$prefs, $quiet]  = AthleteController::loadNotifPrefs($coachId, $db);

        $notifAudience = 'coach';
        $notifAction   = '/app/coach/settings/notifications';

        $pageTitle = 'Notifications';
        $activeNav = 'settings';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/notifications.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function notificationsSave(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $ok = Notifications::applyPrefChange(
            Auth::userId(),
            (string)($in['type'] ?? ''),
            (string)($in['field'] ?? ''),
            $in['value'] ?? null
        );
        echo json_encode(['ok' => $ok]);
        exit;
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

        // Coach's own display timezone.
        if (array_key_exists('timezone', $_POST)) {
            $tz = Timezone::isValid($_POST['timezone']) ? $_POST['timezone'] : Timezone::DEFAULT_TZ;
            $db->prepare('UPDATE users SET timezone = ? WHERE id = ?')->execute([$tz, $coachId]);
            $_SESSION['timezone'] = $tz;
            Timezone::clearCache($coachId);
        }

        $_SESSION['flash_success'] = 'Settings saved.';
        header('Location: /app/coach/settings');
        exit;
    }

    public static function coachMessages(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db        = Database::get();
        $coachId   = Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) {
            http_response_code(404);
            include __DIR__ . '/../../views/errors/404.php';
            return;
        }

        // Mark athlete messages as read
        $db->prepare(
            'UPDATE messages SET read_at = NOW() WHERE athlete_id = ? AND sender_role = "athlete" AND read_at IS NULL'
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

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $pageTitle = h($athlete['name']) . ' — Messages';
        $activeNav = 'athletes';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/messages.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function coachMessagesSend(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId   = Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);
        $db        = Database::get();

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) {
            header('Location: /app/coach/athletes');
            exit;
        }

        $body = trim($_POST['body'] ?? '');
        if (!$body || mb_strlen($body) > 2000) {
            header('Location: /app/coach/athlete/' . $athleteId . '/messages');
            exit;
        }

        $db->prepare(
            'INSERT INTO messages (athlete_id, sender_id, sender_role, body, message_type)
             VALUES (?, ?, "coach", ?, "message")'
        )->execute([$athleteId, $coachId, $body]);

        // Notify the athlete (always-on; email fallback if no push device).
        $ctx = Notifications::athleteContext($athleteId);
        if ($ctx['athlete_user_id']) {
            Notifications::send($ctx['athlete_user_id'], 'message_from_coach', [
                'sender_name'    => Auth::name() ?: 'Your coach',
                'message'        => $body,
                'email_fallback' => true,
            ]);
        }

        header('Location: /app/coach/athlete/' . $athleteId . '/messages');
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
                (SELECT AVG(cw.compliance_score) FROM completed_workouts cw WHERE cw.athlete_id=a.id AND cw.compliance_score IS NOT NULL AND cw.activity_date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)) as avg_compliance,
                (SELECT COUNT(*) FROM messages m WHERE m.athlete_id=a.id AND m.sender_role="athlete" AND m.read_at IS NULL) as unread_messages
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
            'SELECT a.*, u.name, u.email, u.theme_preference, u.timezone
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
            'SELECT * FROM training_plans
             WHERE athlete_id = ? AND status IN ("active", "pending_approval")
             ORDER BY FIELD(status, "active", "pending_approval"), id DESC
             LIMIT 1'
        );
        $stmt->execute([$athleteId]);
        return $stmt->fetch() ?: null;
    }

    private static function getPlanWorkouts(int $planId, PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT pw.id, pw.plan_id, pw.athlete_id, pw.scheduled_date, pw.workout_type,
                    pw.archetype_code, pw.display_title, pw.display_summary, pw.athlete_instructions,
                    pw.display_title                                          AS template_name,
                    COALESCE(pw.athlete_instructions, pw.description, pw.display_summary, \'\') AS description,
                    pw.structure, pw.target_duration, pw.intensity_load,
                    pw.coach_locked, pw.visible_to_athlete,
                    (
                        SELECT cw.compliance_score
                        FROM completed_workouts cw
                        WHERE cw.planned_workout_id = pw.id
                           OR (
                               cw.planned_workout_id IS NULL
                               AND cw.athlete_id = pw.athlete_id
                               AND cw.activity_date = pw.scheduled_date
                           )
                        ORDER BY
                            CASE WHEN cw.planned_workout_id = pw.id THEN 0 ELSE 1 END,
                            cw.synced_at DESC
                        LIMIT 1
                    ) AS compliance_score
             FROM planned_workouts pw
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

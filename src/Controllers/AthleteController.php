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

        // Last 30 days of completed running workouts — dashboard "Your Stats".
        $runCutoff = date('Y-m-d', strtotime($today . ' -30 days'));
        $statsStmt = $db->prepare(
            "SELECT COUNT(DISTINCT activity_date)     AS days_run,
                    COALESCE(SUM(actual_duration), 0) AS total_minutes,
                    SUM(actual_distance)              AS total_distance,
                    COUNT(*)                          AS run_count
             FROM completed_workouts
             WHERE athlete_id = ?
               AND activity_date BETWEEN ? AND ?
               AND workout_type IN ('easy_run','long_run','recovery','easy','long','interval',
                                    'tempo','hill','fartlek','speed','race_pace','workout')"
        );
        $statsStmt->execute([(int)$athlete['id'], $runCutoff, $today]);
        $runStats = $statsStmt->fetch() ?: [];

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
        // Fetch the full two-week swap window in one query: the plan list renders the
        // first ATHLETE_WINDOW_DAYS, while the day-swap picker offers all 14 days
        // (current + next week). Extra dates are simply ignored by the render loop.
        $endDate = Timezone::dateInZone($tz, '+' . (self::SWAP_WINDOW_DAYS - 1) . ' days');
        $workouts = self::getVisibleWorkouts((int)$athlete['id'], $today, $endDate, $db);

        $mustOffDays    = self::athleteMustOffDays((int)$athlete['id'], $db);
        $swapWindowDays = self::SWAP_WINDOW_DAYS;
        $unreadMessages = self::getUnreadCount((int)$athlete['id'], $db);

        $pageTitle = 'My Plan';
        $activeTab = 'plan';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/plan.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /** Athlete day-swap window: today through today + 9 days (the 10-day visible window). */
    private const SWAP_WINDOW_DAYS = 10;

    /**
     * POST /app/athlete/workout/swap — athlete moves a workout to another day within
     * the two-week window. Body (JSON): { workout_id, target_date 'YYYY-MM-DD', force }.
     *
     * If the target day already holds a workout the two dates are swapped atomically;
     * otherwise the workout's date is moved. Moving onto a must-off day requires
     * force=true (the UI shows a soft warning first). After the DB change, affected
     * workouts are re-pushed to Intervals.icu (upsert by srf_{id} moves the event) and
     * the coach is notified. Returns JSON {success: bool, ...}.
     */
    public static function swapWorkout(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $athlete = Auth::getAthlete();
        if (!$athlete) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }
        $athleteId = (int)$athlete['id'];
        $db        = Database::get();

        $in         = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $workoutId  = (int)($in['workout_id'] ?? 0);
        $targetDate = trim((string)($in['target_date'] ?? ''));
        $force      = !empty($in['force']) && $in['force'] !== 'false';

        // Swap window in the athlete's timezone: today .. today + 9 days (10-day window).
        $tz        = $athlete['timezone'] ?? Timezone::DEFAULT_TZ;
        $today     = Timezone::dateInZone($tz, 'now');
        $windowEnd = Timezone::dateInZone($tz, '+' . (self::SWAP_WINDOW_DAYS - 1) . ' days');

        if (!self::isValidDate($targetDate) || $targetDate < $today || $targetDate > $windowEnd) {
            echo json_encode(['success' => false, 'error' => 'invalid_date',
                'message' => 'You can only move workouts within the next 10 days.']);
            exit;
        }

        // Source workout must belong to this athlete's active plan, be visible, and
        // not be cancelled. Coach-locked workouts can't be moved by the athlete.
        $stmt = $db->prepare(
            'SELECT pw.* FROM planned_workouts pw
             WHERE pw.id = ? AND pw.athlete_id = ?
               AND pw.visible_to_athlete = 1
               AND (pw.cancelled = 0 OR pw.cancelled IS NULL)
               AND pw.plan_id = (
                   SELECT id FROM training_plans
                   WHERE athlete_id = ? AND status = "active"
                   ORDER BY id DESC LIMIT 1
               )
             LIMIT 1'
        );
        $stmt->execute([$workoutId, $athleteId, $athleteId]);
        $workout = $stmt->fetch();
        if (!$workout) {
            echo json_encode(['success' => false, 'error' => 'not_found', 'message' => 'Workout not found.']);
            exit;
        }
        if (!empty($workout['coach_locked'])) {
            echo json_encode(['success' => false, 'error' => 'locked',
                'message' => "This workout is locked by your coach and can't be moved."]);
            exit;
        }

        $planId  = (int)$workout['plan_id'];
        $oldDate = (string)$workout['scheduled_date'];

        // The source workout's current day must also sit inside the 10-day window.
        if ($oldDate < $today || $oldDate > $windowEnd) {
            echo json_encode(['success' => false, 'error' => 'out_of_window',
                'message' => 'This workout is outside the 10-day window.']);
            exit;
        }

        // No-op when dropped back on its own day.
        if ($targetDate === $oldDate) {
            echo json_encode(['success' => true]);
            exit;
        }

        // Must-off guard — soft, overridable with force (the UI confirms first).
        $mustOff       = self::athleteMustOffDays($athleteId, $db);
        $targetIsMustOff = in_array((int)date('w', strtotime($targetDate)), $mustOff, true);
        if ($targetIsMustOff && !$force) {
            echo json_encode(['success' => false, 'error' => 'must_off',
                'message' => 'This is a must-off day in your schedule.']);
            exit;
        }

        // A visible, non-cancelled workout on the target day → swap the two dates.
        $other = $db->prepare(
            'SELECT id FROM planned_workouts
             WHERE athlete_id = ? AND plan_id = ? AND scheduled_date = ? AND id <> ?
               AND visible_to_athlete = 1
               AND (cancelled = 0 OR cancelled IS NULL)
             ORDER BY id ASC LIMIT 1'
        );
        $other->execute([$athleteId, $planId, $targetDate, $workoutId]);
        $otherId = (int)($other->fetchColumn() ?: 0);

        $now      = gmdate('Y-m-d H:i:s');
        $affected = [$workoutId];

        if ($otherId > 0) {
            $db->beginTransaction();
            self::applyAthleteMove($db, $workoutId, $targetDate, $oldDate, $targetIsMustOff, $now);
            // The displaced workout takes the source day; flag its own must-off state.
            $otherToMustOff = in_array((int)date('w', strtotime($oldDate)), $mustOff, true);
            self::applyAthleteMove($db, $otherId, $oldDate, $targetDate, $otherToMustOff, $now);
            $db->commit();
            $affected[] = $otherId;
        } else {
            self::applyAthleteMove($db, $workoutId, $targetDate, $oldDate, $targetIsMustOff, $now);
        }

        // Re-push affected workouts (pushWorkout self-guards if not connected / is rest).
        foreach ($affected as $id) {
            IntervalsService::pushWorkout($athleteId, (int)$id, $db);
        }

        // Notify the coach (controllable, default off).
        if (!empty($athlete['coach_id'])) {
            Notifications::send((int)$athlete['coach_id'], 'athlete_day_swap', [
                'athlete_id'   => $athleteId,
                'athlete_name' => $athlete['name'] ?? 'Your athlete',
                'workout_name' => ucfirst(str_replace('_', ' ', (string)$workout['workout_type'])),
                'from_date'    => $oldDate,
                'to_date'      => $targetDate,
            ]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    /**
     * Apply one leg of an athlete move: set the new date, stamp the move audit fields,
     * and preserve the earliest original date so the plan's "moved from" badge is stable.
     */
    private static function applyAthleteMove(PDO $db, int $workoutId, string $newDate, string $fromDate, bool $mustOffOverride, string $now): void
    {
        $db->prepare(
            'UPDATE planned_workouts
             SET scheduled_date          = ?,
                 original_scheduled_date = COALESCE(original_scheduled_date, ?),
                 athlete_moved           = 1,
                 athlete_moved_at        = ?,
                 must_off_override       = ?
             WHERE id = ?'
        )->execute([$newDate, $fromDate, $now, $mustOffOverride ? 1 : 0, $workoutId]);
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

        // Athletes in an active return-to-running plan get the 5-option modified RPE
        // prompt (adds "I felt some discomfort" — architecture §24).
        $rtrCheck = $db->prepare(
            "SELECT 1 FROM training_plans
             WHERE athlete_id = ? AND status = 'active' AND plan_type = 'return_to_running' LIMIT 1"
        );
        $rtrCheck->execute([(int)$athlete['id']]);
        $rtrActive = (bool)$rtrCheck->fetchColumn();

        $unreadMessages = self::getUnreadCount((int)$athlete['id'], $db);

        $pageTitle = 'Training Log';
        $activeTab = 'log';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/log.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /**
     * GET /app/log/:id — session detail: planned vs. actual, the note entry/edit UI,
     * and the full session thread (athlete note + coach replies). Owner-scoped.
     */
    public static function session(array $params): void
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
        $cwId      = (int)($params['id'] ?? 0);

        // Completed workout + its planned counterpart (ownership enforced).
        $stmt = $db->prepare(
            'SELECT cw.*, pw.display_title, pw.display_summary, pw.workout_type AS planned_type,
                    pw.target_duration AS planned_duration, pw.target_distance AS planned_distance,
                    pw.target_pace_min, pw.target_pace_max,
                    COALESCE(pw.athlete_instructions, pw.description) AS planned_desc
             FROM completed_workouts cw
             LEFT JOIN planned_workouts pw ON pw.id = cw.planned_workout_id
             WHERE cw.id = ? AND cw.athlete_id = ? LIMIT 1'
        );
        $stmt->execute([$cwId, $athleteId]);
        $session = $stmt->fetch();
        if (!$session) {
            header('Location: /app/log');
            exit;
        }

        // Session thread: athlete note first, coach replies after.
        $notesStmt = $db->prepare(
            'SELECT sn.*, u.name AS author_name
             FROM session_notes sn
             LEFT JOIN users u ON u.id = sn.author_id
             WHERE sn.completed_workout_id = ?
             ORDER BY sn.created_at ASC, sn.id ASC'
        );
        $notesStmt->execute([$cwId]);
        $notes = $notesStmt->fetchAll();

        $coachName = 'Your coach';
        if (!empty($athlete['coach_id'])) {
            $cs = $db->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
            $cs->execute([(int)$athlete['coach_id']]);
            $coachName = $cs->fetchColumn() ?: 'Your coach';
        }

        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_success']);

        $unreadMessages = self::getUnreadCount($athleteId, $db);
        $pageTitle = 'Session';
        $activeTab = 'log';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/session.php';
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
        $effortDesc  = in_array($_POST['effort_descriptor'] ?? '', ['easy','moderate','hard','very_hard','discomfort'], true)
            ? $_POST['effort_descriptor'] : 'moderate';
        $rpe         = $rpeMap[$effortDesc] ?? 4;
        // Return-to-running "I felt some discomfort" (modified RPE prompt, architecture §24).
        $rpeDiscomfort = $effortDesc === 'discomfort' ? 1 : 0;
        $notes       = substr(trim($_POST['notes'] ?? ''), 0, 1000);
        $actDate     = $_POST['activity_date'] ?? Timezone::dateInZone($athlete['timezone'] ?? Timezone::DEFAULT_TZ, 'now');
        $complStatus = in_array($_POST['completion_status'] ?? '', ['full','partial','no'], true)
            ? $_POST['completion_status'] : 'full';

        // Find matching planned workout for today
        $planned = $db->prepare(
            'SELECT id FROM planned_workouts
             WHERE athlete_id = ? AND scheduled_date = ?
               AND (cancelled = 0 OR cancelled IS NULL) LIMIT 1'
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
              actual_duration, completion_status, rpe, rpe_discomfort, effort_descriptor, compliance_score, synced_at)
             VALUES (?, ?, "manual", ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$athleteId, $plannedId, $actDate, $type, $duration, $complStatus, $rpe, $rpeDiscomfort, $effortDesc, $compliance]);
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

        // Return-to-running adaptive stage progression (engine spec §18.10 / §19 item 6).
        // No-op unless the matched planned workout is a run/walk session in an active
        // return_to_running plan; the engine reads $effortDesc (incl. "discomfort").
        if ($plannedId) {
            try {
                PlanGenerator::onRunWalkCompletion($athleteId, (int)$plannedId, $effortDesc, $db);
            } catch (Throwable $e) {
                error_log('RTR progression failed for athlete ' . $athleteId . ': ' . $e->getMessage());
            }
        }

        // Notify the coach of a manual log (controllable, default off).
        if (!empty($athlete['coach_id'])) {
            Notifications::send((int)$athlete['coach_id'], 'athlete_manual_log', [
                'athlete_id'   => $athleteId,
                'athlete_name' => $athlete['name'] ?? 'Your athlete',
                'workout_name' => trim($type . ' · ' . $duration . ' min'),
            ]);
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

        // Connected-devices "notify me when available" opt-ins (brand => true).
        $deviceNotify = self::loadDeviceNotifyPrefs(Auth::userId(), $db);

        // Intervals.icu connection state (Phase-1 watch integration).
        $intervalsConn       = IntervalsService::connectionForUser((int)Auth::userId(), $db);
        $intervalsConnected  = $intervalsConn !== null;
        $intervalsLastSynced = ($intervalsConn && !empty($intervalsConn['last_synced_at']))
            ? Timezone::format($intervalsConn['last_synced_at'], 'M j, Y · g:i A', (int)Auth::userId())
            : null;

        $pageTitle = 'Settings';
        $activeTab = 'settings';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/settings.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function notifications(): void
    {
        Auth::requireRole('athlete');
        require_once __DIR__ . '/../../views/layout/base.php';

        $athlete = Auth::getAthlete();
        $userId  = Auth::userId();
        Notifications::ensureUserDefaults($userId, 'athlete');

        $db   = Database::get();
        [$prefs, $quiet] = self::loadNotifPrefs($userId, $db);
        $unreadMessages  = $athlete ? self::getUnreadCount((int)$athlete['id'], $db) : 0;

        $notifAudience = 'athlete';
        $notifAction   = '/app/settings/notifications';

        $pageTitle = 'Notifications';
        $activeTab = 'settings';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/notifications.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function notificationsSave(): void
    {
        Auth::requireRole('athlete');
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

    /** Wearable brands an athlete can ask to be notified about ('all' = one opt-in covering every brand). */
    private const DEVICE_BRANDS = ['garmin', 'coros', 'polar', 'suunto', 'all'];

    /** brand => true for each brand the user has opted into notifications for. */
    public static function loadDeviceNotifyPrefs(int $userId, PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT brand FROM device_notify_preferences WHERE user_id = ? AND notify = 1'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $brand) {
            $out[$brand] = true;
        }
        return $out;
    }

    /**
     * POST /app/settings/devices/notify — toggle a device-availability opt-in.
     * Body: { brand, enabled }. Enabling upserts the row; disabling removes it.
     * Returns JSON {success: bool}.
     */
    public static function saveDeviceNotifyPreference(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $in    = json_decode(file_get_contents('php://input'), true) ?: [];
        $brand = strtolower(trim((string)($in['brand'] ?? '')));
        $enabled = !empty($in['enabled']) && $in['enabled'] !== 'false';

        if (!in_array($brand, self::DEVICE_BRANDS, true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'invalid_brand']);
            exit;
        }

        $db  = Database::get();
        $now = gmdate('Y-m-d H:i:s');
        if ($enabled) {
            $db->prepare(
                'INSERT INTO device_notify_preferences (user_id, brand, notify, updated_at)
                 VALUES (?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE notify = 1, updated_at = VALUES(updated_at)'
            )->execute([Auth::userId(), $brand, $now]);
        } else {
            $db->prepare('DELETE FROM device_notify_preferences WHERE user_id = ? AND brand = ?')
               ->execute([Auth::userId(), $brand]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    /** Load a user's preference rows into [type => row] plus the quiet-hours window. */
    public static function loadNotifPrefs(int $userId, PDO $db): array
    {
        $stmt = $db->prepare('SELECT * FROM notification_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        $prefs = [];
        $quiet = ['start' => '22:00:00', 'end' => '07:00:00'];
        foreach ($stmt->fetchAll() as $r) {
            $prefs[$r['notification_type']] = $r;
            $quiet = ['start' => $r['quiet_hours_start'], 'end' => $r['quiet_hours_end']];
        }
        return [$prefs, $quiet];
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
                    pw.display_title AS session_title, u.name AS sender_name
             FROM messages m
             LEFT JOIN completed_workouts cw ON cw.id = m.completed_workout_id
             LEFT JOIN planned_workouts pw ON pw.id = cw.planned_workout_id
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
        $isAjax = self::wantsJson();

        $athlete = Auth::getAthlete();
        $body    = trim($_POST['body'] ?? '');
        if (!$athlete || !$body || mb_strlen($body) > 2000) {
            self::redirectOrJson($isAjax, '/app/messages', ['ok' => false]);
        }

        $db = Database::get();
        $db->prepare(
            'INSERT INTO messages (athlete_id, sender_id, sender_role, body, message_type)
             VALUES (?, ?, "athlete", ?, "message")'
        )->execute([(int)$athlete['id'], Auth::userId(), $body]);
        $msgId = (int)$db->lastInsertId();

        // Notify the coach (always-on; email fallback if no push device).
        if (!empty($athlete['coach_id'])) {
            Notifications::send((int)$athlete['coach_id'], 'message_from_athlete', [
                'athlete_id'     => (int)$athlete['id'],
                'sender_name'    => $athlete['name'] ?? 'Your athlete',
                'message'        => $body,
                'email_fallback' => true,
            ]);
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'message' => self::fetchMessageJson($db, $msgId, (int)Auth::userId())]);
            exit;
        }
        header('Location: /app/messages');
        exit;
    }

    /**
     * Lightweight poll: JSON array of messages newer than ?after=<id> for the
     * logged-in athlete's thread. Mirrors the auth/scope of messages().
     */
    public static function messagesPoll(): void
    {
        Auth::requireRole('athlete');
        header('Content-Type: application/json');

        $athlete = Auth::getAthlete();
        if (!$athlete) { echo json_encode([]); exit; }

        $db        = Database::get();
        $athleteId = (int)$athlete['id'];
        $after     = (int)($_GET['after'] ?? 0);

        $stmt = $db->prepare(
            'SELECT m.*, cw.workout_type AS session_type, cw.activity_date AS session_date,
                    pw.display_title AS session_title
             FROM messages m
             LEFT JOIN completed_workouts cw ON cw.id = m.completed_workout_id
             LEFT JOIN planned_workouts pw ON pw.id = cw.planned_workout_id
             WHERE m.athlete_id = ? AND m.id > ?
             ORDER BY m.sent_at ASC, m.id ASC
             LIMIT 100'
        );
        $stmt->execute([$athleteId, $after]);
        $rows = $stmt->fetchAll();

        // The athlete is actively viewing — mark newly-arrived coach messages read.
        $db->prepare(
            'UPDATE messages SET read_at = NOW()
             WHERE athlete_id = ? AND id > ? AND sender_role = "coach" AND read_at IS NULL'
        )->execute([$athleteId, $after]);

        echo json_encode(self::serializeMessages($rows, (int)Auth::userId()));
        exit;
    }

    // ── Messaging JSON helpers ─────────────────────────────────

    /** True when the request expects a JSON response (fetch-based send). */
    private static function wantsJson(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    }

    private static function redirectOrJson(bool $isAjax, string $location, array $payload): void
    {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($payload);
        } else {
            header('Location: ' . $location);
        }
        exit;
    }

    private static function fetchMessageJson(PDO $db, int $id, int $viewerId): ?array
    {
        $stmt = $db->prepare(
            'SELECT m.*, cw.workout_type AS session_type, cw.activity_date AS session_date,
                    pw.display_title AS session_title
             FROM messages m
             LEFT JOIN completed_workouts cw ON cw.id = m.completed_workout_id
             LEFT JOIN planned_workouts pw ON pw.id = cw.planned_workout_id
             WHERE m.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        return self::serializeMessages([$row], $viewerId)[0] ?? null;
    }

    /** @param array<int,array> $rows */
    private static function serializeMessages(array $rows, int $viewerId): array
    {
        $out = [];
        foreach ($rows as $m) {
            $dt    = Timezone::toLocal($m['sent_at']);
            $cwId  = !empty($m['completed_workout_id']) ? (int)$m['completed_workout_id'] : null;
            $out[] = [
                'id'                   => (int)$m['id'],
                'mine'                 => ((int)$m['sender_id'] === $viewerId),
                'body'                 => $m['body'],
                'type'                 => $m['message_type'],
                'ts'                   => $dt->getTimestamp(),
                'time_label'           => $dt->format('M j · g:ia'),
                'session_type'         => !empty($m['session_type'])
                    ? ucfirst(str_replace('_', ' ', $m['session_type'])) : null,
                'session_date_label'   => !empty($m['session_date'])
                    ? date('M j', strtotime($m['session_date'])) : null,
                // Session-card fields (Section 13): workout name + link target.
                'workout_name'         => $cwId
                    ? self::sessionDisplayName($m['session_title'] ?? null, $m['session_type'] ?? null) : null,
                'completed_workout_id' => $cwId,
            ];
        }
        return $out;
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
        $isAjax    = self::wantsJson();
        $cwId      = (int)($_POST['completed_workout_id'] ?? 0);
        $body      = trim($_POST['body'] ?? '');

        if (!$cwId || $body === '' || mb_strlen($body) > 1000) {
            self::redirectOrJson($isAjax, '/app/log', ['ok' => false]);
        }

        // Verify workout belongs to this athlete
        $check = $db->prepare('SELECT id FROM completed_workouts WHERE id = ? AND athlete_id = ? LIMIT 1');
        $check->execute([$cwId, $athleteId]);
        if (!$check->fetch()) {
            self::redirectOrJson($isAjax, '/app/log', ['ok' => false]);
        }

        // Save session note
        $db->prepare(
            'INSERT INTO session_notes (completed_workout_id, athlete_id, author_id, author_role, body)
             VALUES (?, ?, ?, "athlete", ?)'
        )->execute([$cwId, $athleteId, $userId, $body]);
        $noteId = (int)$db->lastInsertId();

        // Auto-post to messages thread as session card
        $db->prepare(
            'INSERT INTO messages (athlete_id, sender_id, sender_role, body, message_type, completed_workout_id)
             VALUES (?, ?, "athlete", ?, "session_note", ?)'
        )->execute([$athleteId, $userId, $body, $cwId]);

        // Notify the coach a session note was added (controllable, default on).
        $notified = false;
        if (!empty($athlete['coach_id'])) {
            $w = $db->prepare('SELECT workout_type, activity_date FROM completed_workouts WHERE id = ? LIMIT 1');
            $w->execute([$cwId]);
            $cw = $w->fetch() ?: [];
            $label = $cw ? trim(($cw['workout_type'] ?? '') . ' session on ' . ($cw['activity_date'] ?? '')) : 'a session';
            Notifications::send((int)$athlete['coach_id'], 'athlete_session_note', [
                'athlete_id'   => $athleteId,
                'athlete_name' => $athlete['name'] ?? 'Your athlete',
                'workout_name' => $label,
            ]);
            $notified = true;
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'notified' => $notified, 'note' => [
                'id'         => $noteId,
                'mine'       => true,
                'author'     => 'You',
                'role'       => 'athlete',
                'body'       => $body,
                'time_label' => 'Just now',
            ]]);
            exit;
        }

        $_SESSION['flash_success'] = $notified
            ? 'Note saved — your coach has been notified.'
            : 'Note saved.';
        header('Location: /app/log/' . $cwId);
        exit;
    }

    /**
     * POST /app/log/note/edit — athlete edits one of their own session notes.
     * Body: { note_id, body }. Updates the canonical session_notes row (shown on the
     * session detail thread) and best-effort syncs the originating thread card when the
     * edited note is the athlete's first note for that workout. JSON-aware.
     */
    public static function sessionNoteEdit(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();
        $isAjax = self::wantsJson();

        $athlete = Auth::getAthlete();
        if (!$athlete) {
            self::redirectOrJson($isAjax, '/app/log', ['ok' => false]);
        }

        $db        = Database::get();
        $athleteId = (int)$athlete['id'];
        $userId    = Auth::userId();
        $noteId    = (int)($_POST['note_id'] ?? 0);
        $body      = trim($_POST['body'] ?? '');

        if (!$noteId || $body === '' || mb_strlen($body) > 1000) {
            self::redirectOrJson($isAjax, '/app/log', ['ok' => false]);
        }

        // Verify the note is this athlete's own.
        $stmt = $db->prepare(
            'SELECT completed_workout_id FROM session_notes
             WHERE id = ? AND athlete_id = ? AND author_id = ? AND author_role = "athlete" LIMIT 1'
        );
        $stmt->execute([$noteId, $athleteId, $userId]);
        $cwId = (int)($stmt->fetchColumn() ?: 0);
        if (!$cwId) {
            self::redirectOrJson($isAjax, '/app/log', ['ok' => false]);
        }

        $db->prepare('UPDATE session_notes SET body = ? WHERE id = ?')->execute([$body, $noteId]);

        // Keep the posted thread card in sync only when this is the athlete's first
        // note for the workout (the oldest card). Replies are separate and untouched.
        $firstNote = $db->prepare(
            'SELECT id FROM session_notes
             WHERE completed_workout_id = ? AND author_id = ? AND author_role = "athlete"
             ORDER BY id ASC LIMIT 1'
        );
        $firstNote->execute([$cwId, $userId]);
        if ((int)($firstNote->fetchColumn() ?: 0) === $noteId) {
            $db->prepare(
                'UPDATE messages SET body = ?
                 WHERE completed_workout_id = ? AND sender_id = ? AND message_type = "session_note"
                 ORDER BY id ASC LIMIT 1'
            )->execute([$body, $cwId, $userId]);
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'note' => ['id' => $noteId, 'body' => $body]]);
            exit;
        }

        $_SESSION['flash_success'] = 'Note updated.';
        header('Location: /app/log/' . $cwId);
        exit;
    }

    /** Human display name for a session card: planned display_title, else the type, else a generic. */
    private static function sessionDisplayName($title, $type): string
    {
        $t = trim((string)$title);
        if ($t !== '') return $t;
        $ty = trim((string)$type);
        return $ty !== '' ? ucfirst(str_replace('_', ' ', $ty)) : 'Session note';
    }

    // ── Billing (Milestone 8) ──────────────────────────────────

    public static function billing(): void
    {
        Auth::requireRole('athlete');
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $athlete = Auth::getAthlete();
        $userId  = Auth::userId();

        $billing        = Billing::athleteBillingView($userId, $db);
        $unreadMessages = $athlete ? self::getUnreadCount((int)$athlete['id'], $db) : 0;
        $success        = $_SESSION['flash_success'] ?? null;
        $error          = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $pageTitle = 'Billing';
        $activeTab = 'settings';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_athlete.php';
        include __DIR__ . '/../../views/athlete/billing.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /** GET /app/billing/portal — open the Stripe Billing Portal. */
    public static function billingPortal(): void
    {
        Auth::requireRole('athlete');
        $db  = Database::get();
        $url = Billing::createBillingPortalSession(Auth::userId(), $db);
        if (!$url) {
            $_SESSION['flash_error'] = 'Billing management is temporarily unavailable. Please try again later.';
            header('Location: /app/billing');
            exit;
        }
        header('Location: ' . $url);
        exit;
    }

    /** GET /app/billing/success — return from Stripe Checkout. */
    public static function billingSuccess(): void
    {
        Auth::requireRole('athlete');
        $db        = Database::get();
        $sessionId = $_GET['session_id'] ?? '';
        Billing::syncFromCheckoutSession($sessionId, Auth::userId(), $db);
        $_SESSION['flash_success'] = "You're all set — your subscription is active.";
        header('Location: /app/billing');
        exit;
    }

    /** GET /app/billing/cancel — athlete abandoned Stripe Checkout. */
    public static function billingCheckoutCancelled(): void
    {
        Auth::requireRole('athlete');
        $_SESSION['flash_error'] = 'Checkout was cancelled. You can subscribe any time from this page.';
        header('Location: /app/billing');
        exit;
    }

    /** POST /app/billing/cancel — cancel the subscription at period end. */
    public static function billingCancel(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();
        $db = Database::get();
        if (Billing::cancelAtPeriodEnd(Auth::userId(), $db)) {
            $_SESSION['flash_success'] = 'Your subscription will not renew. You keep access until the end of the current period.';
        } else {
            $_SESSION['flash_error'] = "We couldn't cancel your subscription. Please use Manage billing or contact support.";
        }
        header('Location: /app/billing');
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

    /** True when $date is a real YYYY-MM-DD calendar date. */
    private static function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /** The athlete's must-off days as ints (0=Sun … 6=Sat). */
    private static function athleteMustOffDays(int $athleteId, PDO $db): array
    {
        $profile = Auth::getAthleteProfile($athleteId) ?? [];
        $raw     = $profile['must_off_days'] ?? '[]';
        $arr     = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
        return array_map('intval', $arr);
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
               AND (pw.cancelled = 0 OR pw.cancelled IS NULL)
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

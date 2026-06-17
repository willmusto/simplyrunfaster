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
        // Cancelled (soft-deleted) workout dates render a coach-only "Removed" marker;
        // the archetype catalogue powers the "+ Add workout" picker.
        $cancelledDates   = $activePlan ? self::getCancelledWorkoutDates((int)$activePlan['id'], $db) : [];
        $archetypeLibrary = $activePlan ? PlanGenerator::manualArchetypeLibrary($athleteId, $db) : [];
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

    // ── Macro-plan management: reschedule / add / remove ───────────────────────

    /**
     * Persist a drag-to-reschedule from the coach macro plan. Body (JSON):
     *   { workout_id, new_date:'YYYY-MM-DD', force?:bool, swap_with?:int }
     * Coach's arrangement is authoritative — the engine is not re-run.
     */
    public static function rescheduleWorkout(array $params): void
    {
        Auth::requireRole(['coach', 'admin']);
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $coachId   = Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);
        $db        = Database::get();

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'forbidden']); exit; }

        $in        = self::jsonBody();
        $workoutId = (int)($in['workout_id'] ?? 0);
        $newDate   = trim((string)($in['new_date'] ?? ''));
        $force     = !empty($in['force']);
        $swapWith  = isset($in['swap_with']) ? (int)$in['swap_with'] : 0;

        // Workout being moved must belong to this athlete and not be cancelled.
        $stmt = $db->prepare(
            'SELECT pw.id, pw.scheduled_date, pw.plan_id, tp.plan_start_date, tp.plan_end_date
             FROM planned_workouts pw
             JOIN training_plans tp ON tp.id = pw.plan_id
             WHERE pw.id = ? AND pw.athlete_id = ? AND (pw.cancelled = 0 OR pw.cancelled IS NULL)
             LIMIT 1'
        );
        $stmt->execute([$workoutId, $athleteId]);
        $workout = $stmt->fetch();
        if (!$workout) { echo json_encode(['success' => false, 'error' => 'not_found', 'message' => 'Workout not found.']); exit; }

        $planId    = (int)$workout['plan_id'];
        $oldDate   = (string)$workout['scheduled_date'];
        $planStart = (string)$workout['plan_start_date'];
        $planEnd   = (string)$workout['plan_end_date'];

        if (!self::isValidDate($newDate) || $newDate < $planStart || $newDate > $planEnd) {
            echo json_encode(['success' => false, 'error' => 'invalid_date', 'message' => 'That date is outside the plan range.']); exit;
        }

        // Swap path: atomically exchange dates with the workout on the destination day.
        if ($swapWith > 0) {
            $other = $db->prepare(
                'SELECT id, scheduled_date FROM planned_workouts
                 WHERE id = ? AND athlete_id = ? AND plan_id = ? AND (cancelled = 0 OR cancelled IS NULL) LIMIT 1'
            );
            $other->execute([$swapWith, $athleteId, $planId]);
            $otherRow = $other->fetch();
            if (!$otherRow) { echo json_encode(['success' => false, 'error' => 'not_found', 'message' => 'The other workout no longer exists.']); exit; }

            $db->beginTransaction();
            $upd = $db->prepare('UPDATE planned_workouts SET scheduled_date = ? WHERE id = ?');
            $upd->execute([$newDate, $workoutId]);
            $upd->execute([$oldDate, (int)$otherRow['id']]);
            $db->commit();

            // Re-push both swapped workouts (upsert by stable srf_{id} moves the event date).
            IntervalsService::pushWorkout($athleteId, $workoutId, $db);
            IntervalsService::pushWorkout($athleteId, (int)$otherRow['id'], $db);

            echo json_encode(['success' => true, 'swapped' => true]); exit;
        }

        // Must-off warning — non-blocking unless the coach confirms with force.
        $mustOff = self::athleteMustOffDays($athleteId, $db);
        $dow     = (int)date('w', strtotime($newDate));
        if (in_array($dow, $mustOff, true) && !$force) {
            echo json_encode(['success' => false, 'error' => 'must_off',
                'message' => 'That day is marked as a must-off day for this athlete.']); exit;
        }

        // Conflict — destination day already holds a workout in this plan.
        if ($newDate !== $oldDate) {
            $conflict = $db->prepare(
                'SELECT id, display_title, workout_type FROM planned_workouts
                 WHERE athlete_id = ? AND plan_id = ? AND scheduled_date = ? AND id <> ?
                   AND (cancelled = 0 OR cancelled IS NULL) LIMIT 1'
            );
            $conflict->execute([$athleteId, $planId, $newDate, $workoutId]);
            $existing = $conflict->fetch();
            if ($existing) {
                echo json_encode(['success' => false, 'error' => 'conflict',
                    'message' => 'That day already has a workout scheduled.',
                    'existing_workout' => [
                        'id'            => (int)$existing['id'],
                        'display_title' => $existing['display_title'] !== null && $existing['display_title'] !== ''
                            ? (string)$existing['display_title'] : null,
                        'workout_type'  => (string)$existing['workout_type'],
                    ]]); exit;
            }
        }

        $db->prepare('UPDATE planned_workouts SET scheduled_date = ? WHERE id = ?')->execute([$newDate, $workoutId]);

        // Re-push the moved workout (upsert by stable srf_{id} moves the event date).
        IntervalsService::pushWorkout($athleteId, $workoutId, $db);

        echo json_encode(['success' => true]); exit;
    }

    /**
     * Add a workout to the macro plan — archetype picker or free-form entry.
     * Body (JSON): { type:'archetype'|'freeform', scheduled_date, preview?:bool, … }
     * With preview:true the rendered display is returned without inserting.
     */
    public static function addWorkout(array $params): void
    {
        Auth::requireRole(['coach', 'admin']);
        Auth::verifyCsrf();
        require_once __DIR__ . '/../../views/layout/base.php'; // pill_class / pill_label / format_duration
        header('Content-Type: application/json');

        $coachId   = Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);
        $db        = Database::get();

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'forbidden']); exit; }

        $in      = self::jsonBody();
        $type    = ($in['type'] ?? '') === 'freeform' ? 'freeform' : 'archetype';
        $date    = trim((string)($in['scheduled_date'] ?? ''));
        $preview = !empty($in['preview']);

        $plan = self::getActivePlanDetail($athleteId, $db);
        if (!$plan) { echo json_encode(['success' => false, 'error' => 'no_plan', 'message' => 'This athlete has no active plan.']); exit; }
        $planId    = (int)$plan['id'];
        $planStart = (string)$plan['plan_start_date'];
        $planEnd   = (string)$plan['plan_end_date'];

        if (!self::isValidDate($date) || $date < $planStart || $date > $planEnd) {
            echo json_encode(['success' => false, 'error' => 'invalid_date', 'message' => 'That date is outside the plan range.']); exit;
        }

        // The day must be empty for a real insert (the UI only offers the button on
        // rest/empty days). Previews are allowed regardless so the modal can render.
        if (!$preview) {
            $occupied = $db->prepare(
                'SELECT id FROM planned_workouts
                 WHERE athlete_id = ? AND plan_id = ? AND scheduled_date = ?
                   AND (cancelled = 0 OR cancelled IS NULL) LIMIT 1'
            );
            $occupied->execute([$athleteId, $planId, $date]);
            if ($occupied->fetch()) {
                echo json_encode(['success' => false, 'error' => 'conflict', 'message' => 'That day already has a workout scheduled.']); exit;
            }
        }

        if ($type === 'archetype') {
            $code     = trim((string)($in['archetype_code'] ?? ''));
            $variant  = isset($in['archetype_variant']) && $in['archetype_variant'] !== '' ? (string)$in['archetype_variant'] : null;
            $duration = (int)($in['duration'] ?? 0);
            $composed = PlanGenerator::composeManualWorkout($athleteId, $code, $variant, $duration, $db);
            if (!$composed) { echo json_encode(['success' => false, 'error' => 'archetype_failed', 'message' => 'Could not build that workout.']); exit; }

            if ($preview) {
                echo json_encode(['success' => true, 'preview' => self::previewPayload(
                    $composed['display_title'], $composed['display_summary'],
                    $composed['athlete_instructions'], $composed['workout_type'], (int)$composed['target_duration']
                )]); exit;
            }

            $insert = $db->prepare(
                'INSERT INTO planned_workouts
                  (plan_id, athlete_id, scheduled_date, workout_type,
                   archetype_code, archetype_variant, archetype_params,
                   workout_archetype_id, archetype_version_snapshot, instance_signature,
                   structure, display_title, display_summary, athlete_instructions,
                   description, target_duration, intensity_load,
                   coach_locked, coach_edited_by, coach_edited_at, visible_to_athlete)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), 1)'
            );
            $insert->execute([
                $planId, $athleteId, $date, $composed['workout_type'],
                $composed['archetype_code'], $composed['archetype_variant'], $composed['archetype_params'],
                $composed['workout_archetype_id'], $composed['archetype_version_snapshot'], $composed['instance_signature'],
                $composed['structure'], $composed['display_title'], $composed['display_summary'], $composed['athlete_instructions'],
                $composed['athlete_instructions'], $composed['target_duration'], $composed['intensity_load'],
                $coachId,
            ]);
            $id = (int)$db->lastInsertId();

            // Push the new workout to Intervals.icu (no-op if the athlete isn't connected).
            IntervalsService::pushWorkout($athleteId, $id, $db);

            echo json_encode(['success' => true, 'workout' => self::workoutDomPayload(
                $id, $composed['workout_type'], $composed['display_title'], (int)$composed['target_duration'],
                $composed['display_summary'], $composed['athlete_instructions'], $date
            )]); exit;
        }

        // ── Free-form entry ──
        $title    = trim((string)($in['title'] ?? ''));
        $validTypes = ['easy', 'long', 'tempo', 'interval', 'hill', 'fartlek', 'race_pace', 'recovery', 'rest', 'cross_train', 'speed', 'plyometric'];
        $wt       = in_array($in['workout_type'] ?? '', $validTypes, true) ? (string)$in['workout_type'] : 'easy';
        $duration = (int)($in['duration'] ?? 0);
        $instructions = trim((string)($in['instructions'] ?? ''));
        $coachNotes   = trim((string)($in['coach_notes'] ?? ''));

        if ($title === '' || $duration < 1) {
            echo json_encode(['success' => false, 'error' => 'invalid', 'message' => 'Title and a duration of at least 1 minute are required.']); exit;
        }

        $loadFactor = [
            'easy' => 0.5, 'long' => 0.6, 'tempo' => 0.85, 'interval' => 0.9, 'hill' => 0.85, 'fartlek' => 0.8,
            'race_pace' => 0.9, 'recovery' => 0.3, 'rest' => 0.0, 'cross_train' => 0.4, 'speed' => 0.95, 'plyometric' => 0.7,
        ];
        $load = round($duration * ($loadFactor[$wt] ?? 0.5), 2);

        if ($preview) {
            echo json_encode(['success' => true, 'preview' => self::previewPayload(
                $title, null, $instructions ?: null, $wt, $duration
            )]); exit;
        }

        $insert = $db->prepare(
            'INSERT INTO planned_workouts
              (plan_id, athlete_id, scheduled_date, workout_type,
               display_title, athlete_instructions, description, notes,
               target_duration, intensity_load,
               coach_locked, coach_edited_by, coach_edited_at, visible_to_athlete)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), 1)'
        );
        $insert->execute([
            $planId, $athleteId, $date, $wt,
            $title, $instructions ?: null, $instructions ?: null, $coachNotes ?: null,
            $duration, $load, $coachId,
        ]);
        $id = (int)$db->lastInsertId();

        // Push the new workout to Intervals.icu (no-op if the athlete isn't connected).
        IntervalsService::pushWorkout($athleteId, $id, $db);

        echo json_encode(['success' => true, 'workout' => self::workoutDomPayload(
            $id, $wt, $title, $duration, null, $instructions ?: null, $date
        )]); exit;
    }

    /**
     * Soft-delete a workout (coach removes it from the macro plan). The row is kept
     * (cancelled = 1) so the training log is preserved; the day renders as rest.
     */
    public static function removeWorkout(array $params): void
    {
        Auth::requireRole(['coach', 'admin']);
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $coachId   = Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);
        $db        = Database::get();

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'forbidden']); exit; }

        $in        = self::jsonBody();
        $workoutId = (int)($in['workout_id'] ?? 0);

        $stmt = $db->prepare(
            'SELECT id, scheduled_date FROM planned_workouts
             WHERE id = ? AND athlete_id = ? AND (cancelled = 0 OR cancelled IS NULL) LIMIT 1'
        );
        $stmt->execute([$workoutId, $athleteId]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['success' => false, 'error' => 'not_found', 'message' => 'Workout not found.']); exit; }

        $db->prepare('UPDATE planned_workouts SET cancelled = 1, cancelled_at = NOW(), cancelled_by = ? WHERE id = ?')
           ->execute([$coachId, $workoutId]);

        // Remove the event from Intervals.icu (no-op if the athlete isn't connected).
        IntervalsService::deleteWorkout($athleteId, $workoutId, $db);

        echo json_encode(['success' => true, 'date' => (string)$row['scheduled_date']]); exit;
    }

    // ── Macro-plan helpers ─────────────────────────────────────────────────────

    /** Decode the JSON request body into an array (empty array when absent/invalid). */
    private static function jsonBody(): array
    {
        $data = json_decode((string)file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
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

    /** Distinct dates with a cancelled workout (coach-only "Removed" marker). */
    private static function getCancelledWorkoutDates(int $planId, PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT DISTINCT scheduled_date FROM planned_workouts WHERE plan_id = ? AND cancelled = 1'
        );
        $stmt->execute([$planId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** Rendered display for the add-workout modal's Step-3 preview. */
    private static function previewPayload(?string $title, ?string $summary, ?string $instructions, string $wt, int $duration): array
    {
        return [
            'display_title'        => ($title !== null && $title !== '') ? $title : pill_label($wt),
            'display_summary'      => $summary,
            'athlete_instructions' => $instructions,
            'workout_type'         => $wt,
            'type_label'           => pill_label($wt),
            'type_class'           => pill_class($wt),
            'target_duration'      => $duration,
            'duration_label'       => $duration > 0 ? format_duration($duration) : '',
        ];
    }

    /** Everything the client needs to render a newly-added workout into the calendar. */
    private static function workoutDomPayload(int $id, string $wt, ?string $title, int $duration, ?string $summary, ?string $description, string $date): array
    {
        $label = ($title !== null && $title !== '') ? $title : pill_label($wt);
        return [
            'id'              => $id,
            'workout_type'    => $wt,
            'type_label'      => pill_label($wt),
            'type_class'      => pill_class($wt),
            'title'           => $label,
            'display_title'   => $title,
            'target_duration' => $duration,
            'duration_label'  => $duration > 0 ? format_duration($duration) : '',
            'summary'         => $summary,
            'description'     => $description,
            'date'            => $date,
            'coach_locked'    => 1,
        ];
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

            // Push the newly-visible window to Intervals.icu (no-op if not connected).
            IntervalsService::pushNewlyVisible((int)$queue['athlete_id'], $planId, $db);

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

            // Drop the rejected plan's Intervals.icu events (no-op if not connected).
            IntervalsService::deleteEventsForPlan($planId, $db);
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

    // ── Invite links (Milestone 8: billing options) ───────────

    public static function invites(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db               = Database::get();
        $coachId          = Auth::userId();
        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $success = $_SESSION['flash_success'] ?? null;
        $error   = $_SESSION['flash_error']   ?? null;
        $newLink = $_SESSION['flash_invite_url'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['flash_invite_url']);

        $stmt = $db->prepare(
            'SELECT * FROM invite_links WHERE created_by = ? ORDER BY created_at DESC LIMIT 25'
        );
        $stmt->execute([$coachId]);
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stripeReady = Billing::isConfigured();

        $pageTitle = 'Invite athletes';
        $activeNav = 'athletes';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/invites.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function createInvite(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $db      = Database::get();
        $coachId = Auth::userId();

        $discount  = (int)($_POST['discount_percent'] ?? 0);
        if (!in_array($discount, [0, 25, 50, 100], true)) $discount = 0;

        $duration  = $_POST['discount_duration'] ?? '';
        $validDurations = ['30d','60d','90d','120d','365d','forever'];
        if ($discount === 0 || !in_array($duration, $validDurations, true)) {
            $duration = null;
        }

        $interval = in_array($_POST['billing_interval'] ?? '', ['monthly','annual'], true)
            ? $_POST['billing_interval'] : 'monthly';

        $expiryDays = (int)($_POST['expiry_days'] ?? INVITE_DEFAULT_EXPIRY_DAYS);
        if ($expiryDays < 1 || $expiryDays > 90) $expiryDays = INVITE_DEFAULT_EXPIRY_DAYS;
        $maxUses = (int)($_POST['max_uses'] ?? INVITE_DEFAULT_MAX_USES);
        if ($maxUses < 1 || $maxUses > 100) $maxUses = INVITE_DEFAULT_MAX_USES;

        $notes = trim((string)($_POST['notes'] ?? '')) ?: null;

        // A 100%-forever invite is a pure comp (no Stripe checkout, no coupon).
        $isComp   = ($discount === 100 && $duration === 'forever');
        $couponId = null;
        if ($discount > 0 && !$isComp) {
            $couponId = Billing::createCoupon($discount, (string)$duration);
            if ($couponId === null && Billing::isConfigured()) {
                $_SESSION['flash_error'] = "Couldn't create the Stripe coupon. Link not generated.";
                header('Location: /app/coach/invites');
                exit;
            }
        }

        $code      = bin2hex(random_bytes(8));
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+' . $expiryDays . ' days'));

        $db->prepare(
            'INSERT INTO invite_links
                (code, created_by, assigned_coach_id, discount_percent, discount_duration,
                 stripe_coupon_id, billing_interval, expires_at, max_uses, use_count, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        )->execute([
            $code, $coachId, $coachId,
            $discount ?: null, $duration, $couponId, $interval,
            $expiresAt, $maxUses, $notes,
        ]);

        $_SESSION['flash_success']    = 'Invite link created.';
        $_SESSION['flash_invite_url'] = rtrim(APP_URL, '/') . '/invite/' . $code;
        header('Location: /app/coach/invites');
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
        $planPhase        = self::currentPlanPhase(self::getActivePlanDetail($athleteId, $db));

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
        $isAjax = self::wantsJson();

        $coachId   = Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);
        $db        = Database::get();
        $back      = '/app/coach/athlete/' . $athleteId . '/messages';

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        $body    = trim($_POST['body'] ?? '');
        if (!$athlete || !$body || mb_strlen($body) > 2000) {
            self::redirectOrJson($isAjax, $athlete ? $back : '/app/coach/athletes', ['ok' => false]);
        }

        $db->prepare(
            'INSERT INTO messages (athlete_id, sender_id, sender_role, body, message_type)
             VALUES (?, ?, "coach", ?, "message")'
        )->execute([$athleteId, $coachId, $body]);
        $msgId = (int)$db->lastInsertId();

        // Notify the athlete (always-on; email fallback if no push device).
        $ctx = Notifications::athleteContext($athleteId);
        if ($ctx['athlete_user_id']) {
            Notifications::send($ctx['athlete_user_id'], 'message_from_coach', [
                'sender_name'    => Auth::name() ?: 'Your coach',
                'message'        => $body,
                'email_fallback' => true,
            ]);
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'message' => self::fetchMessageJson($db, $msgId, (int)$coachId)]);
            exit;
        }
        header('Location: ' . $back);
        exit;
    }

    /**
     * Coach (or assistant coach) comments on an athlete's completed workout
     * session. The coach-side mirror of AthleteController::sessionNoteSave:
     * stores a session_notes row, posts a session_note_reply card into the
     * thread, and notifies the athlete (coach_session_comment).
     */
    public static function coachSessionNoteSave(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId   = Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);
        $db        = Database::get();
        $back      = '/app/coach/athlete/' . $athleteId . '/messages';

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        $cwId    = (int)($_POST['completed_workout_id'] ?? 0);
        $body    = trim($_POST['body'] ?? '');
        if (!$athlete || !$cwId || !$body || mb_strlen($body) > 1000) {
            header('Location: ' . ($athlete ? $back : '/app/coach/athletes'));
            exit;
        }

        // Verify the completed workout belongs to this athlete.
        $check = $db->prepare(
            'SELECT workout_type, activity_date FROM completed_workouts WHERE id = ? AND athlete_id = ? LIMIT 1'
        );
        $check->execute([$cwId, $athleteId]);
        $cw = $check->fetch();
        if (!$cw) {
            header('Location: ' . $back);
            exit;
        }

        $role = Auth::role() === 'assistant_coach' ? 'assistant_coach' : 'coach';

        // Save session note + post to thread as a session reply card.
        $db->prepare(
            'INSERT INTO session_notes (completed_workout_id, athlete_id, author_id, author_role, body)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$cwId, $athleteId, $coachId, $role, $body]);

        $db->prepare(
            'INSERT INTO messages (athlete_id, sender_id, sender_role, body, message_type, completed_workout_id)
             VALUES (?, ?, ?, ?, "session_note_reply", ?)'
        )->execute([$athleteId, $coachId, $role, $body, $cwId]);

        // Notify the athlete a coach comment was added (controllable, default on).
        $ctx = Notifications::athleteContext($athleteId);
        if ($ctx['athlete_user_id']) {
            $label = trim(($cw['workout_type'] ?? '') . ' session on ' . ($cw['activity_date'] ?? '')) ?: 'your workout';
            Notifications::send($ctx['athlete_user_id'], 'coach_session_comment', [
                'workout_name' => $label,
                'workout_id'   => $cwId,
            ]);
        }

        header('Location: ' . $back);
        exit;
    }

    /**
     * Lightweight poll: JSON array of messages newer than ?after=<id> for an
     * athlete's thread. Same auth/scope as coachMessages().
     */
    public static function coachMessagesPoll(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        header('Content-Type: application/json');

        $db        = Database::get();
        $coachId   = (int)Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) { http_response_code(404); echo json_encode([]); exit; }

        $after = (int)($_GET['after'] ?? 0);

        $stmt = $db->prepare(
            'SELECT m.*, cw.workout_type AS session_type, cw.activity_date AS session_date
             FROM messages m
             LEFT JOIN completed_workouts cw ON cw.id = m.completed_workout_id
             WHERE m.athlete_id = ? AND m.id > ?
             ORDER BY m.sent_at ASC, m.id ASC
             LIMIT 100'
        );
        $stmt->execute([$athleteId, $after]);
        $rows = $stmt->fetchAll();

        // Coach is actively viewing — mark newly-arrived athlete messages read.
        $db->prepare(
            'UPDATE messages SET read_at = NOW()
             WHERE athlete_id = ? AND id > ? AND sender_role = "athlete" AND read_at IS NULL'
        )->execute([$athleteId, $after]);

        echo json_encode(self::serializeMessages($rows, $coachId));
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
            'SELECT m.*, cw.workout_type AS session_type, cw.activity_date AS session_date
             FROM messages m
             LEFT JOIN completed_workouts cw ON cw.id = m.completed_workout_id
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
            $dt = Timezone::toLocal($m['sent_at']);
            $out[] = [
                'id'                 => (int)$m['id'],
                'mine'               => ((int)$m['sender_id'] === $viewerId),
                'body'               => $m['body'],
                'type'               => $m['message_type'],
                'ts'                 => $dt->getTimestamp(),
                'time_label'         => $dt->format('M j · g:ia'),
                'session_type'       => !empty($m['session_type'])
                    ? ucfirst(str_replace('_', ' ', $m['session_type'])) : null,
                'session_date_label' => !empty($m['session_date'])
                    ? date('M j', strtotime($m['session_date'])) : null,
            ];
        }
        return $out;
    }

    /**
     * A lightweight "current plan phase" label for the messages context header.
     * Fixed-label plan types map directly; race_cycle is approximated from how
     * far through the plan window today falls (full block phasing lives in the
     * athlete plan view). Returns null when there's no active plan.
     */
    private static function currentPlanPhase(?array $plan): ?string
    {
        if (!$plan) return null;
        $type  = (string)($plan['plan_type'] ?? '');
        $fixed = [
            'development_plan'   => 'Base',
            'maintenance_plan'   => 'Build',
            'return_to_running'  => 'Return',
            'recovery_block'     => 'Recovery',
        ];
        if (isset($fixed[$type])) return $fixed[$type];
        if ($type !== 'race_cycle') {
            return $type !== '' ? ucfirst(str_replace('_', ' ', $type)) : null;
        }

        $start = strtotime((string)($plan['plan_start_date'] ?? ''));
        $end   = strtotime((string)($plan['plan_end_date'] ?? ''));
        if ($start === false || $end === false || $end <= $start) return 'Race cycle';

        $frac = max(0.0, min(1.0, (time() - $start) / ($end - $start)));
        if ($frac >= 0.80) return 'Taper';
        if ($frac >= 0.60) return 'Peak';
        if ($frac >= 0.30) return 'Build';
        return 'Base';
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
                    ) AS compliance_score,
                    (
                        SELECT cw.id
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
                    ) AS completed_workout_id
             FROM planned_workouts pw
             WHERE pw.plan_id = ?
               AND (pw.cancelled = 0 OR pw.cancelled IS NULL)
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

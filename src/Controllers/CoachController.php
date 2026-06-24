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
        // Phase 3: athlete response profile + open coaching-intelligence (incl. predictive) flags.
        $responseProfile = ResponseProfiler::load($athleteId, $db);
        $predictiveFlags = self::getOpenIntelFlagsForAthlete($athleteId, $db);
        $pbs           = self::getPersonalBests($athleteId, $db);
        $nextRace      = self::getNextRace($athleteId, $db);

        // All races for this athlete, keyed by date, for the macro-plan calendar (§26).
        $racesByDate = [];
        $rStmt = $db->prepare('SELECT * FROM races WHERE athlete_id = ? ORDER BY race_date');
        $rStmt->execute([$athleteId]);
        foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $racesByDate[$r['race_date']][] = $r;
        }

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

        // Assistant-coach context for the quick actions (head coach / admin only).
        $viewerRole       = Auth::role();
        $isHeadOrAdmin    = in_array($viewerRole, ['coach', 'admin'], true);
        $currentAssistant = CoachAssignments::assistantCoachId($athleteId, $db);
        $assistantOptions = [];
        if ($isHeadOrAdmin) {
            $aSql  = 'SELECT id, name FROM users WHERE role = "assistant_coach" AND active = 1';
            $aArgs = [];
            if ($viewerRole !== 'admin') { $aSql .= ' AND managed_by = ?'; $aArgs[] = $coachId; }
            $aSql .= ' ORDER BY name';
            $aStmt = $db->prepare($aSql);
            $aStmt->execute($aArgs);
            $assistantOptions = $aStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Pending plan-regeneration request (assistant coach → head coach).
        $pendingRegen = null;
        if ($isHeadOrAdmin) {
            $rStmt2 = $db->prepare(
                'SELECT prr.id, prr.requested_at, u.name AS requester_name
                 FROM plan_regeneration_requests prr
                 LEFT JOIN users u ON u.id = prr.requested_by
                 WHERE prr.athlete_id = ? AND prr.status = "pending"
                 ORDER BY prr.requested_at DESC LIMIT 1'
            );
            $rStmt2->execute([$athleteId]);
            $pendingRegen = $rStmt2->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $chrome       = self::athleteChromeData($athleteId, $db);
        $chromeActive = 'plan';

        $pageTitle = h($athlete['name']);
        $activeNav = 'athletes';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/athlete_view.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /** Weeks of completed-workout history shown per page on the coach training log. */
    private const LOG_WEEKS_PER_PAGE = 8;

    /**
     * GET /app/coach/athlete/:id/log — read-only coach training log: the athlete's actual
     * completed_workouts, newest first, grouped by Mon–Sun week with per-week rollups, matched
     * (planned-vs-actual + thread link) and unplanned ("off-plan") sessions in one stream.
     * Pure SELECT + render — no writes, no engine calls. Coach-owns-athlete scoped.
     */
    public static function athleteLog(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db        = Database::get();
        $coachId   = (int)Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) {
            http_response_code(404);
            include __DIR__ . '/../../views/errors/404.php';
            return;
        }

        $profile = Auth::getAthleteProfile($athleteId) ?? [];
        $units   = (($profile['units'] ?? 'miles') === 'km') ? 'km' : 'miles';
        $tz      = $athlete['timezone'] ?: 'America/New_York';
        $page    = max(0, (int)($_GET['page'] ?? 0));
        $log     = self::athleteLogData($athleteId, $page, $tz, $db);

        // Only offer the Intervals.icu re-sync card when this athlete actually has a
        // live connection — same lookup the backfill endpoint guards on. Without it
        // the control could only ever return "not connected", so we hide it.
        $hasIntervalsConnection = IntervalsService::connectionForAthlete($athleteId, $db) !== null;

        $chrome       = self::athleteChromeData($athleteId, $db);
        $chromeActive = 'log';

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $pageTitle = 'Training log: ' . h($athlete['name']);
        $activeNav = 'athletes';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/athlete_log.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /**
     * PURE: one page (8 whole Mon–Sun weeks) of completed-workout history for the training log.
     * No writes. Returns ['weeks'=>[...newest first...], 'page', 'has_older', 'has_newer',
     * 'window_start', 'window_end']; each week carries its rollup + rows (each row = the
     * completed_workouts columns + planned_title/planned_duration/planned_type/note_count/matched).
     */
    public static function athleteLogData(int $athleteId, int $page, string $tz, PDO $db): array
    {
        $per  = self::LOG_WEEKS_PER_PAGE;
        $page = max(0, $page);
        $todayLocal = Timezone::dateInZone(Timezone::isValid($tz) ? $tz : 'America/New_York', 'now');

        // Monday of the athlete's current local week, then this page's 8-week window.
        $t    = strtotime($todayLocal);
        $dow  = (int)date('N', $t); // 1=Mon..7=Sun
        $anchorMon = strtotime(date('Y-m-d', $t)) - ($dow - 1) * 86400;
        $newestMon = $anchorMon - ($page * $per * 7 * 86400);
        $oldestMon = $newestMon - (($per - 1) * 7 * 86400);
        $windowStart = date('Y-m-d', $oldestMon);
        $windowEnd   = date('Y-m-d', $newestMon + 6 * 86400);

        $stmt = $db->prepare(
            "SELECT cw.*, pw.display_title AS planned_title, pw.target_duration AS planned_duration,
                    pw.workout_type AS planned_type,
                    (SELECT COUNT(*) FROM session_notes sn
                       WHERE sn.completed_workout_id = cw.id
                          OR (cw.planned_workout_id IS NOT NULL AND sn.planned_workout_id = cw.planned_workout_id)) AS note_count
             FROM completed_workouts cw
             LEFT JOIN planned_workouts pw ON pw.id = cw.planned_workout_id
             WHERE cw.athlete_id = ? AND cw.activity_date BETWEEN ? AND ?
             ORDER BY cw.activity_date DESC, cw.id DESC"
        );
        $stmt->execute([$athleteId, $windowStart, $windowEnd]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Off-plan completions carry their thread inline on the coach Log card (matched
        // sessions link out to the planned-workout thread instead). Batch-load the notes
        // for the off-plan rows in this window, keyed by completed_workout_id.
        $offPlanIds = [];
        foreach ($rows as $r) {
            if ($r['planned_workout_id'] === null) $offPlanIds[] = (int)$r['id'];
        }
        $threads = [];
        if ($offPlanIds) {
            $in = implode(',', array_fill(0, count($offPlanIds), '?'));
            $tn = $db->prepare(
                "SELECT sn.completed_workout_id, sn.body, sn.author_role, sn.created_at, u.name AS author_name
                 FROM session_notes sn
                 LEFT JOIN users u ON u.id = sn.author_id
                 WHERE sn.completed_workout_id IN ($in)
                 ORDER BY sn.created_at ASC, sn.id ASC"
            );
            $tn->execute($offPlanIds);
            foreach ($tn->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $threads[(int)$n['completed_workout_id']][] = $n;
            }
        }

        $weeks = [];
        foreach ($rows as $r) {
            $d    = strtotime((string)$r['activity_date']);
            $rdow = (int)date('N', $d);
            $mon  = date('Y-m-d', $d - ($rdow - 1) * 86400);
            if (!isset($weeks[$mon])) {
                $weeks[$mon] = ['monday' => $mon, 'rows' => [], 'total_minutes' => 0,
                                'total_distance' => 0.0, 'runs' => 0, '_csum' => 0.0, '_ccount' => 0];
            }
            $matched     = $r['planned_workout_id'] !== null;
            $r['matched'] = $matched;
            $r['thread']  = $threads[(int)$r['id']] ?? [];
            $weeks[$mon]['rows'][]          = $r;
            $weeks[$mon]['total_minutes']  += (int)($r['actual_duration'] ?? 0);
            $weeks[$mon]['total_distance'] += (float)($r['actual_distance'] ?? 0);
            $weeks[$mon]['runs']++;
            if ($matched && $r['compliance_score'] !== null) {
                $weeks[$mon]['_csum'] += (float)$r['compliance_score'];
                $weeks[$mon]['_ccount']++;
            }
        }
        krsort($weeks); // newest week first

        $out = [];
        foreach ($weeks as $w) {
            $w['avg_compliance'] = $w['_ccount'] > 0 ? $w['_csum'] / $w['_ccount'] : null;
            $w['label']          = 'Week of ' . date('M j', strtotime($w['monday']));
            unset($w['_csum'], $w['_ccount']);
            $out[] = $w;
        }

        $older = $db->prepare("SELECT 1 FROM completed_workouts WHERE athlete_id = ? AND activity_date < ? LIMIT 1");
        $older->execute([$athleteId, $windowStart]);

        return [
            'weeks'        => $out,
            'page'         => $page,
            'per_page'     => $per,
            'has_older'    => (bool)$older->fetchColumn(),
            'has_newer'    => $page > 0,
            'window_start' => $windowStart,
            'window_end'   => $windowEnd,
        ];
    }

    /**
     * PURE single source of an athlete's OPEN flags across BOTH tables (engine_flags +
     * coaching_intelligence_flags), normalized to the Flags-card shape, newest first. The chrome
     * badge/dot and the Flags-tab Open section both derive from this, so they can't drift. Cheap:
     * two indexed open-status lookups for one athlete. No writes.
     */
    public static function openFlagsForAthlete(int $athleteId, PDO $db): array
    {
        $rows = [];
        try {
            $es = $db->prepare("SELECT * FROM engine_flags WHERE athlete_id = ? AND status = 'open' ORDER BY created_at DESC");
            $es->execute([$athleteId]);
            foreach ($es->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $rows[] = [
                    'source' => 'engine', 'severity' => (string)$f['severity'], 'title' => (string)$f['flag_type'],
                    'message' => (string)($f['message'] ?? ''), 'status' => 'open', 'is_open' => true,
                    'created_at' => (string)$f['created_at'], 'flag_type' => (string)$f['flag_type'],
                    'details' => $f['details'] ?? null, 'resolution' => null,
                ];
            }
        } catch (\Throwable $e) { /* table may be absent pre-migration */ }
        try {
            $is = $db->prepare("SELECT * FROM coaching_intelligence_flags WHERE athlete_id = ? AND status = 'open' ORDER BY created_at DESC");
            $is->execute([$athleteId]);
            foreach ($is->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $rows[] = [
                    'source' => 'intel', 'severity' => (string)$f['severity'], 'title' => (string)($f['title'] ?? $f['flag_type']),
                    'message' => (string)($f['detail'] ?? ''), 'status' => 'open', 'is_open' => true,
                    'created_at' => (string)$f['created_at'], 'flag_type' => (string)$f['flag_type'],
                    'details' => null, 'resolution' => null,
                ];
            }
        } catch (\Throwable $e) { /* table may be absent pre-migration */ }
        usort($rows, static fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
        return $rows;
    }

    /** On-track dot colour from a set of open flags: any critical → red, any warning → amber, else green.
     *  (Intel 'opportunity'/'info' are not concerns and read green.) */
    private static function topSeverityDot(array $openRows): string
    {
        $hasWarning = false;
        foreach ($openRows as $r) {
            $s = (string)($r['severity'] ?? '');
            if ($s === 'critical') return 'red';
            if ($s === 'warning')  $hasWarning = true;
        }
        return $hasWarning ? 'amber' : 'green';
    }

    /**
     * PURE: the athlete's FULL flag record for the Flags tab — open + resolved history across
     * BOTH flag tables (engine_flags + coaching_intelligence_flags), normalized to one shape,
     * within the last $days days, capped at $limit resolved rows. No writes. Returns
     * ['open'=>[...newest first...], 'resolved'=>[...most-recently-resolved first...]].
     *
     * Each normalized row: source, severity, title, message, status, is_open, created_at,
     * flag_type, details, and (when resolved) resolution => [label, at, by, reason].
     */
    public static function athleteFlagRecord(int $athleteId, PDO $db, int $days = 90, int $limit = 50): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $rows   = [];

        // Engine flags (open / dismissed / acted_on) + the resolving coach's name.
        try {
            $es = $db->prepare(
                'SELECT ef.*, u.name AS reviewer_name
                 FROM engine_flags ef
                 LEFT JOIN users u ON u.id = ef.reviewed_by
                 WHERE ef.athlete_id = ? AND ef.created_at >= ?
                 ORDER BY ef.created_at DESC'
            );
            $es->execute([$athleteId, $cutoff]);
            foreach ($es->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $status = (string)$f['status'];
                $res = null;
                if ($status === 'dismissed') {
                    $res = ['label' => 'Dismissed', 'at' => $f['reviewed_at'], 'by' => $f['reviewer_name'] ?: null, 'reason' => ($f['dismiss_reason'] ?: null)];
                } elseif ($status === 'acted_on') {
                    $res = ['label' => 'Acted on', 'at' => $f['reviewed_at'], 'by' => $f['reviewer_name'] ?: null, 'reason' => null];
                }
                $rows[] = [
                    'source'     => 'engine',
                    'severity'   => (string)$f['severity'],
                    'title'      => (string)$f['flag_type'],
                    'message'    => (string)($f['message'] ?? ''),
                    'status'     => $status,
                    'is_open'    => $status === 'open',
                    'created_at' => (string)$f['created_at'],
                    'flag_type'  => (string)$f['flag_type'],
                    'details'    => $f['details'] ?? null,
                    'resolution' => $res,
                ];
            }
        } catch (\Throwable $e) { /* table may be absent pre-migration */ }

        // Coaching-intelligence flags (open / actioned / dismissed / superseded).
        try {
            $is = $db->prepare(
                'SELECT * FROM coaching_intelligence_flags
                 WHERE athlete_id = ? AND created_at >= ?
                 ORDER BY created_at DESC'
            );
            $is->execute([$athleteId, $cutoff]);
            foreach ($is->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $status = (string)$f['status'];
                $res = null;
                if ($status === 'actioned') {
                    $res = ['label' => 'Acted on', 'at' => $f['actioned_at'], 'by' => null, 'reason' => null];
                } elseif ($status === 'superseded') {
                    // Predictive→reactive handoff: an automatic resolution, never a coach action.
                    $res = ['label' => 'Auto-resolved', 'at' => $f['dismissed_at'], 'by' => null, 'reason' => ($f['suggested_action'] ?: null)];
                } elseif ($status === 'dismissed') {
                    $res = ['label' => 'Dismissed', 'at' => $f['dismissed_at'], 'by' => null, 'reason' => null];
                }
                $rows[] = [
                    'source'     => 'intel',
                    'severity'   => (string)$f['severity'],
                    'title'      => (string)($f['title'] ?? $f['flag_type']),
                    'message'    => (string)($f['detail'] ?? ''),
                    'status'     => $status,
                    'is_open'    => $status === 'open',
                    'created_at' => (string)$f['created_at'],
                    'flag_type'  => (string)$f['flag_type'],
                    'details'    => null,
                    'resolution' => $res,
                ];
            }
        } catch (\Throwable $e) { /* table may be absent pre-migration */ }

        // Open set is single-sourced (same as the chrome badge/dot) so they can't drift.
        $open     = self::openFlagsForAthlete($athleteId, $db);
        $resolved = array_values(array_filter($rows, static fn($r) => !$r['is_open']));
        usort($resolved, static function ($a, $b) {
            $ax = (string)($a['resolution']['at'] ?? $a['created_at']);
            $bx = (string)($b['resolution']['at'] ?? $b['created_at']);
            return strcmp($bx, $ax);
        });
        if (count($resolved) > $limit) $resolved = array_slice($resolved, 0, $limit);

        return ['open' => $open, 'resolved' => $resolved];
    }

    /**
     * GET /app/coach/athlete/:id/flags — the Flags tab: this athlete's FULL flag record (open +
     * resolved history across engine_flags + coaching_intelligence_flags), read-only — a distinct
     * "review everything" surface from the Plan view's actionable OPEN ALERTS. No dismiss/act
     * controls here. Coach-owns-athlete scoped.
     */
    public static function athleteFlagsPage(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db        = Database::get();
        $coachId   = (int)Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) {
            http_response_code(404);
            include __DIR__ . '/../../views/errors/404.php';
            return;
        }

        $flagRecord   = self::athleteFlagRecord($athleteId, $db);
        $chrome       = self::athleteChromeData($athleteId, $db);
        $chromeActive = 'flags';

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $pageTitle = 'Flags: ' . h($athlete['name']);
        $activeNav = 'athletes';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/athlete_flags.php';
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

        $chrome       = self::athleteChromeData($athleteId, $db);
        $chromeActive = 'profile';

        $pageTitle = 'Edit Profile: ' . h($athlete['name']);
        $activeNav = 'athletes';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/edit_profile.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    public static function editProfileSave(array $params): void
    {
        // Assistant coaches may edit the profile (the only path to pace zones); an
        // info flag is raised on save so the head coach can review (spec Part 4).
        Auth::requireRole(['coach','assistant_coach','admin']);
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

        // Hyrox is a UI facade over the mile engine (mile spec Part 2): the pill posts
        // goal_race_distance='hyrox', stored as goal='mile' with is_hyrox=1. Translate
        // before sanitising so ProfileForm sees a valid enum value.
        $isHyrox = ($_POST['goal_race_distance'] ?? '') === 'hyrox' ? 1 : 0;
        if ($isHyrox) { $_POST['goal_race_distance'] = 'mile'; }

        $new = ProfileForm::sanitize($_POST, true);

        // Engine-critical completeness + cross-field sanity (Stage A3/A4). Errors block
        // the save; soft warnings ride along with the success flash. plan_type is now
        // editable on the form, so validateSubmission keys the goal-distance requirement
        // on the submitted plan_type (falling back to the stored one).
        $check = ProfileForm::validateSubmission($new, $old['plan_type'] ?? null);
        if (!empty($check['errors'])) {
            $_SESSION['flash_error'] = implode(' ', $check['errors']);
            header('Location: /app/coach/athlete/' . $athleteId . '/edit');
            exit;
        }

        ProfileForm::save($athleteId, $old, $new, [
            'actor_role'   => 'coach',
            'athlete_name' => $athlete['name'],
        ], $db);

        // is_hyrox tracks the current selection; hyrox_ever latches to 1 the first time
        // Hyrox is chosen and never resets, keeping the pill visible after switching away.
        $db->prepare(
            'UPDATE athlete_profiles
                SET is_hyrox = ?, hyrox_ever = GREATEST(hyrox_ever, ?)
              WHERE athlete_id = ?'
        )->execute([$isHyrox, $isHyrox, $athleteId]);

        // Coach override of the athlete's timezone (users column on the athlete's account).
        if (array_key_exists('timezone', $_POST)) {
            $tz = Timezone::isValid($_POST['timezone']) ? $_POST['timezone'] : Timezone::DEFAULT_TZ;
            $db->prepare('UPDATE users SET timezone = ? WHERE id = ?')->execute([$tz, (int)$athlete['user_id']]);
            Timezone::clearCache((int)$athlete['user_id']);
        }

        // Capture pace-zone edits (Coaching Intelligence Layer). Pace zones are profile-
        // level, not tied to a planned workout, so planned_workout_id = 0. before/after
        // hold the pace_zones LONGTEXT value; recorded only when it actually changed.
        $newProfile = Auth::getAthleteProfile($athleteId) ?? [];
        $oldZones   = (string)($old['pace_zones'] ?? '');
        $newZones   = (string)($newProfile['pace_zones'] ?? '');
        if ($oldZones !== $newZones) {
            CoachAdjustments::record(0, $athleteId, (int)$coachId, 'pace_zone_edit',
                ['instructions' => $oldZones !== '' ? $oldZones : null],
                ['instructions' => $newZones !== '' ? $newZones : null],
                $db);
        }

        // Assistant coach edits apply immediately but raise an info flag for the head
        // coach to review (pace-zone edits are the headline capability here).
        if (Auth::role() === 'assistant_coach') {
            $msg = 'Assistant coach updated pace zones for ' . $athlete['name'] . '. Review recommended.';
            $db->prepare(
                'INSERT INTO engine_flags (athlete_id, flag_type, severity, flag_date, message, status, created_at)
                 VALUES (?, "assistant_pace_zone_edit", "info", CURDATE(), ?, "open", NOW())'
            )->execute([$athleteId, $msg]);
            Notifications::notifyFlag($athleteId, 'info', $msg);
        }

        $_SESSION['flash_success'] = 'Training profile updated.'
            . (!empty($check['warnings']) ? ' Note: ' . implode(' ', $check['warnings']) : '');
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

        // Stage B — generation completeness gate (warn-and-allow). The safety net for
        // athletes who never completed full onboarding (coach-created): if any engine-
        // critical field is missing, surface exactly what's absent and the default the
        // engine will substitute, then bounce back WITHOUT generating. A second click
        // (ack_missing=1, set by the warning banner's form) proceeds anyway.
        $profile  = Auth::getAthleteProfile($athleteId) ?? [];
        $missing  = ProfileForm::missingCritical($profile, $profile['plan_type'] ?? null);
        if ($missing && empty($_POST['ack_missing'])) {
            $defaults = [
                'current_weekly_minutes'  => 'start from a minimal default volume',
                'training_days_per_week'  => 'assume 4 running days/week',
                'longest_recent_run_mins' => 'estimate the long run (~60 min)',
                'goal_race_distance'      => 'assume a 5K goal',
            ];
            $names = implode(', ', array_values($missing));
            $acts  = implode('; ', array_map(fn($c) => $defaults[$c] ?? 'use a default', array_keys($missing)));
            $_SESSION['generate_warning'] = [
                'athlete_id' => $athleteId,
                'message'    => "Missing for an accurate plan: {$names}. If you generate now the engine will {$acts}. "
                              . 'Set these in Edit Profile for an accurate plan, or generate anyway.',
            ];
            header('Location: /app/coach/athlete/' . $athleteId);
            exit;
        }

        // Default preserves athlete-exposed weeks; the required checkbox forces a full wipe.
        $fullWipe = !empty($_POST['full_wipe']);
        try {
            $planId = PlanGenerator::generate($athleteId, 'coach_manual', $fullWipe);
            if ($planId) {
                $_SESSION['flash_success'] = $fullWipe
                    ? 'New plan generated (full rebuild) and added to the approval queue.'
                    : 'New plan generated and added to the approval queue. Weeks the athlete has already seen were carried over.';
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

    // ── Assistant coach assignment (head coach / admin) ────────────────────────

    /** POST /app/coach/athlete/{id}/assistant — set or clear the assistant coach. */
    public static function assignAssistant(array $params): void
    {
        // Only head coaches and admins manage assistant assignments.
        Auth::requireRole(['coach', 'admin']);
        Auth::verifyCsrf();

        $coachId   = (int)Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);
        $db        = Database::get();

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) { header('Location: /app/coach/athletes'); exit; }

        $assistantId = (int)($_POST['assistant_coach_id'] ?? 0) ?: null;
        if ($assistantId !== null) {
            // The assistant must be active and (unless the actor is an admin) managed
            // by this head coach.
            $sql  = 'SELECT 1 FROM users WHERE id = ? AND role = "assistant_coach" AND active = 1';
            $args = [$assistantId];
            if (Auth::role() !== 'admin') { $sql .= ' AND managed_by = ?'; $args[] = $coachId; }
            $sql .= ' LIMIT 1';
            $chk = $db->prepare($sql);
            $chk->execute($args);
            if (!$chk->fetchColumn()) {
                $_SESSION['flash_error'] = 'That assistant coach is not available to assign.';
                header('Location: /app/coach/athlete/' . $athleteId);
                exit;
            }
        }

        CoachAssignments::setAssistant($athleteId, $assistantId, $coachId, $db);
        $_SESSION['flash_success'] = $assistantId ? 'Assistant coach assigned.' : 'Assistant coach removed.';
        header('Location: /app/coach/athlete/' . $athleteId);
        exit;
    }

    // ── Plan regeneration requests (assistant coach → head coach) ──────────────

    /** POST /app/coach/athlete/{id}/request-regeneration — assistant asks for a rebuild. */
    public static function requestRegeneration(array $params): void
    {
        // Assistant coaches request; head coaches/admins generate directly.
        Auth::requireRole(['assistant_coach']);
        Auth::verifyCsrf();

        $uid       = (int)Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);
        $db        = Database::get();

        $athlete = self::getAthleteForCoach($athleteId, $uid, $db);
        if (!$athlete) { header('Location: /app/coach/athletes'); exit; }

        // One open request at a time.
        $exists = $db->prepare('SELECT 1 FROM plan_regeneration_requests WHERE athlete_id = ? AND status = "pending" LIMIT 1');
        $exists->execute([$athleteId]);
        if (!$exists->fetchColumn()) {
            $db->prepare(
                'INSERT INTO plan_regeneration_requests (athlete_id, requested_by, requested_at, status)
                 VALUES (?, ?, NOW(), "pending")'
            )->execute([$athleteId, $uid]);
        }

        $_SESSION['flash_success'] = 'Plan regeneration requested. Your head coach will review it.';
        header('Location: /app/coach/athlete/' . $athleteId);
        exit;
    }

    /** POST /app/coach/regeneration/{reqId}/approve — head coach approves → generate. */
    public static function approveRegeneration(array $params): void
    {
        Auth::requireRole(['coach', 'admin']);
        Auth::verifyCsrf();

        $uid   = (int)Auth::userId();
        $reqId = (int)($params['reqId'] ?? 0);
        $db    = Database::get();

        $stmt = $db->prepare('SELECT id, athlete_id FROM plan_regeneration_requests WHERE id = ? AND status = "pending" LIMIT 1');
        $stmt->execute([$reqId]);
        $req = $stmt->fetch();

        if ($req && self::getAthleteForCoach((int)$req['athlete_id'], $uid, $db)) {
            $athleteId = (int)$req['athlete_id'];
            $fullWipe  = !empty($_POST['full_wipe']);
            try {
                PlanGenerator::generate($athleteId, 'coach_manual', $fullWipe);
                $_SESSION['flash_success'] = 'Plan regenerated and added to the approval queue.';
            } catch (Throwable $e) {
                error_log('approveRegeneration generate failed for athlete ' . $athleteId . ': ' . $e->getMessage());
                $_SESSION['flash_error'] = 'Plan generation failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
            }
            $db->prepare('UPDATE plan_regeneration_requests SET status = "approved", actioned_by = ?, actioned_at = NOW() WHERE id = ?')
               ->execute([$uid, $reqId]);
            header('Location: /app/coach/athlete/' . $athleteId);
            exit;
        }

        header('Location: /app/coach/athletes');
        exit;
    }

    /** POST /app/coach/regeneration/{reqId}/dismiss — head coach dismisses (optional note). */
    public static function dismissRegeneration(array $params): void
    {
        Auth::requireRole(['coach', 'admin']);
        Auth::verifyCsrf();

        $uid   = (int)Auth::userId();
        $reqId = (int)($params['reqId'] ?? 0);
        $notes = trim($_POST['notes'] ?? '') ?: null;
        $db    = Database::get();

        $stmt = $db->prepare('SELECT id, athlete_id FROM plan_regeneration_requests WHERE id = ? AND status = "pending" LIMIT 1');
        $stmt->execute([$reqId]);
        $req = $stmt->fetch();

        if ($req && self::getAthleteForCoach((int)$req['athlete_id'], $uid, $db)) {
            $db->prepare('UPDATE plan_regeneration_requests SET status = "dismissed", actioned_by = ?, actioned_at = NOW(), notes = ? WHERE id = ?')
               ->execute([$uid, $notes, $reqId]);
            $_SESSION['flash_success'] = 'Regeneration request dismissed.';
            header('Location: /app/coach/athlete/' . (int)$req['athlete_id']);
            exit;
        }

        header('Location: /app/coach/athletes');
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

    /** Free-form intensity-load factors (mirror the free-form ADD path in addWorkout). */
    private const FREEFORM_LOAD_FACTOR = [
        'easy' => 0.5, 'long' => 0.6, 'tempo' => 0.85, 'interval' => 0.9, 'hill' => 0.85, 'fartlek' => 0.8,
        'race_pace' => 0.9, 'recovery' => 0.3, 'rest' => 0.0, 'cross_train' => 0.4, 'speed' => 0.95, 'plyometric' => 0.7,
    ];

    /**
     * POST /app/coach/workouts/:id/edit — full editing of an assigned (planned) workout, in
     * three modes on the SAME row (extends the original lightweight surface edit; not a second
     * path). Body is form-encoded (legacy weekly-calendar surface edit) OR JSON (the macro
     * edit modal). `mode`: surface | archetype | freeform (default surface).
     *
     *   surface   — tweak workout_type / duration / title / instructions / coach notes in place;
     *               archetype_code + instance_signature unchanged.
     *   archetype — re-resolve through composeManualWorkout, overwriting the archetype snapshot
     *               + all display + signature (a `preview` flag returns the render with no write).
     *   freeform  — title / type / duration / instructions / coach notes; archetype_code = NULL,
     *               instance_signature = NULL (identical to the free-form ADD path).
     *
     * Every save sets coach_locked + coach_edited_by/at, RECOMPUTES intensity_load, keeps display
     * text consistent with the new duration, and best-effort re-pushes to Intervals.icu (a push
     * failure can never fail the edit). Never calls PlanGenerator::generate — one row only.
     */
    public static function editPlannedWorkout(array $params): void
    {
        Auth::requireRole(['coach', 'assistant_coach', 'admin']);
        Auth::verifyCsrf();
        require_once __DIR__ . '/../../views/layout/base.php'; // pill_label / pill_class / format_duration
        header('Content-Type: application/json');

        $workoutId   = (int)($params['id'] ?? 0);
        $coachId     = (int)Auth::userId();
        $isAssistant = Auth::role() === 'assistant_coach';
        $db          = Database::get();

        $in      = !empty($_POST) ? $_POST : self::jsonBody();
        $mode    = (string)($in['mode'] ?? 'surface');
        if (!in_array($mode, ['surface', 'archetype', 'freeform'], true)) $mode = 'surface';
        $preview = !empty($in['preview']);

        $row = $db->prepare('SELECT * FROM planned_workouts WHERE id = ? LIMIT 1');
        $row->execute([$workoutId]);
        $before = $row->fetch(PDO::FETCH_ASSOC);
        if (!$before) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
        $athleteId = (int)$before['athlete_id'];

        // Ownership (head coach via coach_id, assistant via coach_assignments, admin all).
        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

        // Free-form editing is denied to assistant coaches (mirrors free-form add).
        if ($mode === 'freeform' && $isAssistant) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'Assistant coaches cannot create a custom workout.']);
            exit;
        }

        // Editable only when future + uncompleted + not cancelled.
        if (!empty($before['cancelled'])) { echo json_encode(['ok' => false, 'error' => 'cancelled', 'message' => 'A removed workout cannot be edited.']); exit; }
        $tz    = $athlete['timezone'] ?: 'America/New_York';
        $today = Timezone::dateInZone(Timezone::isValid($tz) ? $tz : 'America/New_York', 'now');
        if ((string)$before['scheduled_date'] < $today) {
            echo json_encode(['ok' => false, 'error' => 'past', 'message' => 'Past workouts cannot be edited.']); exit;
        }
        $cw = $db->prepare(
            'SELECT 1 FROM completed_workouts
             WHERE planned_workout_id = ?
                OR (planned_workout_id IS NULL AND athlete_id = ? AND activity_date = ?) LIMIT 1'
        );
        $cw->execute([$workoutId, $athleteId, (string)$before['scheduled_date']]);
        if ($cw->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'completed', 'message' => 'A completed workout cannot be edited.']); exit; }

        $oldType = (string)($before['workout_type'] ?? '');
        $oldDur  = $before['target_duration'] !== null ? (int)$before['target_duration'] : null;
        $oldInst = $before['athlete_instructions'] !== null ? (string)$before['athlete_instructions'] : null;
        $oldArch = $before['archetype_code'] !== null ? (string)$before['archetype_code'] : null;
        $oldLoad = $before['intensity_load'] !== null ? (float)$before['intensity_load'] : null;

        // Resolve the per-mode column set through the pure composer (no DB write / echo / exit),
        // then persist here. Keeps the silent-load-desync logic exercised by the verify.
        $r = self::composeWorkoutEdit($mode, $before, $in, $db);
        if (!empty($r['error'])) {
            echo json_encode(['ok' => false, 'error' => $r['error'], 'message' => $r['message'] ?? null]); exit;
        }
        if ($preview && $mode === 'archetype' && !empty($r['composed'])) {
            $c = $r['composed'];
            echo json_encode(['ok' => true, 'success' => true, 'preview' => self::previewPayload(
                $c['display_title'], $c['display_summary'], $c['athlete_instructions'],
                $c['workout_type'], (int)$c['target_duration']
            )]); exit;
        }
        $changeType = $r['change_type'] ?? null;

        $sets = []; $vals = [];
        foreach ($r['columns'] as $col => $v) { $sets[] = "`{$col}` = ?"; $vals[] = $v; }
        self::finishWorkoutEdit($db, $workoutId, $sets, $vals, $coachId);

        // Best-effort Intervals re-push — DB change already committed; never fail the edit.
        try { IntervalsService::pushWorkout($athleteId, $workoutId, $db); } catch (\Throwable $e) {
            error_log('editPlannedWorkout push failed for workout ' . $workoutId . ': ' . $e->getMessage());
        }

        // Capture the coach adjustment (Coaching Intelligence Layer).
        $after = $db->prepare('SELECT workout_type, target_duration, athlete_instructions, archetype_code FROM planned_workouts WHERE id = ? LIMIT 1');
        $after->execute([$workoutId]);
        $aft = $after->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($changeType !== null) {
            CoachAdjustments::record($workoutId, $athleteId, $coachId, $changeType,
                ['archetype_code' => $oldArch, 'workout_type' => $oldType, 'duration_mins' => $oldDur, 'instructions' => $oldInst],
                [
                    'archetype_code' => $aft['archetype_code'] !== null ? (string)$aft['archetype_code'] : null,
                    'workout_type'   => (string)($aft['workout_type'] ?? $oldType),
                    'duration_mins'  => $aft['target_duration'] !== null ? (int)$aft['target_duration'] : $oldDur,
                    'instructions'   => $aft['athlete_instructions'] !== null ? (string)$aft['athlete_instructions'] : null,
                ],
                $db);
        }

        // Response: legacy {ok, workout} shape (weekly calendar) + the DOM fields the macro modal needs.
        $u = $db->prepare(
            'SELECT id, workout_type, archetype_code, archetype_variant, notes, target_duration, scheduled_date,
                    display_title, display_summary, athlete_instructions,
                    COALESCE(athlete_instructions, description, display_summary, \'\') AS description,
                    coach_locked
             FROM planned_workouts WHERE id = ? LIMIT 1'
        );
        $u->execute([$workoutId]);
        $w  = $u->fetch(PDO::FETCH_ASSOC);
        $wt = (string)$w['workout_type'];
        $arch = $w['archetype_code'] !== null ? (string)$w['archetype_code'] : null;
        $dur = (int)($w['target_duration'] ?? 0);
        echo json_encode(['ok' => true, 'success' => true, 'workout' => [
            'id'                   => (int)$w['id'],
            'workout_type'         => $wt,
            'type_label'           => pill_label($wt, $arch),
            'type_class'           => pill_class($wt, $arch),
            'title'                => ($w['display_title'] ?? '') !== '' ? $w['display_title'] : pill_label($wt, $arch),
            'display_title'        => $w['display_title'],
            'template_name'        => $w['display_title'],
            'target_duration'      => $dur,
            'duration_label'       => $dur > 0 ? format_duration($dur) : '',
            'summary'              => $w['display_summary'],
            'display_summary'      => $w['display_summary'],
            'description'          => $w['description'],
            'athlete_instructions' => $w['athlete_instructions'],
            'archetype_code'       => $arch ?? '',
            'archetype_variant'    => $w['archetype_variant'] !== null ? (string)$w['archetype_variant'] : '',
            'coach_notes'          => $w['notes'] !== null ? (string)$w['notes'] : '',
            'date'                 => (string)$w['scheduled_date'],
            'coach_locked'         => 1,
        ]]);
        exit;
    }

    /**
     * Resolve the column set a workout edit should persist, for a given (mode, existing row,
     * input). PURE: no DB write, no echo, no exit (it only reads via composeManualWorkout for
     * the archetype mode). Returns:
     *   ['error'=>?string, 'message'=>?string]                  on validation failure, OR
     *   ['change_type'=>string, 'columns'=>array<col,?scalar>,  on success
     *    'composed'=>?array]                                     ('composed' set for archetype mode)
     * `columns` maps column name → value to UPDATE (null = SQL NULL). The lock/edited columns are
     * appended by finishWorkoutEdit, not here. Requires the base.php display helpers to be loaded.
     */
    public static function composeWorkoutEdit(string $mode, array $before, array $in, PDO $db): array
    {
        $athleteId = (int)$before['athlete_id'];
        $oldType   = (string)($before['workout_type'] ?? '');
        $oldDur    = $before['target_duration'] !== null ? (int)$before['target_duration'] : null;
        $oldArch   = $before['archetype_code'] !== null ? (string)$before['archetype_code'] : null;
        $oldLoad   = $before['intensity_load'] !== null ? (float)$before['intensity_load'] : null;

        if ($mode === 'archetype') {
            $code     = trim((string)($in['archetype_code'] ?? ''));
            $variant  = isset($in['archetype_variant']) && $in['archetype_variant'] !== '' ? (string)$in['archetype_variant'] : null;
            $duration = (int)($in['duration'] ?? 0);
            $composed = PlanGenerator::composeManualWorkout($athleteId, $code, $variant, $duration, $db);
            if (!$composed) return ['error' => 'archetype_failed', 'message' => 'Could not build that workout.'];

            return [
                'change_type' => 'archetype_substitution',
                'composed'    => $composed,
                'columns'     => [
                    'workout_type'               => $composed['workout_type'],
                    'archetype_code'             => $composed['archetype_code'],
                    'archetype_variant'          => $composed['archetype_variant'],
                    'archetype_params'           => $composed['archetype_params'],
                    'workout_archetype_id'       => $composed['workout_archetype_id'],
                    'archetype_version_snapshot' => $composed['archetype_version_snapshot'],
                    'instance_signature'         => $composed['instance_signature'],
                    'structure'                  => $composed['structure'],
                    'display_title'              => $composed['display_title'],
                    'display_summary'            => $composed['display_summary'],
                    'athlete_instructions'       => $composed['athlete_instructions'],
                    'description'                => $composed['athlete_instructions'],
                    'target_duration'            => (int)$composed['target_duration'],
                    'intensity_load'             => $composed['intensity_load'],
                    // Fresh structured recompose: the watch pushes the (new) structure, not text.
                    'push_text_only'             => 0,
                ],
            ];
        }

        if ($mode === 'freeform') {
            $title      = trim((string)($in['title'] ?? ''));
            $validTypes = ['easy', 'long', 'tempo', 'interval', 'hill', 'fartlek', 'race_pace', 'recovery', 'rest', 'cross_train', 'speed', 'plyometric'];
            $wt         = in_array($in['workout_type'] ?? '', $validTypes, true) ? (string)$in['workout_type'] : 'easy';
            $duration   = (int)($in['duration'] ?? 0);
            $instr      = trim((string)($in['instructions'] ?? '')) ?: null;
            $coachNotes = trim((string)($in['coach_notes'] ?? '')) ?: null;
            if ($title === '' || $duration < 1) return ['error' => 'invalid', 'message' => 'Title and a duration of at least 1 minute are required.'];

            $load = round($duration * (self::FREEFORM_LOAD_FACTOR[$wt] ?? 0.5), 2);
            return [
                'change_type' => 'archetype_substitution',
                'columns'     => [
                    'workout_type'               => $wt,
                    'archetype_code'             => null,
                    'archetype_variant'          => null,
                    'archetype_params'           => null,
                    'workout_archetype_id'       => null,
                    'archetype_version_snapshot' => null,
                    'instance_signature'         => null,
                    'structure'                  => null,
                    'display_title'              => $title,
                    'display_summary'            => null,
                    'athlete_instructions'       => $instr,
                    'description'                => $instr,
                    'notes'                      => $coachNotes,
                    'target_duration'            => $duration,
                    'intensity_load'             => $load,
                    // structure is NULL here; the watch already falls back to the text. Keep
                    // the flag cleared so a later structured edit behaves predictably.
                    'push_text_only'             => 0,
                ],
            ];
        }

        // surface — tweak in place; archetype_code + instance_signature unchanged.
        $validTypes = ['easy','long','interval','hill','fartlek','tempo','race','race_pace','speed','plyometric','recovery','cross_train'];
        $type   = in_array($in['workout_type'] ?? '', $validTypes, true) ? (string)$in['workout_type'] : $oldType;
        $newDur = (isset($in['target_duration']) && (int)$in['target_duration'] > 0) ? (int)$in['target_duration'] : ($oldDur ?? 0);

        // Preserve the row's effective intensity factor; recompute load for the new duration.
        $factor = ($oldLoad !== null && $oldLoad > 0 && $oldDur) ? $oldLoad / $oldDur : (self::FREEFORM_LOAD_FACTOR[$type] ?? 0.5);
        // push_text_only = 1: a surface edit changes display fields / instructions / duration but
        // NOT the stored structure, which is now stale. The watch must push the coach's text (what
        // the app shows), not the old structured steps. The structure stays on the row, preserved
        // for a future structured editor; only the push rendering prefers the text (GAP A fix).
        $cols   = ['workout_type' => $type, 'target_duration' => $newDur, 'intensity_load' => round($newDur * $factor, 2), 'push_text_only' => 1];

        // Coach owns title + instructions when supplied.
        if (array_key_exists('title', $in))                $cols['display_title']        = trim((string)$in['title']) ?: null;
        if (array_key_exists('athlete_instructions', $in)) $cols['athlete_instructions'] = trim((string)$in['athlete_instructions']) ?: null;
        if (array_key_exists('coach_notes', $in))          $cols['notes']                = trim((string)$in['coach_notes']) ?: null;
        // No stale duration text: regenerate the one-line summary when duration/type changed.
        if ($newDur !== $oldDur || $type !== $oldType) {
            $cols['display_summary'] = format_duration($newDur) . ' · ' . pill_label($type, $oldArch);
        }

        $changeType = ($type !== $oldType) ? 'archetype_substitution'
                    : (($newDur !== $oldDur) ? 'duration_change' : 'instructions_edited');
        return ['change_type' => $changeType, 'columns' => $cols];
    }

    /** Apply a workout-edit UPDATE: append the lock/edited columns and run it. Editing never
     *  rewrites added_by_role — that records who ADDED the row; edit attribution is
     *  coach_edited_by + coach_edited_at. */
    private static function finishWorkoutEdit(PDO $db, int $workoutId, array $sets, array $vals, int $coachId): void
    {
        $sets[] = 'coach_locked = 1';
        $sets[] = 'coach_edited_by = ?'; $vals[] = $coachId;
        $sets[] = 'coach_edited_at = NOW()';
        $vals[] = $workoutId;
        $db->prepare('UPDATE planned_workouts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
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
            'SELECT pw.id, pw.scheduled_date, pw.plan_id, pw.workout_type, pw.target_duration,
                    tp.plan_start_date, tp.plan_end_date
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

            // Capture the coach adjustment (Coaching Intelligence Layer).
            CoachAdjustments::record($workoutId, $athleteId, (int)$coachId, 'day_swap',
                ['scheduled_date' => $oldDate, 'workout_type' => (string)$workout['workout_type'], 'duration_mins' => (int)$workout['target_duration']],
                ['scheduled_date' => $newDate, 'workout_type' => (string)$workout['workout_type'], 'duration_mins' => (int)$workout['target_duration']],
                $db);

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

        // Capture the coach adjustment (Coaching Intelligence Layer).
        CoachAdjustments::record($workoutId, $athleteId, (int)$coachId, 'day_swap',
            ['scheduled_date' => $oldDate, 'workout_type' => (string)$workout['workout_type'], 'duration_mins' => (int)$workout['target_duration']],
            ['scheduled_date' => $newDate, 'workout_type' => (string)$workout['workout_type'], 'duration_mins' => (int)$workout['target_duration']],
            $db);

        echo json_encode(['success' => true]); exit;
    }

    /**
     * Add a workout to the macro plan — archetype picker or free-form entry.
     * Body (JSON): { type:'archetype'|'freeform', scheduled_date, preview?:bool, … }
     * With preview:true the rendered display is returned without inserting.
     */
    public static function addWorkout(array $params): void
    {
        // Assistant coaches may add workouts, but only from the archetype picker
        // (no free-form entry); those rows are tagged added_by_role='assistant_coach'.
        Auth::requireRole(['coach', 'assistant_coach', 'admin']);
        Auth::verifyCsrf();
        require_once __DIR__ . '/../../views/layout/base.php'; // pill_class / pill_label / format_duration
        header('Content-Type: application/json');

        $coachId      = Auth::userId();
        $isAssistant  = Auth::role() === 'assistant_coach';
        $addedByRole  = $isAssistant ? 'assistant_coach' : null;
        $athleteId    = (int)($params['id'] ?? 0);
        $db           = Database::get();

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'forbidden']); exit; }

        $in      = self::jsonBody();
        $type    = ($in['type'] ?? '') === 'freeform' ? 'freeform' : 'archetype';
        $date    = trim((string)($in['scheduled_date'] ?? ''));
        $preview = !empty($in['preview']);

        // Assistant coaches are restricted to the archetype picker.
        if ($isAssistant && $type === 'freeform') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden', 'message' => 'Assistant coaches can only add workouts from the archetype picker.']);
            exit;
        }

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
                   coach_locked, coach_edited_by, coach_edited_at, visible_to_athlete, added_by_role)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), 1, ?)'
            );
            $insert->execute([
                $planId, $athleteId, $date, $composed['workout_type'],
                $composed['archetype_code'], $composed['archetype_variant'], $composed['archetype_params'],
                $composed['workout_archetype_id'], $composed['archetype_version_snapshot'], $composed['instance_signature'],
                $composed['structure'], $composed['display_title'], $composed['display_summary'], $composed['athlete_instructions'],
                $composed['athlete_instructions'], $composed['target_duration'], $composed['intensity_load'],
                $coachId, $addedByRole,
            ]);
            $id = (int)$db->lastInsertId();

            // Push the new workout to Intervals.icu (no-op if the athlete isn't connected).
            IntervalsService::pushWorkout($athleteId, $id, $db);

            // Capture the coach adjustment (Coaching Intelligence Layer): before all null.
            CoachAdjustments::record($id, $athleteId, (int)$coachId, 'workout_added',
                [],
                [
                    'archetype_code' => $composed['archetype_code'] !== null ? (string)$composed['archetype_code'] : null,
                    'workout_type'   => (string)$composed['workout_type'],
                    'duration_mins'  => (int)$composed['target_duration'],
                    'scheduled_date' => $date,
                ],
                $db);

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

        // Capture the coach adjustment (Coaching Intelligence Layer): free-form entry has
        // no archetype_code.
        CoachAdjustments::record($id, $athleteId, (int)$coachId, 'workout_added',
            [],
            ['archetype_code' => null, 'workout_type' => $wt, 'duration_mins' => $duration, 'scheduled_date' => $date],
            $db);

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
        // Assistant coaches may remove workouts (spec Part 4).
        Auth::requireRole(['coach', 'assistant_coach', 'admin']);
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
            'SELECT id, scheduled_date, workout_type, target_duration, archetype_code, athlete_instructions
             FROM planned_workouts
             WHERE id = ? AND athlete_id = ? AND (cancelled = 0 OR cancelled IS NULL) LIMIT 1'
        );
        $stmt->execute([$workoutId, $athleteId]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['success' => false, 'error' => 'not_found', 'message' => 'Workout not found.']); exit; }

        $db->prepare('UPDATE planned_workouts SET cancelled = 1, cancelled_at = NOW(), cancelled_by = ? WHERE id = ?')
           ->execute([$coachId, $workoutId]);

        // Remove the event from Intervals.icu (no-op if the athlete isn't connected).
        IntervalsService::deleteWorkout($athleteId, $workoutId, $db);

        // Capture the coach adjustment (Coaching Intelligence Layer): before = full
        // snapshot, after = all null.
        CoachAdjustments::record($workoutId, $athleteId, (int)$coachId, 'workout_removed',
            [
                'archetype_code' => $row['archetype_code'] !== null ? (string)$row['archetype_code'] : null,
                'workout_type'   => (string)$row['workout_type'],
                'duration_mins'  => $row['target_duration'] !== null ? (int)$row['target_duration'] : null,
                'scheduled_date' => (string)$row['scheduled_date'],
                'instructions'   => $row['athlete_instructions'] !== null ? (string)$row['athlete_instructions'] : null,
            ],
            [], $db);

        echo json_encode(['success' => true, 'date' => (string)$row['scheduled_date']]); exit;
    }

    /**
     * Toggle the "flagged for review" marker on a planned workout (Coaching Intelligence
     * Layer, Part 3). Body (JSON): { planned_workout_id, flagged:bool }.
     *
     * If a coach_adjustments row already exists for this workout (any change_type), its
     * flagged_for_review is updated. If none exists and the coach is flagging it on, a
     * minimal marker row is inserted (change_type 'instructions_edited', before == after,
     * no actual change — just marking for attention).
     */
    public static function flagWorkout(): void
    {
        Auth::requireRole(['coach', 'assistant_coach', 'admin']);
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $coachId = (int)Auth::userId();
        $db      = Database::get();
        $in      = self::jsonBody();
        $pwId    = (int)($in['planned_workout_id'] ?? 0);
        $flagged = (!empty($in['flagged']) && $in['flagged'] !== 'false') ? 1 : 0;

        $pw = $db->prepare(
            'SELECT id, athlete_id, scheduled_date, workout_type, target_duration,
                    archetype_code, athlete_instructions
             FROM planned_workouts WHERE id = ? LIMIT 1'
        );
        $pw->execute([$pwId]);
        $w = $pw->fetch(PDO::FETCH_ASSOC);
        if (!$w) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'not_found']); exit; }

        $athleteId = (int)$w['athlete_id'];
        if (!self::getAthleteForCoach($athleteId, $coachId, $db)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }

        $ex = $db->prepare('SELECT id FROM coach_adjustments WHERE planned_workout_id = ? ORDER BY id DESC LIMIT 1');
        $ex->execute([$pwId]);
        $existing = (int)($ex->fetchColumn() ?: 0);

        if ($existing > 0) {
            $db->prepare('UPDATE coach_adjustments SET flagged_for_review = ? WHERE planned_workout_id = ?')
               ->execute([$flagged, $pwId]);
        } elseif ($flagged) {
            $snap = [
                'archetype_code' => $w['archetype_code'] !== null ? (string)$w['archetype_code'] : null,
                'workout_type'   => (string)$w['workout_type'],
                'duration_mins'  => $w['target_duration'] !== null ? (int)$w['target_duration'] : null,
                'scheduled_date' => (string)$w['scheduled_date'],
                'instructions'   => $w['athlete_instructions'] !== null ? (string)$w['athlete_instructions'] : null,
            ];
            $newId = CoachAdjustments::record($pwId, $athleteId, $coachId, 'instructions_edited', $snap, $snap, $db);
            if ($newId > 0) {
                $db->prepare('UPDATE coach_adjustments SET flagged_for_review = 1 WHERE id = ?')->execute([$newId]);
            }
        }

        echo json_encode(['success' => true, 'flagged' => $flagged]);
        exit;
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
        // Assistant coaches may approve plans (spec Part 4); generation stays coach-only.
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $planId   = (int)($params['planId'] ?? 0);
        $coachId  = Auth::userId();
        $db       = Database::get();
        $notes    = trim($_POST['coach_notes'] ?? '');

        // Verify plan belongs to one of this user's athletes
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT paq.id, paq.athlete_id
             FROM plan_approval_queue paq
             JOIN athletes a ON a.id = paq.athlete_id AND ' . $scope . '
             WHERE paq.plan_id = ? AND paq.status = "pending"
             LIMIT 1'
        );
        $stmt->execute(array_merge($sp, [$planId]));
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
        // Assistant coaches action the approval queue alongside approve (spec Part 4).
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $planId  = (int)($params['planId'] ?? 0);
        $coachId = Auth::userId();
        $db      = Database::get();
        $notes   = trim($_POST['coach_notes'] ?? '');

        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT paq.id FROM plan_approval_queue paq
             JOIN athletes a ON a.id = paq.athlete_id AND ' . $scope . '
             WHERE paq.plan_id = ? AND paq.status = "pending" LIMIT 1'
        );
        $stmt->execute(array_merge($sp, [$planId]));
        $queue = $stmt->fetch();

        if ($queue) {
            $db->prepare(
                'UPDATE plan_approval_queue SET status="rejected", reviewed_by=?, reviewed_at=NOW(), coach_notes=? WHERE id=?'
            )->execute([$coachId, $notes, $queue['id']]);

            $db->prepare('UPDATE training_plans SET status="archived", archived_at=NOW() WHERE id=?')->execute([$planId]);

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

        // Enrich pace_recalibration flags with the race + current/proposed zones so the
        // alerts view can render the side-by-side recalibration card (§26 / Part 7).
        foreach ($flags as &$f) {
            if (($f['flag_type'] ?? '') !== 'pace_recalibration') continue;
            $d = json_decode((string)($f['details'] ?? ''), true);
            $raceId = (int)($d['race_id'] ?? 0);
            if (!$raceId) continue;
            $rs = $db->prepare(
                'SELECT r.id, r.race_distance, r.result_time, r.race_date, r.proposed_pace_zones,
                        ap.pace_zones AS current_pace_zones
                 FROM races r JOIN athlete_profiles ap ON ap.athlete_id = r.athlete_id
                 WHERE r.id = ? LIMIT 1'
            );
            $rs->execute([$raceId]);
            if ($row = $rs->fetch(PDO::FETCH_ASSOC)) $f['recal'] = $row;
        }
        unset($f);

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

    // ── Intelligence page (Coaching Intelligence Layer, Part 6) ────────────────

    /**
     * GET /app/coach/intelligence — the renamed + expanded Alerts page. Three sections:
     * Athlete Flags (coaching_intelligence_flags + engine_flags), Flagged for Review
     * (coach_adjustments awaiting a rule decision), and the Decision Library.
     */
    public static function intelligence(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $coachId = (int)Auth::userId();

        $intelFlags  = self::getIntelligenceFlags($coachId, $db);
        $engineFlags = self::getOpenFlags($coachId, $db, null, 100);
        // Enrich pace_recalibration engine flags with the side-by-side recal card data.
        foreach ($engineFlags as &$f) {
            if (($f['flag_type'] ?? '') !== 'pace_recalibration') continue;
            $d = json_decode((string)($f['details'] ?? ''), true);
            $raceId = (int)($d['race_id'] ?? 0);
            if (!$raceId) continue;
            $rs = $db->prepare(
                'SELECT r.id, r.race_distance, r.result_time, r.race_date, r.proposed_pace_zones,
                        ap.pace_zones AS current_pace_zones
                 FROM races r JOIN athlete_profiles ap ON ap.athlete_id = r.athlete_id
                 WHERE r.id = ? LIMIT 1'
            );
            $rs->execute([$raceId]);
            if ($row = $rs->fetch(PDO::FETCH_ASSOC)) $f['recal'] = $row;
        }
        unset($f);

        $flaggedAdjustments = self::getFlaggedAdjustments($coachId, $db);
        $decisions          = self::getCoachDecisions($coachId, $db);

        // Phase 2: proposed rules, roster insights, weekly-review status, upcoming races.
        $proposedDecisions = self::getProposedDecisions($coachId, $db);
        $rosterInsights    = self::getRosterInsights($coachId, $db);
        $rosterNames       = self::rosterNameMap($coachId, $db);
        $upcomingRaces     = self::getUpcomingRaces($coachId, $db, 14);
        $weekStart         = self::currentWeekStart();
        $review            = self::getWeeklyReview($coachId, $weekStart, $db);
        $reviewItemCount   = count($proposedDecisions) + count($flaggedAdjustments) + count($rosterInsights);
        $reviewEstMinutes  = self::estimateReviewMinutes(count($proposedDecisions), count($flaggedAdjustments), count($rosterInsights));

        // Phase 4: multi-coach surfaces (all dormant with a single sole coach).
        $multiCoach = CoachAssignments::multiCoach($db);
        $viewerRole = Auth::role();
        $isHeadCoach = in_array($viewerRole, ['coach','admin'], true);
        $assistantProposals = ($multiCoach && $isHeadCoach) ? self::getAssistantProposals($coachId, $db) : [];
        $canImportPlaybook = $multiCoach && !self::coachHasOwnDecisions($coachId, $db)
            && self::foundingCoachId($coachId, $db) !== null;

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = count($engineFlags) + count($intelFlags);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $pageTitle = 'Intelligence';
        $activeNav = 'intelligence';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/intelligence.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /** GET /app/coach/alerts — backwards-compatible redirect to the Intelligence page. */
    public static function alertsRedirect(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        header('Location: /app/coach/intelligence');
        exit;
    }

    /** Open coaching_intelligence_flags for this coach's athletes, severity-ordered. */
    private static function getIntelligenceFlags(int $coachId, PDO $db): array
    {
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT cif.*, u.name AS athlete_name
             FROM coaching_intelligence_flags cif
             JOIN athletes a ON a.id = cif.athlete_id AND ' . $scope . '
             JOIN users u ON u.id = a.user_id
             WHERE cif.status = "open"
             ORDER BY FIELD(cif.severity,"warning","opportunity","info"), cif.created_at DESC
             LIMIT 200'
        );
        $stmt->execute($sp);
        return $stmt->fetchAll();
    }

    /** coach_adjustments flagged for review and not yet turned into a rule. */
    private static function getFlaggedAdjustments(int $coachId, PDO $db): array
    {
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT ca.*, u.name AS athlete_name
             FROM coach_adjustments ca
             JOIN athletes a ON a.id = ca.athlete_id AND ' . $scope . '
             JOIN users u ON u.id = a.user_id
             WHERE ca.flagged_for_review = 1 AND ca.coaching_decision_id IS NULL
             ORDER BY ca.adjusted_at DESC
             LIMIT 100'
        );
        $stmt->execute($sp);
        return $stmt->fetchAll();
    }

    /** Coaching decisions authored by this coach (the Decision Library). */
    private static function getCoachDecisions(int $coachId, PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM coaching_decisions WHERE created_by = ? ORDER BY id DESC LIMIT 200'
        );
        $stmt->execute([$coachId]);
        return $stmt->fetchAll();
    }

    /** POST /app/coach/intelligence/flag/{id}/dismiss — dismiss a coaching_intelligence_flag. */
    public static function dismissIntelligenceFlag(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId = (int)Auth::userId();
        $flagId  = (int)($params['id'] ?? 0);
        $db      = Database::get();

        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT cif.id FROM coaching_intelligence_flags cif
             JOIN athletes a ON a.id = cif.athlete_id AND ' . $scope . '
             WHERE cif.id = ? LIMIT 1'
        );
        $stmt->execute(array_merge($sp, [$flagId]));
        if ($stmt->fetchColumn()) {
            $db->prepare('UPDATE coaching_intelligence_flags SET status="dismissed", dismissed_at=NOW() WHERE id=?')
               ->execute([$flagId]);
        }
        header('Location: /app/coach/intelligence');
        exit;
    }

    /** POST /app/coach/intelligence/adjustment/{id}/dismiss — unflag without creating a rule. */
    public static function dismissAdjustment(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId = (int)Auth::userId();
        $adjId   = (int)($params['id'] ?? 0);
        $db      = Database::get();

        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT ca.id FROM coach_adjustments ca
             JOIN athletes a ON a.id = ca.athlete_id AND ' . $scope . '
             WHERE ca.id = ? LIMIT 1'
        );
        $stmt->execute(array_merge($sp, [$adjId]));
        if ($stmt->fetchColumn()) {
            $db->prepare('UPDATE coach_adjustments SET flagged_for_review = 0 WHERE id = ?')->execute([$adjId]);
        }
        header('Location: ' . self::intelReturn());
        exit;
    }

    /**
     * POST /app/coach/intelligence/adjustment/{id}/rule — turn a flagged adjustment into a
     * coaching decision. trigger_json / action_json are auto-generated from the adjustment;
     * the coach supplies a title, a reason, and the scope (distances / phases).
     */
    public static function saveDecision(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId = (int)Auth::userId();
        $adjId   = (int)($params['id'] ?? 0);
        $db      = Database::get();

        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT ca.* FROM coach_adjustments ca
             JOIN athletes a ON a.id = ca.athlete_id AND ' . $scope . '
             WHERE ca.id = ? LIMIT 1'
        );
        $stmt->execute(array_merge($sp, [$adjId]));
        $adj = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$adj) { header('Location: ' . self::intelReturn()); exit; }

        $title  = trim((string)($_POST['title'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($title === '')  $title  = 'Coaching rule';
        if ($reason === '') {
            $_SESSION['flash_error'] = 'A reason is required to save a coaching rule.';
            header('Location: ' . self::intelReturn());
            exit;
        }

        // Scope from the modal (fall back to the captured context).
        $distances = array_values(array_filter(array_map('strval', (array)($_POST['distances'] ?? []))));
        $phases    = array_values(array_filter(array_map('strval', (array)($_POST['phases'] ?? []))));
        if (!$distances && $adj['ctx_goal_distance'] !== null && $adj['ctx_goal_distance'] !== '') {
            $distances = [(string)$adj['ctx_goal_distance']];
        }
        if (!$phases && $adj['ctx_phase'] !== null && $adj['ctx_phase'] !== '') {
            $phases = [(string)$adj['ctx_phase']];
        }

        // trigger_json — goal_distance + phase + classification (only non-empty keys).
        $trigger = [];
        if ($distances) $trigger['goal_distance'] = $distances;
        if ($phases)    $trigger['phase'] = $phases;
        if ($adj['ctx_classification'] !== null && $adj['ctx_classification'] !== '') {
            $trigger['classification'] = [(string)$adj['ctx_classification']];
        }

        // action_json — derived from the change type.
        $action = [];
        switch ((string)$adj['change_type']) {
            case 'archetype_substitution':
                if (!empty($adj['before_archetype_code'])) $action['exclude_archetypes'] = [(string)$adj['before_archetype_code']];
                if (!empty($adj['after_archetype_code']))  $action['weight_multipliers'] = [(string)$adj['after_archetype_code'] => 2];
                break;
            case 'duration_change':
                $delta = (int)($adj['after_duration_mins'] ?? 0) - (int)($adj['before_duration_mins'] ?? 0);
                $action['duration_adjustment'] = $delta;
                break;
            case 'day_swap':
            default:
                $action = []; // scheduling/other preference — no archetype-pool effect
                break;
        }

        // Assistant coaches PROPOSE (head coach approves); head coaches/admins save active.
        $isAssistant = (Auth::role() === 'assistant_coach');
        $status = $isAssistant ? 'proposed_by_assistant' : 'active';

        $db->prepare(
            'INSERT INTO coaching_decisions
              (created_by, created_at, status, title, reason, rationale, trigger_json, action_json,
               scope_distances, scope_phases, scope_plan_types, source)
             VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, NULL, "proposed_from_adjustment")'
        )->execute([
            $coachId, $status, $title, $reason, $reason,
            json_encode($trigger ?: (object)[]),
            json_encode($action ?: (object)[]),
            $distances ? json_encode($distances) : null,
            $phases ? json_encode($phases) : null,
        ]);
        $decisionId = (int)$db->lastInsertId();

        $db->prepare('UPDATE coach_adjustments SET coaching_decision_id = ?, flagged_for_review = 0 WHERE id = ?')
           ->execute([$decisionId, $adjId]);

        $_SESSION['flash_success'] = $isAssistant
            ? 'Coaching rule proposed. Your head coach will review it.'
            : 'Coaching rule saved and activated.';
        header('Location: ' . self::intelReturn());
        exit;
    }

    /** POST /app/coach/intelligence/decision/{id}/toggle — flip active ↔ inactive. */
    public static function toggleDecision(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId    = (int)Auth::userId();
        $decisionId = (int)($params['id'] ?? 0);
        $db         = Database::get();

        // Only flips a coach's own active↔inactive rules. Proposed / proposed_by_assistant
        // rows are activated via the approve flow, never this toggle (prevents an assistant
        // self-approving their own proposal).
        $stmt = $db->prepare('SELECT status FROM coaching_decisions WHERE id = ? AND created_by = ? LIMIT 1');
        $stmt->execute([$decisionId, $coachId]);
        $status = $stmt->fetchColumn();
        if ($status === 'active' || $status === 'inactive') {
            $next = ($status === 'active') ? 'inactive' : 'active';
            $db->prepare('UPDATE coaching_decisions SET status = ?, updated_at = NOW() WHERE id = ?')
               ->execute([$next, $decisionId]);
        }
        header('Location: /app/coach/intelligence');
        exit;
    }

    // ── Multi-coach support (Coaching Intelligence Layer, Phase 4) ─────────────

    /** Earliest active head coach / admin other than $exceptId — the founding playbook source. */
    private static function foundingCoachId(int $exceptId, PDO $db): ?int
    {
        $stmt = $db->prepare(
            "SELECT id FROM users WHERE active = 1 AND role IN ('coach','admin') AND id <> ? ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$exceptId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** Has this coach authored any decisions of their own yet? (Gates the one-time import.) */
    private static function coachHasOwnDecisions(int $coachId, PDO $db): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM coaching_decisions WHERE created_by = ? LIMIT 1');
        $stmt->execute([$coachId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Assistant-proposed decisions awaiting this head coach's review (from assistants they manage). */
    private static function getAssistantProposals(int $coachId, PDO $db): array
    {
        $stmt = $db->prepare(
            "SELECT cd.*, u.name AS author_name
             FROM coaching_decisions cd
             JOIN users u ON u.id = cd.created_by
             WHERE cd.status = 'proposed_by_assistant'
               AND cd.created_by IN (SELECT id FROM users WHERE managed_by = ? AND role = 'assistant_coach')
             ORDER BY cd.id DESC LIMIT 100"
        );
        $stmt->execute([$coachId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * POST /app/coach/intelligence/decision/:id/share — toggle a head coach's own ACTIVE
     * decision shared on/off (roster-wide). Head coach / admin only; dormancy-gated.
     */
    public static function shareDecision(array $params): void
    {
        Auth::requireRole(['coach','admin']);
        Auth::verifyCsrf();

        $coachId = (int)Auth::userId();
        $id      = (int)($params['id'] ?? 0);
        $db      = Database::get();

        if (CoachAssignments::multiCoach($db)) {
            $db->prepare(
                "UPDATE coaching_decisions SET shared = 1 - shared, updated_at = NOW()
                 WHERE id = ? AND created_by = ? AND status = 'active'"
            )->execute([$id, $coachId]);
        }
        header('Location: /app/coach/intelligence');
        exit;
    }

    /** POST /app/coach/intelligence/proposal/:id/approve — head coach approves an assistant proposal → active. */
    public static function approveAssistantProposal(array $params): void
    {
        Auth::requireRole(['coach','admin']);
        Auth::verifyCsrf();
        self::actionAssistantProposal((int)($params['id'] ?? 0), 'active', 'Assistant proposal approved and activated.');
    }

    /** POST /app/coach/intelligence/proposal/:id/dismiss — head coach dismisses an assistant proposal → inactive. */
    public static function dismissAssistantProposal(array $params): void
    {
        Auth::requireRole(['coach','admin']);
        Auth::verifyCsrf();
        self::actionAssistantProposal((int)($params['id'] ?? 0), 'inactive', 'Assistant proposal dismissed.');
    }

    /** Shared body for approve/dismiss of an assistant proposal owned by one of this head coach's assistants. */
    private static function actionAssistantProposal(int $id, string $newStatus, string $flash): void
    {
        $coachId = (int)Auth::userId();
        $db      = Database::get();
        $stmt = $db->prepare(
            "SELECT cd.id, cd.reason FROM coaching_decisions cd
             WHERE cd.id = ? AND cd.status = 'proposed_by_assistant'
               AND cd.created_by IN (SELECT id FROM users WHERE managed_by = ? AND role = 'assistant_coach') LIMIT 1"
        );
        $stmt->execute([$id, $coachId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $db->prepare('UPDATE coaching_decisions SET status = ?, rationale = COALESCE(rationale, reason), updated_at = NOW() WHERE id = ?')
               ->execute([$newStatus, $id]);
            $_SESSION['flash_success'] = $flash;
        }
        header('Location: /app/coach/intelligence');
        exit;
    }

    /**
     * POST /app/coach/intelligence/import-playbook — one-time import of the founding coach's
     * ACTIVE decisions (shared AND non-shared alike) as editable 'proposed' copies owned by
     * this coach. Originals (and their shared flags) are untouched. Dormancy-gated; only when
     * the coach has no decisions of their own yet.
     */
    public static function importPlaybook(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId = (int)Auth::userId();
        $db      = Database::get();

        if (!CoachAssignments::multiCoach($db) || self::coachHasOwnDecisions($coachId, $db)) {
            header('Location: /app/coach/intelligence'); exit;
        }
        $sourceId = self::foundingCoachId($coachId, $db);
        if ($sourceId === null) { header('Location: /app/coach/intelligence'); exit; }

        $src = $db->prepare(
            "SELECT title, reason, rationale, trigger_json, action_json, scope_distances, scope_phases, scope_plan_types
             FROM coaching_decisions WHERE created_by = ? AND status = 'active' ORDER BY id ASC"
        );
        $src->execute([$sourceId]);
        $rows = $src->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ins = $db->prepare(
            "INSERT INTO coaching_decisions
               (created_by, created_at, status, shared, title, reason, rationale,
                trigger_json, action_json, scope_distances, scope_phases, scope_plan_types, source)
             VALUES (?, NOW(), 'proposed', 0, ?, ?, ?, ?, ?, ?, ?, ?, 'manual')"
        );
        $n = 0;
        foreach ($rows as $r) {
            $ins->execute([
                $coachId, $r['title'], $r['reason'], ($r['rationale'] ?? $r['reason']),
                $r['trigger_json'], $r['action_json'], $r['scope_distances'], $r['scope_phases'], $r['scope_plan_types'],
            ]);
            $n++;
        }
        $_SESSION['flash_success'] = $n > 0
            ? "Imported {$n} rule" . ($n === 1 ? '' : 's') . " as proposals — review and activate the ones you want."
            : 'No active rules to import yet.';
        header('Location: /app/coach/intelligence');
        exit;
    }

    /**
     * GET /app/coach/intelligence/philosophy — print-styled coaching philosophy export:
     * this coach's active decisions (own + shared decisions they rely on) with plain-prose
     * trigger/action and rationale. Available to any coach (NOT dormancy-gated).
     */
    public static function philosophyExport(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $coachId = (int)Auth::userId();

        // Own active decisions + active shared decisions from anyone (the ones that apply).
        $stmt = $db->prepare(
            "SELECT cd.*, u.name AS author_name
             FROM coaching_decisions cd
             JOIN users u ON u.id = cd.created_by
             WHERE cd.status = 'active' AND (cd.created_by = ? OR cd.shared = 1)
             ORDER BY (cd.created_by = ?) DESC, cd.shared ASC, cd.id ASC"
        );
        $stmt->execute([$coachId, $coachId]);
        $decisions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $coachName = (string)(Auth::name() ?? 'Coach');
        include __DIR__ . '/../../views/coach/philosophy_export.php';
    }

    // ── Weekly review (Coaching Intelligence Layer, Phase 2) ───────────────────

    /** Monday (ISO week start) of the current week, as Y-m-d. */
    private static function currentWeekStart(): string
    {
        return date('Y-m-d', strtotime('monday this week'));
    }

    /** Where a proposed-decision / insight / adjustment action returns to. Forms in the
     *  weekly-review flow post from=review; the Intelligence page / library default to it. */
    private static function intelReturn(): string
    {
        return (($_POST['from'] ?? '') === 'review') ? '/app/coach/intelligence/review' : '/app/coach/intelligence';
    }

    /** The weekly_review_log row for this coach + week, or null. */
    private static function getWeeklyReview(int $coachId, string $weekStart, PDO $db): ?array
    {
        $stmt = $db->prepare('SELECT * FROM weekly_review_log WHERE coach_id = ? AND week_start = ? LIMIT 1');
        $stmt->execute([$coachId, $weekStart]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Proposed coaching_decisions for this coach, highest-evidence first. */
    private static function getProposedDecisions(int $coachId, PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM coaching_decisions
             WHERE created_by = ? AND status = "proposed"
             ORDER BY proposed_from_count DESC, id DESC LIMIT 200'
        );
        $stmt->execute([$coachId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Open roster insights for this coach, severity-ordered. */
    private static function getRosterInsights(int $coachId, PDO $db, int $limit = 200): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM coach_roster_insights
             WHERE coach_id = ? AND status = "open"
             ORDER BY FIELD(severity,"warning","opportunity","info"), created_at DESC
             LIMIT ' . (int)$limit
        );
        $stmt->execute([$coachId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** athlete_id => name map for this coach's scope (resolves insight athlete pills). */
    private static function rosterNameMap(int $coachId, PDO $db): array
    {
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT a.id, u.name FROM athletes a JOIN users u ON u.id = a.user_id WHERE ' . $scope
        );
        $stmt->execute($sp);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $map[(int)$r['id']] = (string)$r['name']; }
        return $map;
    }

    /** Estimated review minutes: 1 min/proposal + 30s/flagged adj + 30s/insight, clamped 2..15. */
    private static function estimateReviewMinutes(int $proposed, int $flagged, int $insights): int
    {
        $secs = $proposed * 60 + $flagged * 30 + $insights * 30;
        $mins = (int)ceil($secs / 60);
        return max(2, min(15, $mins));
    }

    /**
     * GET /app/coach/intelligence/review — the focused single-page weekly review.
     * Sections: proposed decisions, roster insights, flagged adjustments, upcoming
     * races, and a complete-review footer.
     */
    public static function intelligenceReview(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $coachId = (int)Auth::userId();

        $proposedDecisions  = self::getProposedDecisions($coachId, $db);
        $rosterInsights     = self::getRosterInsights($coachId, $db);
        $flaggedAdjustments = self::getFlaggedAdjustments($coachId, $db);
        $upcomingRaces      = self::getUpcomingRaces($coachId, $db, 14);
        $rosterNames        = self::rosterNameMap($coachId, $db);

        $weekStart  = self::currentWeekStart();
        $review     = self::getWeeklyReview($coachId, $weekStart, $db);
        $estMinutes = self::estimateReviewMinutes(count($proposedDecisions), count($flaggedAdjustments), count($rosterInsights));
        $itemsCount = count($proposedDecisions) + count($flaggedAdjustments) + count($rosterInsights);

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $pageTitle = 'Weekly review';
        $activeNav = 'intelligence';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/intelligence_review.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /**
     * POST /app/coach/intelligence/decision/:id/approve — approve a proposed rule.
     * Sets status=active. A reason is required from the review flow; the inline
     * library button auto-fills one from the proposal evidence.
     */
    public static function approveDecision(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId = (int)Auth::userId();
        $id      = (int)($params['id'] ?? 0);
        $db      = Database::get();
        $back    = self::intelReturn();

        $stmt = $db->prepare(
            'SELECT id, title, proposed_from_count FROM coaching_decisions
             WHERE id = ? AND created_by = ? AND status = "proposed" LIMIT 1'
        );
        $stmt->execute([$id, $coachId]);
        $d = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$d) { header('Location: ' . $back); exit; }

        $reason = trim((string)($_POST['reason'] ?? ''));
        $title  = trim((string)($_POST['title'] ?? ''));
        if ($title === '') $title = (string)$d['title'];

        if ($reason === '') {
            if (!empty($_POST['require_reason'])) {
                $_SESSION['flash_error'] = 'A reason is required to approve a proposed rule.';
                header('Location: ' . $back); exit;
            }
            $reason = 'Approved from a proposed pattern based on ' . (int)$d['proposed_from_count'] . ' similar adjustments.';
        }

        $db->prepare('UPDATE coaching_decisions SET status = "active", title = ?, reason = ?, rationale = ?, updated_at = NOW() WHERE id = ?')
           ->execute([$title, $reason, $reason, $id]);
        $_SESSION['flash_success'] = 'Coaching rule approved and activated.';
        header('Location: ' . $back);
        exit;
    }

    /**
     * POST /app/coach/intelligence/decision/:id/modify — approve a proposed rule with
     * edits from the full modal (title, reason, distance/phase scope). Sets status=active.
     */
    public static function modifyDecision(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId = (int)Auth::userId();
        $id      = (int)($params['id'] ?? 0);
        $db      = Database::get();
        $back    = self::intelReturn();

        $stmt = $db->prepare(
            'SELECT * FROM coaching_decisions WHERE id = ? AND created_by = ? AND status = "proposed" LIMIT 1'
        );
        $stmt->execute([$id, $coachId]);
        $d = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$d) { header('Location: ' . $back); exit; }

        $title  = trim((string)($_POST['title'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($title === '') $title = (string)$d['title'];
        if ($reason === '') {
            $_SESSION['flash_error'] = 'A reason is required to save a coaching rule.';
            header('Location: ' . $back); exit;
        }

        $distances = array_values(array_filter(array_map('strval', (array)($_POST['distances'] ?? []))));
        $phases    = array_values(array_filter(array_map('strval', (array)($_POST['phases'] ?? []))));

        // Rebuild trigger_json from the edited scope, preserving any captured classification.
        $trigger = json_decode((string)($d['trigger_json'] ?? ''), true) ?: [];
        unset($trigger['goal_distance'], $trigger['phase']);
        if ($distances) $trigger['goal_distance'] = $distances;
        if ($phases)    $trigger['phase'] = $phases;

        $db->prepare(
            'UPDATE coaching_decisions
             SET status = "active", title = ?, reason = ?, rationale = ?, trigger_json = ?,
                 scope_distances = ?, scope_phases = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([
            $title, $reason, $reason, json_encode($trigger ?: (object)[]),
            $distances ? json_encode($distances) : null,
            $phases ? json_encode($phases) : null,
            $id,
        ]);
        $_SESSION['flash_success'] = 'Coaching rule updated and activated.';
        header('Location: ' . $back);
        exit;
    }

    /** POST /app/coach/intelligence/decision/:id/dismiss — dismiss a proposed rule (status=inactive). */
    public static function dismissProposedDecision(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId = (int)Auth::userId();
        $id      = (int)($params['id'] ?? 0);
        $db      = Database::get();
        $back    = self::intelReturn();

        $db->prepare(
            'UPDATE coaching_decisions SET status = "inactive", updated_at = NOW()
             WHERE id = ? AND created_by = ? AND status = "proposed"'
        )->execute([$id, $coachId]);
        header('Location: ' . $back);
        exit;
    }

    /** POST /app/coach/intelligence/insight/:id/dismiss — dismiss a roster insight. */
    public static function dismissRosterInsight(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId = (int)Auth::userId();
        $id      = (int)($params['id'] ?? 0);
        $db      = Database::get();
        $back    = self::intelReturn();

        $db->prepare(
            'UPDATE coach_roster_insights SET status = "dismissed", dismissed_at = NOW()
             WHERE id = ? AND coach_id = ?'
        )->execute([$id, $coachId]);
        header('Location: ' . $back);
        exit;
    }

    /** Open coaching_intelligence_flags for one athlete (predictive + Phase 1), severity-ordered. */
    private static function getOpenIntelFlagsForAthlete(int $athleteId, PDO $db): array
    {
        try {
            $stmt = $db->prepare(
                'SELECT * FROM coaching_intelligence_flags
                 WHERE athlete_id = ? AND status = "open"
                 ORDER BY FIELD(severity,"warning","opportunity","info"), created_at DESC
                 LIMIT 50'
            );
            $stmt->execute([$athleteId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * POST /app/coach/intelligence/flag/:id/adapt-accept — accept an adaptation_ahead
     * proposal. Creates a pending plan_regeneration_request (the EXISTING approval flow)
     * and marks the flag actioned. Phase 3 NEVER calls PlanGenerator itself.
     */
    public static function acceptAdaptation(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId = (int)Auth::userId();
        $flagId  = (int)($params['id'] ?? 0);
        $db      = Database::get();

        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT cif.id, cif.athlete_id FROM coaching_intelligence_flags cif
             JOIN athletes a ON a.id = cif.athlete_id AND ' . $scope . '
             WHERE cif.id = ? AND cif.flag_type = "adaptation_ahead" AND cif.status = "open" LIMIT 1'
        );
        $stmt->execute(array_merge($sp, [$flagId]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $back = (($_POST['from'] ?? '') === 'athlete' && $row)
            ? '/app/coach/athlete/' . (int)$row['athlete_id']
            : self::intelReturn();

        if (!$row) { header('Location: ' . $back); exit; }
        $athleteId = (int)$row['athlete_id'];

        // Route through the existing regeneration approval flow — no autonomous generation.
        $exists = $db->prepare('SELECT 1 FROM plan_regeneration_requests WHERE athlete_id = ? AND status = "pending" LIMIT 1');
        $exists->execute([$athleteId]);
        if (!$exists->fetchColumn()) {
            $db->prepare(
                'INSERT INTO plan_regeneration_requests (athlete_id, requested_by, requested_at, status, notes)
                 VALUES (?, ?, NOW(), "pending", ?)'
            )->execute([$athleteId, $coachId, 'Adaptation ahead of schedule — proposed by Coaching Intelligence (Phase 3).']);
        }
        $db->prepare('UPDATE coaching_intelligence_flags SET status = "actioned", actioned_at = NOW() WHERE id = ?')->execute([$flagId]);

        $_SESSION['flash_success'] = 'Plan-regeneration request created. Approve it from the athlete page to advance the plan.';
        header('Location: ' . $back);
        exit;
    }

    /**
     * POST /app/coach/intelligence/review/complete — mark this week's review complete.
     * Records the workload reviewed and counts decisions/flags actioned since Monday.
     */
    public static function completeReview(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $coachId   = (int)Auth::userId();
        $db        = Database::get();
        $weekStart = self::currentWeekStart();

        $itemsReviewed = max(0, (int)($_POST['items_reviewed'] ?? 0));

        // Decisions activated this week (approvals + manual add-as-rule).
        $decisionsAdded = (int)self::reviewScalar($db,
            'SELECT COUNT(*) FROM coaching_decisions
             WHERE created_by = ? AND status = "active" AND COALESCE(updated_at, created_at) >= ?',
            [$coachId, $weekStart]);

        [$scope, $sp] = self::athleteScope('a');
        $flagsActioned = (int)self::reviewScalar($db,
            'SELECT COUNT(*) FROM coaching_intelligence_flags cif
             JOIN athletes a ON a.id = cif.athlete_id AND ' . $scope . '
             WHERE cif.actioned_at >= ?', array_merge($sp, [$weekStart]));
        $flagsDismissed = (int)self::reviewScalar($db,
            'SELECT COUNT(*) FROM coaching_intelligence_flags cif
             JOIN athletes a ON a.id = cif.athlete_id AND ' . $scope . '
             WHERE cif.dismissed_at >= ?', array_merge($sp, [$weekStart]));

        $db->prepare(
            'INSERT INTO weekly_review_log
               (coach_id, week_start, completed_at, items_reviewed, decisions_added, flags_actioned, flags_dismissed)
             VALUES (?, ?, NOW(), ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               completed_at = NOW(), items_reviewed = VALUES(items_reviewed),
               decisions_added = VALUES(decisions_added), flags_actioned = VALUES(flags_actioned),
               flags_dismissed = VALUES(flags_dismissed)'
        )->execute([$coachId, $weekStart, $itemsReviewed, $decisionsAdded, $flagsActioned, $flagsDismissed]);

        $_SESSION['flash_success'] = 'Weekly review marked complete. Nice work.';
        header('Location: /app/coach/intelligence');
        exit;
    }

    private static function reviewScalar(PDO $db, string $sql, array $params)
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public static function dismissFlag(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $flagId  = (int)($params['id'] ?? 0);
        $coachId = Auth::userId();
        $db      = Database::get();
        $reason  = trim($_POST['dismiss_reason'] ?? '');

        // Verify flag belongs to one of this user's athletes
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT ef.id, ef.severity FROM engine_flags ef
             JOIN athletes a ON a.id = ef.athlete_id AND ' . $scope . '
             WHERE ef.id = ? LIMIT 1'
        );
        $stmt->execute(array_merge($sp, [$flagId]));
        $flag = $stmt->fetch();

        // Assistant coaches may only dismiss info-level flags (spec Part 4).
        if ($flag && Auth::role() === 'assistant_coach' && ($flag['severity'] ?? '') !== 'info') {
            http_response_code(403);
            include __DIR__ . '/../../views/errors/403.php';
            exit;
        }

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

        // Filters. Type maps to the add-workout slot category (easy/long/quality/
        // recovery); phase to selection.phases; distance to selection.goal_distances
        // (ultra→marathon, mile→5K, since archetypes key on the selector distance).
        $filterType     = $_GET['type']     ?? '';
        $filterPhase    = $_GET['phase']    ?? '';
        $filterDistance = $_GET['distance'] ?? '';
        $filterSearch   = trim($_GET['q']   ?? '');

        $selector = new ArchetypeSelector($db);
        $codes = $db->query('SELECT code FROM workout_archetypes WHERE status = "active" ORDER BY workout_type, name')
                    ->fetchAll(PDO::FETCH_COLUMN);

        $all = [];
        foreach ($codes as $code) {
            $a = $selector->getByCode((string)$code);
            if ($a) $all[] = self::archetypeCard($a);
        }
        $totalCount = count($all);

        $distMatch = $filterDistance !== '' ? self::previewSelectorDistance($filterDistance) : '';

        $archetypes = array_values(array_filter($all, static function (array $a) use ($filterType, $filterPhase, $distMatch, $filterSearch): bool {
            if ($filterType !== '' && !in_array($filterType, $a['categories'], true)) return false;
            if ($filterPhase !== '' && !in_array($filterPhase, $a['phases'], true)) return false;
            if ($distMatch !== '' && !in_array($distMatch, $a['goal_distances'], true)) return false;
            if ($filterSearch !== '') {
                $hay = mb_strtolower($a['name'] . ' ' . $a['code'] . ' ' . $a['description']);
                if (mb_strpos($hay, mb_strtolower($filterSearch)) === false) return false;
            }
            return true;
        }));

        $pageTitle = 'Workout Library';
        $activeNav = 'library';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/library.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /**
     * Normalize a decoded workout_archetypes row into the flat shape the Library
     * view + detail panel consume. All values are derived (read-only) — archetypes
     * are managed via the seeder, never through this UI.
     */
    private static function archetypeCard(array $a): array
    {
        $gen       = $a['generation'] ?? [];
        $sel       = $a['selection']  ?? [];
        $slotTypes = $sel['slot_types'] ?? [];

        // Category buckets from slot_types (mirrors the add-workout picker filters).
        $cats = [];
        if (in_array('easy', $slotTypes, true))     $cats[] = 'easy';
        if (in_array('long_run', $slotTypes, true)) $cats[] = 'long';
        if (in_array('recovery', $slotTypes, true)) $cats[] = 'recovery';
        if (in_array('quality_primary', $slotTypes, true) || in_array('quality_secondary', $slotTypes, true)) $cats[] = 'quality';
        if (empty($cats)) {
            $cats[] = match ((string)($a['workout_type'] ?? 'easy')) {
                'easy' => 'easy', 'long' => 'long', 'recovery' => 'recovery', default => 'quality',
            };
        }

        $variants = [];
        foreach (($a['variants'] ?? []) as $v) {
            if (!isset($v['code'])) continue;
            $variants[] = [
                'code'             => (string)$v['code'],
                'name'             => (string)($v['name'] ?? $v['code']),
                'workout_type'     => (string)($v['workout_type'] ?? ($a['workout_type'] ?? '')),
                'description'      => (string)($v['description'] ?? ''),
                'intensity_factor' => isset($v['intensity_factor']) ? (float)$v['intensity_factor'] : null,
            ];
        }

        $prescModel = (string)($gen['prescription_model'] ?? '');
        $prescLabel = match (true) {
            str_starts_with($prescModel, 'time')     => 'Time-based',
            str_starts_with($prescModel, 'distance') => 'Distance-based',
            $prescModel !== ''                       => ucfirst(str_replace('_', ' ', $prescModel)),
            default                                  => '—',
        };

        return [
            'id'                   => (int)($a['id'] ?? 0),
            'code'                 => (string)($a['code'] ?? ''),
            'name'                 => (string)($a['name'] ?? $a['code'] ?? ''),
            'workout_type'         => (string)($a['workout_type'] ?? 'easy'),
            'description'          => (string)($a['description'] ?? ''),
            'description_template' => (string)($a['display']['description_template'] ?? ''),
            'intensity_factor'     => isset($gen['intensity_factor']) ? (float)$gen['intensity_factor'] : null,
            'prescription_model'   => $prescModel,
            'prescription_label'   => $prescLabel,
            'recovery_model'       => $gen['recovery_model'] ?? null,
            'phases'               => array_values($sel['phases'] ?? []),
            'goal_distances'       => array_values($sel['goal_distances'] ?? []),
            'plan_types'           => array_values($sel['plan_types'] ?? []),
            'slot_types'           => array_values($slotTypes),
            'min_classification'   => (string)($sel['min_classification'] ?? 'workable'),
            'categories'           => array_values(array_unique($cats)),
            'variants'             => $variants,
            'variant_count'        => count($variants),
            'parameters'           => $a['parameters'] ?? [],
        ];
    }

    /** Map a UI goal-distance choice to the selector distance archetypes key on. */
    private static function previewSelectorDistance(string $d): string
    {
        return match (strtolower(trim($d))) {
            '5k'       => '5K',
            '10k'      => '10K',
            'half'     => 'half',
            'marathon' => 'marathon',
            'mile'     => '5K',       // mile resolves to 5K archetypes (selectorDistance)
            'ultra'    => 'marathon', // ultras resolve to marathon archetypes
            default    => '5K',
        };
    }

    /**
     * GET /app/coach/library/preview — read-only archetype preview (JSON, no writes).
     * Runs the archetype through PlanGenerator's resolution pipeline with a throwaway
     * context so coaches can see what an athlete would be shown for any configuration.
     */
    public static function libraryPreview(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        header('Content-Type: application/json');

        $db = Database::get();
        $archetypeId = (int)($_GET['archetype_id'] ?? 0);

        $stmt = $db->prepare('SELECT code FROM workout_archetypes WHERE id = ? AND status = "active" LIMIT 1');
        $stmt->execute([$archetypeId]);
        $code = $stmt->fetchColumn();
        if (!$code) {
            http_response_code(404);
            echo json_encode(['error' => 'Archetype not found']);
            return;
        }

        $classification = ($_GET['classification'] ?? '') === 'well_trained' ? 'well_trained' : 'workable';
        $duration       = max(5, min(300, (int)($_GET['duration'] ?? 45)));
        $variant        = trim((string)($_GET['variant'] ?? '')) ?: null;
        $selDist        = self::previewSelectorDistance($_GET['goal_distance'] ?? 'marathon');

        try {
            $preview = PlanGenerator::previewArchetype((string)$code, $classification, $duration, $selDist, $variant, $db);
        } catch (\Throwable $e) {
            error_log('libraryPreview: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not generate preview']);
            return;
        }

        if ($preview === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Could not generate a preview for this archetype']);
            return;
        }

        echo json_encode(['preview' => $preview]);
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

    /**
     * POST /app/coach/invites/deactivate — disable an invite link the coach owns.
     * Body: { invite_id }. Sets deactivated_at so the link can no longer onboard
     * anyone (getValidInvite() filters on it). Idempotent and owner-scoped: only the
     * creating coach can deactivate, and only a link that isn't already deactivated.
     */
    public static function deactivateInvite(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $db       = Database::get();
        $coachId  = Auth::userId();
        $inviteId = (int)($_POST['invite_id'] ?? 0);

        // Owner-scoped + idempotent. Not gated on use_count: a multi-use link can be
        // partially used yet still active, which is exactly when a coach may want to
        // kill it. rowCount() tells us whether anything was actually deactivated.
        $stmt = $db->prepare(
            'UPDATE invite_links SET deactivated_at = NOW()
             WHERE id = ? AND created_by = ? AND deactivated_at IS NULL'
        );
        $stmt->execute([$inviteId, $coachId]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['flash_success'] = 'Invite link deactivated.';
        } else {
            $_SESSION['flash_error'] = "That invite link couldn't be deactivated.";
        }

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

        // Mark read + fetch the thread (shared with the unified inbox panels).
        [$messages, $planPhase] = self::loadThreadForPanel($db, $athleteId);

        $chrome       = self::athleteChromeData($athleteId, $db);
        $chromeActive = 'messages';

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $pageTitle = 'Messages: ' . h($athlete['name']);
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

        // Allow returning to the Log tab (or another coach athlete sub-page) when the
        // comment was posted from there, e.g. the off-plan thread on the Log card. Only
        // same-site coach athlete paths are honoured (no open redirect).
        $returnTo = (string)($_POST['return_to'] ?? '');
        if ($returnTo !== '' && preg_match('#^/app/coach/athlete/\d+(/[\w/-]*)?$#', $returnTo)) {
            $back = $returnTo;
        }

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

        // Athlete-facing neutrality (spec Part 4/7): assistant coach messages are
        // stored as 'coach' so the athlete sees no role distinction (sender name only)
        // and read/unread tracking, which keys on the 'coach' role, works correctly.
        $role = 'coach';

        // Save session note + post to thread as a session reply card (planned_workout_id
        // resolved from the completion so the workout thread sees coach replies — migration_022).
        $db->prepare(
            'INSERT INTO session_notes (completed_workout_id, planned_workout_id, athlete_id, author_id, author_role, body)
             VALUES (?, (SELECT planned_workout_id FROM completed_workouts WHERE id = ?), ?, ?, ?, ?)'
        )->execute([$cwId, $cwId, $athleteId, $coachId, $role, $body]);

        // Re-float (or create) the single session card for this workout — coach
        // replies fold into that card rather than spawning a new one.
        SessionThread::recordComment($db, $athleteId, $coachId, $role, $cwId, $body);

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
     * GET /app/coach/workout/{planned_workout_id}/thread — coach view of a single
     * workout's thread (parity with the athlete workout thread). Athlete is derived
     * from the planned workout; coach ownership is verified.
     */
    public static function workoutThread(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $coachId = (int)Auth::userId();
        $pwId    = (int)($params['id'] ?? 0);

        $pw = $db->prepare(
            'SELECT id, athlete_id, display_title, display_summary, scheduled_date, workout_type, target_duration
             FROM planned_workouts WHERE id = ? LIMIT 1'
        );
        $pw->execute([$pwId]);
        $workout = $pw->fetch(PDO::FETCH_ASSOC);
        if (!$workout) { http_response_code(404); include __DIR__ . '/../../views/errors/404.php'; return; }

        $athleteId = (int)$workout['athlete_id'];
        $athlete   = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) { http_response_code(404); include __DIR__ . '/../../views/errors/404.php'; return; }

        $notes          = AthleteController::loadWorkoutThreadNotes($db, $pwId);
        $otherPartyName = $athlete['name'] ?? 'Your athlete';
        $viewerId       = $coachId;
        $viewerRole     = 'coach';
        $composeAction  = '/app/coach/workout/' . $pwId . '/send';
        $pollUrl        = '/app/coach/workout/' . $pwId . '/thread/poll';
        $backUrl        = '/app/coach/athlete/' . $athleteId . '/messages';

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $pageTitle = 'Workout thread: ' . h($athlete['name']);
        $activeNav = 'athletes';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/messages/workout_thread.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /** GET /app/coach/workout/{id}/thread/poll — new notes for this workout thread (JSON). */
    public static function workoutThreadPoll(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        header('Content-Type: application/json');

        $db      = Database::get();
        $coachId = (int)Auth::userId();
        $pwId    = (int)($params['id'] ?? 0);

        $pw = $db->prepare('SELECT athlete_id FROM planned_workouts WHERE id = ? LIMIT 1');
        $pw->execute([$pwId]);
        $athleteId = (int)($pw->fetchColumn() ?: 0);
        if (!$athleteId || !self::getAthleteForCoach($athleteId, $coachId, $db)) { echo json_encode([]); exit; }

        $after = (int)($_GET['after'] ?? 0);
        $notes = AthleteController::loadWorkoutThreadNotesAfter($db, $pwId, $after);
        echo json_encode(AthleteController::serializeWorkoutNotes($notes, $coachId));
        exit;
    }

    /** POST /app/coach/workout/{planned_workout_id}/send — coach posts to a workout thread. */
    public static function sendWorkoutMessage(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $db      = Database::get();
        $coachId = (int)Auth::userId();
        $pwId    = (int)($params['id'] ?? 0);
        $body    = trim($_POST['body'] ?? '');

        $pw = $db->prepare('SELECT id, athlete_id, display_title FROM planned_workouts WHERE id = ? LIMIT 1');
        $pw->execute([$pwId]);
        $workout = $pw->fetch(PDO::FETCH_ASSOC);
        if (!$workout) { header('Location: /app/coach/athletes'); exit; }

        $athleteId = (int)$workout['athlete_id'];
        $athlete   = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete || $body === '' || mb_strlen($body) > 1000) {
            header('Location: ' . ($athlete ? '/app/coach/workout/' . $pwId . '/thread' : '/app/coach/athletes'));
            exit;
        }

        // Athlete-facing neutrality (spec Part 4/7): assistant coach messages are
        // stored as 'coach' so the athlete sees no role distinction (sender name only)
        // and read/unread tracking, which keys on the 'coach' role, works correctly.
        $role = 'coach';

        $cw = $db->prepare('SELECT id FROM completed_workouts WHERE planned_workout_id = ? AND athlete_id = ? ORDER BY id DESC LIMIT 1');
        $cw->execute([$pwId, $athleteId]);
        $cwId = (int)($cw->fetchColumn() ?: 0) ?: null;

        $db->prepare(
            'INSERT INTO session_notes (completed_workout_id, planned_workout_id, athlete_id, author_id, author_role, body)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$cwId, $pwId, $athleteId, $coachId, $role, $body]);

        SessionThread::recordCommentPlanned($db, $athleteId, $coachId, $role, $pwId, $cwId, $body);

        $ctx = Notifications::athleteContext($athleteId);
        if ($ctx['athlete_user_id']) {
            Notifications::send($ctx['athlete_user_id'], 'coach_session_comment', [
                'workout_name' => (string)($workout['display_title'] ?: 'your workout'),
                'workout_id'   => $pwId,
            ]);
        }

        header('Location: /app/coach/workout/' . $pwId . '/thread');
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
        $since = (int)($_GET['since'] ?? 0);

        // New messages by id, plus session cards re-floated since the client's
        // last-seen timestamp (a re-float keeps the card's id and only bumps sent_at).
        $floatSql = $since > 0 ? ' OR (m.message_type = "session_note" AND m.sent_at > FROM_UNIXTIME(?))' : '';
        $params   = $since > 0 ? [$athleteId, $after, $since] : [$athleteId, $after];

        $stmt = $db->prepare(
            'SELECT m.*,
                    COALESCE(cw.workout_type, pw2.workout_type)   AS session_type,
                    COALESCE(cw.activity_date, pw2.scheduled_date) AS session_date,
                    COALESCE(pw.display_title, pw2.display_title)  AS session_title
             FROM messages m
             LEFT JOIN completed_workouts cw ON cw.id = m.completed_workout_id
             LEFT JOIN planned_workouts pw  ON pw.id = cw.planned_workout_id
             LEFT JOIN planned_workouts pw2 ON pw2.id = m.planned_workout_id
             WHERE m.athlete_id = ? AND (m.id > ?' . $floatSql . ')
             ORDER BY m.sent_at ASC, m.id ASC
             LIMIT 100'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Coach is actively viewing — mark athlete messages read, including an athlete
        // reply that just re-floated an existing session card.
        $markSql    = $since > 0 ? ' OR (message_type = "session_note" AND sent_at > FROM_UNIXTIME(?))' : '';
        $markParams = $since > 0 ? [$athleteId, $after, $since] : [$athleteId, $after];
        $db->prepare(
            'UPDATE messages SET read_at = NOW()
             WHERE athlete_id = ? AND sender_role = "athlete" AND read_at IS NULL AND (id > ?' . $markSql . ')'
        )->execute($markParams);

        echo json_encode(self::serializeMessages($rows, $coachId));
        exit;
    }

    // ── Unified Messages tab ───────────────────────────────────

    /** GET /app/coach/messages — two-panel unified inbox (list + thread). */
    public static function unifiedMessages(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db      = Database::get();
        $coachId = (int)Auth::userId();

        $threads          = self::getMessageThreads($coachId, $db);
        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        // Optional deep-link / desktop preselect (?athlete=N) renders the thread
        // straight into the right panel and marks it read.
        $selectedId = (int)($_GET['athlete'] ?? 0);
        $athlete = null; $messages = []; $planPhase = null; $backUrl = '/app/coach/messages';
        if ($selectedId) {
            $athlete = self::getAthleteForCoach($selectedId, $coachId, $db);
            if ($athlete) { [$messages, $planPhase] = self::loadThreadForPanel($db, $selectedId); }
        }

        $pageTitle = 'Messages';
        $activeNav = 'messages';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/messages_unified.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /** GET /app/coach/messages/{id} — full-page thread (mobile entry; back → list). */
    public static function unifiedThread(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db        = Database::get();
        $coachId   = (int)Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) { http_response_code(404); include __DIR__ . '/../../views/errors/404.php'; return; }

        [$messages, $planPhase] = self::loadThreadForPanel($db, $athleteId);
        $backUrl = '/app/coach/messages';

        $athletes         = self::getRosterAthletes($coachId, $db);
        $openFlags        = self::getOpenFlagsCount($coachId, $db);
        $pendingApprovals = self::getPendingApprovalsCount($coachId, $db);

        $pageTitle = 'Messages: ' . h($athlete['name']);
        $activeNav = 'messages';
        include __DIR__ . '/../../views/layout/html_open.php';
        include __DIR__ . '/../../views/layout/nav_coach.php';
        include __DIR__ . '/../../views/coach/messages.php';
        include __DIR__ . '/../../views/layout/html_close.php';
    }

    /** GET /app/coach/messages/{id}/panel — thread fragment for desktop AJAX swap. */
    public static function messageThreadPanel(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';

        $db        = Database::get();
        $coachId   = (int)Auth::userId();
        $athleteId = (int)($params['id'] ?? 0);

        $athlete = self::getAthleteForCoach($athleteId, $coachId, $db);
        if (!$athlete) { http_response_code(404); echo 'Not found'; return; }

        [$messages, $planPhase] = self::loadThreadForPanel($db, $athleteId);
        $backUrl = '/app/coach/messages';
        include __DIR__ . '/../../views/coach/messages.php';   // fragment only — no layout
    }

    /** GET /app/coach/messages/unread-count — JSON {count} for the nav badge poll. */
    public static function unreadMessageCount(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        header('Content-Type: application/json');
        echo json_encode(['count' => self::navUnreadCount()]);
        exit;
    }

    /** GET /app/coach/messages/threads — JSON {count, threads} for the list refresh poll. */
    public static function messageThreads(): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        require_once __DIR__ . '/../../views/layout/base.php';
        header('Content-Type: application/json');

        $db      = Database::get();
        $coachId = (int)Auth::userId();
        $threads = self::getMessageThreads($coachId, $db);
        $count   = 0;
        foreach ($threads as $t) { $count += (int)$t['unread_count']; }
        echo json_encode(['count' => $count, 'threads' => $threads]);
        exit;
    }

    /** Total unread athlete messages across the coach's scoped athletes (nav badge). */
    public static function navUnreadCount(): int
    {
        $db = Database::get();
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM messages m
             JOIN athletes a ON a.id = m.athlete_id AND ' . $scope . '
             WHERE m.sender_role = "athlete" AND m.read_at IS NULL'
        );
        $stmt->execute($sp);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Athlete inbox rows: latest message preview + smart time label + unread count,
     * scoped to the coach and sorted by most-recent-message DESC (athletes with no
     * messages sort last, alphabetical). Requires base.php (avatar_initials).
     */
    private static function getMessageThreads(int $coachId, PDO $db): array
    {
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT a.id AS athlete_id, a.user_id AS athlete_user_id, u.name,
                    lm.body AS last_body, lm.sent_at AS last_sent,
                    UNIX_TIMESTAMP(lm.sent_at) AS last_ts, lm.sender_role AS last_role,
                    (SELECT COUNT(*) FROM messages mu
                       WHERE mu.athlete_id = a.id AND mu.sender_role = "athlete" AND mu.read_at IS NULL) AS unread_count
             FROM athletes a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN messages lm ON lm.id = (
                 SELECT m2.id FROM messages m2 WHERE m2.athlete_id = a.id
                 ORDER BY m2.sent_at DESC, m2.id DESC LIMIT 1
             )
             WHERE ' . $scope . ' AND a.status = "active"
             ORDER BY (lm.sent_at IS NULL), lm.sent_at DESC, u.name ASC'
        );
        $stmt->execute($sp);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $ts   = (int)($r['last_ts'] ?? 0);
            $body = trim((string)($r['last_body'] ?? ''));
            if ($body === '') {
                $preview = 'No messages yet';
            } else {
                $preview = mb_substr($body, 0, 60) . (mb_strlen($body) > 60 ? '…' : '');
                if (($r['last_role'] ?? '') !== 'athlete') $preview = 'You: ' . $preview;
            }
            $out[] = [
                'id'           => (int)$r['athlete_id'],
                'name'         => (string)$r['name'],
                'initials'     => avatar_initials((string)$r['name']),
                'preview'      => $preview,
                'ts'           => $ts,
                'time_label'   => self::messageListTimeLabel($r['last_sent'] ?? null, (int)$r['athlete_user_id']),
                'unread'       => ((int)$r['unread_count']) > 0,
                'unread_count' => (int)$r['unread_count'],
            ];
        }
        return $out;
    }

    /**
     * Inbox time label for a UTC message timestamp, evaluated in the athlete's own
     * timezone (users.timezone via Timezone): today → time ("2:34 PM"), yesterday →
     * "Yesterday", within the past week → weekday ("Tuesday"), older → date ("Jun 9").
     */
    private static function messageListTimeLabel(?string $sentUtc, int $athleteUserId): string
    {
        if ($sentUtc === null || $sentUtc === '') return '';
        try {
            $local = Timezone::toLocal($sentUtc, $athleteUserId);
        } catch (\Throwable $e) {
            return '';
        }
        $tz      = Timezone::tzString($athleteUserId);
        $msgDate = $local->format('Y-m-d');

        if ($msgDate === Timezone::dateInZone($tz, 'now'))      return $local->format('g:i A');   // today
        if ($msgDate === Timezone::dateInZone($tz, '-1 day'))   return 'Yesterday';
        // Last 7 calendar days (today inclusive); today/yesterday already handled, so
        // the -6 bound keeps weekday names unambiguous (no same-weekday-a-week-ago clash).
        if ($msgDate >= Timezone::dateInZone($tz, '-6 days'))   return $local->format('l');       // weekday
        return $local->format('M j');                                                              // older
    }

    /**
     * Mark an athlete's thread read and load it for a thread panel (shared by
     * coachMessages, the unified preselect, the AJAX panel, and the mobile page).
     * Returns [messages, planPhaseLabel].
     */
    private static function loadThreadForPanel(PDO $db, int $athleteId): array
    {
        $db->prepare(
            'UPDATE messages SET read_at = NOW() WHERE athlete_id = ? AND sender_role = "athlete" AND read_at IS NULL'
        )->execute([$athleteId]);

        $stmt = $db->prepare(
            'SELECT m.*,
                    COALESCE(cw.workout_type, pw2.workout_type)   AS session_type,
                    COALESCE(cw.activity_date, pw2.scheduled_date) AS session_date,
                    COALESCE(pw.display_title, pw2.display_title)  AS session_title,
                    u.name AS sender_name
             FROM messages m
             LEFT JOIN completed_workouts cw ON cw.id = m.completed_workout_id
             LEFT JOIN planned_workouts pw  ON pw.id = cw.planned_workout_id
             LEFT JOIN planned_workouts pw2 ON pw2.id = m.planned_workout_id
             LEFT JOIN users u ON u.id = m.sender_id
             WHERE m.athlete_id = ?
             ORDER BY m.sent_at ASC
             LIMIT 200'
        );
        $stmt->execute([$athleteId]);
        $messages  = $stmt->fetchAll();
        $planPhase = self::currentPlanPhase(self::getActivePlanDetail($athleteId, $db));
        return [$messages, $planPhase];
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
                'workout_name'       => (!empty($m['completed_workout_id']) || !empty($m['planned_workout_id']))
                    ? (trim((string)($m['session_title'] ?? '')) ?: (!empty($m['session_type']) ? ucfirst(str_replace('_', ' ', $m['session_type'])) : 'Session note')) : null,
                'completed_workout_id' => !empty($m['completed_workout_id']) ? (int)$m['completed_workout_id'] : null,
                'planned_workout_id' => !empty($m['planned_workout_id']) ? (int)$m['planned_workout_id'] : null,
                'reply_count'        => (int)($m['reply_count'] ?? 0),
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

    /**
     * PURE meta for the shared athlete-view chrome (header + tab strip): current phase,
     * plan week, next race, open-flag count, and the on-track dot colour (by top open-flag
     * severity). Reuses the existing getters — no new business logic. Identical on every
     * sub-page so the header/strip render the same everywhere.
     *
     * @return array{phase:?string, week:?int, total_weeks:?int, race:?array, flag_count:int, on_track:string}
     */
    public static function athleteChromeData(int $athleteId, PDO $db): array
    {
        $plan  = self::getActivePlanDetail($athleteId, $db);
        $phase = self::currentPlanPhase($plan);

        $week = null; $totalWeeks = null;
        if ($plan) {
            $start = strtotime((string)($plan['plan_start_date'] ?? ''));
            $end   = strtotime((string)($plan['plan_end_date'] ?? ''));
            if ($start !== false) {
                $w = (int)floor((time() - $start) / (7 * 86400)) + 1;
                if ($end !== false && $end > $start) {
                    $totalWeeks = (int)ceil(($end - $start) / (7 * 86400));
                    $w = max(1, min($w, $totalWeeks));
                } else {
                    $w = max(1, $w);
                }
                $week = $w;
            }
        }

        // Badge + dot count ALL open flags across both tables (same source as the Flags tab's
        // Open section), so the badge equals the Open list and the dot is the true top severity.
        $open = self::openFlagsForAthlete($athleteId, $db);

        return [
            'phase'       => $phase,
            'week'        => $week,
            'total_weeks' => $totalWeeks,
            'race'        => self::getNextRace($athleteId, $db),
            'flag_count'  => count($open),
            'on_track'    => self::topSeverityDot($open),
        ];
    }

    // ── Data helpers ───────────────────────────────────────────

    private static function getRosterAthletes(int $coachId, PDO $db): array
    {
        [$scope, $sp] = self::athleteScope('a');
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
                (SELECT COUNT(*) FROM messages m WHERE m.athlete_id=a.id AND m.sender_role="athlete" AND m.read_at IS NULL) as unread_messages,
                (SELECT COUNT(*) FROM plan_regeneration_requests prr WHERE prr.athlete_id=a.id AND prr.status="pending") as pending_regen
             FROM athletes a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN athlete_profiles ap ON ap.athlete_id = a.id
             WHERE ' . $scope . ' AND a.status = "active"
             ORDER BY u.name ASC'
        );
        $stmt->execute($sp);
        return $stmt->fetchAll();
    }

    private static function getOpenFlagsCount(int $coachId, PDO $db): int
    {
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM engine_flags ef
             JOIN athletes a ON a.id = ef.athlete_id AND ' . $scope . '
             WHERE ef.status = "open"'
        );
        $stmt->execute($sp);
        return (int)$stmt->fetchColumn();
    }

    private static function getPendingApprovalsCount(int $coachId, PDO $db): int
    {
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM plan_approval_queue paq
             JOIN athletes a ON a.id = paq.athlete_id AND ' . $scope . '
             WHERE paq.status = "pending"'
        );
        $stmt->execute($sp);
        return (int)$stmt->fetchColumn();
    }

    private static function getOpenFlags(int $coachId, PDO $db, ?string $severity = null, int $limit = 20): array
    {
        [$scope, $sp] = self::athleteScope('a');
        $sql = 'SELECT ef.*, u.name as athlete_name
                FROM engine_flags ef
                JOIN athletes a ON a.id = ef.athlete_id AND ' . $scope . '
                JOIN users u ON u.id = a.user_id
                WHERE ef.status = "open"';
        $params = $sp;
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
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT paq.*, tp.plan_type, tp.plan_start_date, tp.plan_end_date, u.name as athlete_name
             FROM plan_approval_queue paq
             JOIN training_plans tp ON tp.id = paq.plan_id
             JOIN athletes a ON a.id = paq.athlete_id AND ' . $scope . '
             JOIN users u ON u.id = a.user_id
             WHERE paq.status = "pending"
             ORDER BY paq.requested_at ASC
             LIMIT ' . $limit
        );
        $stmt->execute($sp);
        return $stmt->fetchAll();
    }

    private static function getUpcomingRaces(int $coachId, PDO $db, int $days): array
    {
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT r.*, u.name as athlete_name
             FROM races r
             JOIN athletes a ON a.id = r.athlete_id AND ' . $scope . '
             JOIN users u ON u.id = a.user_id
             WHERE r.race_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY r.race_date ASC'
        );
        $stmt->execute(array_merge($sp, [$days]));
        return $stmt->fetchAll();
    }

    private static function getUnreadMessageThreads(int $coachId, PDO $db): array
    {
        [$scope, $sp] = self::athleteScope('a');
        $stmt = $db->prepare(
            'SELECT m.athlete_id, u.name as athlete_name, m.body, m.sent_at
             FROM messages m
             JOIN athletes a ON a.id = m.athlete_id AND ' . $scope . '
             JOIN users u ON u.id = a.user_id
             WHERE m.sender_role = "athlete" AND m.read_at IS NULL
             GROUP BY m.athlete_id
             ORDER BY m.sent_at DESC
             LIMIT 10'
        );
        $stmt->execute($sp);
        return $stmt->fetchAll();
    }

    /** [sqlFragment, params] scoping `athletes a` to the current coach/assistant/admin. */
    private static function athleteScope(string $alias = 'a'): array
    {
        return CoachAssignments::scope((int)Auth::userId(), Auth::role(), $alias);
    }

    private static function getAthleteForCoach(int $athleteId, int $coachId, PDO $db): ?array
    {
        // Head coaches/admins resolve via coach_id (kept in sync); assistant coaches
        // via coach_assignments.assistant_coach_id. Admins additionally see everyone.
        [$scope, $sp] = self::athleteScope('a');
        $sql = 'SELECT a.*, u.name, u.email, u.theme_preference, u.timezone
                FROM athletes a JOIN users u ON u.id = a.user_id
                WHERE a.id = ? AND (' . $scope . ' OR ? IN (SELECT id FROM users WHERE role = "admin"))
                LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$athleteId], $sp, [$coachId]));
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
                    pw.archetype_code, pw.archetype_variant, pw.notes, pw.display_title, pw.display_summary, pw.athlete_instructions,
                    pw.display_title                                          AS template_name,
                    COALESCE(pw.athlete_instructions, pw.description, pw.display_summary, \'\') AS description,
                    pw.structure, pw.target_duration, pw.intensity_load,
                    pw.coach_locked, pw.visible_to_athlete, pw.added_by_role, pw.carried_over_from_plan_id,
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
                    ) AS completed_workout_id,
                    (
                        SELECT MAX(ca.flagged_for_review)
                        FROM coach_adjustments ca
                        WHERE ca.planned_workout_id = pw.id
                    ) AS flagged_for_review
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

<?php
/**
 * RaceController — tune-up + goal race management (architecture §26).
 *
 * Athletes and coaches add races; the engine patches the surrounding plan
 * (pre-race aerobic taper, no quality within 3 days, race-day skip, post-race
 * recovery — see PlanGenerator::applyRaceAdjustments). After a race date passes
 * the athlete logs a result, which proposes a pace-zone recalibration for the
 * coach to approve / modify / dismiss.
 */
class RaceController
{
    /** Valid stored race_distance ENUM values (form posts these directly). */
    private const DISTANCES = ['5K','10K','15K','half','marathon','50k','50_miler','100k','100_miler','other'];

    /** Athlete-facing label for a stored race_distance. */
    public static function distanceLabel(string $d): string
    {
        return [
            '5K' => '5K', '10K' => '10K', '15K' => '15K', 'half' => 'Half Marathon',
            'marathon' => 'Marathon', '50k' => '50K', '50_miler' => '50 Miler',
            '100k' => '100K', '100_miler' => '100 Miler', 'ultra' => 'Ultra', 'other' => 'Other',
        ][$d] ?? $d;
    }

    /** Value to store in athlete_profiles.goal_race_distance when syncing a goal race. */
    private static function profileDistanceValue(string $d): ?string
    {
        return [
            '5K' => '5K', '10K' => '10K', '15K' => '15K', 'half' => 'Half Marathon',
            'marathon' => 'Marathon', '50k' => '50k', '50_miler' => '50_miler',
            '100k' => '100k', '100_miler' => '100_miler',
        ][$d] ?? null; // ultra/other: leave the profile goal distance unchanged
    }

    /** Distance label PaceZones::fromRace can project from, or null (ultra/other). */
    private static function paceZoneDistance(string $d): ?string
    {
        return in_array($d, ['5K','10K','15K','half','marathon'], true) ? $d : null;
    }

    // ── Athlete entry ─────────────────────────────────────────────────────────

    /** POST /app/athlete/race/add */
    public static function addRace(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();

        $athlete = Auth::getAthlete();
        if (!$athlete) { header('Location: /app/plan'); exit; }
        $db = Database::get();

        $err = self::saveRace((int)$athlete['id'], (int)Auth::userId(), 'athlete', $_POST, $db);
        $_SESSION[$err ? 'flash_error' : 'flash_success'] = $err
            ?: 'Race added. Your coach has been notified and your plan will be adjusted around race week.';

        header('Location: /app/plan');
        exit;
    }

    /** POST /app/athlete/race/result — body: race_id, result_time (HH:MM:SS), result_notes */
    public static function logResult(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();

        $athlete = Auth::getAthlete();
        if (!$athlete) { header('Location: /app'); exit; }
        $db = Database::get();

        $raceId = (int)($_POST['race_id'] ?? 0);
        $race = self::ownedRace($raceId, (int)$athlete['id'], $db);
        if (!$race) { $_SESSION['flash_error'] = 'Race not found.'; header('Location: /app'); exit; }

        $seconds = self::parseHmsToSeconds((string)($_POST['result_time'] ?? ''));
        if ($seconds === null || $seconds <= 0) {
            $_SESSION['flash_error'] = 'Please enter a valid finish time as HH:MM:SS.';
            header('Location: /app');
            exit;
        }
        $notes = trim((string)($_POST['result_notes'] ?? '')) ?: null;

        // Propose a pace-zone recalibration when the distance is projectable.
        $proposed = null;
        $pzDist = self::paceZoneDistance((string)$race['race_distance']);
        if ($pzDist !== null && class_exists('PaceZones')) {
            $zones = PaceZones::fromRace($pzDist, $seconds);
            if ($zones) $proposed = json_encode($zones);
        }

        $db->prepare(
            'UPDATE races SET result_time = ?, result_notes = ?, recalibration_proposed = 1,
             proposed_pace_zones = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$seconds, $notes, $proposed, $raceId]);

        $name = (string)$race['race_name'];
        $dist = self::distanceLabel((string)$race['race_distance']);
        self::raiseFlag(
            (int)$athlete['id'], 'pace_recalibration', 'info',
            "Pace zone update available: " . self::athleteFirstName($athlete) . " ran "
            . self::secondsToHms($seconds) . " at {$dist} ({$name}). Review the proposed zone update.",
            ['race_id' => $raceId], $db
        );

        $_SESSION['flash_success'] = 'Result logged. Your coach will review your pace zones.';
        header('Location: /app');
        exit;
    }

    // ── Coach entry ─────────────────────────────────────────────────────────

    /** POST /app/coach/athlete/:id/race/add */
    public static function coachAddRace(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $athleteId = (int)($params['id'] ?? 0);
        $coachId   = (int)Auth::userId();
        $db        = Database::get();
        if (!self::coachOwns($athleteId, $coachId, $db)) {
            http_response_code(403); echo 'Forbidden'; exit;
        }

        $err = self::saveRace($athleteId, $coachId, (string)(Auth::role() ?? 'coach'), $_POST, $db);
        $_SESSION[$err ? 'flash_error' : 'flash_success'] = $err ?: 'Race added to the plan.';

        header('Location: /app/coach/athlete/' . $athleteId);
        exit;
    }

    /**
     * GET /app/coach/athlete/:id/race-conflicts?date=YYYY-MM-DD
     * Returns JSON { conflicts: ["…"] } — quality sessions within 7 days before the date.
     */
    public static function coachConflicts(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        header('Content-Type: application/json');

        $athleteId = (int)($params['id'] ?? 0);
        $coachId   = (int)Auth::userId();
        $db        = Database::get();
        if (!self::coachOwns($athleteId, $coachId, $db)) {
            http_response_code(403); echo json_encode(['conflicts' => []]); exit;
        }

        $date = trim((string)($_GET['date'] ?? ''));
        echo json_encode(['conflicts' => self::conflictsFor($athleteId, $date, $db)]);
        exit;
    }

    // ── Recalibration (coach) ──────────────────────────────────────────────────

    /** POST /app/coach/races/:id/recalibrate/approve — optional posted zone overrides (Modify). */
    public static function approveRecalibration(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $raceId  = (int)($params['id'] ?? 0);
        $coachId = (int)Auth::userId();
        $db      = Database::get();

        $race = self::coachRace($raceId, $coachId, $db);
        if (!$race) { header('Location: /app/coach/flags'); exit; }

        // Modify: a posted zones JSON overrides the engine proposal; else use the proposal.
        $zonesJson = trim((string)($_POST['zones_json'] ?? ''));
        $zones = $zonesJson !== '' ? json_decode($zonesJson, true) : json_decode((string)$race['proposed_pace_zones'], true);

        if (is_array($zones) && !empty($zones)) {
            $db->prepare('UPDATE athlete_profiles SET pace_zones = ?, pace_zones_source = "race_result" WHERE athlete_id = ?')
               ->execute([json_encode($zones), (int)$race['athlete_id']]);
        }

        $db->prepare(
            'UPDATE races SET recalibration_approved = 1, recalibration_approved_by = ?,
             recalibration_approved_at = NOW(), updated_at = NOW() WHERE id = ?'
        )->execute([$coachId, $raceId]);

        self::closeRecalibrationFlag($raceId, $coachId, 'acted_on', $db);

        $_SESSION['flash_success'] = 'Pace zones updated from race result.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/app/coach/flags'));
        exit;
    }

    /** POST /app/coach/races/:id/recalibrate/dismiss */
    public static function dismissRecalibration(array $params): void
    {
        Auth::requireRole(['coach','assistant_coach','admin']);
        Auth::verifyCsrf();

        $raceId  = (int)($params['id'] ?? 0);
        $coachId = (int)Auth::userId();
        $db      = Database::get();

        $race = self::coachRace($raceId, $coachId, $db);
        if (!$race) { header('Location: /app/coach/flags'); exit; }

        $note = trim((string)($_POST['dismiss_reason'] ?? '')) ?: null;
        $db->prepare(
            'UPDATE races SET recalibration_approved = 0, updated_at = NOW(),
             notes = CASE WHEN ? IS NULL THEN notes ELSE CONCAT(COALESCE(notes,""), ?) END WHERE id = ?'
        )->execute([$note, $note ? "\n[recalibration dismissed] " . $note : '', $raceId]);

        self::closeRecalibrationFlag($raceId, $coachId, 'dismissed', $db, $note);

        $_SESSION['flash_success'] = 'Recalibration dismissed. Pace zones left unchanged.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/app/coach/flags'));
        exit;
    }

    // ── Shared save path ────────────────────────────────────────────────────────

    /** Insert a race, raise flags (athlete only), sync goal race, re-run engine. Returns error string or ''. */
    private static function saveRace(int $athleteId, int $userId, string $role, array $post, PDO $db): string
    {
        $name = trim((string)($post['race_name'] ?? ''));
        $dist = (string)($post['race_distance'] ?? '');
        $date = trim((string)($post['race_date'] ?? ''));
        $isGoal = !empty($post['is_goal_race']) && $post['is_goal_race'] !== '0';

        if ($name === '') return 'Please enter a race name.';
        if (!in_array($dist, self::DISTANCES, true)) return 'Please choose a valid distance.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) return 'Please choose a valid date.';
        if ($date < date('Y-m-d')) return 'Race date must be in the future.';

        $override = null; $overrideUnit = null;
        if ($dist === 'other') {
            $override = (float)($post['custom_distance'] ?? 0);
            if ($override <= 0) return 'Please enter the distance for an "other" race.';
            $overrideUnit = in_array($post['custom_distance_unit'] ?? '', ['miles','km'], true)
                ? $post['custom_distance_unit'] : 'miles';
            // Store distance_override in miles for the engine; remember the entered unit.
            if ($overrideUnit === 'km') $override = round($override * 0.621371, 2);
        }

        $coachNote = ($role !== 'athlete') ? (trim((string)($post['coach_notes'] ?? '')) ?: null) : null;

        $db->prepare(
            'INSERT INTO races
             (athlete_id, added_by, added_by_role, race_name, race_distance, distance_override,
              distance_override_unit, race_date, is_goal_race, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $athleteId, $userId, $role, $name, $dist, $override, $overrideUnit, $date, $isGoal ? 1 : 0, $coachNote,
        ]);

        $label = self::distanceLabel($dist);
        $when  = date('M j, Y', strtotime($date));

        // Sync a goal race onto the profile so the goal-race display + future generation agree.
        if ($isGoal) {
            $profileDist = self::profileDistanceValue($dist);
            if ($profileDist !== null) {
                $db->prepare('UPDATE athlete_profiles SET goal_race_date = ?, goal_race_distance = ? WHERE athlete_id = ?')
                   ->execute([$date, $profileDist, $athleteId]);
            } else {
                $db->prepare('UPDATE athlete_profiles SET goal_race_date = ? WHERE athlete_id = ?')
                   ->execute([$date, $athleteId]);
            }
        }

        // Coach flags for athlete-added races (a coach adding a race doesn't flag themselves).
        if ($role === 'athlete') {
            $athlete = Auth::getAthlete();
            $first   = self::athleteFirstName($athlete ?? []);
            if ($isGoal) {
                self::raiseFlag($athleteId, 'goal_race_changed', 'warning',
                    "{$first} marked a new goal race: {$name} · {$label} · {$when}. Plan rebuild may be needed.",
                    ['race_date' => $date, 'distance' => $dist], $db);
            } else {
                self::raiseFlag($athleteId, 'race_added', 'info',
                    "{$first} added a tune-up race: {$name} · {$label} · {$when}.",
                    ['race_date' => $date, 'distance' => $dist], $db);
            }
        }

        // Re-run the race-aware plan patch so the surrounding week reflects the new race.
        if (class_exists('PlanGenerator')) {
            try { PlanGenerator::applyRaceAdjustments($athleteId, $db); }
            catch (\Throwable $e) { error_log('RaceController applyRaceAdjustments: ' . $e->getMessage()); }
        }

        return '';
    }

    // ── Conflict detection (coach inline warnings) ──────────────────────────────

    /** Human-readable conflict strings for quality sessions within 7 days before $date. */
    public static function conflictsFor(int $athleteId, string $date, PDO $db): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];

        $stmt = $db->prepare(
            "SELECT pw.scheduled_date, pw.workout_type
             FROM planned_workouts pw
             JOIN training_plans tp ON tp.id = pw.plan_id
             WHERE pw.athlete_id = ? AND tp.status IN ('active','pending_approval')
               AND (pw.cancelled = 0 OR pw.cancelled IS NULL)
               AND pw.workout_type IN ('interval','tempo','hill','fartlek','speed','race_pace')
               AND pw.scheduled_date BETWEEN ? AND ?
             ORDER BY pw.scheduled_date"
        );
        $stmt->execute([
            $athleteId,
            date('Y-m-d', strtotime($date . ' -7 days')),
            date('Y-m-d', strtotime($date . ' -1 day')),
        ]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $days = (int)round((strtotime($date) - strtotime((string)$w['scheduled_date'])) / 86400);
            $out[] = "This race falls {$days} day" . ($days === 1 ? '' : 's')
                   . " after a scheduled quality session on " . date('M j', strtotime((string)$w['scheduled_date'])) . ".";
        }
        return $out;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function coachOwns(int $athleteId, int $coachId, PDO $db): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM athletes a
             WHERE a.id = ? AND (a.coach_id = ? OR ? IN (SELECT id FROM users WHERE role = "admin")) LIMIT 1'
        );
        $stmt->execute([$athleteId, $coachId, $coachId]);
        return (bool)$stmt->fetchColumn();
    }

    private static function ownedRace(int $raceId, int $athleteId, PDO $db): ?array
    {
        $stmt = $db->prepare('SELECT * FROM races WHERE id = ? AND athlete_id = ? LIMIT 1');
        $stmt->execute([$raceId, $athleteId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function coachRace(int $raceId, int $coachId, PDO $db): ?array
    {
        $stmt = $db->prepare(
            'SELECT r.* FROM races r JOIN athletes a ON a.id = r.athlete_id
             WHERE r.id = ? AND (a.coach_id = ? OR ? IN (SELECT id FROM users WHERE role = "admin")) LIMIT 1'
        );
        $stmt->execute([$raceId, $coachId, $coachId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function athleteFirstName(array $athlete): string
    {
        $name = trim((string)($athlete['name'] ?? ''));
        return $name === '' ? 'Athlete' : (explode(' ', $name)[0] ?: 'Athlete');
    }

    /** Insert an engine flag (deduped on open type) and notify the coach. */
    private static function raiseFlag(int $athleteId, string $type, string $severity, string $message, ?array $details, PDO $db): void
    {
        // pace_recalibration is keyed to a specific race, so it is NOT deduped against
        // other open recalibration flags; the others dedupe on an open flag of the type.
        if ($type !== 'pace_recalibration') {
            $check = $db->prepare('SELECT id FROM engine_flags WHERE athlete_id = ? AND flag_type = ? AND status = "open" LIMIT 1');
            $check->execute([$athleteId, $type]);
            if ($check->fetch()) {
                // Refresh the message of the existing open flag rather than stacking duplicates.
                $db->prepare('UPDATE engine_flags SET message = ?, details = ?, created_at = NOW() WHERE athlete_id = ? AND flag_type = ? AND status = "open"')
                   ->execute([$message, $details ? json_encode($details) : null, $athleteId, $type]);
                self::notify($athleteId, $severity, $message);
                return;
            }
        }

        $db->prepare(
            'INSERT INTO engine_flags (athlete_id, flag_type, severity, flag_date, details, message, status, created_at)
             VALUES (?, ?, ?, CURDATE(), ?, ?, "open", NOW())'
        )->execute([$athleteId, $type, $severity, $details ? json_encode($details) : null, $message]);

        self::notify($athleteId, $severity, $message);
    }

    private static function notify(int $athleteId, string $severity, string $message): void
    {
        if (class_exists('Notifications')) {
            try { Notifications::notifyFlag($athleteId, $severity, $message); }
            catch (\Throwable $e) { error_log('RaceController notify: ' . $e->getMessage()); }
        }
    }

    private static function closeRecalibrationFlag(int $raceId, int $coachId, string $status, PDO $db, ?string $note = null): void
    {
        // Match the pace_recalibration flag carrying this race_id in its details JSON.
        $stmt = $db->prepare('SELECT id, details FROM engine_flags WHERE flag_type = "pace_recalibration" AND status = "open"');
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $d = json_decode((string)$f['details'], true);
            if (is_array($d) && (int)($d['race_id'] ?? 0) === $raceId) {
                $db->prepare('UPDATE engine_flags SET status = ?, reviewed_by = ?, reviewed_at = NOW(), dismiss_reason = ? WHERE id = ?')
                   ->execute([$status, $coachId, $note, (int)$f['id']]);
            }
        }
    }

    public static function parseHmsToSeconds(string $time): ?int
    {
        $time = trim($time);
        if ($time === '' || !preg_match('/^\d{1,2}(:\d{1,2}){0,2}$/', $time)) return null;
        $parts = array_map('intval', explode(':', $time));
        return match (count($parts)) {
            3 => $parts[0] * 3600 + $parts[1] * 60 + $parts[2],
            2 => $parts[0] * 60 + $parts[1],
            1 => $parts[0],
            default => null,
        };
    }

    public static function secondsToHms(int $s): string
    {
        return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }
}

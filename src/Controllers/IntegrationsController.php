<?php
/**
 * IntegrationsController — Intervals.icu OAuth connect / callback / disconnect, plus
 * the public setup guide. The athlete-facing connection lives in Settings →
 * Connected Devices; see IntervalsService for the API work.
 */
class IntegrationsController
{
    /** GET /app/integrations/intervals/connect — kick off OAuth. */
    public static function connect(): void
    {
        Auth::requireRole('athlete');

        if (!IntervalsService::isConfigured()) {
            $_SESSION['flash_error'] = 'Intervals.icu syncing is not available yet. Please try again later.';
            header('Location: /app/settings');
            exit;
        }

        header('Location: ' . IntervalsService::getAuthUrl((int)Auth::userId()));
        exit;
    }

    /** GET /app/integrations/intervals/callback — OAuth redirect target. */
    public static function callback(): void
    {
        Auth::requireRole('athlete');
        $db = Database::get();

        if (($_GET['error'] ?? '') !== '') {
            $_SESSION['flash_error'] = 'Intervals.icu connection was cancelled.';
            header('Location: /app/settings');
            exit;
        }

        $code  = (string)($_GET['code'] ?? '');
        $state = (string)($_GET['state'] ?? '');
        if ($code === '' || !IntervalsService::exchangeCode($code, $state, $db)) {
            $_SESSION['flash_error'] = "We couldn't connect your Intervals.icu account. Please try again.";
            header('Location: /app/settings');
            exit;
        }

        // Import recent activity so the athlete's log isn't empty after connecting.
        try {
            IntervalsService::backfillActivities((int)Auth::userId(), 30, $db);
        } catch (\Throwable $e) {
            error_log('IntegrationsController::callback backfill: ' . $e->getMessage());
        }

        $_SESSION['flash_success'] = 'Intervals.icu connected. Your recent activities have been imported.';
        header('Location: /app/settings');
        exit;
    }

    /** POST /app/integrations/intervals/disconnect — drop the connection. */
    public static function disconnect(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();

        $db = Database::get();
        $db->prepare('DELETE FROM intervals_connections WHERE user_id = ?')->execute([(int)Auth::userId()]);

        $_SESSION['flash_success'] = 'Intervals.icu disconnected.';
        header('Location: /app/settings');
        exit;
    }

    /**
     * POST /app/integrations/intervals/repush — coach/admin: re-push every visible
     * workout in an athlete's active plan to Intervals.icu (refresh after a code
     * change, without waiting for the cron). Body: athlete_id.
     */
    public static function repush(): void
    {
        Auth::requireRole(['coach', 'admin']);
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $athleteId = (int)($_POST['athlete_id'] ?? 0);
        if ($athleteId < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'athlete_id required']);
            exit;
        }

        $db   = Database::get();
        $stmt = $db->prepare('SELECT user_id, coach_id FROM athletes WHERE id = ? LIMIT 1');
        $stmt->execute([$athleteId]);
        $athlete = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$athlete) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'athlete not found']);
            exit;
        }
        // Coaches may only re-push their own athletes; admins may re-push anyone.
        if (Auth::role() !== 'admin' && (int)$athlete['coach_id'] !== (int)Auth::userId()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }

        $res = IntervalsService::repushAllVisible((int)$athlete['user_id'], $db);
        echo json_encode(['success' => true] + $res);
        exit;
    }

    /**
     * POST /app/integrations/intervals/backfill — coach/assistant/admin: re-sync the
     * last N days of an athlete's Intervals.icu activities WITHOUT a reconnect (the
     * coach Log-tab "Re-sync activities" control; also the recovery path for any run a
     * webhook ever misses). Body: athlete_id, days (30|60|90, default 30). Idempotent.
     */
    public static function backfill(): void
    {
        Auth::requireRole(['coach', 'assistant_coach', 'admin']);
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $athleteId = (int)($_POST['athlete_id'] ?? 0);
        $days      = (int)($_POST['days'] ?? 30);
        if ($athleteId < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'athlete_id required']);
            exit;
        }
        if (!in_array($days, [30, 60, 90], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'days must be 30, 60, or 90']);
            exit;
        }

        $db = Database::get();

        // Mirror the coach athlete-access guard exactly (owner coach + assigned assistant
        // + admin), the same check the Log tab itself uses.
        if (!CoachAssignments::canAccess((int)Auth::userId(), Auth::role(), $athleteId, $db)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }

        $stmt = $db->prepare('SELECT user_id FROM athletes WHERE id = ? LIMIT 1');
        $stmt->execute([$athleteId]);
        $userId = (int)($stmt->fetchColumn() ?: 0);
        if ($userId < 1) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'athlete not found']);
            exit;
        }

        try {
            $res = IntervalsService::backfillDetailed($userId, $days, $db);
        } catch (\Throwable $e) {
            error_log('IntegrationsController::backfill: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Re-sync failed. Please try again.']);
            exit;
        }

        if (!$res['connected']) {
            echo json_encode([
                'success'   => false,
                'connected' => false,
                'message'   => "This athlete hasn't connected Intervals.icu (or needs to reconnect).",
            ]);
            exit;
        }

        // Human summary for the toast.
        $parts = ["Imported {$res['imported_new']} new"];
        if ($res['skipped'] > 0) $parts[] = "{$res['skipped']} already present";
        if ($res['errors']  > 0) $parts[] = "{$res['errors']} error" . ($res['errors'] === 1 ? '' : 's');
        $msg = implode(' · ', $parts) . " (last {$days} days)";
        if (!empty($res['partial'])) {
            $msg .= " — stopped early at the time limit; {$res['remaining']} left, run again to finish.";
        }

        echo json_encode(['success' => true, 'message' => $msg] + $res);
        exit;
    }

    /**
     * POST /app/integrations/intervals/sync-athlete — athlete self-service: re-push
     * their own visible workouts to Intervals.icu now (the "Sync workouts now" button).
     */
    public static function syncAthlete(): void
    {
        Auth::requireRole('athlete');
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $db     = Database::get();
        $userId = (int)Auth::userId();

        if (!IntervalsService::connectionForUser($userId, $db)) {
            echo json_encode(['success' => false, 'message' => 'Not connected']);
            exit;
        }

        try {
            $res = IntervalsService::repushAllVisible($userId, $db);
            $n   = (int)($res['pushed'] ?? 0);
            echo json_encode([
                'success' => true,
                'pushed'  => $n,
                'message' => $n === 0
                    ? 'Your calendar is already up to date'
                    : $n . ' workout' . ($n === 1 ? '' : 's') . ' synced to your Intervals.icu calendar',
            ]);
        } catch (\Throwable $e) {
            error_log('IntegrationsController::syncAthlete: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Sync failed. Please try again']);
        }
        exit;
    }

    /** GET /app/integrations/intervals/guide — public setup walkthrough (no auth). */
    public static function guide(): void
    {
        include __DIR__ . '/../../views/static/intervals_setup.php';
    }
}

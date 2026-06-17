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

    /** GET /app/integrations/intervals/guide — public setup walkthrough (no auth). */
    public static function guide(): void
    {
        include __DIR__ . '/../../views/static/intervals_setup.php';
    }
}

<?php
/**
 * SimplyRunFaster — Front Controller
 */
declare(strict_types=1);

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/home/private/php_errors.log');
error_reporting(E_ALL);

// Composer autoload (Resend SDK, etc.) — present once `composer install` has run.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Bootstrap
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Router.php';
require_once __DIR__ . '/src/Mailer.php';
require_once __DIR__ . '/src/EmailTemplates.php';
require_once __DIR__ . '/src/Notifications.php';
require_once __DIR__ . '/src/SessionThread.php';
require_once __DIR__ . '/src/Timezone.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/CoachAssignments.php';
require_once __DIR__ . '/src/Billing.php';
require_once __DIR__ . '/src/StripeWebhook.php';
require_once __DIR__ . '/src/Crypto.php';
require_once __DIR__ . '/src/IntervalsService.php';
require_once __DIR__ . '/src/ProfileForm.php';
require_once __DIR__ . '/src/Controllers/AuthController.php';
require_once __DIR__ . '/src/Controllers/OnboardingController.php';
require_once __DIR__ . '/src/Controllers/AthleteController.php';
require_once __DIR__ . '/src/Controllers/CoachController.php';
require_once __DIR__ . '/src/Controllers/AdminController.php';
require_once __DIR__ . '/src/Controllers/IntegrationsController.php';
require_once __DIR__ . '/src/Controllers/RaceController.php';
require_once __DIR__ . '/src/Engine/TrainingLoad.php';
require_once __DIR__ . '/src/Engine/RecoveryModel.php';
require_once __DIR__ . '/src/Engine/EffortMapper.php';
require_once __DIR__ . '/src/Engine/PaceZones.php';
require_once __DIR__ . '/src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/src/Engine/PlanGenerator.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// ── Stripe webhook ───────────────────────────────────────────
// Public, session-less, BEFORE auth: Stripe calls this directly and the
// payload is signature-verified inside the handler.
if ($uri === '/webhook/stripe') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        StripeWebhook::handle();
    } else {
        http_response_code(405);
        echo 'method not allowed';
    }
    exit;
}

// ── Intervals.icu webhook ────────────────────────────────────
// Public, session-less, BEFORE auth: Intervals.icu calls this directly and the
// request is verified by the shared webhook secret inside the handler.
if ($uri === '/webhook/intervals') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        require __DIR__ . '/public/webhook_intervals.php';
    } else {
        http_response_code(405);
        echo 'method not allowed';
    }
    exit;
}

// Start session
Auth::startSession();

// ── Marketing / root ─────────────────────────────────────────
if ($uri === '/' || $uri === '') {
    include __DIR__ . '/views/marketing/placeholder.php';
    exit;
}

// ── Forced password change gate ──────────────────────────────
// Accounts created from the admin panel get a temporary password and must change
// it before reaching anything else. Allowlist the change screen + logout/theme/
// offline so the user can complete it. Applies to every role.
if (Auth::check() && !empty($_SESSION['must_change_password'])) {
    $allow = ['/app/change-password', '/app/logout', '/app/theme', '/app/offline'];
    if (!in_array($uri, $allow, true)) {
        header('Location: /app/change-password');
        exit;
    }
}

// ── Athlete subscription gate ────────────────────────────────
// Lapsed athletes are funnelled to the billing/reactivation flow; allowlisted
// areas (onboarding, billing, logout, offline) always pass. Coaches/admins are
// never gated.
if (Auth::check() && Auth::role() === 'athlete') {
    Billing::enforceAthleteAccess($uri);
}

$router = new Router('/app');

// ── Auth ─────────────────────────────────────────────────────
$router->get('/login',    [AuthController::class, 'loginForm']);
$router->post('/login',   [AuthController::class, 'loginSubmit']);
$router->get('/logout',   [AuthController::class, 'logout']);
$router->get('/register', [AuthController::class, 'registerForm']);
$router->post('/register',[AuthController::class, 'registerSubmit']);

// Invite-link registration
$router->get('/invite/:code',  [AuthController::class, 'inviteForm']);
$router->post('/invite/:code', [AuthController::class, 'inviteSubmit']);

// Forced password change (admin-created accounts; any role)
$router->get('/change-password',  [AuthController::class, 'forcePasswordForm']);
$router->post('/change-password', [AuthController::class, 'forcePasswordSubmit']);

// ── Onboarding ───────────────────────────────────────────────
$router->get('/onboarding',           [OnboardingController::class, 'start']);
$router->get('/onboarding/:step',     [OnboardingController::class, 'step']);
$router->post('/onboarding/:step',    [OnboardingController::class, 'stepSubmit']);

// ── Athlete portal ───────────────────────────────────────────
$router->get('/dashboard',  [AthleteController::class, 'today']);
$router->get('/',           [AthleteController::class, 'today']);
$router->get('/plan',       [AthleteController::class, 'plan']);
$router->post('/athlete/workout/swap', [AthleteController::class, 'swapWorkout']);
$router->post('/athlete/race/add',     [RaceController::class, 'addRace']);
$router->post('/athlete/race/result',  [RaceController::class, 'logResult']);
$router->get('/log',        [AthleteController::class, 'log']);
$router->post('/log/manual',[AthleteController::class, 'manualLog']);
$router->post('/log/note/edit',[AthleteController::class, 'sessionNoteEdit']);
$router->get('/log/:id',    [AthleteController::class, 'session']);
$router->get('/progress',   [AthleteController::class, 'progress']);
$router->get('/messages',          [AthleteController::class, 'messages']);
$router->get('/messages/poll',     [AthleteController::class, 'messagesPoll']);
$router->post('/messages/send',    [AthleteController::class, 'messagesSend']);
$router->get('/messages/workout/:id',       [AthleteController::class, 'workoutThread']);
$router->get('/messages/workout/:id/poll',  [AthleteController::class, 'workoutThreadPoll']);
$router->post('/messages/workout/:id/send', [AthleteController::class, 'sendWorkoutMessage']);
$router->post('/log/note',         [AthleteController::class, 'sessionNoteSave']);
$router->get('/settings',          [AthleteController::class, 'settings']);
$router->post('/settings',         [AthleteController::class, 'settingsSave']);
$router->get('/settings/notifications',  [AthleteController::class, 'notifications']);
$router->post('/settings/notifications', [AthleteController::class, 'notificationsSave']);
$router->post('/settings/devices/notify', [AthleteController::class, 'saveDeviceNotifyPreference']);
$router->post('/settings/password',[AthleteController::class, 'changePasswordSubmit']);
$router->get('/settings/training', [AthleteController::class, 'trainingSettings']);
$router->post('/settings/training',[AthleteController::class, 'trainingSettingsSave']);

// ── Intervals.icu integration (OAuth connect / callback / disconnect) ────────
$router->get('/integrations/intervals/connect',     [IntegrationsController::class, 'connect']);
$router->get('/integrations/intervals/callback',    [IntegrationsController::class, 'callback']);
$router->post('/integrations/intervals/disconnect', [IntegrationsController::class, 'disconnect']);
$router->post('/integrations/intervals/repush',     [IntegrationsController::class, 'repush']);
$router->post('/integrations/intervals/sync-athlete',[IntegrationsController::class, 'syncAthlete']);
$router->get('/integrations/intervals/guide',       [IntegrationsController::class, 'guide']);

// ── Athlete billing (Milestone 8) ────────────────────────────
$router->get('/billing',          [AthleteController::class, 'billing']);
$router->get('/billing/portal',   [AthleteController::class, 'billingPortal']);
$router->get('/billing/success',  [AthleteController::class, 'billingSuccess']);
$router->get('/billing/cancel',   [AthleteController::class, 'billingCheckoutCancelled']);
$router->post('/billing/cancel',  [AthleteController::class, 'billingCancel']);

// ── Coach dashboard ──────────────────────────────────────────
$router->get('/coach/dashboard',          [CoachController::class, 'dashboard']);
$router->get('/coach',                    [CoachController::class, 'dashboard']);
$router->get('/coach/athletes',           [CoachController::class, 'roster']);
$router->get('/coach/athlete/:id',             [CoachController::class, 'athleteView']);
$router->get('/coach/athlete/:id/edit',         [CoachController::class, 'editProfile']);
$router->post('/coach/athlete/:id/edit',        [CoachController::class, 'editProfileSave']);
$router->get('/coach/messages',                      [CoachController::class, 'unifiedMessages']);
$router->get('/coach/messages/unread-count',         [CoachController::class, 'unreadMessageCount']);
$router->get('/coach/messages/threads',              [CoachController::class, 'messageThreads']);
$router->get('/coach/messages/:id/panel',            [CoachController::class, 'messageThreadPanel']);
$router->get('/coach/messages/:id',                  [CoachController::class, 'unifiedThread']);
$router->get('/coach/athlete/:id/messages',         [CoachController::class, 'coachMessages']);
$router->get('/coach/athlete/:id/messages/poll',    [CoachController::class, 'coachMessagesPoll']);
$router->post('/coach/athlete/:id/messages/send',   [CoachController::class, 'coachMessagesSend']);
$router->post('/coach/athlete/:id/session-note',     [CoachController::class, 'coachSessionNoteSave']);
$router->post('/coach/athlete/:id/generate-plan',   [CoachController::class, 'generatePlan']);
$router->get('/coach/approvals',                    [CoachController::class, 'approvals']);
$router->post('/coach/plans/:planId/approve',       [CoachController::class, 'approvePlan']);
$router->post('/coach/plans/:planId/reject',        [CoachController::class, 'rejectPlan']);
$router->get('/coach/flags',                        [CoachController::class, 'flags']);
$router->post('/coach/flags/:id/dismiss',           [CoachController::class, 'dismissFlag']);
$router->post('/coach/athlete/:id/race/add',        [RaceController::class, 'coachAddRace']);
$router->get('/coach/athlete/:id/race-conflicts',   [RaceController::class, 'coachConflicts']);
$router->post('/coach/races/:id/recalibrate/approve',[RaceController::class, 'approveRecalibration']);
$router->post('/coach/races/:id/recalibrate/dismiss',[RaceController::class, 'dismissRecalibration']);
$router->get('/coach/workout/:id/thread',           [CoachController::class, 'workoutThread']);
$router->get('/coach/workout/:id/thread/poll',      [CoachController::class, 'workoutThreadPoll']);
$router->post('/coach/workout/:id/send',            [CoachController::class, 'sendWorkoutMessage']);
$router->post('/coach/workouts/:id/edit',             [CoachController::class, 'editPlannedWorkout']);
$router->post('/coach/athlete/:id/workout/reschedule', [CoachController::class, 'rescheduleWorkout']);
$router->post('/coach/athlete/:id/workout/add',        [CoachController::class, 'addWorkout']);
$router->post('/coach/athlete/:id/workout/remove',     [CoachController::class, 'removeWorkout']);
$router->get('/coach/library',            [CoachController::class, 'library']);
$router->get('/coach/library/preview',    [CoachController::class, 'libraryPreview']);
$router->get('/coach/settings',           [CoachController::class, 'settings']);
$router->post('/coach/settings',          [CoachController::class, 'settingsSave']);
$router->get('/coach/settings/notifications',  [CoachController::class, 'notifications']);
$router->post('/coach/settings/notifications', [CoachController::class, 'notificationsSave']);

// ── Invite links (coach/admin) ───────────────────────────────
$router->get('/coach/invites',  [CoachController::class, 'invites']);
$router->post('/coach/invites', [CoachController::class, 'createInvite']);
$router->post('/coach/invites/deactivate', [CoachController::class, 'deactivateInvite']);

// ── Admin billing overview ───────────────────────────────────
$router->get('/admin/billing', [AdminController::class, 'billing']);
$router->post('/admin/billing/comp', [AdminController::class, 'comp']);

// ── Admin user management ────────────────────────────────────
$router->get('/admin/users',                [AdminController::class, 'users']);
$router->get('/admin/users/create',         [AdminController::class, 'createUserForm']);
$router->post('/admin/users/create',        [AdminController::class, 'createUserSubmit']);
$router->post('/admin/users/role',          [AdminController::class, 'updateRole']);
$router->post('/admin/users/deactivate',    [AdminController::class, 'deactivateUser']);
$router->get('/admin/athletes',             [AdminController::class, 'athletes']);
$router->post('/admin/athletes/reassign',   [AdminController::class, 'reassignAthlete']);

// ── Head-coach assistant assignment + regeneration requests ──
$router->post('/coach/athlete/:id/assistant',             [CoachController::class, 'assignAssistant']);
$router->post('/coach/athlete/:id/request-regeneration',  [CoachController::class, 'requestRegeneration']);
$router->post('/coach/regeneration/:reqId/approve',       [CoachController::class, 'approveRegeneration']);
$router->post('/coach/regeneration/:reqId/dismiss',       [CoachController::class, 'dismissRegeneration']);

// ── Theme toggle (POST, returns to referrer) ─────────────────
$router->post('/theme', function () {
    Auth::requireLogin();
    $theme = in_array($_POST['theme'] ?? '', ['light','dark','system'])
        ? $_POST['theme']
        : 'system';
    $db = Database::get();
    $stmt = $db->prepare('UPDATE users SET theme_preference = ? WHERE id = ?');
    $stmt->execute([$theme, Auth::userId()]);
    $_SESSION['theme'] = $theme;
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/app'));
    exit;
});

// ── Web Push subscription (saves a device to push_subscriptions) ─────────────
$router->post('/push/subscribe', function () {
    Auth::requireLogin();
    Auth::verifyCsrf();
    header('Content-Type: application/json');

    $sub      = json_decode(file_get_contents('php://input'), true) ?: [];
    $endpoint = $sub['endpoint'] ?? '';
    $p256dh   = $sub['keys']['p256dh'] ?? '';
    $auth     = $sub['keys']['auth'] ?? '';
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'incomplete subscription']);
        exit;
    }

    $db     = Database::get();
    $userId = Auth::userId();
    $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    // Upsert by endpoint (endpoint is TEXT, so no unique index — match explicitly).
    $find = $db->prepare('SELECT id FROM push_subscriptions WHERE endpoint = ? LIMIT 1');
    $find->execute([$endpoint]);
    $existing = $find->fetchColumn();

    if ($existing) {
        $db->prepare(
            'UPDATE push_subscriptions SET user_id = ?, p256dh = ?, auth = ?, user_agent = ?, last_used_at = NOW() WHERE id = ?'
        )->execute([$userId, $p256dh, $auth, $ua, $existing]);
    } else {
        $db->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $endpoint, $p256dh, $auth, $ua]);
    }

    echo json_encode(['ok' => true]);
    exit;
});

// ── Password reset ───────────────────────────────────────────
$router->get('/forgot-password',  [AuthController::class, 'forgotForm']);
$router->post('/forgot-password', [AuthController::class, 'forgotSubmit']);
$router->get('/reset-password',   [AuthController::class, 'resetForm']);
$router->post('/reset-password',  [AuthController::class, 'resetSubmit']);

// ── Offline fallback (cached by service worker) ──────────────
$router->get('/offline', function () {
    http_response_code(200);
    include __DIR__ . '/views/offline.php';
});

// ── Privacy policy (public, no auth) ─────────────────────────
$router->get('/privacy', function () {
    include __DIR__ . '/views/static/privacy.php';
});

// ── Terms of Service (public, no auth) ───────────────────────
$router->get('/terms', function () {
    include __DIR__ . '/views/static/terms.php';
});

$router->dispatch();
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
require_once __DIR__ . '/src/Timezone.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/ProfileForm.php';
require_once __DIR__ . '/src/Controllers/AuthController.php';
require_once __DIR__ . '/src/Controllers/OnboardingController.php';
require_once __DIR__ . '/src/Controllers/AthleteController.php';
require_once __DIR__ . '/src/Controllers/CoachController.php';
require_once __DIR__ . '/src/Engine/TrainingLoad.php';
require_once __DIR__ . '/src/Engine/RecoveryModel.php';
require_once __DIR__ . '/src/Engine/EffortMapper.php';
require_once __DIR__ . '/src/Engine/PaceZones.php';
require_once __DIR__ . '/src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/src/Engine/PlanGenerator.php';

// Start session
Auth::startSession();

// ── Marketing / root ─────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($uri === '/' || $uri === '') {
    include __DIR__ . '/views/marketing/placeholder.php';
    exit;
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

// ── Onboarding ───────────────────────────────────────────────
$router->get('/onboarding',           [OnboardingController::class, 'start']);
$router->get('/onboarding/:step',     [OnboardingController::class, 'step']);
$router->post('/onboarding/:step',    [OnboardingController::class, 'stepSubmit']);

// ── Athlete portal ───────────────────────────────────────────
$router->get('/dashboard',  [AthleteController::class, 'today']);
$router->get('/',           [AthleteController::class, 'today']);
$router->get('/plan',       [AthleteController::class, 'plan']);
$router->get('/log',        [AthleteController::class, 'log']);
$router->post('/log/manual',[AthleteController::class, 'manualLog']);
$router->get('/progress',   [AthleteController::class, 'progress']);
$router->get('/messages',          [AthleteController::class, 'messages']);
$router->post('/messages/send',    [AthleteController::class, 'messagesSend']);
$router->post('/log/note',         [AthleteController::class, 'sessionNoteSave']);
$router->get('/settings',          [AthleteController::class, 'settings']);
$router->post('/settings',         [AthleteController::class, 'settingsSave']);
$router->post('/settings/password',[AthleteController::class, 'changePasswordSubmit']);
$router->get('/settings/training', [AthleteController::class, 'trainingSettings']);
$router->post('/settings/training',[AthleteController::class, 'trainingSettingsSave']);

// ── Coach dashboard ──────────────────────────────────────────
$router->get('/coach/dashboard',          [CoachController::class, 'dashboard']);
$router->get('/coach',                    [CoachController::class, 'dashboard']);
$router->get('/coach/athletes',           [CoachController::class, 'roster']);
$router->get('/coach/athlete/:id',             [CoachController::class, 'athleteView']);
$router->get('/coach/athlete/:id/edit',         [CoachController::class, 'editProfile']);
$router->post('/coach/athlete/:id/edit',        [CoachController::class, 'editProfileSave']);
$router->get('/coach/athlete/:id/messages',         [CoachController::class, 'coachMessages']);
$router->post('/coach/athlete/:id/messages/send',   [CoachController::class, 'coachMessagesSend']);
$router->post('/coach/athlete/:id/generate-plan',   [CoachController::class, 'generatePlan']);
$router->get('/coach/approvals',                    [CoachController::class, 'approvals']);
$router->post('/coach/plans/:planId/approve',       [CoachController::class, 'approvePlan']);
$router->post('/coach/plans/:planId/reject',        [CoachController::class, 'rejectPlan']);
$router->get('/coach/flags',                        [CoachController::class, 'flags']);
$router->post('/coach/flags/:id/dismiss',           [CoachController::class, 'dismissFlag']);
$router->post('/coach/workouts/:id/edit',             [CoachController::class, 'editPlannedWorkout']);
$router->get('/coach/library',            [CoachController::class, 'library']);
$router->post('/coach/library',           [CoachController::class, 'libraryAddTemplate']);
$router->get('/coach/settings',           [CoachController::class, 'settings']);
$router->post('/coach/settings',          [CoachController::class, 'settingsSave']);

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

$router->dispatch();
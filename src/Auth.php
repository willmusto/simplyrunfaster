<?php
/**
 * Auth — session management, login, registration
 */
class Auth
{
    private static function applyCookieParams(): void
    {
        // Keep the server-side session data alive as long as the cookie (30 days).
        // PHP's default session.gc_maxlifetime is ~24 minutes, so an idle session
        // file can be garbage-collected out from under a still-valid cookie,
        // silently logging the user out well before the 30-day cookie expiry.
        // Must be set before session_start() to take effect.
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',          // site-wide so the session cookie is sent on every route
            'secure'   => true,         // HTTPS only (relies on the .htaccess HTTPS redirect)
            'httponly' => true,         // no JS access
            'samesite' => 'Lax',        // mobile Safari is strict; Lax allows normal navigation
        ]);
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            self::applyCookieParams();
            session_start();
        }

        // Rotate CSRF token each session if not set
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    // ── Authentication ────────────────────────────────────────

    public static function attempt(string $email, string $password): bool
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id, password_hash, role, name, theme_preference, timezone, must_change_password, active FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Deactivated accounts cannot log in.
        if (isset($user['active']) && (int)$user['active'] === 0) {
            return false;
        }

        self::loginUser($user);
        return true;
    }

    public static function register(array $data): int
    {
        $db   = Database::get();
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);

        $stmt = $db->prepare(
            'INSERT INTO users (email, password_hash, role, name, signup_source, invite_code, theme_preference)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            strtolower(trim($data['email'])),
            $hash,
            $data['role']          ?? 'athlete',
            trim($data['name']),
            $data['signup_source'] ?? 'organic',
            $data['invite_code']   ?? null,
            'system',
        ]);
        $userId = (int) $db->lastInsertId();

        // Create athlete record if role is athlete
        if (($data['role'] ?? 'athlete') === 'athlete') {
            $coachId = $data['coach_id'] ?? null;
            $stmt    = $db->prepare(
                'INSERT INTO athletes (user_id, coach_id, billing_status) VALUES (?, ?, ?)'
            );
            $stmt->execute([$userId, $coachId, 'trialing']);

            $athleteId = (int) $db->lastInsertId();

            // Create empty athlete profile
            $db->prepare('INSERT INTO athlete_profiles (athlete_id) VALUES (?)')->execute([$athleteId]);
        }

        // Log in immediately
        $user = $db->query("SELECT id, password_hash, role, name, theme_preference, timezone FROM users WHERE id = $userId")->fetch();
        self::loginUser($user);

        return $userId;
    }

    private static function loginUser(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['theme']     = $user['theme_preference'];
        $_SESSION['timezone']  = Timezone::isValid($user['timezone'] ?? null)
            ? $user['timezone'] : Timezone::DEFAULT_TZ;
        $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
    }

    /** Clear the forced-password-change flag for the current user (after a change). */
    public static function clearMustChangePassword(): void
    {
        unset($_SESSION['must_change_password']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        session_name(SESSION_NAME);
        self::applyCookieParams();
        session_start();
        session_regenerate_id(true);
    }

    // ── Guards ────────────────────────────────────────────────

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    /**
     * Redirect after a request that may have mutated the session (login, logout,
     * registration, onboarding saves, flash messages). Explicitly writes and closes
     * the session BEFORE sending the Location header, so the session file and the
     * (possibly regenerated) session cookie are fully committed before the browser
     * follows the 302. iOS Chrome / WebKit otherwise occasionally drops the just-set
     * cookie on a redirect response, leaving the next request unauthenticated — the
     * infinite login loop. Always exits.
     */
    public static function redirect(string $url): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Location: ' . $url);
        exit;
    }

    /**
     * Redirect on the hop that just (re)issued the session cookie — i.e. immediately
     * after login / registration, where loginUser() called session_regenerate_id().
     *
     * Unlike redirect(), this sends a 200 HTML response carrying the Set-Cookie and
     * performs the navigation with a meta refresh + JS fallback, instead of a 302.
     * iOS Chrome / WebKit intermittently drops a cookie delivered on a redirect
     * response but reliably stores one delivered on a 200 — so the next request is
     * authenticated and the login loop is broken. session_write_close() still runs
     * first so the session file and cookie are committed before output. A plain link
     * is included for the rare case both meta refresh and JS are blocked.
     */
    public static function redirectAfterAuth(string $url): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $attr = htmlspecialchars($url, ENT_QUOTES);
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<meta http-equiv="refresh" content="0; url=' . $attr . '">'
           . '<title>Signing you in…</title></head><body>'
           . '<script>location.replace(' . json_encode($url) . ');</script>'
           . '<p style="font-family:system-ui,-apple-system,sans-serif;text-align:center;margin-top:48px;">'
           . 'Signing you in… <a href="' . $attr . '">Continue</a></p>'
           . '</body></html>';
        exit;
    }

    public static function requireLogin(string $redirect = '/app/login'): void
    {
        if (!self::check()) {
            header('Location: ' . $redirect);
            exit;
        }
    }

    public static function requireRole(string|array $roles): void
    {
        self::requireLogin();
        $roles = (array) $roles;
        if (!in_array($_SESSION['user_role'], $roles, true)) {
            http_response_code(403);
            include __DIR__ . '/../views/errors/403.php';
            exit;
        }
    }

    // ── Accessors ─────────────────────────────────────────────

    public static function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string
    {
        return $_SESSION['user_role'] ?? null;
    }

    public static function name(): ?string
    {
        return $_SESSION['user_name'] ?? null;
    }

    public static function theme(): string
    {
        return $_SESSION['theme'] ?? 'system';
    }

    /**
     * The logged-in user's timezone string. Prefers the session copy (set at login);
     * falls back to a DB lookup for sessions that predate the timezone feature.
     */
    public static function timezone(): string
    {
        $tz = $_SESSION['timezone'] ?? null;
        if (Timezone::isValid($tz)) return $tz;
        return Timezone::tzString(self::userId());
    }

    public static function isCoach(): bool
    {
        return in_array(self::role(), ['coach','assistant_coach','admin'], true);
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    // ── CSRF ──────────────────────────────────────────────────

    public static function csrfToken(): string
    {
        return $_SESSION['csrf_token'] ?? '';
    }

    /**
     * Stateless CSRF token bound to an invite code rather than the PHP session.
     * Derived as HMAC(invite:<code>, server secret) so it survives session drops —
     * the common failure on mobile, where backgrounding the browser can lose the
     * session between rendering the invite registration form and submitting it.
     * The form embeds this token and inviteSubmit re-derives + compares it, so no
     * session state (and no DB column) is needed for the pre-account registration POST.
     */
    public static function inviteCsrfToken(string $code): string
    {
        return hash_hmac('sha256', 'invite:' . $code, CSRF_INVITE_SECRET);
    }

    /** Hidden CSRF field carrying the invite-bound token (for the invite registration form). */
    public static function inviteCsrfField(string $code): string
    {
        return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="'
            . htmlspecialchars(self::inviteCsrfToken($code)) . '">';
    }

    public static function verifyCsrf(): void
    {
        self::verifyCsrfValue($_SESSION['csrf_token'] ?? '');
    }

    /** Verify the posted CSRF token against the invite-bound (session-independent) token. */
    public static function verifyInviteCsrf(string $code): void
    {
        self::verifyCsrfValue(self::inviteCsrfToken($code));
    }

    /**
     * Compare the posted CSRF token to an expected value, rendering the standard
     * 403 / "session expired" response (JSON or styled page) on mismatch. The
     * expected value is the session token for normal posts, or the invite-bound
     * token for the pre-account invite registration post.
     */
    private static function verifyCsrfValue(string $expected): void
    {
        $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($expected !== '' && hash_equals($expected, $token)) {
            return;
        }

        http_response_code(403);

        // Fetch/JSON callers get a machine-readable error they can surface inline;
        // ordinary browser form posts get the styled "session expired" page instead
        // of a white screen.
        $wantsJson = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch')
            || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false
            || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error'   => 'csrf',
                'message' => 'Your session expired. Please refresh the page and try again.',
            ]);
            exit;
        }

        $errorView = __DIR__ . '/../views/errors/csrf.php';
        if (is_file($errorView)) {
            include $errorView;
        } else {
            echo 'Your session expired. Please refresh the page and try again.';
        }
        exit;
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . htmlspecialchars(self::csrfToken()) . '">';
    }

    // ── Helpers ───────────────────────────────────────────────

    public static function getAthlete(?int $userId = null): ?array
    {
        $uid = $userId ?? self::userId();
        if (!$uid) return null;
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT a.*, u.name, u.email, u.theme_preference, u.timezone
             FROM athletes a
             JOIN users u ON u.id = a.user_id
             WHERE a.user_id = ? LIMIT 1'
        );
        $stmt->execute([$uid]);
        return $stmt->fetch() ?: null;
    }

    public static function getAthleteProfile(int $athleteId): ?array
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT * FROM athlete_profiles WHERE athlete_id = ? LIMIT 1');
        $stmt->execute([$athleteId]);
        return $stmt->fetch() ?: null;
    }
}

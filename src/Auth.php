<?php
/**
 * Auth — session management, login, registration
 */
class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
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
        $stmt = $db->prepare('SELECT id, password_hash, role, name, theme_preference FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
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
        $user = $db->query("SELECT id, password_hash, role, name, theme_preference FROM users WHERE id = $userId")->fetch();
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
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        session_start();
        session_regenerate_id(true);
    }

    // ── Guards ────────────────────────────────────────────────

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function requireLogin(string $redirect = '/login'): void
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

    public static function verifyCsrf(): void
    {
        $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            exit('CSRF token mismatch.');
        }
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
            'SELECT a.*, u.name, u.email, u.theme_preference
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

<?php
class AuthController
{
    public static function loginForm(): void
    {
        if (Auth::check()) {
            self::redirectByRole();
        }
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);
        include __DIR__ . '/../../views/auth/login.php';
    }

    public static function loginSubmit(): void
    {
        Auth::verifyCsrf();

        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$email || !$password) {
            $_SESSION['flash_error'] = 'Please enter your email and password.';
            header('Location: /login');
            exit;
        }

        if (!Auth::attempt($email, $password)) {
            $_SESSION['flash_error'] = 'Invalid email or password.';
            header('Location: /login');
            exit;
        }

        self::redirectByRole();
    }

    public static function logout(): void
    {
        Auth::logout();
        header('Location: /login');
        exit;
    }

    public static function registerForm(): void
    {
        if (Auth::check()) {
            self::redirectByRole();
        }
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);
        include __DIR__ . '/../../views/auth/register.php';
    }

    public static function registerSubmit(): void
    {
        Auth::verifyCsrf();

        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']       ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        $errors = self::validateRegistration($name, $email, $password, $confirm);
        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            header('Location: /register');
            exit;
        }

        if (self::emailExists($email)) {
            $_SESSION['flash_error'] = 'An account with that email already exists.';
            header('Location: /register');
            exit;
        }

        Auth::register([
            'name'          => $name,
            'email'         => $email,
            'password'      => $password,
            'role'          => 'athlete',
            'signup_source' => 'organic',
        ]);

        header('Location: /onboarding');
        exit;
    }

    public static function inviteForm(array $params): void
    {
        if (Auth::check()) {
            self::redirectByRole();
        }
        $code   = $params['code'] ?? '';
        $invite = self::getValidInvite($code);
        if (!$invite) {
            include __DIR__ . '/../../views/auth/invite_invalid.php';
            return;
        }
        $coach = self::getCoachForInvite($invite);
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);
        include __DIR__ . '/../../views/auth/invite_register.php';
    }

    public static function inviteSubmit(array $params): void
    {
        Auth::verifyCsrf();

        $code   = $params['code'] ?? '';
        $invite = self::getValidInvite($code);
        if (!$invite) {
            header('Location: /login');
            exit;
        }

        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']       ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        $errors = self::validateRegistration($name, $email, $password, $confirm);
        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            header('Location: /invite/' . $code);
            exit;
        }

        if (self::emailExists($email)) {
            $_SESSION['flash_error'] = 'An account with that email already exists.';
            header('Location: /invite/' . $code);
            exit;
        }

        $userId = Auth::register([
            'name'          => $name,
            'email'         => $email,
            'password'      => $password,
            'role'          => 'athlete',
            'signup_source' => 'invite',
            'invite_code'   => $code,
            'coach_id'      => $invite['assigned_coach_id'],
        ]);

        // Mark invite as used
        $db   = Database::get();
        $stmt = $db->prepare(
            'UPDATE invite_links
             SET used_at = NOW(), used_by = ?, use_count = use_count + 1
             WHERE code = ?'
        );
        $stmt->execute([$userId, $code]);

        // Update invite_code on user record
        $db->prepare('UPDATE users SET invite_code = ? WHERE id = ?')->execute([$code, $userId]);

        header('Location: /onboarding');
        exit;
    }

    // ── Helpers ────────────────────────────────────────────────

    private static function redirectByRole(): void
    {
        $role = Auth::role();
        if (in_array($role, ['coach','assistant_coach','admin'], true)) {
            header('Location: /coach');
        } else {
            header('Location: /');
        }
        exit;
    }

    private static function validateRegistration(string $name, string $email, string $password, string $confirm): array
    {
        $errors = [];
        if (!$name)                          $errors[] = 'Name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (strlen($password) < PASSWORD_MIN_LENGTH)    $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        if ($password !== $confirm)          $errors[] = 'Passwords do not match.';
        return $errors;
    }

    private static function emailExists(string $email): bool
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower($email)]);
        return (bool) $stmt->fetch();
    }

    private static function getValidInvite(string $code): ?array
    {
        if (!$code) return null;
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM invite_links
             WHERE code = ?
               AND expires_at > NOW()
               AND use_count < max_uses
             LIMIT 1'
        );
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    private static function getCoachForInvite(array $invite): ?array
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id, name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$invite['assigned_coach_id']]);
        return $stmt->fetch() ?: null;
    }
}

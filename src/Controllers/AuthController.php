<?php
class AuthController
{
    public static function loginForm(): void
    {
        if (Auth::check()) {
            self::redirectByRole();
        }
        $error   = $_SESSION['flash_error']   ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);
        include __DIR__ . '/../../views/auth/login.php';
    }

    public static function loginSubmit(): void
    {
        Auth::verifyCsrf();

        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$email || !$password) {
            $_SESSION['flash_error'] = 'Please enter your email and password.';
            header('Location: /app/login');
            exit;
        }

        if (!Auth::attempt($email, $password)) {
            $_SESSION['flash_error'] = 'Invalid email or password.';
            header('Location: /app/login');
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
            header('Location: /app/register');
            exit;
        }

        if (self::emailExists($email)) {
            $_SESSION['flash_error'] = 'An account with that email already exists.';
            header('Location: /app/register');
            exit;
        }

        Auth::register([
            'name'          => $name,
            'email'         => $email,
            'password'      => $password,
            'role'          => 'athlete',
            'signup_source' => 'organic',
        ]);

        header('Location: /app/onboarding');
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
            // A coach-deactivated link gets a precise message; everything else
            // (expired / used / unknown) falls back to the generic invalid copy.
            if ($code !== '' && self::inviteIsDeactivated($code)) {
                $inviteTitle   = 'This invite link is no longer active.';
                $inviteMessage = 'The coach who created it has deactivated it. Ask them for a new invite link.';
            }
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
        $code = $params['code'] ?? '';
        // The invite registration form uses a CSRF token bound to the invite code,
        // not the session, so a dropped session (common on mobile) no longer trips
        // a false "session expired" on submit.
        Auth::verifyInviteCsrf($code);

        $invite = self::getValidInvite($code);
        if (!$invite) {
            header('Location: /app/login');
            exit;
        }

        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']       ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        $errors = self::validateRegistration($name, $email, $password, $confirm);
        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            header('Location: /app/invite/' . $code);
            exit;
        }

        if (self::emailExists($email)) {
            $_SESSION['flash_error'] = 'An account with that email already exists.';
            header('Location: /app/invite/' . $code);
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

        // A 100%-forever invite is a permanent comp: mark the user comped now so
        // the subscription gate passes and onboarding skips Stripe checkout.
        if ((int)($invite['discount_percent'] ?? 0) === 100 && ($invite['discount_duration'] ?? '') === 'forever') {
            $db->prepare(
                "UPDATE users SET subscription_status = 'comped', subscription_end_date = NULL WHERE id = ?"
            )->execute([$userId]);
        }

        header('Location: /app/onboarding');
        exit;
    }

    // ── Forced password change (admin-created accounts) ────────

    public static function forcePasswordForm(): void
    {
        Auth::requireLogin();
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);
        include __DIR__ . '/../../views/auth/change_password.php';
    }

    public static function forcePasswordSubmit(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();

        $password = $_POST['password']         ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            $_SESSION['flash_error'] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
            header('Location: /app/change-password');
            exit;
        }
        if ($password !== $confirm) {
            $_SESSION['flash_error'] = 'Passwords do not match.';
            header('Location: /app/change-password');
            exit;
        }

        $db   = Database::get();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
           ->execute([$hash, Auth::userId()]);
        Auth::clearMustChangePassword();

        $_SESSION['flash_success'] = 'Password updated. Welcome to SimplyRunFaster.';
        self::redirectByRole();
    }

    // ── Helpers ────────────────────────────────────────────────

    private static function redirectByRole(): void
    {
        $role = Auth::role();
        if (in_array($role, ['coach','assistant_coach','admin'], true)) {
            header('Location: /app/coach/dashboard');
        } else {
            header('Location: /app/dashboard');
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
               AND deactivated_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    /** True when an invite code exists but has been manually deactivated by its coach. */
    private static function inviteIsDeactivated(string $code): bool
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT 1 FROM invite_links WHERE code = ? AND deactivated_at IS NOT NULL LIMIT 1');
        $stmt->execute([$code]);
        return (bool) $stmt->fetchColumn();
    }

    private static function getCoachForInvite(array $invite): ?array
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id, name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$invite['assigned_coach_id']]);
        return $stmt->fetch() ?: null;
    }

    // ── Password reset ─────────────────────────────────────────

    public static function forgotForm(): void
    {
        $error   = $_SESSION['flash_error']   ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);
        include __DIR__ . '/../../views/auth/forgot.php';
    }

    public static function forgotSubmit(): void
    {
        Auth::verifyCsrf();

        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Please enter a valid email address.';
            header('Location: /app/forgot-password');
            exit;
        }

        $db   = Database::get();
        $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);

            $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$user['id']]);
            $db->prepare(
                'INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)'
            )->execute([$user['id'], $token, $expiresAt]);

            $resetUrl = APP_URL . '/reset-password?token=' . $token;
            $body     = "Hi {$user['name']},\n\nClick the link below to reset your SimplyRunFaster password.\nThis link expires in 1 hour.\n\n{$resetUrl}\n\nIf you didn't request this, you can ignore this email.\n\nSimplyRunFaster";
            mail($email, 'Reset your SimplyRunFaster password', $body, 'From: noreply@simplyrunfaster.com');
        }

        // Always show the same message to prevent email enumeration
        $_SESSION['flash_success'] = "If an account exists for that email, you'll receive a reset link shortly.";
        header('Location: /app/forgot-password');
        exit;
    }

    public static function resetForm(): void
    {
        $token = $_GET['token'] ?? '';
        $valid = self::getValidResetToken($token);
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);
        if (!$valid) {
            include __DIR__ . '/../../views/auth/reset_invalid.php';
            return;
        }
        include __DIR__ . '/../../views/auth/reset.php';
    }

    public static function resetSubmit(): void
    {
        Auth::verifyCsrf();

        $token    = $_POST['token']            ?? '';
        $password = $_POST['password']         ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        $record = self::getValidResetToken($token);
        if (!$record) {
            $_SESSION['flash_error'] = 'This reset link is invalid or has expired.';
            header('Location: /app/forgot-password');
            exit;
        }

        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            $_SESSION['flash_error'] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
            header('Location: /app/reset-password?token=' . urlencode($token));
            exit;
        }

        if ($password !== $confirm) {
            $_SESSION['flash_error'] = 'Passwords do not match.';
            header('Location: /app/reset-password?token=' . urlencode($token));
            exit;
        }

        $db   = Database::get();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $record['user_id']]);
        $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE token = ?')->execute([$token]);

        $_SESSION['flash_success'] = 'Password reset successfully. Please sign in with your new password.';
        header('Location: /login');
        exit;
    }

    private static function getValidResetToken(string $token): ?array
    {
        if (!$token) return null;
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM password_reset_tokens
             WHERE token = ? AND expires_at > NOW() AND used_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }
}

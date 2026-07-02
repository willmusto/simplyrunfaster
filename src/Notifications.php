<?php
/**
 * Notifications — central dispatcher for all push + email notifications.
 *
 * Every notification in the app flows through Notifications::send(). It resolves
 * the recipient's per-type preferences, enforces always-on types, respects quiet
 * hours (evaluated in the user's own timezone via Timezone.php), and dispatches
 * to the enabled channels:
 *
 *   - Web Push  → push_subscriptions table (one row per device), via
 *                 minishlink/web-push using the VAPID keys from config.
 *   - Email     → Resend, via src/Mailer.php + src/EmailTemplates.php.
 *   - SMS       → deferred. channel_sms exists in the schema but is always off.
 *
 * Preference rows live in notification_preferences (PK user_id+notification_type).
 * The canonical defaults live in self::DEFAULTS and are used both to seed rows
 * and as a fallback when a row is missing, so a brand-new type still behaves
 * sensibly before the next seed run.
 */
class Notifications
{
    /**
     * Always-on types: cannot be disabled by the user. They bypass the `enabled`
     * toggle AND quiet hours, but still honor channel selection (with a guaranteed
     * fallback to push if the user somehow turned every channel off).
     */
    public const ALWAYS_ON = [
        'athlete' => ['plan_approved', 'message_from_coach', 'payment_failed_athlete'],
        'coach'   => ['plan_pending_approval', 'critical_flag', 'message_from_athlete', 'payment_failed_coach'],
    ];

    /**
     * Types whose delivery mode may be switched to the daily flag digest.
     * critical_flag is deliberately excluded: it is always-on and always immediate.
     */
    public const DIGESTABLE = ['warning_flag', 'info_flag'];

    /**
     * Canonical default preference per audience and type.
     *   enabled / push / email  → 1|0
     *   time  → default preferred_time (scheduled types)
     *   day   → default preferred_day 0=Sun..6=Sat (weekly types)
     * Omitted keys default to enabled=1, push=1, email=0, time=null, day=null.
     */
    public const DEFAULTS = [
        'athlete' => [
            // Always-on (rows seeded so channels stay configurable)
            'plan_approved'         => ['enabled' => 1, 'push' => 1, 'email' => 1],
            'message_from_coach'    => ['enabled' => 1, 'push' => 1, 'email' => 0],
            'payment_failed_athlete' => ['enabled' => 1, 'push' => 1, 'email' => 1],
            // Controllable
            'tomorrow_plan'         => ['enabled' => 1, 'push' => 1, 'email' => 0, 'time' => '20:00:00'],
            'rpe_prompt'            => ['enabled' => 1, 'push' => 1, 'email' => 0],
            'coach_session_comment' => ['enabled' => 1, 'push' => 1, 'email' => 0],
            'weekly_summary'        => ['enabled' => 1, 'push' => 1, 'email' => 0, 'time' => '18:00:00', 'day' => 0],
            'pre_race_reminder'     => ['enabled' => 1, 'push' => 1, 'email' => 0],
            'long_run_reminder'     => ['enabled' => 0, 'push' => 1, 'email' => 0],
        ],
        'coach' => [
            // Always-on
            'plan_pending_approval'      => ['enabled' => 1, 'push' => 1, 'email' => 1],
            'critical_flag'              => ['enabled' => 1, 'push' => 1, 'email' => 1],
            'message_from_athlete'       => ['enabled' => 1, 'push' => 1, 'email' => 0],
            'payment_failed_coach'       => ['enabled' => 1, 'push' => 1, 'email' => 1],
            // Controllable
            'warning_flag'               => ['enabled' => 1, 'push' => 1, 'email' => 0],
            'info_flag'                  => ['enabled' => 0, 'push' => 1, 'email' => 0],
            // Vehicle for batched warning/info flags (fires only when a digestable
            // type is set to delivery=daily_digest). Not rendered as its own row in
            // the settings UI; channel prefs still apply.
            'flag_digest'                => ['enabled' => 1, 'push' => 1, 'email' => 1, 'time' => '07:30:00'],
            'athlete_session_note'       => ['enabled' => 1, 'push' => 1, 'email' => 0],
            'athlete_manual_log'         => ['enabled' => 0, 'push' => 1, 'email' => 0],
            'athlete_day_swap'           => ['enabled' => 0, 'push' => 1, 'email' => 0],
            'weekly_athlete_digest'      => ['enabled' => 1, 'push' => 1, 'email' => 1, 'time' => '08:00:00', 'day' => 1],
            'individual_athlete_summary' => ['enabled' => 0, 'push' => 1, 'email' => 0],
        ],
    ];

    private const QUIET_START_DEFAULT = '22:00:00';
    private const QUIET_END_DEFAULT   = '07:00:00';

    // ── Audience / type helpers ─────────────────────────────────────────────

    /** Audience bucket for a user: athletes are 'athlete', everyone else 'coach'. */
    public static function audienceForUser(int $userId): string
    {
        $role = self::userRole($userId);
        return $role === 'athlete' ? 'athlete' : 'coach';
    }

    /** Audience that owns a given notification type (null if unknown). */
    public static function audienceForType(string $type): ?string
    {
        foreach (self::DEFAULTS as $audience => $types) {
            if (isset($types[$type])) return $audience;
        }
        return null;
    }

    public static function isAlwaysOn(string $type): bool
    {
        foreach (self::ALWAYS_ON as $list) {
            if (in_array($type, $list, true)) return true;
        }
        return false;
    }

    /** Default preference array for a type, normalized with all keys present. */
    public static function defaultFor(string $type): array
    {
        $audience = self::audienceForType($type) ?? 'athlete';
        $d = self::DEFAULTS[$audience][$type] ?? [];
        return [
            'enabled'           => $d['enabled'] ?? 1,
            'channel_push'      => $d['push']    ?? 1,
            'channel_email'     => $d['email']   ?? 0,
            'channel_sms'       => 0,
            'quiet_hours_start' => self::QUIET_START_DEFAULT,
            'quiet_hours_end'   => self::QUIET_END_DEFAULT,
            'preferred_time'    => $d['time'] ?? null,
            'preferred_day'     => $d['day']  ?? null,
            'delivery'          => $d['delivery'] ?? 'immediate',
        ];
    }

    // ── Seeding ─────────────────────────────────────────────────────────────

    /** Insert any missing default preference rows for one user (idempotent). */
    public static function ensureUserDefaults(int $userId, ?string $role = null): int
    {
        $db        = Database::get();
        $role    ??= self::userRole($userId);
        $audience  = ($role === 'athlete') ? 'athlete' : 'coach';

        $stmt = $db->prepare(
            'INSERT IGNORE INTO notification_preferences
                (user_id, notification_type, enabled, channel_push, channel_email, channel_sms,
                 quiet_hours_start, quiet_hours_end, preferred_time, preferred_day, delivery)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)'
        );

        $inserted = 0;
        foreach (self::DEFAULTS[$audience] as $type => $d) {
            $def = self::defaultFor($type);
            $stmt->execute([
                $userId, $type, $def['enabled'], $def['channel_push'], $def['channel_email'],
                $def['quiet_hours_start'], $def['quiet_hours_end'],
                $def['preferred_time'], $def['preferred_day'], $def['delivery'],
            ]);
            $inserted += $stmt->rowCount();
        }
        return $inserted;
    }

    /** Seed default rows for every existing user. Returns count of rows inserted. */
    public static function seedDefaultsForAllUsers(): int
    {
        $db    = Database::get();
        $users = $db->query('SELECT id, role FROM users')->fetchAll(PDO::FETCH_ASSOC);
        $total = 0;
        foreach ($users as $u) {
            $total += self::ensureUserDefaults((int)$u['id'], $u['role']);
        }
        return $total;
    }

    // ── Preference updates (AJAX from settings UI) ──────────────────────────

    /**
     * Apply a single preference change for a user. Field is whitelisted (no
     * dynamic column from user input beyond these cases); type is validated
     * against the user's audience. Quiet hours apply to all of the user's rows.
     * Always-on types cannot be disabled. Returns true on a successful update.
     */
    public static function applyPrefChange(int $userId, string $type, string $field, $value): bool
    {
        $db = Database::get();
        self::ensureUserDefaults($userId); // guarantee rows exist before update

        if ($type === 'quiet_hours') {
            if (!in_array($field, ['quiet_hours_start', 'quiet_hours_end'], true)) return false;
            $t = self::normalizeTime($value);
            if ($t === null) return false;
            $col  = $field; // whitelisted above
            $stmt = $db->prepare("UPDATE notification_preferences SET {$col} = ? WHERE user_id = ?");
            return $stmt->execute([$t, $userId]);
        }

        if (self::audienceForType($type) !== self::audienceForUser($userId)) return false;

        switch ($field) {
            case 'enabled':
                if (self::isAlwaysOn($type)) return false; // cannot disable always-on
                // fall through
            case 'channel_push':
            case 'channel_email':
                $v   = ((int)$value === 1) ? 1 : 0;
                $col = $field;
                return $db->prepare("UPDATE notification_preferences SET {$col} = ? WHERE user_id = ? AND notification_type = ?")
                          ->execute([$v, $userId, $type]);

            case 'preferred_time':
                $t = self::normalizeTime($value);
                if ($t === null) return false;
                return $db->prepare('UPDATE notification_preferences SET preferred_time = ? WHERE user_id = ? AND notification_type = ?')
                          ->execute([$t, $userId, $type]);

            case 'preferred_day':
                $d = (int)$value;
                if ($d < 0 || $d > 6) return false;
                return $db->prepare('UPDATE notification_preferences SET preferred_day = ? WHERE user_id = ? AND notification_type = ?')
                          ->execute([$d, $userId, $type]);

            case 'delivery':
                // Only warning/info flags may be digested; critical is never batched.
                if (!in_array($type, self::DIGESTABLE, true)) return false;
                if (!in_array($value, ['immediate', 'daily_digest'], true)) return false;
                return $db->prepare('UPDATE notification_preferences SET delivery = ? WHERE user_id = ? AND notification_type = ?')
                          ->execute([$value, $userId, $type]);
        }
        return false;
    }

    private static function normalizeTime($v): ?string
    {
        if (!is_string($v)) return null;
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $v, $m)) return null;
        return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
    }

    // ── Preference resolution ───────────────────────────────────────────────

    /** Resolve a user's effective preference for a type (row if present, else default). */
    public static function getPref(int $userId, string $type): array
    {
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT enabled, channel_push, channel_email, channel_sms,
                    quiet_hours_start, quiet_hours_end, preferred_time, preferred_day, delivery
             FROM notification_preferences WHERE user_id = ? AND notification_type = ? LIMIT 1'
        );
        $stmt->execute([$userId, $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['enabled']       = (int)$row['enabled'];
            $row['channel_push']  = (int)$row['channel_push'];
            $row['channel_email'] = (int)$row['channel_email'];
            $row['channel_sms']   = (int)$row['channel_sms'];
            $row['delivery']      = ($row['delivery'] ?? '') === 'daily_digest' ? 'daily_digest' : 'immediate';
            return $row;
        }
        return self::defaultFor($type);
    }

    /** The user's quiet-hours window (read from any one row; uniform per user). */
    public static function quietWindow(int $userId): array
    {
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT quiet_hours_start, quiet_hours_end FROM notification_preferences
             WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            $row['quiet_hours_start'] ?? self::QUIET_START_DEFAULT,
            $row['quiet_hours_end']   ?? self::QUIET_END_DEFAULT,
        ];
    }

    /**
     * Is it currently quiet hours for this user, in their local timezone?
     * Handles the common overnight wrap (e.g. 22:00 → 07:00).
     */
    public static function isQuietHours(int $userId, ?DateTime $now = null): bool
    {
        [$start, $end] = self::quietWindow($userId);
        if (!$start || !$end || $start === $end) return false;

        $tz  = Timezone::forUser($userId);
        $now = $now ? (clone $now)->setTimezone($tz) : new DateTime('now', $tz);
        $cur = $now->format('H:i:s');

        // Non-wrapping window (e.g. 01:00 → 06:00): inside if start <= cur < end.
        if ($start < $end) {
            return $cur >= $start && $cur < $end;
        }
        // Wrapping window (e.g. 22:00 → 07:00): inside if cur >= start OR cur < end.
        return $cur >= $start || $cur < $end;
    }

    // ── Main dispatch ───────────────────────────────────────────────────────

    /**
     * Send a notification to a user.
     *
     * @param int    $userId Recipient user id.
     * @param string $type   Notification type (see self::DEFAULTS keys).
     * @param array  $data   Presentation data (names, snippets, counts, ids) plus
     *                       optional control flags: bypass_quiet, email_fallback.
     * @return array Result: [type, user_id, push(bool), email(bool), suppressed(string|null)]
     */
    public static function send(int $userId, string $type, array $data = []): array
    {
        $result = ['type' => $type, 'user_id' => $userId, 'push' => false, 'email' => false, 'suppressed' => null];

        $pref     = self::getPref($userId, $type);
        $alwaysOn = self::isAlwaysOn($type);

        if (!$alwaysOn && (int)$pref['enabled'] !== 1) {
            $result['suppressed'] = 'disabled';
            return self::logResult($result);
        }

        $wantPush  = (int)$pref['channel_push'] === 1;
        $wantEmail = (int)$pref['channel_email'] === 1;
        if (!$wantPush && !$wantEmail) {
            if ($alwaysOn) {
                $wantPush = true; // always-on must reach at least one channel
            } else {
                $result['suppressed'] = 'no_channel';
                return self::logResult($result);
            }
        }

        $bypassQuiet = $alwaysOn || !empty($data['bypass_quiet']);
        if (!$bypassQuiet && self::isQuietHours($userId)) {
            $result['suppressed'] = 'quiet_hours';
            return self::logResult($result);
        }

        $push = self::pushContent($type, $data);

        $pushOk = false;
        if ($wantPush) {
            $pushOk = self::sendPush($userId, $push['title'], $push['body'], $push['url'], $type);
            $result['push'] = $pushOk;
        }

        $emailFallback = !empty($data['email_fallback']) || !empty($push['email_fallback']);
        if ($wantEmail) {
            $result['email'] = self::sendEmailForType($userId, $type, $data);
        } elseif (!$pushOk && $emailFallback) {
            // Push channel chosen but undeliverable (no device / failure) → email instead.
            $result['email'] = self::sendEmailForType($userId, $type, $data);
        }

        return self::logResult($result);
    }

    private static function logResult(array $r): array
    {
        error_log(sprintf(
            'Notifications: type=%s user=%d push=%s email=%s%s',
            $r['type'], $r['user_id'], $r['push'] ? '1' : '0', $r['email'] ? '1' : '0',
            $r['suppressed'] ? ' suppressed=' . $r['suppressed'] : ''
        ));
        return $r;
    }

    // ── Channel: Web Push ───────────────────────────────────────────────────

    /**
     * Send a Web Push to every registered device for a user. Prunes subscriptions
     * the push service reports as gone (404/410). Returns true if at least one
     * device was delivered to.
     */
    public static function sendPush(int $userId, string $title, string $body, ?string $url = null, ?string $type = null): bool
    {
        if (!class_exists('Minishlink\\WebPush\\WebPush')) {
            error_log('Notifications: web-push library not loaded; push to user ' . $userId . ' skipped.');
            return false;
        }
        if (!defined('VAPID_PUBLIC_KEY') || VAPID_PUBLIC_KEY === '' || VAPID_PRIVATE_KEY === '') {
            error_log('Notifications: VAPID keys not configured; push to user ' . $userId . ' skipped.');
            return false;
        }

        $db   = Database::get();
        $stmt = $db->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$subs) return false;

        try {
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject'    => defined('VAPID_SUBJECT') ? VAPID_SUBJECT : 'mailto:coach@simplyrunfaster.com',
                    'publicKey'  => VAPID_PUBLIC_KEY,
                    'privateKey' => VAPID_PRIVATE_KEY,
                ],
            ]);

            $payload = json_encode([
                'title' => $title,
                'body'  => $body,
                'url'   => $url ?: '/app',
                // The SW uses `type` for the notification tag (grouping/dedup) and to
                // decide requireInteraction for always-on types. Fallback groups any
                // type-less push under one tag.
                'type'  => $type ?: 'srf-notification',
            ]);

            $byEndpoint = [];
            foreach ($subs as $s) {
                $byEndpoint[$s['endpoint']] = (int)$s['id'];
                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $s['endpoint'],
                    'keys'     => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
                ]);
                $webPush->queueNotification($subscription, $payload);
            }

            $anyOk   = false;
            $delById = $db->prepare('DELETE FROM push_subscriptions WHERE id = ?');
            $touchById = $db->prepare('UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = ?');

            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getEndpoint();
                $id       = $byEndpoint[$endpoint] ?? null;
                if ($report->isSuccess()) {
                    $anyOk = true;
                    if ($id) $touchById->execute([$id]);
                } elseif ($report->isSubscriptionExpired() && $id) {
                    $delById->execute([$id]); // 404/410 Gone → prune dead device
                } else {
                    error_log('Notifications: push failed for user ' . $userId . ': ' . $report->getReason());
                }
            }
            return $anyOk;
        } catch (\Throwable $e) {
            error_log('Notifications: push exception for user ' . $userId . ': ' . $e->getMessage());
            return false;
        }
    }

    // ── Channel: Email ──────────────────────────────────────────────────────

    /** Send an email for a type, building html/text via EmailTemplates. */
    private static function sendEmailForType(int $userId, string $type, array $data): bool
    {
        $user = self::userRow($userId);
        if (!$user || empty($user['email'])) return false;

        $tpl = EmailTemplates::build($type, $data, $user);
        return Mailer::send($user['email'], $tpl['subject'], $tpl['html'], $tpl['text']);
    }

    /** Low-level email send to a user by id (used by cron digests, etc.). */
    public static function sendEmail(int $userId, string $subject, string $html, ?string $text = null): bool
    {
        $user = self::userRow($userId);
        if (!$user || empty($user['email'])) return false;
        return Mailer::send($user['email'], $subject, $html, $text);
    }

    // ── Push presentation (titles / bodies / target urls) ───────────────────

    /**
     * Build push presentation for a type. Real app routes are used (the spec's
     * example URLs like /app/today and /app/alerts don't exist as routes).
     */
    private static function pushContent(string $type, array $data): array
    {
        $name    = $data['athlete_name'] ?? ($data['sender_name'] ?? 'Your athlete');
        $sender  = $data['sender_name']  ?? 'Your coach';
        $workout = $data['workout_name'] ?? 'your workout';
        $snippet = isset($data['message']) ? self::snip($data['message'], 80) : '';

        switch ($type) {
            case 'plan_approved':
                return self::push('Plan approved', 'Your training plan is ready. Open the app to see your schedule.', '/app/dashboard');

            case 'message_from_coach':
                return self::push('Message from ' . $sender, $sender . ': ' . $snippet, '/app/messages', false, true);

            case 'payment_failed_athlete':
                return self::push('Payment problem',
                    'There was a problem with your payment. Please update your billing information to keep your access.',
                    '/app/billing', true, true);

            case 'payment_failed_coach':
                return self::push('Payment failed',
                    $name . "'s subscription payment failed — they may lose access. Open their profile to follow up.",
                    '/app/coach/athlete/' . (int)($data['athlete_id'] ?? 0), true);

            case 'message_from_athlete':
                return self::push('Message from ' . $name, $name . ': ' . $snippet,
                    '/app/coach/athlete/' . (int)($data['athlete_id'] ?? 0) . '/messages', false, true);

            case 'plan_pending_approval':
                return self::push('Plan ready for review', $name . "'s plan is ready for your review.", '/app/coach/approvals');

            case 'critical_flag':
                $flag = trim((string)($data['flag_message'] ?? ''));
                return self::push('Action needed: ' . $name,
                    $flag !== '' ? $name . ' — ' . $flag : $name . ' has a critical flag that needs your attention right away.',
                    '/app/coach/flags');

            case 'warning_flag':
                $flag = trim((string)($data['flag_message'] ?? ''));
                return self::push('Heads up: ' . $name,
                    $flag !== '' ? $name . ' — ' . $flag : $name . ' has a warning flag worth a look when you have a moment.',
                    '/app/coach/flags');

            case 'info_flag':
                $flag = trim((string)($data['flag_message'] ?? ''));
                return self::push('Info: ' . $name,
                    $flag !== '' ? $name . ' — ' . $flag : $name . ' has a new info flag in their training.',
                    '/app/coach/flags');

            case 'coach_session_comment':
                return self::push('New coach comment', 'Your coach commented on ' . $workout, '/app/log');

            case 'rpe_prompt':
                return self::push('How did it feel?', 'How did ' . $workout . ' feel? Log your effort.', '/app/log');

            case 'tomorrow_plan':
                $sub = trim(($data['workout_title'] ?? $workout) . (isset($data['duration']) ? ' · ' . $data['duration'] : ''));
                return self::push("Tomorrow's run", 'Tomorrow: ' . $sub . '. Tap to see the full session.', '/app/plan', !empty($data['bypass_quiet']));

            case 'long_run_reminder':
                return self::push('Long run tomorrow', 'Tomorrow is your long run: ' . ($data['workout_title'] ?? $workout) . '.', '/app/plan');

            case 'weekly_summary':
                return self::push('Your week in review',
                    'Week in review: ' . ($data['completed'] ?? 0) . ' of ' . ($data['planned'] ?? 0)
                    . ' workouts completed. Next week starts ' . ($data['next_week_start'] ?? ''), '/app/progress');

            case 'pre_race_reminder':
                $n = (int)($data['days_out'] ?? 0);
                return self::push('Race day approaching',
                    "You're racing in " . $n . ' day' . ($n === 1 ? '' : 's') . ': ' . ($data['race_name'] ?? 'your race') . '.',
                    '/app/plan', $n <= 1);

            case 'athlete_session_note':
                return self::push('New session note', $name . ' added a note on ' . $workout . '.',
                    '/app/coach/athlete/' . (int)($data['athlete_id'] ?? 0) . '/messages');

            case 'athlete_manual_log':
                return self::push('New manual log', $name . ' logged ' . $workout . '. Tap to review their session.',
                    '/app/coach/athlete/' . (int)($data['athlete_id'] ?? 0));

            case 'athlete_day_swap':
                return self::push('Workout moved', $name . ' moved a workout to a different day.',
                    '/app/coach/athlete/' . (int)($data['athlete_id'] ?? 0));

            case 'flag_digest':
                $n = (int)($data['count'] ?? 0);
                return self::push('Flag digest',
                    $n . ' flag' . ($n === 1 ? '' : 's') . ' across your roster in the last day'
                    . (!empty($data['summary']) ? ': ' . self::snip((string)$data['summary'], 120) : '.'),
                    '/app/coach/flags');

            case 'weekly_athlete_digest':
                return self::push('Weekly digest',
                    'Weekly digest: ' . ($data['athletes'] ?? 0) . ' athletes, ' . ($data['open_flags'] ?? 0)
                    . ' open flags, ' . ($data['upcoming_races'] ?? 0) . ' upcoming races.', '/app/coach/dashboard');

            case 'individual_athlete_summary':
                return self::push('Athlete summary', ($data['summary'] ?? ('Weekly summary for ' . $name)),
                    '/app/coach/athlete/' . (int)($data['athlete_id'] ?? 0));

            default:
                return self::push('SimplyRunFaster',
                    ($data['body'] ?? '') !== '' ? $data['body'] : 'You have a new update in SimplyRunFaster. Open the app to see it.',
                    $data['url'] ?? '/app');
        }
    }

    private static function push(string $title, string $body, string $url, bool $bypassQuiet = false, bool $emailFallback = false): array
    {
        return ['title' => $title, 'body' => $body, 'url' => $url, 'bypass_quiet' => $bypassQuiet, 'email_fallback' => $emailFallback];
    }

    // ── Small utilities ─────────────────────────────────────────────────────

    public static function snip(string $s, int $len): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if (mb_strlen($s) <= $len) return $s;
        return mb_substr($s, 0, $len - 1) . '…';
    }

    private static array $userCache = [];

    private static function userRow(int $userId): ?array
    {
        if (array_key_exists($userId, self::$userCache)) return self::$userCache[$userId];
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id, name, email, role, timezone FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return self::$userCache[$userId] = ($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    private static function userRole(int $userId): string
    {
        return self::userRow($userId)['role'] ?? 'athlete';
    }

    /**
     * Resolve an athlete row id to the people involved: the athlete's own user id,
     * their coach's user id, and the athlete's display name. Used by event wiring.
     */
    public static function athleteContext(int $athleteId): array
    {
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT a.user_id, a.coach_id, u.name
             FROM athletes a JOIN users u ON u.id = a.user_id
             WHERE a.id = ? LIMIT 1'
        );
        $stmt->execute([$athleteId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'athlete_user_id' => (int)($r['user_id'] ?? 0),
            'coach_user_id'   => (int)($r['coach_id'] ?? 0),
            'athlete_name'    => $r['name'] ?? 'Your athlete',
        ];
    }

    /**
     * Convenience used by engine code: notify an athlete's coach about a flag,
     * mapping severity to the right (preference-gated) notification type. Guarded
     * and best-effort so it can never break plan generation.
     */
    public static function notifyFlag(int $athleteId, string $severity, string $message): void
    {
        $type = ['critical' => 'critical_flag', 'warning' => 'warning_flag', 'info' => 'info_flag'][$severity] ?? 'info_flag';
        try {
            $ctx = self::athleteContext($athleteId);
            if (!$ctx['coach_user_id']) return;

            // Digest mode: warning/info flags set to daily_digest are not sent now.
            // The daily cron (cron_notifications.php, flag_digest job) collects the
            // flag rows directly from engine_flags; nothing is lost by skipping here.
            // critical_flag is never digestable and always dispatches immediately.
            if (in_array($type, self::DIGESTABLE, true)) {
                $pref = self::getPref($ctx['coach_user_id'], $type);
                if (($pref['delivery'] ?? 'immediate') === 'daily_digest') {
                    error_log("Notifications: {$type} for athlete {$athleteId} held for daily digest.");
                    return;
                }
            }

            self::send($ctx['coach_user_id'], $type, [
                'athlete_id'   => $athleteId,
                'athlete_name' => $ctx['athlete_name'],
                'flag_message' => $message,
            ]);
        } catch (\Throwable $e) {
            error_log('Notifications::notifyFlag failed: ' . $e->getMessage());
        }
    }
}

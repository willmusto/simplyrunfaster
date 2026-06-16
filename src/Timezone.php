<?php
/**
 * Timezone — per-user timezone conversion.
 *
 * Model: the server runs UTC and every stored DATETIME/DATE is UTC. Conversion
 * to a user's local time happens here, at read/write time, never by shifting the
 * DB. This is the single place timezone logic lives — controllers and views call
 * these methods rather than constructing DateTime/DateTimeZone inline.
 *
 *   - Display: format a UTC datetime in a user's local zone (timestamps on
 *     messages, flags, session notes, etc.).
 *   - "today"/"tomorrow": the calendar date in a user's local zone, used for the
 *     Today tab, the rolling window, and plan generation's plan_start_date.
 *
 * Calendar DATE columns (scheduled_date, plan_start_date, activity_date, …) are
 * NOT instants — they are already stored as the athlete's local calendar date, so
 * they are displayed as-is and never tz-shifted. Only DATETIME instants convert.
 *
 * Invalid/unknown stored timezones fall back to DEFAULT_TZ silently.
 */
class Timezone
{
    public const DEFAULT_TZ = 'America/New_York';

    /** @var array<int,string> user_id → resolved tz string (per-request cache) */
    private static array $userTzCache = [];

    /** @var array<string,DateTimeZone> tz string → DateTimeZone */
    private static array $zoneCache = [];

    /**
     * Curated dropdown list: IANA id → friendly base label (offset appended live
     * at render so DST is reflected accurately). Order is intentional (grouped).
     */
    private const ZONE_LABELS = [
        'America/New_York'    => 'Eastern Time (US & Canada)',
        'America/Chicago'     => 'Central Time (US & Canada)',
        'America/Denver'      => 'Mountain Time (US & Canada)',
        'America/Phoenix'     => 'Mountain Time — Arizona (no DST)',
        'America/Los_Angeles' => 'Pacific Time (US & Canada)',
        'America/Anchorage'   => 'Alaska Time',
        'Pacific/Honolulu'    => 'Hawaii Time',
        'America/Toronto'     => 'Eastern Time — Toronto',
        'America/Vancouver'   => 'Pacific Time — Vancouver',
        'America/Sao_Paulo'   => 'Brasília Time',
        'America/Mexico_City' => 'Central Time — Mexico City',
        'America/Bogota'      => 'Colombia Time',
        'Europe/London'       => 'London (GMT/BST)',
        'Europe/Paris'        => 'Central European Time — Paris',
        'Europe/Berlin'       => 'Central European Time — Berlin',
        'Europe/Rome'         => 'Central European Time — Rome',
        'Europe/Madrid'       => 'Central European Time — Madrid',
        'Europe/Amsterdam'    => 'Central European Time — Amsterdam',
        'Europe/Zurich'       => 'Central European Time — Zurich',
        'Europe/Stockholm'    => 'Central European Time — Stockholm',
        'Africa/Johannesburg' => 'South Africa Time',
        'Asia/Dubai'          => 'Gulf Standard Time',
        'Asia/Kolkata'        => 'India Time',
        'Asia/Singapore'      => 'Singapore Time',
        'Asia/Tokyo'          => 'Japan Time',
        'Asia/Shanghai'       => 'China Time',
        'Australia/Sydney'    => 'Sydney Time',
        'Pacific/Auckland'    => 'New Zealand Time',
        'UTC'                 => 'Coordinated Universal Time (UTC)',
    ];

    /** True if $tz is a usable IANA identifier. */
    public static function isValid(?string $tz): bool
    {
        return is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true);
    }

    /** A DateTimeZone for $tz, falling back to DEFAULT_TZ when invalid. */
    public static function zone(?string $tz): DateTimeZone
    {
        $key = self::isValid($tz) ? $tz : self::DEFAULT_TZ;
        return self::$zoneCache[$key] ??= new DateTimeZone($key);
    }

    /**
     * The stored timezone string for a user (defaults to the logged-in user),
     * validated and falling back to DEFAULT_TZ.
     */
    public static function tzString(?int $userId = null): string
    {
        $viewerId = (class_exists('Auth') ? Auth::userId() : null);
        $userId ??= $viewerId;
        if (!$userId) return self::DEFAULT_TZ;
        // The logged-in viewer's tz is cached in the session at login — use it
        // directly rather than hitting the DB on every render.
        if ($userId === $viewerId && isset($_SESSION['timezone']) && self::isValid($_SESSION['timezone'])) {
            return $_SESSION['timezone'];
        }
        if (isset(self::$userTzCache[$userId])) return self::$userTzCache[$userId];

        $tz = self::DEFAULT_TZ;
        try {
            $stmt = Database::get()->prepare('SELECT timezone FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $stored = $stmt->fetchColumn();
            if (self::isValid($stored)) $tz = $stored;
        } catch (\Throwable $e) {
            // fall through to default
        }
        return self::$userTzCache[$userId] = $tz;
    }

    /** DateTimeZone for a user (defaults to the logged-in viewer). */
    public static function forUser(?int $userId = null): DateTimeZone
    {
        return self::zone(self::tzString($userId));
    }

    /** Forget a cached user tz (call after updating a user's timezone). */
    public static function clearCache(?int $userId = null): void
    {
        if ($userId === null) self::$userTzCache = [];
        else unset(self::$userTzCache[$userId]);
    }

    // ── Conversion / display ─────────────────────────────────────────────────

    /** A UTC stored datetime string → DateTime in the user's local zone. */
    public static function toLocal(string $utcDateTime, ?int $userId = null): DateTime
    {
        $dt = new DateTime($utcDateTime, self::zone('UTC'));
        $dt->setTimezone(self::forUser($userId));
        return $dt;
    }

    /**
     * Format a UTC stored datetime in the user's local zone. Empty/null input
     * yields '' so callers can render it directly.
     */
    public static function format(?string $utcDateTime, string $fmt, ?int $userId = null): string
    {
        if ($utcDateTime === null || $utcDateTime === '') return '';
        try {
            return self::toLocal($utcDateTime, $userId)->format($fmt);
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ── "today" / "tomorrow" in a user's (or explicit zone's) local time ──────

    /** A date string ('Y-m-d' by default) "now $modify" in the given zone. */
    public static function dateInZone(string $tz, string $modify = 'now', string $fmt = 'Y-m-d'): string
    {
        $dt = new DateTime('now', self::zone($tz));
        if ($modify !== 'now' && $modify !== '') $dt->modify($modify);
        return $dt->format($fmt);
    }

    /** Local "today" for a user (defaults to the logged-in viewer). */
    public static function today(?int $userId = null): string
    {
        return self::dateInZone(self::tzString($userId), 'now');
    }

    /** Local "tomorrow" for a user (defaults to the logged-in viewer). */
    public static function tomorrow(?int $userId = null): string
    {
        return self::dateInZone(self::tzString($userId), '+1 day');
    }

    // ── Selector support ─────────────────────────────────────────────────────

    /** @return array<string,string> IANA id → "Label — UTC±hh:mm" for the dropdown. */
    public static function selectOptions(): array
    {
        $out = [];
        foreach (self::ZONE_LABELS as $tz => $label) {
            $out[$tz] = self::label($tz);
        }
        return $out;
    }

    /** Friendly label for a single tz (used in the dropdown and when echoing a stored value). */
    public static function label(string $tz): string
    {
        $base = self::ZONE_LABELS[$tz] ?? $tz;
        // The UTC entry already names itself; no offset suffix needed.
        if ($tz === 'UTC') return $base;
        $offset = self::currentOffsetLabel($tz);
        return $offset === '' ? $base : $base . ' — UTC' . $offset;
    }

    /** Current (DST-aware) offset like "-04:00", "+05:30", or "+00:00". '' on error. */
    private static function currentOffsetLabel(string $tz): string
    {
        try {
            $offset = (new DateTime('now', self::zone($tz)))->getOffset(); // seconds
        } catch (\Throwable $e) {
            return '';
        }
        $sign = $offset < 0 ? '-' : '+';
        $abs  = abs($offset);
        return sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));
    }
}

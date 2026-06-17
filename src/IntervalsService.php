<?php
/**
 * IntervalsService — all Intervals.icu API interaction.
 *
 * Intervals.icu is the Phase-1 watch integration layer (architecture §6): instead
 * of integrating Garmin/COROS/Polar/Suunto/Wahoo directly, athletes connect their
 * watch to a free Intervals.icu account, then connect Intervals.icu to SimplyRunFaster
 * via OAuth. We push structured workouts onto their Intervals.icu calendar (which syncs
 * to the watch) and pull completed run activities back (via backfill + webhooks).
 *
 * Account model note: Intervals.icu connections are keyed by users.id
 * (intervals_connections.user_id → users.id). Workout tables key on athletes.id, so
 * the workout-facing methods (pushWorkout/deleteWorkout) accept an athlete_id and
 * resolve the connection through athletes.user_id; the account-facing methods
 * (OAuth, pullActivity, backfill) accept a user_id.
 *
 * Tokens are stored encrypted at rest (Crypto / APP_ENCRYPTION_KEY). No real secrets
 * live in this file — credentials come from the INTERVALS_* config constants, which
 * are empty placeholders except in config/config.local.php on the server.
 */
class IntervalsService
{
    private const OAUTH_AUTHORIZE = 'https://intervals.icu/oauth/authorize';
    private const OAUTH_TOKEN     = 'https://intervals.icu/api/oauth/token';
    private const API_BASE        = 'https://intervals.icu/api/v1';
    private const SCOPE           = 'ACTIVITY:READ,CALENDAR:WRITE';
    private const EXTERNAL_PREFIX = 'srf_';
    private const METERS_PER_MILE = 1609.34;

    // ── OAuth ────────────────────────────────────────────────────────────────

    /** True only when client id/secret and an encryption key are all present. */
    public static function isConfigured(): bool
    {
        return defined('INTERVALS_CLIENT_ID') && INTERVALS_CLIENT_ID !== ''
            && defined('INTERVALS_CLIENT_SECRET') && INTERVALS_CLIENT_SECRET !== ''
            && Crypto::isConfigured();
    }

    /**
     * Build the Intervals.icu authorize URL and stash a CSRF state token in the
     * session. Scope is kept comma-separated (Intervals.icu's format); only the
     * surrounding params are percent-encoded.
     */
    public static function getAuthUrl(int $userId): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['intervals_oauth_state']   = $state;
        $_SESSION['intervals_oauth_user_id'] = $userId;

        $params = [
            'client_id'     => rawurlencode((string)INTERVALS_CLIENT_ID),
            'redirect_uri'  => rawurlencode((string)INTERVALS_REDIRECT_URI),
            'scope'         => self::SCOPE,             // commas intentionally literal
            'state'         => rawurlencode($state),
            'response_type' => 'code',
        ];
        $query = [];
        foreach ($params as $k => $v) {
            $query[] = $k . '=' . $v;
        }
        return self::OAUTH_AUTHORIZE . '?' . implode('&', $query);
    }

    /**
     * Exchange an authorization code for an access token and persist the connection
     * for the current session user. Returns true on success.
     */
    public static function exchangeCode(string $code, string $state, PDO $db): bool
    {
        $expected = $_SESSION['intervals_oauth_state'] ?? '';
        $userId   = (int)($_SESSION['intervals_oauth_user_id'] ?? 0);
        unset($_SESSION['intervals_oauth_state'], $_SESSION['intervals_oauth_user_id']);

        if ($expected === '' || !hash_equals($expected, $state) || $userId <= 0) {
            error_log('IntervalsService::exchangeCode — state/CSRF mismatch');
            return false;
        }
        if (!self::isConfigured()) {
            error_log('IntervalsService::exchangeCode — integration not configured');
            return false;
        }

        [$status, $body] = self::http('POST', self::OAUTH_TOKEN, [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id'     => INTERVALS_CLIENT_ID,
            'client_secret' => INTERVALS_CLIENT_SECRET,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => INTERVALS_REDIRECT_URI,
        ]));

        if ($status < 200 || $status >= 300) {
            error_log("IntervalsService::exchangeCode — token endpoint HTTP {$status}: " . substr($body, 0, 300));
            return false;
        }

        $data        = json_decode($body, true) ?: [];
        $accessToken = (string)($data['access_token'] ?? '');
        $athleteId   = (string)($data['athlete']['id'] ?? '');
        $scope       = (string)($data['scope'] ?? self::SCOPE);
        if ($accessToken === '' || $athleteId === '') {
            error_log('IntervalsService::exchangeCode — token response missing access_token/athlete.id');
            return false;
        }

        $enc = Crypto::encrypt($accessToken);
        if ($enc === null) {
            error_log('IntervalsService::exchangeCode — token encryption failed (no APP_ENCRYPTION_KEY?)');
            return false;
        }

        $db->prepare(
            'INSERT INTO intervals_connections
                (user_id, intervals_athlete_id, access_token_enc, scope, connected_at, sync_status)
             VALUES (?, ?, ?, ?, NOW(), "ok")
             ON DUPLICATE KEY UPDATE
                intervals_athlete_id = VALUES(intervals_athlete_id),
                access_token_enc     = VALUES(access_token_enc),
                scope                = VALUES(scope),
                connected_at         = NOW(),
                sync_status          = "ok",
                last_error           = NULL'
        )->execute([$userId, $athleteId, $enc, $scope]);

        return true;
    }

    /** Authorization header for a user's Intervals.icu token, or null if unavailable. */
    public static function getHeaders(int $userId, PDO $db): ?array
    {
        $conn = self::connectionForUser($userId, $db);
        if (!$conn) return null;
        $token = Crypto::decrypt($conn['access_token_enc'] ?? null);
        if ($token === null) return null;
        return ['Authorization: Bearer ' . $token];
    }

    // ── Connection lookup helpers ────────────────────────────────────────────

    /** intervals_connections row for a users.id, or null. Safe pre-migration. */
    public static function connectionForUser(int $userId, PDO $db): ?array
    {
        try {
            $stmt = $db->prepare('SELECT * FROM intervals_connections WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            return null; // table may not exist before migration_014
        }
    }

    /** intervals_connections row for an athletes.id (via athletes.user_id), or null. */
    public static function connectionForAthlete(int $athleteId, PDO $db): ?array
    {
        try {
            $stmt = $db->prepare(
                'SELECT ic.* FROM intervals_connections ic
                 JOIN athletes a ON a.user_id = ic.user_id
                 WHERE a.id = ? LIMIT 1'
            );
            $stmt->execute([$athleteId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            return null; // table may not exist before migration_014
        }
    }

    /** True when an athlete has a live Intervals.icu connection. */
    public static function athleteConnected(int $athleteId, PDO $db): bool
    {
        return self::connectionForAthlete($athleteId, $db) !== null;
    }

    /** Resolve the athletes.id for a users.id (athlete role), or null. */
    private static function athleteIdForUser(int $userId, PDO $db): ?int
    {
        $stmt = $db->prepare('SELECT id FROM athletes WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    // ── Workout push ─────────────────────────────────────────────────────────

    /**
     * Convert a planned_workouts row into Intervals.icu native workout text.
     *
     * Drives off the resolved `structure` JSON (the authority — segments + resolved
     * params). Effort/zone names map to Intervals.icu pace zones (Z1 easy … Z6 speed);
     * race_pace uses the athlete's goal pace when pace zones are supplied in $context.
     * Falls back to the plain description when structure is missing/unparseable.
     *
     * @param array $workout planned_workouts row (needs structure, archetype_params,
     *                       athlete_instructions/description, workout_type).
     * @param array $context optional ['pace_zones' => decoded zones, 'goal_distance' => key]
     */
    public static function generateWorkoutText(array $workout, array $context = []): string
    {
        $structure = json_decode((string)($workout['structure'] ?? ''), true);
        $params    = json_decode((string)($workout['archetype_params'] ?? ''), true) ?: [];
        $fallback  = trim((string)($workout['athlete_instructions'] ?? $workout['description'] ?? ''));

        if (!is_array($structure) || empty($structure['segments']) || !is_array($structure['segments'])) {
            if ($fallback === '') {
                error_log('IntervalsService::generateWorkoutText — no structure and no description for workout '
                    . (int)($workout['id'] ?? 0));
            }
            return $fallback;
        }

        $segments = $structure['segments'];
        $blocks   = [];
        foreach ($segments as $seg) {
            if (!is_array($seg)) continue;
            $rendered = self::renderSegment($seg, $params, $context);
            if ($rendered !== '') $blocks[] = $rendered;
        }

        $text = trim(implode("\n\n", $blocks));
        return $text !== '' ? $text : $fallback;
    }

    /**
     * Render one structure segment to an Intervals.icu text block.
     *
     * The stored structure JSON is the engine's resolved structure_template — note it
     * can still carry unresolved {{mapped_effort}} tokens (the engine resolves effort
     * for display text, not for the structure leaves), so effort is derived from the
     * segment TYPE here, not trusted from the token. Each quality archetype uses its
     * own segment_type (tempo_intervals, speed_repeats, hill_repeats, …); they are all
     * mapped explicitly so the main set is never silently dropped.
     */
    private static function renderSegment(array $seg, array $params, array $context): string
    {
        $type = strtolower((string)($seg['segment_type'] ?? ''));

        switch ($type) {
            case 'warmup':
                $m = (int)($seg['duration_minutes'] ?? $params['warmup_minutes'] ?? 0);
                return $m > 0 ? "Warmup\n- {$m}m Z1 Pace" : '';

            case 'cooldown':
                $m = (int)($seg['duration_minutes'] ?? $params['cooldown_minutes'] ?? 0);
                return $m > 0 ? "Cooldown\n- {$m}m Z1 Pace" : '';

            case 'strides':
                $n = (int)($seg['repetitions'] ?? $params['stride_count'] ?? 4);
                $s = (int)($seg['duration_seconds'] ?? $params['stride_duration_seconds'] ?? 15);
                if ($n < 1 || $s < 1) return '';
                return "Strides {$n}x\n- " . self::fmtSeconds($s) . " Z6 Pace\n- 45s Z1 Pace";

            case 'continuous':
                $m = (int)($seg['duration_minutes'] ?? $params['duration_minutes'] ?? 0);
                if ($m < 1) return '';
                return "- {$m}m " . self::zoneFor($seg['effort'] ?? $seg['pace_zone'] ?? 'easy', $context, 'Z2 Pace');

            case 'progression':
                // Single continuous block that lifts; split the time (don't double it).
                $m = (int)($seg['duration_minutes'] ?? $params['duration_minutes'] ?? 0);
                if ($m < 1) return '';
                $finish = self::zoneFor($seg['finish_zone'] ?? '', $context, 'Z4 Pace');
                $a = max(1, (int)round($m * 0.6));
                return "- {$a}m Z1 Pace\n- " . max(1, $m - $a) . "m {$finish}";

            case 'continuous_progression':
                $m = (int)($seg['continuous_work_minutes'] ?? $params['continuous_work_minutes'] ?? $params['duration_minutes'] ?? 0);
                if ($m < 1) return '';
                $a = max(1, (int)round($m * 0.6));
                return "- {$a}m Z2 Pace\n- " . max(1, $m - $a) . "m Z4 Pace";

            case 'repeats':
                return self::renderDistanceRepeats($seg, $params, $context, 'Z5 Pace', 'vo2_standard');

            case 'speed_repeats':
                return self::renderDistanceRepeats($seg, $params, $context, 'Z6 Pace', 'speed_standard');

            case 'tempo_intervals':
                return self::renderTempoIntervals($seg, $params, $context);

            case 'hill_repeats':
            case 'hill_sprints':
                return self::renderHillReps($type, $seg, $params);

            case 'fartlek_ladder':
                return self::renderFartlek($seg, $params, $context);

            default:
                // Unknown segment: detect a distance/time rep block, else nothing.
                return self::renderGenericWork($seg, $params, $context);
        }
    }

    /** Distance-based repeats: "Main Set Nx" + rep distance + recovery jog. */
    private static function renderDistanceRepeats(array $seg, array $params, array $context, string $fallbackZone, string $defaultRecModel): string
    {
        $n      = (int)($seg['repetitions'] ?? $seg['rep_count'] ?? $params['rep_count'] ?? 0);
        $meters = (int)($seg['rep_distance_meters'] ?? $params['rep_distance_meters'] ?? 0);
        if ($n < 1 || $meters < 1) return '';

        $effort = $seg['target_effort'] ?? $seg['effort'] ?? $seg['effort_zone'] ?? $params['target_effort'] ?? '';
        $zone   = self::zoneFor($effort, $context, $fallbackZone);

        $recModel = (string)($seg['recovery_model'] ?? $params['recovery_model'] ?? $defaultRecModel);
        $recSec   = (int)($params['recovery_duration_seconds'] ?? 0);
        if ($recSec < 1) {
            $repSec = self::estimateRepSeconds($meters, (string)$effort, $context);
            $recSec = self::modelRecoverySeconds($recModel, $repSec);
        }
        return self::repeatBlock($n, '- ' . self::fmtMeters($meters) . ' ' . $zone, self::roundSecs($recSec ?: 120));
    }

    /** tempo_intervals: time- (preferred) or distance-based reps at threshold. */
    private static function renderTempoIntervals(array $seg, array $params, array $context): string
    {
        $n = (int)($seg['rep_count'] ?? $params['rep_count'] ?? 0);
        if ($n < 1) return '';
        $zone     = self::zoneFor($seg['effort'] ?? '', $context, 'Z4 Pace');
        $recModel = (string)($seg['recovery_model'] ?? $params['recovery_model'] ?? 'threshold_standard');

        $workSec = 0;
        if (isset($seg['rep_duration_minutes'])) $workSec = (int)round((float)$seg['rep_duration_minutes'] * 60);
        elseif (isset($seg['rep_duration_seconds'])) $workSec = (int)$seg['rep_duration_seconds'];
        elseif (isset($params['work_duration_seconds'])) $workSec = (int)$params['work_duration_seconds'];

        if ($workSec > 0) {
            $recSec = self::roundSecs(self::modelRecoverySeconds($recModel, $workSec));
            return self::repeatBlock($n, '- ' . self::fmtSeconds($workSec) . ' ' . $zone, $recSec);
        }
        if (isset($seg['rep_distance_miles'])) {
            $meters = (int)round((float)$seg['rep_distance_miles'] * self::METERS_PER_MILE);
            $recSec = self::roundSecs(self::modelRecoverySeconds($recModel, self::estimateRepSeconds($meters, 'half_marathon', $context)));
            return self::repeatBlock($n, '- ' . self::fmtMeters($meters) . ' ' . $zone, $recSec);
        }
        return '';
    }

    /**
     * Hill reps / sprints. Hills are effort-based, not pace-based (flat pace zones do
     * not transfer to a climb — engine spec §18.5), so this approximates with a hard
     * zone label + jog/walk-back recovery. Reviewer note: refine if a hill-specific
     * target is preferred over Z5/Z6.
     */
    private static function renderHillReps(string $type, array $seg, array $params): string
    {
        $n      = (int)($seg['repetitions'] ?? $seg['rep_count'] ?? $params['rep_count'] ?? 0);
        $durSec = (int)($seg['rep_duration_seconds'] ?? $seg['duration_seconds'] ?? 0);
        if ($n < 1 || $durSec < 1) return '';
        $recMin = (int)($seg['recovery']['minimum_recovery_seconds'] ?? 0);
        $recSec = self::roundSecs($recMin > 0 ? $recMin : max(90, $durSec));

        // Hills are effort-, not pace-based, so name each step with the effort cue and
        // the jog/walk-back recovery so the cue rides along on the watch.
        if ($type === 'hill_sprints') {
            $zone      = 'Z6 Pace';
            $effortCue = 'uphill sprint — near-maximal but controlled';
        } else {
            $zone      = 'Z5 Pace';
            $effortCue = 'uphill — strong, controlled effort';
        }
        $between = strtolower((string)($seg['recovery']['between_reps'] ?? ''));
        $recCue  = str_contains($between, 'walk') ? 'walk back down, full recovery' : 'jog back down';

        $lines = ["Main Set {$n}x"];
        $lines[] = '- ' . self::fmtSeconds($durSec) . ' ' . $zone . ' ' . $effortCue;
        if ($recSec > 0) {
            $lines[] = '- ' . self::fmtSeconds($recSec) . ' Z1 Pace ' . $recCue;
        }
        return implode("\n", $lines);
    }

    /** Fartlek ladder: one round of the work intervals, repeated $rounds times. */
    private static function renderFartlek(array $seg, array $params, array $context): string
    {
        $rounds = (int)($seg['rounds'] ?? $params['round_count'] ?? 1);
        $works  = $seg['work_intervals_seconds'] ?? $params['work_intervals_seconds'] ?? [];
        if (is_string($works)) $works = json_decode($works, true) ?: [];
        if (!is_array($works) || empty($works)) {
            $works = [60, 90, 120];
        }
        $zone  = self::zoneFor('interval', $context, 'Z5 Pace');
        $lines = ["Main Set {$rounds}x"];
        foreach ($works as $w) {
            $w = (int)$w;
            if ($w < 1) continue;
            $lines[] = '- ' . self::fmtSeconds($w) . ' ' . $zone;
            $lines[] = '- ' . self::fmtSeconds($w) . ' Z1 Pace'; // ~1:1 float recovery
        }
        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /** Generic fallback: detect a distance or time rep block, else nothing. */
    private static function renderGenericWork(array $seg, array $params, array $context): string
    {
        $n = (int)($seg['repetitions'] ?? $seg['rep_count'] ?? $params['rep_count'] ?? 0);
        if ($n >= 1 && (int)($seg['rep_distance_meters'] ?? 0) >= 1) {
            return self::renderDistanceRepeats($seg, $params, $context, 'Z5 Pace', 'vo2_standard');
        }

        $workSec = (int)($seg['work_duration_seconds'] ?? $params['work_duration_seconds'] ?? 0);
        if ($workSec < 1 && isset($seg['rep_duration_minutes'])) $workSec = (int)round((float)$seg['rep_duration_minutes'] * 60);
        if ($workSec < 1 && isset($seg['duration_minutes']))     $workSec = (int)$seg['duration_minutes'] * 60;
        if ($n < 1 || $workSec < 1) return '';

        $recSec = (int)($seg['recovery_duration_seconds'] ?? $params['recovery_duration_seconds'] ?? 0);
        if ($recSec < 1) {
            $recSec = self::modelRecoverySeconds((string)($seg['recovery_model'] ?? $params['recovery_model'] ?? ''), $workSec);
        }
        $zone = self::zoneFor($seg['effort'] ?? $seg['target_effort'] ?? '', $context, 'Z5 Pace');
        return self::repeatBlock($n, '- ' . self::fmtSeconds($workSec) . ' ' . $zone, self::roundSecs($recSec));
    }

    /** Assemble a "Main Set Nx" block from a work line and an optional recovery jog. */
    private static function repeatBlock(int $n, string $workLine, ?int $recSec): string
    {
        if ($n < 1 || $workLine === '') return '';
        $lines = ["Main Set {$n}x", $workLine];
        if ($recSec !== null && $recSec > 0) {
            $lines[] = '- ' . self::fmtSeconds($recSec) . ' Z1 Pace';
        }
        return implode("\n", $lines);
    }

    /** Round a recovery duration to a tidy value (5s under 1min, 15s under 3min, else 30s). */
    private static function roundSecs(int $s): int
    {
        if ($s <= 0)   return 0;
        if ($s < 60)   return (int)(round($s / 5) * 5);
        if ($s < 180)  return (int)(round($s / 15) * 15);
        return (int)(round($s / 30) * 30);
    }

    /** Estimate a single rep's duration (seconds) from pace zones, else a flat guess. */
    private static function estimateRepSeconds(int $meters, $effort, array $context): int
    {
        $zones = $context['pace_zones'] ?? null;
        $miles = $meters / self::METERS_PER_MILE;
        $key   = self::paceKeyForEffort((string)$effort);
        if (is_array($zones) && isset($zones[$key]) && is_numeric($zones[$key]) && (int)$zones[$key] > 0) {
            return (int)round($miles * (int)$zones[$key]); // secs/mile * miles
        }
        // ~6:30/mi fallback when zones are hidden/absent.
        return (int)round($miles * 390);
    }

    /** Recovery seconds from a recovery-model slug applied to a work duration. */
    private static function modelRecoverySeconds(string $model, int $workSeconds): int
    {
        if (!class_exists('RecoveryModel')) {
            return $workSeconds > 0 ? $workSeconds : 90;
        }
        $r = RecoveryModel::get($model ?: 'vo2_standard');
        if (($r['type'] ?? '') === 'ratio' && $r['ratio'] !== null && $workSeconds > 0) {
            return (int)round($workSeconds * (float)$r['ratio']);
        }
        if ($r['fixed_seconds'] !== null) {
            return (int)$r['fixed_seconds'];
        }
        return $workSeconds > 0 ? $workSeconds : 90;
    }

    // ── Effort / zone / formatting helpers ───────────────────────────────────

    /**
     * Map a segment effort/zone to an Intervals.icu pace target string. Unresolved
     * tokens (e.g. "{{mapped_effort}}") and empty values fall back to $fallback so the
     * caller's type-appropriate default (Z4 tempo, Z5 interval, Z6 speed) is used.
     */
    private static function zoneFor($effort, array $context, string $fallback = 'Z2 Pace'): string
    {
        $e = strtolower(trim((string)$effort));
        if ($e === '' || str_contains($e, '{{')) {
            return $fallback;
        }

        // race_pace: prefer the athlete's goal pace from supplied pace zones.
        if ($e === 'race_pace' || $e === 'goal_pace') {
            $zones = $context['pace_zones'] ?? null;
            $key   = $context['goal_distance'] ?? null;
            if (is_array($zones) && $key !== null && isset($zones[$key]) && is_numeric($zones[$key])) {
                $secs = (int)$zones[$key];
                return sprintf('%d:%02d/mi Pace', intdiv($secs, 60), $secs % 60);
            }
            return 'Z3 Pace';
        }

        return match (true) {
            in_array($e, ['easy', 'recovery', 'warmup', 'cooldown', 'z1', 'jog', 'rest', 'walk'], true) => 'Z1 Pace',
            in_array($e, ['moderate', 'steady', 'aerobic', 'z2'], true)                                 => 'Z2 Pace',
            in_array($e, ['marathon', 'marathon_pace', 'z3'], true)                                      => 'Z3 Pace',
            in_array($e, ['tempo', 'threshold', 'half_marathon', 'steady_state', 'z4'], true)            => 'Z4 Pace',
            in_array($e, ['interval', 'vo2', 'vo2max', '10k', '5k', '3k', 'z5'], true)                   => 'Z5 Pace',
            in_array($e, ['speed', 'sprint', 'mile', '800', '400', 'rep', 'neuromuscular', 'z6'], true)  => 'Z6 Pace',
            default                                                                                       => $fallback,
        };
    }

    /** Pace-zone key (PaceZones output keys) nearest to a rep target effort. */
    private static function paceKeyForEffort(string $effort): string
    {
        $e = strtolower(trim($effort));
        return match (true) {
            in_array($e, ['400', '800', 'mile', 'speed', 'z6'], true) => $e === '400' ? '400' : ($e === '800' ? '800' : 'mile'),
            in_array($e, ['3k', '5k', 'z5'], true)                    => '5K',
            in_array($e, ['10k'], true)                               => '10K',
            in_array($e, ['half_marathon', 'threshold', 'tempo', 'z4'], true) => 'half_marathon',
            in_array($e, ['marathon', 'z3'], true)                    => 'marathon',
            default                                                   => '5K',
        };
    }

    /**
     * Format seconds for Intervals.icu native text in human-readable minutes/seconds:
     * whole minutes as "Xm", sub-minute as "Xs", and a remainder as compound "XmYs"
     * (e.g. 135 -> "2m15s", 90 -> "1m30s"). The compact compound form (no space) is
     * the Intervals.icu duration grammar and stays consistent with the "15m"/"30s"
     * single-unit steps elsewhere in the text.
     */
    private static function fmtSeconds(int $secs): string
    {
        if ($secs <= 0) return '0s';
        $m = intdiv($secs, 60);
        $s = $secs % 60;
        if ($m === 0) return "{$s}s";
        if ($s === 0) return "{$m}m";
        return "{$m}m{$s}s";
    }

    /** Format meters as Xkm (Intervals.icu treats bare m as minutes). */
    private static function fmtMeters(int $meters): string
    {
        $km = round($meters / 1000, 2);
        // Trim trailing zeros: 0.40 -> 0.4, 1.00 -> 1
        $km = rtrim(rtrim(number_format($km, 2, '.', ''), '0'), '.');
        return $km . 'km';
    }

    /**
     * Push a planned workout to the athlete's Intervals.icu calendar (bulk upsert by
     * external_id srf_{id}). Returns true on success. Best-effort: never throws.
     */
    public static function pushWorkout(int $athleteId, int $plannedWorkoutId, PDO $db): bool
    {
        try {
            $conn = self::connectionForAthlete($athleteId, $db);
            if (!$conn) return false;

            $stmt = $db->prepare(
                'SELECT pw.*, ap.pace_zones, ap.pace_zones_visible, ap.goal_race_distance
                 FROM planned_workouts pw
                 JOIN athlete_profiles ap ON ap.athlete_id = pw.athlete_id
                 WHERE pw.id = ? AND pw.athlete_id = ? LIMIT 1'
            );
            $stmt->execute([$plannedWorkoutId, $athleteId]);
            $workout = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$workout) return false;

            // Don't push rest days or cancelled workouts.
            if (!empty($workout['cancelled']) || ($workout['workout_type'] ?? '') === 'rest') {
                return false;
            }

            $context = self::paceContext($workout);
            $text    = self::generateWorkoutText($workout, $context);

            $name     = trim((string)($workout['display_title'] ?? '')) ?: ucfirst((string)$workout['workout_type']) . ' run';
            $duration = (int)($workout['target_duration'] ?? 0);
            $event = [[
                'category'         => 'WORKOUT',
                'start_date_local' => (string)$workout['scheduled_date'] . 'T00:00:00',
                'type'             => 'Run',
                'name'             => $name,
                'description'      => $text,
                'moving_time'      => $duration > 0 ? $duration * 60 : null,
                'external_id'      => self::EXTERNAL_PREFIX . $plannedWorkoutId,
            ]];

            $headers = self::getHeaders((int)$conn['user_id'], $db);
            if ($headers === null) return false;
            $headers[] = 'Content-Type: application/json';

            [$status, $body] = self::http(
                'POST',
                self::API_BASE . '/athlete/0/events/bulk?upsert=true',
                $headers,
                json_encode($event)
            );

            if ($status < 200 || $status >= 300) {
                self::logPush($db, $plannedWorkoutId, null, 'failed', "HTTP {$status}: " . substr($body, 0, 250));
                self::markConnectionError($db, (int)$conn['user_id'], "push HTTP {$status}");
                return false;
            }

            $eventId = self::firstEventId($body);
            $db->prepare(
                'UPDATE planned_workouts SET intervals_event_id = ?, pushed_to_watch = 1, pushed_at = NOW() WHERE id = ?'
            )->execute([$eventId, $plannedWorkoutId]);
            self::logPush($db, $plannedWorkoutId, $eventId, 'success', null);
            return true;
        } catch (\Throwable $e) {
            error_log('IntervalsService::pushWorkout failed: ' . $e->getMessage());
            try { self::logPush($db, $plannedWorkoutId, null, 'failed', $e->getMessage()); } catch (\Throwable $e2) {}
            return false;
        }
    }

    /**
     * Push every currently-visible, not-yet-pushed, future workout in a plan (used
     * after plan approval, when a whole window opens at once). No-op when the athlete
     * isn't connected. Returns the number of successful pushes.
     */
    public static function pushNewlyVisible(int $athleteId, int $planId, PDO $db): int
    {
        if (!self::athleteConnected($athleteId, $db)) return 0;

        $stmt = $db->prepare(
            'SELECT id FROM planned_workouts
             WHERE plan_id = ? AND athlete_id = ? AND visible_to_athlete = 1
               AND (cancelled = 0 OR cancelled IS NULL)
               AND workout_type <> "rest"
               AND intervals_event_id IS NULL
               AND scheduled_date >= CURDATE()'
        );
        $stmt->execute([$planId, $athleteId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $pushed = 0;
        foreach ($ids as $id) {
            if (self::pushWorkout($athleteId, (int)$id, $db)) $pushed++;
        }
        return $pushed;
    }

    /**
     * Remove a workout's event from Intervals.icu by external_id (preferred over the
     * stored event id for reliability). Best-effort.
     */
    public static function deleteWorkout(int $athleteId, int $plannedWorkoutId, PDO $db): bool
    {
        try {
            $conn = self::connectionForAthlete($athleteId, $db);
            if (!$conn) return false;

            $headers = self::getHeaders((int)$conn['user_id'], $db);
            if ($headers === null) return false;
            $headers[] = 'Content-Type: application/json';

            $body = json_encode([['external_id' => self::EXTERNAL_PREFIX . $plannedWorkoutId]]);
            [$status] = self::http('PUT', self::API_BASE . '/athlete/0/events/bulk-delete', $headers, $body);

            $db->prepare('UPDATE planned_workouts SET intervals_event_id = NULL, pushed_to_watch = 0 WHERE id = ?')
               ->execute([$plannedWorkoutId]);

            return $status >= 200 && $status < 300;
        } catch (\Throwable $e) {
            error_log('IntervalsService::deleteWorkout failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Pace-zone citation context for generateWorkoutText, mirroring PlanGenerator. */
    private static function paceContext(array $workout): array
    {
        $zones = null;
        if (!empty($workout['pace_zones_visible']) && !empty($workout['pace_zones'])) {
            $decoded = json_decode((string)$workout['pace_zones'], true);
            if (is_array($decoded)) $zones = $decoded;
        }
        $goal = self::normalizeGoalKey((string)($workout['goal_race_distance'] ?? ''));
        return ['pace_zones' => $zones, 'goal_distance' => $goal];
    }

    /** Map a goal_race_distance to a PaceZones output key. */
    private static function normalizeGoalKey(string $d): string
    {
        $d = strtolower(trim($d));
        return match (true) {
            str_contains($d, 'marathon') && !str_contains($d, 'half') => 'marathon',
            str_contains($d, 'half')                                  => 'half_marathon',
            str_contains($d, '10')                                    => '10K',
            default                                                   => '5K',
        };
    }

    /** Extract the first event id from a bulk-events response body. */
    private static function firstEventId(string $body): ?string
    {
        $data = json_decode($body, true);
        if (is_array($data)) {
            $first = $data[0] ?? $data;
            if (is_array($first) && isset($first['id'])) {
                return (string)$first['id'];
            }
        }
        return null;
    }

    private static function logPush(PDO $db, int $plannedWorkoutId, ?string $eventId, string $status, ?string $error): void
    {
        $db->prepare(
            'INSERT INTO intervals_push_log (planned_workout_id, intervals_event_id, pushed_at, status, error_message)
             VALUES (?, ?, NOW(), ?, ?)'
        )->execute([$plannedWorkoutId, $eventId, $status, $error]);
    }

    private static function markConnectionError(PDO $db, int $userId, string $error): void
    {
        $db->prepare('UPDATE intervals_connections SET sync_status = "error", last_error = ? WHERE user_id = ?')
           ->execute([substr($error, 0, 1000), $userId]);
    }

    // ── Activity pull ────────────────────────────────────────────────────────

    /**
     * Pull a single Intervals.icu activity into completed_workouts (idempotent on
     * (source, external_activity_id)). Only Run activities are processed. Runs the
     * post-completion pipeline (compliance, training load, RPE prompt, RTR progression).
     * Returns true when a run row was inserted/updated. Best-effort: never throws.
     */
    public static function pullActivity(int $userId, string $activityId, PDO $db): bool
    {
        try {
            $athleteId = self::athleteIdForUser($userId, $db);
            $headers   = self::getHeaders($userId, $db);
            if ($athleteId === null || $headers === null) return false;

            [$status, $body] = self::http('GET', self::API_BASE . '/athlete/0/activities/' . rawurlencode($activityId), $headers, null);
            if ($status < 200 || $status >= 300) {
                error_log("IntervalsService::pullActivity — HTTP {$status} for activity {$activityId}");
                return false;
            }

            $a = json_decode($body, true) ?: [];
            if (strcasecmp((string)($a['type'] ?? ''), 'Run') !== 0) {
                return false; // skip non-runs silently
            }

            $localDate = substr((string)($a['start_date_local'] ?? ($a['local_date'] ?? '')), 0, 10);
            if ($localDate === '') $localDate = date('Y-m-d');

            $distanceMi = isset($a['distance']) ? round((float)$a['distance'] / self::METERS_PER_MILE, 2) : null;
            $durationMin = isset($a['moving_time']) ? (int)round((int)$a['moving_time'] / 60) : null;
            $avgHr      = isset($a['average_heartrate']) ? (int)round((float)$a['average_heartrate']) : null;
            $maxHr      = isset($a['max_heartrate']) ? (int)round((float)$a['max_heartrate']) : null;
            $device     = self::deviceFromPayload($a);

            // ── Match a planned workout ──
            $plannedId   = self::matchPlannedWorkout($a, $athleteId, $localDate, $db);
            $compliance  = self::complianceScore($plannedId, $localDate, $db);

            // Idempotent upsert keyed by (source, external_activity_id).
            $db->prepare(
                'INSERT INTO completed_workouts
                    (athlete_id, planned_workout_id, source, source_device, external_activity_id,
                     activity_date, workout_type, actual_distance, actual_duration, avg_hr, max_hr,
                     compliance_score, raw_data, synced_at)
                 VALUES (?, ?, "intervals", ?, ?, ?, "run", ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    planned_workout_id = VALUES(planned_workout_id),
                    source_device      = VALUES(source_device),
                    activity_date      = VALUES(activity_date),
                    actual_distance    = VALUES(actual_distance),
                    actual_duration    = VALUES(actual_duration),
                    avg_hr             = VALUES(avg_hr),
                    max_hr             = VALUES(max_hr),
                    compliance_score   = VALUES(compliance_score),
                    raw_data           = VALUES(raw_data),
                    synced_at          = NOW()'
            )->execute([
                $athleteId, $plannedId, $device, $activityId,
                $localDate, $distanceMi, $durationMin, $avgHr, $maxHr,
                $compliance, json_encode($a),
            ]);

            if ($plannedId === null) {
                self::raiseUnmatchedFlag($athleteId, $localDate, $activityId, $db);
            }

            // ── Post-completion pipeline (best-effort) ──
            self::runPostCompletion($athleteId, $plannedId, $localDate, $db);

            return true;
        } catch (\Throwable $e) {
            error_log('IntervalsService::pullActivity failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Import the last $days of Run activities for a user. Returns the number of
     * activities imported. Updates last_synced_at.
     */
    public static function backfillActivities(int $userId, int $days, PDO $db): int
    {
        $headers = self::getHeaders($userId, $db);
        if ($headers === null) return 0;

        $newest = date('Y-m-d');
        $oldest = date('Y-m-d', strtotime("-{$days} days"));
        $url = self::API_BASE . '/athlete/0/activities?' . http_build_query([
            'oldest' => $oldest,
            'newest' => $newest,
        ]);

        [$status, $body] = self::http('GET', $url, $headers, null);
        if ($status < 200 || $status >= 300) {
            self::markConnectionError($db, $userId, "backfill HTTP {$status}");
            return 0;
        }

        $activities = json_decode($body, true) ?: [];
        $count = 0;
        foreach ($activities as $a) {
            if (!is_array($a)) continue;
            if (strcasecmp((string)($a['type'] ?? ''), 'Run') !== 0) continue;
            $id = (string)($a['id'] ?? '');
            if ($id === '') continue;
            if (self::pullActivity($userId, $id, $db)) $count++;
        }

        $db->prepare('UPDATE intervals_connections SET last_synced_at = NOW(), sync_status = "ok", last_error = NULL WHERE user_id = ?')
           ->execute([$userId]);

        return $count;
    }

    /** Best-guess source device from the activity payload, or null. */
    private static function deviceFromPayload(array $a): ?string
    {
        $hay = strtolower((string)($a['device_name'] ?? '') . ' ' . (string)($a['source'] ?? '') . ' ' . (string)($a['external_id'] ?? ''));
        foreach (['garmin', 'coros', 'polar', 'suunto', 'wahoo'] as $brand) {
            if (str_contains($hay, $brand)) return $brand;
        }
        return null;
    }

    /**
     * Resolve the planned_workout_id an activity should attach to:
     *   1) external_id 'srf_{id}' on the activity (or its paired calendar event)
     *   2) same athlete + date + Run + nearest unmatched planned workout that day
     *   3) null (caller inserts unplanned + raises an info flag)
     */
    private static function matchPlannedWorkout(array $a, int $athleteId, string $localDate, PDO $db): ?int
    {
        // 1) external_id on the activity itself or a paired calendar event.
        foreach ([$a['external_id'] ?? null, $a['paired_event_id'] ?? null, ($a['calendar_event']['external_id'] ?? null)] as $candidate) {
            $pid = self::plannedIdFromExternal($candidate);
            if ($pid !== null) {
                $chk = $db->prepare('SELECT id FROM planned_workouts WHERE id = ? AND athlete_id = ? LIMIT 1');
                $chk->execute([$pid, $athleteId]);
                if ($chk->fetchColumn()) return $pid;
            }
        }

        // 2) Nearest unmatched planned Run-ish workout on that date.
        $stmt = $db->prepare(
            'SELECT pw.id FROM planned_workouts pw
             WHERE pw.athlete_id = ? AND pw.scheduled_date = ?
               AND (pw.cancelled = 0 OR pw.cancelled IS NULL)
               AND pw.workout_type NOT IN ("rest","cross_train")
               AND NOT EXISTS (
                   SELECT 1 FROM completed_workouts cw WHERE cw.planned_workout_id = pw.id
               )
             ORDER BY pw.id ASC LIMIT 1'
        );
        $stmt->execute([$athleteId, $localDate]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    /** Parse a 'srf_{id}' external id into a planned workout id, or null. */
    private static function plannedIdFromExternal($external): ?int
    {
        if (!is_string($external) || $external === '') return null;
        if (preg_match('/^' . preg_quote(self::EXTERNAL_PREFIX, '/') . '(\d+)$/', $external, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /** compliance_score: 1.0 same day, 0.5 same week, else 0. */
    private static function complianceScore(?int $plannedId, string $activityDate, PDO $db): ?float
    {
        if ($plannedId === null) return null;
        $stmt = $db->prepare('SELECT scheduled_date FROM planned_workouts WHERE id = ? LIMIT 1');
        $stmt->execute([$plannedId]);
        $planned = $stmt->fetchColumn();
        if (!$planned) return null;
        if ($planned === $activityDate) return 1.0;

        $diff = abs((strtotime($activityDate) - strtotime((string)$planned)) / 86400);
        return $diff <= 6 ? 0.5 : 0.0;
    }

    /** Raise the info-level 'unmatched_activity' flag (mirrors PlanGenerator::raiseFlag). */
    private static function raiseUnmatchedFlag(int $athleteId, string $date, string $activityId, PDO $db): void
    {
        try {
            $db->prepare(
                'INSERT INTO engine_flags
                    (athlete_id, flag_type, severity, flag_date, details, message, status, created_at)
                 VALUES (?, "unmatched_activity", "info", CURDATE(), ?, ?, "open", NOW())'
            )->execute([
                $athleteId,
                json_encode(['activity_date' => $date, 'intervals_activity_id' => $activityId]),
                "Imported a run on {$date} that didn't match a planned workout.",
            ]);
        } catch (\Throwable $e) {
            error_log('IntervalsService::raiseUnmatchedFlag failed: ' . $e->getMessage());
        }
    }

    /**
     * Post-completion pipeline: recompute training load, prompt for RPE on
     * quality/long sessions, and advance return-to-running progression. All guarded.
     */
    private static function runPostCompletion(int $athleteId, ?int $plannedId, string $date, PDO $db): void
    {
        // Training load forward from this date.
        try {
            if (class_exists('TrainingLoad')) TrainingLoad::recompute($athleteId);
        } catch (\Throwable $e) {
            error_log('IntervalsService post-completion training load: ' . $e->getMessage());
        }

        if ($plannedId === null) return;

        $stmt = $db->prepare('SELECT workout_type, archetype_code FROM planned_workouts WHERE id = ? LIMIT 1');
        $stmt->execute([$plannedId]);
        $pw = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $type = (string)($pw['workout_type'] ?? '');

        // Quality / long with no athlete RPE yet → prompt for effort.
        $isQuality = in_array($type, ['tempo', 'interval', 'hill', 'fartlek', 'race_pace', 'speed', 'plyometric', 'long'], true);
        if ($isQuality) {
            try {
                $hasRpe = $db->prepare(
                    'SELECT 1 FROM completed_workouts WHERE planned_workout_id = ? AND rpe IS NOT NULL LIMIT 1'
                );
                $hasRpe->execute([$plannedId]);
                if (!$hasRpe->fetchColumn() && class_exists('Notifications')) {
                    $ctx = Notifications::athleteContext($athleteId);
                    if ($ctx['athlete_user_id']) {
                        Notifications::send($ctx['athlete_user_id'], 'rpe_prompt', [
                            'workout_name' => 'your workout',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                error_log('IntervalsService post-completion rpe prompt: ' . $e->getMessage());
            }
        }

        // Return-to-running adaptive progression (no-op for other plan types).
        try {
            if (class_exists('PlanGenerator') && ($pw['archetype_code'] ?? '') === 'run_walk_intervals') {
                PlanGenerator::onRunWalkCompletion($athleteId, $plannedId, 'moderate', $db);
            }
        } catch (\Throwable $e) {
            error_log('IntervalsService post-completion RTR: ' . $e->getMessage());
        }
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * Minimal cURL wrapper. Returns [status_code, response_body]. Network failures
     * yield [0, '']. $body is a string (form-encoded or JSON) or null.
     */
    private static function http(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) {
            error_log('IntervalsService::http — curl error: ' . curl_error($ch));
            curl_close($ch);
            return [0, ''];
        }
        curl_close($ch);
        return [$status, (string)$resp];
    }
}

<?php
/**
 * ProfileForm — shared logic for the athlete "Training Settings" page and
 * the coach profile-edit page.
 *
 *  - sanitize():     normalise a $_POST payload into DB-column values
 *  - save():         write athlete_profiles (+ updated_at), re-derive
 *                    easy-pace zones when appropriate, and raise a
 *                    'profile_updated' change flag with a field-by-field diff
 *
 * Saving NEVER triggers plan regeneration — values are simply made available
 * for the next plan generation/rebuild to read.
 */
class ProfileForm
{
    /** Day-of-week labels, index 0 = Sunday. */
    public const DAYS = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

    /** Goal race distance options (mirrors onboarding step 1). Ultra entries are
     *  canonical keys; use race_distance_label() for display. */
    public const RACE_DISTANCES = ['5K','10K','15K','Half Marathon','Marathon','mile','50k','50_miler','100k','100_miler'];

    /**
     * Athlete-facing editable fields (also the Step-4 diff set).
     * type drives both sanitising and diff formatting.
     */
    private const ATHLETE_FIELDS = [
        'goal_race_distance'        => ['label' => 'Goal race distance',  'type' => 'enum',  'options' => ['5K','10K','15K','Half Marathon','Marathon','mile','50k','50_miler','100k','100_miler']],
        'ultra_surface'             => ['label' => 'Ultra surface',        'type' => 'enum',  'options' => ['trail','road']],
        'goal_race_date'            => ['label' => 'Goal race date',      'type' => 'date'],
        'goal_finish_time'          => ['label' => 'Goal finish time',    'type' => 'string'],
        'current_weekly_minutes'    => ['label' => 'Weekly volume',       'type' => 'minutes'],
        'longest_recent_run_mins'   => ['label' => 'Longest recent run',  'type' => 'minutes'],
        'years_running'             => ['label' => 'Years running',       'type' => 'float'],
        'months_at_current_volume'  => ['label' => 'Months at volume',    'type' => 'int'],
        'peak_weekly_minutes'       => ['label' => 'Highest weekly volume','type' => 'minutes'],
        'training_days_per_week'    => ['label' => 'Training days',       'type' => 'int'],
        'must_off_days'             => ['label' => 'Must-off days',       'type' => 'days'],
        'scheduling_preference'     => ['label' => 'Scheduling',          'type' => 'enum',  'options' => ['fixed','flex']],
        'long_run_day'              => ['label' => 'Long run day',        'type' => 'dow'],
        'primary_workout_day'       => ['label' => 'Primary workout day', 'type' => 'dow'],
        'injury_history'            => ['label' => 'Injury history',      'type' => 'text'],
        'track_access'              => ['label' => 'Track access',        'type' => 'enum',  'options' => ['yes','no','road_reps_ok']],
        'cross_training_bike'       => ['label' => 'Bike',               'type' => 'enum',  'options' => ['none','stationary','road_gravel']],
        'cross_training_elliptical' => ['label' => 'Elliptical',         'type' => 'enum',  'options' => ['none','gym','home']],
        'cross_training_pool'       => ['label' => 'Pool access',        'type' => 'bool'],
        'cross_training_other'      => ['label' => 'Other cross-training','type' => 'text'],
        'typical_easy_pace_min'     => ['label' => 'Typical easy pace (fast end)', 'type' => 'pace'],
        'typical_easy_pace_max'     => ['label' => 'Typical easy pace (slow end)', 'type' => 'pace'],
        // Most recent race (drives race_result pace zones). Time stored as canonical
        // SECONDS via the 'race_time' type (hh:mm:ss / mm:ss), never a raw-seconds field.
        'most_recent_race_distance' => ['label' => 'Most recent race distance', 'type' => 'enum', 'options' => self::RACE_DISTANCES],
        'most_recent_race_time'     => ['label' => 'Most recent race time', 'type' => 'race_time'],
        'most_recent_race_date'     => ['label' => 'Most recent race date', 'type' => 'date'],
        // Equipment self-report (engine gates). plyometric_clearance is coach-only (a
        // safety/readiness gate) — see COACH_FIELDS.
        'hill_access'               => ['label' => 'Hill access', 'type' => 'bool'],
        'track_field_background'    => ['label' => 'Track/field background', 'type' => 'bool'],
        // Return-to-running only (shown when plan_type = return_to_running). Persisted
        // only when the RTR section is present (see sanitize()); medical_clearance_at is
        // a derived timestamp set as a side-effect in save().
        'medical_clearance_confirmed' => ['label' => 'Medical clearance confirmed', 'type' => 'bool'],
        'return_time_off_band'      => ['label' => 'Time off before returning', 'type' => 'enum', 'options' => ['1_2_weeks','2_6_weeks','6_16_weeks','4_12_months','12_plus_months']],
    ];

    /** Coach-only fields — recorded in the diff details, never surfaced in the athlete-facing message. */
    private const COACH_FIELDS = [
        // plan_type is a coaching decision, not athlete self-service. recovery_block is
        // engine-managed but whitelisted so an athlete already on it is never nulled.
        'plan_type'                 => ['label' => 'Plan type', 'type' => 'enum', 'options' => ['race_cycle','development_plan','maintenance_plan','return_to_running','recovery_block']],
        'peak_volume_ceiling_mins'  => ['label' => 'Peak volume ceiling', 'type' => 'minutes'],
        // plyometric_clearance gates higher-injury-risk work — a coach readiness gate.
        'plyometric_clearance'      => ['label' => 'Plyometric clearance', 'type' => 'bool'],
        'pace_zones_visible'        => ['label' => 'Pace zones visible',   'type' => 'bool'],
        'pace_zones_hidden_reason'  => ['label' => 'Pace zones hidden reason', 'type' => 'text'],
    ];

    /** Field metadata for a given scope. */
    public static function fields(bool $coach = false): array
    {
        return $coach
            ? array_merge(self::ATHLETE_FIELDS, self::COACH_FIELDS)
            : self::ATHLETE_FIELDS;
    }

    // ── Sanitising ───────────────────────────────────────────────────────

    /**
     * Normalise a POST payload to DB-column values for the given scope.
     * Only keys belonging to the scope are returned.
     */
    public static function sanitize(array $post, bool $coach = false): array
    {
        $out = [];
        foreach (self::fields($coach) as $col => $def) {
            $out[$col] = self::sanitizeField($col, $def, $post);
        }
        // Dual-path entry: derive the canonical volume / longest-run minutes from
        // whichever method the form used (time or distance+pace). Derivation falls
        // back to a directly-posted value, so a payload without method fields is
        // unchanged. Only override when a usable value was derived.
        $w = self::deriveWeeklyMinutes($post);
        if ($w !== null) $out['current_weekly_minutes'] = $w;
        $l = self::deriveLongestRunMinutes($post);
        if ($l !== null) $out['longest_recent_run_mins'] = $l;
        // scheduling_preference is NOT NULL with a default; never store null.
        if (array_key_exists('scheduling_preference', $out) && $out['scheduling_preference'] === null) {
            $out['scheduling_preference'] = 'flex';
        }
        // Drop fixed-only day fields when scheduling is flex.
        if (($out['scheduling_preference'] ?? 'flex') !== 'fixed') {
            $out['long_run_day']       = null;
            $out['primary_workout_day'] = null;
        }
        // RTR-only fields are gated in save() on the EFFECTIVE plan type (the athlete
        // form no longer posts plan_type — it's coach-only — so we can't gate here).
        return $out;
    }

    private static function sanitizeField(string $col, array $def, array $post)
    {
        $raw = $post[$col] ?? null;
        switch ($def['type']) {
            case 'int':
            case 'minutes':
                return ($raw === null || $raw === '') ? null : max(0, (int)$raw);
            case 'float':
                return ($raw === null || $raw === '') ? null : max(0, (float)$raw);
            case 'bool':
                return !empty($raw) ? 1 : 0;
            case 'enum':
                return in_array($raw, $def['options'], true) ? $raw : null;
            case 'date':
                return ($raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) ? $raw : null;
            case 'dow':
                return ($raw === null || $raw === '') ? null : max(0, min(6, (int)$raw));
            case 'pace':
                return self::parsePace((string)($raw ?? ''));
            case 'race_time':
                return self::parseRaceTime((string)($raw ?? ''));
            case 'days':
                return self::normalizeDays($raw);
            case 'string':
            case 'text':
            default:
                $v = trim((string)($raw ?? ''));
                return $v === '' ? null : $v;
        }
    }

    // ── Dual-path derivation (volume + longest run) ──────────────────────
    //
    // current_weekly_minutes and longest_recent_run_mins remain the single source
    // of truth. The forms offer two ENTRY methods that both DERIVE these — never a
    // second stored field. The arithmetic is unit-agnostic: distance × pace(sec per
    // unit) / 60 = minutes, so mi/km only affect the labels, not the math.

    /**
     * Derive weekly running minutes from a posted payload.
     *   method 'distance' → weekly_distance × pace(weekly_pace, sec/unit) / 60
     *   method 'time'     → weekly_time_hours×60 + weekly_time_minutes
     * Falls back to a directly-posted current_weekly_minutes (backward compat /
     * programmatic callers). Returns null when nothing usable was provided.
     */
    public static function deriveWeeklyMinutes(array $post): ?int
    {
        if (($post['weekly_volume_method'] ?? 'time') === 'distance') {
            $dist = (float)($post['weekly_distance'] ?? 0);
            $pace = self::parsePace((string)($post['weekly_pace'] ?? ''));
            if ($dist > 0 && $pace) return max(0, (int)round($dist * $pace / 60));
            return null;
        }
        $total = (int)($post['weekly_time_hours'] ?? 0) * 60 + (int)($post['weekly_time_minutes'] ?? 0);
        if ($total <= 0 && isset($post['current_weekly_minutes']) && $post['current_weekly_minutes'] !== '') {
            $total = max(0, (int)$post['current_weekly_minutes']);
        }
        return $total > 0 ? $total : null;
    }

    /**
     * Derive longest-run minutes from a posted payload.
     *   method 'distance' → longest_distance × pace(longest_pace, sec/unit) / 60
     *   method 'time'     → longest_time_minutes
     * Falls back to a directly-posted longest_recent_run_mins. Null when unusable.
     */
    public static function deriveLongestRunMinutes(array $post): ?int
    {
        if (($post['longest_method'] ?? 'time') === 'distance') {
            $dist = (float)($post['longest_distance'] ?? 0);
            $pace = self::parsePace((string)($post['longest_pace'] ?? ''));
            if ($dist > 0 && $pace) return max(0, (int)round($dist * $pace / 60));
            return null;
        }
        $m = (int)($post['longest_time_minutes'] ?? 0);
        if ($m <= 0 && isset($post['longest_recent_run_mins']) && $post['longest_recent_run_mins'] !== '') {
            $m = max(0, (int)$post['longest_recent_run_mins']);
        }
        return $m > 0 ? $m : null;
    }

    // ── Engine-critical completeness + cross-field sanity ────────────────

    /** Engine-critical fields (col => label) that a plan needs to generate accurately. */
    public const ENGINE_CRITICAL = [
        'current_weekly_minutes' => 'Weekly volume',
        'training_days_per_week' => 'Training days per week',
        'longest_recent_run_mins' => 'Longest recent run',
    ];

    /**
     * Which engine-critical fields are missing/blank in $values (a sanitized payload
     * OR an athlete_profiles row). goal_race_distance is required only on the race-goal
     * path (plan_type='race_cycle'); N/A for development / maintenance / return-to-running.
     * Returns [col => label] for each missing field (empty array = complete).
     */
    public static function missingCritical(array $values, ?string $planType): array
    {
        $missing = [];
        foreach (self::ENGINE_CRITICAL as $col => $label) {
            $v = $values[$col] ?? null;
            if ($v === null || $v === '' || (int)$v <= 0) $missing[$col] = $label;
        }
        if (($planType ?? '') === 'race_cycle') {
            $g = $values['goal_race_distance'] ?? null;
            if ($g === null || $g === '') $missing['goal_race_distance'] = 'Goal race distance';
        }
        return $missing;
    }

    /**
     * Cross-field sanity on the derived numbers.
     *   HARD (impossible): a single run can't exceed total weekly volume.
     *   SOFT (implausible, non-blocking): combinations that usually mean a mistake —
     *     high weekly volume with a tiny longest run (the Bekah contradiction), a
     *     longest run shorter than the average run, or low volume with a long single run.
     * Returns ['hard' => ?string, 'soft' => string[]].
     */
    public static function sanityIssues(?int $weekly, ?int $longest, ?int $days): array
    {
        $weekly = (int)$weekly; $longest = (int)$longest; $days = (int)$days;
        $hard = null; $soft = [];

        if ($weekly > 0 && $longest > 0 && $longest > $weekly) {
            return ['hard' => "Longest run ({$longest} min) can't be longer than your whole week's running "
                . "({$weekly} min). Please re-check these.", 'soft' => []];
        }
        if ($weekly >= 150 && $longest > 0 && $longest <= 25) {
            $soft[] = 'High weekly volume with a very short longest run — double-check these numbers.';
        }
        if ($days > 0 && $longest > 0 && $longest < ($weekly / $days) * 0.5) {
            $soft[] = 'Longest run is shorter than your average run — double-check these numbers.';
        }
        if ($weekly > 0 && $weekly <= 60 && $longest >= 50) {
            $soft[] = 'Low weekly volume but a long single run — double-check these numbers.';
        }
        return ['hard' => $hard, 'soft' => $soft];
    }

    /**
     * Validate a sanitized profile submission toward plan generation.
     * Returns ['errors' => string[], 'warnings' => string[]]. Errors block the save
     * (missing engine-critical fields, or an impossible longest>weekly combo); warnings
     * are non-blocking ("double-check") soft sanity notes shown alongside a successful save.
     */
    public static function validateSubmission(array $values, ?string $planType): array
    {
        $errors  = [];
        // plan_type is now editable on the form; a submitted value drives the
        // goal-distance requirement (falls back to the caller's stored plan_type).
        $planType = $values['plan_type'] ?? $planType;
        $missing = self::missingCritical($values, $planType);
        if ($missing) {
            $errors[] = 'Please complete: ' . implode(', ', array_values($missing)) . '.';
        }
        $s = self::sanityIssues(
            $values['current_weekly_minutes']  ?? null,
            $values['longest_recent_run_mins'] ?? null,
            $values['training_days_per_week']  ?? null
        );
        if ($s['hard']) $errors[] = $s['hard'];

        // Race-time plausibility — the SAME rule the onboarding door and fromRace()
        // use, so the coach/athlete form can't introduce a time onboarding would reject.
        $rDist = $values['most_recent_race_distance'] ?? null;
        $rTime = $values['most_recent_race_time'] ?? null; // canonical seconds
        if ($rDist && $rTime && !PaceZones::isPlausibleRaceTime((string)$rDist, (int)$rTime)) {
            $errors[] = 'That race time looks off for the distance. Enter it as H:MM:SS for longer '
                . 'races (e.g. 4:02:00 for a marathon), or M:SS for shorter ones.';
        }

        return ['errors' => $errors, 'warnings' => $s['soft']];
    }

    /** Parse a "mm:ss" (or bare seconds) pace string into seconds per mile. */
    public static function parsePace(string $val): ?int
    {
        $val = trim($val);
        if ($val === '') return null;
        if (preg_match('/^(\d{1,2}):([0-5]?\d)$/', $val, $m)) {
            return (int)$m[1] * 60 + (int)$m[2];
        }
        if (ctype_digit($val)) {
            return (int)$val;
        }
        return null;
    }

    /** Format seconds-per-mile as "m:ss"; empty for null. */
    public static function formatPaceSecs(?int $secs): string
    {
        if (!$secs) return '';
        return sprintf('%d:%02d', intdiv($secs, 60), $secs % 60);
    }

    /**
     * Parse a finish time "h:mm:ss" / "mm:ss" / bare seconds into total seconds.
     * The single canonical race-time parser — shared by onboarding and the profile
     * form so both doors interpret a time identically (never a raw-seconds field).
     */
    public static function parseRaceTime(string $time): ?int
    {
        $time = trim($time);
        if ($time === '') return null;
        if (!preg_match('/^\d{1,2}(:\d{1,2}){0,2}$/', $time)) return null;
        $parts = array_map('intval', explode(':', $time));
        return match (count($parts)) {
            3 => $parts[0] * 3600 + $parts[1] * 60 + $parts[2],
            2 => $parts[0] * 60 + $parts[1],
            1 => $parts[0],
            default => null,
        };
    }

    /** Format total seconds as "h:mm:ss" (>=1h) or "m:ss"; empty for null/0. Echoes a stored race time back to the form. */
    public static function formatRaceTime(?int $secs): string
    {
        if (!$secs || $secs <= 0) return '';
        if ($secs >= 3600) {
            return sprintf('%d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
        }
        return sprintf('%d:%02d', intdiv($secs, 60), $secs % 60);
    }

    /** Normalise a must-off-days payload (JSON string or array) to a sorted JSON string. */
    public static function normalizeDays($raw): string
    {
        if (is_string($raw)) {
            $arr = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $arr = $raw;
        } else {
            $arr = [];
        }
        if (!is_array($arr)) $arr = [];
        $days = [];
        foreach ($arr as $d) {
            $d = (int)$d;
            if ($d >= 0 && $d <= 6) $days[$d] = $d;
        }
        sort($days);
        return json_encode(array_values($days));
    }

    // ── Saving + diff + flag ─────────────────────────────────────────────

    /**
     * Persist changes, re-derive easy-pace zones when appropriate, and raise
     * a profile_updated flag with the diff.
     *
     * @param array $old   existing athlete_profiles row
     * @param array $new   sanitised values (from self::sanitize)
     * @param array $opts  ['actor_role' => 'athlete'|'coach', 'athlete_name' => string]
     * @return array       the computed diff (list of change descriptors)
     */
    public static function save(int $athleteId, array $old, array $new, array $opts, PDO $db): array
    {
        $coach   = ($opts['actor_role'] ?? 'athlete') === 'coach';
        $updates = $new;

        // Re-derive pace zones from easy pace when the easy range changed and
        // we are not clobbering verified ('race_result') or 'manual' zones.
        self::maybeDeriveZones($old, $new, $updates);
        // A changed (valid) race result re-derives verified race_result zones — takes
        // precedence over the easy-pace estimate. Same fromRace() path / plausibility floor.
        self::maybeDeriveRaceZones($old, $new, $updates);

        // RTR-only fields apply only to return-to-running plans. Gate on the EFFECTIVE
        // plan type — the coach's new value if they changed it, else the stored one (the
        // athlete form doesn't post plan_type). Off the RTR path, leave those columns
        // untouched (a hidden/absent checkbox must not zero a stored value).
        $effectivePlan = $new['plan_type'] ?? ($old['plan_type'] ?? '');
        if ($effectivePlan !== 'return_to_running') {
            unset($updates['medical_clearance_confirmed'], $updates['return_time_off_band']);
        }
        // medical_clearance_at is a derived timestamp: stamp it when clearance flips on,
        // clear it when off — mirroring onboarding. Only when the field survived the gate.
        if (array_key_exists('medical_clearance_confirmed', $updates)) {
            $confirmed    = (int)$updates['medical_clearance_confirmed'] === 1;
            $wasConfirmed = (int)($old['medical_clearance_confirmed'] ?? 0) === 1;
            if ($confirmed && !$wasConfirmed) {
                $updates['medical_clearance_at'] = date('Y-m-d H:i:s');
            } elseif (!$confirmed) {
                $updates['medical_clearance_at'] = null;
            }
        }

        // Always touch updated_at.
        $cols   = array_keys($updates);
        $set    = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
        $params = array_values($updates);
        $params[] = $athleteId;
        $db->prepare(
            "UPDATE athlete_profiles SET $set, updated_at = NOW() WHERE athlete_id = ?"
        )->execute($params);

        // Diff is computed over the athlete field set + (for coach) coach fields.
        $diff = self::computeDiff($old, $new, $coach);

        if (!empty($diff)) {
            self::raiseProfileFlag($athleteId, $diff, $opts, $db);
        }

        return $diff;
    }

    /** Decide whether to refresh easy-pace-estimate zones and stage the update. */
    private static function maybeDeriveZones(array $old, array $new, array &$updates): void
    {
        if (!array_key_exists('typical_easy_pace_min', $new)) {
            return; // coach/athlete scope without easy-pace fields — nothing to do
        }

        $newMin = $new['typical_easy_pace_min'] ?? null;
        $newMax = $new['typical_easy_pace_max'] ?? null;
        $oldMin = isset($old['typical_easy_pace_min']) ? (int)$old['typical_easy_pace_min'] : null;
        $oldMax = isset($old['typical_easy_pace_max']) ? (int)$old['typical_easy_pace_max'] : null;

        $changed = ((int)$newMin !== (int)$oldMin) || ((int)$newMax !== (int)$oldMax);
        if (!$changed || !$newMin) {
            return;
        }

        // Never overwrite verified or manually-set zones.
        $source = $old['pace_zones_source'] ?? null;
        $havePopulated = PaceZones::isPopulated($old['pace_zones'] ?? null);
        if ($havePopulated && $source !== 'easy_pace_estimate') {
            return;
        }

        $zones = PaceZones::fromEasyPace((int)$newMin, (int)($newMax ?: $newMin));
        if ($zones) {
            $updates['pace_zones']        = json_encode($zones);
            $updates['pace_zones_source'] = 'easy_pace_estimate';
        }
    }

    /**
     * Re-derive verified race_result zones when the most-recent-race result changed
     * to a valid (projectable, plausible) value. Mirrors onboarding/RaceController:
     * zones are DERIVED via PaceZones::fromRace — never hand-written — and the
     * plausibility floor means an impossible time produces no zones (no garbage).
     */
    private static function maybeDeriveRaceZones(array $old, array $new, array &$updates): void
    {
        if (!array_key_exists('most_recent_race_time', $new)) {
            return; // race fields not on this form scope — nothing to do
        }
        $dist = $new['most_recent_race_distance'] ?? ($old['most_recent_race_distance'] ?? null);
        $time = $new['most_recent_race_time'] ?? null; // canonical seconds
        if (!$dist || !$time) {
            return;
        }
        // Only act on an actual change to the result.
        $oldDist = $old['most_recent_race_distance'] ?? null;
        $oldTime = isset($old['most_recent_race_time']) ? (int)$old['most_recent_race_time'] : null;
        if ((string)$dist === (string)$oldDist && (int)$time === (int)$oldTime) {
            return;
        }
        $zones = PaceZones::fromRace((string)$dist, (int)$time);
        if ($zones) {
            $updates['pace_zones']        = json_encode($zones);
            $updates['pace_zones_source'] = 'race_result';
        }
    }

    /** Build a list of change descriptors comparing old row to new values. */
    public static function computeDiff(array $old, array $new, bool $coach): array
    {
        $diff = [];
        foreach (self::fields($coach) as $col => $def) {
            if (!array_key_exists($col, $new)) continue;
            $oldRaw = $old[$col] ?? null;
            $newRaw = $new[$col];
            if (!self::valuesDiffer($def['type'], $oldRaw, $newRaw)) continue;

            $diff[] = [
                'field'       => $col,
                'label'       => $def['label'],
                'coach_only'  => isset(self::COACH_FIELDS[$col]),
                'old_display' => self::format($def, $oldRaw),
                'new_display' => self::format($def, $newRaw),
            ];
        }
        return $diff;
    }

    private static function valuesDiffer(string $type, $old, $new): bool
    {
        switch ($type) {
            case 'int':
            case 'minutes':
            case 'dow':
            case 'pace':
                $o = ($old === null || $old === '') ? null : (int)$old;
                $n = ($new === null || $new === '') ? null : (int)$new;
                return $o !== $n;
            case 'float':
                $o = ($old === null || $old === '') ? null : (float)$old;
                $n = ($new === null || $new === '') ? null : (float)$new;
                return abs(($o ?? 0) - ($n ?? 0)) > 0.0001 || (($o === null) !== ($n === null));
            case 'bool':
                return (int)(bool)$old !== (int)(bool)$new;
            case 'days':
                return self::normalizeDays($old) !== self::normalizeDays($new);
            default:
                return (string)($old ?? '') !== (string)($new ?? '');
        }
    }

    /** Human-readable rendering of a stored value for the diff. */
    public static function format(array $def, $val): string
    {
        if ($val === null || $val === '') {
            return '—';
        }
        switch ($def['type']) {
            case 'minutes':
                return function_exists('format_duration') ? format_duration((int)$val) : ((int)$val . ' min');
            case 'int':
                return (string)(int)$val;
            case 'float':
                return rtrim(rtrim(number_format((float)$val, 1), '0'), '.');
            case 'bool':
                return $val ? 'Yes' : 'No';
            case 'date':
                return date('M j, Y', strtotime((string)$val));
            case 'dow':
                return self::DAYS[(int)$val] ?? '—';
            case 'pace':
                return self::formatPaceSecs((int)$val) . ' /mi';
            case 'race_time':
                return self::formatRaceTime((int)$val);
            case 'days':
                $arr = json_decode(self::normalizeDays($val), true) ?: [];
                if (!$arr) return 'None';
                return implode(', ', array_map(fn($d) => self::DAYS[$d] ?? '?', $arr));
            case 'enum':
                return self::enumLabel((string)$val);
            default:
                return (string)$val;
        }
    }

    private static function enumLabel(string $v): string
    {
        return match ($v) {
            'road_reps_ok' => 'Road reps OK',
            'road_gravel'  => 'Road / gravel',
            'flex'         => 'Flexible',
            'fixed'        => 'Fixed days',
            'none'         => 'None',
            'race_cycle'        => 'Race cycle',
            'development_plan'  => 'Development',
            'maintenance_plan'  => 'Maintenance',
            'return_to_running' => 'Return to running',
            'recovery_block'    => 'Recovery block',
            '1_2_weeks'    => '1–2 weeks',
            '2_6_weeks'    => '2–6 weeks',
            '6_16_weeks'   => '6–16 weeks',
            '4_12_months'  => '4–12 months',
            '12_plus_months' => '12+ months',
            default        => ucfirst(str_replace('_', ' ', $v)),
        };
    }

    private static function raiseProfileFlag(int $athleteId, array $diff, array $opts, PDO $db): void
    {
        $first = explode(' ', trim($opts['athlete_name'] ?? 'Athlete'))[0] ?: 'Athlete';
        $coach = ($opts['actor_role'] ?? 'athlete') === 'coach';

        // Summary uses athlete-facing changes only (coach-only fields excluded).
        $visible = array_values(array_filter($diff, fn($d) => empty($d['coach_only'])));
        $parts   = [];
        foreach (array_slice($visible, 0, 3) as $d) {
            $parts[] = "{$d['label']} {$d['old_display']} → {$d['new_display']}";
        }
        $extra = count($visible) - count($parts);
        if ($extra > 0) $parts[] = "+{$extra} more";

        $lead    = $coach ? "Coach updated {$first}'s training profile" : "{$first} updated their training profile";
        $message = $parts ? $lead . ': ' . implode(', ', $parts) : $lead;

        $details = json_encode([
            'actor_role' => $opts['actor_role'] ?? 'athlete',
            'changes'    => $diff,
        ]);

        // No dedup: each save is a distinct event the coach should see individually.
        $db->prepare(
            'INSERT INTO engine_flags
             (athlete_id, flag_type, severity, flag_date, details, message, status, created_at)
             VALUES (?, "profile_updated", "info", CURDATE(), ?, ?, "open", NOW())'
        )->execute([$athleteId, $details, $message]);
    }
}

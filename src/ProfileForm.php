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
    ];

    /** Coach-only fields — recorded in the diff details, never surfaced in the athlete-facing message. */
    private const COACH_FIELDS = [
        'peak_volume_ceiling_mins'  => ['label' => 'Peak volume ceiling', 'type' => 'minutes'],
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
        // scheduling_preference is NOT NULL with a default; never store null.
        if (array_key_exists('scheduling_preference', $out) && $out['scheduling_preference'] === null) {
            $out['scheduling_preference'] = 'flex';
        }
        // Drop fixed-only day fields when scheduling is flex.
        if (($out['scheduling_preference'] ?? 'flex') !== 'fixed') {
            $out['long_run_day']       = null;
            $out['primary_workout_day'] = null;
        }
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
            case 'days':
                return self::normalizeDays($raw);
            case 'string':
            case 'text':
            default:
                $v = trim((string)($raw ?? ''));
                return $v === '' ? null : $v;
        }
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

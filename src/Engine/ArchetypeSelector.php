<?php
/**
 * Selects and resolves workout archetypes for plan generation.
 *
 * Column-to-field mapping in workout_archetypes:
 *   selection  — slot eligibility rules (slot_types, phases, plan_types, goal_distances,
 *                min_classification, track_requirement, coach_clearance_required,
 *                requires, excludes)
 *   weights    — scoring maps keyed by phase / goal_distance / classification / plan_type
 *   generation — prescription_model, duration_source, progression_model,
 *                recovery_model, intensity_factor
 *   parameters — per-classification range definitions for every generated parameter
 */
class ArchetypeSelector
{
    private PDO $db;

    /** @var array<string, array> Code-keyed cache populated on first load. */
    private array $cache = [];

    /**
     * Canonical minimum viable session durations, used when existing DB rows
     * have not yet been refreshed from the seed JSON metadata.
     *
     * The seed JSON stores the same values in generation.minimum_session_duration_minutes;
     * keep both in sync until every environment has imported the updated archetypes.
     */
    private const DEFAULT_MINIMUM_SESSION_DURATIONS = [
        'continuous_easy'               => 30,
        'easy_with_strides'             => 30,
        'continuous_long'               => 60,
        'progression_long'              => 70,
        'goal_pace_long_segments'       => 60,
        'fast_finish_long'              => 70,
        'structured_fartlek_ladder'     => 61,
        'equal_distance_repeats'        => 55,
        'mixed_distance_repeats'        => 60,
        'short_speed_repeats'           => 40,
        'sustained_hill_repeats'        => 31,
        'hill_sprints'                  => 40,
        'plyometric_hill_circuits'      => 37,
        'tempo_intervals'               => 37,
        'continuous_progression_tempo'  => 45,
        'long_run_with_pickups'         => 60,
        'high_volume_time_intervals'    => 52,
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return a single archetype by its stable code slug, or null if not found.
     * Returns both active and inactive archetypes (status check is caller's responsibility).
     */
    public function getByCode(string $code): ?array
    {
        if (!isset($this->cache[$code])) {
            $stmt = $this->db->prepare(
                'SELECT * FROM workout_archetypes WHERE code = :code LIMIT 1'
            );
            $stmt->execute([':code' => $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $this->cache[$code] = $this->decode($row);
        }
        return $this->cache[$code];
    }

    /**
     * Select eligible archetypes for a plan slot and pick the best one.
     *
     * @param string $slotType       One of: easy, quality_primary, quality_secondary, long_run
     * @param string $phase          One of: base, build, peak, taper
     * @param string $goalDistance   One of: 5K, 10K, half, marathon
     * @param string $classification One of: well_trained, workable, insufficient
     * @param string $planType       One of: race_cycle, development_plan, maintenance_plan,
     *                               recovery_block, return_to_running
     * @param array  $constraints    Keys: track_access, hill_access, plyometric_clearance,
     *                               track_field_background, weekly_minutes,
     *                               min_duration_week_fraction, excludes (string[])
     * @param array  $excludeCodes   Archetype codes to hard-exclude (anti-repeat hard block)
     * @param array  $penalizedCodes Archetype codes to soft-penalize (anti-repeat soft penalty, -5 pts)
     * @param array  $weightAdjust   code => float multiplier applied to the summed score before the
     *                               weighted pick (e.g. trail ultras boost hill/fartlek, lower track reps)
     */
    public function selectForSlot(
        string $slotType,
        string $phase,
        string $goalDistance,
        string $classification,
        string $planType,
        array  $constraints    = [],
        array  $excludeCodes   = [],
        array  $penalizedCodes = [],
        array  $weightAdjust   = []
    ): ?array {
        $eligible = $this->getEligible($slotType, $phase, $goalDistance, $classification, $planType, $constraints, $excludeCodes);

        if (empty($eligible)) return null;

        $picked = $this->pickWeighted($eligible, $phase, $goalDistance, $classification, $planType, $penalizedCodes, $weightAdjust);
        if ($picked === null) return null;

        return $this->resolveParameters($picked, $classification);
    }

    /**
     * Return all eligible archetypes for a slot context without picking one.
     * Applies all eligibility rules + hard-exclude list.
     */
    public function getEligible(
        string $slotType,
        string $phase,
        string $goalDistance,
        string $classification,
        string $planType,
        array  $constraints  = [],
        array  $excludeCodes = []
    ): array {
        $all      = $this->loadAll();
        $eligible = [];

        foreach ($all as $archetype) {
            if (!empty($excludeCodes) && in_array($archetype['code'], $excludeCodes, true)) {
                continue;
            }
            if ($archetype['status'] !== 'active') {
                continue;
            }
            if ($this->isEligible($archetype, $slotType, $phase, $goalDistance, $classification, $planType, $constraints)) {
                $eligible[] = $archetype;
            }
        }

        return $eligible;
    }

    /**
     * Weighted random pick from eligible archetypes.
     *
     * Weights are summed across phase, goal_distance, classification, and plan_type
     * dimensions. Zero-weight and penalized-to-zero archetypes are excluded.
     *
     * @param array  $penalizedCodes Codes that receive a -5 point deduction before pick.
     * @param array  $weightAdjust   code => float multiplier applied to the summed score (after the
     *                               soft penalty). >1 favours the archetype, <1 suppresses it. Used by
     *                               trail ultras to weight hill/fartlek work up and track reps down.
     */
    public function pickWeighted(
        array  $eligible,
        string $phase          = '',
        string $goalDistance   = '',
        string $classification = '',
        string $planType       = '',
        array  $penalizedCodes = [],
        array  $weightAdjust   = []
    ): ?array {
        $scored = [];
        $total  = 0;

        foreach ($eligible as $archetype) {
            $w     = $archetype['weights'] ?? [];
            $score = 0;
            $score += (int)($w['phase'][$phase] ?? 0);
            $score += (int)($w['goal_distance'][$goalDistance] ?? 0);
            $score += (int)($w['classification'][$classification] ?? 0);
            $score += (int)($w['plan_type'][$planType] ?? 0);

            // Soft penalty: same archetype used recently
            if (in_array($archetype['code'], $penalizedCodes, true)) {
                $score = max(0, $score - 5);
            }

            // Context weight adjustment (e.g. trail-ultra terrain weighting).
            if ($score > 0 && isset($weightAdjust[$archetype['code']])) {
                $score = (int)round($score * (float)$weightAdjust[$archetype['code']]);
            }

            if ($score > 0) {
                $scored[] = ['archetype' => $archetype, 'score' => $score];
                $total   += $score;
            }
        }

        if (empty($scored) || $total === 0) return null;

        $rand       = mt_rand(1, $total);
        $cumulative = 0;
        foreach ($scored as $entry) {
            $cumulative += $entry['score'];
            if ($rand <= $cumulative) {
                return $entry['archetype'];
            }
        }
        return $scored[array_key_last($scored)]['archetype'];
    }

    /**
     * Resolve the `parameters` spec into concrete values for a classification.
     *
     * Adds `resolved_params` key with one concrete value per parameter,
     * taken from the midpoint of the classification range.
     */
    public function resolveParameters(array $archetype, string $classification): array
    {
        $params   = $archetype['parameters'] ?? [];
        $resolved = [];

        foreach ($params as $key => $spec) {
            if (!is_array($spec)) {
                $resolved[$key] = $spec;
                continue;
            }

            $type = $spec['type'] ?? 'integer';

            if (isset($spec[$classification]) && is_array($spec[$classification])) {
                $range = $spec[$classification];
                if (isset($range['min'], $range['max'])) {
                    $mid = $type === 'float'
                        ? round(($range['min'] + $range['max']) / 2, 2)
                        : (int)round(($range['min'] + $range['max']) / 2);
                    $resolved[$key] = $type !== 'float' ? self::roundSeconds($key, $mid) : $mid;
                    continue;
                }
            }

            // Fall back to 'well_trained' range (for archetypes that only define well_trained)
            if (isset($spec['well_trained']) && is_array($spec['well_trained'])) {
                $range = $spec['well_trained'];
                if (isset($range['min'], $range['max'])) {
                    $mid = $type === 'float'
                        ? round(($range['min'] + $range['max']) / 2, 2)
                        : (int)round(($range['min'] + $range['max']) / 2);
                    $resolved[$key] = $type !== 'float' ? self::roundSeconds($key, $mid) : $mid;
                    continue;
                }
            }

            if (isset($spec['default'])) {
                $resolved[$key] = $spec['default'];
            } elseif (!empty($spec['allowed_values']) && is_array($spec['allowed_values'])) {
                $resolved[$key] = $spec['allowed_values'][0];
            } elseif (isset($spec['min'])) {
                $resolved[$key] = $spec['min'];
            } else {
                $resolved[$key] = null;
            }
        }

        $archetype['resolved_params'] = $resolved;
        return $archetype;
    }

    /**
     * Return the archetype's minimum viable session duration in minutes.
     *
     * `generation.minimum_session_duration_minutes` is canonical when present.
     * It exists because distance-based workouts need coaching judgment about
     * assigned effort and jog recoveries; the computed fallback is only for
     * archetypes that do not yet carry an explicit minimum.
     */
    public function getMinimumSessionDurationMinutes(
        array $archetype,
        string $classification = 'workable',
        string $phase = 'base',
        string $goalDistance = '5K'
    ): ?float {
        $generation = $archetype['generation'] ?? [];
        if (isset($generation['minimum_session_duration_minutes'])) {
            return (float)$generation['minimum_session_duration_minutes'];
        }
        if (isset(self::DEFAULT_MINIMUM_SESSION_DURATIONS[$archetype['code'] ?? ''])) {
            return (float)self::DEFAULT_MINIMUM_SESSION_DURATIONS[$archetype['code']];
        }

        $params   = $archetype['parameters'] ?? [];
        $warmup   = self::paramFloor($params, 'warmup_minutes', $classification);
        $cooldown = self::paramFloor($params, 'cooldown_minutes', $classification);

        if ($warmup === null && $cooldown === null) {
            $duration = self::paramFloor($params, 'duration_minutes', $classification);
            return $duration !== null ? (float)$duration : null;
        }

        $warmup   = (float)($warmup ?? 0);
        $cooldown = (float)($cooldown ?? 0);
        $main     = $this->minimumMainSetMinutes($archetype, $classification, $phase, $goalDistance);

        return $main !== null
            ? round($warmup + $main + $cooldown, 1)
            : round($warmup + $cooldown, 1);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Load all archetypes into code-keyed cache. */
    private function loadAll(): array
    {
        if (!empty($this->cache)) {
            return array_values($this->cache);
        }

        $stmt = $this->db->query('SELECT * FROM workout_archetypes');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $decoded = $this->decode($row);
            $this->cache[$decoded['code']] = $decoded;
        }

        return array_values($this->cache);
    }

    /** JSON-decode all LONGTEXT fields in a raw DB row. */
    private function decode(array $row): array
    {
        $jsonFields = [
            'mapped_templates', 'selection', 'weights', 'generation',
            'variants', 'parameters', 'structure_template',
            'display', 'instance_signature', 'coach_notes',
        ];
        foreach ($jsonFields as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $row[$field] = json_decode($row[$field], true) ?? $row[$field];
            }
        }
        return $row;
    }

    /**
     * Test whether an archetype is eligible for the given slot context.
     */
    private function isEligible(
        array  $archetype,
        string $slotType,
        string $phase,
        string $goalDistance,
        string $classification,
        string $planType,
        array  $constraints
    ): bool {
        $s = $archetype['selection'] ?? [];

        if (!in_array($slotType,    $s['slot_types']     ?? [], true)) return false;
        if (!in_array($phase,       $s['phases']         ?? [], true)) return false;
        if (!in_array($planType,    $s['plan_types']     ?? [], true)) return false;
        if (!in_array($goalDistance,$s['goal_distances'] ?? [], true)) return false;

        // Classification floor
        $classRank = ['insufficient' => 0, 'workable' => 1, 'well_trained' => 2];
        $minRank   = $classRank[$s['min_classification'] ?? 'workable'] ?? 1;
        $curRank   = $classRank[$classification] ?? 0;
        if ($curRank < $minRank) return false;

        // Coach clearance — never auto-selected by engine
        if (!empty($s['coach_clearance_required'])) return false;

        // Track requirement
        $trackReq    = $s['track_requirement'] ?? 'none';
        $trackAccess = $constraints['track_access'] ?? 'no';
        if ($trackReq === 'required' && $trackAccess === 'no') return false;

        // requires[] — flags that must be truthy in $constraints
        foreach ($s['requires'] ?? [] as $req) {
            if (empty($constraints[$req])) return false;
        }

        // excludes[] — context tags that disqualify the archetype
        $contextTags = $constraints['excludes'] ?? [];
        foreach ($s['excludes'] ?? [] as $excl) {
            if (in_array($excl, $contextTags, true)) return false;
        }

        // Quality session footprint gate. This is intentionally an eligibility
        // rule, like min_classification: if the smallest viable version of a
        // quality archetype would dominate the generated week, it never enters
        // the weighted candidate pool for that slot.
        if (in_array($slotType, ['quality_primary', 'quality_secondary'], true)) {
            $weeklyMinutes = (int)($constraints['weekly_minutes'] ?? 0);
            $fraction      = (float)($constraints['min_duration_week_fraction'] ?? 0);
            if ($weeklyMinutes > 0 && $fraction > 0) {
                $minDuration = $this->getMinimumSessionDurationMinutes(
                    $archetype, $classification, $phase, $goalDistance
                );
                if ($minDuration !== null && $minDuration > $weeklyMinutes * $fraction) {
                    return false;
                }
            }
        }

        return true;
    }

    private function minimumMainSetMinutes(
        array $archetype, string $classification, string $phase, string $goalDistance
    ): ?float {
        $code   = $archetype['code'] ?? '';
        $params = $archetype['parameters'] ?? [];

        return match ($code) {
            'structured_fartlek_ladder' =>
                $this->minimumFartlekLadderMinutes($params, $classification),
            'tempo_intervals' =>
                (float)(self::paramFloor($params, 'rep_count', $classification) ?? 1)
                    * (float)(self::paramFloor($params, 'rep_duration_minutes', $classification) ?? 0),
            'high_volume_time_intervals' =>
                (float)(self::paramFloor($params, 'rep_count', $classification) ?? 1)
                    * (
                        (float)(self::paramFloor($params, 'work_duration_seconds', $classification) ?? 0)
                        + (float)(self::paramFloor($params, 'recovery_duration_seconds', $classification) ?? 0)
                    ) / 60.0,
            'sustained_hill_repeats' =>
                (float)(self::paramFloor($params, 'rep_count', $classification) ?? 1)
                    * (float)(self::paramFloor($params, 'rep_duration_seconds', $classification) ?? 0)
                    * 2 / 60.0,
            'hill_sprints' =>
                (float)(self::paramFloor($params, 'sprint_count', $classification) ?? 1)
                    * (
                        (float)(self::paramFloor($params, 'sprint_duration_seconds', $classification) ?? 0)
                        + 90
                    ) / 60.0,
            'plyometric_hill_circuits' =>
                (float)(self::paramFloor($params, 'circuit_count', $classification) ?? 1)
                    * (
                        (float)(self::paramFloor($params, 'hill_sprint_duration_seconds', $classification) ?? 0)
                        + 90
                    ) / 60.0,
            'continuous_progression_tempo' =>
                (float)(self::paramFloor($params, 'continuous_work_minutes', $classification) ?? 0),
            'equal_distance_repeats', 'mixed_distance_repeats', 'short_speed_repeats' =>
                $this->minimumDistanceSessionMainMinutes($archetype, $classification, $phase, $goalDistance),
            default => null,
        };
    }

    private function minimumFartlekLadderMinutes(array $params, string $classification): float
    {
        $rounds = (float)(self::paramFloor($params, 'round_count', $classification) ?? 1);
        $patterns = $params['work_intervals_seconds']['allowed_patterns'] ?? [
            [90, 60, 30],
            [60, 120, 180, 240],
            [60, 120, 180, 120, 60],
            [60, 30, 15],
        ];
        $shortestPattern = null;
        foreach ($patterns as $pattern) {
            if (!is_array($pattern)) continue;
            $sum = array_sum(array_map('intval', $pattern));
            if ($shortestPattern === null || $sum < $shortestPattern) {
                $shortestPattern = $sum;
            }
        }
        $intervalSeconds = $shortestPattern ?? 180;
        return $rounds * 2 * $intervalSeconds / 60.0;
    }

    private function minimumDistanceSessionMainMinutes(
        array $archetype, string $classification, string $phase, string $goalDistance
    ): ?float {
        $params = $archetype['parameters'] ?? [];
        $meters = self::paramFloor($params, 'quality_volume_meters', $classification);

        $repCount = self::paramFloor($params, 'rep_count', $classification);
        $repDist  = self::paramFloor($params, 'rep_distance_meters', $classification);
        if ($repCount !== null && $repDist !== null) {
            $meters = max((float)($meters ?? 0), (float)$repCount * (float)$repDist);
        }

        if ($meters === null || (float)$meters <= 0) return null;

        $effort      = $this->minimumDistanceEffort($archetype, $phase, $goalDistance);
        $workMinutes = self::estimateMinutesForMeters((float)$meters, $effort, $classification, $goalDistance);

        $recoveryModel = $archetype['generation']['recovery_model'] ?? '';
        $recovery      = 0.0;
        if ($recoveryModel === 'vo2_standard') {
            // Jogging recoveries are part of the session footprint. At this
            // minimum-estimate layer, use a 1:1 work:recovery relationship.
            $recovery = $workMinutes;
        } elseif ($recoveryModel === 'speed_standard' && $repCount !== null) {
            // Speed repetitions use generous movement recovery; approximate the
            // minimum session footprint as 90 seconds per rep.
            $recovery = (float)$repCount * 90 / 60.0;
        }

        return $workMinutes + $recovery;
    }

    private function minimumDistanceEffort(array $archetype, string $phase, string $goalDistance): string
    {
        $params = $archetype['parameters'] ?? [];

        if (isset($params['target_effort']['default'])) return (string)$params['target_effort']['default'];
        if (!empty($params['target_effort']['allowed_values'][0])) return (string)$params['target_effort']['allowed_values'][0];
        if (!empty($params['effort_zone']['allowed_values'][0])) return (string)$params['effort_zone']['allowed_values'][0];
        if (!empty($params['effort_zones']['allowed_values'][0])) return (string)$params['effort_zones']['allowed_values'][0];

        if (($archetype['effort_mapping']['model'] ?? null) === 'goal_distance_adjusted') {
            return match($goalDistance) {
                '5K'      => $phase === 'peak' ? '5K' : '10K',
                '10K'     => $phase === 'peak' ? '10K' : 'threshold',
                'half'    => $phase === 'peak' ? 'half_marathon' : 'threshold',
                'marathon'=> 'marathon',
                default   => 'threshold',
            };
        }

        return '5K';
    }

    private static function estimateMinutesForMeters(
        float $meters, string $effort, string $classification, string $goalDistance
    ): float {
        $pace = self::paceForEffort($effort, $classification, $goalDistance);
        return ($meters / 1609.34) * $pace;
    }

    private static function paceForEffort(string $effort, string $classification, string $goalDistance): float
    {
        $cls = $classification === 'well_trained' ? 'well_trained' : 'workable';

        $quality = [
            'well_trained' => [
                '5K' => [5.5, 7.5], '10K' => [6.0, 8.0], 'half' => [6.5, 8.5], 'marathon' => [7.0, 9.0],
            ],
            'workable' => [
                '5K' => [7.5, 10.5], '10K' => [8.0, 11.0], 'half' => [8.5, 12.0], 'marathon' => [9.5, 13.0],
            ],
        ];
        $easy = [
            'well_trained' => [
                '5K' => [7.5, 10.5], '10K' => [8.0, 11.0], 'half' => [8.5, 11.5], 'marathon' => [9.0, 12.0],
            ],
            'workable' => [
                '5K' => [9.5, 13.5], '10K' => [10.0, 14.0], 'half' => [10.5, 14.0], 'marathon' => [11.0, 14.5],
            ],
        ];

        $normalized = strtolower(str_replace([' ', '-'], '_', $effort));
        $distanceKey = match ($normalized) {
            'marathon', 'marathon_pace' => 'marathon',
            'half', 'half_marathon', 'threshold', 'tempo' => 'half',
            '10k' => '10K',
            '5k', '3k', 'mile', '800', 'repetition' => '5K',
            'easy', 'recovery' => 'easy',
            default => $goalDistance,
        };

        $source = $distanceKey === 'easy' ? $easy[$cls] : $quality[$cls];
        $range  = $distanceKey === 'easy'
            ? ($source[$goalDistance] ?? $source['5K'])
            : ($source[$distanceKey] ?? $source[$goalDistance] ?? $source['5K']);

        return ($range[0] + $range[1]) / 2;
    }

    private static function paramFloor(array $params, string $key, string $classification): mixed
    {
        if (!isset($params[$key]) || !is_array($params[$key])) return null;
        $spec = $params[$key];

        if (isset($spec[$classification]['min'])) return $spec[$classification]['min'];
        if (isset($spec['workable']['min'])) return $spec['workable']['min'];
        if (isset($spec['well_trained']['min'])) return $spec['well_trained']['min'];
        if (isset($spec['min'])) return $spec['min'];
        if (isset($spec['default'])) return $spec['default'];
        if (!empty($spec['allowed_values']) && is_array($spec['allowed_values'])) {
            $numeric = array_filter($spec['allowed_values'], 'is_numeric');
            if (!empty($numeric)) return min($numeric);
            return $spec['allowed_values'][0];
        }

        return null;
    }

    /**
     * Round _seconds parameter midpoints to coach-friendly increments:
     *   < 30 s  → nearest 5 s
     *   30–90 s → nearest 15 s
     *   > 90 s  → nearest 30 s
     */
    private static function roundSeconds(string $key, int $value): int
    {
        if (substr($key, -8) !== '_seconds') return $value;
        if ($value < 30)  return (int)round($value / 5) * 5;
        if ($value <= 90) return (int)round($value / 15) * 15;
        return (int)round($value / 30) * 30;
    }
}

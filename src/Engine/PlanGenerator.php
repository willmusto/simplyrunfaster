<?php
require_once __DIR__ . '/PaceZones.php';
require_once __DIR__ . '/../Timezone.php';

/**
 * PlanGenerator — archetype-based training plan generation.
 *
 * Handles plan types: race_cycle, development_plan, return_to_running,
 * maintenance_plan, recovery_block.
 *
 * All guardrails from engine spec Section 13 are enforced during generation.
 * Generated plans are written to training_plans + planned_workouts and entered
 * into plan_approval_queue (status=pending). Athletes see nothing until approved.
 */
class PlanGenerator
{
    // Minimum plan lengths by distance (weeks)
    const MIN_CYCLE = [
        '5K'       => 8,
        '10K'      => 10,
        'half'     => 12,
        'HM'       => 12,
        'marathon' => 14,
        'ultra'    => 16,
    ];
    const MAX_PLAN_WEEKS = 24;

    // Phase proportions by athlete base classification
    const PHASE_PROPORTIONS = [
        'well_trained' => ['base' => 0.20, 'build' => 0.40, 'peak' => 0.20, 'taper' => 0.15],
        'workable'     => ['base' => 0.30, 'build' => 0.30, 'peak' => 0.20, 'taper' => 0.15],
        'insufficient' => ['base' => 0.40, 'build' => 0.25, 'peak' => 0.20, 'taper' => 0.15],
    ];

    // Classification thresholds per distance.
    // All three criteria (runs_per_week, weekly_minutes, long_run_minutes) must be met simultaneously.
    const CLASSIFICATION = [
        '5K' => [
            'well_trained' => ['runs_per_week' => 4, 'weekly_minutes' => 180, 'long_run_minutes' => 60],
            'workable'     => ['runs_per_week' => 3, 'weekly_minutes' => 120, 'long_run_minutes' => 45],
        ],
        '10K' => [
            'well_trained' => ['runs_per_week' => 4, 'weekly_minutes' => 210, 'long_run_minutes' => 70],
            'workable'     => ['runs_per_week' => 3, 'weekly_minutes' => 150, 'long_run_minutes' => 50],
        ],
        'half' => [
            'well_trained' => ['runs_per_week' => 5, 'weekly_minutes' => 270, 'long_run_minutes' => 90],
            'workable'     => ['runs_per_week' => 4, 'weekly_minutes' => 180, 'long_run_minutes' => 60],
        ],
        'marathon' => [
            'well_trained' => ['runs_per_week' => 5, 'weekly_minutes' => 360, 'long_run_minutes' => 105],
            'workable'     => ['runs_per_week' => 4, 'weekly_minutes' => 240, 'long_run_minutes' => 75],
        ],
        // ── Ultra distances (§ ultra spec Part 3) ───────────────────────────
        '50k' => [
            'well_trained' => ['runs_per_week' => 5, 'weekly_minutes' => 360, 'long_run_minutes' => 105],
            'workable'     => ['runs_per_week' => 4, 'weekly_minutes' => 240, 'long_run_minutes' => 75],
        ],
        '50_miler' => [
            'well_trained' => ['runs_per_week' => 5, 'weekly_minutes' => 420, 'long_run_minutes' => 120],
            'workable'     => ['runs_per_week' => 4, 'weekly_minutes' => 300, 'long_run_minutes' => 90],
        ],
        '100k' => [
            'well_trained' => ['runs_per_week' => 6, 'weekly_minutes' => 480, 'long_run_minutes' => 150],
            'workable'     => ['runs_per_week' => 5, 'weekly_minutes' => 360, 'long_run_minutes' => 105],
        ],
        '100_miler' => [
            'well_trained' => ['runs_per_week' => 6, 'weekly_minutes' => 600, 'long_run_minutes' => 180],
            'workable'     => ['runs_per_week' => 5, 'weekly_minutes' => 420, 'long_run_minutes' => 120],
        ],
        // ── Mile / 1500m (mile spec Part 3) — lower volume thresholds than 5K;
        //    milers can work with less volume given speed/neuromuscular development.
        'mile' => [
            'well_trained' => ['runs_per_week' => 4, 'weekly_minutes' => 180, 'long_run_minutes' => 45],
            'workable'     => ['runs_per_week' => 3, 'weekly_minutes' => 120, 'long_run_minutes' => 30],
        ],
    ];

    // ── Ultra-distance parameters (ultra spec) ───────────────────────────────
    // Canonical ultra goal_distance keys. normalizeDistance() resolves onboarding
    // values to these; selectorDistance() maps them to 'marathon' for the archetype
    // layer (archetypes carry no ultra goal_distances and the pace-range maps key on
    // 5K/10K/half/marathon), while the engine sizing below keys on the real ultra key.
    const ULTRA_DISTANCES = ['50k', '50_miler', '100k', '100_miler'];

    // Cycle length range [min, max] in weeks. Lower bound suits workable athletes,
    // upper bound well-trained; the race-date-derived length is clamped into the range.
    const ULTRA_CYCLE_WEEKS = [
        '50k'       => [16, 20],
        '50_miler'  => [20, 24],
        '100k'      => [22, 26],
        '100_miler' => [24, 32],
    ];

    // Peak weekly volume ceiling (minutes), well-trained. Workable is capped at 75%.
    // Used as a not-to-exceed cap on the athlete's own peak_volume_ceiling_mins.
    const ULTRA_VOLUME_CEILING = [
        '50k'       => 600,  // 10h
        '50_miler'  => 720,  // 12h
        '100k'      => 840,  // 14h
        '100_miler' => 960,  // 16h
    ];

    // Long-run duration caps (minutes) by phase (time on feet, never distance).
    const ULTRA_LONG_RUN_CAP = [
        '50k'       => ['base' => 150, 'build' => 180, 'peak' => 210, 'taper' => 150],
        '50_miler'  => ['base' => 180, 'build' => 240, 'peak' => 300, 'taper' => 180],
        '100k'      => ['base' => 210, 'build' => 270, 'peak' => 360, 'taper' => 210],
        '100_miler' => ['base' => 240, 'build' => 330, 'peak' => 420, 'taper' => 240],
    ];

    // Sunday medium-long run as a fraction of Saturday's long run (back-to-back).
    const ULTRA_BACK_TO_BACK_RATIO = [
        '50k'       => 0.55,  // 50–60%
        '50_miler'  => 0.55,  // 50–60%
        '100k'      => 0.575, // 50–65%
        '100_miler' => 0.625, // 55–70%
    ];

    // ── Mile / 1500m parameters (mile spec) ──────────────────────────────────
    // Mile maps to '5K' at the archetype/pace layer (selectorDistance), with the
    // real 'mile' key driving the speed-weighted structure below.
    const MILE_CYCLE_WEEKS = [12, 16]; // workable 12, well-trained 16

    // Peak weekly-volume ceiling (minutes): lower than marathon, higher intensity.
    const MILE_VOLUME_CEILING = ['workable' => 420, 'well_trained' => 630];

    // Long-run duration caps (minutes) by phase — aerobic support, not the primary
    // stimulus; peak is maintained (not increased) during sharpening.
    const MILE_LONG_RUN_CAP = ['base' => 60, 'build' => 75, 'peak' => 75, 'taper' => 60];

    // Archetype score multipliers for mile athletes (Part 10): speed/threshold up,
    // plyometrics down. Applied in pickWeighted via the weight-adjust hook.
    const MILE_WEIGHT_ADJUST = [
        'short_speed_repeats'         => 3.0,
        'equal_distance_repeats'      => 3.0,
        'hill_sprints'                => 3.0,
        'tempo_intervals'             => 2.0,
        'sustained_hill_repeats'      => 2.0,
        'structured_fartlek_ladder'   => 2.0,
        'high_volume_time_intervals'  => 2.0,
        'plyometric_hill_circuits'    => 0.5,
    ];

    // Fallback workout_type for slots that have no archetype (or whose metadata lacks workout_type).
    // For archetype-based workouts, metadata.workout_type takes precedence.
    const SLOT_WORKOUT_TYPE = [
        'long_run'          => 'long',
        'medium_long'       => 'long',
        'quality_primary'   => 'interval',
        'quality_secondary' => 'interval',
        'easy'              => 'easy',
        'easy_strides'      => 'easy',
        'recovery'          => 'recovery',
        'race'              => 'race',
    ];

    // Minimum post-capping structure required for athlete-facing instructions
    // to remain coherent. If a slot is too short to preserve these minima, the
    // archetype is rejected for that slot and the selector retries.
    const MIN_VIABLE_INSTANCE_PARAMS = [
        'sustained_hill_repeats'        => ['rep_count' => 3],
        'hill_sprints'                  => ['sprint_count' => 4],
        'tempo_intervals'               => ['rep_count' => 2],
        'continuous_progression_tempo'  => ['continuous_work_minutes' => 15],
        'equal_distance_repeats'        => ['rep_count' => 3],
        'short_speed_repeats'           => ['rep_count' => 4],
        'high_volume_time_intervals'    => ['rep_count' => 6],
        'structured_fartlek_ladder'     => ['round_count' => 1],
    ];

    // Deterministic beginner archetypes whose placement is driven by a fixed
    // stage/structure, not weighted variety-seeking. They are intentionally
    // repeated across a plan, so they bypass the anti-repeat hard block and the
    // per-slot quality-duration budget (the run/walk session IS the athlete's
    // primary session, not budgeted quality work). See engine spec §19 item 6.
    const REPEATABLE_ARCHETYPES = ['run_walk_intervals', 'standalone_strides'];

    // Structured quality archetypes carry a warmup + cooldown whose resolved
    // warmup_minutes / cooldown_minutes are otherwise never surfaced to the athlete
    // (the description_template holds main-set language only). wrapWithWarmupCooldown()
    // prepends a warmup sentence and appends a cooldown sentence for these (§18.7
    // display correction). Split by whether the warmup finishes with strides.
    // Intentionally absent — these already describe their own warmup/cooldown, so
    // wrapping them would double it: run_walk_intervals, standalone_strides, and
    // continuous_progression_tempo (its description_template self-supplies the wrapper:
    // "Warm up with {{warmup_minutes}} minutes of easy running. {{progression_instruction}}
    // ... Cool down with {{cooldown_minutes}} minutes of easy running.").
    const WARMUP_WITH_STRIDES_ARCHETYPES = [
        'equal_distance_repeats', 'short_speed_repeats', 'sustained_hill_repeats',
        'hill_sprints', 'plyometric_hill_circuits', 'hill_sprint_ladder',
    ];
    const WARMUP_NO_STRIDES_ARCHETYPES = [
        'tempo_intervals', 'high_volume_time_intervals',
        'structured_fartlek_ladder', 'mixed_distance_repeats',
    ];

    // Return-to-running adaptive progression bounds (engine spec §18.10 / §19 item 6).
    // Stage 1 is the gentlest run/walk; stage 10 is the first 45-min continuous run.
    const RTR_MIN_STAGE   = 1;
    const RTR_MAX_STAGE   = 10;
    // Rolling visibility horizon for the run/walk window, in days. Mirrors the
    // ATHLETE_WINDOW_DAYS used by cron_update_visibility / approvePlan.
    const RTR_WINDOW_DAYS = 10;

    /** @var array|null Cached engine settings from config/engine_settings.php. */
    private static ?array $settings = null;

    /**
     * Decoded pace_zones for the athlete currently being generated, or null when
     * the athlete's zones are hidden or empty. Set per-generation in generate().
     * When non-null, quality instructions cite the relevant pace zone (§19 item 14);
     * when null, instructions are effort-only and byte-for-byte unchanged.
     */
    private static ?array $paceZones = null;

    /**
     * Real (normalized) goal distance for the athlete currently being generated, e.g.
     * 'mile' or '50k'. Used for fine-grained, distance-specific behaviour that isn't
     * threaded through every signature: the mile tempo pace band (§ mile Part 11) and
     * the mile short-rep distance bias (§ mile Part 10). Set in generate() /
     * composeManualWorkout() and reset to null afterward. Null outside generation.
     */
    private static ?string $planDistance = null;

    /**
     * Across-cycle progression context for the week currently being generated, or null
     * outside a progressing cycle (ad-hoc preview / manual compose / maintenance), in which
     * case the volume-anchored archetypes fall back to per-instance ranging.
     *   fraction — 0..1 position on the volume build-trend (cycle start → peak ceiling),
     *              read AFTER weekly progression is applied. tempo slides its target UP with
     *              it (build); hills / high-volume intervals INVERT it (sharpen toward peak).
     *   cutback  — multiplier (<=1) applied on cutback weeks so the tempo target dips
     *              subtly (half the percentage total volume drops); 1.0 otherwise. Tempo only.
     * Set per-week in the race-cycle / development generators; consumed by the
     * distribute*() methods for tempo_intervals, sustained_hill_repeats, and
     * high_volume_time_intervals.
     */
    private static ?array $cycleProgress = null;

    /**
     * Coaching Intelligence Layer (Part 7) decision-resolver state for the current
     * generation. $coachingDecisions holds the active decisions loaded for the athlete's
     * coaches; the rest accumulate which decisions fired and any conflict notes across
     * the per-week selection passes, written to training_plans.coach_generation_notes
     * after generation. Reset at the start of each generate().
     */
    private static array $coachingDecisions     = [];
    private static array $decisionFiredTitles   = [];  // id => title
    private static array $decisionConflictNotes = [];  // note => true (deduped)
    private static string $genGoalDistance      = '';
    private static string $genClassification    = '';
    private static string $genPlanType          = '';

    /** Load and cache engine settings. */
    private static function settings(): array
    {
        if (self::$settings === null) {
            $path = __DIR__ . '/../../config/engine_settings.php';
            self::$settings = file_exists($path) ? (require $path) : [];
        }
        return self::$settings;
    }

    /**
     * Return the number of quality slots (0–2) allowed in a week.
     *
     * development_plan alternates 1/2 every two weeks (average 1.5/week, 3 per 2 weeks).
     * Cutback weeks always get 1 quality slot regardless of plan type or phase.
     */
    private static function getQualitySlotCount(
        string $planType, string $phase, int $daysPerWeek, int $weekNumber, bool $isCutback,
        ?string $realDistance = null, ?string $classification = null
    ): int {
        // Ultra distances override the per-distance quality cadence (ultra spec Part 10).
        if ($realDistance !== null && in_array($realDistance, self::ULTRA_DISTANCES, true)) {
            if ($isCutback) return 1;
            return match ($realDistance) {
                '50k'       => $weekNumber % 2 === 0 ? 2 : 1, // ~1.5/wk (development-style)
                '50_miler'  => 1,                              // 1/wk throughout
                '100k'      => 1,                              // 1/wk base/build; peak 0–1 (back-to-back may strip)
                '100_miler' => $phase === 'peak' ? 0 : 1,      // 0–1 base/build, 0 peak
                default     => 1,
            };
        }

        // Mile: speed-weighted, highest quality cadence (mile spec Part 9).
        if ($realDistance === 'mile') {
            if ($isCutback) return 1;
            return match ($phase) {
                'base'  => 1,
                'build' => 2,
                'peak'  => $classification === 'well_trained' ? 3 : 2, // 2–3, 3 well-trained only
                'taper' => 1,                                          // activation only
                default => 1,
            };
        }

        if ($planType === 'maintenance_plan') return 1;

        if ($planType === 'development_plan') {
            if ($isCutback) return 1;
            return $weekNumber % 2 === 0 ? 2 : 1;
        }

        // race_cycle
        return match($phase) {
            'base'          => 1,
            'build', 'peak' => $daysPerWeek >= 5 ? 2 : 1,
            'taper'         => 1,
            default         => 1,
        };
    }

    // ── Ultra-distance helpers (ultra spec) ──────────────────────────────────

    /** True when a (raw or normalized) goal distance is one of the four ultra distances. */
    private static function isUltra(string $distance): bool
    {
        return in_array(self::normalizeDistance($distance), self::ULTRA_DISTANCES, true);
    }

    /**
     * Distance key for the ARCHETYPE/PACE layer. Ultras map to 'marathon' (archetypes
     * carry no ultra goal_distances and the pace-range/effort maps key on
     * 5K/10K/half/marathon); the engine's volume/long-run/cutback sizing keeps the real
     * ultra key separately. Non-ultra distances are returned unchanged.
     */
    private static function selectorDistance(string $distance): string
    {
        $d = self::normalizeDistance($distance);
        if (in_array($d, self::ULTRA_DISTANCES, true)) return 'marathon';
        // Mile maps to 5K for the archetype/pace layer (fastest archetype distance;
        // archetypes carry no 'mile' goal_distance). Engine sizing keeps the real key.
        if ($d === 'mile') return '5K';
        return $d;
    }

    /** True when a (raw or normalized) goal distance is the mile / 1500m. */
    private static function isMile(string $distance): bool
    {
        return self::normalizeDistance($distance) === 'mile';
    }

    /** Cycle-length [min, max] in weeks (ultra- and mile-aware). */
    private static function cycleWeekBounds(string $distance): array
    {
        $d = self::normalizeDistance($distance);
        if (isset(self::ULTRA_CYCLE_WEEKS[$d])) return self::ULTRA_CYCLE_WEEKS[$d];
        if ($d === 'mile') return self::MILE_CYCLE_WEEKS;
        return [self::MIN_CYCLE[$d] ?? 8, self::MAX_PLAN_WEEKS];
    }

    /** Phase proportions for distance+classification. 100-miler and mile have custom shapes. */
    private static function phaseProportionsFor(string $distance, string $classification): array
    {
        $d = self::normalizeDistance($distance);
        if ($d === '100_miler') {
            // Base 35% + 5% remainder (added to base in calculatePhases) → 40%;
            // build 30%; peak 20%; taper 10% (capped to 2 weeks below).
            return ['base' => 0.35, 'build' => 0.30, 'peak' => 0.20, 'taper' => 0.10];
        }
        if ($d === 'mile') {
            // Speed-development weighted (mile spec Part 5): short base, expanded
            // build + peak. Sums to 1.0, so no remainder is redistributed.
            return ['base' => 0.25, 'build' => 0.35, 'peak' => 0.25, 'taper' => 0.15];
        }
        return self::PHASE_PROPORTIONS[$classification] ?? self::PHASE_PROPORTIONS['workable'];
    }

    /** Peak weekly-volume ceiling (minutes) for the mile, or null for non-mile. */
    private static function mileVolumeCeiling(string $classification): int
    {
        return self::MILE_VOLUME_CEILING[$classification] ?? self::MILE_VOLUME_CEILING['workable'];
    }

    /** Long-run duration cap (minutes) for a mile phase (pure aerobic support). */
    private static function mileLongRunCap(string $phase): int
    {
        return self::MILE_LONG_RUN_CAP[$phase] ?? self::MILE_LONG_RUN_CAP['base'];
    }

    /** Peak weekly-volume ceiling (minutes) for an ultra; workable capped at 75%. null otherwise. */
    private static function ultraVolumeCeiling(string $distance, string $classification): ?int
    {
        $d = self::normalizeDistance($distance);
        if (!isset(self::ULTRA_VOLUME_CEILING[$d])) return null;
        $ceiling = self::ULTRA_VOLUME_CEILING[$d];
        return $classification === 'well_trained' ? $ceiling : (int)round($ceiling * 0.75);
    }

    /** Long-run duration cap (minutes) for an ultra phase, or null for non-ultra. */
    private static function ultraLongRunCap(string $distance, string $phase): ?int
    {
        $d = self::normalizeDistance($distance);
        if (!isset(self::ULTRA_LONG_RUN_CAP[$d])) return null;
        $caps = self::ULTRA_LONG_RUN_CAP[$d];
        return $caps[$phase] ?? $caps['base'];
    }

    /**
     * Whether week N is a cutback week.
     *   100 miler        → strict every 3 weeks
     *   50 miler / 100K  → alternating 3/4-week blocks (every 3–4 weeks)
     *   everything else  → standard every 4 weeks (marathon / 50K / non-ultra)
     * Never week 1 or the taper. This is the single cutback predicate for race cycles.
     */
    private static function isCutbackWeek(string $distance, int $week, string $phase): bool
    {
        if ($week <= 1 || $phase === 'taper') return false;
        return match (self::normalizeDistance($distance)) {
            '100_miler'        => $week % 3 === 0,
            '50_miler', '100k' => self::alternating34Cutback($week),
            default            => $week % 4 === 0,
        };
    }

    /** Cutback weeks at 4,7,11,14,18,21,… (first at 4, then gaps alternate 3,4). */
    private static function alternating34Cutback(int $week): bool
    {
        $cut = 4; $gapThree = true;
        while ($cut < $week) { $cut += $gapThree ? 3 : 4; $gapThree = !$gapThree; }
        return $cut === $week;
    }

    /** Sunday medium-long run as a fraction of Saturday's long run. */
    private static function ultraSundayRatio(string $distance): float
    {
        return self::ULTRA_BACK_TO_BACK_RATIO[self::normalizeDistance($distance)] ?? 0.55;
    }

    /**
     * Whether a (non-cutback) week schedules a Saturday-long + Sunday-medium-long
     * back-to-back pair (ultra spec Part 9). $phases is the calculatePhases() map.
     */
    private static function ultraBackToBackWeek(
        string $distance, int $week, string $phase, bool $isCutback, array $phases
    ): bool {
        $d = self::normalizeDistance($distance);
        if (!in_array($d, self::ULTRA_DISTANCES, true) || $isCutback || $phase === 'taper') {
            return false;
        }

        $build = $phases['build'] ?? null;
        $base  = $phases['base']  ?? null;
        $peak  = $phases['peak']  ?? null;

        switch ($d) {
            case '50k':
                // Peak only, last 2 (non-cutback) weeks of peak before taper.
                return $phase === 'peak' && $peak && $week >= ($peak['end_week'] - 1);
            case '50_miler':
                if ($phase === 'peak') return true;                       // every non-cutback peak week
                return $phase === 'build' && $build && $week >= ($build['end_week'] - 1); // last 2 build weeks
            case '100k':
                if ($phase === 'peak') return true;
                if ($phase === 'build' && $build) {                       // mid-build onward
                    $mid = (int)floor(($build['start_week'] + $build['end_week']) / 2);
                    return $week >= $mid;
                }
                return false;
            case '100_miler':
                if ($phase === 'peak' || $phase === 'build') return true; // every non-cutback build/peak week
                if ($phase === 'base' && $base) {                         // from mid-base, ≥ base week 6
                    $weekInPhase = $week - $base['start_week'] + 1;
                    $mid         = (int)ceil(($base['end_week'] - $base['start_week'] + 1) / 2);
                    return $weekInPhase >= max(6, $mid);
                }
                return false;
        }
        return false;
    }

    /**
     * Trail long-run instruction cue (Part 12) + power-hiking guidance (Part 13),
     * appended to ultra long-run / medium-long instructions when surface = 'trail'.
     * Power-hiking practice is included for 50 miler / 100K / 100 miler in every
     * phase, and for 50K in the peak phase only. Returns '' for road/non-ultra.
     */
    private static function ultraTrailLongRunCue(string $distance, ?string $surface, string $phase): string
    {
        if ($surface !== 'trail') return '';
        $d = self::normalizeDistance($distance);
        if (!in_array($d, self::ULTRA_DISTANCES, true)) return '';

        $cue = 'Focus on time on feet rather than pace. Walk the uphills when needed: power hiking '
             . 'is a legitimate race strategy and saves your legs for the downhills.';

        $powerHike = ($d !== '50k') || ($phase === 'peak');
        if ($powerHike) {
            $cue .= ' On any significant climbs, transition to a strong power hike rather than running. '
                  . 'Focus on maintaining consistent effort, not pace. Practice this in training: '
                  . "it's a race skill, not a sign of weakness.";
        }
        return $cue;
    }

    /** Archetype score multipliers for trail ultras (hill/fartlek up, track reps down). */
    private static function ultraWeightAdjust(?string $surface): array
    {
        if ($surface !== 'trail') return [];
        return [
            'sustained_hill_repeats'    => 2.0,
            'hill_sprints'              => 2.0,
            'structured_fartlek_ladder' => 1.5,
            'equal_distance_repeats'    => 0.5,
        ];
    }

    /** Archetype score multipliers for mile athletes (speed/threshold up, plyos down). */
    private static function mileWeightAdjust(): array
    {
        return self::MILE_WEIGHT_ADJUST;
    }

    /**
     * Quality archetype codes to exclude for an ultra, by phase/week (FIX 3).
     *   100-miler / 100K — track-style speed and equal/mixed-distance repeats are wrong
     *     for ultra training: excluded in base and peak entirely, allowed only sparingly
     *     in build (≤ one week in four). 100-miler additionally never runs high-volume
     *     track intervals in any phase.
     * Other ultras (50K / 50 miler) keep the full quality pool.
     */
    private static function ultraQualityExcludeCodes(string $distance, string $phase = 'base', int $week = 1): array
    {
        $d = self::normalizeDistance($distance);
        if (!in_array($d, ['100_miler', '100k'], true)) {
            return [];
        }
        $speedTrio = ['short_speed_repeats', 'equal_distance_repeats', 'mixed_distance_repeats'];
        // 100-miler favours aerobic threshold work over any track-style speed session.
        $always = $d === '100_miler' ? ['high_volume_time_intervals'] : [];

        // build: allow the speed trio sparingly (one week in four); base/peak/taper: never.
        if ($phase === 'build' && $week % 4 === 0) {
            return $always;
        }
        return array_merge($always, $speedTrio);
    }

    /**
     * Quality-archetype score multipliers for ultras (FIX 3 / FIX 10A). All ultras favour
     * the hill-sprint ladder; 100-miler / 100K additionally weight sustained hill repeats,
     * the structured fartlek ladder, and tempo intervals 3× (aerobic-power + threshold bias).
     */
    private static function ultraThresholdWeightAdjust(string $distance): array
    {
        $d   = self::normalizeDistance($distance);
        $adj = [];
        if (in_array($d, self::ULTRA_DISTANCES, true)) {
            $adj['hill_sprint_ladder'] = 2.0;
        }
        if (in_array($d, ['100_miler', '100k'], true)) {
            $adj['sustained_hill_repeats']    = 3.0;
            $adj['structured_fartlek_ladder'] = 3.0;
            $adj['tempo_intervals']           = 3.0;
        }
        return $adj;
    }

    /**
     * Per-phase long-run duration ceiling (minutes) for a race cycle (FIX 7). Ultras use the
     * explicit per-phase caps; marathon derives a cap from the peak weekly-volume ceiling
     * (clamped to a sane 75–210 min band). Returns null for distances that keep the legacy
     * weekly-volume long-run sizing (5K / 10K / half).
     */
    private static function raceLongRunPhaseCap(string $distance, string $phase, int $peakCeiling): ?int
    {
        $ultraCap = self::ultraLongRunCap($distance, $phase);
        if ($ultraCap !== null) return $ultraCap;

        if (self::normalizeDistance($distance) === 'marathon') {
            $frac = match ($phase) {
                'base'  => 0.30,
                'build' => 0.33,
                'peak'  => 0.35,
                'taper' => 0.30,
                default => 0.30,
            };
            return min(210, max(75, (int)round($peakCeiling * $frac)));
        }
        return null;
    }

    /** Per-phase rep-count cap for short/distance repeat archetypes (FIX 2). */
    private static function phaseRepCap(string $phase): int
    {
        return match ($phase) {
            'base'  => 8,
            'build' => 12,
            'peak'  => 16,
            default => 16,
        };
    }

    /**
     * Coach-friendly label for a rep/interval duration in seconds (FIX 1):
     * under a minute → "X sec"; a minute and above → "Xm YYs" (e.g. 90 → "1m 30s").
     */
    private static function formatSecondsLabel(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) return $seconds . ' sec';
        return sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }

    /** Compact duration label ("Xh Ymin" / "Xh" / "X min"), mirroring the view helper. */
    private static function durationLabel(int $minutes): string
    {
        if ($minutes < 60) return $minutes . ' min';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m ? "{$h}h {$m}min" : "{$h}h";
    }

    // ── Volume / schedule allocation (§6/§7) ─────────────────────────────────

    /**
     * Item 1 — how many training days a given week's weeklyMins can structurally
     * support under the canonical week shape (§7): 1 long run at the long-run floor
     * plus each additional day at the easy-run floor.
     *
     *   supported = 1 + floor((weeklyMins − longFloor) / easyFloor)
     *
     * e.g. long-floor 60, easy-floor 30: 120 → 3 days, 180 → 5 days. The caller
     * caps the requested training_days_per_week to this so low-volume weeks run
     * fewer days (avoiding all-easy degenerate weeks where the quality budget is
     * squeezed below any structured archetype). Returns ≥1; callers apply their own
     * minimum (2).
     */
    private static function supportedTrainingDays(int $weeklyMins): int
    {
        $s         = self::settings();
        $longFloor = (int)($s['long_run_absolute_floor_minutes'] ?? 60);
        $easyFloor = (int)($s['easy_run_min_minutes'] ?? 20);
        $supported = 1 + (int)floor(max(0, $weeklyMins - $longFloor) / max(1, $easyFloor));
        return max(1, $supported);
    }

    /**
     * Item 2 — informational flag when the athlete's requested training_days_per_week
     * exceeds what week-1 volume can support (Item 1). Not a hold state: the plan
     * generates and is usable, just at a reduced initial day count that ramps up.
     */
    private static function maybeRaiseScheduleRampFlag(
        int $athleteId, int $week1Mins, array $profile, PDO $db
    ): void {
        $requested = max(2, min(7, (int)($profile['training_days_per_week'] ?? 4)));
        $supported = max(2, self::supportedTrainingDays($week1Mins));
        if ($supported >= $requested) return;

        self::raiseFlag(
            $athleteId, 'schedule_day_ramp', 'info',
            "Schedule will start at {$supported} day(s)/week (you requested {$requested}) and ramp up "
            . "toward {$requested} as weekly volume increases. Current volume ({$week1Mins} min/week) does not "
            . "yet support {$requested} running days at the minimum easy-run duration.",
            $db
        );
    }

    /**
     * Item 4 — resolve the week-1 starting weekly minutes.
     *
     * For onboarding / coach_manual the athlete-profile current_weekly_minutes is the
     * source (no continuity assumed). For block_end / engine_rebuild (a prior plan ran
     * its full progression) the new cycle continues the prior plan's trajectory: it
     * starts from the prior plan's peak weekly volume (capped by peak_volume_ceiling),
     * so volume does not silently reset to the onboarding-era value every cycle.
     *
     * Manual-edit override: if the profile was edited after the prior plan was
     * generated (profile.updated_at > prior.generated_at), the coach has likely set a
     * new deliberate baseline, so current_weekly_minutes takes precedence over derived
     * continuity. (Heuristic: athlete_profiles has a single updated_at, not a per-column
     * timestamp, so any profile edit since the last plan counts — documented in §6.)
     */
    private static function resolveStartingWeeklyMins(
        int $athleteId, array $profile, string $trigger, int $currentMins, int $peakCeiling, PDO $db
    ): int {
        if (!in_array($trigger, ['block_end', 'engine_rebuild'], true)) {
            return $currentMins;
        }

        $prior = $db->prepare(
            'SELECT id, generated_at FROM training_plans
             WHERE athlete_id = ? AND plan_type IN ("race_cycle","development_plan","maintenance_plan")
             ORDER BY id DESC LIMIT 1'
        );
        $prior->execute([$athleteId]);
        $priorRow = $prior->fetch(PDO::FETCH_ASSOC);
        if (!$priorRow) return $currentMins;

        // Manual edit since the prior plan → respect the new baseline.
        $profileUpdated = strtotime((string)($profile['updated_at'] ?? '')) ?: 0;
        $priorGenerated = strtotime((string)($priorRow['generated_at'] ?? '')) ?: 0;
        if ($profileUpdated > $priorGenerated) {
            return $currentMins;
        }

        $priorPeak = self::priorPlanPeakVolume((int)$priorRow['id'], $db);
        if ($priorPeak === null || $priorPeak <= 0) return $currentMins;

        // Continue the trajectory, never above the ceiling (§6 "maintains volume").
        return max($currentMins, min($priorPeak, $peakCeiling));
    }

    /**
     * Peak weekly volume reached by a prior plan: sum of stored target_duration per
     * code-week window, max across windows. Uses the peak (not the literal final
     * week, which is typically a planned cutback/taper dip) so continuity
     * reflects the athlete's achieved trajectory rather than the wind-down.
     */
    private static function priorPlanPeakVolume(int $planId, PDO $db): ?int
    {
        $stmt = $db->prepare(
            'SELECT pw.scheduled_date, pw.target_duration, tp.plan_start_date, tp.plan_end_date, tp.plan_type
              FROM planned_workouts pw JOIN training_plans tp ON tp.id = pw.plan_id
              WHERE pw.plan_id = ?'
        );
        $stmt->execute([$planId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;

        $startDate = (string)$rows[0]['plan_start_date'];
        if (self::hasCalendarAlignedCodeWeeks(
            (string)($rows[0]['plan_type'] ?? ''),
            $startDate,
            (string)($rows[0]['plan_end_date'] ?? '')
        )) {
            $startDate = self::firstMondayOnOrAfter($startDate);
        }

        $start   = strtotime($startDate);
        $buckets = [];
        foreach ($rows as $r) {
            $scheduledTs = strtotime($r['scheduled_date']);
            if ($scheduledTs < $start) continue;

            $w = (int)floor(($scheduledTs - $start) / (7 * 86400));
            $buckets[$w] = ($buckets[$w] ?? 0) + (int)($r['target_duration'] ?? 0);
        }
        return $buckets ? (int)max($buckets) : null;
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Generate a plan for an athlete.
     * Returns plan_id on success, null on failure.
     */
    /**
     * @param bool $fullWipe When true, the legacy full-rebuild: archive the prior plan,
     *   delete ALL its Intervals events, regenerate from scratch with no carry-over.
     *   When false (DEFAULT), a regen over an ACTIVE prior plan with athlete-exposed weeks
     *   PRESERVES those whole weeks (and any coach_locked row) by carrying them into the new
     *   plan untouched; generation math is unchanged (full-span), the carried dates are then
     *   restored from the prior plan.
     */
    public static function generate(int $athleteId, string $trigger = 'onboarding', bool $fullWipe = false): ?int
    {
        $db      = Database::get();
        $profile = self::loadProfile($athleteId, $db);
        if (!$profile) return null;

        // Pace-zone citation context (§19 item 14). Only populated when the athlete's
        // zones are both visible and non-empty; otherwise null → effort-only output.
        self::$paceZones = (!empty($profile['pace_zones_visible'])
            && PaceZones::isPopulated($profile['pace_zones'] ?? null))
            ? (json_decode($profile['pace_zones'] ?? 'null', true) ?: null)
            : null;

        // Real goal distance for distance-specific behaviour not threaded through every
        // signature (mile tempo pace band + mile short-rep distance bias).
        self::$planDistance = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');

        // Stage B tempo progression is set per-week by the progressing generators; null
        // here so any non-progressing path (maintenance, recovery, RTR) keeps Stage A.
        self::$cycleProgress = null;

        // Persist the engine's base classification at generation time (BUG 1). It is
        // computed here from the athlete's goal distance + current volume profile and
        // cached on athlete_profiles so downstream consumers (UI, future regenerations,
        // coach overrides) can read it without recomputing. Previously it was computed
        // transiently inside each generator and never written back, leaving the column NULL.
        $baseClassification = self::classifyAthlete($profile, self::$planDistance);
        $db->prepare('UPDATE athlete_profiles SET base_classification = ? WHERE athlete_id = ?')
           ->execute([$baseClassification, $athleteId]);

        $planType = $profile['plan_type'] ?? 'development_plan';

        // Coaching Intelligence decision-resolver context (Part 7). Load the active
        // decisions for this athlete's coaches once; per-week application + firing
        // tracking happen inside insertWeekWorkouts, finalized after generation.
        self::$genGoalDistance       = (string)($profile['goal_race_distance'] ?? '');
        self::$genClassification     = (string)$baseClassification;
        self::$genPlanType           = (string)$planType;
        self::$decisionFiredTitles   = [];
        self::$decisionConflictNotes = [];
        self::$coachingDecisions     = class_exists('CoachingDecisions')
            ? CoachingDecisions::loadActiveForAthlete($athleteId, $db) : [];

        // Raise limited development flag for 3-day-per-week athletes (info, not blocking).
        if (!in_array($planType, ['recovery_block', 'return_to_running'])) {
            if ((int)($profile['training_days_per_week'] ?? 0) === 3) {
                self::raiseFlag($athleteId, 'limited_development_opportunity', 'info',
                    '3 days per week can support consistency, but it limits improvement and development potential.', $db);
            }
        }

        // Regen carry-over (default): capture the athlete-exposed whole weeks + any
        // coach_locked rows from the prior ACTIVE plan BEFORE archiving, so the archive
        // can spare their Intervals events and we can carry them into the new plan after
        // generation. $fullWipe skips this entirely (legacy full rebuild).
        $preserve = $fullWipe ? null : self::capturePreservation($athleteId, $db);

        self::archivePreviousPlans($athleteId, $db, $preserve['row_ids'] ?? []);

        $selector          = new ArchetypeSelector($db);
        $antiRepeatHistory = self::loadAntiRepeatHistory($athleteId, $db);

        // Seed anti-repeat with the carried signatures/codes: the carried rows live in the
        // new plan but are swapped in only after generation, so the fresh remainder must be
        // told about them here to avoid duplicating them within the hard-block window.
        if ($preserve !== null) {
            foreach ($preserve['signatures'] as $sig => $dates) {
                $antiRepeatHistory['signatures'][$sig] = array_merge($antiRepeatHistory['signatures'][$sig] ?? [], $dates);
            }
            foreach ($preserve['codes'] as $code => $dates) {
                $antiRepeatHistory['codes'][$code] = array_merge($antiRepeatHistory['codes'][$code] ?? [], $dates);
            }
        }

        $planId = match ($planType) {
            'race_cycle'        => self::generateRaceCycle($athleteId, $profile, $trigger, $db, $selector, $antiRepeatHistory),
            'return_to_running' => self::generateReturnToRunning($athleteId, $profile, $trigger, $db),
            'maintenance_plan'  => self::generateMaintenancePlan($athleteId, $profile, $trigger, $db, $selector, $antiRepeatHistory),
            'recovery_block'    => self::generateRecoveryBlock($athleteId, $profile, $trigger, $db),
            default             => self::generateDevelopmentPlan($athleteId, $profile, $trigger, $db, $selector, $antiRepeatHistory),
        };

        if ($planId) {
            // The day-1 easy-run guarantee (§19 item 16) applies to race / development /
            // maintenance plans. A return_to_running plan must keep its stage-1 run/walk
            // session on day 1 — never overwrite it with a continuous easy run.
            if ($planType !== 'return_to_running') {
                self::ensurePlanStartEasyRun($planId, $athleteId, $profile, $db, $selector);
            }
            // Patch the plan around any races on file (pre-race aerobic taper, no quality
            // within 3 days, race-day skip, post-race recovery) — §26 / ultra-spec Part 5.
            self::applyRaceAdjustments($athleteId, $db);
            self::validateGeneratedDisplays($planId, $athleteId, $db);
            // Carry the preserved (exposed-week + coach_locked) rows into the new plan,
            // replacing the freshly-generated rows on those dates. Done last so race
            // adjustments / day-1 easy / display validation run on the fresh region only.
            if ($preserve !== null) {
                self::applyPreservation($planId, $preserve, $db);
            }
            // Consistency guard: catch a contradictory plan (gentlest run/walk on-ramp +
            // continuous runs well past a beginner's reach) regardless of how the
            // misclassification arose. Runs on the final, preserved-and-patched content.
            self::flagClassificationContradiction($planId, $athleteId, $planType, $db);
            self::finalizeCoachingDecisions($planId, $db);
            self::createApprovalQueueEntry($planId, $athleteId, $trigger, $db);
        }

        self::$planDistance       = null;
        self::$coachingDecisions  = [];
        self::$cycleProgress = null;
        return $planId;
    }

    // ── Coaching Intelligence decision resolver (Part 7) ──────────────────────

    /**
     * Apply the active coach decisions matching this week's context to the quality
     * selection inputs, recording which decisions fire and any conflicts. Mutates
     * $qualWeightAdjust / $qualExcludeCodes in place; returns max_quality_per_week
     * (null when unconstrained).
     */
    private static function applyCoachingDecisions(
        array &$qualWeightAdjust, array &$qualExcludeCodes,
        string $phase, string $classification, string $planType
    ): ?int {
        if (empty(self::$coachingDecisions) || !class_exists('CoachingDecisions')) return null;

        $ctx = [
            'goal_distance'  => self::$genGoalDistance,
            'phase'          => $phase,
            'classification' => $classification,
            'plan_type'      => $planType,
        ];
        $res = CoachingDecisions::resolve(self::$coachingDecisions, $ctx);

        if (!empty($res['exclude'])) {
            $qualExcludeCodes = array_values(array_unique(array_merge($qualExcludeCodes, $res['exclude'])));
        }
        foreach ($res['weights'] as $code => $mult) {
            $qualWeightAdjust[$code] = (float)($qualWeightAdjust[$code] ?? 1.0) * (float)$mult;
        }
        if ($res['force'] !== null && $res['force'] !== '') {
            // Strongly prefer the forced archetype in the weighted draw.
            $qualWeightAdjust[$res['force']] = max((float)($qualWeightAdjust[$res['force']] ?? 1.0), 999.0);
        }

        foreach ($res['fired'] as $f)      self::$decisionFiredTitles[(int)$f['id']] = (string)$f['title'];
        foreach ($res['conflicts'] as $n)  self::$decisionConflictNotes[$n] = true;

        return $res['max_quality'];
    }

    /**
     * Persist the decision-resolver outcome to training_plans.coach_generation_notes and
     * bump times_fired / last_fired_at on each decision that fired this generation.
     */
    private static function finalizeCoachingDecisions(int $planId, PDO $db): void
    {
        $lines = [];
        if (!empty(self::$decisionFiredTitles)) {
            $lines[] = 'Coaching decisions applied: ' . implode(', ', array_values(self::$decisionFiredTitles));
            foreach (array_keys(self::$decisionConflictNotes) as $note) $lines[] = $note;
            if (class_exists('CoachingDecisions')) {
                CoachingDecisions::recordFired(array_keys(self::$decisionFiredTitles), $db);
            }
        } else {
            $lines[] = 'No coaching decisions matched.';
        }

        try {
            $db->prepare('UPDATE training_plans SET coach_generation_notes = ? WHERE id = ?')
               ->execute([implode("\n", $lines), $planId]);
        } catch (\Throwable $e) {
            error_log('finalizeCoachingDecisions failed: ' . $e->getMessage());
        }
    }

    // ── Race-aware plan adjustments (§26 Tune-Up Race Handling) ───────────────

    /** Easy/rest recovery days inserted after a race, by race distance. */
    const RACE_RECOVERY_DAYS = [
        '5K' => 3, '10K' => 5, '15K' => 6, 'half' => 7, 'marathon' => 14,
        'ultra' => 18, '50k' => 14, '50_miler' => 16, '100k' => 21, '100_miler' => 21,
        'other' => 5,
    ];

    /** Pure-aerobic archetypes a pre-race long run is allowed to use. */
    const PURE_AEROBIC_CODES = ['continuous_long', 'continuous_easy'];

    private static function recoveryDaysForRace(string $distance): int
    {
        return self::RACE_RECOVERY_DAYS[$distance] ?? 5;
    }

    /**
     * Patch the athlete's ACTIVE (or pending) plan around every race on file. Idempotent:
     * all changes are expressed as caps / type swaps / deletes, so re-running (e.g. on each
     * race add, and at plan generation) converges rather than compounding.
     *
     *   - Race day: training workouts removed (the race renders as its own calendar entry).
     *   - ≤7 days before: long runs forced to pure aerobic; 3–4 days out capped to 60% of a
     *     normal long run; 1–2 days out becomes a ≤30 min shakeout.
     *   - ≤3 days before: no quality sessions (converted to easy).
     *   - After the race: recovery/rest days inserted per distance.
     *
     * Coach-locked workouts are never touched.
     */
    public static function applyRaceAdjustments(int $athleteId, ?PDO $db = null): void
    {
        $db = $db ?? Database::get();

        $planStmt = $db->prepare(
            'SELECT id, plan_start_date, plan_end_date FROM training_plans
             WHERE athlete_id = ? AND status IN ("active","pending_approval")
             ORDER BY id DESC LIMIT 1'
        );
        $planStmt->execute([$athleteId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) return;
        $planId = (int)$plan['id'];

        $racesStmt = $db->prepare('SELECT * FROM races WHERE athlete_id = ? ORDER BY race_date');
        $racesStmt->execute([$athleteId]);
        $races = $racesStmt->fetchAll(PDO::FETCH_ASSOC);
        // FIX 9: defend against cross-athlete race contamination. The query is already
        // scoped to athlete_id; this belt-and-suspenders filter guarantees a race row
        // belonging to a different athlete can never patch this athlete's plan, even if
        // a future query change or bad row slipped through. Only this athlete's races
        // adjust this athlete's plan.
        $races = array_values(array_filter(
            $races,
            static fn(array $r): bool => (int)($r['athlete_id'] ?? 0) === $athleteId
        ));
        if (!$races) return;

        // Stable "normal long run" reference (max long in the plan) so the 60% cap never
        // compounds across re-runs — a reduced long run is never the max when a full one exists.
        $nl = $db->prepare("SELECT MAX(target_duration) FROM planned_workouts WHERE plan_id = ? AND workout_type = 'long'");
        $nl->execute([$planId]);
        $normalLong = (int)($nl->fetchColumn() ?: 0);
        if ($normalLong < 60) $normalLong = 120;

        foreach ($races as $race) {
            self::applyOneRaceAdjustment($planId, $athleteId, $race, $normalLong, (string)$plan['plan_end_date'], $db);
        }
    }

    private static function applyOneRaceAdjustment(
        int $planId, int $athleteId, array $race, int $normalLong, string $planEnd, PDO $db
    ): void {
        $raceDate = (string)$race['race_date'];
        $raceTs   = strtotime($raceDate);
        $quality  = ['interval', 'tempo', 'hill', 'fartlek', 'speed', 'race_pace'];

        // Race day: clear non-locked training workouts (the race is its own calendar entry).
        $db->prepare('DELETE FROM planned_workouts WHERE plan_id = ? AND scheduled_date = ? AND coach_locked = 0')
           ->execute([$planId, $raceDate]);

        // Pre-race window (7 days before .. day before).
        $pre = $db->prepare(
            'SELECT * FROM planned_workouts
             WHERE plan_id = ? AND coach_locked = 0 AND scheduled_date BETWEEN ? AND ?'
        );
        $pre->execute([
            $planId,
            date('Y-m-d', strtotime($raceDate . ' -7 days')),
            date('Y-m-d', strtotime($raceDate . ' -1 day')),
        ]);

        $upd = $db->prepare(
            'UPDATE planned_workouts
             SET workout_type = ?, archetype_code = ?, archetype_variant = NULL, archetype_params = NULL,
                 structure = NULL, target_duration = ?, target_pace_min = NULL, target_pace_max = NULL,
                 intensity_load = ?, display_title = ?, display_summary = ?, athlete_instructions = ?, description = ?
             WHERE id = ?'
        );

        foreach ($pre->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $daysOut   = (int)round(($raceTs - strtotime((string)$w['scheduled_date'])) / 86400);
            $type      = (string)$w['workout_type'];
            $dur       = (int)$w['target_duration'];
            $isLong    = ($type === 'long');
            $isQuality = in_array($type, $quality, true);
            $isAerobic = in_array((string)$w['archetype_code'], self::PURE_AEROBIC_CODES, true);

            $newType = $type; $newDur = $dur; $newCode = (string)$w['archetype_code']; $touched = false;

            if ($isQuality && $daysOut <= 3) {                 // no quality within 3 days
                $newType = 'easy'; $newCode = 'continuous_easy'; $touched = true;
            }
            if ($isLong && !$isAerobic) {                       // long run within 7 days → pure aerobic
                $newCode = $dur >= 60 ? 'continuous_long' : 'continuous_easy'; $touched = true;
            }
            if ($daysOut <= 2) {                                // 1–2 days out: short shakeout
                $newType = ($newType === 'long') ? 'easy' : $newType;
                if ($newType !== 'recovery') $newType = 'easy';
                $newCode = 'continuous_easy';
                $newDur  = min($newDur > 0 ? $newDur : 30, 30);
                $touched = true;
            } elseif ($daysOut <= 4 && $isLong) {               // 3–4 days out: 60% of a normal long run
                $newDur  = min($dur > 0 ? $dur : $normalLong, (int)round($normalLong * 0.6));
                $touched = true;
            }

            if (!$touched) continue;

            $desc = $daysOut <= 2
                ? 'Pre-race shakeout: very easy and short. Stay loose and save your legs for race day.'
                : 'Pre-race aerobic run: keep it relaxed and purely aerobic. No fast running or workouts this close to your race.';
            $title   = $newType === 'long' ? 'Long Run (pre-race)' : 'Easy Run (pre-race)';
            $loadIf  = $newType === 'recovery' ? 0.3 : 0.5;
            $upd->execute([
                $newType, $newCode, $newDur, round($newDur * $loadIf, 2),
                $title, self::durationLabel(max(1, $newDur)), $desc, $desc, (int)$w['id'],
            ]);
        }

        // Post-race recovery days.
        $recDays = self::recoveryDaysForRace((string)$race['race_distance']);
        $insert  = $db->prepare(
            'INSERT INTO planned_workouts
             (plan_id, athlete_id, scheduled_date, workout_type, description,
              target_duration, intensity_load, visible_to_athlete, display_title, display_summary, athlete_instructions)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
        );
        for ($d = 1; $d <= $recDays; $d++) {
            $date = date('Y-m-d', strtotime($raceDate . " +{$d} days"));
            if ($planEnd !== '' && $date > $planEnd) break;

            // Tiered by proximity to the race. Any surviving quality session or long
            // run inside the window is overwritten by the tier treatment below (the
            // existing-row UPDATE clears archetype fields), so the whole post-race
            // window resolves to rest / easy / recovery regardless of what was there.
            //   days 1–3   → rest (fully off)
            //   days 4–7   → easy run, 30 min
            //   days 8–end → recovery run, 40 min
            // workout_type uses the real planned_workouts ENUM values ('easy',
            // 'recovery', 'rest') — never 'easy_run', which would truncate to ''.
            if ($d <= 3) {
                $type = 'rest'; $dur = null; $load = 0;
                $title = 'Rest'; $summary = 'Rest + recover';
                $desc  = 'Post-race rest day. Let your body recover with gentle movement only.';
            } elseif ($d <= 7) {
                $type = 'easy'; $dur = 30; $load = round(30 * 0.5, 2);
                $title = 'Easy Run'; $summary = '30 min · easy';
                $desc  = 'Short, very easy run as you recover from your race. Keep the effort relaxed and purely aerobic.';
            } else {
                $type = 'recovery'; $dur = 40; $load = round(40 * 0.3, 2);
                $title = 'Recovery Run'; $summary = '40 min · recovery';
                $desc  = 'Easy recovery run, gentle and aerobic while you continue to recover from your race.';
            }

            $ex = $db->prepare('SELECT id, coach_locked FROM planned_workouts WHERE plan_id = ? AND scheduled_date = ? LIMIT 1');
            $ex->execute([$planId, $date]);
            $row = $ex->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                if (!empty($row['coach_locked'])) continue;
                $db->prepare(
                    'UPDATE planned_workouts
                     SET workout_type = ?, archetype_code = NULL, archetype_variant = NULL, archetype_params = NULL,
                         structure = NULL, target_duration = ?, target_pace_min = NULL, target_pace_max = NULL,
                         intensity_load = ?, display_title = ?, display_summary = ?, athlete_instructions = ?, description = ?,
                         visible_to_athlete = 1
                     WHERE id = ?'
                )->execute([$type, $dur, $load, $title, $summary, $desc, $desc, (int)$row['id']]);
            } else {
                $insert->execute([$planId, $athleteId, $date, $type, $desc, $dur, $load, $title, $summary, $desc]);
            }
        }
    }

    // ── Manual coach add (macro-plan "+ Add workout" archetype picker) ────────

    /**
     * Resolve and render a single archetype instance for a coach manually adding a
     * workout to a plan. Mirrors the resolve→render pipeline of insertWeekWorkouts /
     * insertResolvedWorkout, but for one explicitly chosen archetype + variant +
     * duration rather than the weighted slot selector.
     *
     * Returns an associative array of planned_workouts columns to persist (also used
     * to render the modal's Step-3 preview), or null when the code is unknown/inactive.
     * The chosen variant defaults to the archetype's first variant when none is given.
     */
    public static function composeManualWorkout(
        int $athleteId, string $archetypeCode, ?string $variantCode,
        int $durationMinutes, ?PDO $db = null
    ): ?array {
        $db      = $db ?? Database::get();
        $profile = self::loadProfile($athleteId, $db);
        if (!$profile) return null;

        // Pace-zone citation context (same rule as generate()).
        self::$paceZones = (!empty($profile['pace_zones_visible'])
            && PaceZones::isPopulated($profile['pace_zones'] ?? null))
            ? (json_decode($profile['pace_zones'] ?? 'null', true) ?: null)
            : null;

        $selector  = new ArchetypeSelector($db);
        $archetype = $selector->getByCode($archetypeCode);
        if (!$archetype || ($archetype['status'] ?? 'active') !== 'active') {
            self::$paceZones = null;
            self::$planDistance = null;
            return null;
        }

        $rawDistance    = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');
        $classification = self::classifyAthlete($profile, $rawDistance);
        // Archetypes/pace maps key on marathon for ultras / 5K for mile (selectorDistance);
        // classification + planDistance keep the real key for distance-specific behaviour.
        self::$planDistance = $rawDistance;
        self::$cycleProgress = null; // ad-hoc: no cycle context -> Stage A tempo ranging
        $goalDistance   = self::selectorDistance($rawDistance);
        $phase          = 'base';

        $archetype = $selector->resolveParameters($archetype, $classification);

        // Choose the variant: explicit code if valid, else the first defined variant.
        $variants = $archetype['variants'] ?? [];
        $chosen   = null;
        if ($variantCode !== null && $variantCode !== '') {
            foreach ($variants as $v) {
                if (($v['code'] ?? null) === $variantCode) { $chosen = $v; break; }
            }
        }
        if ($chosen === null && !empty($variants)) {
            $chosen = $variants[0];
        }
        if ($chosen !== null) {
            $archetype['resolved_variant'] = $chosen;
        }

        // Never render below the archetype's minimum viable session. Continuous
        // archetypes (no minimum) keep the coach's requested number; fixed-duration
        // archetypes (run/walk, strides) ignore it and store their structural total.
        $minDur = $selector->getMinimumSessionDurationMinutes($archetype, $classification, $phase, $goalDistance);
        $target = max(1, $durationMinutes);
        if ($minDur !== null) {
            $target = max((int)ceil($minDur), $target);
        }

        $instance = self::addDerivedParams($archetype, $target, $phase, $goalDistance, $classification);

        $display      = $instance['display'] ?? [];
        $title        = self::renderTemplate($display['title_template'] ?? '', $instance);
        $summary      = self::renderTemplate($display['summary_template'] ?? '', $instance);
        $instructions = self::renderTemplate($display['description_template'] ?? '', $instance);
        $instructions = self::normalizeInstructionText($instructions, $instance);
        $instructions = self::wrapWithWarmupCooldown($instructions, $instance['resolved_params'] ?? [], $instance['code'] ?? '');
        $instructions = self::appendPaceCitation($instructions, $instance);
        $instructions = self::prependPrescriptionLead($instructions, $instance);

        $sig             = self::computeInstanceSignature($instance);
        $resolvedVariant = $instance['resolved_variant']['code'] ?? null;
        $params          = $instance['resolved_params'] ?? [];
        $structure       = self::resolveStructure($instance);
        $variantWorkout  = $instance['resolved_variant']['workout_type'] ?? null;
        $metadataWorkout = $instance['metadata']['workout_type'] ?? $instance['workout_type'] ?? null;
        $workoutType     = $variantWorkout ?? $metadataWorkout ?? 'easy';
        $variantIF       = $instance['resolved_variant']['intensity_factor'] ?? null;
        $intensityFactor = (float)($variantIF ?? $instance['generation']['intensity_factor'] ?? 0.5);
        $storedDuration  = self::computeActualDuration($instance) ?? (int)($params['duration_minutes'] ?? $target);
        $load            = round($storedDuration * $intensityFactor, 2);

        self::$paceZones = null;
        self::$planDistance = null;

        return [
            'workout_type'               => $workoutType,
            'archetype_code'             => $instance['code'],
            'archetype_variant'          => $resolvedVariant,
            'archetype_params'           => json_encode($params),
            'workout_archetype_id'       => $instance['id'] ?? null,
            'archetype_version_snapshot' => $instance['version'] ?? null,
            'instance_signature'         => $sig ?: null,
            'structure'                  => $structure ? json_encode($structure) : null,
            'display_title'              => $title ?: null,
            'display_summary'            => $summary ?: null,
            'athlete_instructions'       => $instructions ?: null,
            'target_duration'            => $storedDuration,
            'intensity_load'             => $load,
        ];
    }

    /**
     * Archetypes the structured editor supports: the 5 uniform-rep ones (Phase 1, count +
     * size + recovery) plus mixed_distance_repeats (Phase 2, an ordered rung ladder).
     */
    public const STRUCTURED_EDIT_CODES = [
        'tempo_intervals', 'sustained_hill_repeats', 'equal_distance_repeats',
        'short_speed_repeats', 'high_volume_time_intervals', 'mixed_distance_repeats',
    ];

    public static function isStructuredEditable(?string $code): bool
    {
        return $code !== null && in_array($code, self::STRUCTURED_EDIT_CODES, true);
    }

    /**
     * Re-render a planned workout's DESCRIPTION ONLY from its EXISTING stored params +
     * structure and the athlete's CURRENT pace zones, with NO change to the workout itself.
     * Unlike composeStructuredEdit (which re-derives rep_distance_miles from the athlete's
     * current pace to support edits), this uses the stored params verbatim, so the rebuilt
     * structure is byte-identical to the stored one. addDerivedParams runs in $manual mode
     * (no ranging/cap/snap/re-roll); only the display/render tail runs, picking up the
     * prescription lead line and re-deriving pace citations from current zones.
     *
     * Returns ['display_title','display_summary','athlete_instructions','structure'] (the
     * rebuilt structure is returned only so the caller can assert it equals the stored one;
     * the caller persists TEXT columns only). Null if not a supported quality archetype or
     * the row/profile is missing. Pure: no DB write.
     */
    public static function recomposeDescriptionOnly(int $plannedWorkoutId, ?PDO $db = null): ?array
    {
        $db   = $db ?? Database::get();
        $stmt = $db->prepare('SELECT athlete_id, archetype_code, archetype_variant, archetype_params FROM planned_workouts WHERE id = ? LIMIT 1');
        $stmt->execute([$plannedWorkoutId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $code = (string)($row['archetype_code'] ?? '');
        // Scoped to archetypes whose descriptions are fully token-driven, so a re-render reproduces
        // the specifics faithfully (the 5 uniform-rep + mixed gain the lead line + refreshed pace;
        // continuous_progression_tempo renders {{progression_instruction}} + warm/cool, and STANDARD
        // structured_fartlek_ladder renders {{round_count}}/{{fartlek_ladder_sequence}}, all from
        // stored params). fast_finish_long stays EXCLUDED (its copy is built via conditional code
        // paths that do not re-trigger on re-render).
        $recomposeCodes = array_merge(self::STRUCTURED_EDIT_CODES, ['continuous_progression_tempo', 'structured_fartlek_ladder']);
        if (!in_array($code, $recomposeCodes, true)) return null;
        // The diminishing_descending fartlek variant's description is built by an addDerivedParams
        // code override that only fires on fresh generation (empty work_intervals_seconds); a
        // re-render would drop its bespoke "Each stack counts down..." copy, so refuse it. Standard
        // variants render their ladder from the stored tokens and re-render faithfully.
        if ($code === 'structured_fartlek_ladder'
            && (string)($row['archetype_variant'] ?? '') === 'diminishing_descending') {
            return null;
        }

        $athleteId = (int)$row['athlete_id'];
        $profile   = self::loadProfile($athleteId, $db);
        if (!$profile) return null;

        self::$paceZones = (!empty($profile['pace_zones_visible'])
            && PaceZones::isPopulated($profile['pace_zones'] ?? null))
            ? (json_decode($profile['pace_zones'] ?? 'null', true) ?: null)
            : null;
        $rawDistance    = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');
        $classification = self::classifyAthlete($profile, $rawDistance);
        self::$planDistance  = $rawDistance;
        self::$cycleProgress = null;
        $goalDistance = self::selectorDistance($rawDistance);
        $phase        = 'base';

        $selector  = new ArchetypeSelector($db);
        $archetype = $selector->getByCode($code);
        if (!$archetype) { self::$paceZones = null; self::$planDistance = null; return null; }
        $archetype = $selector->resolveParameters($archetype, $classification);

        // Stored params verbatim (only backfill warmup/cooldown if a very old row lacks them).
        $params = json_decode((string)($row['archetype_params'] ?? '{}'), true) ?: [];
        foreach (['warmup_minutes', 'cooldown_minutes'] as $k) {
            if (!isset($params[$k]) && isset($archetype['resolved_params'][$k])) {
                $params[$k] = $archetype['resolved_params'][$k];
            }
        }
        $variant = self::variantByCode($archetype, (string)($row['archetype_variant'] ?? ''));
        if ($variant !== null) $archetype['resolved_variant'] = $variant;
        $archetype['resolved_params'] = $params;

        // Manual mode: no re-roll. Run only the display/render tail (same as generation).
        $instance = self::addDerivedParams($archetype, 60, $phase, $goalDistance, $classification, true);

        $display      = $instance['display'] ?? [];
        $title        = self::renderTemplate($display['title_template'] ?? '', $instance);
        $summary      = self::renderTemplate($display['summary_template'] ?? '', $instance);
        $instructions = self::renderTemplate($display['description_template'] ?? '', $instance);
        $instructions = self::normalizeInstructionText($instructions, $instance);
        $instructions = self::wrapWithWarmupCooldown($instructions, $instance['resolved_params'] ?? [], $instance['code'] ?? '');
        $instructions = self::appendPaceCitation($instructions, $instance);
        $instructions = self::prependPrescriptionLead($instructions, $instance);
        $structure    = self::resolveStructure($instance);

        self::$paceZones = null;
        self::$planDistance = null;

        return [
            'archetype_code'       => $code,
            'display_title'        => $title ?: null,
            'display_summary'      => $summary ?: null,
            'athlete_instructions' => $instructions ?: null,
            'structure'            => $structure ? json_encode($structure) : null,
        ];
    }

    /**
     * Structured workout editor (Phase 1): rebuild a workout's structure from a coach's
     * EXPLICIT field edits, then re-render title/instructions/summary from that structure via
     * the same render path generation uses, so app, watch, and description agree. Unlike the
     * archetype-swap edit (which re-runs the engine and re-samples), this keeps the coach's
     * values: addDerivedParams() runs in $manual mode, skipping all ranging/cap/snap so the
     * edited rep_count / durations / distances / recovery are preserved (a coach can set 7x7).
     *
     * @param array $edits keys among: rep_count, rep_duration_minutes, rep_duration_seconds,
     *                     rep_distance_meters, work_duration_seconds, recovery_duration_seconds.
     * @return array|null the planned_workouts column set to persist, or null if the workout is
     *                    not one of the 5 supported archetypes / not found.
     */
    public static function composeStructuredEdit(int $plannedWorkoutId, array $edits, ?PDO $db = null): ?array
    {
        $db   = $db ?? Database::get();
        $stmt = $db->prepare('SELECT athlete_id, archetype_code, archetype_variant, archetype_params FROM planned_workouts WHERE id = ? LIMIT 1');
        $stmt->execute([$plannedWorkoutId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $code = (string)($row['archetype_code'] ?? '');
        if (!self::isStructuredEditable($code)) return null;

        $athleteId = (int)$row['athlete_id'];
        $profile   = self::loadProfile($athleteId, $db);
        if (!$profile) return null;

        self::$paceZones = (!empty($profile['pace_zones_visible'])
            && PaceZones::isPopulated($profile['pace_zones'] ?? null))
            ? (json_decode($profile['pace_zones'] ?? 'null', true) ?: null)
            : null;
        $rawDistance    = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');
        $classification = self::classifyAthlete($profile, $rawDistance);
        self::$planDistance  = $rawDistance;
        self::$cycleProgress = null;
        $goalDistance = self::selectorDistance($rawDistance);
        $phase        = 'base';

        $selector  = new ArchetypeSelector($db);
        $archetype = $selector->getByCode($code);
        if (!$archetype) { self::$paceZones = null; self::$planDistance = null; return null; }
        $archetype = $selector->resolveParameters($archetype, $classification);

        // Start from the existing instance params (preserves warmup/cooldown/effort/recovery_model).
        $params = json_decode((string)($row['archetype_params'] ?? '{}'), true) ?: [];
        foreach (['warmup_minutes', 'cooldown_minutes'] as $k) {
            if (!isset($params[$k]) && isset($archetype['resolved_params'][$k])) {
                $params[$k] = $archetype['resolved_params'][$k];
            }
        }
        $variant = self::variantByCode($archetype, (string)($row['archetype_variant'] ?? ''));

        $intOr = static fn($v, $min, $cur) => $v === null ? $cur : max($min, (int)$v);

        if ($code === 'mixed_distance_repeats') {
            // Phase 2: an ordered ladder. The coach's interval_distances array IS the workout
            // (the order is descending / ascending / pyramid). rung_count + total derive from it.
            $dists = is_array($edits['interval_distances'] ?? null)
                ? array_values(array_filter(array_map('intval', $edits['interval_distances']), fn($m) => $m > 0))
                : array_values(array_filter(array_map('intval', (array)($params['interval_distances'] ?? [])), fn($m) => $m > 0));
            if (empty($dists)) { self::$paceZones = null; self::$planDistance = null; return null; }
            $params['interval_distances']    = $dists;
            $params['rung_count']            = count($dists);
            $params['quality_volume_meters'] = array_sum($dists);
            $params['mixed_ladder_sequence'] = implode(' - ', $dists) . ' m';
            $ov = self::detectMixedVariant($archetype, $dists); // keep the title's ordering name coherent
            if ($ov !== null) $variant = $ov;
        } else {
            $params['rep_count'] = $intOr($edits['rep_count'] ?? null, 1, (int)($params['rep_count'] ?? 1));

            if ($code === 'tempo_intervals') {
                $params['rep_duration_minutes'] = $intOr($edits['rep_duration_minutes'] ?? null, 1, (int)($params['rep_duration_minutes'] ?? 1));
                $pace = self::tempoPaceMinPerMile($classification, $goalDistance);
                $params['rep_distance_miles'] = $pace > 0 ? round((int)$params['rep_duration_minutes'] / $pace, 2) : ($params['rep_distance_miles'] ?? 0);
            } elseif ($code === 'sustained_hill_repeats') {
                $params['rep_duration_seconds'] = $intOr($edits['rep_duration_seconds'] ?? null, 1, (int)($params['rep_duration_seconds'] ?? 1));
                $params['rep_duration_display'] = self::formatSecondsLabel((int)$params['rep_duration_seconds']);
            } elseif (in_array($code, ['equal_distance_repeats', 'short_speed_repeats'], true)) {
                if (isset($edits['rep_distance_meters'])) {
                    $params['rep_distance_meters'] = max(1, (int)$edits['rep_distance_meters']);
                    unset($params['rep_duration_seconds']); // re-derive from the new distance below
                    $vm = self::variantByDistance($archetype, (int)$params['rep_distance_meters']);
                    if ($vm !== null) $variant = $vm;
                }
                if ($code === 'short_speed_repeats') $params['effort_zone'] = $params['effort_zone'] ?? 'repetition';
            } elseif ($code === 'high_volume_time_intervals') {
                $params['work_duration_seconds'] = $intOr($edits['work_duration_seconds'] ?? null, 1, (int)($params['work_duration_seconds'] ?? 1));
            }
            if (isset($edits['recovery_duration_seconds'])) {
                $params['recovery_duration_seconds'] = max(1, (int)$edits['recovery_duration_seconds']);
            }
        }

        if ($variant !== null) $archetype['resolved_variant'] = $variant;
        $archetype['resolved_params'] = $params;

        // Manual mode: keep the explicit values, run only the display/structure tail.
        $instance = self::addDerivedParams($archetype, 60, $phase, $goalDistance, $classification, true);

        $display      = $instance['display'] ?? [];
        $title        = self::renderTemplate($display['title_template'] ?? '', $instance);
        $summary      = self::renderTemplate($display['summary_template'] ?? '', $instance);
        $instructions = self::renderTemplate($display['description_template'] ?? '', $instance);
        $instructions = self::normalizeInstructionText($instructions, $instance);
        $instructions = self::wrapWithWarmupCooldown($instructions, $instance['resolved_params'] ?? [], $instance['code'] ?? '');
        $instructions = self::appendPaceCitation($instructions, $instance);
        $instructions = self::prependPrescriptionLead($instructions, $instance);

        $sig             = self::computeInstanceSignature($instance);
        $resolvedVariant = $instance['resolved_variant']['code'] ?? null;
        $finalParams     = $instance['resolved_params'] ?? [];
        $structure       = self::resolveStructure($instance);
        $variantWorkout  = $instance['resolved_variant']['workout_type'] ?? null;
        $metadataWorkout = $instance['metadata']['workout_type'] ?? $instance['workout_type'] ?? null;
        $workoutType     = $variantWorkout ?? $metadataWorkout ?? 'easy';
        $variantIF       = $instance['resolved_variant']['intensity_factor'] ?? null;
        $intensityFactor = (float)($variantIF ?? $instance['generation']['intensity_factor'] ?? 0.5);
        $storedDuration  = self::computeActualDuration($instance) ?? (int)($finalParams['duration_minutes'] ?? 1);
        $load            = round($storedDuration * $intensityFactor, 2);

        self::$paceZones = null;
        self::$planDistance = null;

        return [
            'workout_type'               => $workoutType,
            'archetype_code'             => $instance['code'],
            'archetype_variant'          => $resolvedVariant,
            'archetype_params'           => json_encode($finalParams),
            'instance_signature'         => $sig ?: null,
            'structure'                  => $structure ? json_encode($structure) : null,
            'display_title'              => $title ?: null,
            'display_summary'            => $summary ?: null,
            'athlete_instructions'       => $instructions ?: null,
            'target_duration'            => $storedDuration,
            'intensity_load'             => $load,
        ];
    }

    /** Find an archetype variant by its code, or null. */
    private static function variantByCode(array $archetype, string $code): ?array
    {
        foreach (($archetype['variants'] ?? []) as $v) {
            if (($v['code'] ?? null) === $code) return $v;
        }
        return null;
    }

    /** Find an archetype variant whose rep_distance_meters equals $meters, or null. */
    private static function variantByDistance(array $archetype, int $meters): ?array
    {
        foreach (($archetype['variants'] ?? []) as $v) {
            if ((int)($v['rep_distance_meters'] ?? 0) === $meters) return $v;
        }
        return null;
    }

    /**
     * Match a mixed_distance ladder's ORDER to its variant so the title's ordering name stays
     * truthful after a reorder: strictly descending -> long_to_short, strictly ascending ->
     * short_to_long, single-peak pyramid -> combo_set. An irregular order returns null (keep
     * the existing variant; the ladder itself is the source of truth in structure/description).
     */
    private static function detectMixedVariant(array $archetype, array $dists): ?array
    {
        $n = count($dists);
        if ($n < 2) return null;
        $inc = true; $dec = true;
        for ($i = 1; $i < $n; $i++) {
            if ($dists[$i] <= $dists[$i - 1]) $inc = false;
            if ($dists[$i] >= $dists[$i - 1]) $dec = false;
        }
        $isPyramid = false;
        if (!$inc && !$dec) {
            $peak = (int)array_search(max($dists), $dists, true);
            if ($peak > 0 && $peak < $n - 1) {
                $isPyramid = true;
                for ($i = 1; $i <= $peak; $i++)       if ($dists[$i] <= $dists[$i - 1]) { $isPyramid = false; break; }
                if ($isPyramid) for ($i = $peak + 1; $i < $n; $i++) if ($dists[$i] >= $dists[$i - 1]) { $isPyramid = false; break; }
            }
        }
        $want = $dec ? 'long_to_short' : ($inc ? 'short_to_long' : ($isPyramid ? 'combo_set' : null));
        return $want !== null ? self::variantByCode($archetype, $want) : null;
    }

    /**
     * Read-only archetype preview for the coach Library browser. Runs the same
     * resolve → render pipeline as composeManualWorkout() but against a throwaway
     * context (explicit classification + selector goal distance) instead of loading
     * a real athlete profile. Effort-only: pace citations are suppressed because
     * there is no athlete pace-zone profile to cite. No DB writes.
     *
     * @param string      $goalDistance Selector distance: 5K|10K|half|marathon.
     * @return array|null { workout_type, display_title, display_summary,
     *                      athlete_instructions, generated_parameters, structure,
     *                      target_duration, variant } or null if code unknown/inactive.
     */
    public static function previewArchetype(
        string $archetypeCode, string $classification, int $durationMinutes,
        string $goalDistance, ?string $variantCode = null, ?PDO $db = null
    ): ?array {
        $db = $db ?? Database::get();

        $classification = in_array($classification, ['workable', 'well_trained'], true)
            ? $classification : 'workable';
        $goalDistance = in_array($goalDistance, ['5K', '10K', 'half', 'marathon'], true)
            ? $goalDistance : '5K';

        self::$paceZones   = null;          // effort-only — no athlete zones to cite
        self::$planDistance = $goalDistance;
        self::$cycleProgress = null;   // ad-hoc preview: no cycle context -> Stage A tempo ranging

        $selector  = new ArchetypeSelector($db);
        $archetype = $selector->getByCode($archetypeCode);
        if (!$archetype || ($archetype['status'] ?? 'active') !== 'active') {
            self::$planDistance = null;
            return null;
        }

        $phase     = 'base';
        $archetype = $selector->resolveParameters($archetype, $classification);

        // Variant: explicit code if valid, else the first defined variant ("Auto").
        $variants = $archetype['variants'] ?? [];
        $chosen   = null;
        if ($variantCode !== null && $variantCode !== '' && $variantCode !== 'auto') {
            foreach ($variants as $v) {
                if (($v['code'] ?? null) === $variantCode) { $chosen = $v; break; }
            }
        }
        if ($chosen === null && !empty($variants)) {
            $chosen = $variants[0];
        }
        if ($chosen !== null) {
            $archetype['resolved_variant'] = $chosen;
        }

        $minDur = $selector->getMinimumSessionDurationMinutes($archetype, $classification, $phase, $goalDistance);
        $target = max(1, $durationMinutes);
        if ($minDur !== null) {
            $target = max((int)ceil($minDur), $target);
        }

        $instance = self::addDerivedParams($archetype, $target, $phase, $goalDistance, $classification);

        $display      = $instance['display'] ?? [];
        $title        = self::renderTemplate($display['title_template'] ?? '', $instance);
        $summary      = self::renderTemplate($display['summary_template'] ?? '', $instance);
        $instructions = self::renderTemplate($display['description_template'] ?? '', $instance);
        $instructions = self::normalizeInstructionText($instructions, $instance);
        $instructions = self::wrapWithWarmupCooldown($instructions, $instance['resolved_params'] ?? [], $instance['code'] ?? '');
        $instructions = self::appendPaceCitation($instructions, $instance);
        $instructions = self::prependPrescriptionLead($instructions, $instance);

        $params          = $instance['resolved_params'] ?? [];
        $structure       = self::resolveStructure($instance);
        $variantWorkout  = $instance['resolved_variant']['workout_type'] ?? null;
        $metadataWorkout = $instance['metadata']['workout_type'] ?? $instance['workout_type'] ?? null;
        $workoutType     = $variantWorkout ?? $metadataWorkout ?? 'easy';
        $storedDuration  = self::computeActualDuration($instance) ?? (int)($params['duration_minutes'] ?? $target);

        self::$paceZones   = null;
        self::$planDistance = null;

        return [
            'workout_type'         => $workoutType,
            'display_title'        => $title ?: null,
            'display_summary'      => $summary ?: null,
            'athlete_instructions' => $instructions ?: null,
            'generated_parameters' => $params,
            'structure'            => $structure ?: null,
            'target_duration'      => $storedDuration,
            'variant'              => $instance['resolved_variant']['code'] ?? null,
        ];
    }

    /**
     * Archetype catalogue for the coach's manual-add picker, scoped to one athlete so
     * default durations reflect their classification. Returns active archetypes with
     * a UI category (easy / long / quality / recovery), short description, default
     * duration, and selectable variants.
     */
    public static function manualArchetypeLibrary(int $athleteId, ?PDO $db = null): array
    {
        $db      = $db ?? Database::get();
        $profile = self::loadProfile($athleteId, $db) ?? [];
        $rawDistance    = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');
        $classification = self::classifyAthlete($profile, $rawDistance);
        $goalDistance   = self::selectorDistance($rawDistance);

        $selector = new ArchetypeSelector($db);
        $rows = $db->query('SELECT code FROM workout_archetypes WHERE status = "active" ORDER BY workout_type, name')
                   ->fetchAll(PDO::FETCH_COLUMN);

        $catFor = static function (string $wt): string {
            return match ($wt) {
                'easy'     => 'easy',
                'long'     => 'long',
                'recovery' => 'recovery',
                default    => 'quality',
            };
        };

        $out = [];
        foreach ($rows as $code) {
            $arch = $selector->getByCode((string)$code);
            if (!$arch) continue;
            $wt  = (string)($arch['workout_type'] ?? 'easy');
            $min = $selector->getMinimumSessionDurationMinutes($arch, $classification, 'base', $goalDistance);
            $default = match ($wt) {
                'easy'     => 45,
                'long'     => 75,
                'recovery' => 30,
                default    => $min !== null ? (int)max(40, ceil($min)) : 50,
            };
            if ($min !== null) $default = max($default, (int)ceil($min));
            $default = (int)(round($default / 5) * 5);

            $variants = [];
            foreach (($arch['variants'] ?? []) as $v) {
                if (!isset($v['code'])) continue;
                $variants[] = ['code' => (string)$v['code'], 'name' => (string)($v['name'] ?? $v['code'])];
            }

            $out[] = [
                'code'             => (string)$arch['code'],
                'name'             => (string)($arch['name'] ?? $arch['code']),
                'workout_type'     => $wt,
                'category'         => $catFor($wt),
                'description'      => (string)($arch['description'] ?? ''),
                'default_duration' => $default,
                'variants'         => $variants,
            ];
        }
        return $out;
    }

    // ── Plan type generators ─────────────────────────────────────────────────

    private static function generateRaceCycle(
        int $athleteId, array $profile, string $trigger, PDO $db,
        ArchetypeSelector $selector, array &$antiRepeatHistory
    ): ?int {
        $raceDate = $profile['goal_race_date'] ?? null;
        $distance = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');

        // A race cycle is structurally meaningless without a goal race date (phase
        // lengths, taper, and total weeks are all derived from it). Refuse to generate
        // and surface a critical flag so the coach fixes the profile, rather than
        // silently producing a broken or mis-typed plan.
        if (!$raceDate) {
            // No goal race date → a race cycle is structurally impossible (phases, taper, and
            // total weeks are all derived from it). Reuse the valid 'plan_rebuild_needed'
            // flag_type (a coach "fix the profile and regenerate" signal) rather than a
            // dedicated 'missing_goal_race_date' type: the latter is NOT an engine_flags ENUM
            // member, so on MyISAM it is silently coerced to '' and the critical flag vanishes
            // (same class of bug as the unmatched_activity flag, migration 032). The message
            // stays specific so the coach sees exactly why generation refused; details.reason
            // tags the cause for analytics/backfill even though flag_type is the reused value.
            self::raiseFlag($athleteId, 'plan_rebuild_needed', 'critical',
                "Cannot generate a race cycle plan without a goal race date. Please add the athlete's "
                . "goal race date to their profile, then regenerate the plan.",
                $db, ['reason' => 'missing_goal_race_date']);
            return null;
        }

        // Ultra context (ultra spec). $selDist is what the archetype/pace layer sees
        // (marathon for ultras); $distance keeps the real ultra key for engine sizing.
        $isUltra = self::isUltra($distance);
        $selDist = self::selectorDistance($distance);
        $surface = $isUltra ? ($profile['ultra_surface'] ?? 'road') : null;
        $ultra   = $isUltra ? ['distance' => $distance, 'surface' => $surface] : null;

        // 100K / 100 miler are effort-only regardless of pace_zones_visible (Part 11).
        if (in_array($distance, ['100k', '100_miler'], true)) {
            self::$paceZones = null;
        }

        $startDate  = self::planStartDate($athleteId, $db); // "tomorrow" in the athlete's timezone
        $totalWeeks = (int)ceil((strtotime($raceDate) - strtotime($startDate)) / (7 * 86400));
        [$minWeeks, $maxWeeks] = self::cycleWeekBounds($distance);

        if ($totalWeeks < $minWeeks) {
            self::raiseFlag($athleteId, 'plan_rebuild_needed', 'warning',
                "Goal race is {$totalWeeks} weeks away — minimum for {$distance} is {$minWeeks} weeks.", $db);
        }

        $totalWeeks     = max($minWeeks, min($maxWeeks, $totalWeeks));
        $endDate        = min(date('Y-m-d', strtotime($startDate . " +{$totalWeeks} weeks -1 day")), $raceDate);
        $classification = self::classifyAthlete($profile, $distance);
        $phases         = self::calculatePhases($totalWeeks, self::phaseProportionsFor($distance, $classification));

        // 100 miler tapers in 2 weeks only; freed weeks extend peak (Part 5).
        if ($distance === '100_miler') {
            $phases = self::capTaperWeeks($phases, 2);
        }

        if ($classification === 'insufficient') {
            self::raiseFlag($athleteId, 'insufficient_base', 'critical',
                'Athlete base is insufficient for the selected distance. Coach decision required.', $db);
        }

        // Volume base resolved BEFORE the plan record is created so the cross-cycle
        // continuity query (Item 4) sees the prior plan, not this new empty one.
        $currentMins    = max(60, (int)($profile['current_weekly_minutes'] ?? 120));
        $peakCeiling    = max($currentMins, (int)($profile['peak_volume_ceiling_mins'] ?? (int)round($currentMins * 1.4)));
        // Ultra peak volume is capped by the distance ceiling (workable = 75%); never below
        // the athlete's current volume so the plan can still start where they are (Part 7).
        $ultraCeiling   = $isUltra ? self::ultraVolumeCeiling($distance, $classification) : null;
        if ($ultraCeiling !== null) {
            $peakCeiling = max($currentMins, min($peakCeiling, $ultraCeiling));
        }
        // Mile volume is lower than marathon but higher intensity; cap by the mile ceiling
        // while still letting the plan start from the athlete's current volume (Part 7).
        if ($distance === 'mile') {
            $peakCeiling = max($currentMins, min($peakCeiling, self::mileVolumeCeiling($classification)));
        }
        $longestRun     = max(30, (int)($profile['longest_recent_run_mins'] ?? 60));
        $buildBase      = self::resolveStartingWeeklyMins($athleteId, $profile, $trigger, $currentMins, $peakCeiling, $db);
        $volumeFloor    = $buildBase; // cycle-start volume; the build-trend floor for Stage B tempo progression

        $planId         = self::createPlanRecord($athleteId, 'race_cycle', $startDate, $raceDate, $raceDate, $trigger, $db);
        $maxLongRun     = $longestRun;
        $constraints    = self::buildConstraints($profile);

        // FIX 7: long-run weekly-progression state (ultra + marathon race cycles). The long
        // run ramps ~12% per non-cutback week from the athlete's current longest toward the
        // per-phase ceiling, reaching it only gradually by phase end (the cap is a ceiling,
        // not a per-week target); cutback weeks drop to ~65% of the prior week. 5K/10K/half
        // keep the legacy weekly-volume long-run sizing inside insertWeekWorkouts.
        $lrProgress      = $isUltra || $distance === 'marathon';
        $longFloorMins   = (int)(self::settings()['long_run_absolute_floor_minutes'] ?? 60);
        $lrPrev          = $longestRun;   // previous week's long run
        $lrPhaseStart    = $longestRun;   // long run entering the current phase
        $lrCurPhase      = null;
        $lrPrevPhaseCap  = null;          // ceiling of the phase just completed

        // Trail ultras: a one-time info reminder for the coach (Part 12 / Part 15).
        if ($isUltra && $surface === 'trail') {
            self::raiseFlag(
                $athleteId, 'ultra_surface_reminder', 'info',
                'This athlete is training for a trail ultra. Consider scheduling one night run in peak '
                . 'phase to simulate race conditions. Coordinate timing and safety with the athlete directly.',
                $db, ['plan_id' => $planId, 'distance' => $distance], false
            );
        }

        // Hyrox athletes: a one-time functional-fitness supplement reminder (mile spec Part 13).
        if ($distance === 'mile' && !empty($profile['is_hyrox'])) {
            self::raiseFlag(
                $athleteId, 'hyrox_supplement_reminder', 'info',
                'Hyrox athlete, running plan only. The running plan develops the speed and threshold '
                . 'fitness needed for the 8 x 1km running segments. Athlete has been advised to supplement '
                . 'with functional fitness training (rowing, sleds, burpees, sandbags, wall balls, lunges) '
                . 'at a CrossFit box or functional fitness gym.',
                $db, ['plan_id' => $planId], false
            );
        }

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $phase         = self::getPhaseForWeek($week, $phases, $totalWeeks);
            $weekInPhase   = $week - ($phases[$phase]['start_week'] ?? 1) + 1;
            $isCutback     = self::isCutbackWeek($distance, $week, $phase);
            $isRaceWeek    = ($week === $totalWeeks);
            $isPreRaceWeek = ($week === $totalWeeks - 1 && $totalWeeks > 2);

            // Per-week back-to-back flag + week number (ultra only); threaded to schedule,
            // insertion, and the phase/week-aware quality exclusions (FIX 3).
            if ($ultra !== null) {
                $ultra['back_to_back'] =
                    self::ultraBackToBackWeek($distance, $week, $phase, $isCutback, $phases);
                $ultra['week'] = $week;
            }

            // FIX 7: per-week long-run duration for ultra / marathon race cycles. Each phase
            // ramps its long run gradually up to that phase's ceiling, reaching it only at the
            // phase's end. The base phase opens from a reduced long run (so it has room to ramp
            // even when the athlete's current longest already sits at the base cap, which was
            // the "flat 4h base" bug); later phases continue from the previous phase's ceiling.
            // Cutback weeks drop to ~65% of the prior week.
            $longRunOverride = null;
            if ($lrProgress && in_array($phase, ['base', 'build', 'peak'], true)) {
                $phaseInfo = $phases[$phase] ?? null;
                $phaseLen  = $phaseInfo ? max(1, $phaseInfo['end_week'] - $phaseInfo['start_week'] + 1) : 1;
                $phaseCap  = self::raceLongRunPhaseCap($distance, $phase, $peakCeiling) ?? 210;
                if ($phase !== $lrCurPhase) {
                    $lrPhaseStart = $lrPrevPhaseCap !== null
                        ? $lrPrevPhaseCap                                                    // continue from prior phase ceiling
                        : max($longFloorMins, (int)round(min($longestRun, $phaseCap) * 0.70)); // reduced base opener
                    $lrCurPhase     = $phase;
                    $lrPrevPhaseCap = $phaseCap;
                }
                if ($isCutback) {
                    $longRunOverride = max($longFloorMins, (int)round($lrPrev * 0.65));
                } else {
                    $frac   = min(1.0, $weekInPhase / $phaseLen);   // progress through the phase
                    $target = (int)round($lrPhaseStart + ($phaseCap - $lrPhaseStart) * $frac);
                    $longRunOverride = max($longFloorMins, min($phaseCap, $target));
                }
                $lrPrev = $longRunOverride;
            }

            // Taper and race week derive from the ceiling (not the build base); cutback
            // reduces from the build base without advancing it; build weeks advance the
            // base by × 1.10 (Item 3 — post-cutback resumes from the pre-cutback peak).
            if ($phase === 'taper') {
                $taperMult  = match(true) {
                    $weekInPhase === 1 => 0.75,
                    $weekInPhase === 2 => 0.60,
                    default            => 0.45,
                };
                $weeklyMins = max(30, (int)round($peakCeiling * $taperMult));
            } elseif ($isRaceWeek) {
                $weeklyMins = max(30, (int)round($peakCeiling * 0.40));
            } elseif ($isCutback) {
                $weeklyMins = max(30, (int)round($buildBase * 0.75));
            } else {
                $weeklyMins = min((int)round($buildBase * 1.10), $peakCeiling);
                $buildBase  = $weeklyMins;
            }

            if ($week === 1) self::maybeRaiseScheduleRampFlag($athleteId, $weeklyMins, $profile, $db);

            // Stage B: tempo target tracks the volume build-trend ($buildBase is the undipped
            // trend; $weeklyMins is this week's actual, dipped on cutback). Read after the
            // weeklyMins progression above so the target scales off the progressed volume.
            self::$cycleProgress = self::cycleProgressContext(
                $isCutback, $buildBase, $weeklyMins, $volumeFloor, $peakCeiling
            );

            $weekStart  = date('Y-m-d', strtotime($startDate . ' +' . (($week - 1) * 7) . ' days'));
            $schedule   = self::buildDaySchedule($profile, $phase, $weeklyMins, $isRaceWeek, $isPreRaceWeek, $athleteId, $db, 'race_cycle', $week, $isCutback, $ultra);
            $maxLongRun = self::insertWeekWorkouts(
                $planId, $athleteId, $weekStart, $endDate,
                $schedule, $phase, $selDist, $classification, 'race_cycle',
                $weeklyMins, $maxLongRun, $constraints, $db, $selector, $antiRepeatHistory,
                null, null, $ultra, $longRunOverride
            );
        }

        self::$cycleProgress = null; // clear so post-generation steps see no cycle context
        return $planId;
    }

    /**
     * Shrink a phase plan's taper to at most $maxTaper weeks, extending peak to fill the
     * freed weeks (the race week stays the final taper week). Used for the 100-miler's
     * 2-week taper (ultra spec Part 5). No-op when the taper is already short enough.
     */
    private static function capTaperWeeks(array $phases, int $maxTaper): array
    {
        if (!isset($phases['taper'])) return $phases;
        $taperLen = $phases['taper']['end_week'] - $phases['taper']['start_week'] + 1;
        if ($taperLen <= $maxTaper) return $phases;

        $shrink = $taperLen - $maxTaper;
        $phases['taper']['start_week'] += $shrink;
        if (isset($phases['peak'])) {
            $phases['peak']['end_week'] = $phases['taper']['start_week'] - 1;
        }
        return $phases;
    }

    private static function generateDevelopmentPlan(
        int $athleteId, array $profile, string $trigger, PDO $db,
        ArchetypeSelector $selector, array &$antiRepeatHistory
    ): ?int {
        $startDate     = self::planStartDate($athleteId, $db); // "tomorrow" in the athlete's timezone
        $totalWeeks    = 12;
        $codeWeekStart = self::firstMondayOnOrAfter($startDate);
        $endDate       = self::codeWeekEndDate($codeWeekStart, $totalWeeks);

        // Volume base resolved BEFORE the plan record is created so the cross-cycle
        // continuity query (Item 4) sees the prior plan, not this new empty one.
        $currentMins    = max(60, (int)($profile['current_weekly_minutes'] ?? 120));
        $peakCeiling    = max($currentMins, (int)($profile['peak_volume_ceiling_mins'] ?? (int)round($currentMins * 1.4)));
        $buildBase      = self::resolveStartingWeeklyMins($athleteId, $profile, $trigger, $currentMins, $peakCeiling, $db);
        $volumeFloor    = $buildBase; // cycle-start volume; the build-trend floor for Stage B tempo progression

        $planId         = self::createPlanRecord($athleteId, 'development_plan', $startDate, $endDate, null, $trigger, $db);
        $maxLongRun     = max(30, (int)($profile['longest_recent_run_mins'] ?? 60));
        $constraints    = self::buildConstraints($profile);
        $rawDist        = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');
        $classification = self::classifyAthlete($profile, $rawDist);
        // Archetype/pace layer keys on the selector distance (mile→5K, ultra→marathon);
        // classification + self::$planDistance keep the real distance.
        $goalDist       = self::selectorDistance($rawDist);

        // Snapshot the anti-repeat history before the loop: the lead-in (which precedes week 1)
        // resolves against this prior-plan-only context so it isn't blocked by week 1's own picks.
        $leadInHistory = $antiRepeatHistory;

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $isCutback = ($week > 1 && $week % 4 === 0);
            // Item 3: cutback reduces from the build base but does NOT advance it, so the
            // next build week resumes from the pre-cutback peak (× 1.08), not the dip.
            if ($isCutback) {
                $weeklyMins = max(30, (int)round($buildBase * 0.80));
            } else {
                $weeklyMins = min((int)round($buildBase * 1.08), $peakCeiling);
                $buildBase  = $weeklyMins;
            }

            if ($week === 1) self::maybeRaiseScheduleRampFlag($athleteId, $weeklyMins, $profile, $db);

            // Stage B: tempo target tracks the volume build-trend (see race cycle). Set after
            // the weeklyMins progression so it scales off the progressed volume.
            self::$cycleProgress = self::cycleProgressContext(
                $isCutback, $buildBase, $weeklyMins, $volumeFloor, $peakCeiling
            );

            $weekStart  = date('Y-m-d', strtotime($codeWeekStart . ' +' . (($week - 1) * 7) . ' days'));
            $schedule   = self::buildDaySchedule($profile, 'base', $weeklyMins, false, false, $athleteId, $db, 'development_plan', $week, $isCutback);
            // Capture code-week-1's pattern/volume so the lead-in can mirror it (below).
            // $maxLongRun here is the pre-week-1 value, reproducing week 1's long-run scale.
            if ($week === 1) {
                $leadInSchedule   = $schedule;
                $leadInMins       = $weeklyMins;
                $leadInMaxLongRun = $maxLongRun;
                $leadInProgress   = self::$cycleProgress; // lead-in mirrors week 1
            }
            $maxLongRun = self::insertWeekWorkouts(
                $planId, $athleteId, $weekStart, $endDate,
                $schedule, 'base', $goalDist, $classification, 'development_plan',
                $weeklyMins, $maxLongRun, $constraints, $db, $selector, $antiRepeatHistory
            );
        }

        // Lead-in (days between plan_start_date and the first Monday) mirrors code-week 1's
        // day-type pattern. Uncounted toward the 12-week progression; generated last so the
        // code-week trajectory is unaffected. No-op when plan_start_date is a Monday.
        self::$cycleProgress = $leadInProgress ?? null; // lead-in mirrors week 1's progression
        self::insertLeadInWorkouts(
            $planId, $athleteId, $startDate, $codeWeekStart, $endDate,
            $leadInSchedule ?? [], $leadInMins ?? 0, 'base',
            $goalDist, $classification, 'development_plan',
            $leadInMaxLongRun ?? $maxLongRun, $constraints, $profile,
            $db, $selector, $leadInHistory
        );

        self::$cycleProgress = null; // clear so post-generation steps see no cycle context
        return $planId;
    }

    private static function generateMaintenancePlan(
        int $athleteId, array $profile, string $trigger, PDO $db,
        ArchetypeSelector $selector, array &$antiRepeatHistory
    ): ?int {
        $startDate     = self::planStartDate($athleteId, $db); // "tomorrow" in the athlete's timezone
        $totalWeeks    = 12;
        $codeWeekStart = self::firstMondayOnOrAfter($startDate);
        $endDate       = self::codeWeekEndDate($codeWeekStart, $totalWeeks);

        $planId      = self::createPlanRecord($athleteId, 'maintenance_plan', $startDate, $endDate, null, $trigger, $db);
        $peakCeiling = max(120, (int)($profile['peak_volume_ceiling_mins'] ?? 240));
        $weeklyMins  = (int)round($peakCeiling * 0.85);
        $maxLongRun  = max(40, (int)($profile['longest_recent_run_mins'] ?? 60));
        $constraints = self::buildConstraints($profile);
        $rawDist     = self::normalizeDistance($profile['goal_race_distance'] ?? 'marathon');
        $classification = self::classifyAthlete($profile, $rawDist);
        // Archetype/pace layer keys on the selector distance (mile→5K, ultra→marathon).
        $goalDist    = self::selectorDistance($rawDist);

        // Maintenance volume is ceiling-anchored (85% of ceiling, constant) rather than
        // ramped from current_weekly_minutes, so it is already cross-cycle continuous;
        // the day-ramp flag still applies if that volume can't support the requested days.
        self::maybeRaiseScheduleRampFlag($athleteId, $weeklyMins, $profile, $db);

        // Snapshot for the lead-in (precedes week 1); see generateDevelopmentPlan.
        $leadInHistory = $antiRepeatHistory;

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $weekStart  = date('Y-m-d', strtotime($codeWeekStart . ' +' . (($week - 1) * 7) . ' days'));
            $schedule   = self::buildDaySchedule($profile, 'build', $weeklyMins, false, false, $athleteId, $db, 'maintenance_plan', $week, false);
            if ($week === 1) {
                $leadInSchedule   = $schedule;
                $leadInMaxLongRun = $maxLongRun;
            }
            $maxLongRun = self::insertWeekWorkouts(
                $planId, $athleteId, $weekStart, $endDate,
                $schedule, 'build', $goalDist, $classification, 'maintenance_plan',
                $weeklyMins, $maxLongRun, $constraints, $db, $selector, $antiRepeatHistory
            );
        }

        // Lead-in mirrors code-week 1's pattern (uncounted). No-op on Monday starts.
        self::insertLeadInWorkouts(
            $planId, $athleteId, $startDate, $codeWeekStart, $endDate,
            $leadInSchedule ?? [], $weeklyMins, 'build',
            $goalDist, $classification, 'maintenance_plan',
            $leadInMaxLongRun ?? $maxLongRun, $constraints, $profile,
            $db, $selector, $leadInHistory
        );

        return $planId;
    }

    /**
     * Return-to-running: pre-generate the FULL expected progression upfront so the coach
     * sees every planned session in the macro view. All RTR_MAX_STAGE (10) run/walk
     * sessions are created at their expected stage (session N at stage N, 1..10, with
     * stage 10 the first continuous run) on an every-other-day cadence (≥2-day gap, never
     * on must-off days, ~20 days total), with rest / cross-training filling every day in
     * between. plan_end_date spans the whole progression.
     *
     * Visibility mirrors a normal plan: the initial rolling window (first RTR_WINDOW_DAYS
     * days) is opened (visible_to_athlete=1); sessions beyond it stay visible_to_athlete=0
     * — the coach sees them, the athlete does not yet. rtr_current_stage is set to 1.
     *
     * The adaptive progression (onRunWalkCompletion) re-stages the NEXT pending session in
     * place as the athlete advances, rather than inserting sessions one at a time.
     */
    private static function generateReturnToRunning(
        int $athleteId, array $profile, string $trigger, PDO $db
    ): ?int {
        $selector = new ArchetypeSelector($db);

        $stage          = 1;
        $sessionsTarget = self::RTR_MAX_STAGE;                 // 10 sessions = stages 1..10
        $startDate      = self::planStartDate($athleteId, $db); // "tomorrow" in athlete tz

        // Create the plan first (inserts need the id); plan_end_date is finalized below
        // once the last session's date is known.
        $planId = self::createPlanRecord($athleteId, 'return_to_running', $startDate, $startDate, null, $trigger, $db);
        $db->prepare('UPDATE training_plans SET rtr_current_stage = ? WHERE id = ?')->execute([$stage, $planId]);

        $mustOff = json_decode($profile['must_off_days'] ?? '[]', true) ?: [];
        $insert  = self::rtrInsertStatement($db);

        $placed        = 0;
        $lastRunOffset = -2;   // allow day 0 to be a run day (every-other-day spacing)
        $offset        = 0;
        $lastDate      = $startDate;
        $maxOffset     = 60;   // safety bound

        while ($placed < $sessionsTarget && $offset <= $maxOffset) {
            $date = date('Y-m-d', strtotime($startDate . " +{$offset} days"));
            $dow  = (int)date('w', strtotime($date));

            $isRunDay = !in_array($dow, $mustOff, true) && ($offset - $lastRunOffset) >= 2;

            if ($isRunDay) {
                // Pre-generate session N at stage N (1..10) so the coach sees the full
                // progression. rtr_current_stage stays 1 (the athlete starts at stage 1);
                // onRunWalkCompletion re-stages the next session as the athlete advances.
                $sessionStage = $placed + 1;
                $instance = self::resolveRunWalkStage($selector, $sessionStage, 0, 'base', '5K', 'insufficient');
                if ($instance !== null) {
                    self::insertResolvedWorkout($insert, $planId, $athleteId, $date, 'easy', $instance);
                    $placed++;
                    $lastRunOffset = $offset;
                    $lastDate      = $date;
                    $offset++;
                    continue;
                }
            }

            // Non-run day: cross-train (matched to equipment) or a generic rest day.
            self::insertReturnToRunningOffDay($insert, $planId, $athleteId, $date, $profile);
            $lastDate = $date;
            $offset++;
        }

        // plan_end_date spans the full pre-generated progression (the last session's date).
        $db->prepare('UPDATE training_plans SET plan_end_date = ? WHERE id = ?')->execute([$lastDate, $planId]);

        // Open the initial rolling window (first RTR_WINDOW_DAYS days); everything beyond
        // it stays visible_to_athlete=0 until the rolling-window cron / approval reaches it.
        $windowEnd = date('Y-m-d', strtotime($startDate . ' +' . (self::RTR_WINDOW_DAYS - 1) . ' days'));
        $db->prepare(
            'UPDATE planned_workouts SET visible_to_athlete = 1
             WHERE plan_id = ? AND scheduled_date BETWEEN ? AND ?'
        )->execute([$planId, $startDate, $windowEnd]);

        return $planId;
    }

    /**
     * Prepared INSERT matching insertWeekWorkouts' column order, with
     * visible_to_athlete defaulted to 0 (the rolling-window cron / approval opens
     * the window). Shared by every return_to_running insertion path.
     */
    private static function rtrInsertStatement(PDO $db): PDOStatement
    {
        return $db->prepare(
            'INSERT INTO planned_workouts
             (plan_id, athlete_id, scheduled_date, workout_type,
              archetype_code, archetype_variant, archetype_params,
              description, target_duration, intensity_load, visible_to_athlete,
              workout_archetype_id, archetype_version_snapshot, instance_signature,
              structure, display_title, display_summary, athlete_instructions)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)'
        );
    }

    /**
     * Insert a return-to-running off day: low-impact cross-training matched to the
     * athlete's available equipment, otherwise a gentle generic rest day. No rehab /
     * coach-drill reference (the coach has not provided one), and no em dashes.
     */
    private static function insertReturnToRunningOffDay(
        PDOStatement $insert, int $planId, int $athleteId, string $date, array $profile
    ): void {
        [$type, $duration, $load, $title, $summary, $desc] = self::returnToRunningOffDayPrescription($profile);
        $insert->execute([
            $planId, $athleteId, $date, $type,
            null, null, null,
            $desc, $duration, $load,
            null, null, null, null, $title, $summary, $desc,
        ]);
    }

    /**
     * Resolve the off-day prescription for a return-to-running plan from the athlete's
     * cross-training equipment. Returns [workout_type, target_duration, intensity_load,
     * display_title, display_summary, description]. Effort-only, 20-25 min cross-training;
     * generic rest otherwise. No em dashes anywhere.
     */
    private static function returnToRunningOffDayPrescription(array $profile): array
    {
        if (($profile['cross_training_bike'] ?? 'none') !== 'none') {
            return ['cross_train', 25, round(25 * 0.4, 2), 'Cross-Training', '20-25 min · low impact',
                'Easy cycling, 20-25 min at a comfortable effort.'];
        }
        if (($profile['cross_training_elliptical'] ?? 'none') !== 'none') {
            return ['cross_train', 25, round(25 * 0.4, 2), 'Cross-Training', '20-25 min · low impact',
                'Easy elliptical, 20-25 min at a comfortable effort.'];
        }
        if (!empty($profile['cross_training_pool'])) {
            return ['cross_train', 25, round(25 * 0.4, 2), 'Cross-Training', '20-25 min · low impact',
                'Easy pool running or swimming, 20-25 min.'];
        }
        return ['rest', null, 0, 'Rest', 'Rest day',
            'Rest day. Keep movement gentle. Walking, light stretching, or your preferred low-impact activity is fine. Save your energy for the next run session.'];
    }

    /**
     * Adaptive return-to-running stage progression (engine spec §18.10 / §19 item 6).
     *
     * Called after an athlete logs completion of a run_walk_intervals workout. Reads
     * the modified-RPE response (effort_descriptor) and:
     *   - discomfort                       → regress one stage (floor 1), raise the
     *                                         existing return_to_running_discomfort flag
     *   - clean, current stage < 10        → advance one stage
     *   - clean, current stage == 10       → do NOT advance; raise a plan_rebuild_needed
     *                                         (info) flag so the coach transitions the
     *                                         athlete out of return_to_running
     * It then re-stages the next pending run/walk session in place to the new stage (see
     * restageNextRunWalk; the baseline progression is pre-generated session N at stage N) and runs
     * validateGeneratedDisplays() on the patched plan.
     *
     * Returns a short status string for logging, or null when the workout is not a
     * run/walk session inside an active return_to_running plan (a safe no-op for every
     * other completed-workout log).
     */
    public static function onRunWalkCompletion(
        int $athleteId, int $plannedWorkoutId, string $effortDescriptor, ?PDO $db = null
    ): ?string {
        $db = $db ?? Database::get();

        // The completed workout must be a run/walk session.
        $pw = $db->prepare(
            'SELECT id, plan_id, scheduled_date, archetype_code
             FROM planned_workouts WHERE id = ? AND athlete_id = ? LIMIT 1'
        );
        $pw->execute([$plannedWorkoutId, $athleteId]);
        $workout = $pw->fetch(PDO::FETCH_ASSOC);
        if (!$workout || ($workout['archetype_code'] ?? '') !== 'run_walk_intervals') {
            return null;
        }

        // …inside an active return_to_running plan.
        $pl = $db->prepare(
            'SELECT id, plan_type, status, rtr_current_stage
             FROM training_plans WHERE id = ? AND athlete_id = ? LIMIT 1'
        );
        $pl->execute([(int)$workout['plan_id'], $athleteId]);
        $plan = $pl->fetch(PDO::FETCH_ASSOC);
        if (!$plan || $plan['plan_type'] !== 'return_to_running' || $plan['status'] !== 'active') {
            return null;
        }

        $planId        = (int)$plan['id'];
        $currentStage  = max(self::RTR_MIN_STAGE, min(self::RTR_MAX_STAGE, (int)($plan['rtr_current_stage'] ?? 1)));
        $completedDate = (string)$workout['scheduled_date'];
        $isDiscomfort  = ($effortDescriptor === 'discomfort');

        $newStage     = $currentStage;
        $scheduleNext = true;  // schedule a next run/walk session unless the progression is complete
        $outcome      = '';

        if ($isDiscomfort) {
            // Auto-regress so the athlete never stalls, AND flag the coach for review.
            $newStage = max(self::RTR_MIN_STAGE, $currentStage - 1);
            self::raiseFlag(
                $athleteId, 'return_to_running_discomfort', 'warning',
                "Athlete reported discomfort after a stage {$currentStage} run/walk session. "
                . "Progression auto-regressed to stage {$newStage}; review before the next session.",
                $db,
                ['plan_id' => $planId, 'from_stage' => $currentStage, 'to_stage' => $newStage,
                 'completed_date' => $completedDate],
                false  // each discomfort report is its own signal — do not dedupe against an open flag
            );
            $outcome = "discomfort → regressed {$currentStage}→{$newStage}";
        } elseif ($currentStage >= self::RTR_MAX_STAGE) {
            // Stage 10 (45-min continuous run) completed cleanly: progression is done.
            // Mirror the recovery_block transition pattern — the coach decides the next
            // plan type (development_plan / maintenance_plan / race_cycle).
            $scheduleNext = false;
            self::raiseFlag(
                $athleteId, 'plan_rebuild_needed', 'info',
                'Athlete has completed the return-to-running progression. The stage 10 first '
                . 'continuous run was completed cleanly. Ready for a goal-setting conversation and '
                . 'transition to the next plan type (development plan, maintenance plan, or race cycle).',
                $db,
                ['plan_id' => $planId, 'reason' => 'return_to_running_complete',
                 'stage' => $currentStage, 'completed_date' => $completedDate]
            );
            $outcome = 'stage 10 clean → transition flag raised (no further advance)';
        } else {
            $newStage = $currentStage + 1;
            $outcome  = "clean → advanced {$currentStage}→{$newStage}";
        }

        if ($newStage !== $currentStage) {
            $db->prepare('UPDATE training_plans SET rtr_current_stage = ? WHERE id = ?')
               ->execute([$newStage, $planId]);
        }

        // Re-stage the next pending run/walk session in place to the new stage, keeping the
        // rest of the pre-generated progression intact. (The baseline plan is pre-generated
        // session N at stage N; this realigns the next session to the athlete's actual stage.)
        self::restageNextRunWalk($planId, $athleteId, $newStage, $completedDate, $scheduleNext, $db);

        // The patch wrote fresh display fields — re-validate, mirroring generate().
        self::validateGeneratedDisplays($planId, $athleteId, $db);

        return "rtr plan {$planId}: {$outcome}";
    }

    /**
     * Re-stage the NEXT pending run/walk session to $newStage after a completion. The
     * baseline progression is pre-generated (session N at stage N), so the common path is an
     * in-place UPDATE of the next pending session's archetype + display fields — no
     * delete/insert. Coach-locked sessions are preserved (the coach's explicit override
     * wins, §24).
     *
     * When $scheduleRun is false (clean stage-10 completion), nothing is scheduled — the
     * progression is complete. As a safety net (e.g. when discomfort regressions have
     * consumed the pre-generated set), if no pending session remains a new one is appended
     * on the next every-other-day slot, extending the plan and opening the live window.
     */
    private static function restageNextRunWalk(
        int $planId, int $athleteId, int $newStage, string $afterDate, bool $scheduleRun, PDO $db
    ): void {
        if (!$scheduleRun) return;

        $selector = new ArchetypeSelector($db);
        $instance = self::resolveRunWalkStage($selector, $newStage, 0, 'base', '5K', 'insufficient');
        if ($instance === null) return;

        // Next uncompleted run/walk session after the just-completed one (skip coach-locked).
        $next = $db->prepare(
            'SELECT id FROM planned_workouts
             WHERE plan_id = ? AND athlete_id = ? AND archetype_code = "run_walk_intervals"
               AND scheduled_date > ? AND coach_locked = 0
             ORDER BY scheduled_date ASC LIMIT 1'
        );
        $next->execute([$planId, $athleteId, $afterDate]);
        $nextId = (int)($next->fetchColumn() ?: 0);

        if ($nextId > 0) {
            self::updateResolvedRunWalk($nextId, $instance, $db);
            return;
        }

        // Safety net: no pending session left — append one on the next every-other-day slot.
        $profile = self::loadProfile($athleteId, $db);
        $mustOff = json_decode(($profile['must_off_days'] ?? '[]'), true) ?: [];
        $afterTs = strtotime($afterDate);

        for ($i = 2; $i <= 30; $i++) {
            $ts   = strtotime($afterDate . " +{$i} days");
            $date = date('Y-m-d', $ts);
            if (in_array((int)date('w', $ts), $mustOff, true)) continue;

            $occ = $db->prepare('SELECT 1 FROM planned_workouts WHERE plan_id = ? AND scheduled_date = ? LIMIT 1');
            $occ->execute([$planId, $date]);
            if ($occ->fetchColumn()) continue;

            $insert = self::rtrInsertStatement($db);
            self::insertResolvedWorkout($insert, $planId, $athleteId, $date, 'easy', $instance);

            $db->prepare('UPDATE training_plans SET plan_end_date = GREATEST(COALESCE(plan_end_date, ?), ?) WHERE id = ?')
               ->execute([$date, $date, $planId]);

            $tz    = self::athleteTimezone($athleteId, $db);
            $today = Timezone::dateInZone($tz, 'now');
            $db->prepare(
                'UPDATE planned_workouts SET visible_to_athlete = 1
                 WHERE plan_id = ? AND scheduled_date BETWEEN ? AND ? AND visible_to_athlete = 0'
            )->execute([$planId, $today, $date]);
            return;
        }
    }

    /**
     * Update a planned run/walk row in place to a freshly resolved instance (re-staging).
     * Mirrors insertResolvedWorkout's field rendering, but as an UPDATE that preserves the
     * row's scheduled_date and visibility.
     */
    private static function updateResolvedRunWalk(int $workoutId, array $instance, PDO $db): void
    {
        $display      = $instance['display'] ?? [];
        $title        = self::renderTemplate($display['title_template'] ?? '', $instance);
        $summary      = self::renderTemplate($display['summary_template'] ?? '', $instance);
        $instructions = self::renderTemplate($display['description_template'] ?? '', $instance);
        $instructions = self::normalizeInstructionText($instructions, $instance);
        $instructions = self::wrapWithWarmupCooldown($instructions, $instance['resolved_params'] ?? [], $instance['code'] ?? '');
        $instructions = self::appendPaceCitation($instructions, $instance);
        $instructions = self::prependPrescriptionLead($instructions, $instance);

        $sig             = self::computeInstanceSignature($instance);
        $variantCode     = $instance['resolved_variant']['code'] ?? null;
        $params          = $instance['resolved_params'] ?? [];
        $structure       = self::resolveStructure($instance);
        $variantWorkout  = $instance['resolved_variant']['workout_type'] ?? null;
        $metadataWorkout = $instance['metadata']['workout_type'] ?? $instance['workout_type'] ?? null;
        $workoutType     = $variantWorkout ?? $metadataWorkout ?? 'easy';
        $variantIF       = $instance['resolved_variant']['intensity_factor'] ?? null;
        $intensityFactor = (float)($variantIF ?? $instance['generation']['intensity_factor'] ?? 0.5);
        $storedDuration  = self::computeActualDuration($instance) ?? (int)($params['duration_minutes'] ?? 0);
        $load            = round($storedDuration * $intensityFactor, 2);

        $db->prepare(
            'UPDATE planned_workouts SET
                workout_type = ?, archetype_code = ?, archetype_variant = ?, archetype_params = ?,
                description = ?, target_duration = ?, intensity_load = ?,
                workout_archetype_id = ?, archetype_version_snapshot = ?, instance_signature = ?,
                structure = ?, display_title = ?, display_summary = ?, athlete_instructions = ?
             WHERE id = ?'
        )->execute([
            $workoutType, $instance['code'], $variantCode, json_encode($params),
            $instructions ?: null, $storedDuration, $load,
            $instance['id'] ?? null, $instance['version'] ?? null, $sig ?: null,
            $structure ? json_encode($structure) : null,
            $title ?: null, $summary ?: null, $instructions ?: null,
            $workoutId,
        ]);
    }

    /**
     * Resolve a run_walk_intervals instance at a specific stage (1-10), forcing the
     * stage variant so the deterministic structure is used rather than a weighted
     * random pick. Used by the return_to_running pathway (stage from
     * rtr_current_stage) — the development insufficient-base path goes through the
     * normal selector and defaults to stage 1 inside addDerivedParams.
     */
    private static function resolveRunWalkStage(
        ArchetypeSelector $selector, int $stage, int $targetMinutes,
        string $phase, string $goalDistance, string $classification
    ): ?array {
        $archetype = $selector->getByCode('run_walk_intervals');
        if (!$archetype) return null;
        $archetype = $selector->resolveParameters($archetype, $classification);
        $archetype['resolved_variant'] = self::runWalkStageVariant($archetype, $stage);
        return self::addDerivedParams($archetype, $targetMinutes, $phase, $goalDistance, $classification);
    }

    /**
     * Render and insert a resolved archetype instance using a prepared statement
     * matching the insertWeekWorkouts column order. Shared by the return_to_running
     * pathway so its run/walk workouts carry full archetype/display fields and pass
     * validateGeneratedDisplays().
     */
    private static function insertResolvedWorkout(
        PDOStatement $insert, int $planId, int $athleteId, string $date,
        string $slotWorkoutType, array $instance
    ): void {
        $display      = $instance['display'] ?? [];
        $title        = self::renderTemplate($display['title_template'] ?? '', $instance);
        $summary      = self::renderTemplate($display['summary_template'] ?? '', $instance);
        $instructions = self::renderTemplate($display['description_template'] ?? '', $instance);
        $instructions = self::normalizeInstructionText($instructions, $instance);
        $instructions = self::wrapWithWarmupCooldown($instructions, $instance['resolved_params'] ?? [], $instance['code'] ?? '');
        $instructions = self::appendPaceCitation($instructions, $instance);
        $instructions = self::prependPrescriptionLead($instructions, $instance);

        $sig             = self::computeInstanceSignature($instance);
        $variantCode     = $instance['resolved_variant']['code'] ?? null;
        $params          = $instance['resolved_params'] ?? [];
        $structure       = self::resolveStructure($instance);
        $variantWorkout  = $instance['resolved_variant']['workout_type'] ?? null;
        $metadataWorkout = $instance['metadata']['workout_type'] ?? $instance['workout_type'] ?? null;
        $workoutType     = $variantWorkout ?? $metadataWorkout ?? $slotWorkoutType;
        $variantIF       = $instance['resolved_variant']['intensity_factor'] ?? null;
        $intensityFactor = (float)($variantIF ?? $instance['generation']['intensity_factor'] ?? 0.5);
        $storedDuration  = self::computeActualDuration($instance) ?? (int)($params['duration_minutes'] ?? 0);
        $load            = round($storedDuration * $intensityFactor, 2);

        $insert->execute([
            $planId, $athleteId, $date, $workoutType,
            $instance['code'], $variantCode, json_encode($params),
            $instructions ?: null, $storedDuration, $load,
            $instance['id'] ?? null, $instance['version'] ?? null, $sig ?: null,
            $structure ? json_encode($structure) : null,
            $title ?: null, $summary ?: null, $instructions ?: null,
        ]);
    }

    private static function ensurePlanStartEasyRun(
        int $planId, int $athleteId, array $profile, PDO $db, ArchetypeSelector $selector
    ): void {
        $plan = $db->prepare('SELECT plan_start_date FROM training_plans WHERE id = ? LIMIT 1');
        $plan->execute([$planId]);
        $startDate = (string)($plan->fetchColumn() ?: '');
        if ($startDate === '') return;

        $existing = $db->prepare(
            'SELECT target_duration
             FROM planned_workouts
             WHERE plan_id = ? AND scheduled_date = ?
             ORDER BY id ASC
             LIMIT 1'
        );
        $existing->execute([$planId, $startDate]);
        $existingDuration = $existing->fetchColumn();

        $settings  = self::settings();
        $easyFloor = (int)($settings['easy_run_min_minutes'] ?? 30);
        $easyCap   = (int)($settings['easy_run_max_minutes'] ?? 70);
        $target    = (int)($existingDuration ?: $easyFloor);
        $target    = max($easyFloor, min($easyCap, $target));

        $goalDistance   = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');
        $classification = self::classifyAthlete($profile, $goalDistance);
        $instance       = self::resolveStandardEasyRun($selector, $target, 'base', $goalDistance, $classification);
        if ($instance === null) return;

        $db->prepare('DELETE FROM planned_workouts WHERE plan_id = ? AND scheduled_date = ?')
            ->execute([$planId, $startDate]);

        $insert = $db->prepare(
            'INSERT INTO planned_workouts
             (plan_id, athlete_id, scheduled_date, workout_type,
              archetype_code, archetype_variant, archetype_params,
              description, target_duration, intensity_load, visible_to_athlete,
              workout_archetype_id, archetype_version_snapshot, instance_signature,
              structure, display_title, display_summary, athlete_instructions)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)'
        );
        self::insertResolvedWorkout($insert, $planId, $athleteId, $startDate, 'easy', $instance);
    }

    private static function resolveStandardEasyRun(
        ArchetypeSelector $selector, int $targetMinutes,
        string $phase, string $goalDistance, string $classification
    ): ?array {
        $archetype = $selector->getByCode('continuous_easy');
        if (!$archetype) return null;

        $archetype = $selector->resolveParameters($archetype, $classification);
        $standardVariant = null;
        foreach (($archetype['variants'] ?? []) as $variant) {
            if (($variant['code'] ?? '') === 'standard_easy') {
                $standardVariant = $variant;
                break;
            }
        }
        $archetype['resolved_variant'] = $standardVariant ?? [
            'code' => 'standard_easy',
            'name' => 'Standard Easy Run',
            'workout_type' => 'easy',
            'intensity_factor' => 0.5,
        ];

        return self::addDerivedParams($archetype, $targetMinutes, $phase, $goalDistance, $classification);
    }

    private static function generateRecoveryBlock(
        int $athleteId, array $profile, string $trigger, PDO $db
    ): ?int {
        $startDate     = self::planStartDate($athleteId, $db); // "tomorrow" in the athlete's timezone
        $totalWeeks    = 4;
        $codeWeekStart = self::firstMondayOnOrAfter($startDate);
        $endDate       = self::codeWeekEndDate($codeWeekStart, $totalWeeks);

        $planId    = self::createPlanRecord($athleteId, 'recovery_block', $startDate, $endDate, null, $trigger, $db);
        $crossDesc = self::getCrossTrainDescription($profile);

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $weeklyMins = match($week) { 1 => 60, 2 => 80, 3 => 100, default => 120 };
            $runDays    = max(2, (int)($profile['training_days_per_week'] ?? 4) - 2);
            $mustOff    = json_decode($profile['must_off_days'] ?? '[]', true) ?: [];
            $available  = array_values(array_diff([0,1,2,3,4,5,6], $mustOff));
            sort($available);

            $weekStart = date('Y-m-d', strtotime($codeWeekStart . ' +' . (($week - 1) * 7) . ' days'));

            for ($d = 0; $d < 7; $d++) {
                $date      = date('Y-m-d', strtotime($weekStart . " +{$d} days"));
                $dayOfWeek = (int)date('w', strtotime($date));
                if (in_array($dayOfWeek, $mustOff)) continue;

                $posInAvail = array_search($dayOfWeek, array_values($available));
                if ($posInAvail !== false && $posInAvail < $runDays) {
                    $dur = max(20, (int)round($weeklyMins / $runDays));
                    $db->prepare(
                        'INSERT INTO planned_workouts
                         (plan_id, athlete_id, scheduled_date, workout_type,
                          archetype_code, description, target_duration, intensity_load, visible_to_athlete)
                         VALUES (?, ?, ?, "recovery", "continuous_easy", "Easy recovery run, short and gentle. Keep the effort very easy.", ?, ?, 0)'
                    )->execute([$planId, $athleteId, $date, $dur, round($dur * 0.3, 2)]);
                } else {
                    $db->prepare(
                        'INSERT INTO planned_workouts
                         (plan_id, athlete_id, scheduled_date, workout_type,
                          description, target_duration, intensity_load, visible_to_athlete)
                         VALUES (?, ?, ?, "cross_train", ?, 30, ?, 0)'
                    )->execute([$planId, $athleteId, $date, $crossDesc, round(30 * 0.4, 2)]);
                }
            }
        }

        return $planId;
    }

    // ── Week/day building ────────────────────────────────────────────────────

    /**
     * Returns a 7-element array keyed by day-of-week (0=Sun…6=Sat) → slot type.
     *
     * Slot types: long_run, quality_primary, quality_secondary, easy,
     *             easy_strides, recovery, race, rest
     */
    private static function buildDaySchedule(
        array $profile, string $phase, int $weeklyMins,
        bool $isRaceWeek, bool $isPreRaceWeek, int $athleteId, PDO $db,
        string $planType = 'development_plan', int $weekNumber = 1, bool $isCutback = false,
        ?array $ultra = null
    ): array {
        $schedule = array_fill(0, 7, 'rest');
        $mustOff  = json_decode($profile['must_off_days'] ?? '[]', true) ?: [];

        // Item 1 — days-per-week ramp (§6/§7). Rather than forcing the requested
        // training_days_per_week from week 1, cap the scheduled day count to what this
        // week's weeklyMins can structurally support under the canonical week shape
        // (1 long ≥ long-floor + the remaining days each ≥ easy-floor). When volume is
        // low the week runs fewer days (more rest); as weeklyMins grows week-over-week
        // the count ramps toward the requested days. must_off_days still constrains the
        // available pool below, so the actual scheduled count is min(numDays, available).
        $requestedDays = max(2, min(7, (int)($profile['training_days_per_week'] ?? 4)));
        $numDays       = max(2, min($requestedDays, self::supportedTrainingDays($weeklyMins)));

        // Cutback weeks give even a 7-day athlete a genuine recovery day: drop a 7-day
        // week to 6 so exactly one rest day appears. The recoverySlot tiebreaker below
        // then places it the day after the long run. Only the all-7 case is reduced —
        // athletes who already have rest days on normal weeks keep their cutback shape.
        if ($isCutback && $numDays >= 7) {
            $numDays = 6;
        }

        $longPrefRaw = isset($profile['long_run_day']) ? (int)$profile['long_run_day'] : 0;
        $longPref    = $longPrefRaw > 0 ? $longPrefRaw : null;
        $workoutPref = (isset($profile['primary_workout_day']) && $profile['primary_workout_day'] !== '')
            ? (int)$profile['primary_workout_day'] : null;

        $available = array_values(array_diff([0,1,2,3,4,5,6], $mustOff));
        sort($available);
        if (empty($available)) $available = [1, 3, 5, 0];

        // Pick long run day
        $longDay = null;
        if ($longPref !== null && in_array($longPref, $available)) {
            $longDay = $longPref;
        } elseif ($longPref !== null) {
            foreach ([0, 6] as $candidate) {
                if (in_array($candidate, $available)) { $longDay = $candidate; break; }
            }
            if ($longDay === null) $longDay = reset($available) ?: 0;
            self::raiseFlag($athleteId, 'long_run_day_conflict', 'info',
                "Preferred long run day (day {$longPref}) conflicts with availability. Long run placed on day {$longDay}.", $db);
        } else {
            foreach ([0, 6] as $candidate) {
                if (in_array($candidate, $available)) { $longDay = $candidate; break; }
            }
            if ($longDay === null) $longDay = end($available);
        }

        // Pick primary workout day (≥2 days circular distance from long run)
        $workoutDay = null;
        if ($workoutPref !== null && in_array($workoutPref, $available)) {
            $gap = min(abs($workoutPref - $longDay), 7 - abs($workoutPref - $longDay));
            if ($gap >= 2) $workoutDay = $workoutPref;
        }
        if ($workoutDay === null) {
            foreach ([2, 3, 1, 4] as $candidate) {
                if (!in_array($candidate, $available) || $candidate === $longDay) continue;
                $gap = min(abs($candidate - $longDay), 7 - abs($candidate - $longDay));
                if ($gap >= 2) { $workoutDay = $candidate; break; }
            }
        }
        if ($workoutDay === null) {
            foreach ($available as $d) {
                if ($d !== $longDay) { $workoutDay = $d; break; }
            }
        }

        // The day immediately after the long run is the natural recovery rest day. When
        // the greedy fill below has to leave a day un-trained (i.e. a rest day is needed),
        // we prefer to leave THIS day rather than letting the residual rest default to the
        // highest-numbered day (Saturday). Suppressed on ultra back-to-back weeks, where
        // that slot is reserved for the Sunday medium-long run (assigned later). (BUG 3)
        $backToBack   = $ultra !== null && !empty($ultra['back_to_back']);
        $recoverySlot = (!$backToBack && $longDay !== null) ? ($longDay + 1) % 7 : null;

        // Fill remaining training days (greedy max-gap to avoid consecutive days)
        $anchors    = array_values(array_filter([$longDay, $workoutDay], fn($v) => $v !== null));
        $remaining  = array_values(array_diff($available, $anchors));
        $addlNeeded = $numDays - count($anchors);
        $addlDays   = [];
        $trainSoFar = $anchors;

        for ($i = 0; $i < $addlNeeded && !empty($remaining); $i++) {
            // First pass: find the best max-gap score and every candidate that ties it.
            $bestGap = -1;
            $tied    = [];
            foreach ($remaining as $candidate) {
                $minGap = 7;
                foreach ($trainSoFar as $t) {
                    $g      = min(abs($candidate - $t), 7 - abs($candidate - $t));
                    $minGap = min($minGap, $g);
                }
                if ($minGap > $bestGap)      { $bestGap = $minGap; $tied = [$candidate]; }
                elseif ($minGap === $bestGap) { $tied[] = $candidate; }
            }
            // Tiebreaker: when several placements share the best gap score the bare
            // greedy would pick the lowest-numbered day, which for full-availability
            // athletes degenerates into adjacent rest-day clusters (e.g. Fri+Sat both
            // off before a Sunday long run). Prefer the placement that minimizes the
            // largest contiguous rest block in the resulting weekly schedule. With
            // constrained availability there is usually no tie, so behavior is unchanged.
            $bestDay = null;
            if (count($tied) === 1) {
                $bestDay = $tied[0];
            } elseif (count($tied) > 1) {
                $bestBlock = PHP_INT_MAX;
                $blockTied = [];
                foreach ($tied as $candidate) {
                    $block = self::largestRestBlock(array_merge($trainSoFar, [$candidate]));
                    if ($block < $bestBlock)       { $bestBlock = $block; $blockTied = [$candidate]; }
                    elseif ($block === $bestBlock) { $blockTied[] = $candidate; }
                }
                // Secondary tiebreaker (BUG 3): for 6-/7-day athletes every single-rest-day
                // layout scores an identical largest-rest-block (1), so the block score above
                // can't discriminate and the bare greedy would leave the highest day (Saturday)
                // as the lone rest. Prefer training on a day OTHER than the post-long-run
                // recovery slot, so that slot is the one left un-trained — placing the rest day
                // the day after the long run (a real recovery day) instead of biasing Saturday.
                $bestDay = $blockTied[0];
                if (count($blockTied) > 1 && $recoverySlot !== null) {
                    foreach ($blockTied as $candidate) {
                        if ($candidate !== $recoverySlot) { $bestDay = $candidate; break; }
                    }
                }
            }
            if ($bestDay !== null) {
                $addlDays[]   = $bestDay;
                $trainSoFar[] = $bestDay;
                $remaining    = array_values(array_diff($remaining, [$bestDay]));
            }
        }

        $runDays = array_unique(array_merge($anchors, $addlDays));
        sort($runDays);

        // Quality slots: driven by plan type / phase / days-per-week (ultra + mile
        // distances override the cadence). The mile can request up to 3 quality slots
        // in peak (well-trained), so placement is generalised to N extra slots beyond
        // the primary, each ≥2 days from the primary and from one another, ≥1 from long.
        $realDist  = $ultra['distance'] ?? self::$planDistance;
        $clsForQual = self::classifyAthlete($profile, (string)($realDist ?? '5K'));
        $allowedQualSlots = self::getQualitySlotCount($planType, $phase, $numDays, $weekNumber, $isCutback, $realDist, $clsForQual);
        $extraQualNeeded  = max(0, $allowedQualSlots - 1);
        $extraQualDays    = [];
        if ($extraQualNeeded > 0 && $workoutDay !== null && !$isRaceWeek && count($runDays) >= 4) {
            foreach ($runDays as $d) {
                if (count($extraQualDays) >= $extraQualNeeded) break;
                if ($d === $longDay || $d === $workoutDay || in_array($d, $extraQualDays, true)) continue;
                $gapFromPrimary = min(abs($d - $workoutDay), 7 - abs($d - $workoutDay));
                $gapFromLong    = min(abs($d - $longDay), 7 - abs($d - $longDay));
                $okFromExtras   = true;
                foreach ($extraQualDays as $e) {
                    if (min(abs($d - $e), 7 - abs($d - $e)) < 2) { $okFromExtras = false; break; }
                }
                if ($gapFromPrimary >= 2 && $gapFromLong >= 1 && $okFromExtras) { $extraQualDays[] = $d; }
            }
        }

        // Assign slot types
        if ($isRaceWeek) {
            foreach ($runDays as $day) {
                $schedule[$day] = ($day === $longDay) ? 'race' : 'easy';
            }
        } elseif ($isPreRaceWeek) {
            foreach ($runDays as $day) {
                $schedule[$day] = ($day === $longDay) ? 'long_run' : 'easy';
            }
        } else {
            foreach ($runDays as $day) {
                $schedule[$day] = match(true) {
                    $day === $longDay                       => 'long_run',
                    $day === $workoutDay                    => 'quality_primary',
                    in_array($day, $extraQualDays, true)    => 'quality_secondary',
                    default                                 => 'easy',
                };
            }
        }

        // Zero-quality weeks (e.g. 100-miler peak, Part 10): the workout-day slot is
        // always seeded as quality_primary above, so demote any quality to easy here.
        if ($allowedQualSlots < 1) {
            for ($d = 0; $d < 7; $d++) {
                if (in_array($schedule[$d], ['quality_primary', 'quality_secondary'], true)) {
                    $schedule[$d] = 'easy';
                }
            }
        }

        // Ultra back-to-back: a Sunday medium-long run the day after the Saturday long
        // run, on tired legs (ultra spec Part 9). The pair takes priority over quality for
        // ultras — any quality session within one rest day of the pair (the same ≥2-day
        // circular spacing rule the rest of the scheduler uses) is demoted to easy.
        if ($ultra !== null && !empty($ultra['back_to_back']) && $longDay !== null) {
            $mediumDay = ($longDay + 1) % 7;
            if (!in_array($mediumDay, $mustOff, true)) {
                $schedule[$mediumDay] = 'medium_long';
                for ($d = 0; $d < 7; $d++) {
                    if (!in_array($schedule[$d], ['quality_primary', 'quality_secondary'], true)) continue;
                    $gapLong = min(abs($d - $longDay),   7 - abs($d - $longDay));
                    $gapMed  = min(abs($d - $mediumDay), 7 - abs($d - $mediumDay));
                    if ($gapLong < 2 || $gapMed < 2) $schedule[$d] = 'easy';
                }
            }
        }

        // Guardrail: at least 1 rest day — UNLESS the athlete genuinely requested 7
        // training days and this week's volume supports them ($numDays === 7). Forcing a
        // rest day on a true 7-day athlete is wrong (BUG 2), and the old reverse loop here
        // always picked Saturday, compounding the Saturday-rest bias. When volume can't
        // support 7 days, $numDays is already < 7 and a natural rest day exists.
        if ($numDays < 7 && count(array_filter($schedule, fn($t) => $t === 'rest')) < 1) {
            for ($d = 6; $d >= 0; $d--) {
                if ($schedule[$d] === 'easy' && !in_array($d, $mustOff)) {
                    $schedule[$d] = 'rest';
                    break;
                }
            }
        }

        // Guardrail: cap quality sessions at the allocation for this week (2 for most
        // distances; up to 3 for a well-trained miler's peak — mile spec Part 9).
        $qualCap   = max(1, $allowedQualSlots);
        $qualCount = count(array_filter($schedule, fn($t) => in_array($t, ['quality_primary', 'quality_secondary'])));
        if ($qualCount > $qualCap) {
            $reduced = 0;
            for ($d = 0; $d < 7 && $reduced < ($qualCount - $qualCap); $d++) {
                if ($schedule[$d] === 'quality_secondary') {
                    $schedule[$d] = 'easy';
                    $reduced++;
                }
            }
        }

        // Strides placement: mark day-before quality/race as easy_strides
        // Skip if prev is the day after another quality (avoid day-after-workout rule)
        $stridesMax   = self::settings()['strides_sessions_per_week_max'] ?? 2;
        $stridesCount = 0;
        for ($d = 0; $d < 7 && $stridesCount < $stridesMax; $d++) {
            if (!in_array($schedule[$d], ['quality_primary', 'quality_secondary', 'race'])) continue;
            $prev     = ($d - 1 + 7) % 7;
            $prevPrev = ($d - 2 + 7) % 7;
            if ($schedule[$prev] !== 'easy') continue;
            if (in_array($schedule[$prevPrev], ['quality_primary', 'quality_secondary'])) continue;
            $schedule[$prev] = 'easy_strides';
            $stridesCount++;
        }

        // Mile athletes get strides more often (mile spec Part 12): 3–4×/week in
        // build/peak, daily activation strides in the taper. Beyond the day-before-quality
        // strides placed above, convert additional easy days (not the day after a quality
        // session) to easy_strides until the mile target is reached.
        if (self::isMile((string)($realDist ?? ''))) {
            $mileTarget = match ($phase) {
                'build', 'peak' => 4,
                'taper'         => 7, // every easy day (activation)
                default         => 2,
            };
            for ($d = 0; $d < 7 && $stridesCount < $mileTarget; $d++) {
                if ($schedule[$d] !== 'easy') continue;
                $prev = ($d - 1 + 7) % 7;
                if (in_array($schedule[$prev], ['quality_primary', 'quality_secondary', 'long_run'], true)) continue;
                $schedule[$d] = 'easy_strides';
                $stridesCount++;
            }
        }

        return $schedule;
    }

    /**
     * Length of the longest run of consecutive non-training (rest) days across the
     * circular week, given the set of training day indices (0=Sun … 6=Sat). Used as
     * the greedy day-selection tiebreaker so equal max-gap placements prefer the one
     * that breaks up rest clusters rather than bunching rest days together.
     */
    private static function largestRestBlock(array $runDays): int
    {
        $isRun = array_fill(0, 7, false);
        foreach ($runDays as $d) {
            $d = (int)$d;
            if ($d >= 0 && $d <= 6) $isRun[$d] = true;
        }
        // No training days at all → the whole week is one rest block.
        if (!in_array(true, $isRun, true)) return 7;
        // Walk twice around the week so a block spanning the Sat→Sun wrap is counted.
        $max = 0; $cur = 0;
        for ($i = 0; $i < 14; $i++) {
            if ($isRun[$i % 7]) { $cur = 0; }
            else { $cur++; $max = max($max, $cur); }
        }
        return min($max, 7);
    }

    /**
     * Insert planned_workouts for one week using the archetype engine.
     * Returns updated max long run minutes.
     */
    private static function insertWeekWorkouts(
        int $planId, int $athleteId, string $weekStart, string $planEnd,
        array $schedule, string $phase, string $goalDistance,
        string $classification, string $planType,
        int $weeklyMins, int $maxLongRun,
        array $constraints, PDO $db,
        ArchetypeSelector $selector, array &$antiRepeatHistory,
        ?string $rangeStart = null, ?string $rangeEnd = null,
        ?array $ultra = null, ?int $longRunOverride = null
    ): int {
        // Count slot types for volume allocation
        $longCount   = count(array_filter($schedule, fn($t) => $t === 'long_run'));
        $mediumCount = count(array_filter($schedule, fn($t) => $t === 'medium_long'));
        $qualCount = count(array_filter($schedule, fn($t) => in_array($t, ['quality_primary', 'quality_secondary'])));
        $easyCount = count(array_filter($schedule, fn($t) => in_array($t, ['easy', 'easy_strides'])));

        $s = self::settings();

        // Volume allocation
        $longMins  = 0;
        $longFloor = $s['long_run_absolute_floor_minutes'] ?? 60;
        if ($longCount > 0) {
            $progressiveCeiling = (int)round($maxLongRun * 1.15);
            if ($longRunOverride !== null) {
                // FIX 7: caller-computed weekly long-run progression (ultra / marathon race
                // cycles) takes precedence over the legacy weekly-volume sizing.
                $longMins = max($longFloor, $longRunOverride);
            } elseif ($ultra !== null) {
                // Ultra long runs are prescribed by time on feet, capped per phase, and
                // ramped via the 15%/week individual-run ceiling (ultra spec Part 8).
                $phaseCap = self::ultraLongRunCap($ultra['distance'], $phase) ?? 210;
                $longMins = max($longFloor, min($phaseCap, $progressiveCeiling));
            } elseif (self::$planDistance === 'mile') {
                // Mile long runs are aerobic support, capped per phase and not grown in
                // peak (sharpening) — mile spec Part 8.
                $longMins = max($longFloor, min(self::mileLongRunCap($phase), $progressiveCeiling));
            } else {
                $longTarget = max($longFloor, (int)floor($weeklyMins * 0.28));
                $guardrail  = (int)round($weeklyMins * 0.35);
                $longMins   = max($longFloor, min($longTarget, $progressiveCeiling, $guardrail));
            }
            $maxLongRun = max($maxLongRun, $longMins);
        }

        // Ultra back-to-back medium-long run: a fraction of the long run on tired legs.
        $mediumMins = 0;
        if ($mediumCount > 0 && $ultra !== null) {
            $mediumMins = max($longFloor, (int)round($longMins * self::ultraSundayRatio($ultra['distance'])));
        }

        // Quality archetype weighting: trail terrain + (100-miler) threshold preference,
        // or the mile's speed/threshold emphasis (mile spec Part 10).
        $qualWeightAdjust = [];
        $qualExcludeCodes = [];
        if ($ultra !== null) {
            $qualWeightAdjust = self::ultraWeightAdjust($ultra['surface'] ?? null);
            // FIX 3: 100mi/100k favour hill/fartlek/tempo; all ultras favour the hill-sprint ladder.
            $qualWeightAdjust = array_merge($qualWeightAdjust, self::ultraThresholdWeightAdjust($ultra['distance']));
            // FIX 10A: keep threshold/tempo work available and favoured in ultra base/build.
            if (in_array($phase, ['base', 'build'], true)) {
                $qualWeightAdjust['tempo_intervals']              = max((float)($qualWeightAdjust['tempo_intervals'] ?? 1), 2.0);
                $qualWeightAdjust['continuous_progression_tempo'] = max((float)($qualWeightAdjust['continuous_progression_tempo'] ?? 1), 2.0);
            }
            $qualExcludeCodes = self::ultraQualityExcludeCodes($ultra['distance'], $phase, (int)($ultra['week'] ?? 1));
        } elseif (self::$planDistance === 'mile') {
            $qualWeightAdjust = self::mileWeightAdjust();
        }

        // FIX 10C: weight goal-pace long-run segments higher for ultras in build/peak.
        $longWeightAdjust = ($ultra !== null && in_array($phase, ['build', 'peak'], true))
            ? ['goal_pace_long_segments' => 2.5]
            : [];

        $easyFloor = $s['easy_run_min_minutes'] ?? 20;
        $easyCap   = $s['easy_run_max_minutes'] ?? 70;
        // FIX 8: ultras sustain materially longer easy runs; lift the cap so easy-run
        // duration tracks weekly-volume growth instead of pinning at the road cap.
        if ($ultra !== null) {
            $easyCap = max($easyCap, 110);
        }

        // Per-quality-slot duration budget: the honest session footprint (warmup + main +
        // cooldown) a quality session may occupy while still leaving every easy slot at or
        // above its floor and keeping the week's stored total at the weekly target.
        //
        // This budget is BOTH the fit-to-slot resolution target ($qualTarget) for quality
        // archetypes AND the upper bound enforced at selection time (max_quality_duration).
        // Quality sessions therefore scale with weekly volume (≈31 min on a narrow cutback week
        // up to ≈64 min at peak for a 3-day athlete) rather than the former flat 30–40 min cap
        // (the old `max(30, min(40, weeklyMins × 0.20))` work budget). This matches the workout
        // library's intent — e.g. WL-008 (1000m repeats) and WL-014 (tempo intervals) run 50–70
        // min with realistic warmup/cooldown. A candidate whose resolved sum-of-parts still
        // exceeds the budget (e.g. short_speed_repeats, which has no fit-to-slot cap, on a
        // low-volume week) is rejected in resolveSlotInstance and falls through the eligible pool
        // to continuous_easy. The budget reserves the easy-slot floor, so easyMins never drops
        // below it. Computed at the week-allocation level — the upper-bound counterpart to
        // minimum_viable_params.
        $perSlotQualBudget = PHP_INT_MAX;
        $qualTarget        = 0;
        if ($qualCount > 0) {
            $easyReserve       = $easyFloor * $easyCount;
            $perSlotQualBudget = (int)floor(($weeklyMins - $longMins * $longCount - $easyReserve) / $qualCount);
            $qualTarget        = max($easyFloor, $perSlotQualBudget);
        }

        // easyMins is computed after quality slots are resolved (see below) so the remainder
        // reflects each quality slot's actual stored duration rather than a nominal estimate.
        $easyMins = $easyFloor;

        $insert = $db->prepare(
            'INSERT INTO planned_workouts
             (plan_id, athlete_id, scheduled_date, workout_type,
              archetype_code, archetype_variant, archetype_params,
              description, target_duration, intensity_load, visible_to_athlete,
              workout_archetype_id, archetype_version_snapshot, instance_signature,
              structure, display_title, display_summary, athlete_instructions)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)'
        );

        // Enumerate this week's training days (date + slot type), skipping rest and out-of-range.
        $days = [];
        for ($d = 0; $d < 7; $d++) {
            $date = date('Y-m-d', strtotime($weekStart . " +{$d} days"));
            if ($date > $planEnd) continue;
            // Optional window (used by the lead-in: only insert dates in [rangeStart, rangeEnd]).
            if ($rangeStart !== null && $date < $rangeStart) continue;
            if ($rangeEnd   !== null && $date > $rangeEnd)   continue;
            $dow      = (int)date('w', strtotime($date));
            $slotType = $schedule[$dow] ?? 'rest';
            if ($slotType === 'rest') continue;
            $days[] = ['date' => $date, 'slot' => $slotType];
        }

        $qualSlots = ['quality_primary', 'quality_secondary'];

        // Coaching Intelligence decision resolver (Part 7): merge active coach decisions'
        // exclude/weight/force/max-quality actions into the quality selection for this
        // week's context. No-op when no decisions match.
        $decisionMaxQuality = self::applyCoachingDecisions(
            $qualWeightAdjust, $qualExcludeCodes, $phase, $classification, $planType
        );
        $qualResolved = 0;

        // Pass 1 — resolve quality slots first so their honest sum-of-parts durations are known
        // before easyMins is computed. The max_quality_duration budget keeps each quality
        // session small enough to leave the easy slots at/above their floor. Anti-repeat history
        // is updated here; pass 2 reuses these cached instances rather than re-resolving.
        $qualInstances     = [];
        $actualQualityMins = 0;
        foreach ($days as $day) {
            if (!in_array($day['slot'], $qualSlots, true)) continue;

            // Decision-imposed quality cap: extra quality slots become easy runs.
            if ($decisionMaxQuality !== null && $qualResolved >= $decisionMaxQuality) {
                $instance = self::resolveNamedArchetype(
                    'continuous_easy', $qualTarget, $phase, $goalDistance, $classification,
                    $day['date'], $antiRepeatHistory, $selector
                );
                $qualInstances[$day['date']] = $instance;
                if ($instance !== null) {
                    $actualQualityMins += self::computeActualDuration($instance) ?? $qualTarget;
                }
                continue;
            }

            $slotConstraints = $constraints + [
                'weekly_minutes'             => $weeklyMins,
                'min_duration_week_fraction' => (float)($s['quality_min_duration_week_fraction'] ?? 0.40),
                'max_quality_duration'       => $perSlotQualBudget,
            ];
            $instance = self::resolveSlotInstance(
                $day['slot'], $phase, $goalDistance, $classification, $planType,
                $slotConstraints, $antiRepeatHistory, $qualTarget, $day['date'],
                $db, $selector, $qualWeightAdjust, $qualExcludeCodes
            );
            $qualInstances[$day['date']] = $instance;
            if ($instance !== null) {
                $qualResolved++;
                $actualQualityMins += self::computeActualDuration($instance) ?? $qualTarget;
            }
        }

        // Easy: distribute the remainder after the resolved quality + back-to-back footprint,
        // bounded by floor/cap.
        if ($easyCount > 0) {
            $used     = $longMins * $longCount + $actualQualityMins + $mediumMins * $mediumCount;
            $easyMins = max($easyFloor, min($easyCap, (int)floor(($weeklyMins - $used) / $easyCount)));
        }

        // Pass 2 — insert every day's workout. Quality slots reuse the pass-1 instance.
        foreach ($days as $day) {
            $date     = $day['date'];
            $slotType = $day['slot'];

            if ($slotType === 'race') {
                $insert->execute([
                    $planId, $athleteId, $date, 'race',
                    null, null, null,
                    'Race day. Run your race.',
                    45, round(45 * 1.35, 2),
                    null, null, null, null, 'Race Day', 'Goal race', 'Race day. Run your race.',
                ]);
                continue;
            }

            // Ultra back-to-back medium-long run (ultra spec Part 9). An easy, continuous
            // run on tired legs the day after the long run — NOT a quality session. Uses
            // continuous_long (≥60 min) or continuous_easy (<60 min), labelled "Medium-Long
            // Run", and carries the trail cue for trail ultras.
            if ($slotType === 'medium_long') {
                $code     = $mediumMins >= 60 ? 'continuous_long' : 'continuous_easy';
                $instance = self::resolveNamedArchetype(
                    $code, $mediumMins, $phase, $goalDistance, $classification,
                    $date, $antiRepeatHistory, $selector
                );
                if ($instance === null) continue;

                $params       = $instance['resolved_params'] ?? [];
                $instructions = self::renderTemplate($instance['display']['description_template'] ?? '', $instance);
                $instructions = self::normalizeInstructionText($instructions, $instance);
                $btb          = 'Back-to-back: run this on tired legs the day after your long run, at an '
                              . 'easy, conversational effort. Time on feet is the goal, not pace.';
                $cue          = self::ultraTrailLongRunCue($ultra['distance'], $ultra['surface'] ?? null, $phase);
                $instructions = trim($btb . ($cue !== '' ? ' ' . $cue : '') . ' ' . trim($instructions));

                $variantIF       = $instance['resolved_variant']['intensity_factor'] ?? null;
                $intensityFactor = (float)($variantIF ?? $instance['generation']['intensity_factor'] ?? 0.5);
                $storedDuration  = self::computeActualDuration($instance) ?? $mediumMins;
                $load            = round($storedDuration * $intensityFactor, 2);

                $insert->execute([
                    $planId, $athleteId, $date, 'long',
                    $instance['code'], $instance['resolved_variant']['code'] ?? null,
                    json_encode($params),
                    $instructions ?: null, $storedDuration, $load,
                    $instance['id'] ?? null, $instance['version'] ?? null,
                    self::computeInstanceSignature($instance) ?: null,
                    null, 'Medium-Long Run', self::durationLabel($storedDuration) . ' · easy', $instructions ?: null,
                ]);
                continue;
            }

            $targetMinutes = match($slotType) {
                'long_run'                              => $longMins,
                'quality_primary', 'quality_secondary'  => $qualTarget,
                'recovery'                              => min(30, $easyMins),
                default                                 => $easyMins, // easy, easy_strides
            };

            if (array_key_exists($date, $qualInstances)) {
                $instance = $qualInstances[$date];
            } elseif ($slotType === 'long_run' && self::$planDistance === 'mile') {
                // Mile long runs are pure aerobic only (mile spec Part 8) — no embedded
                // workouts, progression, or fast finish. The quality stimulus is the track work.
                $code     = $targetMinutes >= 60 ? 'continuous_long' : 'continuous_easy';
                $instance = self::resolveNamedArchetype(
                    $code, $targetMinutes, $phase, $goalDistance, $classification,
                    $date, $antiRepeatHistory, $selector
                );
            } else {
                $slotConstraints = $constraints + [
                    'weekly_minutes'             => $weeklyMins,
                    'min_duration_week_fraction' => (float)($s['quality_min_duration_week_fraction'] ?? 0.40),
                ];
                $instance = self::resolveSlotInstance(
                    $slotType, $phase, $goalDistance, $classification, $planType,
                    $slotConstraints, $antiRepeatHistory, $targetMinutes, $date,
                    $db, $selector,
                    $slotType === 'long_run' ? $longWeightAdjust : []
                );
            }

            if ($instance === null) continue;

            // Render athlete-facing text
            $display      = $instance['display'] ?? [];
            $title        = self::renderTemplate($display['title_template'] ?? '', $instance);
            $summary      = self::renderTemplate($display['summary_template'] ?? '', $instance);
            $instructions = self::renderTemplate($display['description_template'] ?? '', $instance);
            $instructions = self::normalizeInstructionText($instructions, $instance);
            $instructions = self::wrapWithWarmupCooldown($instructions, $instance['resolved_params'] ?? [], $instance['code'] ?? '');
            $instructions = self::appendPaceCitation($instructions, $instance);
            $instructions = self::prependPrescriptionLead($instructions, $instance);

            // Trail ultra long-run cue + power-hiking guidance (ultra spec Parts 12/13).
            if ($ultra !== null && $slotType === 'long_run') {
                $cue = self::ultraTrailLongRunCue($ultra['distance'], $ultra['surface'] ?? null, $phase);
                if ($cue !== '') {
                    $instructions = trim($instructions) === '' ? $cue : rtrim($instructions) . ' ' . $cue;
                }
            }

            // FIX 10B/10C: ultra threshold + goal-pace contextual framing. Tempo/threshold
            // efforts and goal-pace long-run segments run far faster than ultra race pace by
            // design; spell that out so the athlete trusts the prescription.
            if ($ultra !== null) {
                $cFrame = $instance['code'] ?? '';
                if (in_array($cFrame, ['tempo_intervals', 'continuous_progression_tempo'], true)) {
                    $frame = 'This effort is significantly faster than your goal race pace, and that is '
                           . 'intentional. Building your ability to sustain this effort in training makes ultra '
                           . 'pace feel comfortable by comparison. Run this controlled and strong.';
                    $instructions = trim($instructions) === '' ? $frame : rtrim($instructions) . ' ' . $frame;
                } elseif ($cFrame === 'goal_pace_long_segments') {
                    $frame = 'These segments are run at approximately marathon effort, which is significantly '
                           . 'faster than your goal race pace. This is intentional: training at marathon effort '
                           . 'builds the aerobic capacity and running economy that will make ultra pace feel '
                           . 'sustainable deep into your race.';
                    $instructions = trim($instructions) === '' ? $frame : rtrim($instructions) . ' ' . $frame;
                }
            }

            $sig         = self::computeInstanceSignature($instance);
            $variantCode = $instance['resolved_variant']['code'] ?? null;
            $params      = $instance['resolved_params'] ?? [];
            $structure   = self::resolveStructure($instance);

            // When a variant specifies workout_type, use it — regardless of which slot type
            // triggered selection. This ensures continuous_easy's standard_easy variant
            // stores workout_type='easy' (not 'interval') when filling a quality-slot fallback,
            // and recovery_easy stores 'recovery' in any context.
            $variantWorkoutType = $instance['resolved_variant']['workout_type'] ?? null;
            $metadataWorkoutType = $instance['metadata']['workout_type'] ?? $instance['workout_type'] ?? null;
            $workoutType        = $variantWorkoutType ?? $metadataWorkoutType ?? (self::SLOT_WORKOUT_TYPE[$slotType] ?? 'easy');
            $variantIF       = $instance['resolved_variant']['intensity_factor'] ?? null;
            $intensityFactor = (float)($variantIF ?? $instance['generation']['intensity_factor'] ?? 0.5);
            // Use actual sum-of-parts (warmup + main + cooldown) as the stored duration so
            // target_duration always matches the described session structure. Falls back to
            // the slot allocation for continuous archetypes where no breakdown exists.
            $storedDuration  = self::computeActualDuration($instance) ?? $targetMinutes;
            $load            = round($storedDuration * $intensityFactor, 2);

            $insert->execute([
                $planId, $athleteId, $date, $workoutType,
                $instance['code'],
                $variantCode,
                json_encode($params),
                $instructions ?: null,
                $storedDuration, $load,
                $instance['id'] ?? null,
                $instance['version'] ?? null,
                $sig ?: null,
                $structure ? json_encode($structure) : null,
                $title ?: null,
                $summary ?: null,
                $instructions ?: null,
            ]);
        }

        return $maxLongRun;
    }

    /**
     * Generate lead-in workouts — the days between plan_start_date and the first Monday
     * (code-week 1's start). The lead-in sits OUTSIDE the 12-week accounting (it never counts
     * toward weeklyMins, cutback cadence, quality selection, or cross-cycle continuity — the
     * verification weeklyTrace and the cutback math both anchor on the Monday code-week start),
     * but its days are populated with real content rather than blanket rest.
     *
     * Approach: mirror code-week 1's day-type pattern onto the matching days-of-week. We reuse
     * insertWeekWorkouts with the week-1 schedule, anchored on the Monday one week before
     * code-week 1 so each lead-in date's day-of-week maps to week 1's slot for that day, and a
     * [rangeStart, rangeEnd] window restricted to the lead-in dates. Per-slot durations are
     * therefore week-1-scale (a lead-in long run looks like week 1's long run, etc.). Because the
     * lead-in spans < 7 days, each day-of-week appears at most once, so it is a faithful slice of
     * week 1's rhythm. Generated after the main loop, so it cannot perturb the code-week
     * trajectory. It resolves against a SNAPSHOT of the anti-repeat history taken before the main
     * loop (prior-plan history only) — the chronologically faithful context, since the lead-in
     * precedes week 1. This is deliberate: resolving against the post-loop history would let week
     * 1's own signatures hard-block the lead-in's matching slots and force a type-changing fallback
     * (e.g. a long-run slot collapsing to a recovery run). Using the pre-loop snapshot keeps the
     * day TYPES faithful to week 1; the snapshot is passed by value, so its mutations are discarded.
     *
     * The absolute plan-start easy-run guarantee is enforced centrally after generation by
     * ensurePlanStartEasyRun(), so this lead-in mirror can stay faithful to code-week 1's rhythm.
     *
     * Relationship to the schedule_day_ramp flag (Item 2): that flag describes code-week 1's
     * scheduled day count vs. the requested training_days_per_week, and is raised inside the main
     * loop from week 1's volume alone. The lead-in is uncounted and generated separately, so it
     * neither feeds nor alters that assessment — the flag continues to mean strictly "week 1 runs
     * fewer days than requested," regardless of how many days the lead-in happens to populate.
     *
     * No-op when plan_start_date is a Monday (codeWeekStart === plan_start_date → no lead-in).
     */
    private static function insertLeadInWorkouts(
        int $planId, int $athleteId, string $startDate, string $codeWeekStart, string $planEnd,
        array $week1Schedule, int $weeklyMins, string $phase,
        string $goalDistance, string $classification, string $planType,
        int $maxLongRun, array $constraints, array $profile, PDO $db,
        ArchetypeSelector $selector, array $antiRepeatHistory
    ): void {
        // No lead-in on Monday starts (or any degenerate case where the code-week is not later).
        if (strtotime($codeWeekStart) <= strtotime($startDate) || empty($week1Schedule)) {
            return;
        }

        $leadInEnd  = date('Y-m-d', strtotime($codeWeekStart . ' -1 day'));   // the Sunday before
        $anchorWeek = date('Y-m-d', strtotime($codeWeekStart . ' -7 days'));  // Monday day-of-week anchor

        // Mirror week 1's pattern. The plan-start easy-run guarantee is applied after generation.
        $schedule = $week1Schedule;

        self::insertWeekWorkouts(
            $planId, $athleteId, $anchorWeek, $planEnd,
            $schedule, $phase, $goalDistance, $classification, $planType,
            $weeklyMins, $maxLongRun, $constraints, $db, $selector, $antiRepeatHistory,
            $startDate, $leadInEnd
        );
    }

    // ── Archetype instance resolution ────────────────────────────────────────

    /**
     * Select, resolve, and return an archetype instance for a plan slot.
     * Enforces hard-block and soft-penalty windows from engine_settings.
     * Falls back to continuous_easy if all candidates are blocked.
     * Updates $antiRepeatHistory in place.
     */
    private static function resolveSlotInstance(
        string $slotType, string $phase, string $goalDistance, string $classification,
        string $planType, array $constraints, array &$antiRepeatHistory,
        int $targetMinutes, string $scheduledDate,
        PDO $db, ArchetypeSelector $selector,
        array $weightAdjust = [], array $extraExcludeCodes = []
    ): ?array {
        // easy_strides → always use easy_with_strides directly
        if ($slotType === 'easy_strides') {
            return self::resolveNamedArchetype(
                'easy_with_strides', $targetMinutes, $phase, $goalDistance,
                $classification, $scheduledDate, $antiRepeatHistory, $selector
            );
        }

        $s        = self::settings();
        $hardDays = $s['same_instance_hard_block_days']    ?? 28;
        $softDays = $s['same_archetype_soft_penalty_days'] ?? 10;

        // Soft penalty: codes used within the soft-penalty window
        $cutoffSoft = date('Y-m-d', strtotime($scheduledDate . " -{$softDays} days"));
        $penalized  = [];
        foreach ($antiRepeatHistory['codes'] as $code => $dates) {
            foreach ($dates as $dt) {
                if ($dt >= $cutoffSoft) { $penalized[] = $code; break; }
            }
        }

        // Effective slot type for selector (recovery maps to easy archetype slot)
        $selectorSlot = $slotType === 'recovery' ? 'easy' : $slotType;

        $excludeSigs  = [];
        // Caller-supplied exclusions (e.g. 100-miler suppressing track-style speed work).
        $excludeCodes = $extraExcludeCodes;
        $result       = null;
        $cutoffHard   = date('Y-m-d', strtotime($scheduledDate . " -{$hardDays} days"));

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $candidate = $selector->selectForSlot(
                $selectorSlot, $phase, $goalDistance, $classification, $planType,
                $constraints, array_unique($excludeCodes), array_unique($penalized), $weightAdjust
            );

            if ($candidate === null) break;

            // Pick variant and resolve derived parameters
            $candidate = self::addDerivedParams($candidate, $targetMinutes, $phase, $goalDistance, $classification);
            if (self::isBelowMinimumViableInstance($candidate)) {
                $excludeCodes[] = $candidate['code'];
                continue;
            }

            // Deterministic beginner archetypes (run/walk, standalone strides) are the
            // athlete's primary session and intentionally recur: they bypass both the
            // per-slot quality-duration budget and the anti-repeat hard block.
            if (in_array($candidate['code'], self::REPEATABLE_ARCHETYPES, true)) {
                $result = $candidate;
                break;
            }

            // Week-allocation budget: reject a quality candidate whose honest sum-of-parts
            // duration would exceed this slot's volume budget (which reserves the easy-slot
            // floor). Continuous archetypes (null actual duration) are never rejected here, so
            // continuous_easy remains the guaranteed final fallback. See insertWeekWorkouts.
            $maxQualDur = $constraints['max_quality_duration'] ?? null;
            if ($maxQualDur !== null && in_array($slotType, ['quality_primary', 'quality_secondary'], true)) {
                $actualDur = self::computeActualDuration($candidate);
                if ($actualDur !== null && $actualDur > $maxQualDur) {
                    $excludeCodes[] = $candidate['code'];
                    continue;
                }
            }

            // Block by signature only — not by archetype code — so that other
            // variants of the same archetype remain eligible in subsequent attempts.
            $sig         = self::computeInstanceSignature($candidate);
            $hardBlocked = in_array($sig, $excludeSigs, true);
            if (!$hardBlocked) {
                foreach ($antiRepeatHistory['signatures'][$sig] ?? [] as $dt) {
                    if ($dt >= $cutoffHard) { $hardBlocked = true; break; }
                }
            }

            if (!$hardBlocked) {
                $result = $candidate;
                break;
            }

            $excludeSigs[] = $sig;
        }

        // Final fallback: continuous_easy
        if ($result === null) {
            $result = self::resolveNamedArchetype(
                'continuous_easy', $targetMinutes, $phase, $goalDistance,
                $classification, $scheduledDate, $antiRepeatHistory, $selector
            );
        }

        if ($result === null) return null;

        // Record in in-memory anti-repeat history
        $sig = self::computeInstanceSignature($result);
        $antiRepeatHistory['signatures'][$sig][]       = $scheduledDate;
        $antiRepeatHistory['codes'][$result['code']][] = $scheduledDate;

        return $result;
    }

    private static function isBelowMinimumViableInstance(array $archetype): bool
    {
        $code = $archetype['code'] ?? '';
        $minimums = $archetype['generation']['minimum_viable_params']
            ?? self::MIN_VIABLE_INSTANCE_PARAMS[$code]
            ?? null;
        if ($minimums === null) return false;

        $params = $archetype['resolved_params'] ?? [];
        foreach ($minimums as $key => $minimum) {
            if ((float)($params[$key] ?? 0) < (float)$minimum) {
                return true;
            }
        }

        return false;
    }

    /** Resolve a specific archetype by code with derived params applied. */
    private static function resolveNamedArchetype(
        string $code, int $targetMinutes, string $phase, string $goalDistance,
        string $classification, string $scheduledDate,
        array &$antiRepeatHistory, ArchetypeSelector $selector
    ): ?array {
        $archetype = $selector->getByCode($code);
        if (!$archetype) return null;

        $archetype = $selector->resolveParameters($archetype, $classification);
        $archetype = self::addDerivedParams($archetype, $targetMinutes, $phase, $goalDistance, $classification);

        $sig = self::computeInstanceSignature($archetype);
        $antiRepeatHistory['signatures'][$sig][]         = $scheduledDate;
        $antiRepeatHistory['codes'][$archetype['code']][] = $scheduledDate;

        return $archetype;
    }

    /**
     * Post-resolution: override duration with volume target, pick a variant,
     * and add derived parameters (mapped_effort, main_run_duration_minutes,
     * distance_range, total_distance, distance, time_range, etc.).
     *
     * Order of operations matters: fit-to-slot capping must run before total_distance
     * and distance_range so those values reflect capped rep counts.
     */
    private static function addDerivedParams(
        array $archetype, int $targetMinutes,
        string $phase, string $goalDistance, string $classification,
        bool $manual = false
    ): array {
        // $manual = a coach structured edit: keep the explicit resolved_params values and
        // skip every block that would re-range or re-clamp them (distribution, fit-to-slot,
        // phase cap, even-or-3 snap, mile bias). The display/structure tail still runs, so
        // title, instructions, structure, distance, and duration re-derive from the edit.
        // Default false leaves generation byte-for-byte unchanged.
        $params  = $archetype['resolved_params'] ?? [];
        $display = $archetype['display'] ?? [];

        // §18.7 distance/time correction. These quality archetypes historically
        // summarized only their main-set volume ("{{total_distance}} miles · {{time_range}}"),
        // which excludes warmup + cooldown and badly understates the session — e.g. a
        // 40-min session showing "1 mile · 8–11 min". Switch them to the full-session
        // duration + distance-range summary already used by every other structured
        // archetype, so the figures match the stored target_duration (warmup + main +
        // cooldown). Done here (not in the archetype data) so the fix lives entirely in
        // the generator; the show_* flags below drive distance_range computation.
        $fullSessionSummaryArchetypes = [
            'tempo_intervals', 'continuous_progression_tempo',
            'equal_distance_repeats', 'mixed_distance_repeats', 'short_speed_repeats',
        ];
        if (in_array($archetype['code'] ?? '', $fullSessionSummaryArchetypes, true)) {
            $display['show_distance_range'] = true;
            $display['show_time_range']     = false;
            $display['summary_template']    = '{{duration_minutes}} min · {{distance_range}}';
            $archetype['display']           = $display;
        }

        // FIX 1: render sustained-hill-repeat rep durations with the coach-friendly label
        // ("45 sec", "1m 30s") in the TITLE rather than raw seconds. The description now
        // comes from the DB-seeded template (new athlete-facing copy with the conditional
        // {{checkpoint_recovery_instruction}} clause), so only the title is overridden here.
        if (($archetype['code'] ?? '') === 'sustained_hill_repeats') {
            $display['title_template'] = '{{rep_count}} × {{rep_duration_display}} Hill Repeats';
            $archetype['display'] = $display;
        }

        // Volume-derived duration always overrides the archetype midpoint
        $params['duration_minutes'] = $targetMinutes;

        // run_walk_intervals: deterministic staged run/walk. Force a fixed stage when
        // none is preset (development insufficient-base → stage 1; return_to_running
        // presets the stage from rtr_current_stage), then build the instance-specific,
        // effort-only title + instruction and an honest fixed total duration.
        if ($archetype['code'] === 'run_walk_intervals') {
            if (!isset($archetype['resolved_variant'])) {
                $archetype['resolved_variant'] = self::runWalkStageVariant($archetype, 1);
            }
            $params = self::buildRunWalkParams($params, $archetype['resolved_variant']);
        }

        // standalone_strides: honest short fixed duration (warmup + stride window +
        // cooldown), not the slot allocation. Each stride is ~stride_duration plus
        // ~60 sec full recovery.
        if ($archetype['code'] === 'standalone_strides') {
            $warm = (int)($params['warmup_minutes'] ?? 5);
            $cool = (int)($params['cooldown_minutes'] ?? 5);
            $sc   = (int)($params['stride_count'] ?? 5);
            $sd   = (int)($params['stride_duration_seconds'] ?? 25);
            $params['duration_minutes'] = $warm + (int)ceil($sc * ($sd + 60) / 60) + $cool;
        }

        // easy_with_strides: main run duration excludes the stride window
        if ($archetype['code'] === 'easy_with_strides') {
            $strideWindow = (int)($params['stride_window_minutes'] ?? 10);
            $params['main_run_duration_minutes'] = max(15, $targetMinutes - $strideWindow);
        }

        // Map effort for archetypes that reference {{mapped_effort}}
        $mapped = self::mapEffort($archetype, $phase, $goalDistance, $classification);
        if ($mapped !== null) {
            $params['mapped_effort'] = $mapped;
        }

        // structured_fartlek_ladder (Batch 3): widen pattern-selection variety + add the
        // parameterized "diminishing descending ladder" family. The variant is picked with a
        // weighted draw (four standard shapes, plus the diminishing family occasionally). For
        // standard variants the engine now varies WHICH allowed_pattern it uses within the
        // variant's shape (not one frozen pattern) and samples round_count (more in base,
        // fewer at peak). The diminishing family builds a nested-descent sequence from sampled
        // start/step/stacks, so it produces many distinct variations.
        if ($archetype['code'] === 'structured_fartlek_ladder' && empty($params['work_intervals_seconds'])) {
            if (!isset($archetype['resolved_variant'])) {
                $archetype['resolved_variant'] = self::pickFartlekVariantWeighted($archetype['variants'] ?? []);
            }
            $variantCode  = $archetype['resolved_variant']['code'] ?? 'descending';
            $warmupMins   = (int)($params['warmup_minutes']   ?? 0);
            $cooldownMins = (int)($params['cooldown_minutes'] ?? 0);
            $avail        = max(0, $targetMinutes - $warmupMins - $cooldownMins);

            if ($variantCode === 'diminishing_descending') {
                $ladder = self::buildDiminishingLadder($avail * 60);
                $params['work_intervals_seconds'] = $ladder['flat'];
                $params['fartlek_ladder_sequence'] = $ladder['display'];
                $params['round_count'] = 1; // the nested ladder is the full session
                $display['title_template'] = '{{variant_name}}';
                $display['description_template'] =
                    'A diminishing descending ladder: {{fartlek_ladder_sequence}}. Each stack counts down to '
                    . 'the shortest surge, and the next stack starts one step lower than the last. Run every '
                    . 'surge controlled and a touch quicker as it shortens, with equal easy recovery between efforts.';
                $archetype['display'] = $display;
            } else {
                // Vary which allowed_pattern is used within the variant's shape.
                $allowed = $archetype['parameters']['work_intervals_seconds']['allowed_patterns'] ?? [];
                $wantShape = match ($variantCode) {
                    'ascending' => 'ascending',
                    'symmetric' => 'symmetric',
                    default     => 'descending', // descending + sharp_descending
                };
                $group = array_values(array_filter($allowed, fn($p) => is_array($p) && self::classifyFartlekPattern($p) === $wantShape));
                if ($variantCode === 'sharp_descending') {
                    $sharp = array_values(array_filter($group, fn($p) => min($p) <= 30));
                    if (!empty($sharp)) $group = $sharp;
                }
                if (empty($group)) $group = [[90, 60, 30]];
                $pattern = $group[array_rand($group)];
                $params['work_intervals_seconds'] = $pattern;
                $allWholeMin = max($pattern) >= 60 && array_sum(array_map(fn($s) => $s % 60, $pattern)) === 0;
                $params['fartlek_ladder_sequence'] = $allWholeMin
                    ? implode('–', array_map(fn($s) => $s / 60, $pattern)) . ' min'
                    : implode('–', $pattern) . ' sec';

                // round_count: sampled, MORE in base and FEWER at peak (sharpen), then slot-capped.
                $rcSpec   = $archetype['parameters']['round_count'][$classification]
                    ?? $archetype['parameters']['round_count']['well_trained'] ?? ['min' => 1, 'max' => 2];
                $rcMin    = (int)($rcSpec['min'] ?? 1);
                $rcMax    = (int)($rcSpec['max'] ?? 2);
                $center   = $rcMin + ($rcMax - $rcMin) * (1.0 - self::cycleFraction());
                $rounds   = max($rcMin, min($rcMax, (int)round($center + self::randFloat(-0.6, 0.6))));
                $roundSec = 2 * array_sum($pattern); // work + equal recovery
                if ($roundSec > 0) {
                    $maxRounds = max(1, (int)floor($avail * 60 / $roundSec));
                    $rounds = min($rounds, $maxRounds);
                }
                $params['round_count'] = max(1, $rounds);
            }
        }

        // hill_sprint_ladder (FIX 6): a fixed ladder of near-maximal hill sprints with
        // jog-back recovery between every rep. The variant determines the (descending /
        // pyramid / double-descending) sequence; build the display sequence + rep count.
        if (($archetype['code'] ?? '') === 'hill_sprint_ladder') {
            if (!isset($archetype['resolved_variant'])) {
                $archetype['resolved_variant'] = self::pickVariant($archetype);
            }
            $variantCode = $archetype['resolved_variant']['code'] ?? 'descending';
            $ladderMap = [
                'descending'        => [60, 50, 40, 30, 20, 10],
                'pyramid'           => [10, 20, 30, 40, 50, 60, 50, 40, 30, 20, 10],
                'double_descending' => [60, 50, 40, 30, 20, 10, 50, 40, 30, 20, 10],
            ];
            $seq = $ladderMap[$variantCode] ?? $ladderMap['descending'];
            $params['ladder_seconds']       = $seq;
            $params['rep_count']            = count($seq);
            $params['hill_sprint_sequence'] = implode('-', $seq) . ' sec';
        }

        // Pick a variant if not already set (must happen before fit-to-slot capping
        // so the variant is available, though capping below is param-driven not variant-driven).
        // Batch 2 FREQUENCY bias: for the distance-based interval pair, weight the variant
        // pick by rep distance so long-rep sessions (1000-1600m) come up more often than
        // short-rep ones (400/600m) -- Will's "6x1000 is common, 6 miles of 400s is rare".
        if (!isset($archetype['resolved_variant'])) {
            $archetype['resolved_variant'] =
                in_array($archetype['code'] ?? '', ['equal_distance_repeats', 'short_speed_repeats'], true)
                    ? self::pickDistanceVariantWeighted($archetype['variants'] ?? [])
                    : self::pickVariant($archetype);
        }

        // tempo_intervals (resolveParameters-ranging-spec Stage A): replace the frozen
        // midpoint (every well_trained tempo resolved to 4 x 14, every week, every phase)
        // with a sampled total-work target distributed into reps shaped by the selected
        // variant. Runs here, after the variant is chosen, so the variant finally drives
        // structure (rep length -> rep count) instead of only tagging workout_type.
        // Stage A is variety + coherence only; no across-cycle progression yet (Stage B).
        if (!$manual && ($archetype['code'] ?? '') === 'tempo_intervals') {
            $params = self::distributeTempoIntervals(
                $params,
                $archetype['resolved_variant'] ?? [],
                $archetype['parameters']['threshold_volume_minutes'] ?? [],
                $classification,
                $goalDistance
            );
        }

        // archetype-ranging-rollout Batch 1: volume-anchored ranging for hills and
        // high-volume time intervals. Both SHARPEN toward peak (inverted vs tempo): more/
        // longer reps in base, fewer/shorter at peak. Gated on code; tempo and the other
        // four parameterized archetypes are untouched.
        if (!$manual && ($archetype['code'] ?? '') === 'sustained_hill_repeats') {
            $params = self::distributeSustainedHillRepeats(
                $params, $archetype['resolved_variant'] ?? [], $classification, $goalDistance
            );
        }
        if (!$manual && ($archetype['code'] ?? '') === 'high_volume_time_intervals') {
            $params = self::distributeHighVolumeTimeIntervals($params, $classification, $goalDistance);
        }

        // archetype-ranging-rollout Batch 3: dynamic ladder for mixed_distance_repeats. The
        // variant fixes the ordering; the engine builds a coherent track-standard sequence
        // (3-9 rungs, more in base, fewer at peak, scaled by goal). Description/title are
        // overridden to show the actual ladder. SHARPEN direction, like Batches 1-2.
        if (($archetype['code'] ?? '') === 'mixed_distance_repeats') {
            // Generation builds the ladder; a manual edit ($manual) supplies its own
            // interval_distances (composeStructuredEdit), so only the distribution is gated.
            // The ladder title/description overrides apply either way so the edit reads right.
            if (!$manual) {
                $params = self::distributeMixedDistanceRepeats(
                    $params, $archetype['resolved_variant'] ?? [], $archetype['parameters'] ?? [],
                    $classification, $goalDistance, $targetMinutes
                );
            }
            $display['title_template']       = '{{variant_name}}';
            $display['description_template'] =
                'A mixed-distance ladder: {{mixed_ladder_sequence}}, run at a controlled hard effort. '
                . 'Stay controlled through the early reps and hold your quality across the full sequence, '
                . 'with easy jog recovery between each one.';
            $archetype['display'] = $display;
        }

        // Mile rep-distance bias (mile spec Part 10): for equal_distance_repeats, prefer the
        // shortest rep distance (≤800m suits milers), overriding the random variant pick.
        if (!$manual && self::$planDistance === 'mile' && ($archetype['code'] ?? '') === 'equal_distance_repeats') {
            $best = null; $bestDist = PHP_INT_MAX;
            foreach (($archetype['variants'] ?? []) as $v) {
                $rd = (int)($v['rep_distance_meters'] ?? 0);
                if ($rd > 0 && $rd < $bestDist) { $bestDist = $rd; $best = $v; }
            }
            if ($best !== null) $archetype['resolved_variant'] = $best;
        }

        // Generic fallback: derive rep_distance from quality_volume/rep_count only when an
        // archetype leaves it unset and isn't one of the discrete-distance archetypes below.
        if (!empty($params['rep_count']) && !empty($params['quality_volume_meters'])
            && empty($params['rep_distance_meters'])
            && !in_array($archetype['code'], ['equal_distance_repeats', 'short_speed_repeats'], true)) {
            $params['rep_distance_meters'] = (int)round($params['quality_volume_meters'] / $params['rep_count'] / 10) * 10;
        }

        // archetype-ranging-rollout Batch 2: volume-anchored ranging for the distance-based
        // interval pair. The selected variant sets the rep distance (track standard); the
        // quality-volume target is resolved by distance (short rep -> low end, long rep ->
        // high end), goal distance (marathoner > 5K), and phase (SHARPEN toward peak: more
        // volume/reps in base, fewer at peak). rep_count = volume / rep_distance, even-or-3.
        if (!$manual && in_array($archetype['code'], ['short_speed_repeats', 'equal_distance_repeats'], true)) {
            $params = self::distributeDistanceRepeats(
                $params,
                $archetype['resolved_variant'] ?? [],
                $archetype['variants'] ?? [],
                $archetype['parameters'] ?? [],
                $classification,
                $goalDistance,
                $archetype['code']
            );
        }
        // Derive rep_duration_seconds for distance-based interval archetypes. Used by
        // fit-to-slot capping and computeMainSetMinutes for sum-of-parts duration honesty.
        // Uses the quality pace range midpoint as a proxy for effort time per rep.
        if (
            in_array($archetype['code'], ['equal_distance_repeats', 'short_speed_repeats'], true)
            && empty($params['rep_duration_seconds'])
            && !empty($params['rep_distance_meters'])
        ) {
            $repDistMiles = (int)$params['rep_distance_meters'] / 1609.34;
            $paces        = $classification === 'well_trained' ? [5.5, 7.5] : [7.5, 10.5];
            $params['rep_duration_seconds'] = max(20, (int)round($repDistMiles * (($paces[0] + $paces[1]) / 2) * 60));
        }

        // (sustained_hill_repeats rep_duration_seconds + rep_duration_display are now set
        // by distributeSustainedHillRepeats from the selected variant's band, 45-240 sec;
        // the former 30-90 sec clamp is gone so Long Sustained reps reach their full range.)

        // Fit-to-slot: cap the scalable dimension so warmup + main + cooldown ≤ targetMinutes.
        // Prevents classification midpoints from producing sessions that far exceed the
        // quality slot's volume allocation. Must run before total_distance and distance_range.
        $warmupMins   = (int)($params['warmup_minutes']   ?? 0);
        $cooldownMins = (int)($params['cooldown_minutes'] ?? 0);
        $available    = max(0, $targetMinutes - $warmupMins - $cooldownMins);

        if (!$manual) switch ($archetype['code']) {
            case 'tempo_intervals':
                // Safety cap only: the variant distribution above already set a coherent
                // rep_count. Never drop below 2 reps (1 long rep is a continuous tempo, a
                // different archetype) even when the slot is tight; the eligibility gate
                // keeps tempo out of slots too small for its 2-rep minimum, and the stored
                // duration is recomputed honestly as warmup + main + cooldown.
                if (!empty($params['rep_duration_minutes']) && (float)$params['rep_duration_minutes'] > 0) {
                    $maxReps = max(2, (int)floor($available / (float)$params['rep_duration_minutes']));
                    $capped  = max(2, min((int)($params['rep_count'] ?? 2), $maxReps));
                    // The slot cap can land on a disallowed count (5, 7, ...); snap DOWN
                    // to the nearest "even or 3" so it stays within the slot.
                    $params['rep_count'] = self::snapDownEvenOr3($capped);
                }
                break;
            case 'high_volume_time_intervals':
                $repSec = (int)($params['work_duration_seconds'] ?? 0) + (int)($params['recovery_duration_seconds'] ?? 0);
                if ($repSec > 0) {
                    $maxReps = max(1, (int)floor($available * 60 / $repSec));
                    $params['rep_count'] = min((int)($params['rep_count'] ?? 1), $maxReps);
                }
                break;
            case 'sustained_hill_repeats':
                $repSec = (int)($params['rep_duration_seconds'] ?? 0);
                if ($repSec > 0) {
                    // Each rep cycle: hill time + equal jog-back ≈ 2× rep_duration
                    $maxReps = max(1, (int)floor($available * 60 / ($repSec * 2)));
                    $params['rep_count'] = min((int)($params['rep_count'] ?? 1), $maxReps);
                }
                break;
            case 'hill_sprints':
                $sprintSec = (int)($params['sprint_duration_seconds'] ?? 0);
                if ($sprintSec > 0) {
                    // Each sprint cycle: sprint_duration + ~90 sec walk-back recovery
                    $maxReps = max(1, (int)floor($available * 60 / ($sprintSec + 90)));
                    $params['sprint_count'] = min((int)($params['sprint_count'] ?? 1), $maxReps);
                }
                break;
            case 'equal_distance_repeats':
                // Rep cycle = work run + equal-duration jogging recovery (vo2_standard model).
                // Uses rep_duration_seconds-based cap; falls back to heuristic if absent.
                $repSec  = (int)($params['rep_duration_seconds'] ?? 0);
                $maxReps = $repSec > 0
                    ? max(1, (int)floor($available * 60 / ($repSec * 2)))
                    : max(1, (int)floor($available / 3));
                $params['rep_count'] = min((int)($params['rep_count'] ?? 1), $maxReps);
                break;
            case 'short_speed_repeats':
                // Rep cycle = sprint + ~90 sec generous movement recovery (speed_standard model),
                // matching computeMainSetMinutes. Batch 2 phase-scales reps in the distribution,
                // so this is a slot-fit safety cap (the old phaseRepCap no longer applies here).
                $repSec  = (int)($params['rep_duration_seconds'] ?? 0);
                if ($repSec > 0) {
                    $maxReps = max(1, (int)floor($available * 60 / ($repSec + 90)));
                    $params['rep_count'] = min((int)($params['rep_count'] ?? 1), $maxReps);
                }
                break;
            case 'continuous_progression_tempo':
                if (!empty($params['continuous_work_minutes']) && (int)$params['continuous_work_minutes'] > $available) {
                    $params['continuous_work_minutes'] = max(10, $available);
                }
                break;
        }

        // FIX 2: cap rep counts by phase (base 8, build 12, peak 16) so base carries less
        // volume than build/peak. Now ONLY mixed_distance_repeats (still on the build
        // direction, Batch 3). equal_distance / short_speed were removed: Batch 2 inverts
        // their phase direction (sharpen toward peak) inside distributeDistanceRepeats, so
        // this build-direction cap would fight it and block base sessions from reaching the
        // 9+ checkpoint band. Their slot-fit caps in the switch above still apply.
        if (!$manual && in_array($archetype['code'] ?? '', ['mixed_distance_repeats'], true)
            && !empty($params['rep_count'])) {
            $params['rep_count'] = min((int)$params['rep_count'], self::phaseRepCap($phase));
        }

        // Even-or-3 rep snapping for the rep-based archetypes, so the generated rep_count is
        // idiomatic (no 5/7/9) and, for hills, matches the conditional checkpoint math
        // (halfway = rep_count / 2 stays a clean integer). Snaps DOWN so it never exceeds the
        // fit-to-slot / phase cap. Tempo is snapped in its own distribution path.
        if (!$manual && in_array($archetype['code'] ?? '', ['sustained_hill_repeats', 'equal_distance_repeats', 'high_volume_time_intervals', 'short_speed_repeats'], true)
            && !empty($params['rep_count'])) {
            $params['rep_count'] = self::snapDownEvenOr3((int)$params['rep_count']);
        }

        $params = self::addConditionalInstructionParams($archetype['code'], $params);

        // continuous_progression_tempo: build a structure-aware, instance-specific instruction
        // from the (capped) continuous_work_minutes split into thirds, differentiated by variant.
        // Effort-based language (no pace numbers) per §2/§3 — the established athlete-facing tone.
        if ($archetype['code'] === 'continuous_progression_tempo') {
            $w = max(1, (int)($params['continuous_work_minutes'] ?? 0));
            $a = (int)round($w / 3);
            $b = (int)round($w / 3);
            $c = max(1, $w - $a - $b);
            $tempoEffort = 'tempo effort, comfortably hard, where you could say a few words but not hold a conversation';
            $variantCode = $archetype['resolved_variant']['code'] ?? 'linear_progression';
            if ($variantCode === 'wave_progression') {
                // FIX 4: a genuine wave pattern, not a 3-segment linear build. Base moderate
                // effort with short surges to tempo and floats back to moderate, trending up.
                $params['progression_instruction'] =
                    "Run continuously for {$w} minutes with no recovery breaks. Settle into a base moderate effort, "
                    . "then ride waves throughout: every couple of minutes surge for 30 to 60 seconds up to a "
                    . "{$tempoEffort}, then float back down to a moderate effort, not easy, before the next surge. "
                    . "Keep the floats honest and let the overall effort trend gradually upward, so the final waves "
                    . "are the strongest, sharpest running of the session.";
            } else {
                $params['progression_instruction'] =
                    "Run continuously for {$w} minutes with no recovery breaks, building effort steadily: roughly the "
                    . "first {$a} minutes at an easy, comfortable effort, the middle {$b} minutes at a moderate effort, "
                    . "and the final {$c} minutes at {$tempoEffort}.";
            }
        }

        // total_distance: quality volume in miles for distance-based workouts.
        // Computed after fit-to-slot capping so rep_count reflects the capped value.
        if (!isset($params['total_distance'])) {
            if (!empty($params['rep_count']) && !empty($params['rep_distance_meters'])) {
                // Meter-based repeats (equal_distance_repeats)
                $params['total_distance'] = round(
                    (int)$params['rep_count'] * (int)$params['rep_distance_meters'] / 1609.34, 1
                );
            } elseif (!empty($params['rep_count']) && !empty($params['rep_distance_miles'])) {
                // Mile-based repeats (tempo_intervals)
                $params['total_distance'] = round(
                    (int)$params['rep_count'] * (float)$params['rep_distance_miles'], 1
                );
            } elseif (!empty($params['quality_volume_meters'])) {
                // Generic quality volume in meters (mixed_distance, short_speed, etc.)
                $params['total_distance'] = round(
                    (int)$params['quality_volume_meters'] / 1609.34, 1
                );
            } elseif (!empty($params['continuous_work_minutes'])) {
                // Continuous quality work time (continuous_progression_tempo)
                $params['total_distance'] = self::estimateDistanceMiles(
                    (int)$params['continuous_work_minutes'], $goalDistance, $classification
                );
            } elseif (!empty($display['show_time_range'])) {
                // Last resort: estimate total distance from full workout duration
                $params['total_distance'] = self::estimateDistanceMiles(
                    $targetMinutes, $goalDistance, $classification
                );
            }
        }

        // distance: single distance value used by goal_pace and similar archetypes
        if (!isset($params['distance'])) {
            if (isset($params['total_distance'])) {
                $params['distance'] = $params['total_distance'];
            } elseif (!empty($params['quality_volume_meters'])) {
                $params['distance'] = round((int)$params['quality_volume_meters'] / 1609.34, 1);
            }
        }

        // Use the same effective duration for display text and stored target_duration:
        // honest sum-of-parts when derivable, otherwise the slot allocation.
        $archetype['resolved_params'] = $params;
        $displayDuration = self::computeActualDuration($archetype) ?? $targetMinutes;
        $params['duration_minutes'] = $displayDuration;
        $archetype['resolved_params'] = $params;

        // distance_range: half-mile estimate for time-based workouts, based on
        // the same effective duration stored in planned_workouts.target_duration.
        if (!empty($display['show_distance_range'])) {
            $params['distance_range'] = self::computeDistanceRange(
                $displayDuration, $goalDistance, $classification
            );
        }

        // time_range: "X–X min" estimate for the quality volume segment
        if (!empty($display['show_time_range']) && isset($params['total_distance'])) {
            $params['time_range'] = self::computeTimeRange(
                (float)$params['total_distance'], $goalDistance, $classification
            );
        }

        $archetype['resolved_params'] = $params;
        return $archetype;
    }

    private static function addConditionalInstructionParams(string $code, array $params): array
    {
        switch ($code) {
            case 'sustained_hill_repeats':
                // Conditional mid-set cue keyed on the actual (even-or-3) rep_count:
                // <=8 omitted, 9-16 one at halfway, 17+ two at quarter/three-quarter.
                $params['checkpoint_recovery_instruction'] = self::checkpointClause(
                    (int)($params['rep_count'] ?? 0),
                    'take an extra 45 to 90 seconds of standing recovery if you need it'
                );
                break;

            case 'hill_sprints':
                $params['sprint_recovery_instruction'] = ((int)($params['sprint_count'] ?? 0) > 1)
                    ? 'Walk back down and recover fully before the next one.'
                    : 'Walk back down, recover fully, then cool down.';
                break;

            case 'tempo_intervals':
                $params['tempo_recovery_instruction'] = ((int)($params['rep_count'] ?? 0) > 1)
                    ? 'Recover easily between segments and focus on rhythm and consistency.'
                    : 'Settle into rhythm and keep the effort controlled from start to finish.';
                break;

            case 'equal_distance_repeats':
                $params['repeat_consistency_instruction'] = ((int)($params['rep_count'] ?? 0) > 1)
                    ? 'Focus on even pacing and maintaining quality from the first rep to the last.'
                    : 'Focus on controlled, efficient mechanics for the full rep.';
                // Same conditional mechanism as hills, but a form-check cue rather than
                // standing recovery (shares the {{checkpoint_recovery_instruction}} token).
                $params['checkpoint_recovery_instruction'] = self::checkpointClause(
                    (int)($params['rep_count'] ?? 0),
                    'do a quick form check and reset before continuing'
                );
                break;

            case 'high_volume_time_intervals':
                $params['cumulative_volume_instruction'] = ((int)($params['rep_count'] ?? 0) >= 6)
                    ? 'The cumulative volume is the point.'
                    : 'Keep the effort controlled and stop before form breaks down.';
                break;
        }

        return $params;
    }

    /**
     * Compute a half-mile "X–Y miles" estimate for a time-based workout.
     * Uses classification- and goal-distance-based easy pace ranges.
     */
    private static function computeDistanceRange(
        int $durationMinutes, string $goalDistance, string $classification
    ): string {
        // [fast_pace_min_per_mile, slow_pace_min_per_mile]
        $paceRanges = [
            'well_trained' => [
                '5K'      => [7.5,  10.5],
                '10K'     => [8.0,  11.0],
                'half'    => [8.5,  11.5],
                'marathon'=> [9.0,  12.0],
            ],
            'workable' => [
                '5K'      => [9.5,  13.5],
                '10K'     => [10.0, 14.0],
                'half'    => [10.5, 14.0],
                'marathon'=> [11.0, 14.5],
            ],
        ];

        $cls   = array_key_exists($classification, $paceRanges) ? $classification : 'workable';
        $paces = $paceRanges[$cls][$goalDistance] ?? $paceRanges[$cls]['5K'];

        [$fastPace, $slowPace] = $paces;
        $lower = self::roundDisplayMiles($durationMinutes / $slowPace);
        $upper = self::roundDisplayMiles($durationMinutes / $fastPace);
        if ($upper <= $lower) {
            $upper = $lower + 0.5;
        }

        return self::formatDisplayMiles($lower) . '–' . self::formatDisplayMiles($upper) . ' miles';
    }

    private static function roundDisplayMiles(float $miles): float
    {
        return max(0.5, round($miles * 2) / 2);
    }

    private static function formatDisplayMiles(float $miles): string
    {
        $rounded = self::roundDisplayMiles($miles);
        if (abs($rounded - round($rounded)) < 0.0001) {
            return (string)(int)round($rounded);
        }
        return number_format($rounded, 1, '.', '');
    }

    /**
     * Estimate total distance (miles) from a duration using the midpoint of the
     * classification-based easy pace range. Used as a fallback when no explicit
     * quality volume parameter is available.
     */
    private static function estimateDistanceMiles(
        int $durationMinutes, string $goalDistance, string $classification
    ): float {
        $paceRanges = [
            'well_trained' => [
                '5K'      => [7.5,  10.5],
                '10K'     => [8.0,  11.0],
                'half'    => [8.5,  11.5],
                'marathon'=> [9.0,  12.0],
            ],
            'workable' => [
                '5K'      => [9.5,  13.5],
                '10K'     => [10.0, 14.0],
                'half'    => [10.5, 14.0],
                'marathon'=> [11.0, 14.5],
            ],
        ];
        $cls     = array_key_exists($classification, $paceRanges) ? $classification : 'workable';
        $paces   = $paceRanges[$cls][$goalDistance] ?? $paceRanges[$cls]['5K'];
        $avgPace = ($paces[0] + $paces[1]) / 2;
        return round($durationMinutes / $avgPace, 1);
    }

    /**
     * Compute an "X–X min" estimate for a given quality volume in miles.
     * Uses classification- and goal-distance-based interval effort pace ranges.
     */
    private static function computeTimeRange(
        float $totalDistanceMiles, string $goalDistance, string $classification
    ): string {
        // [fast_pace_min_per_mile, slow_pace_min_per_mile] for interval/threshold efforts
        $paceRanges = [
            'well_trained' => [
                '5K'      => [5.5,  7.5],
                '10K'     => [6.0,  8.0],
                'half'    => [6.5,  8.5],
                'marathon'=> [7.0,  9.0],
            ],
            'workable' => [
                '5K'      => [7.5,  10.5],
                '10K'     => [8.0,  11.0],
                'half'    => [8.5,  12.0],
                'marathon'=> [9.5,  13.0],
            ],
        ];

        $cls   = array_key_exists($classification, $paceRanges) ? $classification : 'workable';
        $paces = $paceRanges[$cls][$goalDistance] ?? $paceRanges[$cls]['5K'];

        [$fastPace, $slowPace] = $paces;
        $lower = (int)round($totalDistanceMiles * $fastPace);
        $upper = (int)round($totalDistanceMiles * $slowPace);

        return "{$lower}–{$upper} min";
    }

    /**
     * Stage B: build the per-week tempo progression context from the volume curve.
     *
     * Couples the tempo target to the engine's existing weekly volume progression: the
     * fraction is the position of the build-TREND volume (cutback weeks do not advance
     * the trend) between the cycle's starting volume and the peak ceiling, read AFTER
     * progression is applied. On a cutback week the target is reduced subtly, by HALF the
     * percentage the actual (dipped) volume falls below the trend, so quality dips less
     * than easy volume.
     *
     * @param int $trendVol   the undipped build-trend volume for this week (buildBase)
     * @param int $dippedVol  the actual resolved weekly volume (lower than trend on cutback)
     * @param int $floorVol   the cycle's starting weekly volume (build-trend floor)
     * @param int $peakVol    the peak volume ceiling (build-trend ceiling)
     */
    private static function cycleProgressContext(
        bool $isCutback, int $trendVol, int $dippedVol, int $floorVol, int $peakVol
    ): array {
        $denom    = $peakVol - $floorVol;
        $fraction = $denom > 0 ? max(0.0, min(1.0, ($trendVol - $floorVol) / $denom)) : 1.0;

        $cutback = 1.0;
        if ($isCutback && $trendVol > 0 && $dippedVol < $trendVol) {
            $volumeDropPct = ($trendVol - $dippedVol) / $trendVol;
            $cutback = 1.0 - 0.5 * $volumeDropPct; // tempo dips half as much as total volume
        }

        return ['fraction' => $fraction, 'cutback' => $cutback];
    }

    /**
     * Volume-anchored tempo distribution (resolveParameters-ranging-spec Stage A).
     *
     * Replaces the frozen midpoint with a sampled total-work target distributed into
     * reps shaped by the selected variant. The variant sets rep LENGTH; total work
     * divided by length sets rep COUNT, so the variant finally drives structure.
     *
     * Anchor: threshold_volume_minutes (workable 16-40, well_trained 20-60), the field
     * already present in the archetype but previously resolved-and-discarded. It is
     * sampled across a wide window (not hugged at a center) so two tempos for the same
     * athlete in the same phase can differ substantially in shape. well_trained samples
     * the upper part of its range and longer goal distances sit higher in range, so the
     * stronger athlete / longer race sees more total volume.
     *
     * Coherence guards: minimum 2 reps, maximum 8 reps, rep length within the variant
     * range, total clamped to the classification ceiling. No across-cycle progression
     * here (Stage B): the target is resolved per-instance but does not yet grow over the
     * cycle. The fit-to-slot cap downstream is the final safety on rep_count.
     *
     * @param array $thresholdSpec parameters.threshold_volume_minutes (per-classification
     *                             {min,max}); insufficient borrows the well_trained range,
     *                             matching resolveParameters' fallback.
     */
    private static function distributeTempoIntervals(
        array $params, array $variant, array $thresholdSpec,
        string $classification, string $goalDistance
    ): array {
        // 1. Resolve the total-work target (minutes at threshold effort).
        $range = $thresholdSpec[$classification]
            ?? $thresholdSpec['well_trained']
            ?? ['min' => 20, 'max' => 60];
        $tMin  = (float)($range['min'] ?? 20);
        $tMax  = (float)($range['max'] ?? 60);
        $span  = max(0.0, $tMax - $tMin);

        // Longer goal distance sits higher in the range (a marathoner's tempo target is
        // higher than a 5K athlete's). mile maps to 5K and ultra to marathon upstream.
        $goalBias = match ($goalDistance) {
            'marathon' => 0.30,
            'half'     => 0.20,
            '10K'      => 0.10,
            default    => 0.00, // 5K
        };

        // Classification band as a fraction of the range. well_trained is pinned to the
        // upper part (floor 0.40) so it always out-totals workable at the same goal/week;
        // both shift up with goal distance.
        if ($classification === 'well_trained') {
            $bandLo = min(0.85, 0.40 + $goalBias);
            $bandHi = 1.00;
        } else { // workable / insufficient (insufficient borrowed the well_trained range)
            $bandLo = min(0.50, $goalBias);
            $bandHi = min(1.00, 0.65 + $goalBias);
        }

        // Stage B: across-cycle progression. When a cycle context is present, slide the
        // sampling sub-window from the low part of the band (cycle start) to the high part
        // (peak) along the volume build-trend, so a peak-phase tempo out-totals a base-phase
        // one for the same athlete. A sub-window (not a point) preserves within-week variety;
        // variant + rep-length sampling supply the rest. Outside a progressing cycle (ad-hoc
        // preview / manual compose / maintenance) the context is null and the full band is
        // sampled, i.e. Stage A behaviour is unchanged.
        $progress = self::$cycleProgress;
        $cutback  = 1.0;
        if ($progress === null) {
            $frac = self::randFloat($bandLo, $bandHi);
        } else {
            $p       = max(0.0, min(1.0, (float)($progress['fraction'] ?? 0.0)));
            $cutback = (float)($progress['cutback'] ?? 1.0);
            $center  = $bandLo + ($bandHi - $bandLo) * $p;
            $half    = 0.10; // within-week spread around the progression center
            $lo      = max($bandLo, $center - $half);
            $hi      = min($bandHi, $center + $half);
            $frac    = self::randFloat($lo, $hi);
        }

        $target = ($tMin + $span * $frac) * $cutback;
        $target = max($tMin, min($tMax, $target)); // ceiling + floor guard

        // 2. Rep length: a CONVENTIONAL value within the selected variant's band (the
        //    durations/distances a coach actually writes), not raw sampling across the
        //    range. This is what turns 4 x 11 into 4 x 12: the total-work target is the
        //    flexible variable (it was always an estimate, dependent on pace/athlete/
        //    history), while the rep length snaps to a round number. distance_based
        //    prescribes miles, converted to minutes via a threshold-pace proxy so the
        //    same total-divided-by-length math applies.
        $variantCode = $variant['code'] ?? 'time_based';
        $pace        = self::tempoPaceMinPerMile($classification, $goalDistance);
        $ceiling     = $tMax; // classification threshold-volume ceiling

        // Candidate rep lengths as [repMinutes, repMiles] pairs.
        $candidates = [];
        if ($variantCode === 'distance_based') {
            // Conventional rep distances (miles): 0.5 ~ 800m, 1.0 = 1600m, etc.
            foreach ([0.5, 0.75, 1.0, 1.5, 2.0, 2.5, 3.0] as $mi) {
                $candidates[] = [$mi * $pace, $mi];
            }
        } else {
            // Conventional rep durations (minutes) within each variant band.
            $bands = [
                'long_reps'        => [12, 15, 18, 20],          // the long end, few reps
                'cruise_intervals' => [4, 5, 6, 7],              // the short end, more reps
                'time_based'       => [4, 5, 6, 8, 10, 12, 15, 18, 20],
            ];
            foreach ($bands[$variantCode] ?? $bands['time_based'] as $m) {
                $candidates[] = [(float)$m, $pace > 0 ? $m / $pace : 0.0];
            }
        }

        // Keep only rep lengths whose 2-rep minimum fits the ceiling, so a single long
        // rep length can't force the work far past the classification ceiling (matters
        // for distance_based, where a slow athlete's 3-mile rep is ~30 min). Never empty.
        $fit = array_values(array_filter($candidates, fn($c) => 2 * $c[0] <= $ceiling + 1e-9));
        if (!empty($fit)) $candidates = $fit;

        [$repMinutes, $repMiles] = $candidates[array_rand($candidates)];

        // 3. Rep count: the CONVENTIONAL whole number in [2,8] whose total work lands
        //    closest to the target without exceeding the ceiling. Valid counts are "even
        //    or 3" (a coaching idiom: 3x15 reads fine, but 5/7 reps do not), so 5 is
        //    absent and a raw count near it resolves to 4 or 6, whichever total sits
        //    nearer the target. The total flexes to enable a round (count, length)
        //    pairing; the >=2 / <=8 guards still hold.
        $conventionalCounts = [2, 3, 4, 6, 8];
        $repCount  = 2;
        $bestDelta = INF;
        foreach ($conventionalCounts as $c) {
            $work = $c * $repMinutes;
            if ($c > 2 && $work > $ceiling + 1e-9) continue; // keep work under the ceiling
            $delta = abs($target - $work);
            if ($delta < $bestDelta - 1e-9) {
                $bestDelta = $delta;
                $repCount  = $c;
            }
        }

        // Display the rep distance preserving conventional fractions (0.5, 0.75, 1, 1.5).
        $miDisplay = rtrim(rtrim(number_format($repMiles, 2, '.', ''), '0'), '.');

        $params['rep_count']                = $repCount;
        $params['rep_duration_minutes']     = (int)max(1, round($repMinutes));
        $params['rep_distance_miles']       = round($repMiles, 2);
        $params['rep_distance_display']     = $miDisplay !== '' ? $miDisplay : '0';
        $params['threshold_volume_minutes'] = (int)round($target);

        return $params;
    }

    /**
     * Threshold/tempo pace proxy in minutes per mile (midpoint of the quality-effort
     * band). Used to convert distance-based tempo reps into minutes so they distribute
     * against the same threshold_volume_minutes target as the time-based variants.
     */
    private static function tempoPaceMinPerMile(string $classification, string $goalDistance): float
    {
        $paces = [
            'well_trained' => [
                '5K' => [6.0, 8.0], '10K' => [6.0, 8.0], 'half' => [6.5, 8.5], 'marathon' => [7.0, 9.0],
            ],
            'workable' => [
                '5K' => [8.0, 11.0], '10K' => [8.0, 11.0], 'half' => [8.5, 12.0], 'marathon' => [9.5, 13.0],
            ],
        ];
        $cls = array_key_exists($classification, $paces) ? $classification : 'workable';
        [$fast, $slow] = $paces[$cls][$goalDistance] ?? $paces[$cls]['5K'];
        return ($fast + $slow) / 2;
    }

    /** Uniform random float in [lo, hi]. */
    private static function randFloat(float $lo, float $hi): float
    {
        if ($hi <= $lo) return $lo;
        return $lo + (mt_rand() / mt_getrandmax()) * ($hi - $lo);
    }

    /**
     * Across-cycle position in [0,1] (0 = base / cycle start, 1 = peak), from the shared
     * volume build-trend context. Outside a progressing cycle (ad-hoc preview / manual
     * compose / maintenance) there is no cycle position, so sample uniformly for variety.
     */
    private static function cycleFraction(): float
    {
        $c = self::$cycleProgress;
        if ($c !== null && isset($c['fraction'])) {
            return max(0.0, min(1.0, (float)$c['fraction']));
        }
        return self::randFloat(0.0, 1.0);
    }

    /** Nearest value from a list of conventional values (keeps durations idiomatic). */
    private static function snapToList(float $value, array $list): int
    {
        $best = (int)$list[0]; $bestDelta = INF;
        foreach ($list as $v) {
            $d = abs($v - $value);
            if ($d < $bestDelta) { $bestDelta = $d; $best = (int)$v; }
        }
        return $best;
    }

    /**
     * archetype-ranging-rollout Batch 1: volume-anchored ranging for sustained_hill_repeats.
     *
     * Variant sets the rep-duration band (Short 45-75s, Standard 75-150s, Long 150-240s),
     * snapped to conventional values. Rep count is INVERTED phase scaling (sharpen toward
     * peak): base (cycle start) carries MORE reps, peak FEWER, sampled along the shared
     * cycle fraction. The base end is extended above the old 10 cap so base sessions reach
     * the 9-16 band and the already-built checkpoint clause activates at halfway (peak stays
     * <=8, clause-free). Goal distance raises the base ceiling so a marathoner's hills carry
     * more total work than a 5K's. Even-or-3 is enforced here and re-applied post fit-to-slot.
     */
    private static function distributeSustainedHillRepeats(
        array $params, array $variant, string $classification, string $goalDistance
    ): array {
        $bands = [
            'short_sustained'    => [45, 60, 75],
            'standard_sustained' => [75, 90, 120, 150],
            'long_sustained'     => [150, 180, 210, 240],
        ];
        // hill_circuit / any unmapped variant falls back to the standard band.
        $list   = $bands[$variant['code'] ?? ''] ?? $bands['standard_sustained'];
        $repDur = (int)$list[array_rand($list)];

        // Goal distance raises the base rep ceiling (total work scales up for longer races).
        $goalBoost = match ($goalDistance) { 'marathon' => 3, 'half' => 2, '10K' => 1, default => 0 };
        if ($classification === 'well_trained') { $peakLow = 6; $baseHigh = min(12, 10 + $goalBoost); }
        else                                    { $peakLow = 4; $baseHigh = min(10, 8 + $goalBoost); }

        // Inverted phase scaling: base (fraction 0) -> baseHigh, peak (fraction 1) -> peakLow.
        $p         = self::cycleFraction();
        $repTarget = $peakLow + ($baseHigh - $peakLow) * (1.0 - $p);
        $repCount  = self::snapDownEvenOr3((int)round($repTarget));

        $params['rep_count']            = max(2, $repCount);
        $params['rep_duration_seconds'] = $repDur;
        $params['rep_duration_display'] = self::formatSecondsLabel($repDur);
        return $params;
    }

    /**
     * archetype-ranging-rollout Batch 1: range AROUND the classic 20 x 2min-on/1min-off
     * for high_volume_time_intervals instead of freezing at it.
     *
     * The canonical 20x120/60 still comes up frequently. Otherwise: on-duration sharpens
     * toward peak (longer in base ~210s, shorter at peak ~90s), the on/off ratio is sampled
     * (2:1 classic, 3:2, 1:1 much harder), and rep_count is even-or-3 scaled up with goal
     * distance (marathoner more reps -> more total work than a 5K). Durations snap to
     * conventional values. well_trained-only (the selection gate is unchanged).
     */
    private static function distributeHighVolumeTimeIntervals(
        array $params, string $classification, string $goalDistance
    ): array {
        // Keep the canonical session a frequent (not constant) outcome.
        if (mt_rand(1, 100) <= 30) {
            $params['rep_count']                 = 20;
            $params['work_duration_seconds']     = 120;
            $params['recovery_duration_seconds'] = 60;
            return $params;
        }

        $p = self::cycleFraction();

        // On-duration sharpens toward peak (base longer, peak shorter), snapped conventional.
        $workConv   = [90, 120, 150, 180, 210, 240];
        $workTarget = 210 - (210 - 90) * $p + self::randFloat(-25, 25);
        $work       = self::snapToList($workTarget, $workConv);

        // On/off ratio variety: 2:1 (classic), 3:2, 1:1 (recovery == work, much harder).
        // Recovery is capped at 120s, so a 1:1 ratio pairs with shorter on-reps while a long
        // on-rep keeps a tighter effective ratio (it stays a high-volume session, not a few
        // long intervals).
        $mults    = [0.5, 0.6667, 1.0];
        $recovery = self::snapToList($work * $mults[array_rand($mults)], [30, 45, 60, 90, 120]);

        // Rep count even-or-3 within the 12-20 band, centred higher for longer goals.
        $goalFrac  = match ($goalDistance) { 'marathon' => 0.9, 'half' => 0.65, '10K' => 0.4, default => 0.2 };
        $center    = 12 + $goalFrac * 8; // 12..20
        $repCount  = self::snapDownEvenOr3((int)round(max(12.0, min(20.0, $center + self::randFloat(-2, 2)))));

        $params['rep_count']                 = $repCount;
        $params['work_duration_seconds']     = $work;
        $params['recovery_duration_seconds'] = $recovery;
        return $params;
    }

    /**
     * Snap a rep count DOWN to the nearest idiomatic "even or 3" value (floor 2). A rep
     * count is valid iff it is even or exactly 3 (3x15 reads fine, but 5/7/9-rep sets do
     * not). Snapping down rather than to-nearest keeps the result within whatever cap
     * produced it (fit-to-slot, phase cap, or the tempo ceiling).
     */
    private static function snapDownEvenOr3(int $n): int
    {
        if ($n <= 2)        return 2;
        if ($n === 3)       return 3;
        if ($n % 2 === 0)   return $n;   // already even
        return $n - 1;                   // disallowed odd (5, 7, 9, ...) -> next lower even
    }

    /** Ordinal form of a positive integer: 1st, 2nd, 3rd, 4th, 11th, 12th, 13th, 21st. */
    private static function ordinal(int $n): string
    {
        $mod100 = $n % 100;
        if ($mod100 >= 11 && $mod100 <= 13) {
            return $n . 'th';
        }
        return $n . match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    /**
     * Build the conditional mid-set checkpoint clause for a rep-based archetype, keyed on
     * the instance's actual rep_count. Returns '' (omitted, not blank) for <= 8 reps; one
     * checkpoint at halfway for 9-16; two at quarter and three-quarter for 17+. The cue
     * fragment is archetype-specific (hills: standing recovery; equal_distance: form
     * check). The clause carries a LEADING space when present, so a description can append
     * {{checkpoint_recovery_instruction}} with no preceding space and read cleanly when the
     * clause is omitted. rep_count is "even or 3", so the positions are clean integers.
     */
    private static function checkpointClause(int $repCount, string $action): string
    {
        if ($repCount <= 8) {
            return '';
        }
        if ($repCount <= 16) {
            $half = (int)round($repCount / 2);
            return ' After the ' . self::ordinal($half) . ' rep, ' . $action . '.';
        }
        $quarter      = self::snapDownEvenOr3((int)round($repCount * 0.25));
        $threeQuarter = self::snapDownEvenOr3((int)round($repCount * 0.75));
        return ' After the ' . self::ordinal($quarter) . ' and ' . self::ordinal($threeQuarter)
            . ' reps, ' . $action . '.';
    }

    /**
     * archetype-ranging-rollout Batch 2: volume-anchored ranging for the distance-based
     * interval pair (equal_distance_repeats, short_speed_repeats).
     *
     * The variant fixes the rep distance (a track standard). The quality-volume target is
     * positioned within the classification's quality_volume_meters range by three levers:
     *   - rep DISTANCE: short reps target the LOW end, long reps the HIGH end (so a 6x400
     *     session stays small and a 6x1000 is larger -- Will's distance-biases-volume rule);
     *   - GOAL distance: marathoner sits higher than a 5K runner (total scales up with goal);
     *   - PHASE: SHARPEN toward peak -> more volume in base, less at peak (inverted cycle
     *     fraction, same direction as Batch 1).
     * rep_count = round(volume / rep_distance), clamped to the classification band and snapped
     * even-or-3. So short reps yield MORE reps at a SMALLER total, long reps FEWER at a LARGER
     * total; a high-volume base session can reach the 9+ band and fire the checkpoint clause
     * (equal_distance). short_speed stays low-total by design (its narrow volume band).
     */
    private static function distributeDistanceRepeats(
        array $params, array $variant, array $variants, array $allParams,
        string $classification, string $goalDistance, string $code
    ): array {
        $default = $code === 'short_speed_repeats' ? 200 : 800;
        $repDist = (int)($variant['rep_distance_meters'] ?? $params['rep_distance_meters'] ?? $default);

        // Position of this rep distance among the archetype's selectable variant distances.
        $dists = array_values(array_filter(array_map(fn($v) => (int)($v['rep_distance_meters'] ?? 0), $variants)));
        $dmin = $dists ? min($dists) : $repDist;
        $dmax = $dists ? max($dists) : $repDist;
        $distFrac = $dmax > $dmin ? ($repDist - $dmin) / ($dmax - $dmin) : 0.5;

        $goalFrac  = match ($goalDistance) { 'marathon' => 0.9, 'half' => 0.65, '10K' => 0.4, default => 0.2 };
        $phaseFrac = 1.0 - self::cycleFraction(); // base -> 1 (more volume), peak -> 0 (sharpen)

        $vRange = $allParams['quality_volume_meters'][$classification]
            ?? $allParams['quality_volume_meters']['well_trained'] ?? ['min' => 2400, 'max' => 6000];
        $vMin = (float)($vRange['min'] ?? 2400);
        $vMax = (float)($vRange['max'] ?? 6000);
        $pVol = 0.40 * $distFrac + 0.35 * $goalFrac + 0.25 * $phaseFrac;
        $pVol = max(0.0, min(1.0, $pVol + self::randFloat(-0.08, 0.08)));
        $volTarget = $vMin + ($vMax - $vMin) * $pVol;

        $rcSpec = $allParams['rep_count'][$classification] ?? $allParams['rep_count']['well_trained'] ?? ['min' => 4, 'max' => 12];
        $rcMin  = (int)($rcSpec['min'] ?? 4);
        $rcMax  = (int)($rcSpec['max'] ?? 12);
        $repCount = (int)round($volTarget / max(1, $repDist));
        $repCount = self::snapDownEvenOr3(max($rcMin, min($rcMax, $repCount)));

        $params['rep_distance_meters'] = $repDist;
        $params['rep_count']           = max(2, $repCount);
        $params['quality_volume_meters'] = (int)round($volTarget);
        if ($code === 'short_speed_repeats') {
            $params['effort_zone'] = $params['effort_zone'] ?? 'repetition';
        }
        return $params;
    }

    /**
     * Batch 2 FREQUENCY bias: weighted variant pick favouring LONGER rep distances, so long-
     * rep interval sessions are prescribed more often than short-rep ones (weight is linear in
     * rep distance). Falls back to a uniform pick when distances are absent.
     */
    private static function pickDistanceVariantWeighted(array $variants): array
    {
        if (empty($variants)) return ['code' => 'standard', 'name' => 'Standard'];
        $total = 0; $weighted = [];
        foreach ($variants as $v) {
            $w = max(1, (int)($v['rep_distance_meters'] ?? 0));
            $weighted[] = ['v' => $v, 'w' => $w];
            $total += $w;
        }
        $r = mt_rand(1, $total); $acc = 0;
        foreach ($weighted as $e) {
            $acc += $e['w'];
            if ($r <= $acc) return $e['v'];
        }
        return $weighted[array_key_last($weighted)]['v'];
    }

    // ── archetype-ranging-rollout Batch 3 (mixed_distance / structured_fartlek) ────

    /** Track-standard rung distances for mixed-distance ladders. */
    private const MIXED_TRACK = [200, 300, 400, 600, 800, 1000, 1200, 1600];

    /**
     * Batch 3: DYNAMIC SEQUENCE generation for mixed_distance_repeats. The session is a
     * ladder of DIFFERENT track-standard distances (not N reps of one), so even-or-3 does
     * not apply. The variant fixes the ORDERING (long_to_short / strength_speed = descending;
     * short_to_long / speed_strength = ascending; combo_set = pyramid). Rung count is 3-9
     * (~5 typical), MORE in base and FEWER toward peak (sharpen), and higher for longer goal
     * distances. The quality_volume_meters anchor (goal + phase scaled) sets the average rung
     * distance; the rungs are chosen as a coherent window of track standards and arranged by
     * the variant so the engine never emits a random jumble. Truncated to fit the slot.
     */
    private static function distributeMixedDistanceRepeats(
        array $params, array $variant, array $allParams,
        string $classification, string $goalDistance, int $targetMinutes
    ): array {
        $vRange = $allParams['quality_volume_meters'][$classification]
            ?? $allParams['quality_volume_meters']['well_trained'] ?? ['min' => 3000, 'max' => 6000];
        $vMin = (float)($vRange['min'] ?? 3000);
        $vMax = (float)($vRange['max'] ?? 6000);

        $goalFrac  = match ($goalDistance) { 'marathon' => 0.9, 'half' => 0.65, '10K' => 0.4, default => 0.2 };
        $cycleFrac = self::cycleFraction();
        $phaseFrac = 1.0 - $cycleFrac;
        $pVol      = max(0.0, min(1.0, 0.55 * $goalFrac + 0.45 * $phaseFrac + self::randFloat(-0.07, 0.07)));
        $volTarget = (int)round($vMin + ($vMax - $vMin) * $pVol);

        // Rung count: base more, peak fewer; goal distance raises the base ceiling. Hard cap 9.
        $goalBoost = match ($goalDistance) { 'marathon' => 2, 'half' => 1, '10K' => 1, default => 0 };
        $baseHigh  = min(8, 6 + $goalBoost);
        $rungCount = (int)round(4 + ($baseHigh - 4) * $phaseFrac);
        $rungCount = max(3, min(9, $rungCount));

        $variantCode = $variant['code'] ?? 'long_to_short';
        $pace = $classification === 'well_trained' ? 6.5 : 9.0; // min/mile quality proxy
        $warm = (int)($params['warmup_minutes'] ?? 0);
        $cool = (int)($params['cooldown_minutes'] ?? 0);
        $available = max(10, $targetMinutes - $warm - $cool);
        $estMin = static fn(array $s): float => array_sum(array_map(fn($m) => $m / 1609.34 * $pace * 2, $s));

        $seq = self::buildMixedLadder($variantCode, $volTarget, $rungCount);
        $guard = 0;
        while ($estMin($seq) > $available && $rungCount > 3 && $guard++ < 8) {
            $rungCount--;
            $volTarget = (int)round($volTarget * 0.88);
            $seq = self::buildMixedLadder($variantCode, $volTarget, $rungCount);
        }

        $params['interval_distances']    = $seq;
        $params['rung_count']            = count($seq);
        $params['quality_volume_meters'] = array_sum($seq);
        $params['mixed_ladder_sequence'] = implode(' - ', $seq) . ' m';
        return $params;
    }

    /**
     * Build a coherent ladder of $rungCount track-standard distances around the average
     * (volume / count), arranged by the variant ordering. Ascending/descending take a
     * distinct window of track standards; combo_set builds a pyramid (ascending to a peak,
     * then mirrored down). strength_speed/speed_strength shift the window toward longer/
     * shorter distances respectively.
     */
    private static function buildMixedLadder(string $variant, int $volTarget, int $rungCount): array
    {
        $track = self::MIXED_TRACK;
        $n     = count($track);
        $order = match ($variant) {
            'long_to_short', 'strength_speed' => 'desc',
            'short_to_long', 'speed_strength' => 'asc',
            default                           => 'pyramid', // combo_set
        };
        $shift = match ($variant) { 'strength_speed' => 1, 'speed_strength' => -1, default => 0 };

        if ($order === 'pyramid') {
            $k   = max(2, (int)ceil($rungCount / 2)); // ascending half incl peak
            $avg = $volTarget / max(1, $rungCount);
            $idx = self::nearestIndex($track, $avg);
            $start = max(0, min($n - $k, $idx - (int)floor($k / 2) + $shift));
            $up  = array_slice($track, $start, $k);              // ascending distinct
            return array_merge($up, array_reverse(array_slice($up, 0, $k - 1)));
        }

        $rungCount = min($rungCount, $n);
        $avg   = $volTarget / max(1, $rungCount);
        $idx   = self::nearestIndex($track, $avg);
        $start = max(0, min($n - $rungCount, $idx - (int)floor($rungCount / 2) + $shift));
        $win   = array_slice($track, $start, $rungCount);        // ascending distinct
        return $order === 'desc' ? array_reverse($win) : $win;
    }

    /** Index of the list entry nearest $value. */
    private static function nearestIndex(array $list, float $value): int
    {
        $best = 0; $bd = INF;
        foreach ($list as $i => $v) { $d = abs($v - $value); if ($d < $bd) { $bd = $d; $best = $i; } }
        return $best;
    }

    /**
     * Batch 3 fartlek variant pick: the standard shape variants at equal weight, the
     * "diminishing_descending" family OCCASIONAL (~9%). The family is now a DB variant row
     * (coach-pickable in the Library); it is weighted low here so auto-roll frequency is
     * unchanged. If the DB row is absent (fresh env before the migration), the family is
     * still synthesized so in-code generation keeps working either way.
     */
    private static function pickFartlekVariantWeighted(array $variants): array
    {
        $candidates = []; $hasDiminishing = false;
        foreach ($variants as $v) {
            $isDim = ($v['code'] ?? '') === 'diminishing_descending';
            if ($isDim) $hasDiminishing = true;
            $candidates[] = ['v' => $v, 'w' => $isDim ? 4 : 10];
        }
        if (!$hasDiminishing) {
            $candidates[] = ['v' => ['code' => 'diminishing_descending', 'name' => 'Diminishing Descending Ladder'], 'w' => 4];
        }
        $total = array_sum(array_column($candidates, 'w'));
        $r = mt_rand(1, max(1, $total)); $acc = 0;
        foreach ($candidates as $e) { $acc += $e['w']; if ($r <= $acc) return $e['v']; }
        return $candidates[array_key_last($candidates)]['v'];
    }

    /**
     * Batch 3: parameterized "diminishing descending ladder" surge sequence. A nested descent:
     * each stack counts down from its top to the floor by a fixed step; each stack starts one
     * step lower than the last. start_point 60-120s, step 10/15s, 3-5 stacks (sampled), so it
     * produces many distinct variations rather than one fixed monster. Truncated so the full
     * work + equal-recovery footprint fits the available main-set seconds. Returns the flat
     * surge list plus a stack-grouped display string.
     */
    private static function buildDiminishingLadder(int $availSeconds): array
    {
        $start = [60, 75, 90, 105, 120][array_rand([60, 75, 90, 105, 120])];
        $step  = [10, 15][array_rand([10, 15])];
        $floor = $step >= 15 ? 15 : 10;
        $maxStacks = mt_rand(3, 5);

        $flat = []; $stacks = []; $used = 0;
        for ($s = 0; $s < $maxStacks; $s++) {
            $top = $start - $s * $step;
            if ($top < $floor + $step) break; // a stack needs at least two surges
            $stack = [];
            for ($v = $top; $v >= $floor; $v -= $step) {
                if ($used + $v * 2 > $availSeconds && !empty($flat)) { $stacks[] = $stack; break 2; }
                $stack[] = $v; $flat[] = $v; $used += $v * 2;
            }
            $stacks[] = $stack;
        }
        $stacks  = array_values(array_filter($stacks, fn($s) => !empty($s)));
        $display = implode(', ', array_map(fn($st) => implode('-', $st), $stacks)) . ' sec';
        return ['flat' => $flat, 'display' => $display];
    }

    /** Classify a fartlek work-interval pattern by shape (for varied selection within a variant). */
    private static function classifyFartlekPattern(array $p): string
    {
        $inc = true; $dec = true;
        for ($i = 1, $c = count($p); $i < $c; $i++) {
            if ($p[$i] <= $p[$i - 1]) $inc = false;
            if ($p[$i] >= $p[$i - 1]) $dec = false;
        }
        if ($inc) return 'ascending';
        if ($dec) return 'descending';
        return 'symmetric';
    }

    /** Pick a random variant from the archetype's variants array. */
    private static function pickVariant(array $archetype): array
    {
        $variants = $archetype['variants'] ?? [];
        if (empty($variants)) return ['code' => 'standard', 'name' => 'Standard'];
        return $variants[array_rand($variants)];
    }

    /** Find the run_walk_intervals variant for a stage (1-10), defaulting to stage 1. */
    private static function runWalkStageVariant(array $archetype, int $stage): array
    {
        foreach ($archetype['variants'] ?? [] as $v) {
            if ((int)($v['stage'] ?? 0) === $stage) return $v;
        }
        return $archetype['variants'][0] ?? [
            'code' => 'stage_1', 'name' => 'Stage 1', 'workout_type' => 'easy', 'stage' => 1,
            'run_minutes' => 1, 'walk_minutes' => 3, 'rep_count' => 10,
            'warmup_minutes' => 10, 'cooldown_minutes' => 5,
        ];
    }

    /**
     * Copy a run_walk stage variant's structure into resolved_params and build the
     * instance-specific, effort-only title + instruction and the honest total
     * duration. Stages 1-9 are N reps of (run X / walk Y) bookended by a brisk-walk
     * warmup and a walk cooldown; stage 10 is the first continuous run.
     */
    private static function buildRunWalkParams(array $params, array $v): array
    {
        $stage = (int)($v['stage'] ?? 1);
        $warm  = (int)($v['warmup_minutes'] ?? 0);
        $cool  = (int)($v['cooldown_minutes'] ?? 0);
        $params['stage']            = $stage;
        $params['warmup_minutes']   = $warm;
        $params['cooldown_minutes'] = $cool;

        if (!empty($v['is_continuous'])) {
            $cont = (int)($v['continuous_minutes'] ?? 45);
            $params['run_minutes']      = $cont;
            $params['walk_minutes']     = 0;
            $params['rep_count']        = 0;
            $params['duration_minutes'] = $cont;
            $params['run_walk_title']   = "Stage {$stage}: {$cont} min continuous run";
            $params['run_walk_instruction'] =
                "Your first continuous run. Settle into an easy, conversational effort and stay relaxed for the "
                . "full {$cont} minutes with no walk breaks. Keep it gentle; this is about time on your feet, not pace. "
                . "Stop immediately and note any pain or unusual discomfort, and let your coach know how it felt.";
            return $params;
        }

        $run  = (int)($v['run_minutes'] ?? 1);
        $walk = (int)($v['walk_minutes'] ?? 1);
        $reps = (int)($v['rep_count'] ?? 1);
        $params['run_minutes']      = $run;
        $params['walk_minutes']     = $walk;
        $params['rep_count']        = $reps;
        $params['duration_minutes'] = $warm + $reps * ($run + $walk) + $cool;

        $runWord  = $run === 1 ? 'minute' : 'minutes';
        $walkWord = $walk === 1 ? 'minute' : 'minutes';
        $params['run_walk_title'] = "Stage {$stage}: {$reps} × {$run}min run / {$walk}min walk";
        $params['run_walk_instruction'] =
            "Warm up with {$warm} minutes of brisk walking. Then run {$run} {$runWord} at an easy, conversational "
            . "effort and walk {$walk} {$walkWord} to recover; repeat that {$reps} times. Cool down with {$cool} "
            . "minutes of easy walking. Keep every running segment relaxed: effort, not pace. Stop immediately and "
            . "note any pain or unusual discomfort, and let your coach know how it felt.";
        return $params;
    }

    /**
     * Compute the main-set duration in minutes from resolved (and capped) parameters.
     * Returns null for archetypes where main-set time can't be derived from params alone
     * (e.g. mixed_distance_repeats, which lacks a rep_count/rep_duration structure).
     */
    private static function computeMainSetMinutes(string $code, array $params): ?float
    {
        return match ($code) {
            'tempo_intervals' =>
                (int)($params['rep_count'] ?? 0) * (float)($params['rep_duration_minutes'] ?? 0),
            'high_volume_time_intervals' =>
                (int)($params['rep_count'] ?? 0)
                    * ((int)($params['work_duration_seconds'] ?? 0) + (int)($params['recovery_duration_seconds'] ?? 0))
                    / 60.0,
            // Hill time + equal jog-back recovery per rep cycle ≈ 2× rep_duration
            'sustained_hill_repeats' =>
                (int)($params['rep_count'] ?? 0) * (int)($params['rep_duration_seconds'] ?? 0) * 2 / 60.0,
            // Sprint + ~90 sec walk-back recovery per sprint
            'hill_sprints' =>
                (int)($params['sprint_count'] ?? 0) * ((int)($params['sprint_duration_seconds'] ?? 0) + 90) / 60.0,
            // Each ladder rep: sprint up + jog back to the bottom (≈ equal time) → 2× sprint
            'hill_sprint_ladder' =>
                !empty($params['ladder_seconds'])
                    ? 2 * array_sum((array)$params['ladder_seconds']) / 60.0
                    : null,
            'structured_fartlek_ladder' =>
                !empty($params['work_intervals_seconds'])
                    ? (int)($params['round_count'] ?? 1) * 2 * array_sum((array)$params['work_intervals_seconds']) / 60.0
                    : null,
            'continuous_progression_tempo' =>
                (float)($params['continuous_work_minutes'] ?? 0),
            // Rep + walk-back recovery per sprint (speed_standard model — same formula as hill_sprints)
            'short_speed_repeats' =>
                (int)($params['rep_count'] ?? 0) * ((int)($params['rep_duration_seconds'] ?? 0) + 90) / 60.0,
            // Rep + equal-duration jogging recovery (vo2_standard model)
            'equal_distance_repeats' =>
                (int)($params['rep_count'] ?? 0) * (int)($params['rep_duration_seconds'] ?? 0) * 2 / 60.0,
            // Circuit + ~90 sec active recovery per circuit (coach-clearance only, same pattern as hill_sprints)
            'plyometric_hill_circuits' =>
                (int)($params['circuit_count'] ?? 0) * ((int)($params['hill_sprint_duration_seconds'] ?? 0) + 90) / 60.0,
            default => null,
        };
    }

    /**
     * Compute the actual session duration as warmup + main_set + cooldown from resolved params.
     * Returns null for continuous archetypes (no warmup/cooldown) or where main-set
     * time can't be derived — callers fall back to the slot allocation in those cases.
     */
    private static function computeActualDuration(array $archetype): ?int
    {
        $params   = $archetype['resolved_params'] ?? [];

        // Fixed-duration archetypes store their honest total directly in duration_minutes
        // (run/walk stage total; standalone-strides warmup + stride window + cooldown).
        if (in_array($archetype['code'] ?? '', self::REPEATABLE_ARCHETYPES, true)) {
            $d = (int)($params['duration_minutes'] ?? 0);
            return $d > 0 ? $d : null;
        }

        $warmup   = (int)($params['warmup_minutes']   ?? 0);
        $cooldown = (int)($params['cooldown_minutes'] ?? 0);

        if ($warmup === 0 && $cooldown === 0) return null;

        $main = self::computeMainSetMinutes($archetype['code'], $params);
        if ($main === null) return null;

        return (int)round($warmup + $main + $cooldown);
    }

    /**
     * Compute the pipe-delimited instance signature from archetype.instance_signature.fields.
     * Fields 'code' and 'variant' are resolved from archetype metadata; all others from resolved_params.
     */
    private static function computeInstanceSignature(array $archetype): string
    {
        $fields      = $archetype['instance_signature']['fields'] ?? ['code'];
        $params      = $archetype['resolved_params'] ?? [];
        $variantCode = $archetype['resolved_variant']['code'] ?? 'standard';
        $parts       = [];

        foreach ($fields as $field) {
            if ($field === 'code') {
                $parts[] = $archetype['code'];
            } elseif ($field === 'variant') {
                $parts[] = $variantCode;
            } elseif (array_key_exists($field, $params)) {
                $v       = $params[$field];
                $parts[] = is_array($v) ? implode(',', $v) : (string)$v;
            } else {
                $parts[] = '';
            }
        }

        return implode('|', $parts);
    }

    /**
     * Replace {{token}} placeholders in a template string with resolved parameter values.
     */
    private static function renderTemplate(string $template, array $archetype): string
    {
        if ($template === '') return '';

        $params      = $archetype['resolved_params'] ?? [];
        $variantName = $archetype['resolved_variant']['name'] ?? '';
        $variantCode = $archetype['resolved_variant']['code'] ?? '';

        $tokens = array_merge($params, [
            'variant_name'           => $variantName,
            'variant'                => $variantCode,
            'generated_workout_title'=> $archetype['name'] ?? $archetype['metadata']['name'] ?? '',
        ]);

        $rendered = preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($tokens) {
            $key = $m[1];
            if (!array_key_exists($key, $tokens)) return '';
            $v = $tokens[$key];
            if (in_array($key, ['distance', 'total_distance'], true) && is_numeric($v)) {
                return self::formatDisplayMiles((float)$v);
            }
            return is_array($v) ? implode(', ', $v) : (string)$v;
        }, $template);

        return preg_replace('/(^|·\s)1 miles\b/', '${1}1 mile', $rendered);
    }

    private static function normalizeInstructionText(string $instructions, array $archetype): string
    {
        if (($archetype['code'] ?? '') === 'sustained_hill_repeats') {
            $params = $archetype['resolved_params'] ?? [];
            $repCount = (int)($params['rep_count'] ?? 0);
            $oldQuarterText = 'At the quarter, halfway, and three-quarter points of the workout, take 45-90 seconds standing recovery if you need it.';
            $oldQuarterTextUtf = 'At the quarter, halfway, and three-quarter points of the workout, take 45–90 seconds standing recovery if you need it.';
            $replacement = $params['checkpoint_recovery_instruction'] ?? '';
            if ($repCount < 4) {
                $instructions = str_replace([$oldQuarterText, $oldQuarterTextUtf], $replacement, $instructions);
            }
            $instructions = preg_replace('/\s+/', ' ', $instructions);
        }

        // FIX 5: no em dashes in generated workout text. Any em dash sourced from a
        // DB-seeded description_template (rendered before this point) is collapsed to a
        // comma, keeping athlete instructions clean without a DB reseed. En dashes (used
        // in distance/time ranges) are intentionally left alone.
        $instructions = preg_replace('/\s*\x{2014}\s*/u', ', ', $instructions);

        return trim($instructions);
    }

    /**
     * Prepend a warmup sentence and append a cooldown sentence to a structured
     * quality session's instructions (§18.7 display correction). The archetype's
     * description_template carries only the main-set language; the warmup_minutes /
     * cooldown_minutes resolved at generation time are otherwise never told to the
     * athlete, so every quality session read as if it started cold and stopped dead.
     *
     * Scoped to the 10 structured quality archetypes (the warmup-with-strides and
     * warmup-without-strides lists). Self-contained sessions whose own templates
     * already describe a warmup and cooldown (run_walk_intervals, standalone_strides)
     * are excluded so they are never double-wrapped.
     *
     * Must run AFTER renderTemplate()/normalizeInstructionText() and BEFORE
     * appendPaceCitation(), so the cooldown sentence sits before any pace citation:
     *   [Warmup]. [Main-set description]. [Cooldown]. [Pace citation if any].
     */
    /**
     * Prepend a concrete prescription lead line to a templated quality session's
     * description, so the athlete reads the actual numbers (reps, length, effort,
     * recovery), not only the coaching prose. Format:
     *   "N × [length] at [effort], [recovery] between reps." then the prose unchanged.
     *
     * Recovery is resolved through RecoveryModel::resolveSeconds — the SAME shared
     * resolver the watch renderer uses — so the description's recovery and the watch's
     * recovery step are guaranteed to agree (and an explicit coach override wins in both).
     * For distance reps the rep duration is estimated via PaceZones::estimateRepSeconds
     * with the athlete's visible zones, identical to the watch.
     *
     * Scope: the 5 uniform-rep templated archetypes. mixed_distance_repeats and the
     * fartlek ladder already lead with their full sequence (distances + effort +
     * recovery) in their own templates, so they are intentionally not re-led here.
     * The effort phrase uses words only; the numeric pace stays in the end-of-description
     * citation (appendPaceCitation), so pace is never double-cited.
     */
    private static function prependPrescriptionLead(string $instructions, array $instance): string
    {
        $code = (string)($instance['code'] ?? '');
        $p    = $instance['resolved_params'] ?? [];
        $n    = (int)($p['rep_count'] ?? 0);
        $override = (int)($p['recovery_duration_seconds'] ?? 0);
        $model    = (string)($p['recovery_model'] ?? '');

        $lead = '';
        switch ($code) {
            case 'tempo_intervals':
                $repMin = (float)($p['rep_duration_minutes'] ?? 0);
                if ($n < 1 || $repMin <= 0) break;
                $rec = RecoveryModel::resolveSeconds($model ?: 'threshold_standard', (int)round($repMin * 60), $override);
                $lead = $n . ' × ' . self::leadMinutesLabel($repMin) . ' at comfortably hard (tempo) effort, '
                      . '~' . self::leadSecondsLabel($rec) . ' easy jog between reps.';
                break;

            case 'high_volume_time_intervals':
                $work = (int)($p['work_duration_seconds'] ?? 0);
                if ($n < 1 || $work < 1) break;
                $rec = RecoveryModel::resolveSeconds($model ?: 'vo2_standard', $work, $override);
                $lead = $n . ' × ' . self::leadSecondsLabel($work) . ' at a hard, controlled effort, '
                      . '~' . self::leadSecondsLabel($rec) . ' easy jog between reps.';
                break;

            case 'sustained_hill_repeats':
                $durSec = (int)($p['rep_duration_seconds'] ?? 0);
                if ($n < 1 || $durSec < 1) break;
                // hill_standard recovery is a jog back down to the start (full recovery), a
                // defined cue rather than a fixed count — matching the watch's hill renderer.
                $lead = $n . ' × ' . self::leadSecondsLabel($durSec) . ' uphill at a strong, controlled effort, '
                      . 'jog back down to the start between reps.';
                break;

            case 'equal_distance_repeats':
            case 'short_speed_repeats':
                $meters = (int)($p['rep_distance_meters'] ?? 0);
                if ($n < 1 || $meters < 1) break;
                $effort = self::leadWorkSegmentEffort($instance);
                if ($code === 'short_speed_repeats') {
                    $rec  = RecoveryModel::resolveSeconds($model ?: 'speed_standard', 0, $override);
                    $lead = $n . ' × ' . $meters . 'm at a fast, near-sprint effort, '
                          . '~' . self::leadSecondsLabel($rec) . ' full recovery between reps.';
                } else {
                    $repSec = PaceZones::estimateRepSeconds($meters, $effort, self::$paceZones);
                    $rec    = RecoveryModel::resolveSeconds($model ?: 'vo2_standard', $repSec, $override);
                    $lead   = $n . ' × ' . $meters . 'm at ' . self::leadEffortPhrase($effort) . ', '
                            . '~' . self::leadSecondsLabel($rec) . ' easy jog between reps.';
                }
                break;
        }

        if ($lead === '') return $instructions;
        $instructions = trim($instructions);
        return $instructions === '' ? $lead : $lead . ' ' . $instructions;
    }

    /** Effort of the main work segment (matches what the watch renderer reads). */
    private static function leadWorkSegmentEffort(array $instance): string
    {
        $structure = self::resolveStructure($instance);
        foreach (($structure['segments'] ?? []) as $s) {
            if (!is_array($s)) continue;
            if (isset($s['rep_distance_meters'])
                || in_array((string)($s['segment_type'] ?? ''), ['repeats', 'speed_repeats'], true)) {
                return (string)($s['target_effort'] ?? $s['effort'] ?? $s['effort_zone'] ?? '');
            }
        }
        return (string)(($instance['resolved_params'] ?? [])['target_effort'] ?? '');
    }

    /** Human effort phrase for a distance-rep target effort token. */
    private static function leadEffortPhrase(string $effort): string
    {
        $e = strtolower(trim($effort));
        return match (true) {
            in_array($e, ['3k'], true)                 => '3K effort',
            in_array($e, ['5k', 'z5'], true)           => '5K effort',
            in_array($e, ['10k'], true)                => '10K effort',
            in_array($e, ['mile'], true)               => 'mile-race effort',
            in_array($e, ['800', '400', 'speed', 'sprint', 'z6'], true) => 'fast, near-sprint effort',
            in_array($e, ['half_marathon', 'threshold', 'tempo', 'z4'], true) => 'comfortably hard (tempo) effort',
            default                                    => 'a hard, controlled (around 5K) effort',
        };
    }

    /** "14 min" / "12.5 min" from a minutes value. */
    private static function leadMinutesLabel(float $minutes): string
    {
        if (abs($minutes - round($minutes)) < 0.05) return (int)round($minutes) . ' min';
        return rtrim(rtrim(number_format($minutes, 1), '0'), '.') . ' min';
    }

    /** "45 sec" / "90 sec" / "2 min" / "2 min 30 sec" from a seconds value. */
    private static function leadSecondsLabel(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 120 && $seconds % 60 !== 0) return $seconds . ' sec';
        $m = intdiv($seconds, 60);
        $r = $seconds % 60;
        if ($m < 1) return $seconds . ' sec';
        return $r === 0 ? $m . ' min' : $m . ' min ' . $r . ' sec';
    }

    private static function wrapWithWarmupCooldown(
        string $instructions, array $resolvedParams, string $archetypeCode
    ): string {
        $withStrides = in_array($archetypeCode, self::WARMUP_WITH_STRIDES_ARCHETYPES, true);
        $noStrides   = in_array($archetypeCode, self::WARMUP_NO_STRIDES_ARCHETYPES, true);
        if (!$withStrides && !$noStrides) {
            return $instructions; // not a wrapped archetype (easy/long/recovery/run-walk/strides)
        }

        $warmup   = (int)($resolvedParams['warmup_minutes']   ?? 0);
        $cooldown = (int)($resolvedParams['cooldown_minutes'] ?? 0);
        if ($warmup <= 0 || $cooldown <= 0) {
            return $instructions;
        }

        $warmupSentence = $withStrides
            ? "Warm up with {$warmup} minutes of easy running, finishing with 4 × 15-second strides with full recovery between each."
            : "Warm up with {$warmup} minutes of easy running.";
        $cooldownSentence = "Cool down with {$cooldown} minutes of easy running.";

        $main  = trim($instructions);
        $parts = $main === ''
            ? [$warmupSentence, $cooldownSentence]
            : [$warmupSentence, $main, $cooldownSentence];

        return implode(' ', $parts);
    }

    /**
     * Append the pace-zone citation clause to rendered quality instructions when the
     * athlete's zones are visible (§19 item 14). A no-op when self::$paceZones is null
     * (hidden/empty zones) or the archetype is effort-only — so the effort-language
     * output is byte-for-byte unchanged in those cases.
     */
    private static function appendPaceCitation(string $instructions, array $archetype): string
    {
        if (self::$paceZones === null) {
            return $instructions;
        }

        $clause = PaceZones::qualityCitation(
            $archetype['code'] ?? '',
            $archetype['resolved_params'] ?? [],
            self::$paceZones,
            $archetype['resolved_variant']['code'] ?? null,
            self::$planDistance
        );
        if ($clause === null || $clause === '') {
            return $instructions;
        }

        return $instructions === '' ? $clause : rtrim($instructions) . ' ' . $clause;
    }

    /**
     * Post-generation consistency guard. A coherent plan never combines the gentlest
     * run/walk on-ramp (stage-1 run_walk_intervals: 1 min run / 3 min walk) with
     * continuous runs well beyond a beginner's reach (> 45 min). When both appear in
     * one plan the base classification is almost certainly wrong — typically a missing
     * optional input (e.g. a blank longest_recent_run_mins) demoting a capable athlete
     * onto the run/walk path. Raise a coach-facing flag rather than shipping the
     * contradiction silently, so it is caught regardless of the underlying cause.
     *
     * Skips return_to_running, where stage-1 run/walk alongside a later continuous run
     * is the intended progression. Reuses the existing 'plan_rebuild_needed' flag_type
     * (a coach "review / rebuild this plan" signal) to avoid a flag_type ENUM migration;
     * the message states the contradiction and its likely cause explicitly.
     */
    private static function flagClassificationContradiction(
        int $planId, int $athleteId, string $planType, PDO $db
    ): void {
        if ($planType === 'return_to_running') return;

        $s1 = $db->prepare(
            "SELECT COUNT(*) FROM planned_workouts
             WHERE plan_id = ? AND archetype_code = 'run_walk_intervals' AND archetype_variant = 'stage_1'"
        );
        $s1->execute([$planId]);
        if ((int)$s1->fetchColumn() === 0) return;

        // Continuous run (anything that is NOT a run/walk session) longer than 45 min.
        $cont = $db->prepare(
            "SELECT COUNT(*) FROM planned_workouts
             WHERE plan_id = ? AND target_duration > 45
               AND (archetype_code IS NULL OR archetype_code <> 'run_walk_intervals')"
        );
        $cont->execute([$planId]);
        $longest = $db->prepare(
            "SELECT MAX(target_duration) FROM planned_workouts
             WHERE plan_id = ? AND (archetype_code IS NULL OR archetype_code <> 'run_walk_intervals')"
        );
        $longest->execute([$planId]);

        if ((int)$cont->fetchColumn() === 0) return;
        $maxMins = (int)$longest->fetchColumn();

        self::raiseFlag(
            $athleteId, 'plan_rebuild_needed', 'warning',
            'Possible misclassification: this plan mixes a stage-1 run/walk on-ramp (1 min run / '
            . '3 min walk) with continuous runs up to ' . self::durationLabel(max(1, $maxMins))
            . ' — those do not fit the same athlete. The base classification is likely wrong, often '
            . 'because an optional input is blank (check the athlete\'s longest recent run, weekly '
            . 'minutes, and training days). Review before approving; correct the profile and regenerate '
            . 'if the run/walk on-ramp is not appropriate.',
            $db,
            ['plan_id' => $planId, 'reason' => 'stage1_runwalk_with_long_continuous', 'longest_continuous_minutes' => $maxMins]
        );
    }

    private static function validateGeneratedDisplays(int $planId, int $athleteId, PDO $db): void
    {
        $stmt = $db->prepare(
            'SELECT scheduled_date, workout_type, archetype_code, archetype_params,
                    display_title, display_summary, athlete_instructions
             FROM planned_workouts
             WHERE plan_id = ? AND archetype_code IS NOT NULL'
        );
        $stmt->execute([$planId]);

        $issues = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $text = trim(implode(' ', array_filter([
                $row['display_title'] ?? '',
                $row['display_summary'] ?? '',
                $row['athlete_instructions'] ?? '',
            ])));

            $reasons = [];
            if (preg_match('/\{\{\w+\}\}/', $text)) {
                $reasons[] = 'unresolved_template_token';
            }

            $params = json_decode($row['archetype_params'] ?? '{}', true);
            if (!is_array($params)) $params = [];

            if (
                self::qualityDisplayExpectsNumbers((string)($row['workout_type'] ?? ''), (string)($row['archetype_code'] ?? ''), $params)
                && !preg_match('/\d/', $text)
            ) {
                $reasons[] = 'numeric_quality_display_has_no_digits';
            }

            // §19 item 13: a session that carries a warmup or cooldown must surface
            // both in the athlete-facing text. wrapWithWarmupCooldown() adds the
            // sentences for structured quality archetypes; run/walk and strides carry
            // the language in their own templates. A miss means the wrap was skipped.
            $warm = (int)($params['warmup_minutes']   ?? 0);
            $cool = (int)($params['cooldown_minutes'] ?? 0);
            if (
                ($warm > 0 || $cool > 0)
                && (stripos($text, 'warm') === false || stripos($text, 'cool') === false)
            ) {
                $reasons[] = 'missing_warmup_cooldown_text';
            }

            if (!empty($reasons)) {
                $issues[] = [
                    'date' => $row['scheduled_date'],
                    'archetype_code' => $row['archetype_code'],
                    'display_title' => $row['display_title'],
                    'reasons' => $reasons,
                ];
            }
        }

        if (empty($issues)) return;

        $count = count($issues);
        $sample = array_slice($issues, 0, 5);
        // Name the affected workouts (date + title) in the message itself so the
        // flag is actionable without digging into the details JSON.
        $named = [];
        foreach (array_slice($issues, 0, 3) as $i) {
            $when = !empty($i['date']) ? date('M j', strtotime($i['date'])) : 'undated';
            $what = $i['display_title'] ?: $i['archetype_code'];
            $named[] = "{$when} \"{$what}\"";
        }
        $list = implode(', ', $named);
        if ($count > count($named)) {
            $list .= ' and ' . ($count - count($named)) . ' more';
        }
        self::raiseFlag(
            $athleteId,
            'display_generation_incomplete',
            'warning',
            "Plan {$planId} has {$count} workout display(s) with incomplete generated text: {$list}. Review before approval.",
            $db,
            ['plan_id' => $planId, 'issues' => $sample],
            false
        );
    }

    private static function qualityDisplayExpectsNumbers(string $workoutType, string $code, array $params): bool
    {
        $qualityTypes = ['interval', 'tempo', 'hill', 'fartlek', 'speed'];
        $numericKeys = [
            'rep_count', 'sprint_count', 'round_count', 'circuit_count',
            'continuous_work_minutes', 'rep_duration_seconds', 'rep_duration_minutes',
            'work_duration_seconds', 'recovery_duration_seconds', 'rep_distance_meters',
            'rep_distance_miles', 'quality_volume_meters',
        ];
        $numericArchetypes = [
            'equal_distance_repeats', 'mixed_distance_repeats', 'short_speed_repeats',
            'sustained_hill_repeats', 'hill_sprints', 'tempo_intervals',
            'continuous_progression_tempo', 'high_volume_time_intervals',
            'structured_fartlek_ladder', 'plyometric_hill_circuits',
        ];

        if (!in_array($workoutType, $qualityTypes, true) && !in_array($code, $numericArchetypes, true)) {
            return false;
        }

        foreach ($numericKeys as $key) {
            if (isset($params[$key]) && $params[$key] !== '' && $params[$key] !== null) {
                return true;
            }
        }

        return in_array($code, $numericArchetypes, true);
    }

    /**
     * Render all {{token}} placeholders in the structure_template (deep, recursive).
     * Returns the resolved structure array or null if there is no structure template.
     */
    private static function resolveStructure(array $archetype): ?array
    {
        $tpl = $archetype['structure_template'] ?? null;
        if (!$tpl) return null;

        $params      = $archetype['resolved_params'] ?? [];
        $variantCode = $archetype['resolved_variant']['code'] ?? 'standard';
        $variantName = $archetype['resolved_variant']['name'] ?? '';

        $tokens = array_merge($params, [
            'variant_name'           => $variantName,
            'variant'                => $variantCode,
            'generated_workout_title'=> $archetype['name'] ?? $archetype['metadata']['name'] ?? '',
        ]);

        return self::deepRenderTokens($tpl, $tokens);
    }

    /** Recursively replace {{token}} in all string leaves of a nested array. */
    private static function deepRenderTokens(mixed $node, array $tokens): mixed
    {
        if (is_string($node)) {
            // If the entire string is a single {{token}}, return the typed value
            if (preg_match('/^\{\{(\w+)\}\}$/', $node, $m)) {
                return array_key_exists($m[1], $tokens) ? $tokens[$m[1]] : $node;
            }
            return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($tokens) {
                if (!array_key_exists($m[1], $tokens)) return $m[0];
                $v = $tokens[$m[1]];
                return is_array($v) ? json_encode($v) : (string)$v;
            }, $node);
        }
        if (is_array($node)) {
            $out = [];
            foreach ($node as $k => $v) {
                $out[$k] = self::deepRenderTokens($v, $tokens);
            }
            return $out;
        }
        return $node;
    }

    /**
     * Map {{mapped_effort}} token for archetypes that use effort_mapping.
     * Returns an effort zone string or null if no effort mapping is defined.
     */
    private static function mapEffort(
        array $archetype, string $phase, string $goalDistance, string $classification
    ): ?string {
        $model = $archetype['effort_mapping']['model'] ?? null;
        if ($model === null) return null;

        if ($model === 'goal_distance_adjusted') {
            return match($goalDistance) {
                '5K'     => $phase === 'peak' ? '5K' : '10K',
                '10K'    => $phase === 'peak' ? '10K' : 'threshold',
                'half'   => $phase === 'peak' ? 'half_marathon' : 'threshold',
                'marathon'=> 'marathon',
                default  => 'threshold',
            };
        }

        if ($model === 'neuromuscular') return 'near_maximal_but_controlled';

        return null;
    }

    // ── Constraints & history ────────────────────────────────────────────────

    /**
     * Build the archetype eligibility constraints map from an athlete profile.
     * Keys must match the archetype selection.requires[] and excludes[] entries.
     */
    private static function buildConstraints(array $profile): array
    {
        $hillAccess = !empty($profile['hill_access']) && $profile['hill_access'] !== 'none';
        // track_access is the athlete's own enum ('yes'/'no'/'road_reps_ok', schema default
        // 'road_reps_ok'); the selector blocks track_requirement='required' archetypes only
        // when it is 'no'. It must never be derived from track_field_background, which is a
        // separate tinyint(1) gate consumed via selection.requires[] entries.
        $trackAccess = $profile['track_access'] ?? null;
        if (!in_array($trackAccess, ['yes', 'no', 'road_reps_ok'], true)) {
            $trackAccess = 'road_reps_ok';
        }

        return [
            'track_access'                        => $trackAccess,
            'track_field_background'              => !empty($profile['track_field_background']),
            'hill_access'                         => $hillAccess,
            'plyometric_clearance'                => !empty($profile['plyometric_clearance']),
            'hilly_terrain_or_substitute_route'   => $hillAccess,
            'short_steep_hill_or_safe_substitute' => $hillAccess,
            'excludes'                            => [], // context tags (none by default)
        ];
    }

    /**
     * Pre-load the hard-block window of planned workout archetypes for anti-repeat checks.
     * Window length comes from engine_settings same_instance_hard_block_days (default 28).
     * Returns: ['signatures' => [sig => [dates]], 'codes' => [code => [dates]]]
     */
    private static function loadAntiRepeatHistory(int $athleteId, PDO $db): array
    {
        $history  = ['signatures' => [], 'codes' => []];
        $hardDays = self::settings()['same_instance_hard_block_days'] ?? 28;
        $cutoff   = date('Y-m-d', strtotime("-{$hardDays} days"));

        try {
            $stmt = $db->prepare(
                'SELECT pw.archetype_code, pw.instance_signature, pw.scheduled_date
                 FROM planned_workouts pw
                 JOIN training_plans tp ON tp.id = pw.plan_id
                 WHERE pw.athlete_id = ? AND pw.scheduled_date >= ?
                   AND pw.archetype_code IS NOT NULL
                   AND tp.status IN (\'pending_approval\', \'active\')'
            );
            $stmt->execute([$athleteId, $cutoff]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = $row['archetype_code'] ?? null;
                $sig  = $row['instance_signature'] ?? null;
                $date = $row['scheduled_date'];

                if ($code) $history['codes'][$code][]  = $date;
                if ($sig)  $history['signatures'][$sig][] = $date;
            }
        } catch (PDOException $e) {
            // Columns may not exist pre-migration — return empty history
        }

        return $history;
    }

    // ── Classification & phase calculations ──────────────────────────────────

    /**
     * Base classification from the athlete's volume profile.
     *
     * CORE PRINCIPLE: a blank/optional input must never collapse an athlete to a worse
     * classification than their populated data supports. Each axis (runs_per_week,
     * weekly_minutes, long_run_minutes) is tested ONLY when it carries real data
     * (non-null, > 0). A missing axis is EXCLUDED from the test, not scored as 0 —
     * otherwise an unanswered "longest recent run" question pins an otherwise
     * well-trained athlete to 'insufficient' and onto the run/walk on-ramp.
     *
     * A tier is met when every PRESENT axis clears that tier's threshold, anchored on
     * weekly_minutes (we never promote above 'insufficient' with no volume signal at
     * all). A sanity floor then guarantees that volume AND frequency both independently
     * clearing 'workable' can never read 'insufficient', whatever the long-run axis says
     * (or doesn't). Genuinely low *populated* volume/frequency still classifies low —
     * the change is specifically that MISSING ≠ failing; low-but-present still fails.
     */
    private static function classifyAthlete(array $profile, string $distance): string
    {
        $dist       = self::normalizeDistance($distance);
        $thresholds = self::CLASSIFICATION[$dist] ?? self::CLASSIFICATION['5K'];

        $axes = [
            'runs_per_week'    => self::presentAxis($profile['training_days_per_week']  ?? null),
            'weekly_minutes'   => self::presentAxis($profile['current_weekly_minutes']  ?? null),
            'long_run_minutes' => self::presentAxis($profile['longest_recent_run_mins'] ?? null),
        ];

        if (self::meetsTier($axes, $thresholds['well_trained'])) return 'well_trained';
        if (self::meetsTier($axes, $thresholds['workable']))     return 'workable';

        // Sanity floor: volume AND frequency both independently clearing 'workable'
        // cannot be 'insufficient' — this catches the case where a present-but-low
        // long-run axis would otherwise demote a high-volume, frequent runner.
        $wb = $thresholds['workable'];
        if ($axes['weekly_minutes'] !== null && $axes['runs_per_week'] !== null
            && $axes['weekly_minutes'] >= $wb['weekly_minutes']
            && $axes['runs_per_week']  >= $wb['runs_per_week']) {
            return 'workable';
        }

        return 'insufficient';
    }

    /**
     * Normalize a classification axis: real data (non-null, > 0) → int; blank/zero → null
     * (an unanswered optional field is absent, not a value of zero).
     */
    private static function presentAxis(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') return null;
        $v = (int)$raw;
        return $v > 0 ? $v : null;
    }

    /**
     * Whether the athlete meets a classification tier. Missing axes are excluded; every
     * PRESENT axis must clear its threshold. Anchored on weekly_minutes: with no volume
     * signal at all we never assert a tier above 'insufficient' (the safe default for a
     * data-less profile, and for a genuine beginner whose populated axes fall short).
     */
    private static function meetsTier(array $axes, array $threshold): bool
    {
        if ($axes['weekly_minutes'] === null) return false;

        foreach ($axes as $name => $value) {
            if ($value === null) continue;                       // missing axis excluded
            if ($value < (int)($threshold[$name] ?? 0)) return false; // present axis must clear the bar
        }
        return true;
    }

    private static function calculatePhases(int $totalWeeks, array $props): array
    {
        $remainder = 1.0 - array_sum($props);
        $adjusted  = $props;
        $adjusted['base'] += max(0.0, $remainder);

        $phases = [];
        $week   = 1;
        foreach (['base', 'build', 'peak', 'taper'] as $ph) {
            $len = max(1, (int)round($totalWeeks * ($adjusted[$ph] ?? 0)));
            $phases[$ph] = ['start_week' => $week, 'end_week' => $week + $len - 1];
            $week += $len;
        }

        // Distribute rounding slack to base
        $allocated = 0;
        foreach ($phases as $ph) {
            $allocated += $ph['end_week'] - $ph['start_week'] + 1;
        }
        if ($allocated < $totalWeeks) {
            $phases['base']['end_week'] += ($totalWeeks - $allocated);
            $w = $phases['base']['end_week'] + 1;
            foreach (['build', 'peak', 'taper'] as $ph) {
                $len = $phases[$ph]['end_week'] - $phases[$ph]['start_week'];
                $phases[$ph]['start_week'] = $w;
                $phases[$ph]['end_week']   = $w + $len;
                $w = $phases[$ph]['end_week'] + 1;
            }
        }

        return $phases;
    }

    private static function getPhaseForWeek(int $week, array $phases, int $totalWeeks): string
    {
        if ($week >= $totalWeeks - 1) return 'taper';

        foreach (['base', 'build', 'peak', 'taper'] as $ph) {
            if (isset($phases[$ph]) &&
                $week >= $phases[$ph]['start_week'] &&
                $week <= $phases[$ph]['end_week']) {
                return $ph;
            }
        }
        return 'base';
    }

    // ── DB helpers ───────────────────────────────────────────────────────────

    private static function loadProfile(int $athleteId, PDO $db): ?array
    {
        $stmt = $db->prepare('SELECT * FROM athlete_profiles WHERE athlete_id = ? LIMIT 1');
        $stmt->execute([$athleteId]);
        return $stmt->fetch() ?: null;
    }

    /** The athlete's stored timezone (falls back to the default when unset/invalid). */
    private static function athleteTimezone(int $athleteId, PDO $db): string
    {
        $stmt = $db->prepare('SELECT u.timezone FROM athletes a JOIN users u ON u.id = a.user_id WHERE a.id = ? LIMIT 1');
        $stmt->execute([$athleteId]);
        $tz = $stmt->fetchColumn();
        return Timezone::isValid($tz) ? $tz : Timezone::DEFAULT_TZ;
    }

    /**
     * plan_start_date = "tomorrow" from the ATHLETE's perspective. A UTC server
     * midnight may be a different calendar day in the athlete's local time, so the
     * start date is computed in their timezone (engine spec §5 timezone model).
     */
    private static function planStartDate(int $athleteId, PDO $db): string
    {
        return Timezone::dateInZone(self::athleteTimezone($athleteId, $db), '+1 day');
    }

    private static function archivePreviousPlans(int $athleteId, PDO $db, array $preserveWorkoutIds = []): void
    {
        // Capture the plans being archived so their Intervals.icu calendar events can
        // be deleted (otherwise old workouts linger on the athlete's watch). Carry-over
        // workout ids are spared so their events survive for the new plan.
        $sel = $db->prepare(
            'SELECT id FROM training_plans
             WHERE athlete_id = ? AND status IN ("active", "pending_approval")'
        );
        $sel->execute([$athleteId]);
        $planIds = $sel->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $db->prepare(
            'UPDATE training_plans SET status = "archived", archived_at = NOW()
             WHERE athlete_id = ? AND status IN ("active", "pending_approval")'
        )->execute([$athleteId]);

        $db->prepare(
            'UPDATE plan_approval_queue SET status = "rejected"
             WHERE athlete_id = ? AND status = "pending"'
        )->execute([$athleteId]);

        foreach ($planIds as $pid) {
            self::deleteArchivedPlanEvents((int)$pid, $db, $preserveWorkoutIds);
        }
    }

    /**
     * Capture the carry-over set for a regen over an ACTIVE prior plan: every row in an
     * athlete-EXPOSED whole week (≥1 visible_to_athlete row that week), plus any
     * coach_locked row, restricted to the new plan's forward span (>= tomorrow). Returns
     * null when there is nothing to preserve (no active prior plan, or no exposed weeks),
     * in which case the regen behaves exactly as before.
     *
     * @return array{prior_plan_id:int, row_ids:int[], dates:string[], signatures:array, codes:array}|null
     */
    private static function capturePreservation(int $athleteId, PDO $db): ?array
    {
        $planStmt = $db->prepare(
            "SELECT id FROM training_plans WHERE athlete_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1"
        );
        $planStmt->execute([$athleteId]);
        $priorPlanId = (int)($planStmt->fetchColumn() ?: 0);
        if ($priorPlanId < 1) return null;

        $planStart = self::planStartDate($athleteId, $db); // tomorrow (athlete tz)

        // Forward-span rows of the prior plan (anything the new plan could overlap).
        $rowsStmt = $db->prepare(
            "SELECT id, scheduled_date, visible_to_athlete, coach_locked, instance_signature, archetype_code
             FROM planned_workouts
             WHERE plan_id = ? AND scheduled_date >= ?"
        );
        $rowsStmt->execute([$priorPlanId, $planStart]);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return null;

        // Mondays of weeks that contain >=1 visible row (the exposed whole weeks).
        $exposedMondays = [];
        foreach ($rows as $r) {
            if ((int)$r['visible_to_athlete'] === 1) {
                $exposedMondays[self::mondayOf((string)$r['scheduled_date'])] = true;
            }
        }

        $rowIds = [];
        $dates  = [];   // dates whose freshly-generated rows must be removed (carried instead)
        $sigs   = [];
        $codes  = [];

        // Carried rows: every row in an exposed week, plus any coach_locked row.
        foreach ($rows as $r) {
            $date     = (string)$r['scheduled_date'];
            $inExposed = isset($exposedMondays[self::mondayOf($date)]);
            $locked    = (int)$r['coach_locked'] === 1;
            if (!$inExposed && !$locked) continue;

            $rowIds[]      = (int)$r['id'];
            $dates[$date]  = true;
            if (!empty($r['instance_signature'])) $sigs[(string)$r['instance_signature']][] = $date;
            if (!empty($r['archetype_code']))     $codes[(string)$r['archetype_code']][]   = $date;
        }

        // Exposed weeks are carried as WHOLE weeks: clear every fresh row across the full
        // Mon–Sun span of each exposed week (>= planStart), so a date with no prior row
        // (a seen rest day) carries as rest rather than keeping a fresh workout.
        foreach (array_keys($exposedMondays) as $monday) {
            for ($i = 0; $i < 7; $i++) {
                $d = date('Y-m-d', strtotime($monday . ' +' . $i . ' days'));
                if ($d >= $planStart) $dates[$d] = true;
            }
        }

        if (!$rowIds) return null; // active prior plan but nothing exposed/locked → full regen

        return [
            'prior_plan_id' => $priorPlanId,
            'row_ids'       => $rowIds,
            'dates'         => array_keys($dates),
            'signatures'    => $sigs,
            'codes'         => $codes,
        ];
    }

    /**
     * Swap the carried rows into the freshly-generated plan: delete the fresh rows on the
     * carried dates, then MOVE the preserved prior rows into the new plan (keeping their id,
     * intervals_event_id, content, coach_locked, and visibility) marked carried. Moving
     * (not copying) keeps the srf_{id} Intervals event intact — never delete+recreate.
     */
    private static function applyPreservation(int $newPlanId, array $preserve, PDO $db): void
    {
        $endStmt = $db->prepare('SELECT plan_end_date FROM training_plans WHERE id = ? LIMIT 1');
        $endStmt->execute([$newPlanId]);
        $planEnd = (string)($endStmt->fetchColumn() ?: '');
        if ($planEnd === '') return;

        // Carried rows / dates that actually fall within the new plan's span.
        $rowDateStmt = $db->prepare('SELECT scheduled_date FROM planned_workouts WHERE id = ? LIMIT 1');
        $carryIds = [];
        foreach ($preserve['row_ids'] as $id) {
            $rowDateStmt->execute([(int)$id]);
            $d = (string)($rowDateStmt->fetchColumn() ?: '');
            if ($d !== '' && $d <= $planEnd) $carryIds[] = (int)$id;
        }
        $dates = array_values(array_filter($preserve['dates'], static fn($d) => $d <= $planEnd));
        if (!$carryIds || !$dates) return;

        // 1) Remove the freshly-generated rows on the carried dates.
        $place = implode(',', array_fill(0, count($dates), '?'));
        $db->prepare("DELETE FROM planned_workouts WHERE plan_id = ? AND scheduled_date IN ($place)")
           ->execute(array_merge([$newPlanId], $dates));

        // 2) Move the preserved prior rows into the new plan, marked carried.
        $idPlace = implode(',', array_fill(0, count($carryIds), '?'));
        $db->prepare(
            "UPDATE planned_workouts
             SET plan_id = ?, carried_over_from_plan_id = ?, carried_over_at = NOW()
             WHERE id IN ($idPlace)"
        )->execute(array_merge([$newPlanId, (int)$preserve['prior_plan_id']], $carryIds));
    }

    /** The Monday (Y-m-d) of the ISO week containing $date. */
    private static function mondayOf(string $date): string
    {
        $t = strtotime($date);
        if ($t === false) return $date;
        $dow = (int)date('N', $t); // 1=Mon..7=Sun
        return date('Y-m-d', $t - ($dow - 1) * 86400);
    }

    /**
     * Best-effort removal of an archived plan's Intervals.icu calendar events. Lazily
     * loads IntervalsService so this works from every regeneration path (web + cron),
     * and is a silent no-op when the athlete isn't connected.
     */
    private static function deleteArchivedPlanEvents(int $planId, PDO $db, array $excludeWorkoutIds = []): void
    {
        if (!class_exists('IntervalsService')) {
            $crypto  = __DIR__ . '/../Crypto.php';
            $service = __DIR__ . '/../IntervalsService.php';
            if (is_file($crypto))  require_once $crypto;
            if (is_file($service)) require_once $service;
        }
        if (class_exists('IntervalsService')) {
            try {
                IntervalsService::deleteEventsForPlan($planId, $db, $excludeWorkoutIds);
            } catch (\Throwable $e) {
                error_log('PlanGenerator::deleteArchivedPlanEvents: ' . $e->getMessage());
            }
        }
    }

    private static function createPlanRecord(
        int $athleteId, string $planType, string $startDate, string $endDate,
        ?string $raceDate, string $trigger, PDO $db
    ): int {
        $db->prepare(
            'INSERT INTO training_plans
             (athlete_id, status, plan_start_date, plan_end_date,
              goal_race_date, generated_at, generation_trigger, plan_type)
             VALUES (?, "pending_approval", ?, ?, ?, NOW(), ?, ?)'
        )->execute([$athleteId, $startDate, $endDate, $raceDate, $trigger, $planType]);

        return (int)$db->lastInsertId();
    }

    private static function createApprovalQueueEntry(
        int $planId, int $athleteId, string $trigger, PDO $db
    ): void {
        $db->prepare(
            'INSERT INTO plan_approval_queue
             (plan_id, athlete_id, requested_at, request_reason, status)
             VALUES (?, ?, NOW(), ?, "pending")'
        )->execute([$planId, $athleteId, $trigger]);

        // Notify the athlete's coach that a plan is waiting for review (always-on).
        if (class_exists('Notifications')) {
            try {
                $ctx = Notifications::athleteContext($athleteId);
                if ($ctx['coach_user_id']) {
                    Notifications::send($ctx['coach_user_id'], 'plan_pending_approval', [
                        'athlete_id'   => $athleteId,
                        'athlete_name' => $ctx['athlete_name'],
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('PlanGenerator: plan_pending_approval notify failed: ' . $e->getMessage());
            }
        }
    }

    private static function raiseFlag(
        int $athleteId, string $flagType, string $severity, string $message, PDO $db,
        ?array $details = null, bool $dedupeOpen = true
    ): void {
        if ($dedupeOpen) {
            $check = $db->prepare(
                'SELECT id FROM engine_flags WHERE athlete_id = ? AND flag_type = ? AND status = "open" LIMIT 1'
            );
            $check->execute([$athleteId, $flagType]);
            if ($check->fetch()) return;
        }

        $db->prepare(
            'INSERT INTO engine_flags
             (athlete_id, flag_type, severity, flag_date, details, message, status, created_at)
             VALUES (?, ?, ?, CURDATE(), ?, ?, "open", NOW())'
        )->execute([$athleteId, $flagType, $severity, $details ? json_encode($details) : null, $message]);

        // Notify the coach (critical/warning are on by default; info is opt-in).
        if (class_exists('Notifications')) {
            Notifications::notifyFlag($athleteId, $severity, $message);
        }
    }

    // ── Utility ──────────────────────────────────────────────────────────────

    private static function usesCalendarAlignedCodeWeeks(string $planType): bool
    {
        return in_array($planType, ['development_plan', 'maintenance_plan', 'recovery_block'], true);
    }

    private static function hasCalendarAlignedCodeWeeks(string $planType, string $startDate, ?string $endDate): bool
    {
        if (!self::usesCalendarAlignedCodeWeeks($planType)) return false;
        if ((int)date('N', strtotime($startDate)) === 1) return true;
        if ($endDate === null || $endDate === '') return false;
        return (int)date('N', strtotime($endDate)) === 7;
    }

    private static function firstMondayOnOrAfter(string $date): string
    {
        $ts = strtotime($date);
        $isoDow = (int)date('N', $ts); // 1=Mon ... 7=Sun
        $offset = (8 - $isoDow) % 7;
        return date('Y-m-d', strtotime("+{$offset} days", $ts));
    }

    private static function codeWeekEndDate(string $codeWeekStart, int $totalWeeks): string
    {
        return date('Y-m-d', strtotime($codeWeekStart . " +{$totalWeeks} weeks -1 day"));
    }

    private static function normalizeDistance(string $d): string
    {
        $d = strtolower(trim($d));
        return match(true) {
            in_array($d, ['half', 'hm', 'half marathon', '21k'])          => 'half',
            in_array($d, ['marathon', 'm', '42k', 'full', 'full marathon'])=> 'marathon',
            in_array($d, ['10k', '10km', '10 km'])                         => '10K',
            in_array($d, ['5k', '5km', '5 km'])                            => '5K',
            // Ultra distances (canonical keys + common labels). Time-based throughout.
            in_array($d, ['50k', '50km', '50 km', '50k ultra'])                       => '50k',
            in_array($d, ['50_miler', '50 miler', '50-mile ultra', '50 mile', '50mi'])=> '50_miler',
            in_array($d, ['100k', '100km', '100 km', '100k ultra'])                   => '100k',
            in_array($d, ['100_miler', '100 miler', '100-mile ultra', '100 mile', '100mi']) => '100_miler',
            // Mile / 1500m (a Hyrox goal is stored as 'mile' with is_hyrox=1).
            in_array($d, ['mile', '1 mile', '1mile', '1500', '1500m', 'mile / 1500m', 'hyrox']) => 'mile',
            default                                                         => '5K',
        };
    }

    private static function getCrossTrainDescription(array $profile): string
    {
        if (($profile['cross_training_bike'] ?? 'none') !== 'none') {
            return 'Easy cycling at a comfortable effort, 30 minutes. Low impact active recovery.';
        }
        if (($profile['cross_training_elliptical'] ?? 'none') !== 'none') {
            return 'Easy elliptical at a comfortable effort, 30 minutes. Low impact active recovery.';
        }
        if (!empty($profile['cross_training_pool'])) {
            return 'Easy pool running or swimming at a comfortable effort, 25 to 30 minutes.';
        }
        return 'Rest day or easy walk. Keep it gentle and restorative.';
    }
}

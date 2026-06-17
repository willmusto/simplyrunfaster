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
    // continuous_progression_tempo (its description_template is "Warm up N min easy.
    // {{progression_instruction}} Cool down N min easy.").
    const WARMUP_WITH_STRIDES_ARCHETYPES = [
        'equal_distance_repeats', 'short_speed_repeats', 'sustained_hill_repeats',
        'hill_sprints', 'plyometric_hill_circuits',
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
        ?string $ultraDistance = null
    ): int {
        // Ultra distances override the per-distance quality cadence (ultra spec Part 10).
        if ($ultraDistance !== null && in_array($ultraDistance, self::ULTRA_DISTANCES, true)) {
            if ($isCutback) return 1;
            return match ($ultraDistance) {
                '50k'       => $weekNumber % 2 === 0 ? 2 : 1, // ~1.5/wk (development-style)
                '50_miler'  => 1,                              // 1/wk throughout
                '100k'      => 1,                              // 1/wk base/build; peak 0–1 (back-to-back may strip)
                '100_miler' => $phase === 'peak' ? 0 : 1,      // 0–1 base/build, 0 peak
                default     => 1,
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
        return in_array($d, self::ULTRA_DISTANCES, true) ? 'marathon' : $d;
    }

    /** Cycle-length [min, max] in weeks (ultra-aware). */
    private static function cycleWeekBounds(string $distance): array
    {
        $d = self::normalizeDistance($distance);
        if (isset(self::ULTRA_CYCLE_WEEKS[$d])) return self::ULTRA_CYCLE_WEEKS[$d];
        return [self::MIN_CYCLE[$d] ?? 8, self::MAX_PLAN_WEEKS];
    }

    /** Phase proportions for distance+classification. 100-miler expands base, shortens taper. */
    private static function phaseProportionsFor(string $distance, string $classification): array
    {
        if (self::normalizeDistance($distance) === '100_miler') {
            // Base 35% + 5% remainder (added to base in calculatePhases) → 40%;
            // build 30%; peak 20%; taper 10% (capped to 2 weeks below).
            return ['base' => 0.35, 'build' => 0.30, 'peak' => 0.20, 'taper' => 0.10];
        }
        return self::PHASE_PROPORTIONS[$classification] ?? self::PHASE_PROPORTIONS['workable'];
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

        $cue = 'Focus on time on feet rather than pace. Walk the uphills when needed — power hiking '
             . 'is a legitimate race strategy and saves your legs for the downhills.';

        $powerHike = ($d !== '50k') || ($phase === 'peak');
        if ($powerHike) {
            $cue .= ' On any significant climbs, transition to a strong power hike rather than running. '
                  . 'Focus on maintaining consistent effort, not pace. Practice this in training — '
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

    /** Quality archetype codes to exclude for an ultra (100-miler favours aerobic threshold work). */
    private static function ultraQualityExcludeCodes(string $distance): array
    {
        if (self::normalizeDistance($distance) === '100_miler') {
            // Prefer tempo_intervals / structured_fartlek_ladder over track-style speed work.
            return ['equal_distance_repeats', 'short_speed_repeats', 'high_volume_time_intervals'];
        }
        return [];
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
    public static function generate(int $athleteId, string $trigger = 'onboarding'): ?int
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

        $planType = $profile['plan_type'] ?? 'development_plan';

        // Raise limited development flag for 3-day-per-week athletes (info, not blocking).
        if (!in_array($planType, ['recovery_block', 'return_to_running'])) {
            if ((int)($profile['training_days_per_week'] ?? 0) === 3) {
                self::raiseFlag($athleteId, 'limited_development_opportunity', 'info',
                    '3 days per week can support consistency, but it limits improvement and development potential.', $db);
            }
        }

        self::archivePreviousPlans($athleteId, $db);

        $selector          = new ArchetypeSelector($db);
        $antiRepeatHistory = self::loadAntiRepeatHistory($athleteId, $db);

        $planId = match ($planType) {
            'race_cycle'        => self::generateRaceCycle($athleteId, $profile, $trigger, $db, $selector, $antiRepeatHistory),
            'return_to_running' => self::generateReturnToRunning($athleteId, $profile, $trigger, $db),
            'maintenance_plan'  => self::generateMaintenancePlan($athleteId, $profile, $trigger, $db, $selector, $antiRepeatHistory),
            'recovery_block'    => self::generateRecoveryBlock($athleteId, $profile, $trigger, $db),
            default             => self::generateDevelopmentPlan($athleteId, $profile, $trigger, $db, $selector, $antiRepeatHistory),
        };

        if ($planId) {
            self::ensurePlanStartEasyRun($planId, $athleteId, $profile, $db, $selector);
            self::validateGeneratedDisplays($planId, $athleteId, $db);
            self::createApprovalQueueEntry($planId, $athleteId, $trigger, $db);
        }

        return $planId;
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
            return null;
        }

        $rawDistance    = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');
        $classification = self::classifyAthlete($profile, $rawDistance);
        // Archetypes/pace maps key on marathon for ultras (ultra spec); classification keeps the real key.
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

        if (!$raceDate) {
            return self::generateDevelopmentPlan($athleteId, $profile, $trigger, $db, $selector, $antiRepeatHistory);
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
        $longestRun     = max(30, (int)($profile['longest_recent_run_mins'] ?? 60));
        $buildBase      = self::resolveStartingWeeklyMins($athleteId, $profile, $trigger, $currentMins, $peakCeiling, $db);

        $planId         = self::createPlanRecord($athleteId, 'race_cycle', $startDate, $raceDate, $raceDate, $trigger, $db);
        $maxLongRun     = $longestRun;
        $constraints    = self::buildConstraints($profile);

        // Trail ultras: a one-time info reminder for the coach (Part 12 / Part 15).
        if ($isUltra && $surface === 'trail') {
            self::raiseFlag(
                $athleteId, 'ultra_surface_reminder', 'info',
                'This athlete is training for a trail ultra. Consider scheduling one night run in peak '
                . 'phase to simulate race conditions. Coordinate timing and safety with the athlete directly.',
                $db, ['plan_id' => $planId, 'distance' => $distance], false
            );
        }

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $phase         = self::getPhaseForWeek($week, $phases, $totalWeeks);
            $weekInPhase   = $week - ($phases[$phase]['start_week'] ?? 1) + 1;
            $isCutback     = self::isCutbackWeek($distance, $week, $phase);
            $isRaceWeek    = ($week === $totalWeeks);
            $isPreRaceWeek = ($week === $totalWeeks - 1 && $totalWeeks > 2);

            // Per-week back-to-back flag (ultra only); threaded to schedule + insertion.
            if ($ultra !== null) {
                $ultra['back_to_back'] =
                    self::ultraBackToBackWeek($distance, $week, $phase, $isCutback, $phases);
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

            $weekStart  = date('Y-m-d', strtotime($startDate . ' +' . (($week - 1) * 7) . ' days'));
            $schedule   = self::buildDaySchedule($profile, $phase, $weeklyMins, $isRaceWeek, $isPreRaceWeek, $athleteId, $db, 'race_cycle', $week, $isCutback, $ultra);
            $maxLongRun = self::insertWeekWorkouts(
                $planId, $athleteId, $weekStart, $endDate,
                $schedule, $phase, $selDist, $classification, 'race_cycle',
                $weeklyMins, $maxLongRun, $constraints, $db, $selector, $antiRepeatHistory,
                null, null, $ultra
            );
        }

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

        $planId         = self::createPlanRecord($athleteId, 'development_plan', $startDate, $endDate, null, $trigger, $db);
        $maxLongRun     = max(30, (int)($profile['longest_recent_run_mins'] ?? 60));
        $constraints    = self::buildConstraints($profile);
        $goalDist       = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');
        $classification = self::classifyAthlete($profile, $goalDist);

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

            $weekStart  = date('Y-m-d', strtotime($codeWeekStart . ' +' . (($week - 1) * 7) . ' days'));
            $schedule   = self::buildDaySchedule($profile, 'base', $weeklyMins, false, false, $athleteId, $db, 'development_plan', $week, $isCutback);
            // Capture code-week-1's pattern/volume so the lead-in can mirror it (below).
            // $maxLongRun here is the pre-week-1 value, reproducing week 1's long-run scale.
            if ($week === 1) {
                $leadInSchedule   = $schedule;
                $leadInMins       = $weeklyMins;
                $leadInMaxLongRun = $maxLongRun;
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
        self::insertLeadInWorkouts(
            $planId, $athleteId, $startDate, $codeWeekStart, $endDate,
            $leadInSchedule ?? [], $leadInMins ?? 0, 'base',
            $goalDist, $classification, 'development_plan',
            $leadInMaxLongRun ?? $maxLongRun, $constraints, $profile,
            $db, $selector, $leadInHistory
        );

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
        $goalDist    = self::normalizeDistance($profile['goal_race_distance'] ?? 'marathon');
        $classification = self::classifyAthlete($profile, $goalDist);

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
     * Return-to-running: a STATIC initial rolling window (next 10 days) at run/walk
     * stage 1. Run days use the run_walk_intervals archetype (stage 1) on an
     * every-other-day cadence, capped by the athlete's training_days_per_week as an
     * UPPER BOUND and never on must-off days. Non-run days are low-impact cross-
     * training (if equipment) or rest, both carrying a coach-drill note (rehab
     * Phases I–III are handled by the coach off-platform).
     *
     * rtr_current_stage is set to 1 at creation. plan_end_date is the window end
     * (start + 9 days); the adaptive stage-progression follow-on (engine spec §19
     * item 6) extends the plan as the athlete advances through the stages.
     */
    private static function generateReturnToRunning(
        int $athleteId, array $profile, string $trigger, PDO $db
    ): ?int {
        $selector = new ArchetypeSelector($db);

        $stage      = 1;
        $windowDays = 10;
        $startDate  = self::planStartDate($athleteId, $db); // "tomorrow" in the athlete's timezone
        $endDate    = date('Y-m-d', strtotime($startDate . ' +' . ($windowDays - 1) . ' days'));

        $planId = self::createPlanRecord($athleteId, 'return_to_running', $startDate, $endDate, null, $trigger, $db);
        $db->prepare('UPDATE training_plans SET rtr_current_stage = ? WHERE id = ?')->execute([$stage, $planId]);

        $cap          = max(1, min(7, (int)($profile['training_days_per_week'] ?? 3)));
        $mustOff      = json_decode($profile['must_off_days'] ?? '[]', true) ?: [];
        $hasEquipment = self::hasCrossTrainingEquipment($profile);
        $crossDesc    = self::getCrossTrainDescription($profile);
        $drillNote    = self::RTR_DRILL_NOTE;

        $insert = self::rtrInsertStatement($db);

        $runDaysUsed   = 0;
        $lastRunOffset = -2; // allow day 0 to be a run day (every-other-day spacing)

        for ($d = 0; $d < $windowDays; $d++) {
            $date = date('Y-m-d', strtotime($startDate . " +{$d} days"));
            $dow  = (int)date('w', strtotime($date));

            $isRunDay = !in_array($dow, $mustOff, true)
                && $runDaysUsed < $cap
                && ($d - $lastRunOffset) >= 2;

            if ($isRunDay) {
                $instance = self::resolveRunWalkStage($selector, $stage, 0, 'base', '5K', 'insufficient');
                if ($instance !== null) {
                    self::insertResolvedWorkout($insert, $planId, $athleteId, $date, 'easy', $instance);
                    $runDaysUsed++;
                    $lastRunOffset = $d;
                    continue;
                }
            }

            // Non-run day: cross-train (if equipment) or rest, both with the drill note.
            self::insertReturnToRunningOffDay($insert, $planId, $athleteId, $date, $hasEquipment, $crossDesc, $drillNote);
        }

        return $planId;
    }

    /** Coach-drill reminder appended to every return-to-running off day. */
    const RTR_DRILL_NOTE = ' Complete your coach-provided mobility and strength drills (rehab Phases I–III) on this day as well.';

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
     * Insert a return-to-running off day: low-impact cross-training when the
     * athlete has equipment, otherwise a gentle rest day. Both carry the coach
     * drill note (rehab Phases I–III are handled by the coach off-platform).
     */
    private static function insertReturnToRunningOffDay(
        PDOStatement $insert, int $planId, int $athleteId, string $date,
        bool $hasEquipment, string $crossDesc, string $drillNote
    ): void {
        if ($hasEquipment) {
            $desc = $crossDesc . $drillNote;
            $insert->execute([
                $planId, $athleteId, $date, 'cross_train',
                null, null, null,
                $desc, 30, round(30 * 0.4, 2),
                null, null, null, null, 'Cross-Training', '30 min · low impact', $desc,
            ]);
        } else {
            $desc = 'Rest day — keep movement gentle and let your body recover.' . $drillNote;
            $insert->execute([
                $planId, $athleteId, $date, 'rest',
                null, null, null,
                $desc, null, 0,
                null, null, null, null, 'Rest', 'Rest + coach drills', $desc,
            ]);
        }
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
     * It then patches the next scheduled run/walk session to the new stage and keeps the
     * rolling visibility window populated (see regenerateReturnToRunningWindow), and runs
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
                'Athlete has completed the return-to-running progression — the stage 10 first '
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

        // Patch the next scheduled run/walk session to the new stage and keep the
        // rolling window populated (extends the plan as the athlete climbs the stages).
        self::regenerateReturnToRunningWindow($planId, $athleteId, $newStage, $completedDate, $scheduleNext, $db);

        // The patch wrote fresh display fields — re-validate, mirroring generate().
        self::validateGeneratedDisplays($planId, $athleteId, $db);

        return "rtr plan {$planId}: {$outcome}";
    }

    /**
     * Rebuild the forward (future) portion of a return_to_running plan's rolling
     * window after a stage change. Wipes uncompleted planned workouts dated after the
     * just-completed session, then regenerates: the NEXT eligible run day becomes a
     * single run_walk_intervals session at $stage (every-other-day cadence from the
     * completed session, never on must-off days), and all other days in the window are
     * cross-training/rest with the coach drill note. Exactly one pending run/walk
     * session is kept ahead at any time — when the athlete completes it, the stage
     * updates again and the window is rebuilt from there.
     *
     * When $scheduleRun is false (clean stage-10 completion), no further run is
     * scheduled — the window holds only gentle cross/rest days until the coach
     * transitions the athlete to a new plan type.
     *
     * Newly generated days inside the live [today, horizon] window are made visible
     * immediately (the same window the daily cron / approval opens), so the athlete
     * sees their next session without waiting for the nightly cron.
     */
    private static function regenerateReturnToRunningWindow(
        int $planId, int $athleteId, int $stage, string $fromDate, bool $scheduleRun, PDO $db
    ): void {
        $profile = self::loadProfile($athleteId, $db);
        if (!$profile) return;

        $selector     = new ArchetypeSelector($db);
        $mustOff      = json_decode($profile['must_off_days'] ?? '[]', true) ?: [];
        $hasEquipment = self::hasCrossTrainingEquipment($profile);
        $crossDesc    = self::getCrossTrainDescription($profile);
        $drillNote    = self::RTR_DRILL_NOTE;

        $tz       = self::athleteTimezone($athleteId, $db);
        $today    = Timezone::dateInZone($tz, 'now');
        $todayTs  = strtotime($today);
        $fromTs   = strtotime($fromDate);

        // Regenerate from the day after the completed session, but never into the past.
        $startTs = strtotime('+1 day', $fromTs);
        if ($startTs < $todayTs) $startTs = $todayTs;
        $startGen = date('Y-m-d', $startTs);

        // Wipe future, uncompleted planned workouts so we never leave a stale-stage run
        // or a duplicate day — but PRESERVE coach-locked sessions (explicit overrides;
        // §24 lets the coach hold/advance/regress stages). The completed session (on
        // $fromDate) and everything before $startGen are untouched.
        $db->prepare('DELETE FROM planned_workouts WHERE plan_id = ? AND scheduled_date >= ? AND coach_locked = 0')
           ->execute([$planId, $startGen]);

        $insert = self::rtrInsertStatement($db);

        // The completed session anchors the every-other-day cadence for the next run.
        $lastRunTs  = $fromTs;
        $runPlaced  = false;

        // Dates still holding a surviving (coach-locked) workout: don't double up on
        // them, and if one is a run/walk session, treat the next-run slot as already
        // filled so we respect the coach's pinned session instead of competing with it.
        $locked = $db->prepare(
            'SELECT scheduled_date, archetype_code FROM planned_workouts
             WHERE plan_id = ? AND scheduled_date >= ?'
        );
        $locked->execute([$planId, $startGen]);
        $lockedDates = [];
        foreach ($locked->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lockedDates[$r['scheduled_date']] = true;
            if (($r['archetype_code'] ?? '') === 'run_walk_intervals') {
                $runPlaced = true;
            }
        }

        $horizonTs  = strtotime('+' . self::RTR_WINDOW_DAYS . ' days', max($todayTs, $fromTs));
        $hardCapTs  = strtotime('+14 days', $horizonTs); // bound the search for the next run day
        $lastGenTs  = $startTs;

        for ($ts = $startTs; ; $ts = strtotime('+1 day', $ts)) {
            $reachedHorizon = $ts > $horizonTs;
            $stillNeedRun   = $scheduleRun && !$runPlaced;
            // Run to the horizon; continue past it only until the next run lands (bounded).
            if ($reachedHorizon && (!$stillNeedRun || $ts > $hardCapTs)) break;

            $date = date('Y-m-d', $ts);
            $dow  = (int)date('w', $ts);

            // A surviving coach-locked workout already occupies this date — leave it.
            if (isset($lockedDates[$date])) {
                $lastGenTs = $ts;
                continue;
            }

            $isRunDay = $scheduleRun && !$runPlaced
                && !in_array($dow, $mustOff, true)
                && (($ts - $lastRunTs) / 86400) >= 2;

            if ($isRunDay) {
                $instance = self::resolveRunWalkStage($selector, $stage, 0, 'base', '5K', 'insufficient');
                if ($instance !== null) {
                    self::insertResolvedWorkout($insert, $planId, $athleteId, $date, 'easy', $instance);
                    $runPlaced = true;
                    $lastRunTs = $ts;
                    $lastGenTs = $ts;
                    continue;
                }
            }

            self::insertReturnToRunningOffDay($insert, $planId, $athleteId, $date, $hasEquipment, $crossDesc, $drillNote);
            $lastGenTs = $ts;
        }

        $windowEnd = date('Y-m-d', $lastGenTs);

        // Extend the plan to cover the new window end (never shrink it).
        $db->prepare(
            'UPDATE training_plans SET plan_end_date = GREATEST(COALESCE(plan_end_date, ?), ?) WHERE id = ?'
        )->execute([$windowEnd, $windowEnd, $planId]);

        // Open visibility for the live window immediately (same horizon the cron uses).
        $db->prepare(
            'UPDATE planned_workouts SET visible_to_athlete = 1
             WHERE plan_id = ? AND scheduled_date BETWEEN ? AND ? AND visible_to_athlete = 0'
        )->execute([$planId, $today, $windowEnd]);
    }

    /** True when the athlete has any cross-training equipment available. */
    private static function hasCrossTrainingEquipment(array $profile): bool
    {
        return ($profile['cross_training_bike'] ?? 'none') !== 'none'
            || ($profile['cross_training_elliptical'] ?? 'none') !== 'none'
            || !empty($profile['cross_training_pool']);
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
                         VALUES (?, ?, ?, "recovery", "continuous_easy", "Easy recovery run — short and gentle. Keep the effort very easy.", ?, ?, 0)'
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
                foreach ($tied as $candidate) {
                    $block = self::largestRestBlock(array_merge($trainSoFar, [$candidate]));
                    if ($block < $bestBlock) { $bestBlock = $block; $bestDay = $candidate; }
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

        // Secondary quality: driven by plan type / phase / days-per-week slot allocation
        // (ultra distances override the cadence per Part 10).
        $allowedQualSlots = self::getQualitySlotCount($planType, $phase, $numDays, $weekNumber, $isCutback, $ultra['distance'] ?? null);
        $hasSecondary     = $allowedQualSlots >= 2 && count($runDays) >= 4 && !$isRaceWeek;
        $secondaryDay = null;
        if ($hasSecondary && $workoutDay !== null) {
            foreach ($runDays as $d) {
                if ($d === $longDay || $d === $workoutDay) continue;
                $gapFromPrimary = min(abs($d - $workoutDay), 7 - abs($d - $workoutDay));
                $gapFromLong    = min(abs($d - $longDay), 7 - abs($d - $longDay));
                if ($gapFromPrimary >= 2 && $gapFromLong >= 1) { $secondaryDay = $d; break; }
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
                    $day === $longDay      => 'long_run',
                    $day === $workoutDay   => 'quality_primary',
                    $day === $secondaryDay => 'quality_secondary',
                    default                => 'easy',
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

        // Guardrail: at least 1 rest day
        if (count(array_filter($schedule, fn($t) => $t === 'rest')) < 1) {
            for ($d = 6; $d >= 0; $d--) {
                if ($schedule[$d] === 'easy' && !in_array($d, $mustOff)) {
                    $schedule[$d] = 'rest';
                    break;
                }
            }
        }

        // Guardrail: max 2 quality sessions per week
        $qualCount = count(array_filter($schedule, fn($t) => in_array($t, ['quality_primary', 'quality_secondary'])));
        if ($qualCount > 2) {
            $reduced = 0;
            for ($d = 0; $d < 7 && $reduced < ($qualCount - 2); $d++) {
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
        ?array $ultra = null
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
            if ($ultra !== null) {
                // Ultra long runs are prescribed by time on feet, capped per phase, and
                // ramped via the 15%/week individual-run ceiling (ultra spec Part 8).
                $phaseCap = self::ultraLongRunCap($ultra['distance'], $phase) ?? 210;
                $longMins = max($longFloor, min($phaseCap, $progressiveCeiling));
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

        // Trail terrain weighting + (100-miler) aerobic-threshold quality preference.
        $qualWeightAdjust = $ultra !== null ? self::ultraWeightAdjust($ultra['surface'] ?? null) : [];
        $qualExcludeCodes = $ultra !== null ? self::ultraQualityExcludeCodes($ultra['distance']) : [];

        $easyFloor = $s['easy_run_min_minutes'] ?? 20;
        $easyCap   = $s['easy_run_max_minutes'] ?? 70;

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

        // Pass 1 — resolve quality slots first so their honest sum-of-parts durations are known
        // before easyMins is computed. The max_quality_duration budget keeps each quality
        // session small enough to leave the easy slots at/above their floor. Anti-repeat history
        // is updated here; pass 2 reuses these cached instances rather than re-resolving.
        $qualInstances     = [];
        $actualQualityMins = 0;
        foreach ($days as $day) {
            if (!in_array($day['slot'], $qualSlots, true)) continue;
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
            } else {
                $slotConstraints = $constraints + [
                    'weekly_minutes'             => $weeklyMins,
                    'min_duration_week_fraction' => (float)($s['quality_min_duration_week_fraction'] ?? 0.40),
                ];
                $instance = self::resolveSlotInstance(
                    $slotType, $phase, $goalDistance, $classification, $planType,
                    $slotConstraints, $antiRepeatHistory, $targetMinutes, $date,
                    $db, $selector
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

            // Trail ultra long-run cue + power-hiking guidance (ultra spec Parts 12/13).
            if ($ultra !== null && $slotType === 'long_run') {
                $cue = self::ultraTrailLongRunCue($ultra['distance'], $ultra['surface'] ?? null, $phase);
                if ($cue !== '') {
                    $instructions = trim($instructions) === '' ? $cue : rtrim($instructions) . ' ' . $cue;
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
        string $phase, string $goalDistance, string $classification
    ): array {
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

        // structured_fartlek_ladder: pick variant early to derive the correct work-interval
        // pattern, then format it as a human-readable sequence for description templates.
        if ($archetype['code'] === 'structured_fartlek_ladder' && empty($params['work_intervals_seconds'])) {
            if (!isset($archetype['resolved_variant'])) {
                $archetype['resolved_variant'] = self::pickVariant($archetype);
            }
            $variantCode = $archetype['resolved_variant']['code'] ?? 'descending';
            $patternMap  = [
                'descending'       => [90, 60, 30],
                'ascending'        => [60, 120, 180, 240],
                'symmetric'        => [60, 120, 180, 120, 60],
                'sharp_descending' => [60, 30, 15],
            ];
            $pattern = $patternMap[$variantCode] ?? [90, 60, 30];
            $params['work_intervals_seconds'] = $pattern;
            $allWholeMin = max($pattern) >= 60
                && array_sum(array_map(fn($s) => $s % 60, $pattern)) === 0;
            $params['fartlek_ladder_sequence'] = $allWholeMin
                ? implode('–', array_map(fn($s) => $s / 60, $pattern)) . ' min'
                : implode('–', $pattern) . ' sec';
            // Cap round_count so warmup + (rounds × 2 × interval-sum) + cooldown ≤ targetMinutes
            $warmupMins   = (int)($params['warmup_minutes']   ?? 0);
            $cooldownMins = (int)($params['cooldown_minutes'] ?? 0);
            $avail        = max(0, $targetMinutes - $warmupMins - $cooldownMins);
            $roundSec     = 2 * array_sum($pattern); // work + equal recovery
            if ($roundSec > 0) {
                $maxRounds = max(1, (int)floor($avail * 60 / $roundSec));
                $params['round_count'] = min((int)($params['round_count'] ?? 1), $maxRounds);
            }
        }

        // Pick a variant if not already set (must happen before fit-to-slot capping
        // so the variant is available, though capping below is param-driven not variant-driven)
        if (!isset($archetype['resolved_variant'])) {
            $archetype['resolved_variant'] = self::pickVariant($archetype);
        }

        // Generic fallback: derive rep_distance from quality_volume/rep_count only when an
        // archetype leaves it unset and isn't one of the discrete-distance archetypes below.
        if (!empty($params['rep_count']) && !empty($params['quality_volume_meters'])
            && empty($params['rep_distance_meters'])
            && !in_array($archetype['code'], ['equal_distance_repeats', 'short_speed_repeats'], true)) {
            $params['rep_distance_meters'] = (int)round($params['quality_volume_meters'] / $params['rep_count'] / 10) * 10;
        }

        // short_speed_repeats and equal_distance_repeats: each variant prescribes a distinct
        // discrete rep distance (data-driven from the variant JSON) — so variants render distinct
        // titles and produce distinct instance signatures (resolves Item 10). rep_count is then
        // derived from the quality-volume target so total volume stays within the archetype's band
        // regardless of distance (longer reps → fewer reps), clamped to the classification's
        // rep_count range so it never drops below minimum_viable_params.
        if (in_array($archetype['code'], ['short_speed_repeats', 'equal_distance_repeats'], true)) {
            $variantDist = $archetype['resolved_variant']['rep_distance_meters'] ?? null;
            $default     = $archetype['code'] === 'short_speed_repeats' ? 200 : 800;
            $params['rep_distance_meters'] = (int)($variantDist ?? $params['rep_distance_meters'] ?? $default);
            if ($archetype['code'] === 'short_speed_repeats') {
                $params['effort_zone'] = $params['effort_zone'] ?? 'repetition';
            }
            if (!empty($params['quality_volume_meters']) && $params['rep_distance_meters'] > 0) {
                $rcSpec = $archetype['parameters']['rep_count'][$classification] ?? ['min' => 4, 'max' => 10];
                $rcMin  = (int)($rcSpec['min'] ?? 4);
                $rcMax  = (int)($rcSpec['max'] ?? 10);
                $params['rep_count'] = max($rcMin, min($rcMax,
                    (int)round($params['quality_volume_meters'] / $params['rep_distance_meters'])));
            }
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

        // Fit-to-slot: cap the scalable dimension so warmup + main + cooldown ≤ targetMinutes.
        // Prevents classification midpoints from producing sessions that far exceed the
        // quality slot's volume allocation. Must run before total_distance and distance_range.
        $warmupMins   = (int)($params['warmup_minutes']   ?? 0);
        $cooldownMins = (int)($params['cooldown_minutes'] ?? 0);
        $available    = max(0, $targetMinutes - $warmupMins - $cooldownMins);

        switch ($archetype['code']) {
            case 'tempo_intervals':
                if (!empty($params['rep_duration_minutes']) && (float)$params['rep_duration_minutes'] > 0) {
                    $maxReps = max(1, (int)floor($available / (float)$params['rep_duration_minutes']));
                    $params['rep_count'] = min((int)($params['rep_count'] ?? 1), $maxReps);
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
            case 'continuous_progression_tempo':
                if (!empty($params['continuous_work_minutes']) && (int)$params['continuous_work_minutes'] > $available) {
                    $params['continuous_work_minutes'] = max(10, $available);
                }
                break;
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
            $tempoEffort = 'tempo effort — comfortably hard, where you could say a few words but not hold a conversation';
            $variantCode = $archetype['resolved_variant']['code'] ?? 'linear_progression';
            if ($variantCode === 'wave_progression') {
                $params['progression_instruction'] =
                    "Run continuously for {$w} minutes with no recovery breaks, riding waves of effort that trend "
                    . "faster overall: roughly the first {$a} minutes easing in from a comfortable to a moderate effort, "
                    . "the middle {$b} minutes alternating short tempo surges with moderate floats, and the final "
                    . "{$c} minutes settling into a sustained {$tempoEffort}.";
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
                $repCount = (int)($params['rep_count'] ?? 0);
                if ($repCount >= 4) {
                    $params['checkpoint_recovery_instruction'] =
                        'At the quarter, halfway, and three-quarter points of the workout, take 45-90 seconds standing recovery if you need it.';
                } elseif ($repCount >= 3) {
                    $params['checkpoint_recovery_instruction'] =
                        'If you need extra recovery, take one short 45-90 second standing reset around halfway.';
                } else {
                    $params['checkpoint_recovery_instruction'] = '';
                }
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
                . "full {$cont} minutes — no walk breaks. Keep it gentle; this is about time on your feet, not pace. "
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
            . "minutes of easy walking. Keep every running segment relaxed — effort, not pace. Stop immediately and "
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
        if (($archetype['code'] ?? '') !== 'sustained_hill_repeats') {
            return trim($instructions);
        }

        $params = $archetype['resolved_params'] ?? [];
        $repCount = (int)($params['rep_count'] ?? 0);
        $oldQuarterText = 'At the quarter, halfway, and three-quarter points of the workout, take 45-90 seconds standing recovery if you need it.';
        $oldQuarterTextUtf = 'At the quarter, halfway, and three-quarter points of the workout, take 45–90 seconds standing recovery if you need it.';
        $replacement = $params['checkpoint_recovery_instruction'] ?? '';
        if ($repCount < 4) {
            $instructions = str_replace([$oldQuarterText, $oldQuarterTextUtf], $replacement, $instructions);
        }

        return trim(preg_replace('/\s+/', ' ', $instructions));
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
            $archetype['resolved_variant']['code'] ?? null
        );
        if ($clause === null || $clause === '') {
            return $instructions;
        }

        return $instructions === '' ? $clause : rtrim($instructions) . ' ' . $clause;
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
        self::raiseFlag(
            $athleteId,
            'display_generation_incomplete',
            'warning',
            "Plan {$planId} has {$count} workout display(s) with incomplete generated text. Review before approval.",
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
        $trackBg    = ($profile['track_field_background'] ?? '') === 'yes';

        return [
            'track_access'                        => $trackBg ? 'yes' : 'no',
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

    private static function classifyAthlete(array $profile, string $distance): string
    {
        $runsPerWeek = (int)($profile['training_days_per_week'] ?? 0);
        $weekly      = (int)($profile['current_weekly_minutes'] ?? 0);
        $longRun     = (int)($profile['longest_recent_run_mins'] ?? 0);
        $dist        = self::normalizeDistance($distance);

        $thresholds = self::CLASSIFICATION[$dist] ?? self::CLASSIFICATION['5K'];

        $wt = $thresholds['well_trained'];
        if ($runsPerWeek >= $wt['runs_per_week'] && $weekly >= $wt['weekly_minutes'] && $longRun >= $wt['long_run_minutes']) {
            return 'well_trained';
        }
        $wb = $thresholds['workable'];
        if ($runsPerWeek >= $wb['runs_per_week'] && $weekly >= $wb['weekly_minutes'] && $longRun >= $wb['long_run_minutes']) {
            return 'workable';
        }
        return 'insufficient';
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

    private static function archivePreviousPlans(int $athleteId, PDO $db): void
    {
        // Capture the plans being archived so their Intervals.icu calendar events can
        // be deleted (otherwise old workouts linger on the athlete's watch).
        $sel = $db->prepare(
            'SELECT id FROM training_plans
             WHERE athlete_id = ? AND status IN ("active", "pending_approval")'
        );
        $sel->execute([$athleteId]);
        $planIds = $sel->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $db->prepare(
            'UPDATE training_plans SET status = "archived"
             WHERE athlete_id = ? AND status IN ("active", "pending_approval")'
        )->execute([$athleteId]);

        $db->prepare(
            'UPDATE plan_approval_queue SET status = "rejected"
             WHERE athlete_id = ? AND status = "pending"'
        )->execute([$athleteId]);

        foreach ($planIds as $pid) {
            self::deleteArchivedPlanEvents((int)$pid, $db);
        }
    }

    /**
     * Best-effort removal of an archived plan's Intervals.icu calendar events. Lazily
     * loads IntervalsService so this works from every regeneration path (web + cron),
     * and is a silent no-op when the athlete isn't connected.
     */
    private static function deleteArchivedPlanEvents(int $planId, PDO $db): void
    {
        if (!class_exists('IntervalsService')) {
            $crypto  = __DIR__ . '/../Crypto.php';
            $service = __DIR__ . '/../IntervalsService.php';
            if (is_file($crypto))  require_once $crypto;
            if (is_file($service)) require_once $service;
        }
        if (class_exists('IntervalsService')) {
            try {
                IntervalsService::deleteEventsForPlan($planId, $db);
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
            default                                                         => '5K',
        };
    }

    private static function getCrossTrainDescription(array $profile): string
    {
        if (($profile['cross_training_bike'] ?? 'none') !== 'none') {
            return 'Easy cycling — comfortable effort, 30 minutes. Low impact active recovery.';
        }
        if (($profile['cross_training_elliptical'] ?? 'none') !== 'none') {
            return 'Easy elliptical — comfortable effort, 30 minutes. Low impact active recovery.';
        }
        if (!empty($profile['cross_training_pool'])) {
            return 'Easy pool running or swimming — comfortable effort, 25–30 minutes.';
        }
        return 'Rest day or easy walk — keep it gentle and restorative.';
    }
}

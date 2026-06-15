<?php
require_once __DIR__ . '/PaceZones.php';

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
    ];

    // Fallback workout_type for slots that have no archetype (or whose metadata lacks workout_type).
    // For archetype-based workouts, metadata.workout_type takes precedence.
    const SLOT_WORKOUT_TYPE = [
        'long_run'          => 'long',
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
        string $planType, string $phase, int $daysPerWeek, int $weekNumber, bool $isCutback
    ): int {
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
            self::validateGeneratedDisplays($planId, $athleteId, $db);
            self::createApprovalQueueEntry($planId, $athleteId, $trigger, $db);
        }

        return $planId;
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

        $startDate  = date('Y-m-d', strtotime('+1 day'));
        $totalWeeks = (int)ceil((strtotime($raceDate) - strtotime($startDate)) / (7 * 86400));
        $minWeeks   = self::MIN_CYCLE[$distance] ?? 8;

        if ($totalWeeks < $minWeeks) {
            self::raiseFlag($athleteId, 'plan_rebuild_needed', 'warning',
                "Goal race is {$totalWeeks} weeks away — minimum for {$distance} is {$minWeeks} weeks.", $db);
        }

        $totalWeeks     = max($minWeeks, min(self::MAX_PLAN_WEEKS, $totalWeeks));
        $endDate        = min(date('Y-m-d', strtotime($startDate . " +{$totalWeeks} weeks -1 day")), $raceDate);
        $classification = self::classifyAthlete($profile, $distance);
        $phases         = self::calculatePhases($totalWeeks, self::PHASE_PROPORTIONS[$classification]);

        if ($classification === 'insufficient') {
            self::raiseFlag($athleteId, 'insufficient_base', 'critical',
                'Athlete base is insufficient for the selected distance. Coach decision required.', $db);
        }

        // Volume base resolved BEFORE the plan record is created so the cross-cycle
        // continuity query (Item 4) sees the prior plan, not this new empty one.
        $currentMins    = max(60, (int)($profile['current_weekly_minutes'] ?? 120));
        $peakCeiling    = max($currentMins, (int)($profile['peak_volume_ceiling_mins'] ?? (int)round($currentMins * 1.4)));
        $longestRun     = max(30, (int)($profile['longest_recent_run_mins'] ?? 60));
        $buildBase      = self::resolveStartingWeeklyMins($athleteId, $profile, $trigger, $currentMins, $peakCeiling, $db);

        $planId         = self::createPlanRecord($athleteId, 'race_cycle', $startDate, $raceDate, $raceDate, $trigger, $db);
        $maxLongRun     = $longestRun;
        $constraints    = self::buildConstraints($profile);

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $phase         = self::getPhaseForWeek($week, $phases, $totalWeeks);
            $weekInPhase   = $week - ($phases[$phase]['start_week'] ?? 1) + 1;
            $isCutback     = ($week > 1 && $week % 4 === 0 && $phase !== 'taper');
            $isRaceWeek    = ($week === $totalWeeks);
            $isPreRaceWeek = ($week === $totalWeeks - 1 && $totalWeeks > 2);

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
            $schedule   = self::buildDaySchedule($profile, $phase, $weeklyMins, $isRaceWeek, $isPreRaceWeek, $athleteId, $db, 'race_cycle', $week, $isCutback);
            $maxLongRun = self::insertWeekWorkouts(
                $planId, $athleteId, $weekStart, $endDate,
                $schedule, $phase, $distance, $classification, 'race_cycle',
                $weeklyMins, $maxLongRun, $constraints, $db, $selector, $antiRepeatHistory
            );
        }

        return $planId;
    }

    private static function generateDevelopmentPlan(
        int $athleteId, array $profile, string $trigger, PDO $db,
        ArchetypeSelector $selector, array &$antiRepeatHistory
    ): ?int {
        $startDate     = date('Y-m-d', strtotime('+1 day'));
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
            $maxLongRun = self::insertWeekWorkouts(
                $planId, $athleteId, $weekStart, $endDate,
                $schedule, 'base', $goalDist, $classification, 'development_plan',
                $weeklyMins, $maxLongRun, $constraints, $db, $selector, $antiRepeatHistory
            );
        }

        return $planId;
    }

    private static function generateMaintenancePlan(
        int $athleteId, array $profile, string $trigger, PDO $db,
        ArchetypeSelector $selector, array &$antiRepeatHistory
    ): ?int {
        $startDate     = date('Y-m-d', strtotime('+1 day'));
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

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $weekStart  = date('Y-m-d', strtotime($codeWeekStart . ' +' . (($week - 1) * 7) . ' days'));
            $schedule   = self::buildDaySchedule($profile, 'build', $weeklyMins, false, false, $athleteId, $db, 'maintenance_plan', $week, false);
            $maxLongRun = self::insertWeekWorkouts(
                $planId, $athleteId, $weekStart, $endDate,
                $schedule, 'build', $goalDist, $classification, 'maintenance_plan',
                $weeklyMins, $maxLongRun, $constraints, $db, $selector, $antiRepeatHistory
            );
        }

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
        $startDate  = date('Y-m-d', strtotime('+1 day'));
        $endDate    = date('Y-m-d', strtotime($startDate . ' +' . ($windowDays - 1) . ' days'));

        $planId = self::createPlanRecord($athleteId, 'return_to_running', $startDate, $endDate, null, $trigger, $db);
        $db->prepare('UPDATE training_plans SET rtr_current_stage = ? WHERE id = ?')->execute([$stage, $planId]);

        $cap          = max(1, min(7, (int)($profile['training_days_per_week'] ?? 3)));
        $mustOff      = json_decode($profile['must_off_days'] ?? '[]', true) ?: [];
        $hasEquipment = self::hasCrossTrainingEquipment($profile);
        $crossDesc    = self::getCrossTrainDescription($profile);
        $drillNote    = ' Complete your coach-provided mobility and strength drills (rehab Phases I–III) on this day as well.';

        $insert = $db->prepare(
            'INSERT INTO planned_workouts
             (plan_id, athlete_id, scheduled_date, workout_type,
              archetype_code, archetype_variant, archetype_params,
              description, target_duration, intensity_load, visible_to_athlete,
              workout_archetype_id, archetype_version_snapshot, instance_signature,
              structure, display_title, display_summary, athlete_instructions)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)'
        );

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

        return $planId;
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
        $instructions = self::appendPaceCitation($instructions, $instance);

        $sig             = self::computeInstanceSignature($instance);
        $variantCode     = $instance['resolved_variant']['code'] ?? null;
        $params          = $instance['resolved_params'] ?? [];
        $structure       = self::resolveStructure($instance);
        $variantWorkout  = $instance['resolved_variant']['workout_type'] ?? null;
        $workoutType     = $variantWorkout ?? $slotWorkoutType;
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

    private static function generateRecoveryBlock(
        int $athleteId, array $profile, string $trigger, PDO $db
    ): ?int {
        $startDate     = date('Y-m-d', strtotime('+1 day'));
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
        string $planType = 'development_plan', int $weekNumber = 1, bool $isCutback = false
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
            $bestDay = null;
            $bestGap = -1;
            foreach ($remaining as $candidate) {
                $minGap = 7;
                foreach ($trainSoFar as $t) {
                    $g      = min(abs($candidate - $t), 7 - abs($candidate - $t));
                    $minGap = min($minGap, $g);
                }
                if ($minGap > $bestGap) { $bestGap = $minGap; $bestDay = $candidate; }
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
        $allowedQualSlots = self::getQualitySlotCount($planType, $phase, $numDays, $weekNumber, $isCutback);
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
     * Insert planned_workouts for one week using the archetype engine.
     * Returns updated max long run minutes.
     */
    private static function insertWeekWorkouts(
        int $planId, int $athleteId, string $weekStart, string $planEnd,
        array $schedule, string $phase, string $goalDistance,
        string $classification, string $planType,
        int $weeklyMins, int $maxLongRun,
        array $constraints, PDO $db,
        ArchetypeSelector $selector, array &$antiRepeatHistory
    ): int {
        // Count slot types for volume allocation
        $longCount = count(array_filter($schedule, fn($t) => $t === 'long_run'));
        $qualCount = count(array_filter($schedule, fn($t) => in_array($t, ['quality_primary', 'quality_secondary'])));
        $easyCount = count(array_filter($schedule, fn($t) => in_array($t, ['easy', 'easy_strides'])));

        $s = self::settings();

        // Volume allocation
        $longMins  = 0;
        $longFloor = $s['long_run_absolute_floor_minutes'] ?? 60;
        if ($longCount > 0) {
            $longTarget = max($longFloor, (int)floor($weeklyMins * 0.28));
            $ceiling    = (int)round($maxLongRun * 1.15);
            $guardrail  = (int)round($weeklyMins * 0.35);
            $longMins   = max($longFloor, min($longTarget, $ceiling, $guardrail));
            $maxLongRun = max($maxLongRun, $longMins);
        }

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
                $db, $selector
            );
            $qualInstances[$day['date']] = $instance;
            if ($instance !== null) {
                $actualQualityMins += self::computeActualDuration($instance) ?? $qualTarget;
            }
        }

        // Easy: distribute the remainder after the resolved quality footprint, bounded by floor/cap.
        if ($easyCount > 0) {
            $used     = $longMins * $longCount + $actualQualityMins;
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
            $instructions = self::appendPaceCitation($instructions, $instance);

            $sig         = self::computeInstanceSignature($instance);
            $variantCode = $instance['resolved_variant']['code'] ?? null;
            $params      = $instance['resolved_params'] ?? [];
            $structure   = self::resolveStructure($instance);

            // When a variant specifies workout_type, use it — regardless of which slot type
            // triggered selection. This ensures continuous_easy's standard_easy variant
            // stores workout_type='easy' (not 'interval') when filling a quality-slot fallback,
            // and recovery_easy stores 'recovery' in any context.
            $variantWorkoutType = $instance['resolved_variant']['workout_type'] ?? null;
            $workoutType        = $variantWorkoutType ?? (self::SLOT_WORKOUT_TYPE[$slotType] ?? 'easy');
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
        PDO $db, ArchetypeSelector $selector
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
        $excludeCodes = [];
        $result       = null;
        $cutoffHard   = date('Y-m-d', strtotime($scheduledDate . " -{$hardDays} days"));

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $candidate = $selector->selectForSlot(
                $selectorSlot, $phase, $goalDistance, $classification, $planType,
                $constraints, array_unique($excludeCodes), array_unique($penalized)
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

    private static function archivePreviousPlans(int $athleteId, PDO $db): void
    {
        $db->prepare(
            'UPDATE training_plans SET status = "archived"
             WHERE athlete_id = ? AND status IN ("active", "pending_approval")'
        )->execute([$athleteId]);

        $db->prepare(
            'UPDATE plan_approval_queue SET status = "rejected"
             WHERE athlete_id = ? AND status = "pending"'
        )->execute([$athleteId]);
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

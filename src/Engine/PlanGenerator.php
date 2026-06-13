<?php
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

    /** @var array|null Cached engine settings from config/engine_settings.php. */
    private static ?array $settings = null;

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

        $planId         = self::createPlanRecord($athleteId, 'race_cycle', $startDate, $raceDate, $raceDate, $trigger, $db);
        $weeklyMins     = max(60, (int)($profile['current_weekly_minutes'] ?? 120));
        $peakCeiling    = max($weeklyMins, (int)($profile['peak_volume_ceiling_mins'] ?? (int)round($weeklyMins * 1.4)));
        $longestRun     = max(30, (int)($profile['longest_recent_run_mins'] ?? 60));
        $prevWeeklyMins = $weeklyMins;
        $maxLongRun     = $longestRun;
        $constraints    = self::buildConstraints($profile);

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $phase         = self::getPhaseForWeek($week, $phases, $totalWeeks);
            $weekInPhase   = $week - ($phases[$phase]['start_week'] ?? 1) + 1;
            $isCutback     = ($week > 1 && $week % 4 === 0 && $phase !== 'taper');
            $isRaceWeek    = ($week === $totalWeeks);
            $isPreRaceWeek = ($week === $totalWeeks - 1 && $totalWeeks > 2);

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
                $weeklyMins = max(30, (int)round($prevWeeklyMins * 0.75));
            } else {
                $weeklyMins = min((int)round($prevWeeklyMins * 1.10), $peakCeiling);
            }
            $prevWeeklyMins = $weeklyMins;

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
        $startDate  = date('Y-m-d', strtotime('+1 day'));
        $totalWeeks = 12;
        $endDate    = date('Y-m-d', strtotime($startDate . " +{$totalWeeks} weeks -1 day"));

        $planId         = self::createPlanRecord($athleteId, 'development_plan', $startDate, $endDate, null, $trigger, $db);
        $weeklyMins     = max(60, (int)($profile['current_weekly_minutes'] ?? 120));
        $peakCeiling    = max($weeklyMins, (int)($profile['peak_volume_ceiling_mins'] ?? (int)round($weeklyMins * 1.4)));
        $prevWeeklyMins = $weeklyMins;
        $maxLongRun     = max(30, (int)($profile['longest_recent_run_mins'] ?? 60));
        $constraints    = self::buildConstraints($profile);
        $goalDist       = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');
        $classification = self::classifyAthlete($profile, $goalDist);

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $isCutback  = ($week > 1 && $week % 4 === 0);
            $weeklyMins = $isCutback
                ? max(30, (int)round($prevWeeklyMins * 0.75))
                : min((int)round($prevWeeklyMins * 1.08), $peakCeiling);
            $prevWeeklyMins = $weeklyMins;

            $weekStart  = date('Y-m-d', strtotime($startDate . ' +' . (($week - 1) * 7) . ' days'));
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
        $startDate  = date('Y-m-d', strtotime('+1 day'));
        $totalWeeks = 12;
        $endDate    = date('Y-m-d', strtotime($startDate . " +{$totalWeeks} weeks -1 day"));

        $planId      = self::createPlanRecord($athleteId, 'maintenance_plan', $startDate, $endDate, null, $trigger, $db);
        $peakCeiling = max(120, (int)($profile['peak_volume_ceiling_mins'] ?? 240));
        $weeklyMins  = (int)round($peakCeiling * 0.85);
        $maxLongRun  = max(40, (int)($profile['longest_recent_run_mins'] ?? 60));
        $constraints = self::buildConstraints($profile);
        $goalDist    = self::normalizeDistance($profile['goal_race_distance'] ?? 'marathon');
        $classification = self::classifyAthlete($profile, $goalDist);

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $weekStart  = date('Y-m-d', strtotime($startDate . ' +' . (($week - 1) * 7) . ' days'));
            $schedule   = self::buildDaySchedule($profile, 'build', $weeklyMins, false, false, $athleteId, $db, 'maintenance_plan', $week, false);
            $maxLongRun = self::insertWeekWorkouts(
                $planId, $athleteId, $weekStart, $endDate,
                $schedule, 'build', $goalDist, $classification, 'maintenance_plan',
                $weeklyMins, $maxLongRun, $constraints, $db, $selector, $antiRepeatHistory
            );
        }

        return $planId;
    }

    private static function generateReturnToRunning(
        int $athleteId, array $profile, string $trigger, PDO $db
    ): ?int {
        $band          = $profile['return_time_off_band'] ?? '6_16_weeks';
        $startingStage = in_array($band, ['6_16_weeks', '4_12_months', '12_plus_months']) ? 1 : 3;
        $sessionsPerStage = in_array($band, ['4_12_months', '12_plus_months']) ? 2 : 1;

        $r2rStages = [
            1  => ['run' => 1,  'walk' => 2, 'total' => 21],
            2  => ['run' => 2,  'walk' => 2, 'total' => 24],
            3  => ['run' => 3,  'walk' => 2, 'total' => 25],
            4  => ['run' => 4,  'walk' => 1, 'total' => 25],
            5  => ['run' => 5,  'walk' => 1, 'total' => 30],
            6  => ['run' => 6,  'walk' => 1, 'total' => 28],
            7  => ['run' => 7,  'walk' => 1, 'total' => 32],
            8  => ['run' => 8,  'walk' => 1, 'total' => 27],
            9  => ['run' => 9,  'walk' => 1, 'total' => 30],
            10 => ['run' => 30, 'walk' => 0, 'total' => 30],
        ];

        $sessions = [];
        for ($stage = $startingStage; $stage <= 10; $stage++) {
            for ($s = 0; $s < $sessionsPerStage; $s++) {
                $sessions[] = $stage;
            }
        }

        $planDays   = count($sessions) * 2 + 4;
        $startDate  = date('Y-m-d', strtotime('+1 day'));
        $totalWeeks = max(4, (int)ceil($planDays / 7));
        $endDate    = date('Y-m-d', strtotime($startDate . " +{$totalWeeks} weeks -1 day"));
        $planId     = self::createPlanRecord($athleteId, 'return_to_running', $startDate, $endDate, null, $trigger, $db);
        $crossDesc  = self::getCrossTrainDescription($profile);

        $currentDate  = strtotime($startDate);
        $sessionIndex = 0;

        while ($sessionIndex < count($sessions)) {
            $date = date('Y-m-d', $currentDate);
            if ($date > $endDate) break;

            $stage     = $sessions[$sessionIndex];
            $stageData = $r2rStages[$stage];
            $desc      = $stage < 10
                ? "Run/walk: {$stageData['run']} min run, {$stageData['walk']} min walk. Repeat ~{$stageData['total']} min. Keep effort easy throughout."
                : "First continuous run — easy effort, {$stageData['total']} minutes. Stay comfortable and celebrate the milestone.";

            $db->prepare(
                'INSERT INTO planned_workouts
                 (plan_id, athlete_id, scheduled_date, workout_type,
                  description, target_duration, intensity_load, visible_to_athlete)
                 VALUES (?, ?, ?, "easy", ?, ?, ?, 0)'
            )->execute([$planId, $athleteId, $date, $desc, $stageData['total'], round($stageData['total'] * 0.4, 2)]);

            $sessionIndex++;
            $currentDate = strtotime('+1 day', $currentDate);

            $date2 = date('Y-m-d', $currentDate);
            if ($date2 <= $endDate) {
                $db->prepare(
                    'INSERT INTO planned_workouts
                     (plan_id, athlete_id, scheduled_date, workout_type,
                      description, target_duration, intensity_load, visible_to_athlete)
                     VALUES (?, ?, ?, "cross_train", ?, 30, ?, 0)'
                )->execute([$planId, $athleteId, $date2, $crossDesc, round(30 * 0.4, 2)]);
            }
            $currentDate = strtotime('+1 day', $currentDate);
        }

        return $planId;
    }

    private static function generateRecoveryBlock(
        int $athleteId, array $profile, string $trigger, PDO $db
    ): ?int {
        $startDate  = date('Y-m-d', strtotime('+1 day'));
        $totalWeeks = 4;
        $endDate    = date('Y-m-d', strtotime($startDate . " +{$totalWeeks} weeks -1 day"));

        $planId    = self::createPlanRecord($athleteId, 'recovery_block', $startDate, $endDate, null, $trigger, $db);
        $crossDesc = self::getCrossTrainDescription($profile);

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $weeklyMins = match($week) { 1 => 60, 2 => 80, 3 => 100, default => 120 };
            $runDays    = max(2, (int)($profile['training_days_per_week'] ?? 4) - 2);
            $mustOff    = json_decode($profile['must_off_days'] ?? '[]', true) ?: [];
            $available  = array_values(array_diff([0,1,2,3,4,5,6], $mustOff));
            sort($available);

            $weekStart = date('Y-m-d', strtotime($startDate . ' +' . (($week - 1) * 7) . ' days'));

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
        $numDays  = max(2, min(7, (int)($profile['training_days_per_week'] ?? 4)));

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

        // Quality: 20% of weekly, hard-capped at 30–40 min
        $qualMins = $qualCount > 0 ? max(30, min(40, (int)round($weeklyMins * 0.20))) : 0;

        // Easy: distribute remainder, bounded by engine settings
        $easyFloor = $s['easy_run_min_minutes'] ?? 20;
        $easyCap   = $s['easy_run_max_minutes'] ?? 70;
        $easyMins  = $easyFloor;
        if ($easyCount > 0) {
            $used     = $longMins * $longCount + $qualMins * $qualCount;
            $easyMins = max($easyFloor, min($easyCap, (int)floor(($weeklyMins - $used) / $easyCount)));
        }

        $insert = $db->prepare(
            'INSERT INTO planned_workouts
             (plan_id, athlete_id, scheduled_date, workout_type,
              archetype_code, archetype_variant, archetype_params,
              description, target_duration, intensity_load, visible_to_athlete,
              workout_archetype_id, archetype_version_snapshot, instance_signature,
              structure, display_title, display_summary, athlete_instructions)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)'
        );

        for ($d = 0; $d < 7; $d++) {
            $date     = date('Y-m-d', strtotime($weekStart . " +{$d} days"));
            if ($date > $planEnd) continue;

            $dow      = (int)date('w', strtotime($date));
            $slotType = $schedule[$dow] ?? 'rest';
            if ($slotType === 'rest') continue;

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
                'quality_primary', 'quality_secondary'  => $qualMins,
                'recovery'                              => min(30, $easyMins),
                default                                 => $easyMins, // easy, easy_strides
            };

            $slotConstraints = $constraints + [
                'weekly_minutes' => $weeklyMins,
                'min_duration_week_fraction' => (float)($s['quality_min_duration_week_fraction'] ?? 0.40),
            ];

            $instance = self::resolveSlotInstance(
                $slotType, $phase, $goalDistance, $classification, $planType,
                $slotConstraints, $antiRepeatHistory, $targetMinutes, $date,
                $db, $selector
            );

            if ($instance === null) continue;

            // Render athlete-facing text
            $display      = $instance['display'] ?? [];
            $title        = self::renderTemplate($display['title_template'] ?? '', $instance);
            $summary      = self::renderTemplate($display['summary_template'] ?? '', $instance);
            $instructions = self::renderTemplate($display['description_template'] ?? '', $instance);
            $instructions = self::normalizeInstructionText($instructions, $instance);

            $sig         = self::computeInstanceSignature($instance);
            $variantCode = $instance['resolved_variant']['code'] ?? null;
            $params      = $instance['resolved_params'] ?? [];
            $structure   = self::resolveStructure($instance);

            // Prefer the resolved variant's workout_type when it's more specific than the
            // slot default — specifically, recovery_easy variant must store as 'recovery'
            // even when it is selected as a fallback for a quality or easy slot.
            $variantWorkoutType = $instance['resolved_variant']['workout_type'] ?? null;
            $workoutType = ($variantWorkoutType === 'recovery')
                ? 'recovery'
                : (self::SLOT_WORKOUT_TYPE[$slotType] ?? 'easy');
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
                // Minimum real-world rep cycle estimate for measured repeats:
                // one work rep plus jogging recovery. This cap prevents tiny
                // quality slots from producing "1 x repeat" interval sessions.
                $maxReps = max(1, (int)floor($available / 3));
                $params['rep_count'] = min((int)($params['rep_count'] ?? 1), $maxReps);
                break;
            case 'continuous_progression_tempo':
                if (!empty($params['continuous_work_minutes']) && (int)$params['continuous_work_minutes'] > $available) {
                    $params['continuous_work_minutes'] = max(10, $available);
                }
                break;
        }

        $params = self::addConditionalInstructionParams($archetype['code'], $params);

        // Derive rep_distance_meters from quality_volume_meters/rep_count when not directly
        // resolvable (e.g. equal_distance_repeats stores rep_distance as allowed_values only)
        if (empty($params['rep_distance_meters']) && !empty($params['rep_count']) && !empty($params['quality_volume_meters'])) {
            $params['rep_distance_meters'] = (int)round($params['quality_volume_meters'] / $params['rep_count'] / 10) * 10;
        }
        if ($archetype['code'] === 'short_speed_repeats') {
            $params['rep_distance_meters'] = (int)($params['rep_distance_meters'] ?? 200);
            $params['effort_zone'] = $params['effort_zone'] ?? 'repetition';
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

        // distance_range: "X.X–X.X miles" estimate for time-based workouts.
        // Computed after fit-to-slot capping using the actual main-set duration so the
        // displayed range matches the described session structure.
        if (!empty($display['show_distance_range'])) {
            $warmup   = (int)($params['warmup_minutes'] ?? 0);
            $cooldown = (int)($params['cooldown_minutes'] ?? 0);
            if ($warmup + $cooldown > 0) {
                $mainMins   = self::computeMainSetMinutes($archetype['code'], $params);
                if ($mainMins === null) {
                    $mainMins = max(0, $targetMinutes - $warmup - $cooldown);
                }
                $hillCodes  = ['sustained_hill_repeats', 'hill_sprints', 'plyometric_hill_circuits'];
                $mainFactor = in_array($archetype['code'], $hillCodes, true) ? 0.6 : 1.0;
                $effectiveMins = (int)round($warmup + $cooldown + $mainMins * $mainFactor);
            } else {
                $effectiveMins = $targetMinutes;
            }
            $params['distance_range'] = self::computeDistanceRange(
                $effectiveMins, $goalDistance, $classification
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
     * Compute a "X.X–X.X miles" estimate for a time-based workout.
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
        $lower = round($durationMinutes / $slowPace, 1);
        $upper = round($durationMinutes / $fastPace, 1);

        return "{$lower}–{$upper} miles";
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

    /**
     * Compute the main-set duration in minutes from resolved (and capped) parameters.
     * Returns null for archetypes where main-set time can't be derived from params alone
     * (e.g. distance-based archetypes without pace, or plyometric circuits).
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

        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($tokens) {
            $key = $m[1];
            if (!array_key_exists($key, $tokens)) return '';
            $v = $tokens[$key];
            return is_array($v) ? implode(', ', $v) : (string)$v;
        }, $template);
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

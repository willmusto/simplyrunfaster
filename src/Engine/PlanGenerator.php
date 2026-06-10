<?php
/**
 * PlanGenerator — rule-based training plan generation.
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

    // Classification thresholds [min_months_at_volume, min_weekly_minutes]
    // Weekly minutes approximated from mileage at ~10 min/mile
    const CLASSIFICATION = [
        '5K'       => ['well_trained' => [6,  250], 'workable' => [3,  150]],
        '10K'      => ['well_trained' => [9,  300], 'workable' => [6,  200]],
        'half'     => ['well_trained' => [12, 350], 'workable' => [9,  250]],
        'HM'       => ['well_trained' => [12, 350], 'workable' => [9,  250]],
        'marathon' => ['well_trained' => [18, 450], 'workable' => [12, 350]],
    ];

    // Template selection priority lists per phase/slot
    const TEMPLATES = [
        'base' => [
            'primary_quality'   => ['WL-012', 'WL-007', 'WL-015', 'WL-022'],
            'secondary_quality' => ['WL-007', 'WL-012'],
            'long'              => ['WL-004', 'WL-003', 'WL-016'],
            'long_aerobic'      => ['WL-003'],
            'easy_strides'      => ['WL-002'],
            'easy'              => ['WL-001'],
        ],
        'build' => [
            'primary_quality'   => ['WL-014', 'WL-008', 'WL-011', 'WL-022', 'WL-020'],
            'secondary_quality' => ['WL-007', 'WL-022', 'WL-012'],
            'long'              => ['WL-006', 'WL-016', 'WL-005', 'WL-004', 'WL-003'],
            'long_aerobic'      => ['WL-003'],
            'easy_strides'      => ['WL-002'],
            'easy'              => ['WL-001'],
        ],
        'peak' => [
            'primary_quality'   => ['WL-008', 'WL-009', 'WL-014', 'WL-020', 'WL-023', 'WL-010'],
            'secondary_quality' => ['WL-020', 'WL-022'],
            'long'              => ['WL-005', 'WL-006', 'WL-003'],
            'long_aerobic'      => ['WL-003'],
            'easy_strides'      => ['WL-002'],
            'easy'              => ['WL-001'],
        ],
        'taper' => [
            'primary_quality'   => ['WL-014', 'WL-020'],
            'secondary_quality' => ['WL-020'],
            'long'              => ['WL-003'],
            'long_aerobic'      => ['WL-003'],
            'pre_race'          => ['WL-017'],
            'easy_strides'      => ['WL-002'],
            'easy'              => ['WL-001'],
        ],
    ];

    // Return-to-running stage definitions
    const R2R_STAGES = [
        1  => ['run' => 1,  'walk' => 2, 'reps' => 7,  'total' => 21],
        2  => ['run' => 2,  'walk' => 2, 'reps' => 6,  'total' => 24],
        3  => ['run' => 3,  'walk' => 2, 'reps' => 5,  'total' => 25],
        4  => ['run' => 4,  'walk' => 1, 'reps' => 5,  'total' => 25],
        5  => ['run' => 5,  'walk' => 1, 'reps' => 5,  'total' => 30],
        6  => ['run' => 6,  'walk' => 1, 'reps' => 4,  'total' => 28],
        7  => ['run' => 7,  'walk' => 1, 'reps' => 4,  'total' => 32],
        8  => ['run' => 8,  'walk' => 1, 'reps' => 3,  'total' => 27],
        9  => ['run' => 9,  'walk' => 1, 'reps' => 3,  'total' => 30],
        10 => ['run' => 30, 'walk' => 0, 'reps' => 1,  'total' => 30],
    ];

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

        // Archive any existing pending/active plans
        self::archivePreviousPlans($athleteId, $db);

        $planId = match ($planType) {
            'race_cycle'       => self::generateRaceCycle($athleteId, $profile, $trigger, $db),
            'return_to_running'=> self::generateReturnToRunning($athleteId, $profile, $trigger, $db),
            'maintenance_plan' => self::generateMaintenancePlan($athleteId, $profile, $trigger, $db),
            'recovery_block'   => self::generateRecoveryBlock($athleteId, $profile, $trigger, $db),
            default            => self::generateDevelopmentPlan($athleteId, $profile, $trigger, $db),
        };

        if ($planId) {
            self::createApprovalQueueEntry($planId, $athleteId, $trigger, $db);
        }

        return $planId;
    }

    // ── Plan type generators ─────────────────────────────────────────────────

    private static function generateRaceCycle(
        int $athleteId, array $profile, string $trigger, PDO $db
    ): ?int {
        $raceDate = $profile['goal_race_date'] ?? null;
        $distance = self::normalizeDistance($profile['goal_race_distance'] ?? '5K');

        if (!$raceDate) {
            // No race date — fall back to development plan
            return self::generateDevelopmentPlan($athleteId, $profile, $trigger, $db);
        }

        $startDate   = date('Y-m-d', strtotime('+1 day'));
        $totalWeeks  = (int)ceil((strtotime($raceDate) - strtotime($startDate)) / (7 * 86400));
        $minWeeks    = self::MIN_CYCLE[$distance] ?? 8;

        if ($totalWeeks < $minWeeks) {
            self::raiseFlag($athleteId, 'plan_rebuild_needed', 'warning',
                "Goal race is {$totalWeeks} weeks away — minimum for {$distance} is {$minWeeks} weeks. Consider adjusting goal or distance.", $db);
        }

        $totalWeeks = max($minWeeks, min(self::MAX_PLAN_WEEKS, $totalWeeks));
        $endDate    = date('Y-m-d', strtotime($startDate . " +{$totalWeeks} weeks -1 day"));
        // Snap end date to race date
        $endDate    = min($endDate, $raceDate);

        $classification = self::classifyAthlete($profile, $distance);
        $phases         = self::calculatePhases($totalWeeks, self::PHASE_PROPORTIONS[$classification]);

        if ($classification === 'insufficient') {
            self::raiseFlag($athleteId, 'insufficient_base', 'critical',
                'Athlete base is insufficient for the selected distance. Coach decision required: redirect to shorter distance, extend cycle, or proceed with modified plan.', $db);
        }

        $planId = self::createPlanRecord(
            $athleteId, 'race_cycle', $startDate, $raceDate, $raceDate, $trigger, $db
        );

        $weeklyMins      = max(60, (int)($profile['current_weekly_minutes'] ?? 120));
        $peakCeiling     = max($weeklyMins, (int)($profile['peak_volume_ceiling_mins'] ?? (int)round($weeklyMins * 1.4)));
        $longestRun      = max(30, (int)($profile['longest_recent_run_mins'] ?? 60));
        $prevWeeklyMins  = $weeklyMins;
        $maxLongRun      = $longestRun;

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $phase         = self::getPhaseForWeek($week, $phases, $totalWeeks);
            $weekInPhase   = $week - ($phases[$phase]['start_week'] ?? 1) + 1;
            $isCutback     = ($week > 1 && $week % 4 === 0 && $phase !== 'taper');
            $isRaceWeek    = ($week === $totalWeeks);
            $isPreRaceWeek = ($week === $totalWeeks - 1 && $totalWeeks > 2);

            // Volume for this week
            if ($phase === 'taper') {
                $taperWeek  = $weekInPhase;
                $taperMult  = match(true) {
                    $taperWeek === 1 => 0.75,
                    $taperWeek === 2 => 0.60,
                    default          => 0.45,
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

            $weekStart = date('Y-m-d', strtotime($startDate . ' +' . (($week - 1) * 7) . ' days'));
            $schedule  = self::buildDaySchedule($profile, $phase, $weeklyMins, $isRaceWeek, $isPreRaceWeek);
            $maxLongRun = self::insertWeekWorkouts(
                $planId, $athleteId, $weekStart, $endDate,
                $schedule, $phase, $distance, $weekInPhase,
                $weeklyMins, $maxLongRun, $db
            );
        }

        return $planId;
    }

    private static function generateDevelopmentPlan(
        int $athleteId, array $profile, string $trigger, PDO $db
    ): ?int {
        $startDate  = date('Y-m-d', strtotime('+1 day'));
        $totalWeeks = 12;
        $endDate    = date('Y-m-d', strtotime($startDate . " +{$totalWeeks} weeks -1 day"));

        $planId = self::createPlanRecord(
            $athleteId, 'development_plan', $startDate, $endDate, null, $trigger, $db
        );

        $weeklyMins     = max(60, (int)($profile['current_weekly_minutes'] ?? 120));
        $peakCeiling    = max($weeklyMins, (int)($profile['peak_volume_ceiling_mins'] ?? (int)round($weeklyMins * 1.4)));
        $longestRun     = max(30, (int)($profile['longest_recent_run_mins'] ?? 60));
        $prevWeeklyMins = $weeklyMins;
        $maxLongRun     = $longestRun;

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $isCutback = ($week > 1 && $week % 4 === 0);
            if ($isCutback) {
                $weeklyMins = max(30, (int)round($prevWeeklyMins * 0.75));
            } else {
                $weeklyMins = min((int)round($prevWeeklyMins * 1.08), $peakCeiling);
            }
            $prevWeeklyMins = $weeklyMins;

            $weekStart = date('Y-m-d', strtotime($startDate . ' +' . (($week - 1) * 7) . ' days'));
            $schedule  = self::buildDaySchedule($profile, 'base', $weeklyMins, false, false);
            $maxLongRun = self::insertWeekWorkouts(
                $planId, $athleteId, $weekStart, $endDate,
                $schedule, 'base', '5K', $week,
                $weeklyMins, $maxLongRun, $db
            );
        }

        return $planId;
    }

    private static function generateReturnToRunning(
        int $athleteId, array $profile, string $trigger, PDO $db
    ): ?int {
        $band = $profile['return_time_off_band'] ?? '6_16_weeks';

        $startingStage = match($band) {
            '2_6_weeks'      => 3,
            '6_16_weeks'     => 1,
            '4_12_months'    => 1,
            '12_plus_months' => 1,
            default          => 3,
        };
        $sessionsPerStage = in_array($band, ['4_12_months', '12_plus_months']) ? 2 : 1;

        // Build sessions from starting stage to stage 10
        $sessions = [];
        for ($stage = $startingStage; $stage <= 10; $stage++) {
            for ($s = 0; $s < $sessionsPerStage; $s++) {
                $sessions[] = $stage;
            }
        }

        // Plan length: sessions × 2 days (every other day) + a few days buffer
        $planDays  = count($sessions) * 2 + 4;
        $startDate = date('Y-m-d', strtotime('+1 day'));
        $endDate   = date('Y-m-d', strtotime($startDate . " +{$planDays} days"));
        $totalWeeks = max(4, (int)ceil($planDays / 7));
        $endDate    = date('Y-m-d', strtotime($startDate . " +{$totalWeeks} weeks -1 day"));

        $planId = self::createPlanRecord(
            $athleteId, 'return_to_running', $startDate, $endDate, null, $trigger, $db
        );

        // Cross-train description
        $crossDesc = self::getCrossTrainDescription($profile);

        $currentDate  = strtotime($startDate);
        $sessionIndex = 0;
        $dayCount     = 0;

        while ($sessionIndex < count($sessions)) {
            $date = date('Y-m-d', $currentDate);
            if ($date > $endDate) break;

            $stage = $sessions[$sessionIndex];
            $stageData = self::R2R_STAGES[$stage];

            if ($stage < 10) {
                $desc = "Run/walk intervals: {$stageData['run']} min run, {$stageData['walk']} min walk. "
                    . "Repeat for approximately {$stageData['total']} minutes. Keep the effort easy throughout.";
            } else {
                $desc = "First continuous run — easy effort, {$stageData['total']} minutes. "
                    . "Keep the pace comfortable. This is a milestone — celebrate it and stay easy.";
            }

            // Run day
            $db->prepare(
                'INSERT INTO planned_workouts
                 (plan_id, athlete_id, scheduled_date, workout_type, description,
                  target_duration, intensity_load, visible_to_athlete)
                 VALUES (?, ?, ?, "easy", ?, ?, ?, 0)'
            )->execute([$planId, $athleteId, $date, $desc, $stageData['total'],
                        round($stageData['total'] * 0.4, 2)]);

            $sessionIndex++;
            $currentDate = strtotime("+1 day", $currentDate);

            // Off day
            $date2 = date('Y-m-d', $currentDate);
            if ($date2 <= $endDate) {
                $db->prepare(
                    'INSERT INTO planned_workouts
                     (plan_id, athlete_id, scheduled_date, workout_type, description,
                      target_duration, intensity_load, visible_to_athlete)
                     VALUES (?, ?, ?, "cross_train", ?, ?, ?, 0)'
                )->execute([$planId, $athleteId, $date2, $crossDesc, 30, round(30 * 0.4, 2)]);
            }

            $currentDate = strtotime("+1 day", $currentDate);
            $dayCount += 2;
        }

        return $planId;
    }

    private static function generateMaintenancePlan(
        int $athleteId, array $profile, string $trigger, PDO $db
    ): ?int {
        $startDate  = date('Y-m-d', strtotime('+1 day'));
        $totalWeeks = 12;
        $endDate    = date('Y-m-d', strtotime($startDate . " +{$totalWeeks} weeks -1 day"));

        $planId = self::createPlanRecord(
            $athleteId, 'maintenance_plan', $startDate, $endDate, null, $trigger, $db
        );

        $peakCeiling   = max(120, (int)($profile['peak_volume_ceiling_mins'] ?? 240));
        $weeklyMins    = (int)round($peakCeiling * 0.85); // 85% of peak
        $longestRun    = max(40, (int)($profile['longest_recent_run_mins'] ?? 60));
        $maxLongRun    = $longestRun;

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $weekStart = date('Y-m-d', strtotime($startDate . ' +' . (($week - 1) * 7) . ' days'));
            $schedule  = self::buildDaySchedule($profile, 'build', $weeklyMins, false, false);
            $maxLongRun = self::insertWeekWorkouts(
                $planId, $athleteId, $weekStart, $endDate,
                $schedule, 'build', 'marathon', $week,
                $weeklyMins, $maxLongRun, $db
            );
        }

        return $planId;
    }

    private static function generateRecoveryBlock(
        int $athleteId, array $profile, string $trigger, PDO $db
    ): ?int {
        // 4-week recovery block — no quality, cross-training emphasis
        $startDate  = date('Y-m-d', strtotime('+1 day'));
        $totalWeeks = 4;
        $endDate    = date('Y-m-d', strtotime($startDate . " +{$totalWeeks} weeks -1 day"));

        $planId = self::createPlanRecord(
            $athleteId, 'recovery_block', $startDate, $endDate, null, $trigger, $db
        );

        $crossDesc = self::getCrossTrainDescription($profile);

        for ($week = 1; $week <= $totalWeeks; $week++) {
            $weeklyMins    = match($week) { 1 => 60, 2 => 80, 3 => 100, default => 120 };
            $runDays       = max(2, (int)($profile['training_days_per_week'] ?? 4) - 2);
            $mustOff       = json_decode($profile['must_off_days'] ?? '[]', true) ?: [];
            $available     = array_diff([0,1,2,3,4,5,6], $mustOff);
            sort($available);

            $weekStart = date('Y-m-d', strtotime($startDate . ' +' . (($week - 1) * 7) . ' days'));

            for ($d = 0; $d < 7; $d++) {
                $date      = date('Y-m-d', strtotime($weekStart . " +{$d} days"));
                $dayOfWeek = (int)date('w', strtotime($date));

                if (in_array($dayOfWeek, $mustOff)) {
                    // Rest day
                    continue;
                }

                $posInAvail = array_search($dayOfWeek, array_values($available));
                if ($posInAvail !== false && $posInAvail < $runDays) {
                    // Easy recovery run
                    $dur = (int)round($weeklyMins / $runDays);
                    $db->prepare(
                        'INSERT INTO planned_workouts
                         (plan_id, athlete_id, scheduled_date, workout_type, description,
                          target_duration, intensity_load, visible_to_athlete)
                         VALUES (?, ?, ?, "recovery", "Easy recovery run — short and gentle. Keep the effort very easy.", ?, ?, 0)'
                    )->execute([$planId, $athleteId, $date, $dur, round($dur * 0.3, 2)]);
                } else {
                    // Cross-train
                    $db->prepare(
                        'INSERT INTO planned_workouts
                         (plan_id, athlete_id, scheduled_date, workout_type, description,
                          target_duration, intensity_load, visible_to_athlete)
                         VALUES (?, ?, ?, "cross_train", ?, 30, ?, 0)'
                    )->execute([$planId, $athleteId, $date, $crossDesc, round(30 * 0.4, 2)]);
                }
            }
        }

        return $planId;
    }

    // ── Week/day building ────────────────────────────────────────────────────

    /**
     * Returns a 7-element array keyed by day-of-week (0=Sun…6=Sat) → workout type.
     */
    private static function buildDaySchedule(
        array $profile,
        string $phase,
        int $weeklyMins,
        bool $isRaceWeek,
        bool $isPreRaceWeek
    ): array {
        $schedule = array_fill(0, 7, 'rest');
        $mustOff  = json_decode($profile['must_off_days'] ?? '[]', true) ?: [];
        $numDays  = max(2, min(7, (int)($profile['training_days_per_week'] ?? 4)));

        $longPref    = (isset($profile['long_run_day']) && $profile['long_run_day'] !== '')
            ? (int)$profile['long_run_day'] : null;
        $workoutPref = (isset($profile['primary_workout_day']) && $profile['primary_workout_day'] !== '')
            ? (int)$profile['primary_workout_day'] : null;

        $available = array_values(array_diff([0,1,2,3,4,5,6], $mustOff));
        sort($available);

        if (empty($available)) {
            $available = [1, 3, 5, 0]; // fallback
        }

        // Pick long run day
        $longDay = null;
        if ($longPref !== null && in_array($longPref, $available)) {
            $longDay = $longPref;
        } else {
            // Prefer Saturday (6) then Sunday (0), then last available
            foreach ([6, 0] as $candidate) {
                if (in_array($candidate, $available)) { $longDay = $candidate; break; }
            }
            if ($longDay === null) $longDay = end($available);
        }

        // Pick primary workout day (≥2 days from long run)
        $workoutDay = null;
        if ($workoutPref !== null && in_array($workoutPref, $available)) {
            $gap = min(abs($workoutPref - $longDay), 7 - abs($workoutPref - $longDay));
            if ($gap >= 2) $workoutDay = $workoutPref;
        }
        if ($workoutDay === null) {
            foreach ([2, 3, 1, 4] as $candidate) { // Tue, Wed, Mon, Thu preferred
                if (in_array($candidate, $available) && $candidate !== $longDay) {
                    $gap = min(abs($candidate - $longDay), 7 - abs($candidate - $longDay));
                    if ($gap >= 2) { $workoutDay = $candidate; break; }
                }
            }
            if ($workoutDay === null) {
                // Any available day at least 1 day from long run
                foreach ($available as $d) {
                    if ($d !== $longDay) { $workoutDay = $d; break; }
                }
            }
        }

        // Fill remaining running days — greedy max-gap to avoid consecutive training days.
        // Each new day is chosen to maximise its minimum circular distance to any day
        // already selected, so the schedule stays as evenly spread as possible.
        $anchors    = array_values(array_filter([$longDay, $workoutDay], fn($v) => $v !== null));
        $remaining  = array_values(array_diff($available, $anchors));
        sort($remaining);
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
                if ($minGap > $bestGap) {
                    $bestGap = $minGap;
                    $bestDay = $candidate;
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

        // Can we have a secondary quality session?
        $hasSecondary = $numDays >= 5 && count($runDays) >= 4 && !$isRaceWeek;
        $secondaryDay = null;
        if ($hasSecondary && $workoutDay !== null) {
            foreach ($runDays as $d) {
                if ($d === $longDay || $d === $workoutDay) continue;
                $gapFromPrimary = min(abs($d - $workoutDay), 7 - abs($d - $workoutDay));
                $gapFromLong    = min(abs($d - $longDay), 7 - abs($d - $longDay));
                if ($gapFromPrimary >= 2 && $gapFromLong >= 1) { $secondaryDay = $d; break; }
            }
        }

        // Primary quality type by phase
        $primaryType = match($phase) {
            'base'  => 'hill',
            'build' => 'tempo',
            'peak'  => 'interval',
            'taper' => 'tempo',
            default => 'fartlek',
        };

        if ($isRaceWeek) {
            // Race week: rest mostly, one easy, pre-race activation, race day
            foreach ($runDays as $day) {
                $schedule[$day] = match(true) {
                    $day === $longDay    => 'race',
                    $day === $workoutDay => 'easy', // pre-race activation
                    default              => 'easy',
                };
            }
        } elseif ($isPreRaceWeek) {
            foreach ($runDays as $day) {
                $schedule[$day] = match(true) {
                    $day === $longDay    => 'long', // pure aerobic — guardrail
                    $day === $workoutDay => 'easy', // light sharpener
                    default              => 'easy',
                };
            }
        } else {
            foreach ($runDays as $day) {
                $schedule[$day] = match(true) {
                    $day === $longDay    => 'long',
                    $day === $workoutDay => $primaryType,
                    $day === $secondaryDay => 'fartlek',
                    default              => 'easy',
                };
            }
        }

        // Guardrail: at least 1 rest day
        $restDays = count(array_filter($schedule, fn($t) => $t === 'rest'));
        if ($restDays < 1) {
            // Remove last easy day and make it rest
            for ($d = 6; $d >= 0; $d--) {
                if ($schedule[$d] === 'easy' && !in_array($d, $mustOff)) {
                    $schedule[$d] = 'rest';
                    break;
                }
            }
        }

        // Guardrail: max 2 quality sessions per week
        $qualCount = count(array_filter($schedule, fn($t) => in_array($t, ['interval','tempo','hill','fartlek'])));
        if ($qualCount > 2) {
            $reduced = 0;
            for ($d = 0; $d < 7 && $reduced < ($qualCount - 2); $d++) {
                if ($schedule[$d] === 'fartlek') { $schedule[$d] = 'easy'; $reduced++; }
            }
        }

        return $schedule;
    }

    /**
     * Insert planned_workouts for one week. Returns updated max long run minutes.
     */
    private static function insertWeekWorkouts(
        int $planId, int $athleteId, string $weekStart, string $planEnd,
        array $schedule, string $phase, string $distance, int $weekInPhase,
        int $weeklyMins, int $maxLongRun, PDO $db
    ): int {
        // Compute durations
        $qualityDays = array_keys(array_filter($schedule, fn($t) => in_array($t, ['interval','tempo','hill','fartlek'])));
        $longDays    = array_keys(array_filter($schedule, fn($t) => $t === 'long'));
        $easyDays    = array_keys(array_filter($schedule, fn($t) => $t === 'easy'));
        $raceDays    = array_keys(array_filter($schedule, fn($t) => $t === 'race'));

        $qualCount = count($qualityDays);
        $longCount = count($longDays);
        $easyCount = count($easyDays);

        // Long run duration (max 35% of weekly, max 15% growth)
        $longMins = 0;
        if ($longCount > 0) {
            $target  = (int)round($weeklyMins * 0.28);
            $ceiling = (int)round($maxLongRun * 1.15);
            $guardrail = (int)round($weeklyMins * 0.35);
            $longMins = max(30, min($target, $ceiling, $guardrail));
            $maxLongRun = max($maxLongRun, $longMins);
        }

        // Quality duration
        $qualMins = $qualCount > 0 ? max(45, min(80, (int)round($weeklyMins * 0.20))) : 0;

        // Easy run duration
        $easyRemaining = max(0, $weeklyMins - $longMins - ($qualMins * $qualCount));
        $easyMins = $easyCount > 0 ? max(20, (int)round($easyRemaining / $easyCount)) : 30;

        $insert = $db->prepare(
            'INSERT INTO planned_workouts
             (plan_id, athlete_id, scheduled_date, workout_type, workout_template_id,
              description, target_duration, intensity_load, visible_to_athlete)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
        );

        $easyStridesToggle = 0; // Alternate strides on easy days

        for ($d = 0; $d < 7; $d++) {
            $date = date('Y-m-d', strtotime($weekStart . " +{$d} days"));
            if ($date > $planEnd) continue;

            // $schedule is keyed by day-of-week (0=Sun…6=Sat); look up by actual dow
            $dow  = (int)date('w', strtotime($date));
            $type = $schedule[$dow] ?? 'rest';
            if ($type === 'rest') continue; // don't insert rest rows (handled as gap in calendar)

            [$templateId, $desc, $factor, $dur] = self::resolveWorkoutTemplate(
                $type, $phase, $distance, $weekInPhase, $easyStridesToggle,
                $longMins, $qualMins, $easyMins, $db
            );

            if ($type === 'easy') $easyStridesToggle = 1 - $easyStridesToggle;

            $load = round($dur * $factor, 2);
            $insert->execute([$planId, $athleteId, $date, $type, $templateId, $desc, $dur, $load]);
        }

        return $maxLongRun;
    }

    /**
     * Returns [template_id, description, intensity_factor, duration].
     */
    private static function resolveWorkoutTemplate(
        string $type, string $phase, string $distance, int $weekInPhase,
        int $easyToggle, int $longMins, int $qualMins, int $easyMins, PDO $db
    ): array {
        $phaseTemplates = self::TEMPLATES[$phase] ?? self::TEMPLATES['base'];

        switch ($type) {
            case 'rest':
                return [null, 'Rest day.', 0.0, 0];

            case 'race':
                return [null, 'Race day. Run your race.', 1.35, 45];

            case 'long':
                // Rotate long run templates by week
                $codes = $phaseTemplates['long'] ?? ['WL-003'];
                $idx   = ($weekInPhase - 1) % count($codes);
                // But not within 7 days of race — always pure aerobic
                // (phase-level logic handles pre_race already via buildDaySchedule)
                $tpl = self::bestTemplate([$codes[$idx], 'WL-003'], $db);
                return [
                    $tpl['id'] ?? null,
                    $tpl['description'] ?? 'Long easy run. Time on your feet at a comfortable, sustainable pace.',
                    (float)($tpl['intensity_factor'] ?? 0.6),
                    $longMins,
                ];

            case 'interval':
            case 'tempo':
            case 'hill':
                // Rotate quality templates by week
                $rotation = $phaseTemplates['primary_quality'] ?? ['WL-007'];
                $idx   = ($weekInPhase - 1) % count($rotation);
                $tpl   = self::bestTemplate([$rotation[$idx], $rotation[0]], $db);
                $defaultFactor = ['interval' => 1.0, 'tempo' => 0.8, 'hill' => 0.7][$type] ?? 0.8;
                return [
                    $tpl['id'] ?? null,
                    $tpl['description'] ?? 'Quality training session.',
                    (float)($tpl['intensity_factor'] ?? $defaultFactor),
                    $qualMins,
                ];

            case 'fartlek':
                $rotation = $phaseTemplates['secondary_quality'] ?? ['WL-007'];
                $idx = ($weekInPhase - 1) % count($rotation);
                $tpl = self::bestTemplate([$rotation[$idx], 'WL-007'], $db);
                return [
                    $tpl['id'] ?? null,
                    $tpl['description'] ?? 'Fartlek session — unstructured, playful speed work.',
                    (float)($tpl['intensity_factor'] ?? 0.7),
                    $qualMins,
                ];

            case 'easy':
                // Alternate between strides and pure easy
                if ($easyToggle) {
                    $tpl = self::bestTemplate(['WL-002'], $db);
                    return [
                        $tpl['id'] ?? null,
                        $tpl['description'] ?? 'Easy run finishing with strides.',
                        (float)($tpl['intensity_factor'] ?? 0.55),
                        $easyMins,
                    ];
                } else {
                    $tpl = self::bestTemplate(['WL-001'], $db);
                    return [
                        $tpl['id'] ?? null,
                        $tpl['description'] ?? 'Easy, conversational run. No pace target — run by feel.',
                        (float)($tpl['intensity_factor'] ?? 0.5),
                        $easyMins,
                    ];
                }

            case 'recovery':
                $tpl = self::bestTemplate(['WL-018'], $db);
                return [
                    $tpl['id'] ?? null,
                    $tpl['description'] ?? 'Short, easy recovery run — slower than easy. Movement only.',
                    (float)($tpl['intensity_factor'] ?? 0.3),
                    min(30, $easyMins),
                ];

            case 'cross_train':
                return [null, 'Easy cross-training at a comfortable effort.', 0.4, max(25, $easyMins)];

            default:
                return [null, 'Training session.', 0.5, $easyMins];
        }
    }

    // ── Classification & phase calculations ──────────────────────────────────

    private static function classifyAthlete(array $profile, string $distance): string
    {
        $months  = (int)($profile['months_at_current_volume'] ?? 0);
        $weekly  = (int)($profile['current_weekly_minutes'] ?? 0);
        $dist    = self::normalizeDistance($distance);

        $thresholds = self::CLASSIFICATION[$dist] ?? self::CLASSIFICATION['5K'];

        if ($months >= $thresholds['well_trained'][0] && $weekly >= $thresholds['well_trained'][1]) {
            return 'well_trained';
        }
        if ($months >= $thresholds['workable'][0] && $weekly >= $thresholds['workable'][1]) {
            return 'workable';
        }
        return 'insufficient';
    }

    /**
     * Returns per-phase start/end week numbers (1-indexed).
     */
    private static function calculatePhases(int $totalWeeks, array $props): array
    {
        $remainder = 1.0 - array_sum($props); // ~5% goes to base
        $adjusted  = $props;
        $adjusted['base'] += max(0, $remainder);

        $phases = [];
        $week   = 1;
        foreach (['base', 'build', 'peak', 'taper'] as $ph) {
            $len = max(1, (int)round($totalWeeks * ($adjusted[$ph] ?? 0)));
            $phases[$ph] = ['start_week' => $week, 'end_week' => $week + $len - 1];
            $week += $len;
        }

        // Distribute any rounding slack to base
        $allocated = array_sum(array_column($phases, 'end_week'))
                   - array_sum(array_column($phases, 'start_week'))
                   + count($phases);
        if ($allocated < $totalWeeks) {
            $phases['base']['end_week'] += ($totalWeeks - $allocated);
            // Re-number subsequent phases
            foreach (['build', 'peak', 'taper'] as $ph) {
                $phases[$ph]['start_week'] = $phases[array_key_first(array_slice($phases, array_search($ph, array_keys($phases)) - 1, 1))]['end_week'] + 1;
                $phases[$ph]['end_week']   = $phases[$ph]['start_week'] + ($phases[$ph]['end_week'] - $phases[$ph]['start_week']);
            }
        }

        return $phases;
    }

    private static function getPhaseForWeek(int $week, array $phases, int $totalWeeks): string
    {
        // Ensure taper is always at least the last 2 weeks (guardrail)
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
        int $athleteId, string $flagType, string $severity, string $message, PDO $db
    ): void {
        $db->prepare(
            'INSERT INTO engine_flags
             (athlete_id, flag_type, severity, flag_date, message, status, created_at)
             VALUES (?, ?, ?, CURDATE(), ?, "open", NOW())'
        )->execute([$athleteId, $flagType, $severity, $message]);
    }

    private static function bestTemplate(array $libraryCodes, PDO $db): ?array
    {
        if (empty($libraryCodes)) return null;

        try {
            $placeholders = implode(',', array_fill(0, count($libraryCodes), '?'));
            $stmt = $db->prepare(
                "SELECT * FROM workout_library WHERE library_code IN ($placeholders)"
            );
            $stmt->execute($libraryCodes);
            $found = [];
            foreach ($stmt->fetchAll() as $row) {
                $found[$row['library_code']] = $row;
            }
            foreach ($libraryCodes as $code) {
                if (isset($found[$code])) return $found[$code];
            }
        } catch (PDOException $e) {
            // library_code column may not exist yet (pre-migration)
        }
        return null;
    }

    // ── Utility ──────────────────────────────────────────────────────────────

    private static function normalizeDistance(string $d): string
    {
        $d = strtolower(trim($d));
        return match(true) {
            in_array($d, ['half', 'hm', 'half marathon', '21k'])     => 'half',
            in_array($d, ['marathon', 'm', '42k', 'full', 'full marathon']) => 'marathon',
            in_array($d, ['10k', '10km', '10 km'])                    => '10K',
            in_array($d, ['5k', '5km', '5 km'])                       => '5K',
            default                                                    => '5K',
        };
    }

    private static function getCrossTrainDescription(array $profile): string
    {
        $bike      = $profile['cross_training_bike'] ?? 'none';
        $elliptical = $profile['cross_training_elliptical'] ?? 'none';
        $pool      = (bool)($profile['cross_training_pool'] ?? 0);

        if ($bike !== 'none') {
            return 'Easy cycling — comfortable effort, 30 minutes. Low impact active recovery.';
        }
        if ($elliptical !== 'none') {
            return 'Easy elliptical — comfortable effort, 30 minutes. Low impact active recovery.';
        }
        if ($pool) {
            return 'Easy pool running or swimming — comfortable effort, 25-30 minutes.';
        }
        return 'Rest day or easy walk — keep it gentle and restorative.';
    }
}

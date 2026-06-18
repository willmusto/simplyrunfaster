<?php
/**
 * CoachAdjustments — capture layer for the Coaching Intelligence Layer (Phase 1).
 *
 * Every coach (or athlete-initiated) change to a planned workout is recorded as a
 * coach_adjustments row, with a frozen snapshot of the athlete's training context at
 * the moment of the change so patterns remain analyzable after the profile evolves.
 *
 * This is a pure capture pipe: it records, it does not analyze. Recording must never
 * be able to break the underlying action it is observing, so callers wrap record() in
 * a try/catch and a failure here is logged, not propagated.
 */
class CoachAdjustments
{
    /** Known before/after keys → coach_adjustments column suffixes. */
    private const FIELD_MAP = [
        'archetype_code' => 'archetype_code',
        'workout_type'   => 'workout_type',
        'duration_mins'  => 'duration_mins',
        'scheduled_date' => 'scheduled_date',
        'instructions'   => 'instructions',
    ];

    /**
     * Record a single adjustment and return the inserted row id (0 on failure).
     *
     * @param array $before Snapshot keys: archetype_code, workout_type, duration_mins,
     *                      scheduled_date, instructions (any subset; missing → NULL).
     * @param array $after  Same key shape as $before.
     */
    public static function record(
        int $plannedWorkoutId,
        int $athleteId,
        int $coachId,
        string $changeType,
        array $before,
        array $after,
        PDO $db
    ): int {
        try {
            $ctx = self::athleteContext($athleteId, $db);

            $cols = [
                'planned_workout_id' => $plannedWorkoutId,
                'athlete_id'         => $athleteId,
                'coach_id'           => $coachId,
                'adjusted_at'        => date('Y-m-d H:i:s'),
                'change_type'        => $changeType,
            ];
            foreach (self::FIELD_MAP as $key => $suffix) {
                $cols['before_' . $suffix] = self::norm($before[$key] ?? null);
                $cols['after_'  . $suffix] = self::norm($after[$key]  ?? null);
            }
            $cols['ctx_goal_distance']  = $ctx['goal_distance'];
            $cols['ctx_phase']          = $ctx['phase'];
            $cols['ctx_week_number']    = $ctx['week_number'];
            $cols['ctx_classification'] = $ctx['classification'];
            $cols['ctx_weekly_mins']    = $ctx['weekly_mins'];
            $cols['ctx_plan_week']      = $ctx['plan_week'];

            $names  = array_keys($cols);
            $place  = implode(', ', array_fill(0, count($names), '?'));
            $sql    = 'INSERT INTO coach_adjustments (`' . implode('`, `', $names) . '`) VALUES (' . $place . ')';
            $db->prepare($sql)->execute(array_values($cols));

            return (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('CoachAdjustments::record failed for workout ' . $plannedWorkoutId . ': ' . $e->getMessage());
            return 0;
        }
    }

    /** Empty string → NULL; everything else passes through (ints/strings/dates). */
    private static function norm($v)
    {
        if ($v === '' ) return null;
        return $v;
    }

    /**
     * Snapshot of the athlete's current training context. Derives phase / week numbers
     * from the active (else pending) plan window using the same proximity model the rest
     * of the app uses for a lightweight phase label.
     *
     * @return array{goal_distance:?string,phase:?string,week_number:?int,classification:?string,weekly_mins:?int,plan_week:?int}
     */
    public static function athleteContext(int $athleteId, PDO $db): array
    {
        $out = [
            'goal_distance' => null, 'phase' => null, 'week_number' => null,
            'classification' => null, 'weekly_mins' => null, 'plan_week' => null,
        ];

        $p = $db->prepare(
            'SELECT goal_race_distance, base_classification, current_weekly_minutes
             FROM athlete_profiles WHERE athlete_id = ? LIMIT 1'
        );
        $p->execute([$athleteId]);
        if ($prof = $p->fetch(PDO::FETCH_ASSOC)) {
            $out['goal_distance']  = $prof['goal_race_distance'] !== null ? (string)$prof['goal_race_distance'] : null;
            $out['classification'] = $prof['base_classification'] !== null ? (string)$prof['base_classification'] : null;
            $out['weekly_mins']    = $prof['current_weekly_minutes'] !== null ? (int)$prof['current_weekly_minutes'] : null;
        }

        $pl = $db->prepare(
            'SELECT plan_type, plan_start_date, plan_end_date FROM training_plans
             WHERE athlete_id = ? AND status IN ("active","pending_approval")
             ORDER BY FIELD(status, "active", "pending_approval"), id DESC LIMIT 1'
        );
        $pl->execute([$athleteId]);
        $plan = $pl->fetch(PDO::FETCH_ASSOC);
        if ($plan) {
            $out['phase'] = self::phaseLabel($plan);
            $start = strtotime((string)($plan['plan_start_date'] ?? ''));
            $end   = strtotime((string)($plan['plan_end_date'] ?? ''));
            if ($start !== false) {
                $week = (int)floor((time() - $start) / (7 * 86400)) + 1;
                $week = max(1, $week);
                if ($end !== false && $end > $start) {
                    $total = (int)ceil(($end - $start) / (7 * 86400));
                    $week  = min($week, max(1, $total));
                    $out['plan_week'] = min($week, max(1, $total));
                } else {
                    $out['plan_week'] = $week;
                }
                $out['week_number'] = $week;
            }
        }

        return $out;
    }

    /**
     * Lightweight phase label for the plan. Fixed-label plan types map directly;
     * race_cycle is approximated from how far through the window today falls — the
     * same model as CoachController::currentPlanPhase.
     */
    private static function phaseLabel(array $plan): ?string
    {
        $type  = (string)($plan['plan_type'] ?? '');
        $fixed = [
            'development_plan'  => 'base',
            'maintenance_plan'  => 'build',
            'return_to_running' => 'return',
            'recovery_block'    => 'recovery',
        ];
        if (isset($fixed[$type])) return $fixed[$type];
        if ($type !== 'race_cycle') {
            return $type !== '' ? $type : null;
        }
        $start = strtotime((string)($plan['plan_start_date'] ?? ''));
        $end   = strtotime((string)($plan['plan_end_date'] ?? ''));
        if ($start === false || $end === false || $end <= $start) return 'base';
        $frac = max(0.0, min(1.0, (time() - $start) / ($end - $start)));
        if ($frac >= 0.80) return 'taper';
        if ($frac >= 0.60) return 'peak';
        if ($frac >= 0.30) return 'build';
        return 'base';
    }
}

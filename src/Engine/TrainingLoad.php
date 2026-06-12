<?php
/**
 * TrainingLoad — computes ATL, CTL, TSB per athlete.
 *
 * ATL: rolling 7-day average of daily stress  ("fatigue")
 * CTL: rolling 42-day average of daily stress ("fitness")
 * TSB: CTL − ATL                             ("form")
 *
 * Daily stress = duration_minutes × intensity_factor.
 * Intensity factors are looked up from the workout's library template when
 * available; otherwise inferred from workout_type.
 */
class TrainingLoad
{
    // Fallback factors by workout_type (from engine spec Section 17)
    const TYPE_FACTORS = [
        'rest'        => 0.0,
        'recovery'    => 0.3,
        'cross_train' => 0.4,
        'easy'        => 0.5,
        'long'        => 0.6,
        'fartlek'     => 0.7,
        'hill'        => 0.7,
        'tempo'       => 0.8,
        'race_pace'   => 0.85,
        'interval'    => 1.0,
        'race'        => 1.35,
    ];

    /**
     * Recompute training load for an athlete over the last 60 days.
     * Writes to training_load table (INSERT … ON DUPLICATE KEY UPDATE).
     */
    public static function recompute(int $athleteId): void
    {
        $db = Database::get();

        // Pull all completed workouts in the last 60 days (covers the 42-day CTL window).
        // For archetype-generated workouts intensity_load is pre-computed on planned_workouts;
        // derive intensity_factor from it. Fall back to workout_library for legacy workouts.
        $stmt = $db->prepare(
            'SELECT cw.activity_date, cw.workout_type, cw.actual_duration, cw.rpe,
                    CASE
                        WHEN pw.intensity_load IS NOT NULL AND pw.target_duration > 0
                            THEN pw.intensity_load / pw.target_duration
                        ELSE wl.intensity_factor
                    END AS intensity_factor
             FROM completed_workouts cw
             LEFT JOIN planned_workouts pw ON pw.id = cw.planned_workout_id
             LEFT JOIN workout_library  wl ON wl.id = pw.workout_template_id
             WHERE cw.athlete_id = ?
               AND cw.activity_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
             ORDER BY cw.activity_date ASC'
        );
        $stmt->execute([$athleteId]);
        $rows = $stmt->fetchAll();

        // Build a map: date → total daily stress
        $dailyStress = [];
        foreach ($rows as $row) {
            $d      = $row['activity_date'];
            $stress = self::computeStress($row);
            $dailyStress[$d] = ($dailyStress[$d] ?? 0.0) + $stress;
        }

        // Compute ATL/CTL/TSB for each of the last 50 days and upsert
        $end   = new DateTime('today');
        $start = (new DateTime('today'))->modify('-50 days');

        $cur = clone $start;
        $upsert = $db->prepare(
            'INSERT INTO training_load (athlete_id, date, atl, ctl, tsb, daily_stress, computed_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               atl=VALUES(atl), ctl=VALUES(ctl), tsb=VALUES(tsb),
               daily_stress=VALUES(daily_stress), computed_at=NOW()'
        );

        while ($cur <= $end) {
            $date  = $cur->format('Y-m-d');
            $daily = round($dailyStress[$date] ?? 0.0, 3);
            $atl   = self::rollingAvg($dailyStress, $date, 7);
            $ctl   = self::rollingAvg($dailyStress, $date, 42);
            $tsb   = round($ctl - $atl, 3);

            $upsert->execute([$athleteId, $date, $atl, $ctl, $tsb, $daily]);
            $cur->modify('+1 day');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public static function computeStress(array $workout): float
    {
        $duration = (int)($workout['actual_duration'] ?? 0);
        if ($duration <= 0) return 0.0;

        $factor = isset($workout['intensity_factor']) && $workout['intensity_factor'] !== null
            ? (float)$workout['intensity_factor']
            : (self::TYPE_FACTORS[$workout['workout_type'] ?? 'easy'] ?? 0.5);

        return round($duration * $factor, 3);
    }

    private static function rollingAvg(array $dailyStress, string $endDate, int $days): float
    {
        $sum  = 0.0;
        $date = new DateTime($endDate);
        for ($i = 0; $i < $days; $i++) {
            $sum += $dailyStress[$date->format('Y-m-d')] ?? 0.0;
            $date->modify('-1 day');
        }
        return round($sum / $days, 3);
    }
}

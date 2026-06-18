<?php
/**
 * ResponseProfiler — Coaching Intelligence Layer, Phase 3 (athlete response modeling).
 *
 * Computes a small set of INTERPRETABLE per-athlete metrics from the athlete's own
 * history (completed_workouts, athlete_behavior_log, training_load, planned_workouts)
 * and stores them in athlete_response_profiles. Every metric carries a value, a
 * sample_size, and a confidence tier; when the sample is too small it reports "not
 * enough data" (value null) rather than guessing.
 *
 * These metrics individualize the Phase 3 predictions (PredictiveFlags) and surface
 * in the coach's athlete context panel. This is an engine, not AI — each metric is a
 * named, deterministic formula a coach can read.
 *
 * READ-ONLY against everything except its own athlete_response_profiles row.
 */
class ResponseProfiler
{
    /** Compute, persist, and return this athlete's response profile. */
    public static function recompute(int $athleteId, PDO $db): array
    {
        $profile = self::compute($athleteId, $db);
        self::store($athleteId, $profile, $db);
        return $profile;
    }

    /** Load the stored profile (decoded) or null when none computed yet. */
    public static function load(int $athleteId, PDO $db): ?array
    {
        try {
            $stmt = $db->prepare('SELECT computed_at, weeks_of_data, metrics_json FROM athlete_response_profiles WHERE athlete_id = ? LIMIT 1');
            $stmt->execute([$athleteId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            return [
                'computed_at'   => (string)$row['computed_at'],
                'weeks_of_data' => (int)$row['weeks_of_data'],
                'metrics'       => json_decode((string)$row['metrics_json'], true) ?: [],
            ];
        } catch (\Throwable $e) {
            error_log('ResponseProfiler::load failed for athlete ' . $athleteId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Compute the profile (no persistence). Returns
     * ['weeks_of_data'=>int, 'enough_data'=>bool, 'metrics'=>[...]].
     */
    public static function compute(int $athleteId, PDO $db): array
    {
        $weeks = self::weeksOfData($athleteId, $db);
        $win   = PredictiveConstants::PROFILE_WINDOW_DAYS;

        $completed   = self::fetchCompleted($db, $athleteId, $win);
        $compByWeek  = self::completionByWeek($db, $athleteId, $win);
        $load        = self::fetchTrainingLoad($db, $athleteId, $win);
        $plannedWeek = self::plannedMinutesByWeek($db, $athleteId, $win);

        $metrics = [
            'easy_rpe_delta'        => self::rpeDelta($completed, PredictiveConstants::EASY_TYPES,    $weeks, PredictiveConstants::MIN_SAMPLE_EASY_RPE,    'Easy-day effort vs prescribed'),
            'quality_rpe_delta'     => self::rpeDelta($completed, PredictiveConstants::QUALITY_TYPES, $weeks, PredictiveConstants::MIN_SAMPLE_QUALITY_RPE, 'Quality effort vs prescribed'),
            'volume_tolerance_mins' => self::volumeTolerance($completed, $compByWeek, $weeks),
            'recovery_days'         => self::recoverySignature($load, $weeks),
            'cutback_response'      => self::cutbackResponse($plannedWeek, $compByWeek, $weeks),
        ];

        return [
            'weeks_of_data' => $weeks,
            'enough_data'   => $weeks >= PredictiveConstants::MIN_WEEKS_DATA,
            'metrics'       => $metrics,
        ];
    }

    private static function store(int $athleteId, array $profile, PDO $db): void
    {
        try {
            $db->prepare(
                'INSERT INTO athlete_response_profiles (athlete_id, computed_at, weeks_of_data, metrics_json)
                 VALUES (?, NOW(), ?, ?)
                 ON DUPLICATE KEY UPDATE computed_at = NOW(), weeks_of_data = VALUES(weeks_of_data), metrics_json = VALUES(metrics_json)'
            )->execute([$athleteId, (int)$profile['weeks_of_data'], json_encode($profile['metrics'])]);
        } catch (\Throwable $e) {
            error_log('ResponseProfiler::store failed for athlete ' . $athleteId . ': ' . $e->getMessage());
        }
    }

    // ── Metric computations ──────────────────────────────────────────────────

    /** Mean (mapped effort − expected effort) across completed sessions whose type is in $types. */
    private static function rpeDelta(array $completed, array $types, int $weeks, int $minSample, string $label): array
    {
        $sum = 0.0; $n = 0;
        foreach ($completed as $cw) {
            $eff = (string)($cw['effort_descriptor'] ?? '');
            if (!isset(PredictiveConstants::EFFORT_MAP[$eff])) continue;
            $type = strtolower((string)($cw['pw_type'] ?? '') ?: (string)($cw['cw_type'] ?? ''));
            if (!in_array($type, $types, true)) continue;
            if (!isset(PredictiveConstants::EXPECTED_EFFORT[$type])) continue;
            $sum += PredictiveConstants::EFFORT_MAP[$eff] - PredictiveConstants::EXPECTED_EFFORT[$type];
            $n++;
        }
        $conf  = PredictiveConstants::metricConfidence($weeks, $n, $minSample);
        $value = ($conf === PredictiveConstants::CONF_NONE) ? null : round($sum / max(1, $n), 2);
        return ['value' => $value, 'sample_size' => $n, 'confidence' => $conf, 'label' => $label, 'unit' => 'effort_pts'];
    }

    /** Highest weekly minutes sustained (≥ VOLUME_SUSTAIN_WEEKS consecutive weeks) at compliance ≥ OK. */
    private static function volumeTolerance(array $completed, array $compByWeek, int $weeks): array
    {
        // Weekly minutes by absolute week index.
        $minsByWeek = [];
        foreach ($completed as $cw) {
            $wi = self::weekIndex((string)$cw['activity_date']);
            $minsByWeek[$wi] = ($minsByWeek[$wi] ?? 0) + (int)($cw['actual_duration'] ?? 0);
        }
        $weekKeys = array_keys($minsByWeek);
        sort($weekKeys);
        $sample = count($weekKeys);

        // Walk consecutive runs of compliant weeks; sustained level = min minutes in a run.
        $best = null; $runStart = null; $runMins = [];
        $flush = function () use (&$best, &$runMins) {
            if (count($runMins) >= PredictiveConstants::VOLUME_SUSTAIN_WEEKS) {
                $level = min($runMins);
                if ($best === null || $level > $best) $best = $level;
            }
            $runMins = [];
        };
        $prev = null;
        foreach ($weekKeys as $wi) {
            $compliant = isset($compByWeek[$wi]) && $compByWeek[$wi] >= PredictiveConstants::VOLUME_COMPLIANCE_OK;
            $consecutive = ($prev !== null && $wi === $prev + 1);
            if (!$consecutive) $flush();
            if ($compliant) {
                $runMins[] = $minsByWeek[$wi];
            } else {
                $flush();
            }
            $prev = $wi;
        }
        $flush();

        $conf = PredictiveConstants::metricConfidence($weeks, $sample, PredictiveConstants::MIN_SAMPLE_VOLUME_WEEKS);
        if ($conf === PredictiveConstants::CONF_NONE || $best === null) {
            // Sample may be fine but no sustained compliant block observed yet.
            $value = ($best === null) ? null : (int)$best;
            if ($best === null) $conf = PredictiveConstants::CONF_NONE;
            return ['value' => $value, 'sample_size' => $sample, 'confidence' => $conf,
                    'label' => 'Tolerated weekly volume', 'unit' => 'mins'];
        }
        return ['value' => (int)$best, 'sample_size' => $sample, 'confidence' => $conf,
                'label' => 'Tolerated weekly volume', 'unit' => 'mins'];
    }

    /** Mean days from a fatigue dip (TSB < FATIGUE) back to normalized (TSB ≥ TARGET). */
    private static function recoverySignature(array $load, int $weeks): array
    {
        // Chronological TSB by date.
        usort($load, static fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
        $episodes = [];
        $dipStart = null;
        foreach ($load as $row) {
            $tsb = (float)$row['tsb'];
            $d   = (string)$row['date'];
            if ($dipStart === null) {
                if ($tsb < PredictiveConstants::RECOVERY_TSB_FATIGUE) $dipStart = $d;
            } else {
                if ($tsb >= PredictiveConstants::RECOVERY_TSB_TARGET) {
                    $days = (int)round((strtotime($d) - strtotime($dipStart)) / 86400);
                    if ($days > 0) $episodes[] = $days;
                    $dipStart = null;
                }
            }
        }
        $n    = count($episodes);
        $conf = PredictiveConstants::metricConfidence($weeks, $n, PredictiveConstants::MIN_SAMPLE_RECOVERY);
        $value = ($conf === PredictiveConstants::CONF_NONE) ? null : round(array_sum($episodes) / max(1, $n), 1);
        return ['value' => $value, 'sample_size' => $n, 'confidence' => $conf,
                'label' => 'Typical days to recover', 'unit' => 'days'];
    }

    /** Mean compliance bounce (cutback week − prior week) across detected cutback weeks. */
    private static function cutbackResponse(array $plannedByWeek, array $compByWeek, int $weeks): array
    {
        $weekKeys = array_keys($plannedByWeek);
        sort($weekKeys);
        $bounces = [];
        foreach ($weekKeys as $wi) {
            $prevWi = $wi - 1;
            if (!isset($plannedByWeek[$prevWi]) || $plannedByWeek[$prevWi] <= 0) continue;
            $isCutback = $plannedByWeek[$wi] < PredictiveConstants::CUTBACK_RATIO * $plannedByWeek[$prevWi];
            if (!$isCutback) continue;
            if (!isset($compByWeek[$wi], $compByWeek[$prevWi])) continue;
            $bounces[] = $compByWeek[$wi] - $compByWeek[$prevWi];
        }
        $n    = count($bounces);
        $conf = PredictiveConstants::metricConfidence($weeks, $n, PredictiveConstants::MIN_SAMPLE_CUTBACK);
        $value = ($conf === PredictiveConstants::CONF_NONE) ? null : round(array_sum($bounces) / max(1, $n), 3);
        return ['value' => $value, 'sample_size' => $n, 'confidence' => $conf,
                'label' => 'Compliance bounce on cutback weeks', 'unit' => 'ratio'];
    }

    // ── Data access ────────────────────────────────────────────────────────────

    private static function weeksOfData(int $athleteId, PDO $db): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(DISTINCT YEARWEEK(logged_at, 3)) FROM athlete_behavior_log WHERE athlete_id = ?'
        );
        $stmt->execute([$athleteId]);
        return (int)$stmt->fetchColumn();
    }

    /** Completed sessions in the window with planned/completed type + effort + duration. */
    private static function fetchCompleted(PDO $db, int $athleteId, int $windowDays): array
    {
        $stmt = $db->prepare(
            "SELECT cw.activity_date, cw.actual_duration, cw.effort_descriptor,
                    cw.workout_type AS cw_type, pw.workout_type AS pw_type
             FROM completed_workouts cw
             LEFT JOIN planned_workouts pw ON pw.id = cw.planned_workout_id
             WHERE cw.athlete_id = ? AND cw.activity_date >= (CURDATE() - INTERVAL " . (int)$windowDays . " DAY)
             ORDER BY cw.activity_date ASC"
        );
        $stmt->execute([$athleteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Mean completion_rate per absolute week index, from athlete_behavior_log. */
    private static function completionByWeek(PDO $db, int $athleteId, int $windowDays): array
    {
        $stmt = $db->prepare(
            "SELECT logged_at, metric_value FROM athlete_behavior_log
             WHERE athlete_id = ? AND metric_type = 'completion_rate'
               AND logged_at >= (CURDATE() - INTERVAL " . (int)$windowDays . " DAY)"
        );
        $stmt->execute([$athleteId]);
        $sum = []; $cnt = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $wi = self::weekIndex((string)$r['logged_at']);
            $sum[$wi] = ($sum[$wi] ?? 0) + (float)$r['metric_value'];
            $cnt[$wi] = ($cnt[$wi] ?? 0) + 1;
        }
        $out = [];
        foreach ($sum as $wi => $s) { $out[$wi] = $s / max(1, $cnt[$wi]); }
        return $out;
    }

    private static function fetchTrainingLoad(PDO $db, int $athleteId, int $windowDays): array
    {
        $stmt = $db->prepare(
            "SELECT `date`, tsb, ctl FROM training_load
             WHERE athlete_id = ? AND `date` >= (CURDATE() - INTERVAL " . (int)$windowDays . " DAY)"
        );
        $stmt->execute([$athleteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Planned minutes per absolute week index (non-cancelled). */
    private static function plannedMinutesByWeek(PDO $db, int $athleteId, int $windowDays): array
    {
        $stmt = $db->prepare(
            "SELECT scheduled_date, target_duration FROM planned_workouts
             WHERE athlete_id = ? AND (cancelled = 0 OR cancelled IS NULL)
               AND scheduled_date >= (CURDATE() - INTERVAL " . (int)$windowDays . " DAY)"
        );
        $stmt->execute([$athleteId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $wi = self::weekIndex((string)$r['scheduled_date']);
            $out[$wi] = ($out[$wi] ?? 0) + (int)($r['target_duration'] ?? 0);
        }
        return $out;
    }

    /** Absolute week index (Monday-aligned weeks since epoch) for a date/datetime string. */
    private static function weekIndex(string $dateOrDatetime): int
    {
        $t = strtotime($dateOrDatetime);
        if ($t === false) return 0;
        // Shift so weeks break on Monday (epoch 1970-01-01 was a Thursday → +3 days).
        return (int)floor(($t + 3 * 86400) / (7 * 86400));
    }
}

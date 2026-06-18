<?php
/**
 * CoachingIntelligence — daily behavior-metric capture + pattern flagging
 * (Coaching Intelligence Layer, Phase 1, Parts 4 & 5).
 *
 * run() is invoked once per day from the daily cron (cron_notifications.php). It logs
 * raw behavior signals to athlete_behavior_log, then evaluates a fixed set of patterns
 * into coaching_intelligence_flags. This is the capture/surface pipe — there is no
 * predictive modeling or cross-athlete analysis here (that is Phase 2+).
 *
 * Requires CoachAdjustments (for the phase / plan-week context helper).
 */
class CoachingIntelligence
{
    /** completed_workouts.effort_descriptor → numeric effort. */
    private const EFFORT_MAP = [
        'easy' => 3, 'moderate' => 5, 'hard' => 7, 'very_hard' => 9, 'discomfort' => 10,
    ];

    /**
     * Planned workout_type → expected effort. Covers the real planned_workouts ENUM
     * plus the alias names used in the spec map (easy_run/long_run/hill_session/workout).
     */
    private const EXPECTED_EFFORT = [
        'easy' => 3, 'easy_run' => 3, 'recovery' => 2, 'long' => 5, 'long_run' => 5,
        'tempo' => 7, 'workout' => 7, 'hill' => 7, 'hill_session' => 7, 'fartlek' => 6,
        'speed' => 8, 'race_pace' => 8, 'interval' => 8, 'plyometric' => 7, 'cross_train' => 3,
    ];

    /** Planned types whose completions carry a meaningful RPE-vs-target signal. */
    private const RPE_TYPES = [
        'long', 'long_run', 'tempo', 'interval', 'hill', 'hill_session', 'fartlek',
        'speed', 'race_pace', 'workout', 'plyometric',
    ];

    /**
     * Run a full daily pass. Returns ['behavior'=>int, 'flags'=>int].
     */
    public static function run(PDO $db, bool $verbose = false): array
    {
        $behavior = 0;
        $flags    = 0;

        $athletes = $db->query(
            "SELECT a.id AS athlete_id, a.user_id, a.coach_id, u.name
             FROM athletes a
             JOIN users u ON u.id = a.user_id
             JOIN training_plans tp ON tp.athlete_id = a.id AND tp.status = 'active'
             WHERE a.status = 'active'
             GROUP BY a.id, a.user_id, a.coach_id, u.name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($athletes as $a) {
            $athleteId = (int)$a['athlete_id'];
            $ctx       = CoachAdjustments::athleteContext($athleteId, $db);
            $planWeek  = $ctx['plan_week'];
            $phase     = $ctx['phase'];

            $behavior += self::logCompletionRate($db, $athleteId, $planWeek, $phase);
            $behavior += self::logRpeVsTarget($db, $athleteId, $planWeek, $phase);
            $behavior += self::logEngagementScore($db, $athleteId, (int)$a['user_id'], $planWeek, $phase);

            $flags += self::evaluateFlags($db, $athleteId, (int)$a['coach_id'], (string)$a['name']);

            if ($verbose) {
                echo "  {$a['name']}: behavior+flags pass done\n";
            }
        }

        return ['behavior' => $behavior, 'flags' => $flags];
    }

    // ── Behavior metrics ───────────────────────────────────────────────────────

    /** completion_rate: completed (7d) / planned-visible (7d up to today). Skips when no planned. */
    private static function logCompletionRate(PDO $db, int $athleteId, ?int $planWeek, ?string $phase): int
    {
        $planned = (int)self::scalar($db,
            "SELECT COUNT(*) FROM planned_workouts
             WHERE athlete_id = ? AND visible_to_athlete = 1 AND (cancelled = 0 OR cancelled IS NULL)
               AND scheduled_date >= (NOW() - INTERVAL 7 DAY) AND scheduled_date <= NOW()",
            [$athleteId]
        );
        if ($planned <= 0) return 0;

        $completed = (int)self::scalar($db,
            "SELECT COUNT(*) FROM completed_workouts
             WHERE athlete_id = ? AND activity_date >= (NOW() - INTERVAL 7 DAY)",
            [$athleteId]
        );

        $ratio = $completed / $planned;
        if ($ratio > 1.0) $ratio = 1.0;

        self::insertMetric($db, $athleteId, 'completion_rate', round($ratio, 4),
            json_encode(['completed' => $completed, 'planned' => $planned]), $planWeek, $phase);
        return 1;
    }

    /**
     * rpe_vs_target: per qualifying completed quality/long workout (effort_descriptor not
     * null) in the last 7 days, mapped_actual - mapped_expected. Deduped by completed-
     * workout id so a session is logged once, never re-logged on later daily runs.
     */
    private static function logRpeVsTarget(PDO $db, int $athleteId, ?int $planWeek, ?string $phase): int
    {
        $stmt = $db->prepare(
            "SELECT cw.id, cw.effort_descriptor, cw.workout_type AS cw_type, pw.workout_type AS pw_type
             FROM completed_workouts cw
             LEFT JOIN planned_workouts pw ON pw.id = cw.planned_workout_id
             WHERE cw.athlete_id = ? AND cw.effort_descriptor IS NOT NULL
               AND cw.activity_date >= (NOW() - INTERVAL 7 DAY)
             ORDER BY cw.activity_date ASC, cw.id ASC"
        );
        $stmt->execute([$athleteId]);

        $dupe = $db->prepare(
            "SELECT 1 FROM athlete_behavior_log
             WHERE athlete_id = ? AND metric_type = 'rpe_vs_target' AND metric_context LIKE ? LIMIT 1"
        );

        $logged = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cw) {
            $effort = (string)$cw['effort_descriptor'];
            if (!isset(self::EFFORT_MAP[$effort])) continue;

            // Prefer the planned workout's type for the target; fall back to the completed type.
            $type = (string)($cw['pw_type'] ?? '') ?: (string)($cw['cw_type'] ?? '');
            $type = strtolower($type);
            if (!in_array($type, self::RPE_TYPES, true)) continue;
            if (!isset(self::EXPECTED_EFFORT[$type])) continue;

            $cwId = (int)$cw['id'];
            $dupe->execute([$athleteId, '%"cwid":' . $cwId . '%']);
            if ($dupe->fetchColumn()) continue;   // already logged this session

            $value = self::EFFORT_MAP[$effort] - self::EXPECTED_EFFORT[$type];
            self::insertMetric($db, $athleteId, 'rpe_vs_target', (float)$value,
                json_encode(['cwid' => $cwId, 'actual' => self::EFFORT_MAP[$effort], 'expected' => self::EXPECTED_EFFORT[$type], 'type' => $type]),
                $planWeek, $phase);
            $logged++;
        }
        return $logged;
    }

    /** engagement_score: composite 0-100 (messages + recency of training + recency of login). */
    private static function logEngagementScore(PDO $db, int $athleteId, int $userId, ?int $planWeek, ?string $phase): int
    {
        $msgs = (int)self::scalar($db,
            "SELECT COUNT(*) FROM messages
             WHERE athlete_id = ? AND sender_role = 'athlete' AND sent_at >= (NOW() - INTERVAL 7 DAY)",
            [$athleteId]
        );
        $msgPts = $msgs >= 3 ? 30 : ($msgs >= 1 ? 20 : 0);

        $lastWorkout = self::scalar($db,
            "SELECT MAX(activity_date) FROM completed_workouts WHERE athlete_id = ?", [$athleteId]
        );
        $workoutPts = 0;
        if ($lastWorkout) {
            $days = self::daysSince((string)$lastWorkout);
            $workoutPts = $days <= 1 ? 35 : ($days <= 3 ? 25 : ($days <= 6 ? 10 : 0));
        }

        $lastLogin = self::scalar($db, "SELECT last_login_at FROM users WHERE id = ?", [$userId]);
        $loginPts = 0;
        if ($lastLogin) {
            $days = self::daysSince((string)$lastLogin);
            $loginPts = $days <= 1 ? 25 : ($days <= 3 ? 15 : ($days <= 7 ? 5 : 0));
        }

        $score = $msgPts + $workoutPts + $loginPts;
        self::insertMetric($db, $athleteId, 'engagement_score', (float)$score,
            json_encode(['messages' => $msgPts, 'workout' => $workoutPts, 'login' => $loginPts]), $planWeek, $phase);
        return 1;
    }

    // ── Flags ──────────────────────────────────────────────────────────────────

    private static function evaluateFlags(PDO $db, int $athleteId, int $coachId, string $name): int
    {
        if ($coachId <= 0) return 0;   // can't attribute a flag without a coach
        $count = 0;

        // 1 & 2 — RPE trending (last 3 rpe_vs_target entries, min 3).
        $rpe = self::lastValues($db, $athleteId, 'rpe_vs_target', 3);
        if (count($rpe) >= 3) {
            $avg = array_sum($rpe) / count($rpe);
            if ($avg > 1.5) {
                $count += self::raise($db, $athleteId, $coachId, 'rpe_trending_high', 'warning',
                    "{$name} is working harder than prescribed",
                    'Effort has averaged ' . self::signed($avg) . ' above target across the last 3 quality sessions. '
                    . 'This may indicate the current intensity is too high or the athlete is not recovering adequately between sessions.',
                    'Consider reducing quality session intensity or substituting aerobic threshold work for 2 weeks. Check in with the athlete.');
            } elseif ($avg < -1.5) {
                $count += self::raise($db, $athleteId, $coachId, 'rpe_trending_low', 'info',
                    "{$name} is working easier than prescribed",
                    'Effort has averaged ' . self::signed($avg) . ' below target. The athlete may be ready for more challenge.',
                    'Consider advancing volume or intensity. This athlete may be adapting ahead of schedule.');
            }
        }

        // 3 & 4 — completion-rate patterns.
        $compRows = self::lastRows($db, $athleteId, 'completion_rate', 8);
        if (!empty($compRows)) {
            $current = (float)$compRows[0]['metric_value'];
            $prior   = self::valueAround7DaysBefore($compRows);

            if ($current < 0.60 && $prior !== null && $prior > 0.75) {
                $count += self::raise($db, $athleteId, $coachId, 'compliance_dropping', 'warning',
                    "{$name} compliance dropped this week",
                    'Completion rate dropped from ' . self::pct($prior) . ' to ' . self::pct($current) . ' this week.',
                    'Check in with the athlete. Consider whether the plan needs adjustment.');
            }

            $last3 = array_slice($compRows, 0, 3);
            if (count($last3) >= 3 && self::allEqual($last3, 1.0)) {
                $count += self::raise($db, $athleteId, $coachId, 'compliance_streak', 'opportunity',
                    "{$name} has hit every workout for 3 weeks",
                    '100% compliance for 3 consecutive weeks. The athlete is responding well to the current plan.',
                    'Consider whether volume or intensity can be advanced ahead of schedule.');
            }
        }

        // 5 & 6 — engagement patterns.
        $engRows = self::lastRows($db, $athleteId, 'engagement_score', 8);
        if (!empty($engRows)) {
            $current = (float)$engRows[0]['metric_value'];
            $prior   = self::valueAround7DaysBefore($engRows);

            if ($current < 40 && $prior !== null && $prior > 60) {
                $count += self::raise($db, $athleteId, $coachId, 'engagement_dropping', 'warning',
                    "{$name} engagement is dropping",
                    'Engagement score dropped from ' . round($prior) . ' to ' . round($current) . ' this week.',
                    'Personal outreach recommended within 24 hours.');
            }

            $last3 = array_slice($engRows, 0, 3);
            if (count($last3) >= 3 && self::allBelow($last3, 20.0)) {
                $count += self::raise($db, $athleteId, $coachId, 'dropout_risk', 'warning',
                    "{$name} may be at risk of dropping out",
                    'Engagement has been critically low for 3 or more consecutive days.',
                    'Immediate personal outreach recommended. Consider pausing the plan until you reconnect with the athlete.');
            }
        }

        return $count;
    }

    /**
     * Insert a coaching_intelligence_flag unless one of the same type for the same athlete
     * was created within the last 14 days (regardless of status). Returns 1 on insert, 0 on skip.
     */
    private static function raise(
        PDO $db, int $athleteId, int $coachId, string $type, string $severity,
        string $title, string $detail, ?string $action
    ): int {
        $dupe = $db->prepare(
            "SELECT 1 FROM coaching_intelligence_flags
             WHERE athlete_id = ? AND flag_type = ? AND created_at >= (NOW() - INTERVAL 14 DAY) LIMIT 1"
        );
        $dupe->execute([$athleteId, $type]);
        if ($dupe->fetchColumn()) return 0;

        $db->prepare(
            "INSERT INTO coaching_intelligence_flags
              (athlete_id, coach_id, created_at, flag_type, severity, title, detail, suggested_action, status)
             VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, 'open')"
        )->execute([$athleteId, $coachId, $type, $severity, $title, $detail, $action]);
        return 1;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private static function insertMetric(PDO $db, int $athleteId, string $type, float $value, ?string $context, ?int $planWeek, ?string $phase): void
    {
        $db->prepare(
            "INSERT INTO athlete_behavior_log (athlete_id, logged_at, metric_type, metric_value, metric_context, plan_week, phase)
             VALUES (?, NOW(), ?, ?, ?, ?, ?)"
        )->execute([$athleteId, $type, $value, $context, $planWeek, $phase]);
    }

    private static function scalar(PDO $db, string $sql, array $params)
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** Most recent N metric values (numeric), newest first. */
    private static function lastValues(PDO $db, int $athleteId, string $type, int $n): array
    {
        $rows = self::lastRows($db, $athleteId, $type, $n);
        return array_map(static fn($r) => (float)$r['metric_value'], $rows);
    }

    /** Most recent N metric rows (metric_value + logged_at), newest first. */
    private static function lastRows(PDO $db, int $athleteId, string $type, int $n): array
    {
        $stmt = $db->prepare(
            "SELECT metric_value, logged_at FROM athlete_behavior_log
             WHERE athlete_id = ? AND metric_type = ?
             ORDER BY logged_at DESC, id DESC LIMIT " . (int)$n
        );
        $stmt->execute([$athleteId, $type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * The metric value from "about 7 days before" the most recent row: the newest row whose
     * logged_at is at least 6 days older than the latest. Null when none that old exists.
     */
    private static function valueAround7DaysBefore(array $rowsDesc): ?float
    {
        if (count($rowsDesc) < 2) return null;
        $latest = strtotime((string)$rowsDesc[0]['logged_at']);
        if ($latest === false) return null;
        foreach ($rowsDesc as $i => $r) {
            if ($i === 0) continue;
            $t = strtotime((string)$r['logged_at']);
            if ($t !== false && ($latest - $t) >= 6 * 86400) {
                return (float)$r['metric_value'];
            }
        }
        return null;
    }

    private static function allEqual(array $rows, float $target): bool
    {
        foreach ($rows as $r) { if ((float)$r['metric_value'] !== $target) return false; }
        return true;
    }

    private static function allBelow(array $rows, float $threshold): bool
    {
        foreach ($rows as $r) { if ((float)$r['metric_value'] >= $threshold) return false; }
        return true;
    }

    private static function daysSince(string $dateOrDatetime): int
    {
        $t = strtotime($dateOrDatetime);
        if ($t === false) return 9999;
        return (int)floor((time() - $t) / 86400);
    }

    private static function signed(float $v): string
    {
        return ($v >= 0 ? '+' : '') . number_format($v, 1);
    }

    private static function pct(float $ratio): string
    {
        return round($ratio * 100) . '%';
    }
}

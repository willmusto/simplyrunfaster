<?php
/**
 * PredictiveFlags — Coaching Intelligence Layer, Phase 3 (forward-looking flags).
 *
 * Four deterministic, interpretable predictions over an athlete's own history. Each
 * is a named formula reading athlete_behavior_log, training_load and completed_workouts
 * (plus the individualized ResponseProfiler metrics); thresholds live in
 * PredictiveConstants. NO ML, no opaque scoring — a coach can read exactly why a flag
 * fired.
 *
 *   predicted_fatigue      — sustained volume ramp + RPE trending high + TSB sharply
 *                            negative (1–2 wk horizon).
 *   injury_risk_pattern    — volume spike + RPE trending high SIMULTANEOUSLY. Framed
 *                            as a load PATTERN for coach attention, never a diagnosis.
 *   predicted_dropout      — engagement TRAJECTORY (declining slope) + low absolute.
 *                            Coexists with Phase 1 engagement_dropping/dropout_risk.
 *   adaptation_ahead       — high compliance + quality RPE trending easy + CTL rising
 *                            without fatigue → a coach-approved PROPOSAL to advance the
 *                            plan (accept routes through the EXISTING regeneration flow;
 *                            never autonomous).
 *
 * Each flag is the single open coaching_intelligence_flags row of its type per athlete:
 * recompute UPSERTs it (refreshing confidence/horizon/detail) and RESOLVES (auto-
 * dismisses) it when the condition clears. WRITES ONLY to coaching_intelligence_flags
 * (its own intelligence table). Never calls PlanGenerator.
 */
class PredictiveFlags
{
    public const TYPES = ['predicted_fatigue', 'injury_risk_pattern', 'predicted_dropout', 'adaptation_ahead'];

    /**
     * Recompute response profiles + predictive flags for every active athlete with a
     * coach. Returns ['profiles'=>int, 'open'=>int, 'resolved'=>int].
     */
    public static function run(PDO $db, bool $verbose = false): array
    {
        $out = ['profiles' => 0, 'open' => 0, 'resolved' => 0];

        $athletes = $db->query(
            "SELECT a.id AS athlete_id, a.coach_id, u.name
             FROM athletes a JOIN users u ON u.id = a.user_id
             WHERE a.status = 'active' AND a.coach_id IS NOT NULL AND a.coach_id > 0"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($athletes as $a) {
            $athleteId = (int)$a['athlete_id'];
            $coachId   = (int)$a['coach_id'];
            try {
                $profile = ResponseProfiler::recompute($athleteId, $db);
                $out['profiles']++;
                $r = self::evaluateAthlete($athleteId, $coachId, $db, $profile);
                $out['open']     += $r['open'];
                $out['resolved'] += $r['resolved'];
                if ($verbose) echo "  {$a['name']}: open+={$r['open']} resolved+={$r['resolved']}\n";
            } catch (\Throwable $e) {
                error_log('PredictiveFlags athlete ' . $athleteId . ' failed: ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Evaluate all four predictions for one athlete. $profile is the ResponseProfiler
     * payload (recomputed by the caller). Returns ['open'=>int,'resolved'=>int].
     */
    public static function evaluateAthlete(int $athleteId, int $coachId, PDO $db, ?array $profile = null): array
    {
        $open = 0; $resolved = 0;
        if ($coachId <= 0) return ['open' => 0, 'resolved' => 0];

        $profile = $profile ?? ResponseProfiler::load($athleteId, $db);
        $weeks   = (int)($profile['weeks_of_data'] ?? 0);

        // Gather named inputs once.
        $in = self::inputs($db, $athleteId, $weeks);

        $act = static function (?bool $fired) use (&$open, &$resolved) {
            if ($fired === true) $open++; elseif ($fired === false) $resolved++;
        };

        $act(self::evalFatigue($db, $athleteId, $coachId, $weeks, $in));
        $act(self::evalInjury($db, $athleteId, $coachId, $weeks, $in));
        $act(self::evalDropout($db, $athleteId, $coachId, $weeks, $in));
        $act(self::evalAdaptation($db, $athleteId, $coachId, $weeks, $in, $profile));

        return ['open' => $open, 'resolved' => $resolved];
    }

    // ── Predictions ─────────────────────────────────────────────────────────────

    /**
     * predicted_fatigue: sustained volume ramp ≥ RAMP_RATIO (vs 2 weeks ago) AND RPE
     * trending > +RPE_DELTA AND TSB < TSB_DROP and still falling.
     * Returns true (fired/refreshed), false (resolved), or null (no change).
     */
    private static function evalFatigue(PDO $db, int $aid, int $cid, int $weeks, array $in): ?bool
    {
        $C = PredictiveConstants::class;
        $haveInputs = $in['rpe_n'] >= $C::RPE_TREND_SESSIONS && $in['vol_2wk_ago'] > 0 && $in['tsb_now'] !== null && $in['tsb_week_ago'] !== null;
        $conf = $C::metricConfidence($weeks, $in['rpe_n'], $C::RPE_TREND_SESSIONS);

        $ramp = $haveInputs && $in['vol_last7'] >= $C::FATIGUE_RAMP_RATIO * $in['vol_2wk_ago'];
        $fires = $haveInputs && $conf !== $C::CONF_NONE
            && $ramp
            && $in['rpe_avg'] > $C::FATIGUE_RPE_DELTA
            && $in['tsb_now'] < $C::FATIGUE_TSB_DROP
            && $in['tsb_now'] < $in['tsb_week_ago'];

        if (!$fires) return self::resolve($db, $aid, 'predicted_fatigue');

        $rampPct = (int)round(($in['vol_last7'] / max(1, $in['vol_2wk_ago']) - 1) * 100);
        $detail  = 'Training load is building toward fatigue: weekly volume up ' . $rampPct
                 . '% over two weeks, recent quality effort averaging ' . self::signed($in['rpe_avg'])
                 . ' above prescribed, and TSB at ' . round($in['tsb_now'])
                 . ' and still falling. Without easing soon this athlete is likely to be overreached within the next week or two.';
        return self::upsert($db, $aid, $cid, 'predicted_fatigue', 'warning',
            'Fatigue likely building',
            $detail,
            'Consider an earlier cutback or reducing quality-session intensity over the next 1–2 weeks.',
            $conf, $C::FATIGUE_HORIZON_DAYS);
    }

    /**
     * injury_risk_pattern: acute:chronic volume spike ≥ SPIKE_RATIO AND RPE trending
     * > +RPE_DELTA SIMULTANEOUSLY. Framed as a load PATTERN (principle 4) — never a
     * diagnosis or a guarantee.
     */
    private static function evalInjury(PDO $db, int $aid, int $cid, int $weeks, array $in): ?bool
    {
        $C = PredictiveConstants::class;
        $haveInputs = $in['rpe_n'] >= $C::RPE_TREND_SESSIONS && $in['vol_chronic_weekly'] > 0;
        $conf = $C::metricConfidence($weeks, $in['rpe_n'], $C::RPE_TREND_SESSIONS);

        $spike = $haveInputs && $in['vol_last7'] >= $C::INJURY_SPIKE_RATIO * $in['vol_chronic_weekly'];
        $fires = $haveInputs && $conf !== $C::CONF_NONE && $spike && $in['rpe_avg'] > $C::INJURY_RPE_DELTA;

        if (!$fires) return self::resolve($db, $aid, 'injury_risk_pattern');

        $ratio  = round($in['vol_last7'] / max(1, $in['vol_chronic_weekly']), 2);
        $detail = 'This is a training-load PATTERN associated with elevated injury risk — worth a look, not a diagnosis or a prediction of injury. '
                . 'This week\'s volume is ' . $ratio . '× the recent average while quality effort is running '
                . self::signed($in['rpe_avg']) . ' above prescribed. A volume spike and rising effort together are the classic combination worth easing.';
        return self::upsert($db, $aid, $cid, 'injury_risk_pattern', 'warning',
            'Load pattern worth a look',
            $detail,
            'Review the recent volume jump with the athlete; consider flattening the ramp. Coach judgement only — not medical advice.',
            $conf, $C::INJURY_HORIZON_DAYS);
    }

    /**
     * predicted_dropout: engagement TRAJECTORY — declining slope (≤ SLOPE_PER_WEEK) AND
     * low absolute current score (< ABS_SCORE), over ≥ MIN_POINTS samples. Forward-
     * looking; coexists with (does not replace) Phase 1 engagement_dropping/dropout_risk.
     */
    private static function evalDropout(PDO $db, int $aid, int $cid, int $weeks, array $in): ?bool
    {
        $C = PredictiveConstants::class;
        $haveInputs = $in['eng_n'] >= $C::DROPOUT_MIN_POINTS && $in['eng_slope_week'] !== null && $in['eng_current'] !== null;
        $conf = $C::metricConfidence($weeks, $in['eng_n'], $C::DROPOUT_MIN_POINTS);

        $fires = $haveInputs && $conf !== $C::CONF_NONE
            && $in['eng_slope_week'] <= $C::DROPOUT_SLOPE_PER_WEEK
            && $in['eng_current'] < $C::DROPOUT_ABS_SCORE;

        if (!$fires) return self::resolve($db, $aid, 'predicted_dropout');

        $detail = 'Engagement is on a downward trajectory: falling about ' . abs(round($in['eng_slope_week'], 1))
                . ' points per week and now at ' . round($in['eng_current'])
                . '. The trend (not just today\'s number) suggests rising dropout risk over the coming weeks.';
        return self::upsert($db, $aid, $cid, 'predicted_dropout', 'warning',
            'Engagement trending toward dropout',
            $detail,
            'A proactive check-in now, while the trajectory is still shallow, is more effective than waiting for engagement to bottom out.',
            $conf, $C::DROPOUT_HORIZON_DAYS);
    }

    /**
     * adaptation_ahead: compliance ≥ ADAPT_COMPLIANCE AND quality RPE trending easy
     * (≤ ADAPT_QUALITY_RPE_DELTA) AND CTL rising (slope > ADAPT_CTL_SLOPE) AND no open
     * fatigue/injury signal → an OPPORTUNITY proposal to advance the plan. The coach
     * accepts (routes through the existing regeneration approval flow) or dismisses.
     */
    private static function evalAdaptation(PDO $db, int $aid, int $cid, int $weeks, array $in, ?array $profile): ?bool
    {
        $C = PredictiveConstants::class;
        $haveInputs = $weeks >= $C::ADAPT_MIN_WEEKS && $in['rpe_n'] >= $C::RPE_TREND_SESSIONS
            && $in['compliance_avg'] !== null && $in['ctl_slope_week'] !== null;
        $conf = $C::metricConfidence($weeks, $in['rpe_n'], $C::RPE_TREND_SESSIONS);

        $noNegativeSignals = !self::flagOpen($db, $aid, 'predicted_fatigue') && !self::flagOpen($db, $aid, 'injury_risk_pattern');

        $fires = $haveInputs && $conf !== $C::CONF_NONE
            && $in['compliance_avg'] >= $C::ADAPT_COMPLIANCE
            && $in['rpe_avg'] <= $C::ADAPT_QUALITY_RPE_DELTA
            && $in['ctl_slope_week'] > $C::ADAPT_CTL_SLOPE
            && $noNegativeSignals;

        if (!$fires) return self::resolve($db, $aid, 'adaptation_ahead');

        $detail = 'This athlete looks ready for more: compliance averaging ' . round($in['compliance_avg'] * 100)
                . '%, quality sessions running ' . self::signed($in['rpe_avg']) . ' vs prescribed (easier than asked), '
                . 'and fitness (CTL) rising with no fatigue or load-spike signals. A modest progression is likely well tolerated.';
        return self::upsert($db, $aid, $cid, 'adaptation_ahead', 'opportunity',
            'Adaptation ahead of schedule',
            $detail,
            'Accept to send a plan-regeneration request through your normal approval flow, or dismiss. Nothing changes automatically.',
            $conf, $C::ADAPT_HORIZON_DAYS);
    }

    // ── Flag persistence (UPSERT one open flag per type; auto-resolve on clear) ──

    private static function upsert(
        PDO $db, int $aid, int $cid, string $type, string $severity,
        string $title, string $detail, string $action, string $confidence, int $horizon
    ): ?bool {
        $predictedFor = date('Y-m-d', strtotime('+' . (int)$horizon . ' days'));

        // Refresh the existing open flag of this type, if any.
        $sel = $db->prepare("SELECT id FROM coaching_intelligence_flags WHERE athlete_id = ? AND flag_type = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
        $sel->execute([$aid, $type]);
        $id = $sel->fetchColumn();
        if ($id !== false) {
            $db->prepare(
                "UPDATE coaching_intelligence_flags
                 SET severity = ?, title = ?, detail = ?, suggested_action = ?,
                     confidence = ?, prediction_horizon_days = ?, predicted_for_date = ?
                 WHERE id = ?"
            )->execute([$severity, $title, $detail, $action, $confidence, $horizon, $predictedFor, (int)$id]);
            return true;
        }

        // No open flag: respect a recent coach action / auto-resolve (cooldown) before re-surfacing.
        $cool = $db->prepare(
            "SELECT 1 FROM coaching_intelligence_flags
             WHERE athlete_id = ? AND flag_type = ? AND created_at >= (NOW() - INTERVAL " . (int)PredictiveConstants::DROPOUT_TREND_DAYS . " DAY) LIMIT 1"
        );
        $cool->execute([$aid, $type]);
        if ($cool->fetchColumn()) return null;

        $db->prepare(
            "INSERT INTO coaching_intelligence_flags
               (athlete_id, coach_id, created_at, flag_type, severity, title, detail, suggested_action, status,
                confidence, prediction_horizon_days, predicted_for_date)
             VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, 'open', ?, ?, ?)"
        )->execute([$aid, $cid, $type, $severity, $title, $detail, $action, $confidence, $horizon, $predictedFor]);
        return true;
    }

    /** Auto-resolve (dismiss) any open flag of this type. Returns false if one was closed, null otherwise. */
    private static function resolve(PDO $db, int $aid, string $type): ?bool
    {
        $sel = $db->prepare("SELECT id FROM coaching_intelligence_flags WHERE athlete_id = ? AND flag_type = ? AND status = 'open' LIMIT 1");
        $sel->execute([$aid, $type]);
        if ($sel->fetchColumn() === false) return null;
        $db->prepare("UPDATE coaching_intelligence_flags SET status = 'dismissed', dismissed_at = NOW() WHERE athlete_id = ? AND flag_type = ? AND status = 'open'")
           ->execute([$aid, $type]);
        return false;
    }

    private static function flagOpen(PDO $db, int $aid, string $type): bool
    {
        $sel = $db->prepare("SELECT 1 FROM coaching_intelligence_flags WHERE athlete_id = ? AND flag_type = ? AND status = 'open' LIMIT 1");
        $sel->execute([$aid, $type]);
        return (bool)$sel->fetchColumn();
    }

    // ── Named inputs ─────────────────────────────────────────────────────────────

    private static function inputs(PDO $db, int $athleteId, int $weeks): array
    {
        // RPE-vs-target (quality/long) trend: last N sessions.
        $rpe = self::lastValues($db, $athleteId, 'rpe_vs_target', PredictiveConstants::RPE_TREND_SESSIONS);
        $rpeAvg = $rpe ? array_sum($rpe) / count($rpe) : 0.0;

        // Compliance: last 3 completion_rate entries.
        $comp = self::lastValues($db, $athleteId, 'completion_rate', 3);
        $complianceAvg = $comp ? array_sum($comp) / count($comp) : null;

        // Engagement trajectory over the trend window.
        [$engSlopeWeek, $engCurrent, $engN] = self::engagementTrend($db, $athleteId);

        // Rolling volume windows (minutes).
        $volLast7    = self::volumeBetween($db, $athleteId, 6, 0);
        $vol2wkAgo   = self::volumeBetween($db, $athleteId, 20, 14);
        $volChronic  = self::volumeBetween($db, $athleteId, 27, 7) / 3.0; // weekly avg over prior 3 weeks

        // Training load.
        [$tsbNow, $ctlSeries] = self::loadSeries($db, $athleteId);
        $tsbWeekAgo = self::tsbAround($db, $athleteId, 7);
        $ctlSlopeWeek = self::slopePerWeek($ctlSeries);

        return [
            'rpe_avg' => round($rpeAvg, 2), 'rpe_n' => count($rpe),
            'compliance_avg' => $complianceAvg,
            'eng_slope_week' => $engSlopeWeek, 'eng_current' => $engCurrent, 'eng_n' => $engN,
            'vol_last7' => $volLast7, 'vol_2wk_ago' => $vol2wkAgo, 'vol_chronic_weekly' => $volChronic,
            'tsb_now' => $tsbNow, 'tsb_week_ago' => $tsbWeekAgo, 'ctl_slope_week' => $ctlSlopeWeek,
        ];
    }

    private static function lastValues(PDO $db, int $athleteId, string $type, int $n): array
    {
        $stmt = $db->prepare(
            "SELECT metric_value FROM athlete_behavior_log
             WHERE athlete_id = ? AND metric_type = ? ORDER BY logged_at DESC, id DESC LIMIT " . (int)$n
        );
        $stmt->execute([$athleteId, $type]);
        return array_map('floatval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** [slopePerWeek|null, current|null, n] for engagement_score over DROPOUT_TREND_DAYS. */
    private static function engagementTrend(PDO $db, int $athleteId): array
    {
        $stmt = $db->prepare(
            "SELECT logged_at, metric_value FROM athlete_behavior_log
             WHERE athlete_id = ? AND metric_type = 'engagement_score'
               AND logged_at >= (NOW() - INTERVAL " . (int)PredictiveConstants::DROPOUT_TREND_DAYS . " DAY)
             ORDER BY logged_at ASC"
        );
        $stmt->execute([$athleteId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $n = count($rows);
        if ($n === 0) return [null, null, 0];
        $series = [];
        foreach ($rows as $r) { $series[] = [strtotime((string)$r['logged_at']) / 86400.0, (float)$r['metric_value']]; }
        $current = (float)end($rows)['metric_value'];
        $slopeDay = self::leastSquaresSlope($series);
        return [$slopeDay === null ? null : $slopeDay * 7.0, $current, $n];
    }

    /** Sum of completed actual_duration between today-$from and today-$to (inclusive). */
    private static function volumeBetween(PDO $db, int $athleteId, int $from, int $to): float
    {
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(actual_duration),0) FROM completed_workouts
             WHERE athlete_id = ? AND activity_date BETWEEN (CURDATE() - INTERVAL " . (int)$from . " DAY) AND (CURDATE() - INTERVAL " . (int)$to . " DAY)"
        );
        $stmt->execute([$athleteId]);
        return (float)$stmt->fetchColumn();
    }

    /** [latest TSB|null, [[dayX, ctl], ...] over CTL_TREND_DAYS]. */
    private static function loadSeries(PDO $db, int $athleteId): array
    {
        $stmt = $db->prepare(
            "SELECT `date`, tsb, ctl FROM training_load
             WHERE athlete_id = ? AND `date` >= (CURDATE() - INTERVAL " . (int)PredictiveConstants::CTL_TREND_DAYS . " DAY)
             ORDER BY `date` ASC"
        );
        $stmt->execute([$athleteId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return [null, []];
        $tsbNow = (float)end($rows)['tsb'];
        $series = [];
        foreach ($rows as $r) { $series[] = [strtotime((string)$r['date']) / 86400.0, (float)$r['ctl']]; }
        return [$tsbNow, $series];
    }

    /** TSB on the training_load row closest to $daysAgo days ago, or null. */
    private static function tsbAround(PDO $db, int $athleteId, int $daysAgo): ?float
    {
        $stmt = $db->prepare(
            "SELECT tsb FROM training_load WHERE athlete_id = ? AND `date` <= (CURDATE() - INTERVAL " . (int)$daysAgo . " DAY)
             ORDER BY `date` DESC LIMIT 1"
        );
        $stmt->execute([$athleteId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (float)$v;
    }

    private static function slopePerWeek(array $series): ?float
    {
        $s = self::leastSquaresSlope($series);
        return $s === null ? null : $s * 7.0;
    }

    /** Least-squares slope (Δy per unit x) over [[x,y],...]; null when < 2 points or degenerate. */
    private static function leastSquaresSlope(array $points): ?float
    {
        $n = count($points);
        if ($n < 2) return null;
        $x0 = $points[0][0];
        $sx = $sy = $sxy = $sxx = 0.0;
        foreach ($points as [$x, $y]) {
            $x -= $x0;
            $sx += $x; $sy += $y; $sxy += $x * $y; $sxx += $x * $x;
        }
        $denom = $n * $sxx - $sx * $sx;
        if (abs($denom) < 1e-9) return null;
        return ($n * $sxy - $sx * $sy) / $denom;
    }

    private static function signed(float $v): string
    {
        return ($v >= 0 ? '+' : '') . number_format($v, 1);
    }
}

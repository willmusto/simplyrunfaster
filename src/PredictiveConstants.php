<?php
/**
 * PredictiveConstants — Coaching Intelligence Layer, Phase 3.
 *
 * The single tunable location for every Phase 3 threshold, ramp rate, confidence
 * cutoff and prediction horizon (mirroring the §17 intensity-factor philosophy:
 * the numbers live here, the logic in ResponseProfiler / PredictiveFlags reads
 * them, so a coach-facing constant can be retuned without touching a branch).
 *
 * Phase 3 is an ENGINE, not AI: every prediction is a deterministic, interpretable
 * formula over the athlete's own history, and every input below is named.
 */
class PredictiveConstants
{
    // ── Data sufficiency & confidence tiers ──────────────────────────────────
    // Below MIN_WEEKS_DATA weeks of behavior_log, no prediction is emitted and the
    // UI shows "Not enough data yet".
    public const MIN_WEEKS_DATA = 4;

    // weeks-of-data → confidence tier. <4 = none, 4–8 low, 8–16 medium, 16+ high.
    public const TIER_WEEKS_LOW    = 4;
    public const TIER_WEEKS_MEDIUM = 8;
    public const TIER_WEEKS_HIGH   = 16;

    public const CONF_NONE   = 'none';
    public const CONF_LOW    = 'low';
    public const CONF_MEDIUM = 'medium';
    public const CONF_HIGH   = 'high';

    // Look-back windows (days) over which metrics / trends are computed.
    public const PROFILE_WINDOW_DAYS = 182;  // response-profile metric window (~26 wk)
    public const RPE_TREND_SESSIONS  = 3;    // last N quality sessions for an RPE trend
    public const DROPOUT_TREND_DAYS  = 28;   // engagement-slope window
    public const CTL_TREND_DAYS      = 28;   // CTL-slope window (adaptation)
    public const VOLUME_RAMP_WINDOW_DAYS = 28; // weekly-volume window for ramp/spike

    // Per-metric minimum sample sizes (a metric below its floor reports "not enough
    // data" instead of a value).
    public const MIN_SAMPLE_EASY_RPE      = 4;   // qualifying easy/recovery sessions
    public const MIN_SAMPLE_QUALITY_RPE   = 4;   // qualifying quality/long sessions
    public const MIN_SAMPLE_VOLUME_WEEKS  = 4;   // weeks of volume history
    public const MIN_SAMPLE_RECOVERY      = 2;   // recovery episodes observed
    public const MIN_SAMPLE_CUTBACK       = 1;   // cutback weeks observed

    // ── Effort maps (mirror CoachingIntelligence Phase 1; kept here so Phase 3
    //    reads thresholds from one place). effort_descriptor → numeric effort. ──
    public const EFFORT_MAP = [
        'easy' => 3, 'moderate' => 5, 'hard' => 7, 'very_hard' => 9, 'discomfort' => 10,
    ];

    // Planned workout_type → expected effort (real ENUM + spec aliases).
    public const EXPECTED_EFFORT = [
        'easy' => 3, 'easy_run' => 3, 'recovery' => 2, 'long' => 5, 'long_run' => 5,
        'tempo' => 7, 'workout' => 7, 'hill' => 7, 'hill_session' => 7, 'fartlek' => 6,
        'speed' => 8, 'race_pace' => 8, 'interval' => 8, 'plyometric' => 7, 'cross_train' => 3,
    ];

    // Workout-type buckets for the easy-vs-quality RPE split.
    public const EASY_TYPES    = ['easy', 'easy_run', 'recovery', 'cross_train'];
    public const QUALITY_TYPES = ['long', 'long_run', 'tempo', 'workout', 'hill', 'hill_session',
                                  'fartlek', 'speed', 'race_pace', 'interval', 'plyometric'];

    // ── Response-profile metric thresholds ───────────────────────────────────
    // Volume tolerance: a week "counts" only at/above this completion rate, and the
    // tolerated level must hold for VOLUME_SUSTAIN_WEEKS consecutive such weeks.
    public const VOLUME_COMPLIANCE_OK = 0.80;
    public const VOLUME_SUSTAIN_WEEKS = 2;

    // Recovery signature: a fatigue dip starts when TSB falls below FATIGUE and is
    // "recovered" when TSB climbs back to TARGET; the metric is the mean days between.
    public const RECOVERY_TSB_FATIGUE = -15.0;
    public const RECOVERY_TSB_TARGET  = -5.0;

    // Cutback week: planned (else completed) weekly minutes below this ratio of the
    // prior week's.
    public const CUTBACK_RATIO = 0.80;

    // ── predicted_fatigue (1–2 week horizon) ─────────────────────────────────
    // Sustained ramp: this week's volume ≥ RAMP_RATIO × the volume RAMP_WEEKS ago,
    // held across the ramp; RPE delta trending above +RPE_DELTA; TSB below TSB_DROP.
    public const FATIGUE_RAMP_RATIO   = 1.10;
    public const FATIGUE_RAMP_WEEKS   = 2;
    public const FATIGUE_RPE_DELTA    = 1.0;
    public const FATIGUE_TSB_DROP     = -15.0;
    public const FATIGUE_HORIZON_DAYS = 10;

    // ── injury_risk_pattern (load PATTERN, not medical advice) ───────────────
    // Acute:chronic-style spike (this week ÷ trailing average) AND RPE trending high
    // SIMULTANEOUSLY.
    public const INJURY_SPIKE_RATIO   = 1.50;
    public const INJURY_RPE_DELTA     = 1.0;
    public const INJURY_HORIZON_DAYS  = 14;

    // ── predicted_dropout (engagement TRAJECTORY) ────────────────────────────
    // Negative engagement slope (points/week) AND low absolute current score, over
    // at least MIN_POINTS engagement samples.
    public const DROPOUT_SLOPE_PER_WEEK = -3.0;
    public const DROPOUT_ABS_SCORE      = 45.0;
    public const DROPOUT_MIN_POINTS     = 4;
    public const DROPOUT_HORIZON_DAYS   = 21;

    // ── adaptation_ahead (opportunity → coach-approved proposal) ─────────────
    // Compliance consistently high AND quality RPE trending easy (delta ≤ threshold)
    // AND CTL rising (slope ≥ threshold) with no open fatigue/injury signal.
    public const ADAPT_COMPLIANCE       = 0.90;
    public const ADAPT_QUALITY_RPE_DELTA = -1.0;
    public const ADAPT_CTL_SLOPE        = 0.0;   // CTL points/week; > 0 = rising
    public const ADAPT_MIN_WEEKS        = 4;
    public const ADAPT_HORIZON_DAYS     = 14;

    /**
     * weeks-of-data → confidence tier. Returns CONF_NONE below MIN_WEEKS_DATA.
     * A per-metric sample size can only DROP a tier (see capTier), never raise it.
     */
    public static function tierForWeeks(int $weeks): string
    {
        if ($weeks < self::TIER_WEEKS_LOW)    return self::CONF_NONE;
        if ($weeks < self::TIER_WEEKS_MEDIUM) return self::CONF_LOW;
        if ($weeks < self::TIER_WEEKS_HIGH)   return self::CONF_MEDIUM;
        return self::CONF_HIGH;
    }

    /** Numeric rank for a tier (none<low<medium<high), for comparisons/capping. */
    public static function tierRank(string $tier): int
    {
        return ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3][$tier] ?? 0;
    }

    /** The lower of two tiers. */
    public static function capTier(string $a, string $b): string
    {
        return self::tierRank($a) <= self::tierRank($b) ? $a : $b;
    }

    /**
     * Confidence for a single metric: base tier from weeks of data, capped down a
     * step when the sample size is between its floor and 2× its floor.
     */
    public static function metricConfidence(int $weeks, int $sampleSize, int $minSample): string
    {
        if ($sampleSize < $minSample) return self::CONF_NONE;
        $tier = self::tierForWeeks($weeks);
        if ($tier === self::CONF_NONE) return self::CONF_NONE;
        if ($sampleSize < $minSample * 2) {
            // thin sample: drop one tier (but never below low once the floor is met)
            $dropped = max(1, self::tierRank($tier) - 1);
            return ['low', 'medium', 'high'][$dropped - 1];
        }
        return $tier;
    }
}

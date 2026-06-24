<?php
/**
 * RecoveryModel — maps archetype recovery_model slugs to concrete recovery parameters.
 *
 * Two interfaces:
 *   get()       — athlete-facing description + simplified timing for rendering
 *   getParams() — full spec-defined parameters consumed by the archetype resolver
 *
 * Referenced by generation.recovery_model in the archetype JSON.
 */
class RecoveryModel
{
    /**
     * Athlete-facing description and simplified engine timing.
     *
     * @return array{type:string, description:string, ratio:float|null, fixed_seconds:int|null}
     */
    public static function get(string $model): array
    {
        return match($model) {

            'vo2_standard' => [
                'type'          => 'ratio',
                'description'   => 'Easy jog recovery — work-to-rest ratio 1:1 to 2:1',
                'ratio'         => 1.0,
                'fixed_seconds' => null,
            ],

            'threshold_standard' => [
                'type'          => 'ratio',
                'description'   => 'Short jog recovery — 15–30% of interval duration',
                'ratio'         => 0.20,
                'fixed_seconds' => null,
            ],

            'speed_standard' => [
                'type'          => 'fixed',
                'description'   => 'Full recovery (90 sec minimum, more if needed)',
                'ratio'         => null,
                'fixed_seconds' => 90,
            ],

            'hill_standard' => [
                'type'          => 'walk_back',
                'description'   => 'Jog back down the hill (full recovery). Brief standing rest every quarter if needed.',
                'ratio'         => null,
                'fixed_seconds' => null,
            ],

            'fartlek_interval_recovery' => [
                'type'          => 'ratio',
                'description'   => 'Easy jog recovery — work-to-rest ratio between 1:2 and 2:1',
                'ratio'         => 1.0,
                'fixed_seconds' => null,
            ],

            'full_walkback_recovery' => [
                'type'          => 'walk_back',
                'description'   => 'Walk back to start (full recovery, 90 sec minimum)',
                'ratio'         => null,
                'fixed_seconds' => 90,
            ],

            'return_to_easy_between_pickups' => [
                'type'          => 'effort_return',
                'description'   => 'Return to easy running effort after each pickup',
                'ratio'         => null,
                'fixed_seconds' => null,
            ],

            'short_float_recovery' => [
                'type'          => 'fixed',
                'description'   => 'Easy float or short jog recovery',
                'ratio'         => null,
                'fixed_seconds' => 60,
            ],

            // NOTE: full_recovery_between_circuits definitions are a reasonable default
            // for plyometric_hill_circuits. Flagged for coach review — not finalised.
            'full_recovery_between_circuits' => [
                'type'          => 'fixed',
                'description'   => 'Full standing recovery between circuits (60 sec minimum)',
                'ratio'         => null,
                'fixed_seconds' => 60,
            ],

            default => [
                'type'          => 'fixed',
                'description'   => 'Easy recovery',
                'ratio'         => null,
                'fixed_seconds' => 90,
            ],
        };
    }

    /**
     * Full spec-defined parameter set for a recovery model.
     * These are consumed by the archetype resolver when constructing workout structure.
     *
     * NOTE: full_recovery_between_circuits is a reasonable default for
     * plyometric_hill_circuits. It was not in the original recovery model list —
     * definition is pending coach review before treating as final.
     */
    public static function getParams(string $model): array
    {
        return match($model) {

            'vo2_standard' => [
                'work_ratio_min' => 0.50,
                'work_ratio_max' => 1.00,
            ],

            'threshold_standard' => [
                'work_ratio_min' => 0.15,
                'work_ratio_max' => 0.30,
            ],

            'speed_standard' => [
                'full_recovery'           => true,
                'minimum_recovery_seconds'=> 90,
                'allow_extra_recovery'    => true,
            ],

            'hill_standard' => [
                'between_reps'                   => 'jog_back',
                'checkpoint_recovery'            => true,
                'checkpoint_positions'           => [0.25, 0.50, 0.75],
                'checkpoint_duration_seconds_min'=> 45,
                'checkpoint_duration_seconds_max'=> 90,
                'fallback_if_few_reps'           => 'halfway',
            ],

            'fartlek_interval_recovery' => [
                'work_ratio_min' => 0.50,
                'work_ratio_max' => 2.00,
            ],

            'full_walkback_recovery' => [
                'between_reps'            => 'walk_back_full_recovery',
                'minimum_recovery_seconds'=> 90,
                'allow_extra_recovery'    => true,
            ],

            'return_to_easy_between_pickups' => [
                'between_pickups' => 'easy_running',
            ],

            'short_float_recovery' => [
                'default'                   => 'easy',
                'allow_float_for_well_trained' => true,
            ],

            // Reasonable default — pending coach review (see class docblock).
            'full_recovery_between_circuits' => [
                'between_circuits'         => 'full_recovery',
                'minimum_recovery_seconds' => 60,
                'allow_extra_recovery'     => true,
            ],

            default => [],
        };
    }

    /** Athlete-facing description only. */
    public static function describe(string $model): string
    {
        return self::get($model)['description'];
    }

    // ── Shared recovery-seconds resolver ─────────────────────────────────────
    // Single source of truth for the concrete recovery between reps. Both the watch
    // renderer (IntervalsService) and the athlete-facing description (PlanGenerator
    // lead line) call resolveSeconds(), so the recovery they show is guaranteed to
    // agree. The math was previously private to IntervalsService (roundSecs +
    // modelRecoverySeconds + the "explicit override wins" rule); it lives here now.

    /** Round a recovery duration to a tidy value (5s under 1min, 15s under 3min, else 30s). */
    public static function roundSeconds(int $s): int
    {
        if ($s <= 0)   return 0;
        if ($s < 60)   return (int)(round($s / 5) * 5);
        if ($s < 180)  return (int)(round($s / 15) * 15);
        return (int)(round($s / 30) * 30);
    }

    /** Recovery seconds from a recovery-model slug applied to a work duration (unrounded). */
    public static function modelSeconds(string $model, int $workSeconds): int
    {
        $r = self::get($model ?: 'vo2_standard');
        if (($r['type'] ?? '') === 'ratio' && $r['ratio'] !== null && $workSeconds > 0) {
            return (int)round($workSeconds * (float)$r['ratio']);
        }
        if ($r['fixed_seconds'] !== null) {
            return (int)$r['fixed_seconds'];
        }
        return $workSeconds > 0 ? $workSeconds : 90;
    }

    /**
     * Concrete recovery seconds between reps: an explicit coach override
     * (recovery_duration_seconds) wins; otherwise the model is applied to the work
     * duration. Always rounded to a tidy value. Returns 0 only when the override is 0
     * and the model resolves to 0 (which it never does — every model has a ratio,
     * fixed_seconds, or the 90s default), so callers always get a concrete value.
     */
    public static function resolveSeconds(string $model, int $workSeconds, int $explicitOverride = 0): int
    {
        if ($explicitOverride > 0) {
            return self::roundSeconds($explicitOverride);
        }
        return self::roundSeconds(self::modelSeconds($model, $workSeconds));
    }
}

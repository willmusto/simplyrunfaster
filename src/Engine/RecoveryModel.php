<?php
/**
 * RecoveryModel — maps archetype recovery_model slugs to concrete recovery parameters.
 *
 * Returns athlete-facing description and engine-facing timing/ratio data.
 * Used by PlanGenerator when rendering structured workout segments.
 */
class RecoveryModel
{
    /**
     * Returns recovery parameters for a named model.
     *
     * @return array{
     *   type: string,
     *   description: string,
     *   ratio: float|null,
     *   fixed_seconds: int|null
     * }
     */
    public static function get(string $model): array
    {
        return match($model) {

            'fartlek_interval_recovery' => [
                'type'           => 'ratio',
                'description'    => 'Easy jog recovery equal to effort duration',
                'ratio'          => 1.0,
                'fixed_seconds'  => null,
            ],

            'vo2_standard' => [
                'type'           => 'fixed',
                'description'    => '2:30–3:00 easy jog',
                'ratio'          => null,
                'fixed_seconds'  => 165, // midpoint of 150–180
            ],

            'threshold_standard' => [
                'type'           => 'fixed',
                'description'    => '60–90 sec easy jog',
                'ratio'          => null,
                'fixed_seconds'  => 75,
            ],

            'speed_standard' => [
                'type'           => 'fixed',
                'description'    => 'Full recovery (2–3 min walk or jog)',
                'ratio'          => null,
                'fixed_seconds'  => 150,
            ],

            'hill_standard' => [
                'type'           => 'walk_back',
                'description'    => 'Jog back down the hill (full recovery)',
                'ratio'          => null,
                'fixed_seconds'  => null,
            ],

            'full_walkback_recovery' => [
                'type'           => 'walk_back',
                'description'    => 'Walk back to start (full recovery)',
                'ratio'          => null,
                'fixed_seconds'  => null,
            ],

            'short_float_recovery' => [
                'type'           => 'fixed',
                'description'    => '45–90 sec easy float or jog',
                'ratio'          => null,
                'fixed_seconds'  => 67,
            ],

            'return_to_easy_between_pickups' => [
                'type'           => 'effort_return',
                'description'    => 'Return to easy effort after each pickup',
                'ratio'          => null,
                'fixed_seconds'  => null,
            ],

            'full_recovery_between_circuits' => [
                'type'           => 'fixed',
                'description'    => '2–3 min full recovery between circuits',
                'ratio'          => null,
                'fixed_seconds'  => 150,
            ],

            default => [
                'type'           => 'fixed',
                'description'    => 'Easy recovery',
                'ratio'          => null,
                'fixed_seconds'  => 90,
            ],
        };
    }

    /** Athlete-facing description only. */
    public static function describe(string $model): string
    {
        return self::get($model)['description'];
    }
}

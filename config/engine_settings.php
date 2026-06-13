<?php
/**
 * Engine settings — global constants for plan generation.
 *
 * PlanGenerator and its helpers read from this file via PlanGenerator::settings().
 * Do not hardcode these values inline in engine logic.
 */
return [
    // Anti-repeat rules
    'same_instance_hard_block_days'             => 28,
    'same_archetype_soft_penalty_days'          => 10,
    'allow_same_archetype_if_signature_differs' => true,

    // Easy run duration bounds (minutes)
    'easy_run_min_minutes'                      => 30,
    'easy_run_max_minutes'                      => 70,

    // Long run duration bounds by character (minutes)
    'pure_long_run_min_minutes'                 => 60,
    'pure_long_run_max_minutes'                 => 105,
    'progression_long_min_minutes'              => 70,
    'progression_long_max_minutes'              => 120,
    'long_run_absolute_floor_minutes'           => 60,

    // Quality archetype eligibility
    //
    // A quality archetype is not eligible for a generated week when its minimum
    // viable session footprint would exceed this fraction of that week's total
    // training time. The quality slot itself is allocated at roughly 20% of the
    // week and capped at 30-40 minutes; 40% is a hard upper bound for warmup/
    // cooldown-heavy quality sessions so they can fit real-world structure
    // without becoming the dominant stimulus of a low-volume development week.
    'quality_min_duration_week_fraction'         => 0.40,

    // Strides frequency per week
    'strides_sessions_per_week_min'             => 1,
    'strides_sessions_per_week_max'             => 2,
];

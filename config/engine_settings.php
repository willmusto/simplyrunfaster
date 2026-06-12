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

    // Strides frequency per week
    'strides_sessions_per_week_min'             => 1,
    'strides_sessions_per_week_max'             => 2,
];

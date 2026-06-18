<?php
/**
 * Seed the workout_archetypes table with system archetypes.
 *
 * Run once after migration_002_archetype_engine.sql:
 *   php scripts/seed_archetypes.php
 *
 * Safe to re-run: uses INSERT IGNORE so existing rows are skipped.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = Database::get();

// ----------------------------------------------------------------
// Archetype definitions (mirrors workout_archetypes.cleaned.json).
// Each entry is mapped to table columns below.
// ----------------------------------------------------------------

$archetypes = [

    // ----------------------------------------------------------
    // continuous_easy
    // ----------------------------------------------------------
    [
        'code'    => 'continuous_easy',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Continuous Easy Run',
            'workout_type'      => 'easy',
            'mapped_templates'  => ['WL-001', 'WL-018'],
            'description'       => 'Default continuous easy or recovery running with no structured fast work.',
        ],
        'selection' => [
            'slot_types'               => ['easy', 'recovery'],
            'phases'                   => ['base', 'build', 'peak', 'taper'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => ['day_before_race_if_activation_needed'],
        ],
        'weights' => [
            'phase'          => ['base' => 10, 'build' => 10, 'peak' => 9, 'taper' => 10],
            'goal_distance'  => ['5K' => 10, '10K' => 10, 'half' => 10, 'marathon' => 10],
            'classification' => ['workable' => 10, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 10, 'development_plan' => 10, 'maintenance_plan' => 10, 'recovery_block' => 6, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'easy_run_duration',
            'progression_model'  => 'duration_from_weekly_volume',
            'recovery_model'     => null,
            'intensity_factor'   => 0.5,
        ],
        'variants' => [
            ['code' => 'standard_easy',  'name' => 'Standard Easy Run',  'workout_type' => 'easy',     'intensity_factor' => 0.5],
            ['code' => 'recovery_easy',  'name' => 'Recovery Run',        'workout_type' => 'recovery', 'intensity_factor' => 0.3],
        ],
        'parameters' => [
            'duration_minutes' => [
                'type'         => 'integer',
                'workable'     => ['min' => 30, 'max' => 60],
                'well_trained' => ['min' => 35, 'max' => 70],
                'absolute_min' => 30,
                'absolute_max' => 70,
            ],
            'recovery_duration_minutes' => [
                'type'         => 'integer',
                'workable'     => ['min' => 20, 'max' => 30],
                'well_trained' => ['min' => 20, 'max' => 30],
                'absolute_min' => 20,
                'absolute_max' => 30,
            ],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'continuous', 'duration_minutes' => '{{duration_minutes}}', 'effort' => 'easy', 'hr_zone' => [1, 2], 'pace_zone' => null, 'pace_display' => false],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => '{{variant_name}}',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => 'An easy, conversational run. Keep the effort relaxed enough that you could speak in full sentences the whole way. Do not chase pace today.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'duration_minutes']],
        'coach_notes' => [
            'intended_use'  => 'Default filler for non-quality running days, recovery days, and rolling adjustment downgrades.',
            'special_rules' => [
                'Easy runs are always prescribed by duration, never by pace.',
                'Distance range is displayed after duration using the athlete\'s easy pace range.',
                'Recovery variant is capped at 30 minutes.',
                'No RPE collected for standard easy runs.',
                'HR zone 1–2 compliance is monitored passively.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // easy_with_strides
    // ----------------------------------------------------------
    [
        'code'    => 'easy_with_strides',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Easy Run with Strides',
            'workout_type'      => 'easy',
            'mapped_templates'  => ['WL-002', 'WL-017'],
            'description'       => 'Easy running with short relaxed accelerations to maintain coordination, turnover, and running economy.',
        ],
        'selection' => [
            'slot_types'               => ['easy'],
            'phases'                   => ['base', 'build', 'peak', 'taper'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => ['day_after_workout', 'long_run_day'],
        ],
        'weights' => [
            'phase'          => ['base' => 7, 'build' => 9, 'peak' => 10, 'taper' => 10],
            'goal_distance'  => ['5K' => 10, '10K' => 9, 'half' => 8, 'marathon' => 7],
            'classification' => ['workable' => 10, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 10, 'development_plan' => 8, 'maintenance_plan' => 9, 'recovery_block' => 2, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'easy_run_duration',
            'progression_model'  => 'duration_from_weekly_volume',
            'recovery_model'     => null,
            'intensity_factor'   => 0.55,
        ],
        'variants' => [
            ['code' => '4x15s', 'name' => '4 × 15 sec strides'],
            ['code' => '6x15s', 'name' => '6 × 15 sec strides'],
            ['code' => '4x20s', 'name' => '4 × 20 sec strides'],
            ['code' => '6x20s', 'name' => '6 × 20 sec strides'],
        ],
        'parameters' => [
            'duration_minutes'        => ['type' => 'integer', 'workable' => ['min' => 30, 'max' => 60], 'well_trained' => ['min' => 35, 'max' => 70], 'absolute_min' => 30, 'absolute_max' => 70],
            'stride_count'            => ['type' => 'integer', 'workable' => ['min' => 4, 'max' => 6],   'well_trained' => ['min' => 4, 'max' => 8]],
            'stride_duration_seconds' => ['type' => 'integer', 'workable' => ['min' => 15, 'max' => 20], 'well_trained' => ['min' => 15, 'max' => 20]],
            'stride_placement'        => ['type' => 'enum', 'default' => 'end_of_run', 'allowed_values' => ['end_of_run']],
            'stride_window_minutes'   => ['type' => 'integer', 'default' => 10],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'continuous', 'duration_minutes' => '{{main_run_duration_minutes}}', 'effort' => 'easy', 'hr_zone' => [1, 2]],
                ['segment_type' => 'strides',    'repetitions' => '{{stride_count}}', 'duration_seconds' => '{{stride_duration_seconds}}', 'effort' => 'fast_relaxed', 'recovery' => 'full_walk_or_jog'],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => 'Easy Run with Strides',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => 'Run easily throughout, then finish with {{stride_count}} relaxed strides. Accelerate smoothly, stay controlled, and focus on quick, efficient running rather than sprinting.',
        ],
        'instance_signature' => ['fields' => ['code', 'duration_minutes', 'stride_count', 'stride_duration_seconds']],
        'coach_notes' => [
            'intended_use'  => 'Neuromuscular preparation before workouts and races. Maintains economy and turnover without adding meaningful fatigue.',
            'special_rules' => [
                'Target 1–2 stride sessions per week.',
                'Prefer the day before workouts.',
                'Prefer the day before races.',
                'Avoid the day after workouts.',
                'Strides are controlled accelerations, not sprints.',
                'Full recovery between strides.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // continuous_long
    // ----------------------------------------------------------
    [
        'code'    => 'continuous_long',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Continuous Long Run',
            'workout_type'      => 'long',
            'mapped_templates'  => ['WL-003'],
            'description'       => 'Continuous aerobic long run performed entirely at easy effort.',
        ],
        'selection' => [
            'slot_types'               => ['long_run'],
            'phases'                   => ['base', 'build', 'peak', 'taper'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 10, 'build' => 7, 'peak' => 3, 'taper' => 8],
            'goal_distance'  => ['5K' => 5, '10K' => 7, 'half' => 10, 'marathon' => 10],
            'classification' => ['workable' => 10, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 8, 'development_plan' => 10, 'maintenance_plan' => 10, 'recovery_block' => 4, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'long_run_duration',
            'progression_model'  => 'weekly_volume_driven',
            'recovery_model'     => null,
            'intensity_factor'   => 0.8,
        ],
        'variants' => [
            ['code' => 'standard', 'name' => 'Standard Long Run'],
        ],
        'parameters' => [
            'duration_minutes' => ['type' => 'integer', 'workable' => ['min' => 60, 'max' => 90], 'well_trained' => ['min' => 75, 'max' => 105], 'absolute_min' => 60, 'absolute_max' => 105],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'continuous', 'duration_minutes' => '{{duration_minutes}}', 'effort' => 'easy', 'hr_zone' => [1, 2], 'pace_zone' => 'easy'],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => 'Long Run',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => 'A steady aerobic long run. Stay relaxed, keep the effort controlled, and focus on accumulating time on your feet.',
        ],
        'instance_signature' => ['fields' => ['code', 'duration_minutes']],
        'coach_notes' => [
            'intended_use'  => 'Foundational aerobic long run. Primary long-run archetype during base phase and development plans.',
            'special_rules' => [
                'Long-run duration derived from weekly volume target.',
                'Target duration is approximately 28% of weekly running volume.',
                'Absolute minimum duration is 60 minutes.',
                'No embedded quality work.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // progression_long
    // ----------------------------------------------------------
    [
        'code'    => 'progression_long',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Progression Long Run',
            'workout_type'      => 'long',
            'mapped_templates'  => ['WL-004'],
            'description'       => 'Long run that gradually increases in intensity throughout the run.',
        ],
        'selection' => [
            'slot_types'               => ['long_run'],
            'phases'                   => ['base', 'build', 'peak'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 4, 'build' => 8, 'peak' => 8, 'taper' => 0],
            'goal_distance'  => ['5K' => 5, '10K' => 7, 'half' => 10, 'marathon' => 10],
            'classification' => ['workable' => 8, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 10, 'development_plan' => 6, 'maintenance_plan' => 5, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'long_run_duration',
            'progression_model'  => 'long_run_progression',
            'recovery_model'     => null,
            'intensity_factor'   => 0.9,
        ],
        'variants' => [
            ['code' => 'gentle',     'name' => 'Gentle Progression'],
            ['code' => 'standard',   'name' => 'Standard Progression'],
            ['code' => 'aggressive', 'name' => 'Aggressive Progression'],
        ],
        'parameters' => [
            'duration_minutes'          => ['type' => 'integer', 'workable' => ['min' => 70, 'max' => 105], 'well_trained' => ['min' => 80, 'max' => 120], 'absolute_min' => 70, 'absolute_max' => 120],
            'progression_finish_zone'   => ['type' => 'zone_range', 'min' => 'steady', 'max' => 'threshold'],
            'progression_start_percent' => ['type' => 'integer', 'min' => 60, 'max' => 80],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'progression', 'duration_minutes' => '{{duration_minutes}}', 'start_zone' => 'easy', 'finish_zone' => '{{progression_finish_zone}}'],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => 'Progression Long Run',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => 'Start comfortably and gradually increase the effort throughout the run. Finish strong and controlled without forcing the pace.',
        ],
        'instance_signature' => ['fields' => ['code', 'duration_minutes', 'variant', 'progression_finish_zone']],
        'coach_notes' => [
            'intended_use'  => 'Bridge between purely aerobic long runs and long runs with embedded quality.',
            'special_rules' => [
                'Progression is continuous rather than segmented.',
                'Finish zone determined by phase and goal distance.',
                '5K athletes may progress closer to threshold.',
                'Marathon athletes may finish closer to marathon effort.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // goal_pace_long_segments
    // ----------------------------------------------------------
    [
        'code'    => 'goal_pace_long_segments',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Long Run with Goal Pace Segments',
            'workout_type'      => 'long',
            'mapped_templates'  => ['WL-005'],
            'description'       => 'Long run containing structured blocks at goal race pace.',
        ],
        'selection' => [
            'slot_types'               => ['long_run'],
            'phases'                   => ['build', 'peak'],
            'plan_types'               => ['race_cycle'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 0, 'build' => 8, 'peak' => 10, 'taper' => 0],
            'goal_distance'  => ['5K' => 2, '10K' => 4, 'half' => 8, 'marathon' => 10],
            'classification' => ['workable' => 6, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 10, 'development_plan' => 0, 'maintenance_plan' => 0, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'distance_based',
            'duration_source'    => null,
            'progression_model'  => 'goal_pace_segment_expansion',
            'recovery_model'     => null,
            'intensity_factor'   => 1.05,
        ],
        'variants' => [
            ['code' => 'continuous_finish', 'name' => 'Continuous Goal Pace Finish'],
            ['code' => 'broken_segments',   'name' => 'Broken Goal Pace Segments'],
            ['code' => 'hybrid',            'name' => 'Hybrid Goal Pace Long Run'],
        ],
        'parameters' => [
            'total_distance'      => ['type' => 'distance'],
            'goal_pace_distance'  => ['type' => 'distance', 'workable' => ['min_percent' => 15, 'max_percent' => 30], 'well_trained' => ['min_percent' => 20, 'max_percent' => 40]],
            'goal_pace_zone'      => ['type' => 'goal_specific', 'values' => ['5K', '10K', 'half_marathon', 'marathon']],
        ],
        'structure_template' => ['segments' => 'generated_by_variant'],
        'display' => [
            'lead_with'            => 'distance',
            'show_distance_range'  => false,
            'show_time_range'      => true,
            'title_template'       => 'Long Run with Goal Pace Segments',
            'summary_template'     => '{{distance}} miles · {{time_range}}',
            'description_template' => 'A long run with structured segments at goal race pace. Focus on rhythm, efficiency, and learning how race effort feels late in a run.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'total_distance', 'goal_pace_distance', 'goal_pace_zone']],
        'coach_notes' => [
            'intended_use'  => 'Race-specific long run used primarily during build and peak phases.',
            'special_rules' => [
                'Most common for half marathon and marathon athletes.',
                'May be used sparingly for 10K athletes.',
                'Goal pace volume expands throughout the cycle.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // fast_finish_long
    // ----------------------------------------------------------
    [
        'code'    => 'fast_finish_long',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Fast Finish Long Run',
            'workout_type'      => 'long',
            'mapped_templates'  => ['WL-006'],
            'description'       => 'Aerobic long run followed by a substantial faster closing segment.',
        ],
        'selection' => [
            'slot_types'               => ['long_run'],
            'phases'                   => ['build', 'peak'],
            'plan_types'               => ['race_cycle', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 1, 'build' => 8, 'peak' => 9, 'taper' => 0],
            'goal_distance'  => ['5K' => 4, '10K' => 6, 'half' => 9, 'marathon' => 10],
            'classification' => ['workable' => 7, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 10, 'development_plan' => 2, 'maintenance_plan' => 5, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'long_run_duration',
            'progression_model'  => 'finish_segment_expansion',
            'recovery_model'     => null,
            'intensity_factor'   => 1.0,
        ],
        'variants' => [
            ['code' => 'steady_finish',    'name' => 'Steady Finish'],
            ['code' => 'marathon_finish',  'name' => 'Marathon Effort Finish'],
            ['code' => 'threshold_finish', 'name' => 'Threshold Finish'],
        ],
        'parameters' => [
            'duration_minutes'       => ['type' => 'integer', 'workable' => ['min' => 70, 'max' => 110], 'well_trained' => ['min' => 80, 'max' => 120], 'absolute_min' => 70, 'absolute_max' => 120],
            'finish_segment_percent' => ['type' => 'integer', 'workable' => ['min' => 15, 'max' => 25],  'well_trained' => ['min' => 20, 'max' => 35]],
            'finish_zone'            => ['type' => 'zone_range', 'min' => 'steady', 'max' => 'threshold'],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'continuous',  'duration_percent' => 'remaining', 'effort' => 'easy_to_steady'],
                ['segment_type' => 'fast_finish',  'duration_percent' => '{{finish_segment_percent}}', 'effort' => '{{finish_zone}}'],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => 'Fast Finish Long Run',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => 'Run aerobically for most of the session, then close with a strong final segment. Finish controlled and efficient rather than all-out.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'duration_minutes', 'finish_segment_percent', 'finish_zone']],
        'coach_notes' => [
            'intended_use'  => 'Develops fatigue resistance and teaches athletes to run well late in a race.',
            'special_rules' => [
                'Distinguished from progression runs by a clear late-run gear shift.',
                'Finish intensity determined by phase, goal distance, and athlete classification.',
                'Most common for half marathon and marathon athletes.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // long_run_with_pickups
    // ----------------------------------------------------------
    [
        'code'    => 'long_run_with_pickups',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Long Run with Pickups',
            'workout_type'      => 'long',
            'mapped_templates'  => ['WL-016'],
            'description'       => 'Long aerobic run with short time-based pickups inserted throughout the run.',
        ],
        'selection' => [
            'slot_types'               => ['long_run'],
            'phases'                   => ['base', 'build'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 8, 'build' => 7, 'peak' => 2, 'taper' => 0],
            'goal_distance'  => ['5K' => 4, '10K' => 6, 'half' => 10, 'marathon' => 10],
            'classification' => ['workable' => 8, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 8, 'development_plan' => 10, 'maintenance_plan' => 8, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'long_run_duration',
            'progression_model'  => 'pickup_count_and_duration_expansion',
            'recovery_model'     => 'return_to_easy_between_pickups',
            'intensity_factor'   => 0.85,
        ],
        'variants' => [
            ['code' => 'short_pickups',    'name' => 'Short Pickups'],
            ['code' => 'standard_pickups', 'name' => 'Standard Pickups'],
            ['code' => 'long_pickups',     'name' => 'Long Pickups'],
            ['code' => 'mixed_pickups',    'name' => 'Mixed Pickups'],
        ],
        'parameters' => [
            'duration_minutes'     => ['type' => 'integer', 'workable' => ['min' => 60, 'max' => 100],  'well_trained' => ['min' => 75, 'max' => 120], 'absolute_min' => 60, 'absolute_max' => 120],
            'pickup_count'         => ['type' => 'integer', 'workable' => ['min' => 4, 'max' => 8],     'well_trained' => ['min' => 6, 'max' => 12]],
            'pickup_duration_seconds' => ['type' => 'integer', 'workable' => ['min' => 30, 'max' => 90], 'well_trained' => ['min' => 30, 'max' => 180]],
            'pickup_spacing_minutes'  => ['type' => 'integer', 'workable' => ['min' => 6, 'max' => 12],  'well_trained' => ['min' => 5, 'max' => 10]],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'long_run_with_pickups', 'duration_minutes' => '{{duration_minutes}}', 'base_effort' => 'easy', 'pickup_count' => '{{pickup_count}}', 'pickup_duration_seconds' => '{{pickup_duration_seconds}}', 'pickup_spacing_minutes' => '{{pickup_spacing_minutes}}', 'pickup_effort' => '{{mapped_effort}}', 'recovery' => 'return_to_easy_between_pickups'],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => 'Long Run with Pickups',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => 'Run the long run mostly at easy effort. Insert {{pickup_count}} pickups of {{pickup_duration_seconds}} seconds throughout the run, returning to easy effort after each one.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'duration_minutes', 'pickup_count', 'pickup_duration_seconds', 'mapped_effort']],
        'coach_notes' => [
            'intended_use'  => 'Introduces rhythm changes, economy work, and light fatigue resistance inside a long run without making it race-specific.',
            'special_rules' => [
                'Pickups are generally time-based.',
                'Athlete should return to easy effort between pickups.',
                'If the run contains sustained race-pace blocks, use goal_pace_long_segments instead.',
                'If the run has one substantial closing segment, use fast_finish_long instead.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // structured_fartlek_ladder
    // ----------------------------------------------------------
    [
        'code'    => 'structured_fartlek_ladder',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Structured Fartlek Ladder',
            'workout_type'      => 'fartlek',
            'mapped_templates'  => ['WL-007', 'WL-020'],
            'description'       => 'Time-based fartlek session using ascending, descending, or symmetric ladders of faster running and easy recoveries.',
        ],
        'selection' => [
            'slot_types'               => ['quality_primary', 'quality_secondary'],
            'phases'                   => ['base', 'build', 'peak'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 10, 'build' => 8, 'peak' => 5, 'taper' => 1],
            'goal_distance'  => ['5K' => 9, '10K' => 10, 'half' => 8, 'marathon' => 7],
            'classification' => ['workable' => 10, 'well_trained' => 8],
            'plan_type'      => ['race_cycle' => 8, 'development_plan' => 10, 'maintenance_plan' => 10, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'quality_session_duration',
            'progression_model'  => 'ladder_volume_and_rounds',
            'recovery_model'     => 'fartlek_interval_recovery',
            'intensity_factor'   => 0.7,
        ],
        'variants' => [
            ['code' => 'descending',      'name' => 'Descending Fartlek Ladder',  'examples' => ['90-60-30', '3-2-1']],
            ['code' => 'ascending',       'name' => 'Ascending Fartlek Ladder',   'examples' => ['1-2-3-4', '2-3-4-5']],
            ['code' => 'symmetric',       'name' => 'Symmetric Fartlek Ladder',   'examples' => ['1-2-3-2-1']],
            ['code' => 'sharp_descending','name' => 'Sharp Descending Fartlek',   'examples' => ['60-30-15']],
        ],
        'parameters' => [
            'warmup_minutes'         => ['type' => 'integer', 'workable' => ['min' => 15, 'max' => 20], 'well_trained' => ['min' => 15, 'max' => 20]],
            'cooldown_minutes'       => ['type' => 'integer', 'workable' => ['min' => 10, 'max' => 15], 'well_trained' => ['min' => 10, 'max' => 15]],
            'round_count'            => ['type' => 'integer', 'workable' => ['min' => 1, 'max' => 2],   'well_trained' => ['min' => 2, 'max' => 4]],
            'recovery_ratio'         => ['type' => 'float',   'workable' => ['min' => 1.0, 'max' => 2.0], 'well_trained' => ['min' => 0.75, 'max' => 2.0]],
            'hard_effort_zones'      => ['type' => 'ordered_zone_list', 'values' => ['10K', '5K', 'mile']],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',          'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy', 'hr_zone' => [1, 2]],
                ['segment_type' => 'fartlek_ladder',  'variant' => '{{variant}}', 'rounds' => '{{round_count}}', 'recovery_model' => '{{recovery_model}}', 'recovery_ratio' => '{{recovery_ratio}}', 'effort_progression' => '{{hard_effort_zones}}'],
                ['segment_type' => 'cooldown',        'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy', 'hr_zone' => [1, 2]],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => '{{variant_name}}',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => 'A structured fartlek session with faster running broken into a ladder. Run the longer efforts controlled and the shorter efforts a little quicker. Recover easily between each one.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'round_count', 'work_intervals_seconds', 'recovery_ratio']],
        'coach_notes' => [
            'intended_use'  => 'Flexible quality session for base, development, maintenance, and lighter build/peak work.',
            'special_rules' => [
                'Fartlek remains time-based even though it is a quality session.',
                'Can serve as primary or secondary quality.',
                'Recovery generated from interval duration using the fartlek recovery model.',
                'Longer efforts map to 10K effort; shorter efforts progress toward 5K or mile.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // equal_distance_repeats
    // ----------------------------------------------------------
    [
        'code'    => 'equal_distance_repeats',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Equal Distance Repeats',
            'workout_type'      => 'interval',
            'mapped_templates'  => ['WL-008'],
            'description'       => 'Track or measured-distance intervals using repeated reps of identical distance.',
        ],
        'selection' => [
            'slot_types'               => ['quality_primary'],
            'phases'                   => ['base', 'build', 'peak'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'preferred',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 6, 'build' => 10, 'peak' => 8, 'taper' => 1],
            'goal_distance'  => ['5K' => 10, '10K' => 10, 'half' => 7, 'marathon' => 4],
            'classification' => ['workable' => 8, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 10, 'development_plan' => 7, 'maintenance_plan' => 5, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'distance_based',
            'duration_source'    => 'quality_session_duration',
            'progression_model'  => 'volume_expansion',
            'recovery_model'     => 'vo2_standard',
            'intensity_factor'   => 1.0,
        ],
        'variants' => [
            ['code' => '400s',  'name' => '400m Repeats'],
            ['code' => '600s',  'name' => '600m Repeats'],
            ['code' => '800s',  'name' => '800m Repeats'],
            ['code' => '1000s', 'name' => '1000m Repeats'],
            ['code' => '1200s', 'name' => '1200m Repeats'],
            ['code' => '1600s', 'name' => '1600m Repeats'],
        ],
        'parameters' => [
            'warmup_minutes'      => ['type' => 'integer', 'min' => 15, 'max' => 20],
            'cooldown_minutes'    => ['type' => 'integer', 'min' => 10, 'max' => 15],
            'rep_distance_meters' => ['type' => 'integer', 'allowed_values' => [400, 600, 800, 1000, 1200, 1600]],
            'rep_count'           => ['type' => 'integer', 'workable' => ['min' => 4, 'max' => 8],  'well_trained' => ['min' => 5, 'max' => 12]],
            'quality_volume_meters'=> ['type' => 'integer', 'workable' => ['min' => 2400, 'max' => 6000], 'well_trained' => ['min' => 3200, 'max' => 8000]],
            'target_effort'       => ['type' => 'enum', 'allowed_values' => ['10K', '5K', '3K']],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',   'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy'],
                ['segment_type' => 'strides',  'repetitions' => 4, 'duration_seconds' => 15],
                ['segment_type' => 'repeats',  'repetitions' => '{{rep_count}}', 'rep_distance_meters' => '{{rep_distance_meters}}', 'target_effort' => '{{target_effort}}', 'recovery_model' => 'vo2_standard'],
                ['segment_type' => 'cooldown', 'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy'],
            ],
        ],
        'display' => [
            'lead_with'            => 'distance',
            'show_distance_range'  => false,
            'show_time_range'      => true,
            'title_template'       => '{{rep_count}} × {{rep_distance_meters}}m',
            'summary_template'     => '{{total_distance}} miles · {{time_range}}',
            'description_template' => 'Run each repeat at a consistent controlled effort. Focus on even pacing and maintaining quality from the first rep to the last.',
        ],
        'instance_signature' => ['fields' => ['code', 'rep_count', 'rep_distance_meters', 'target_effort']],
        'coach_notes' => [
            'intended_use'  => 'Foundational interval archetype for aerobic power, VO2 development, and race-specific fitness.',
            'special_rules' => [
                'Progress primarily by increasing total interval volume.',
                'Track preferred but road/GPS versions are acceptable.',
                'Warmup includes strides by default.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // mixed_distance_repeats
    // ----------------------------------------------------------
    [
        'code'    => 'mixed_distance_repeats',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Mixed Distance Repeats',
            'workout_type'      => 'interval',
            'mapped_templates'  => ['WL-009', 'WL-010'],
            'description'       => 'Interval sessions combining multiple repeat distances and/or effort zones within the same workout.',
        ],
        'selection' => [
            'slot_types'               => ['quality_primary'],
            'phases'                   => ['build', 'peak'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'preferred',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 2, 'build' => 8, 'peak' => 10, 'taper' => 1],
            'goal_distance'  => ['5K' => 10, '10K' => 10, 'half' => 6, 'marathon' => 3],
            'classification' => ['workable' => 7, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 10, 'development_plan' => 4, 'maintenance_plan' => 4, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'distance_based',
            'duration_source'    => 'quality_session_duration',
            'progression_model'  => 'mixed_interval_volume_expansion',
            'recovery_model'     => 'vo2_standard',
            'intensity_factor'   => 1.05,
        ],
        'variants' => [
            ['code' => 'long_to_short',  'name' => 'Long to Short'],
            ['code' => 'short_to_long',  'name' => 'Short to Long'],
            ['code' => 'combo_set',      'name' => 'Combo Set'],
            ['code' => 'strength_speed', 'name' => 'Strength + Speed'],
            ['code' => 'speed_strength', 'name' => 'Speed + Strength'],
        ],
        'parameters' => [
            'warmup_minutes'        => ['type' => 'integer', 'min' => 15, 'max' => 20],
            'cooldown_minutes'      => ['type' => 'integer', 'min' => 10, 'max' => 15],
            'quality_volume_meters' => ['type' => 'integer', 'workable' => ['min' => 3000, 'max' => 6000], 'well_trained' => ['min' => 4000, 'max' => 9000]],
            'interval_distances'    => ['type' => 'array_integer', 'allowed_values' => [200, 300, 400, 600, 800, 1000, 1200, 1600]],
            'effort_zones'          => ['type' => 'ordered_zone_list', 'allowed_values' => ['10K', '5K', '3K', 'mile']],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',        'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy'],
                ['segment_type' => 'strides',       'repetitions' => 4, 'duration_seconds' => 15],
                ['segment_type' => 'mixed_repeats', 'variant' => '{{variant}}', 'quality_volume_meters' => '{{quality_volume_meters}}', 'interval_distances' => '{{interval_distances}}', 'effort_zones' => '{{effort_zones}}', 'recovery_model' => 'vo2_standard'],
                ['segment_type' => 'cooldown',      'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy'],
            ],
        ],
        'display' => [
            'lead_with'            => 'distance',
            'show_distance_range'  => false,
            'show_time_range'      => true,
            'title_template'       => '{{variant_name}}',
            'summary_template'     => '{{total_distance}} miles · {{time_range}}',
            'description_template' => '{{quality_volume_meters}}m of mixed-distance intervals combining different repeat lengths and speeds. Stay controlled early and maintain quality throughout.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'interval_distances', 'effort_zones']],
        'coach_notes' => [
            'intended_use'  => 'Advanced interval archetype used to create variety, race specificity, and multiple physiological stimuli within one session.',
            'special_rules' => [
                'Can generate classic ladder workouts, strength-plus-speed, or speed-plus-strength sessions.',
                'More common in peak phase.',
                'Track preferred but road equivalents may be generated.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // high_volume_time_intervals
    // ----------------------------------------------------------
    [
        'code'    => 'high_volume_time_intervals',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'High Volume Time Intervals',
            'workout_type'      => 'interval',
            'mapped_templates'  => ['WL-021'],
            'description'       => 'High-volume time-based intervals using repeated on/off segments to accumulate sustained quality work.',
        ],
        'selection' => [
            'slot_types'               => ['quality_primary'],
            'phases'                   => ['build', 'peak'],
            'plan_types'               => ['race_cycle'],
            'goal_distances'           => ['half', 'marathon'],
            'min_classification'       => 'well_trained',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 0, 'build' => 7, 'peak' => 9, 'taper' => 0],
            'goal_distance'  => ['5K' => 0, '10K' => 2, 'half' => 10, 'marathon' => 10],
            'classification' => ['workable' => 0, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 10, 'development_plan' => 0, 'maintenance_plan' => 0, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'quality_session_duration',
            'progression_model'  => 'time_interval_volume_expansion',
            'recovery_model'     => 'short_float_recovery',
            'intensity_factor'   => 1.0,
        ],
        'variants' => [
            ['code' => 'classic_2_on_1_off',  'name' => 'Classic 2 On / 1 Off'],
            ['code' => 'longer_on_short_off',  'name' => 'Longer On / Short Off'],
            ['code' => 'mixed_time_intervals', 'name' => 'Mixed Time Intervals'],
        ],
        'parameters' => [
            'warmup_minutes'            => ['type' => 'integer', 'min' => 15, 'max' => 20],
            'cooldown_minutes'          => ['type' => 'integer', 'min' => 10, 'max' => 15],
            'rep_count'                 => ['type' => 'integer', 'well_trained' => ['min' => 12, 'max' => 20]],
            'work_duration_seconds'     => ['type' => 'integer', 'well_trained' => ['min' => 90,  'max' => 240]],
            'recovery_duration_seconds' => ['type' => 'integer', 'well_trained' => ['min' => 45,  'max' => 90]],
            'total_work_minutes'        => ['type' => 'integer', 'well_trained' => ['min' => 24,  'max' => 40]],
            'default_classic_session'   => ['type' => 'object',  'rep_count' => 20, 'work_duration_seconds' => 120, 'recovery_duration_seconds' => 60],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',         'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy'],
                ['segment_type' => 'time_intervals',  'variant' => '{{variant}}', 'rep_count' => '{{rep_count}}', 'work_duration_seconds' => '{{work_duration_seconds}}', 'recovery_duration_seconds' => '{{recovery_duration_seconds}}', 'work_effort' => '{{mapped_effort}}', 'recovery_effort' => 'easy_or_float'],
                ['segment_type' => 'cooldown',        'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy'],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => '{{rep_count}} × {{work_duration_seconds}} sec On / {{recovery_duration_seconds}} sec Off',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => 'A high-volume time-based workout. Run the on segments at a controlled hard effort and keep the recoveries easy enough to maintain rhythm. The cumulative volume is the point.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'rep_count', 'work_duration_seconds', 'recovery_duration_seconds', 'mapped_effort']],
        'coach_notes' => [
            'intended_use'  => 'High-volume threshold-oriented interval work for well-trained half marathon and marathon athletes.',
            'special_rules' => [
                'Reserved for well-trained athletes.',
                'The classic version is 20 × 2 min on / 1 min off.',
                'Recoveries should usually be easy; well-trained athletes may float them.',
                'Do not prescribe during recovery block or return-to-running plans.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // short_speed_repeats
    // ----------------------------------------------------------
    [
        'code'    => 'short_speed_repeats',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Short Speed Repeats',
            'workout_type'      => 'speed',
            'mapped_templates'  => ['WL-019', 'WL-022', 'WL-023'],
            'description'       => 'Short repetitions focused on economy, turnover, speed development, and speed endurance.',
        ],
        'selection' => [
            'slot_types'               => ['quality_primary', 'quality_secondary'],
            'phases'                   => ['base', 'build', 'peak'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'preferred',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 6, 'build' => 8, 'peak' => 10, 'taper' => 3],
            'goal_distance'  => ['5K' => 10, '10K' => 8, 'half' => 5, 'marathon' => 2],
            'classification' => ['workable' => 7, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 9, 'development_plan' => 8, 'maintenance_plan' => 8, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'distance_based',
            'duration_source'    => 'quality_session_duration',
            'progression_model'  => 'speed_volume_expansion',
            'recovery_model'     => 'speed_standard',
            'intensity_factor'   => 0.95,
            'minimum_session_duration_minutes' => 40,
            'minimum_duration_notes' => 'Estimated from minimum speed volume, generous movement recoveries, 15 min warmup, and 10 min cooldown.',
            'minimum_viable_params' => ['rep_count' => 4],
        ],
        'variants' => [
            ['code' => 'economy_200s',       'name' => 'Economy 200s'],
            ['code' => 'speed_300s',         'name' => 'Speed 300s'],
            ['code' => 'speed_endurance_400s','name' => 'Speed Endurance 400s'],
            ['code' => 'broken_speed_set',   'name' => 'Broken Speed Set'],
            ['code' => 'repetition_session', 'name' => 'Repetition Session'],
        ],
        'parameters' => [
            'warmup_minutes'        => ['type' => 'integer', 'min' => 15, 'max' => 20],
            'cooldown_minutes'      => ['type' => 'integer', 'min' => 10, 'max' => 15],
            'rep_distance_meters'   => ['type' => 'integer', 'allowed_values' => [60, 80, 100, 150, 200, 300, 400], 'default' => 200],
            'rep_count'             => ['type' => 'integer', 'workable' => ['min' => 4, 'max' => 10],  'well_trained' => ['min' => 6, 'max' => 16]],
            'effort_zone'           => ['type' => 'enum',    'allowed_values' => ['repetition', 'mile', '800'], 'default' => 'repetition'],
            'quality_volume_meters' => ['type' => 'integer', 'workable' => ['min' => 800, 'max' => 2400], 'well_trained' => ['min' => 1200, 'max' => 4000]],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',        'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy'],
                ['segment_type' => 'strides',       'repetitions' => 4, 'duration_seconds' => 15],
                ['segment_type' => 'speed_repeats', 'rep_distance_meters' => '{{rep_distance_meters}}', 'rep_count' => '{{rep_count}}', 'effort_zone' => '{{effort_zone}}', 'recovery_model' => 'speed_standard'],
                ['segment_type' => 'cooldown',      'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy'],
            ],
        ],
        'display' => [
            'lead_with'            => 'distance',
            'show_distance_range'  => false,
            'show_time_range'      => true,
            'title_template'       => '{{rep_count}} × {{rep_distance_meters}}m',
            'summary_template'     => '{{total_distance}} miles · {{time_range}}',
            'description_template' => '{{rep_count}} × {{rep_distance_meters}}m at near-sprint effort. Run each rep fast and controlled, powerful but not falling apart. Take full walk-back recovery between reps to preserve speed and mechanics on every one.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'rep_distance_meters', 'rep_count', 'effort_zone']],
        'coach_notes' => [
            'intended_use'  => 'Develop economy, turnover, speed, and speed endurance without excessive metabolic stress.',
            'special_rules' => [
                'Recovery is intentionally generous.',
                'Form quality is prioritized over fatigue.',
                'Most heavily weighted for 5K athletes.',
                'Can be used as a secondary quality session.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // sustained_hill_repeats
    // ----------------------------------------------------------
    [
        'code'    => 'sustained_hill_repeats',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Sustained Hill Repeats',
            'workout_type'      => 'hill',
            'mapped_templates'  => ['WL-011'],
            'description'       => 'Time-based uphill repeats on a sustained runnable hill with jog-back recovery.',
        ],
        'selection' => [
            'slot_types'               => ['quality_primary', 'quality_secondary'],
            'phases'                   => ['base', 'build', 'peak'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => ['hilly_terrain_or_substitute_route'],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 7, 'build' => 10, 'peak' => 6, 'taper' => 0],
            'goal_distance'  => ['5K' => 8, '10K' => 9, 'half' => 8, 'marathon' => 6],
            'classification' => ['workable' => 8, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 9, 'development_plan' => 10, 'maintenance_plan' => 8, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'quality_session_duration',
            'progression_model'  => 'hill_repeat_volume_expansion',
            'recovery_model'     => 'hill_standard',
            'intensity_factor'   => 0.7,
        ],
        'variants' => [
            ['code' => 'short_sustained',    'name' => 'Short Sustained Hills'],
            ['code' => 'standard_sustained', 'name' => 'Standard Sustained Hills'],
            ['code' => 'long_sustained',     'name' => 'Long Sustained Hills'],
            ['code' => 'hill_circuit',       'name' => 'Hill Circuit'],
        ],
        'parameters' => [
            'warmup_minutes'      => ['type' => 'integer', 'min' => 15, 'max' => 20],
            'cooldown_minutes'    => ['type' => 'integer', 'min' => 10, 'max' => 15],
            'rep_duration_seconds'=> ['type' => 'integer', 'workable' => ['min' => 45, 'max' => 120], 'well_trained' => ['min' => 45, 'max' => 240]],
            'rep_count'           => ['type' => 'integer', 'workable' => ['min' => 4, 'max' => 8],    'well_trained' => ['min' => 5, 'max' => 10]],
            'hill_grade_guidance' => ['type' => 'object', 'preferred_percent' => ['min' => 4, 'max' => 8], 'acceptable_percent' => ['min' => 3, 'max' => 10], 'required' => false],
            'checkpoint_recovery' => ['type' => 'object', 'enabled' => true, 'positions' => [0.25, 0.5, 0.75], 'duration_seconds' => ['min' => 45, 'max' => 90], 'optional' => true],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',       'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy', 'hr_zone' => [1, 2]],
                ['segment_type' => 'hill_repeats', 'repetitions' => '{{rep_count}}', 'rep_duration_seconds' => '{{rep_duration_seconds}}', 'effort' => '{{mapped_effort}}', 'hill_grade_guidance' => '{{hill_grade_guidance}}', 'recovery' => ['between_reps' => 'jog_back', 'checkpoint_recovery' => '{{checkpoint_recovery}}']],
                ['segment_type' => 'cooldown',     'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy', 'hr_zone' => [1, 2]],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => '{{rep_count}} × {{rep_duration_seconds}} sec Hill Repeats',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => 'Find a sustained runnable hill. Run each uphill repeat at the assigned effort while keeping your mechanics strong. Jog back down easily after each rep.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'rep_count', 'rep_duration_seconds', 'mapped_effort']],
        'coach_notes' => [
            'intended_use'  => 'Builds strength, aerobic power, and climbing mechanics without requiring track access.',
            'special_rules' => [
                'Prescribed by time, not distance.',
                'Jog-back recovery is standard.',
                'Optional checkpoint standing recovery at 25%, 50%, and 75% of the session.',
                'Suitable as primary or secondary quality depending on phase and athlete load.',
                'Requires hill_access on athlete profile.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // hill_sprints
    // ----------------------------------------------------------
    [
        'code'    => 'hill_sprints',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Hill Sprints',
            'workout_type'      => 'hill',
            'mapped_templates'  => ['WL-012'],
            'description'       => 'Short, steep hill sprints for neuromuscular power, force production, and running economy.',
        ],
        'selection' => [
            'slot_types'               => ['quality_secondary'],
            'phases'                   => ['base', 'build', 'peak'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => ['short_steep_hill_or_safe_substitute'],
            'excludes'                 => ['day_after_workout', 'day_before_race'],
        ],
        'weights' => [
            'phase'          => ['base' => 10, 'build' => 8, 'peak' => 4, 'taper' => 0],
            'goal_distance'  => ['5K' => 10, '10K' => 9, 'half' => 7, 'marathon' => 6],
            'classification' => ['workable' => 8, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 8, 'development_plan' => 10, 'maintenance_plan' => 8, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'quality_session_duration',
            'progression_model'  => 'sprint_count_expansion',
            'recovery_model'     => 'full_walkback_recovery',
            'intensity_factor'   => 0.7,
        ],
        'variants' => [
            ['code' => 'intro',    'name' => 'Intro Hill Sprints'],
            ['code' => 'standard', 'name' => 'Standard Hill Sprints'],
            ['code' => 'extended', 'name' => 'Extended Hill Sprints'],
        ],
        'parameters' => [
            'warmup_minutes'        => ['type' => 'integer', 'min' => 20, 'max' => 25],
            'cooldown_minutes'      => ['type' => 'integer', 'min' => 10, 'max' => 15],
            'sprint_duration_seconds'=> ['type' => 'integer', 'workable' => ['min' => 8, 'max' => 12], 'well_trained' => ['min' => 10, 'max' => 15]],
            'sprint_count'          => ['type' => 'integer', 'workable' => ['min' => 6, 'max' => 10], 'well_trained' => ['min' => 8, 'max' => 12]],
            'hill_grade_guidance'   => ['type' => 'object', 'preferred_percent' => ['min' => 6, 'max' => 12], 'acceptable_percent' => ['min' => 5, 'max' => 15], 'required' => false],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',      'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy', 'hr_zone' => [1, 2]],
                ['segment_type' => 'hill_sprints', 'repetitions' => '{{sprint_count}}', 'duration_seconds' => '{{sprint_duration_seconds}}', 'effort' => 'near_maximal_but_controlled', 'hill_grade_guidance' => '{{hill_grade_guidance}}', 'recovery' => ['between_reps' => 'walk_back_full_recovery', 'minimum_recovery_seconds' => 90, 'allow_extra_recovery' => true]],
                ['segment_type' => 'cooldown',    'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy', 'hr_zone' => [1, 2]],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => '{{sprint_count}} × {{sprint_duration_seconds}} sec Hill Sprints',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => 'Find a short, steep hill. Sprint uphill hard but controlled, then walk back down and recover fully before the next one. These are about power and mechanics, not getting tired.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'sprint_count', 'sprint_duration_seconds']],
        'coach_notes' => [
            'intended_use'  => 'Neuromuscular speed and power development for all race distances.',
            'special_rules' => [
                'Full recovery is required between sprints.',
                'Do not turn this into an aerobic workout.',
                'Stop the session if mechanics deteriorate.',
                'Avoid the day after a workout and avoid the day before races.',
                'Requires hill_access on athlete profile.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // plyometric_hill_circuits
    // ----------------------------------------------------------
    [
        'code'    => 'plyometric_hill_circuits',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Plyometric Hill Circuits',
            'workout_type'      => 'plyometric',
            'mapped_templates'  => ['WL-013'],
            'description'       => 'Short hill circuits combining uphill running with plyometric and coordination drills.',
        ],
        'selection' => [
            'slot_types'               => ['quality_secondary'],
            'phases'                   => ['base', 'build'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => true,
            'requires'                 => ['hill_access', 'plyometric_clearance'],
            'excludes'                 => ['injury_restriction', 'return_to_running'],
        ],
        'weights' => [
            'phase'          => ['base' => 10, 'build' => 7, 'peak' => 1, 'taper' => 0],
            'goal_distance'  => ['5K' => 10, '10K' => 9, 'half' => 7, 'marathon' => 5],
            'classification' => ['workable' => 6, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 5, 'development_plan' => 10, 'maintenance_plan' => 8, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'count_based',
            'duration_source'    => 'quality_session_duration',
            'progression_model'  => 'circuit_expansion',
            'recovery_model'     => 'full_recovery_between_circuits',
            'intensity_factor'   => 0.6,
        ],
        'variants' => [
            ['code' => 'basic',    'name' => 'Basic Plyometric Circuit'],
            ['code' => 'bounding', 'name' => 'Bounding Circuit'],
            ['code' => 'mixed',    'name' => 'Mixed Plyometric Circuit'],
            ['code' => 'advanced', 'name' => 'Advanced Plyometric Circuit'],
        ],
        'parameters' => [
            'warmup_minutes'             => ['type' => 'integer', 'min' => 20, 'max' => 25],
            'cooldown_minutes'           => ['type' => 'integer', 'min' => 10, 'max' => 15],
            'circuit_count'              => ['type' => 'integer', 'workable' => ['min' => 4, 'max' => 6],  'well_trained' => ['min' => 5, 'max' => 10]],
            'hill_sprint_duration_seconds'=> ['type' => 'integer', 'workable' => ['min' => 8, 'max' => 15], 'well_trained' => ['min' => 10, 'max' => 20]],
            'drill_count_per_circuit'    => ['type' => 'integer', 'workable' => ['min' => 1, 'max' => 2],  'well_trained' => ['min' => 2, 'max' => 4]],
            'hill_grade_guidance'        => ['type' => 'object', 'preferred_percent' => ['min' => 4, 'max' => 8], 'acceptable_percent' => ['min' => 3, 'max' => 10]],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',             'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy'],
                ['segment_type' => 'plyometric_circuit',  'variant' => '{{variant}}', 'repetitions' => '{{circuit_count}}', 'hill_sprint_duration_seconds' => '{{hill_sprint_duration_seconds}}', 'drills_per_circuit' => '{{drill_count_per_circuit}}', 'recovery' => ['type' => 'full_recovery_between_circuits']],
                ['segment_type' => 'cooldown',            'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy'],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => 'Plyometric Hill Circuit',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => 'Complete {{circuit_count}} circuits, each with a {{hill_sprint_duration_seconds}}-second uphill sprint followed by plyometric drills. Focus on coordination, stiffness, rhythm, and quality movement. Recover fully between circuits.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'circuit_count', 'hill_sprint_duration_seconds', 'drill_count_per_circuit']],
        'coach_notes' => [
            'intended_use'  => 'Develop elastic strength, coordination, and running economy through low-volume, high-quality plyometric work.',
            'special_rules' => [
                'Coach clearance required.',
                'plyometric_clearance flag must be set on athlete profile.',
                'hill_access flag must be set on athlete profile.',
                'Never prescribe during return-to-running plans.',
                'Quality of movement is more important than volume.',
                'Stop the session if mechanics deteriorate.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // tempo_intervals
    // ----------------------------------------------------------
    [
        'code'    => 'tempo_intervals',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Tempo Intervals',
            'workout_type'      => 'tempo',
            'mapped_templates'  => ['WL-014'],
            'description'       => 'Threshold training performed in multiple sustained segments separated by short recoveries.',
        ],
        'selection' => [
            'slot_types'               => ['quality_primary'],
            'phases'                   => ['base', 'build', 'peak'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 10, 'build' => 10, 'peak' => 8, 'taper' => 1],
            'goal_distance'  => ['5K' => 6, '10K' => 8, 'half' => 10, 'marathon' => 10],
            'classification' => ['workable' => 9, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 10, 'development_plan' => 10, 'maintenance_plan' => 8, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'hybrid',
            'duration_source'    => 'quality_session_duration',
            'progression_model'  => 'threshold_volume_expansion',
            'recovery_model'     => 'threshold_standard',
            'intensity_factor'   => 0.85,
        ],
        'variants' => [
            ['code' => 'time_based',      'name' => 'Time-Based Tempo'],
            ['code' => 'distance_based',  'name' => 'Distance-Based Tempo'],
            ['code' => 'long_reps',       'name' => 'Long Tempo Reps'],
            ['code' => 'cruise_intervals','name' => 'Cruise Intervals'],
        ],
        'parameters' => [
            'warmup_minutes'          => ['type' => 'integer', 'min' => 15, 'max' => 20],
            'cooldown_minutes'        => ['type' => 'integer', 'min' => 10, 'max' => 15],
            'rep_count'               => ['type' => 'integer', 'workable' => ['min' => 2, 'max' => 4], 'well_trained' => ['min' => 2, 'max' => 5]],
            'rep_duration_minutes'    => ['type' => 'integer', 'workable' => ['min' => 6, 'max' => 15], 'well_trained' => ['min' => 8, 'max' => 20]],
            'rep_distance_miles'      => ['type' => 'float',   'workable' => ['min' => 1.0, 'max' => 2.0], 'well_trained' => ['min' => 1.0, 'max' => 3.0]],
            'threshold_volume_minutes'=> ['type' => 'integer', 'workable' => ['min' => 16, 'max' => 40], 'well_trained' => ['min' => 20, 'max' => 60]],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',          'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy'],
                ['segment_type' => 'strides',         'repetitions' => 4, 'duration_seconds' => 15],
                ['segment_type' => 'tempo_intervals', 'variant' => '{{variant}}', 'rep_count' => '{{rep_count}}', 'rep_duration_minutes' => '{{rep_duration_minutes}}', 'rep_distance_miles' => '{{rep_distance_miles}}', 'effort' => '{{mapped_effort}}', 'recovery_model' => 'threshold_standard'],
                ['segment_type' => 'cooldown',        'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy'],
            ],
        ],
        'display' => [
            'lead_with'            => 'distance',
            'show_distance_range'  => false,
            'show_time_range'      => true,
            'title_template'       => '{{generated_workout_title}}',
            'summary_template'     => '{{total_distance}} miles · {{time_range}}',
            'description_template' => 'Run each tempo segment at a comfortably hard effort that you could sustain for roughly an hour of racing. Recover easily between segments.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'rep_count', 'rep_duration_minutes', 'rep_distance_miles']],
        'coach_notes' => [
            'intended_use'  => 'Primary threshold-development workout across all goal distances.',
            'special_rules' => [
                'Time-based prescriptions are preferred.',
                'Primary progression occurs through threshold volume expansion.',
                'One of the highest-frequency quality archetypes in the system.',
                'Warmup includes strides by default.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // continuous_progression_tempo
    // ----------------------------------------------------------
    [
        'code'    => 'continuous_progression_tempo',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'              => 'Continuous Progression Tempo',
            'workout_type'      => 'tempo',
            'mapped_templates'  => ['WL-015'],
            'description'       => 'Continuous aerobic and threshold work performed without recovery breaks.',
        ],
        'selection' => [
            'slot_types'               => ['quality_primary'],
            'phases'                   => ['base', 'build', 'peak'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 10, 'build' => 9, 'peak' => 6, 'taper' => 0],
            'goal_distance'  => ['5K' => 7, '10K' => 8, 'half' => 10, 'marathon' => 10],
            'classification' => ['workable' => 8, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 8, 'development_plan' => 10, 'maintenance_plan' => 10, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'quality_session_duration',
            'progression_model'  => 'continuous_tempo_expansion',
            'recovery_model'     => null,
            'intensity_factor'   => 0.9,
        ],
        'variants' => [
            ['code' => 'linear_progression', 'name' => 'Linear Progression Tempo'],
            ['code' => 'wave_progression',   'name' => 'Wave Progression Tempo'],
        ],
        'parameters' => [
            'warmup_minutes'         => ['type' => 'integer', 'min' => 15, 'max' => 20],
            'cooldown_minutes'       => ['type' => 'integer', 'min' => 10, 'max' => 15],
            'continuous_work_minutes'=> ['type' => 'integer', 'workable' => ['min' => 20, 'max' => 40], 'well_trained' => ['min' => 30, 'max' => 60]],
            'wave_count'             => ['type' => 'integer', 'workable' => ['min' => 1, 'max' => 2],   'well_trained' => ['min' => 2, 'max' => 4]],
            'float_type'             => ['type' => 'enum', 'allowed_values' => ['float', 'easy']],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',                  'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy'],
                ['segment_type' => 'strides',                 'repetitions' => 4, 'duration_seconds' => 15],
                ['segment_type' => 'continuous_progression',  'variant' => '{{variant}}', 'continuous_work_minutes' => '{{continuous_work_minutes}}', 'wave_count' => '{{wave_count}}', 'float_type' => '{{float_type}}', 'effort_mapping' => 'goal_distance_adjusted'],
                ['segment_type' => 'cooldown',                'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy'],
            ],
        ],
        'display' => [
            'lead_with'            => 'distance',
            'show_distance_range'  => false,
            'show_time_range'      => true,
            'title_template'       => '{{continuous_work_minutes}} min Progression Tempo',
            'summary_template'     => '{{total_distance}} miles · {{time_range}}',
            'description_template' => '{{continuous_work_minutes}} minutes of continuous tempo with no recovery breaks. Let the effort build naturally and focus on rhythm, control, and smooth transitions between gears.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'continuous_work_minutes', 'wave_count', 'float_type']],
        'coach_notes' => [
            'intended_use'  => 'Continuous threshold development and aerobic strength without stopping or resetting effort.',
            'special_rules' => [
                'No recovery breaks are allowed.',
                'If the session uses easy recoveries, use structured_fartlek_ladder instead.',
                'Wave variants are encouraged for well-trained athletes.',
                'Float running should be favored over easy running when appropriate.',
                'One of the primary development-plan quality archetypes.',
                'Warmup includes strides by default.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // run_walk_intervals  (return_to_running + insufficient-base; §19 item 6)
    // ----------------------------------------------------------
    [
        'code'    => 'run_walk_intervals',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'             => 'Run/Walk Intervals',
            'workout_type'     => 'easy',
            'mapped_templates' => [],
            'description'      => 'Staged run/walk session for return-to-running and insufficient-base athletes. Ten deterministic stages progress from short run segments with generous walk breaks to a first continuous run.',
        ],
        'selection' => [
            'slot_types'               => ['easy', 'quality_primary', 'quality_secondary'],
            'phases'                   => ['base', 'build', 'peak', 'taper'],
            'plan_types'               => ['development_plan', 'return_to_running'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'insufficient',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => [],
        ],
        'weights' => [
            'phase'          => ['base' => 0, 'build' => 0, 'peak' => 0, 'taper' => 0],
            'goal_distance'  => ['5K' => 0, '10K' => 0, 'half' => 0, 'marathon' => 0],
            'classification' => ['insufficient' => 10],
            'plan_type'      => ['race_cycle' => 0, 'development_plan' => 0, 'maintenance_plan' => 0, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'fixed_stage',
            'progression_model'  => 'stage_progression',
            'recovery_model'     => 'walk_break',
            'intensity_factor'   => 0.4,
            'minimum_session_duration_minutes' => 30,
        ],
        'variants' => [
            ['code' => 'stage_1',  'name' => 'Stage 1',  'workout_type' => 'easy', 'stage' => 1,  'run_minutes' => 1, 'walk_minutes' => 3, 'rep_count' => 10, 'warmup_minutes' => 10, 'cooldown_minutes' => 5],
            ['code' => 'stage_2',  'name' => 'Stage 2',  'workout_type' => 'easy', 'stage' => 2,  'run_minutes' => 2, 'walk_minutes' => 2, 'rep_count' => 10, 'warmup_minutes' => 10, 'cooldown_minutes' => 5],
            ['code' => 'stage_3',  'name' => 'Stage 3',  'workout_type' => 'easy', 'stage' => 3,  'run_minutes' => 3, 'walk_minutes' => 1, 'rep_count' => 10, 'warmup_minutes' => 10, 'cooldown_minutes' => 5],
            ['code' => 'stage_4',  'name' => 'Stage 4',  'workout_type' => 'easy', 'stage' => 4,  'run_minutes' => 4, 'walk_minutes' => 1, 'rep_count' => 8,  'warmup_minutes' => 10, 'cooldown_minutes' => 5],
            ['code' => 'stage_5',  'name' => 'Stage 5',  'workout_type' => 'easy', 'stage' => 5,  'run_minutes' => 5, 'walk_minutes' => 1, 'rep_count' => 7,  'warmup_minutes' => 10, 'cooldown_minutes' => 5],
            ['code' => 'stage_6',  'name' => 'Stage 6',  'workout_type' => 'easy', 'stage' => 6,  'run_minutes' => 6, 'walk_minutes' => 1, 'rep_count' => 6,  'warmup_minutes' => 10, 'cooldown_minutes' => 5],
            ['code' => 'stage_7',  'name' => 'Stage 7',  'workout_type' => 'easy', 'stage' => 7,  'run_minutes' => 7, 'walk_minutes' => 1, 'rep_count' => 6,  'warmup_minutes' => 10, 'cooldown_minutes' => 5],
            ['code' => 'stage_8',  'name' => 'Stage 8',  'workout_type' => 'easy', 'stage' => 8,  'run_minutes' => 8, 'walk_minutes' => 1, 'rep_count' => 6,  'warmup_minutes' => 10, 'cooldown_minutes' => 5],
            ['code' => 'stage_9',  'name' => 'Stage 9',  'workout_type' => 'easy', 'stage' => 9,  'run_minutes' => 9, 'walk_minutes' => 1, 'rep_count' => 6,  'warmup_minutes' => 10, 'cooldown_minutes' => 5],
            ['code' => 'stage_10', 'name' => 'Stage 10', 'workout_type' => 'easy', 'stage' => 10, 'is_continuous' => true, 'continuous_minutes' => 45, 'warmup_minutes' => 0, 'cooldown_minutes' => 0],
        ],
        'parameters' => [
            'stage' => ['type' => 'integer', 'default' => 1],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'run_walk', 'stage' => '{{stage}}', 'run_minutes' => '{{run_minutes}}', 'walk_minutes' => '{{walk_minutes}}', 'rep_count' => '{{rep_count}}', 'warmup_minutes' => '{{warmup_minutes}}', 'cooldown_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy'],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => false,
            'show_time_range'      => false,
            'title_template'       => '{{run_walk_title}}',
            'summary_template'     => '{{duration_minutes}} min · run/walk',
            'description_template' => '{{run_walk_instruction}}',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'stage']],
        'coach_notes' => [
            'intended_use'  => 'Return-to-running progression and insufficient-base entry point. Stage is deterministic, so the archetype is exempt from anti-repeat hard-blocking.',
            'special_rules' => [
                'Effort language only — never prescribe pace for run/walk.',
                'Stages 1-9 are N reps of (run X / walk Y) with brisk-walk warmup and walk cooldown.',
                'Stage 10 is the first continuous run.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // standalone_strides  (insufficient-base + return_to_running; §19 item 6)
    // ----------------------------------------------------------
    [
        'code'    => 'standalone_strides',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'             => 'Standalone Strides',
            'workout_type'     => 'easy',
            'mapped_templates' => [],
            'description'      => 'Short neuromuscular session: brief warmup, a handful of relaxed strides with full recovery, brief cooldown. Distinct from easy_with_strides.',
        ],
        'selection' => [
            'slot_types'               => ['easy'],
            'phases'                   => ['base', 'build', 'peak', 'taper'],
            'plan_types'               => ['development_plan', 'return_to_running'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'insufficient',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => [],
            'excludes'                 => ['day_after_workout'],
        ],
        'weights' => [
            'phase'          => ['base' => 0, 'build' => 0, 'peak' => 0, 'taper' => 0],
            'goal_distance'  => ['5K' => 0, '10K' => 0, 'half' => 0, 'marathon' => 0],
            'classification' => ['insufficient' => 8],
            'plan_type'      => ['race_cycle' => 0, 'development_plan' => 0, 'maintenance_plan' => 0, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'fixed_short',
            'progression_model'  => null,
            'recovery_model'     => null,
            'intensity_factor'   => 0.45,
            'minimum_session_duration_minutes' => 15,
        ],
        'variants' => [
            ['code' => 'standard', 'name' => 'Standalone Strides'],
        ],
        'parameters' => [
            'stride_count'            => ['type' => 'integer', 'workable' => ['min' => 4, 'max' => 6], 'well_trained' => ['min' => 4, 'max' => 6]],
            'stride_duration_seconds' => ['type' => 'integer', 'workable' => ['min' => 20, 'max' => 30], 'well_trained' => ['min' => 20, 'max' => 30]],
            'warmup_minutes'          => ['type' => 'integer', 'default' => 5],
            'cooldown_minutes'        => ['type' => 'integer', 'default' => 5],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',   'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy'],
                ['segment_type' => 'strides',  'repetitions' => '{{stride_count}}', 'duration_seconds' => '{{stride_duration_seconds}}', 'effort' => 'fast_relaxed', 'recovery' => 'full_walk_or_jog'],
                ['segment_type' => 'cooldown', 'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy'],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => false,
            'show_time_range'      => false,
            'title_template'       => 'Standalone Strides',
            'summary_template'     => '{{duration_minutes}} min · {{stride_count}} strides',
            'description_template' => 'A short, easy session focused on form. Warm up with {{warmup_minutes}} minutes of easy jogging or brisk walking, then run {{stride_count}} relaxed strides of about {{stride_duration_seconds}} seconds each, taking full recovery between every one. Cool down with {{cooldown_minutes}} minutes easy. Strides are smooth controlled accelerations, never sprints: focus on quick, relaxed turnover.',
        ],
        'instance_signature' => ['fields' => ['code', 'stride_count', 'stride_duration_seconds']],
        'coach_notes' => [
            'intended_use'  => 'Light neuromuscular stimulus for insufficient-base and return-to-running athletes who do not yet sustain a full easy run. Repeatable; exempt from anti-repeat hard-blocking.',
            'special_rules' => [
                'Effort language only — no pace prescription.',
                'Full recovery between strides.',
                'Distinct from easy_with_strides.',
            ],
        ],
    ],

    // ----------------------------------------------------------
    // hill_sprint_ladder  (FIX 6 — neuromuscular hill-sprint ladder)
    // ----------------------------------------------------------
    [
        'code'    => 'hill_sprint_ladder',
        'version' => 1,
        'status'  => 'active',
        'metadata' => [
            'name'             => 'Hill Sprint Ladder',
            'workout_type'     => 'hill',
            'mapped_templates' => [],
            'description'      => 'A descending or pyramid ladder of near-maximal hill sprints, each rep a different duration, with a jog back to the bottom between every rep for neuromuscular variety in one session.',
        ],
        'selection' => [
            'slot_types'               => ['quality_primary', 'quality_secondary'],
            'phases'                   => ['base', 'build', 'peak'],
            'plan_types'               => ['race_cycle', 'development_plan', 'maintenance_plan'],
            'goal_distances'           => ['5K', '10K', 'half', 'marathon'],
            'min_classification'       => 'workable',
            'track_requirement'        => 'none',
            'coach_clearance_required' => false,
            'requires'                 => ['hill_access'],
            'excludes'                 => ['day_after_workout', 'day_before_race'],
        ],
        'weights' => [
            // marathon carries the highest goal-distance weight because ultras map to the
            // marathon archetype layer; ultras are further boosted at generation time.
            'phase'          => ['base' => 10, 'build' => 8, 'peak' => 3, 'taper' => 0],
            'goal_distance'  => ['5K' => 2, '10K' => 3, 'half' => 5, 'marathon' => 8],
            'classification' => ['workable' => 8, 'well_trained' => 10],
            'plan_type'      => ['race_cycle' => 8, 'development_plan' => 8, 'maintenance_plan' => 6, 'recovery_block' => 0, 'return_to_running' => 0],
        ],
        'generation' => [
            'prescription_model' => 'time_based',
            'duration_source'    => 'quality_session_duration',
            'progression_model'  => 'ladder_variant',
            'recovery_model'     => 'jog_back_recovery',
            'intensity_factor'   => 0.7,
            'minimum_session_duration_minutes' => 35,
            'minimum_duration_notes' => 'Fixed ladder session: 15 min warmup with strides + ladder sprints with jog-back recovery + 10 min cooldown.',
        ],
        'effort_mapping' => [
            'model'             => 'neuromuscular',
            'duration_dependent'=> false,
            'target_stimulus'   => 'maximal_hill_power',
        ],
        'variants' => [
            ['code' => 'descending',        'name' => 'Hill Sprint Descending Ladder'],
            ['code' => 'pyramid',           'name' => 'Hill Sprint Pyramid'],
            ['code' => 'double_descending', 'name' => 'Hill Sprint Double Descending'],
        ],
        'parameters' => [
            'warmup_minutes'   => ['type' => 'integer', 'default' => 15],
            'cooldown_minutes' => ['type' => 'integer', 'default' => 10],
        ],
        'structure_template' => [
            'segments' => [
                ['segment_type' => 'warmup',   'duration_minutes' => '{{warmup_minutes}}', 'effort' => 'easy', 'strides' => '4x15s'],
                ['segment_type' => 'hill_sprint_ladder', 'variant' => '{{variant}}', 'sequence_seconds' => '{{hill_sprint_sequence}}', 'effort' => 'near_maximal', 'recovery' => 'jog_back_to_bottom'],
                ['segment_type' => 'cooldown', 'duration_minutes' => '{{cooldown_minutes}}', 'effort' => 'easy'],
            ],
        ],
        'display' => [
            'lead_with'            => 'duration',
            'show_distance_range'  => true,
            'show_time_range'      => false,
            'title_template'       => '{{variant_name}}',
            'summary_template'     => '{{duration_minutes}} min · {{distance_range}}',
            'description_template' => '{{hill_sprint_sequence}} hill sprints. Jog back to the bottom between each rep. Each sprint is near-maximal effort regardless of duration. The variety in rep length builds neuromuscular adaptability and keeps the session engaging.',
        ],
        'instance_signature' => ['fields' => ['code', 'variant', 'rep_count']],
        'coach_notes' => [
            'intended_use'  => 'Neuromuscular power and running economy with rep-length variety. Heavily weighted for ultra-distance and strength-focused athletes; light for 5K/10K/mile who already get plenty of fast work.',
            'special_rules' => [
                'Jog back to the bottom of the hill between every rep, in every variant. Never walk back, never stand and rest.',
                'Every sprint is near-maximal effort regardless of duration.',
                'Sequence is determined by variant (descending, pyramid, double descending).',
                'Warmup includes 4 x 15 sec strides; cooldown is 10 min easy.',
                'Requires hill access; substitute a safe steep hill if needed.',
            ],
        ],
    ],

]; // end $archetypes


// ----------------------------------------------------------------
// INSERT
// ----------------------------------------------------------------

$sql = <<<'SQL'
INSERT IGNORE INTO `workout_archetypes`
    (`code`, `version`, `status`, `name`, `workout_type`,
     `mapped_templates`, `description`,
     `selection`, `weights`, `generation`,
     `variants`, `parameters`, `structure_template`,
     `display`, `instance_signature`, `coach_notes`,
     `created_by`, `platform_wide`)
VALUES
    (:code, :version, :status, :name, :workout_type,
     :mapped_templates, :description,
     :selection, :weights, :generation,
     :variants, :parameters, :structure_template,
     :display, :instance_signature, :coach_notes,
     NULL, 1)
SQL;

$stmt = $pdo->prepare($sql);
$count = 0;

foreach ($archetypes as $a) {
    $m = $a['metadata'];
    $stmt->execute([
        ':code'               => $a['code'],
        ':version'            => $a['version'],
        ':status'             => $a['status'],
        ':name'               => $m['name'],
        ':workout_type'       => $m['workout_type'],
        ':mapped_templates'   => json_encode($m['mapped_templates']),
        ':description'        => $m['description'],
        ':selection'          => json_encode($a['selection']),
        ':weights'            => json_encode($a['weights']),
        ':generation'         => json_encode($a['generation']),
        ':variants'           => isset($a['variants'])           ? json_encode($a['variants'])           : null,
        ':parameters'         => isset($a['parameters'])         ? json_encode($a['parameters'])         : null,
        ':structure_template' => isset($a['structure_template']) ? json_encode($a['structure_template']) : null,
        ':display'            => isset($a['display'])            ? json_encode($a['display'])            : null,
        ':instance_signature' => isset($a['instance_signature']) ? json_encode($a['instance_signature']) : null,
        ':coach_notes'        => isset($a['coach_notes'])        ? json_encode($a['coach_notes'])        : null,
    ]);
    $count++;
}

echo "Seeded {$count} archetypes into workout_archetypes.\n";

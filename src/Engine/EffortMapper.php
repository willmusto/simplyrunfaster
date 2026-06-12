<?php
/**
 * EffortMapper — translates internal effort zone names to athlete-facing language.
 *
 * Effort names come from archetype structure_template tokens like {{effort}},
 * {{finish_zone}}, {{target_effort}}, etc.
 *
 * Optionally accepts goalDistance to give distance-specific language for
 * race-pace effort zones (e.g. "goal 5K pace" vs "goal marathon pace").
 */
class EffortMapper
{
    /**
     * Returns a short athlete-facing effort label.
     * Used in display text generation for workout instructions.
     */
    public static function label(string $effort, string $goalDistance = ''): string
    {
        return match($effort) {
            'easy'                       => 'easy, conversational effort',
            'easy_to_steady'             => 'easy to steady, building throughout',
            'steady'                     => 'steady, comfortably engaged effort',
            'float'                      => 'float — faster than easy but not hard',
            'threshold'                  => 'comfortably hard (threshold)',
            'marathon'                   => self::raceLabel('marathon', $goalDistance),
            'half_marathon'              => self::raceLabel('half', $goalDistance),
            '10K'                        => self::raceLabel('10K', $goalDistance),
            '5K'                         => self::raceLabel('5K', $goalDistance),
            '3K'                         => '3K race effort',
            'mile'                       => 'mile race effort',
            '800'                        => '800m race effort',
            'repetition'                 => 'controlled near-sprint',
            'near_maximal_but_controlled'=> 'near-maximal, powerful but controlled',
            'fast_relaxed'               => 'fast and relaxed — acceleration, not a sprint',
            'easy_or_float'              => 'easy or floating recovery',
            default                      => $effort,
        };
    }

    /**
     * Returns a sentence-length description suitable for embedded workout instructions.
     */
    public static function sentence(string $effort, string $goalDistance = ''): string
    {
        return match($effort) {
            'easy'  => 'Keep the effort easy and conversational throughout.',
            'steady'=> 'Run at a steady, honest effort you could hold for a long time.',
            'threshold' => 'Run at a comfortably hard effort — approximately your 1-hour race pace.',
            'marathon'  => 'Run at your goal marathon pace.',
            'half_marathon' => 'Run at your goal half marathon pace.',
            '10K'   => 'Run at your goal 10K pace.',
            '5K'    => 'Run at your goal 5K pace.',
            'mile'  => 'Run at mile race effort — fast and controlled.',
            'near_maximal_but_controlled' => 'Sprint hard but stay powerful and controlled — not falling apart.',
            'fast_relaxed' => 'Accelerate smoothly to a fast but relaxed stride.',
            default => "Run at {$effort} effort.",
        };
    }

    /** Intensity factor for training load calculation (mirrors archetype generation.intensity_factor). */
    public static function intensityFactor(string $effort): float
    {
        return match($effort) {
            'easy'                        => 0.5,
            'recovery'                    => 0.3,
            'easy_to_steady', 'steady'    => 0.65,
            'float'                       => 0.7,
            'threshold', 'marathon'       => 0.85,
            'half_marathon', '10K'        => 0.95,
            '5K', '3K', 'mile'            => 1.0,
            '800', 'repetition'           => 1.05,
            'near_maximal_but_controlled' => 1.1,
            default                       => 0.7,
        };
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private static function raceLabel(string $zone, string $goalDistance): string
    {
        // If the goal matches the zone, say "goal race pace"; otherwise be explicit
        $normalized = match(strtolower($goalDistance)) {
            'marathon', 'm', '42k' => 'marathon',
            'half', 'hm', '21k'   => 'half',
            '10k', '10km'          => '10K',
            '5k', '5km'            => '5K',
            default                => '',
        };
        if ($normalized === $zone) {
            return "goal race pace ({$zone})";
        }
        return "goal {$zone} pace";
    }
}

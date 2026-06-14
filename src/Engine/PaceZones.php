<?php
/**
 * PaceZones — derives an athlete's pace-zone profile from a reference
 * performance using distance-equivalency math (McMillan tables, implemented
 * as the Riegel exponent relationship T2 = T1 * (D2/D1)^1.06).
 *
 * Two entry points, one shared core:
 *   - fromRace($distance, $timeSeconds)  → source 'race_result'
 *   - fromEasyPace($minSecPerMile, $maxSecPerMile) → source 'easy_pace_estimate'
 *
 * The easy-pace pathway is McMillan's "project from a training pace" mode:
 * the entered easy pace is converted to an equivalent marathon-pace velocity,
 * which becomes the projection basis. It is the SAME underlying equivalency
 * math as the race pathway, not a separate system — only the basis differs.
 *
 * Output (stored JSON in athlete_profiles.pace_zones) — all paces are
 * seconds per mile:
 *   {
 *     "source": "easy_pace_estimate",
 *     "generated_at": "2026-06-14",
 *     "easy":  {"min": 540, "max": 600},
 *     "long":  {"min": 540, "max": 600},
 *     "marathon": 480, "half_marathon": 458, "10K": 432,
 *     "5K": 414, "mile": 372, "800": 354, "400": 342
 *   }
 *
 * Pace zones are used for QUALITY prescription only. Easy/long runs are
 * always prescribed by time on feet — the easy/long ranges here are
 * informational and, on the easy-pace pathway, simply echo what the
 * athlete entered.
 */
class PaceZones
{
    /** Riegel fatigue exponent — the "McMillan math" relationship. */
    private const RIEGEL_EXP = 1.06;

    /**
     * Easy pace is treated as ~20% slower (in pace) than marathon pace.
     * Used to convert an entered easy pace into a marathon-pace projection
     * basis. Deliberately a single documented constant: this is the
     * estimate pathway (source = 'easy_pace_estimate'), framed to coaches
     * and athletes as "estimated" until a real race result verifies it.
     */
    private const EASY_TO_MARATHON_RATIO = 1.20;

    /** Reference distances in miles. */
    private const MILES = [
        'marathon'      => 26.21875,
        'half_marathon' => 13.109375,
        '15K'           => 9.320568,
        '10K'           => 6.213712,
        '5K'            => 3.106856,
        'mile'          => 1.0,
        '800'           => 0.4970970,
        '400'           => 0.2485485,
    ];

    /** Zones emitted in the output profile (subset of MILES, in display order). */
    private const OUTPUT_DISTANCES = ['marathon','half_marathon','10K','5K','mile','800','400'];

    /**
     * Derive zones from a race/time-trial result.
     * @param string $distance  goal/result distance label (5K, 10K, Half Marathon, Marathon, ...)
     * @param int    $timeSeconds finish time in seconds
     * @return array|null  zone profile, or null if inputs unusable
     */
    public static function fromRace(string $distance, int $timeSeconds): ?array
    {
        $refMiles = self::distanceToMiles($distance);
        if ($refMiles === null || $timeSeconds <= 0) {
            return null;
        }

        // Marathon-pace basis derived from the race, then easy/long ranges
        // bracket marathon pace by +18%..+30% (slower) per common easy-pace guidance.
        $marathonTime = self::project($refMiles, $timeSeconds, self::MILES['marathon']);
        $marathonPace = $marathonTime / self::MILES['marathon'];
        $easy = [
            'min' => (int)round($marathonPace * 1.18),
            'max' => (int)round($marathonPace * 1.30),
        ];

        return self::build($refMiles, $timeSeconds, $easy, 'race_result');
    }

    /**
     * Derive zones from a typical easy-pace range (seconds per mile).
     * @param int $minSecPerMile faster end of easy range
     * @param int $maxSecPerMile slower end of easy range
     */
    public static function fromEasyPace(int $minSecPerMile, int $maxSecPerMile): ?array
    {
        $min = min($minSecPerMile, $maxSecPerMile);
        $max = max($minSecPerMile, $maxSecPerMile);
        if ($min <= 0) {
            return null;
        }

        $easyMid = ($min + $max) / 2.0;

        // Convert easy pace → equivalent marathon pace → equivalent marathon
        // performance, which becomes the projection basis.
        $marathonPace = $easyMid / self::EASY_TO_MARATHON_RATIO;
        $marathonTime = $marathonPace * self::MILES['marathon'];

        // Easy/long ranges echo what the athlete actually told us.
        $easy = ['min' => $min, 'max' => $max];

        return self::build(self::MILES['marathon'], (int)round($marathonTime), $easy, 'easy_pace_estimate');
    }

    /** True when a stored pace_zones JSON value represents a populated profile. */
    public static function isPopulated(?string $json): bool
    {
        if ($json === null || trim($json) === '') {
            return false;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) && !empty($decoded['5K']);
    }

    // ── Core ──────────────────────────────────────────────────────────────

    /**
     * Build the full zone profile from a reference performance.
     * @param float $refMiles reference distance in miles
     * @param int   $refTime  reference time in seconds
     * @param array $easy     {min,max} easy range, seconds per mile
     */
    private static function build(float $refMiles, int $refTime, array $easy, string $source): array
    {
        $zones = [
            'source'       => $source,
            'generated_at' => date('Y-m-d'),
            'easy'         => $easy,
            'long'         => $easy, // long runs are by duration; range mirrors easy
        ];

        foreach (self::OUTPUT_DISTANCES as $key) {
            $time = self::project($refMiles, $refTime, self::MILES[$key]);
            $zones[$key] = (int)round($time / self::MILES[$key]);
        }

        return $zones;
    }

    /** Riegel projection: predicted time at $toMiles given a reference performance. */
    private static function project(float $fromMiles, float $fromTime, float $toMiles): float
    {
        return $fromTime * pow($toMiles / $fromMiles, self::RIEGEL_EXP);
    }

    /** Maps a distance label (and common aliases) to miles. */
    private static function distanceToMiles(string $distance): ?float
    {
        $d = strtolower(trim($distance));
        return match (true) {
            in_array($d, ['marathon','m','42k','full','full marathon'], true)        => self::MILES['marathon'],
            in_array($d, ['half','hm','half marathon','21k'], true)                  => self::MILES['half_marathon'],
            in_array($d, ['15k','15km'], true)                                       => self::MILES['15K'],
            in_array($d, ['10k','10km','10 km'], true)                               => self::MILES['10K'],
            in_array($d, ['5k','5km','5 km'], true)                                  => self::MILES['5K'],
            in_array($d, ['mile','1 mile','1600'], true)                             => self::MILES['mile'],
            in_array($d, ['800','800m'], true)                                       => self::MILES['800'],
            in_array($d, ['400','400m'], true)                                       => self::MILES['400'],
            default                                                                   => null,
        };
    }
}

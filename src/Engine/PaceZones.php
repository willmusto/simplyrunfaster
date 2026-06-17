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

    // ── Quality pace-zone citation (engine spec §19 item 14) ────────────────
    //
    // When an athlete's pace_zones are visible and populated, quality-session
    // instructions cite the relevant zone alongside the existing effort-language
    // description. This is an ADDITIVE clause appended at render time — the effort
    // framing is untouched, and when zones are hidden/empty no clause is produced,
    // so the effort-only output is byte-for-byte unchanged.
    //
    // Cited zones render as a narrow ±5 sec/mile range around a scalar zone, or as
    // the band between two zones for the threshold/mixed efforts that genuinely
    // span a range. Format reuses an "M:SS/mile" pace formatter (see formatRange).

    /** Width, in seconds per mile, of the ±band drawn around a single scalar zone. */
    private const SCALAR_PACE_HALF_BAND = 5;

    /**
     * Build the pace-citation clause for a quality archetype instance, or null
     * when the archetype is effort-only (hill terrain) or the needed zone keys
     * are absent. Callers append the returned sentence to the rendered effort text.
     *
     * @param string      $code    archetype code
     * @param array       $params  resolved_params for the instance
     * @param array|null  $zones   decoded pace_zones (seconds/mile), or null when hidden/empty
     * @param string|null $variant resolved variant code (used by fast_finish_long)
     */
    public static function qualityCitation(string $code, array $params, ?array $zones, ?string $variant = null, ?string $goalDistance = null): ?string
    {
        if (empty($zones)) {
            return null;
        }

        switch ($code) {
            // Threshold/tempo efforts sit in the band between 10K (fast end) and
            // half-marathon (slow end) pace. Cited as that band directly. Milers work
            // at a higher intensity, so cite the faster mile–5K band instead (mile spec Part 11).
            case 'tempo_intervals':
            case 'continuous_progression_tempo':
            case 'high_volume_time_intervals':
                $range = $goalDistance === 'mile'
                    ? self::bandRange($zones, 'mile', '5K')
                    : self::bandRange($zones, '10K', 'half_marathon');
                return $range ? "Target roughly {$range} on the tempo work." : null;

            // Distance repeats: map the prescribed rep distance to the nearest
            // track/short pace key, cited as a narrow range around that pace.
            case 'equal_distance_repeats':
            case 'short_speed_repeats':
                $meters = self::repDistanceMeters($params);
                if ($meters === null) {
                    return null;
                }
                $key   = self::nearestDistanceKey($meters);
                $range = self::scalarRange($zones, $key);
                return $range ? "Aim for around {$range} on the reps." : null;

            // Mixed-distance reps span short/fast to longer reps — cite the band
            // from mile (fast end) to 5K (slow end) pace.
            case 'mixed_distance_repeats':
                $range = self::bandRange($zones, 'mile', '5K');
                return $range ? "Aim for around {$range} across the mixed reps." : null;

            // Fartlek ladder: controlled longer efforts to quicker short efforts —
            // cite the band from 5K (fast end) to 10K (slow end) pace.
            case 'structured_fartlek_ladder':
                $range = self::bandRange($zones, '5K', '10K');
                return $range ? "On the faster efforts, aim for around {$range}." : null;

            // Fast-finish long run: only the closing segment is pace-prescribed,
            // mapped from the finish variant/zone to the corresponding pace key.
            case 'fast_finish_long':
                $key   = self::finishZoneKey($variant, $params['finish_zone'] ?? null);
                $range = self::scalarRange($zones, $key);
                return $range ? "Run the closing segment at around {$range}." : null;

            // Hill archetypes (sustained_hill_repeats, hill_sprints,
            // plyometric_hill_circuits) and everything else remain effort-only.
            // Uphill running is ~40% slower per minute than flat easy pace
            // (see engine spec §18.5), so flat-road pace zones do not transfer to
            // hill terrain — citing them would misprescribe the effort.
            default:
                return null;
        }
    }

    /** Format seconds-per-mile as "M:SS/mile". */
    public static function formatPace(int $secs): string
    {
        return sprintf('%d:%02d/mile', intdiv($secs, 60), $secs % 60);
    }

    /** Format a low/high seconds-per-mile pair as "M:SS–M:SS/mile" (en-dash). */
    public static function formatRange(int $lo, int $hi): string
    {
        return sprintf('%d:%02d–%d:%02d/mile', intdiv($lo, 60), $lo % 60, intdiv($hi, 60), $hi % 60);
    }

    /** A ±SCALAR_PACE_HALF_BAND range around a single scalar zone, or null if absent. */
    private static function scalarRange(?array $zones, string $key): ?string
    {
        if (empty($zones[$key]) || !is_numeric($zones[$key])) {
            return null;
        }
        $v = (int)$zones[$key];
        return self::formatRange($v - self::SCALAR_PACE_HALF_BAND, $v + self::SCALAR_PACE_HALF_BAND);
    }

    /** The band between two scalar zones (smaller=faster first), or null if either is absent. */
    private static function bandRange(?array $zones, string $a, string $b): ?string
    {
        if (empty($zones[$a]) || empty($zones[$b]) || !is_numeric($zones[$a]) || !is_numeric($zones[$b])) {
            return null;
        }
        $lo = min((int)$zones[$a], (int)$zones[$b]);
        $hi = max((int)$zones[$a], (int)$zones[$b]);
        return self::formatRange($lo, $hi);
    }

    /**
     * Return the prescribed single-rep distance for distance-repeat citations.
     * Intentionally does not fall back to quality_volume_meters: that is total
     * work volume, not the rep length used for nearest-distance pace mapping.
     */
    private static function repDistanceMeters(array $params): ?int
    {
        if (!isset($params['rep_distance_meters']) || !is_numeric($params['rep_distance_meters'])) {
            return null;
        }

        $meters = (int)$params['rep_distance_meters'];
        return $meters > 0 ? $meters : null;
    }

    /**
     * Map a rep distance in meters to the nearest track/short pace key.
     * Breakpoints are the geometric midpoints between adjacent reference
     * distances (400, 800, 1609, 3107 m), so each distance snaps to the key it
     * is closest to in pace terms: e.g. 200→400, 600→800, 1200→mile, 3000→5K.
     */
    private static function nearestDistanceKey(int $meters): string
    {
        return match (true) {
            $meters > 0 && $meters < 566  => '400',
            $meters < 1134                => '800',
            $meters < 2236                => 'mile',
            default                       => '5K',
        };
    }

    /**
     * Map a fast_finish_long finish to a single pace key. The finish character is
     * carried by the variant (threshold_finish / marathon_finish / steady_finish);
     * the finish_zone param is a fallback. "threshold" → half-marathon pace (the
     * nearest single zone to threshold); "marathon"/"steady" → marathon pace.
     */
    private static function finishZoneKey(?string $variant, ?string $finishZone): string
    {
        $hint = strtolower((string)($variant ?? '') . ' ' . (string)($finishZone ?? ''));
        return match (true) {
            str_contains($hint, 'threshold') => 'half_marathon',
            default                          => 'marathon',
        };
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

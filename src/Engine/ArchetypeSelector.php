<?php
/**
 * Selects and resolves workout archetypes for plan generation.
 *
 * Column-to-field mapping in workout_archetypes:
 *   selection  — slot eligibility rules (slot_types, phases, plan_types, goal_distances,
 *                min_classification, track_requirement, coach_clearance_required,
 *                requires, excludes)
 *   weights    — scoring maps keyed by phase / goal_distance / classification / plan_type
 *   generation — prescription_model, duration_source, progression_model,
 *                recovery_model, intensity_factor
 *   parameters — per-classification range definitions for every generated parameter
 */
class ArchetypeSelector
{
    private PDO $db;

    /** @var array<string, array> Code-keyed cache populated on first load. */
    private array $cache = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return a single archetype by its stable code slug, or null if not found.
     * Returns both active and inactive archetypes (status check is caller's responsibility).
     */
    public function getByCode(string $code): ?array
    {
        if (!isset($this->cache[$code])) {
            $stmt = $this->db->prepare(
                'SELECT * FROM workout_archetypes WHERE code = :code LIMIT 1'
            );
            $stmt->execute([':code' => $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $this->cache[$code] = $this->decode($row);
        }
        return $this->cache[$code];
    }

    /**
     * Select eligible archetypes for a plan slot and pick the best one.
     *
     * @param string $slotType       One of: easy, quality_primary, quality_secondary, long_run
     * @param string $phase          One of: base, build, peak, taper
     * @param string $goalDistance   One of: 5K, 10K, half, marathon
     * @param string $classification One of: well_trained, workable, insufficient
     * @param string $planType       One of: race_cycle, development_plan, maintenance_plan,
     *                               recovery_block, return_to_running
     * @param array  $constraints    Keys: track_access, hill_access, plyometric_clearance,
     *                               track_field_background, excludes (string[])
     * @param array  $excludeCodes   Archetype codes to hard-exclude (anti-repeat hard block)
     * @param array  $penalizedCodes Archetype codes to soft-penalize (anti-repeat soft penalty, -5 pts)
     */
    public function selectForSlot(
        string $slotType,
        string $phase,
        string $goalDistance,
        string $classification,
        string $planType,
        array  $constraints    = [],
        array  $excludeCodes   = [],
        array  $penalizedCodes = []
    ): ?array {
        $eligible = $this->getEligible($slotType, $phase, $goalDistance, $classification, $planType, $constraints, $excludeCodes);

        if (empty($eligible)) return null;

        $picked = $this->pickWeighted($eligible, $phase, $goalDistance, $classification, $planType, $penalizedCodes);
        if ($picked === null) return null;

        return $this->resolveParameters($picked, $classification);
    }

    /**
     * Return all eligible archetypes for a slot context without picking one.
     * Applies all eligibility rules + hard-exclude list.
     */
    public function getEligible(
        string $slotType,
        string $phase,
        string $goalDistance,
        string $classification,
        string $planType,
        array  $constraints  = [],
        array  $excludeCodes = []
    ): array {
        $all      = $this->loadAll();
        $eligible = [];

        foreach ($all as $archetype) {
            if (!empty($excludeCodes) && in_array($archetype['code'], $excludeCodes, true)) {
                continue;
            }
            if ($archetype['status'] !== 'active') {
                continue;
            }
            if ($this->isEligible($archetype, $slotType, $phase, $goalDistance, $classification, $planType, $constraints)) {
                $eligible[] = $archetype;
            }
        }

        return $eligible;
    }

    /**
     * Weighted random pick from eligible archetypes.
     *
     * Weights are summed across phase, goal_distance, classification, and plan_type
     * dimensions. Zero-weight and penalized-to-zero archetypes are excluded.
     *
     * @param array  $penalizedCodes Codes that receive a -5 point deduction before pick.
     */
    public function pickWeighted(
        array  $eligible,
        string $phase          = '',
        string $goalDistance   = '',
        string $classification = '',
        string $planType       = '',
        array  $penalizedCodes = []
    ): ?array {
        $scored = [];
        $total  = 0;

        foreach ($eligible as $archetype) {
            $w     = $archetype['weights'] ?? [];
            $score = 0;
            $score += (int)($w['phase'][$phase] ?? 0);
            $score += (int)($w['goal_distance'][$goalDistance] ?? 0);
            $score += (int)($w['classification'][$classification] ?? 0);
            $score += (int)($w['plan_type'][$planType] ?? 0);

            // Soft penalty: same archetype used recently
            if (in_array($archetype['code'], $penalizedCodes, true)) {
                $score = max(0, $score - 5);
            }

            if ($score > 0) {
                $scored[] = ['archetype' => $archetype, 'score' => $score];
                $total   += $score;
            }
        }

        if (empty($scored) || $total === 0) return null;

        $rand       = mt_rand(1, $total);
        $cumulative = 0;
        foreach ($scored as $entry) {
            $cumulative += $entry['score'];
            if ($rand <= $cumulative) {
                return $entry['archetype'];
            }
        }
        return $scored[array_key_last($scored)]['archetype'];
    }

    /**
     * Resolve the `parameters` spec into concrete values for a classification.
     *
     * Adds `resolved_params` key with one concrete value per parameter,
     * taken from the midpoint of the classification range.
     */
    public function resolveParameters(array $archetype, string $classification): array
    {
        $params   = $archetype['parameters'] ?? [];
        $resolved = [];

        foreach ($params as $key => $spec) {
            if (!is_array($spec)) {
                $resolved[$key] = $spec;
                continue;
            }

            $type = $spec['type'] ?? 'integer';

            if (isset($spec[$classification]) && is_array($spec[$classification])) {
                $range = $spec[$classification];
                if (isset($range['min'], $range['max'])) {
                    $mid = $type === 'float'
                        ? round(($range['min'] + $range['max']) / 2, 2)
                        : (int)round(($range['min'] + $range['max']) / 2);
                    $resolved[$key] = $type !== 'float' ? self::roundSeconds($key, $mid) : $mid;
                    continue;
                }
            }

            // Fall back to 'well_trained' range (for archetypes that only define well_trained)
            if (isset($spec['well_trained']) && is_array($spec['well_trained'])) {
                $range = $spec['well_trained'];
                if (isset($range['min'], $range['max'])) {
                    $mid = $type === 'float'
                        ? round(($range['min'] + $range['max']) / 2, 2)
                        : (int)round(($range['min'] + $range['max']) / 2);
                    $resolved[$key] = $type !== 'float' ? self::roundSeconds($key, $mid) : $mid;
                    continue;
                }
            }

            if (isset($spec['default'])) {
                $resolved[$key] = $spec['default'];
            } elseif (isset($spec['min'])) {
                $resolved[$key] = $spec['min'];
            } else {
                $resolved[$key] = null;
            }
        }

        $archetype['resolved_params'] = $resolved;
        return $archetype;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Load all archetypes into code-keyed cache. */
    private function loadAll(): array
    {
        if (!empty($this->cache)) {
            return array_values($this->cache);
        }

        $stmt = $this->db->query('SELECT * FROM workout_archetypes');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $decoded = $this->decode($row);
            $this->cache[$decoded['code']] = $decoded;
        }

        return array_values($this->cache);
    }

    /** JSON-decode all LONGTEXT fields in a raw DB row. */
    private function decode(array $row): array
    {
        $jsonFields = [
            'mapped_templates', 'selection', 'weights', 'generation',
            'variants', 'parameters', 'structure_template',
            'display', 'instance_signature', 'coach_notes',
        ];
        foreach ($jsonFields as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $row[$field] = json_decode($row[$field], true) ?? $row[$field];
            }
        }
        return $row;
    }

    /**
     * Test whether an archetype is eligible for the given slot context.
     */
    private function isEligible(
        array  $archetype,
        string $slotType,
        string $phase,
        string $goalDistance,
        string $classification,
        string $planType,
        array  $constraints
    ): bool {
        $s = $archetype['selection'] ?? [];

        if (!in_array($slotType,    $s['slot_types']     ?? [], true)) return false;
        if (!in_array($phase,       $s['phases']         ?? [], true)) return false;
        if (!in_array($planType,    $s['plan_types']     ?? [], true)) return false;
        if (!in_array($goalDistance,$s['goal_distances'] ?? [], true)) return false;

        // Classification floor
        $classRank = ['insufficient' => 0, 'workable' => 1, 'well_trained' => 2];
        $minRank   = $classRank[$s['min_classification'] ?? 'workable'] ?? 1;
        $curRank   = $classRank[$classification] ?? 0;
        if ($curRank < $minRank) return false;

        // Coach clearance — never auto-selected by engine
        if (!empty($s['coach_clearance_required'])) return false;

        // Track requirement
        $trackReq    = $s['track_requirement'] ?? 'none';
        $trackAccess = $constraints['track_access'] ?? 'no';
        if ($trackReq === 'required' && $trackAccess === 'no') return false;

        // requires[] — flags that must be truthy in $constraints
        foreach ($s['requires'] ?? [] as $req) {
            if (empty($constraints[$req])) return false;
        }

        // excludes[] — context tags that disqualify the archetype
        $contextTags = $constraints['excludes'] ?? [];
        foreach ($s['excludes'] ?? [] as $excl) {
            if (in_array($excl, $contextTags, true)) return false;
        }

        return true;
    }

    /**
     * Round _seconds parameter midpoints to coach-friendly increments:
     *   < 30 s  → nearest 5 s
     *   30–90 s → nearest 15 s
     *   > 90 s  → nearest 30 s
     */
    private static function roundSeconds(string $key, int $value): int
    {
        if (substr($key, -8) !== '_seconds') return $value;
        if ($value < 30)  return (int)round($value / 5) * 5;
        if ($value <= 90) return (int)round($value / 15) * 15;
        return (int)round($value / 30) * 30;
    }
}

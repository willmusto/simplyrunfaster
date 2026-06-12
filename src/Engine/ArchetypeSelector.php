<?php

namespace SimplyRunFaster\Engine;

use PDO;

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
        $this->db = $db ?? \Database::get();
    }

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /**
     * Return a single archetype by its stable code slug, or null if not found.
     *
     * @return array|null Archetype row with all LONGTEXT fields decoded to PHP arrays.
     */
    public function getByCode(string $code): ?array
    {
        if (!isset($this->cache[$code])) {
            $stmt = $this->db->prepare(
                'SELECT * FROM workout_archetypes WHERE code = :code AND status = :status LIMIT 1'
            );
            $stmt->execute([':code' => $code, ':status' => 'active']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
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
     * @param array  $constraints    Keys used: track_access (yes|no|road_reps_ok),
     *                               hill_access (bool), plyometric_clearance (bool),
     *                               track_field_background (bool), excludes (string[])
     * @return array|null            Resolved archetype, or null if nothing eligible.
     */
    public function selectForSlot(
        string $slotType,
        string $phase,
        string $goalDistance,
        string $classification,
        string $planType,
        array  $constraints = []
    ): ?array {
        $all      = $this->loadAll();
        $eligible = [];

        foreach ($all as $archetype) {
            if ($this->isEligible($archetype, $slotType, $phase, $goalDistance, $classification, $planType, $constraints)) {
                $eligible[] = $archetype;
            }
        }

        if (empty($eligible)) {
            return null;
        }

        $picked = $this->pickWeighted($eligible, $phase, $goalDistance, $classification, $planType);
        if ($picked === null) {
            return null;
        }

        return $this->resolveParameters($picked, $classification);
    }

    /**
     * Weighted random pick from a set of eligible archetypes.
     *
     * Weights are drawn from each archetype's `weights` map for the given
     * dimensions and summed.  Zero-weight archetypes are excluded.
     *
     * @param array  $eligible       Decoded archetype rows.
     * @param string $phase
     * @param string $goalDistance
     * @param string $classification
     * @param string $planType
     * @return array|null
     */
    public function pickWeighted(
        array  $eligible,
        string $phase          = '',
        string $goalDistance   = '',
        string $classification = '',
        string $planType       = ''
    ): ?array {
        $scored = [];
        $total  = 0;

        foreach ($eligible as $archetype) {
            $w = $archetype['weights'] ?? [];
            $score = 0;
            $score += (int) ($w['phase'][$phase] ?? 0);
            $score += (int) ($w['goal_distance'][$goalDistance] ?? 0);
            $score += (int) ($w['classification'][$classification] ?? 0);
            $score += (int) ($w['plan_type'][$planType] ?? 0);

            if ($score > 0) {
                $scored[]  = ['archetype' => $archetype, 'score' => $score];
                $total    += $score;
            }
        }

        if (empty($scored) || $total === 0) {
            return null;
        }

        $rand = mt_rand(1, $total);
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
     * Resolve the `parameters` spec into concrete values for a given classification.
     *
     * Returns the archetype array with a `resolved_params` key added, containing
     * one concrete value per parameter (picked from the midpoint of the applicable
     * classification range, or the default if no range is defined).
     *
     * @param array  $archetype     Decoded archetype row (from getByCode / selectForSlot).
     * @param string $classification well_trained | workable | insufficient
     * @return array Archetype with `resolved_params` key populated.
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

            // If a classification-keyed range exists, use the midpoint.
            if (isset($spec[$classification]) && is_array($spec[$classification])) {
                $range = $spec[$classification];
                if (isset($range['min'], $range['max'])) {
                    $resolved[$key] = $type === 'float'
                        ? round(($range['min'] + $range['max']) / 2, 2)
                        : (int) round(($range['min'] + $range['max']) / 2);
                    continue;
                }
            }

            // Fall back to 'default', then 'min', then null.
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

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    /** Load all active archetypes, decoding LONGTEXT fields. */
    private function loadAll(): array
    {
        if (!empty($this->cache)) {
            return array_values($this->cache);
        }

        $stmt = $this->db->query('SELECT * FROM workout_archetypes WHERE status = \'active\'');
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
     *
     * Checks selection.slot_types, phases, plan_types, goal_distances,
     * min_classification, track_requirement, coach_clearance_required,
     * and the requires/excludes arrays against $constraints.
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

        // Slot type must be listed.
        if (!in_array($slotType, $s['slot_types'] ?? [], true)) {
            return false;
        }
        // Phase must be listed.
        if (!in_array($phase, $s['phases'] ?? [], true)) {
            return false;
        }
        // Plan type must be listed.
        if (!in_array($planType, $s['plan_types'] ?? [], true)) {
            return false;
        }
        // Goal distance must be listed.
        if (!in_array($goalDistance, $s['goal_distances'] ?? [], true)) {
            return false;
        }

        // Classification floor.
        $classRank = ['insufficient' => 0, 'workable' => 1, 'well_trained' => 2];
        $minRank   = $classRank[$s['min_classification'] ?? 'workable'] ?? 1;
        $curRank   = $classRank[$classification] ?? 0;
        if ($curRank < $minRank) {
            return false;
        }

        // Coach clearance.
        if (!empty($s['coach_clearance_required'])) {
            return false; // engine never auto-selects clearance-required archetypes
        }

        // Track requirement.
        $trackReq    = $s['track_requirement'] ?? 'none';
        $trackAccess = $constraints['track_access'] ?? 'no';
        if ($trackReq === 'required' && $trackAccess === 'no') {
            return false;
        }

        // requires[] — each entry is a flag key that must be truthy in $constraints.
        foreach ($s['requires'] ?? [] as $req) {
            if (empty($constraints[$req])) {
                return false;
            }
        }

        // excludes[] — each entry is a context tag that disqualifies the archetype.
        $contextTags = $constraints['excludes'] ?? [];
        foreach ($s['excludes'] ?? [] as $excl) {
            if (in_array($excl, $contextTags, true)) {
                return false;
            }
        }

        return true;
    }
}

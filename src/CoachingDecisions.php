<?php
/**
 * CoachingDecisions — the decision resolver for the Coaching Intelligence Layer
 * (Phase 1, Part 7).
 *
 * Coaching decisions are rules distilled from flagged plan adjustments (or authored
 * manually). At plan-generation time the engine loads the active decisions for the
 * athlete's coaches, matches each decision's trigger_json against the current context
 * (goal distance, phase, classification, plan type), and applies the matching
 * action_json to the archetype candidate pool.
 *
 * Recognised actions (Part 7 step 3):
 *   - exclude_archetypes  (array)  — remove these codes from the candidate pool
 *   - weight_multipliers  (object) — multiply archetype scores
 *   - max_quality_per_week (int)   — cap the number of quality slots
 *   - force_archetype     (string) — strongly prefer one archetype
 *
 * Conflict resolution: when two matching decisions act on the same archetype with
 * conflicting actions (one excludes it, another weights it), the decision with the
 * higher id (more recently created) wins.
 *
 * This is a pipe, not analytics: it starts from an empty table and rules accumulate
 * only from coach review.
 */
class CoachingDecisions
{
    /**
     * Active decisions authored by any coach assigned to this athlete (head or assistant),
     * decoded. Returns [] when none. Guarded so a pre-migration call is a clean no-op.
     *
     * @return array<int,array{id:int,title:string,trigger:array,action:array}>
     */
    public static function loadActiveForAthlete(int $athleteId, PDO $db): array
    {
        try {
            $stmt = $db->prepare(
                "SELECT id, title, trigger_json, action_json
                 FROM coaching_decisions
                 WHERE status = 'active'
                   AND created_by IN (
                       SELECT coach_id FROM coach_assignments WHERE athlete_id = ?
                       UNION
                       SELECT assistant_coach_id FROM coach_assignments WHERE athlete_id = ? AND assistant_coach_id IS NOT NULL
                   )
                 ORDER BY id ASC"
            );
            $stmt->execute([$athleteId, $athleteId]);
        } catch (\Throwable $e) {
            error_log('CoachingDecisions::loadActiveForAthlete failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'      => (int)$r['id'],
                'title'   => (string)$r['title'],
                'trigger' => json_decode((string)$r['trigger_json'], true) ?: [],
                'action'  => json_decode((string)$r['action_json'], true) ?: [],
            ];
        }
        return $out;
    }

    /**
     * Does a trigger match the current generation context?
     * Each present trigger array (goal_distance / phase / classification / plan_type) must
     * contain the context value; absent keys are wildcards.
     *
     * @param array $ctx ['goal_distance'=>?, 'phase'=>?, 'classification'=>?, 'plan_type'=>?]
     */
    public static function matches(array $trigger, array $ctx): bool
    {
        foreach (['goal_distance', 'phase', 'classification', 'plan_type'] as $key) {
            if (empty($trigger[$key]) || !is_array($trigger[$key])) continue;   // wildcard
            $val = (string)($ctx[$key] ?? '');
            if ($val === '' || !in_array($val, $trigger[$key], true)) return false;
        }
        return true;
    }

    /**
     * Resolve all decisions against a context into a merged action set.
     *
     * @param array<int,array> $decisions Pre-loaded (ascending id) active decisions.
     * @return array{
     *   exclude:array<int,string>, weights:array<string,float>,
     *   max_quality:?int, force:?string, fired:array<int,array{id:int,title:string}>,
     *   conflicts:array<int,string>
     * }
     */
    public static function resolve(array $decisions, array $ctx): array
    {
        // Process ascending by id so a higher (later) id always overrides on conflict.
        usort($decisions, static fn($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));

        $exclude   = [];   // code => true
        $weights   = [];   // code => float multiplier
        $owner     = [];   // code => ['id'=>int,'action'=>'exclude'|'weight','title'=>string]
        $maxQual   = null;
        $force     = null;
        $fired     = [];
        $conflicts = [];

        foreach ($decisions as $d) {
            if (!self::matches($d['trigger'] ?? [], $ctx)) continue;
            $fired[] = ['id' => (int)$d['id'], 'title' => (string)$d['title']];
            $action  = $d['action'] ?? [];
            $title   = (string)$d['title'];
            $id      = (int)$d['id'];

            foreach ((array)($action['exclude_archetypes'] ?? []) as $code) {
                $code = (string)$code;
                if (isset($owner[$code]) && $owner[$code]['action'] === 'weight') {
                    // Higher id (this decision) wins → exclude takes over from a prior weight.
                    unset($weights[$code]);
                    $conflicts[] = self::conflictNote($owner[$code]['title'], $title, $code);
                }
                $exclude[$code] = true;
                $owner[$code]   = ['id' => $id, 'action' => 'exclude', 'title' => $title];
            }

            foreach ((array)($action['weight_multipliers'] ?? []) as $code => $mult) {
                $code = (string)$code;
                if (isset($owner[$code]) && $owner[$code]['action'] === 'exclude') {
                    // Higher id (this decision) wins → weight takes over from a prior exclude.
                    unset($exclude[$code]);
                    $conflicts[] = self::conflictNote($owner[$code]['title'], $title, $code);
                }
                $weights[$code] = isset($weights[$code]) ? $weights[$code] * (float)$mult : (float)$mult;
                $owner[$code]   = ['id' => $id, 'action' => 'weight', 'title' => $title];
            }

            if (isset($action['max_quality_per_week'])) $maxQual = (int)$action['max_quality_per_week'];
            if (!empty($action['force_archetype']))     $force   = (string)$action['force_archetype'];
        }

        return [
            'exclude'     => array_keys($exclude),
            'weights'     => $weights,
            'max_quality' => $maxQual,
            'force'       => $force,
            'fired'       => $fired,
            'conflicts'   => array_values(array_unique($conflicts)),
        ];
    }

    private static function conflictNote(string $titleA, string $titleB, string $archetype): string
    {
        return "Decision conflict: {$titleA} vs {$titleB} on {$archetype}. {$titleB} took precedence.";
    }

    /** Increment times_fired and stamp last_fired_at for each fired decision id. */
    public static function recordFired(array $ids, PDO $db): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return;
        try {
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare(
                "UPDATE coaching_decisions
                 SET times_fired = times_fired + 1, last_fired_at = NOW()
                 WHERE id IN ($in)"
            )->execute($ids);
        } catch (\Throwable $e) {
            error_log('CoachingDecisions::recordFired failed: ' . $e->getMessage());
        }
    }
}

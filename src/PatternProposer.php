<?php
/**
 * PatternProposer — Coaching Intelligence Layer, Phase 2.
 *
 * Turns recurring un-reviewed coach adjustments into *proposed* coaching_decisions
 * the coach can approve, modify, or dismiss in the weekly review. This is the
 * analysis counterpart to CoachAdjustments (capture) and the manual add-as-rule
 * flow (Phase 1): instead of waiting for the coach to flag each adjustment, the
 * proposer notices when the same kind of change has been made enough times for a
 * given (change_type, phase, goal_distance) and drafts a rule from it.
 *
 * Called from the Monday digest cron (so proposals are fresh in the email) and
 * may be run on demand. Like the rest of the layer it must never break the action
 * it observes, so analyze() swallows and logs its own errors.
 */
class PatternProposer
{
    /** Roster-size-aware proposal threshold. */
    private static function threshold(int $athleteCount): int
    {
        if ($athleteCount < 20) return 2;
        if ($athleteCount <= 50) return 3;
        return 5;
    }

    /**
     * Analyze one coach's recent adjustments and create proposed decisions.
     * Returns the number of proposals created.
     */
    public static function analyze(int $coachId, PDO $db): int
    {
        if ($coachId <= 0) return 0;

        try {
            $athleteCount = (int)self::scalar($db,
                'SELECT COUNT(*) FROM athletes WHERE coach_id = ? AND status = "active"', [$coachId]);
            $threshold = self::threshold($athleteCount);

            // Candidate groups: un-reviewed, un-proposed adjustments in the last 90 days.
            $groupStmt = $db->prepare(
                "SELECT change_type, ctx_phase, ctx_goal_distance, COUNT(*) AS n
                 FROM coach_adjustments
                 WHERE coach_id = ?
                   AND coaching_decision_id IS NULL
                   AND proposed_decision_id IS NULL
                   AND adjusted_at > (NOW() - INTERVAL 90 DAY)
                 GROUP BY change_type, ctx_phase, ctx_goal_distance
                 HAVING COUNT(*) >= ?"
            );
            $groupStmt->execute([$coachId, $threshold]);
            $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$groups) return 0;

            $existing = self::existingDecisions($coachId, $db);
            $created  = 0;

            foreach ($groups as $g) {
                $changeType = (string)$g['change_type'];
                $phase      = ($g['ctx_phase'] !== null && $g['ctx_phase'] !== '') ? (string)$g['ctx_phase'] : null;
                $distance   = ($g['ctx_goal_distance'] !== null && $g['ctx_goal_distance'] !== '') ? (string)$g['ctx_goal_distance'] : null;

                // Fetch the adjustments in this exact group.
                $rows = self::groupRows($db, $coachId, $changeType, $phase, $distance);
                if (count($rows) < $threshold) continue;
                $ids = array_map(static fn($r) => (int)$r['id'], $rows);

                // 1) Already covered by an active/proposed decision? Skip, but mark these
                //    adjustments so the proposer stops reconsidering them.
                $coverId = self::findCovering($existing, $changeType, $distance, $phase);
                if ($coverId !== null) {
                    self::markAdjustments($db, $ids, $coverId);
                    continue;
                }

                // 2) Build the proposed decision.
                $title   = self::title($changeType, $rows, $distance, $phase);
                $trigger = self::trigger($rows, $distance, $phase);
                $action  = self::action($changeType, $rows);

                $db->prepare(
                    'INSERT INTO coaching_decisions
                       (created_by, created_at, status, title, reason, trigger_json, action_json,
                        scope_distances, scope_phases, scope_plan_types, source,
                        proposed_from_count, proposed_at)
                     VALUES (?, NOW(), "proposed", ?, "", ?, ?, ?, ?, NULL, "proposed_from_adjustment", ?, NOW())'
                )->execute([
                    $coachId, $title,
                    json_encode($trigger ?: (object)[]),
                    json_encode($action ?: (object)[]),
                    $distance ? json_encode([$distance]) : null,
                    $phase ? json_encode([$phase]) : null,
                    count($rows),
                ]);
                $decisionId = (int)$db->lastInsertId();

                // 3) Tie the contributing adjustments to the proposal.
                self::markAdjustments($db, $ids, $decisionId);

                // 4) Log a roster insight so the proposal surfaces in the feed / digest.
                self::logInsight($db, $coachId, $decisionId, $title, $rows);

                // Keep the in-memory cache current so a later group can't duplicate it.
                $existing[] = [
                    'id' => $decisionId, 'change_type' => $changeType,
                    'distances' => $distance ? [$distance] : [],
                    'phases' => $phase ? [$phase] : [],
                ];
                $created++;
            }

            return $created;
        } catch (\Throwable $e) {
            error_log('PatternProposer::analyze failed for coach ' . $coachId . ': ' . $e->getMessage());
            return 0;
        }
    }

    // ── Group helpers ────────────────────────────────────────────────────────

    /** Adjustments belonging to one (change_type, phase, distance) group. NULL-safe. */
    private static function groupRows(PDO $db, int $coachId, string $changeType, ?string $phase, ?string $distance): array
    {
        $sql = "SELECT * FROM coach_adjustments
                WHERE coach_id = ? AND change_type = ?
                  AND coaching_decision_id IS NULL AND proposed_decision_id IS NULL
                  AND adjusted_at > (NOW() - INTERVAL 90 DAY)
                  AND " . ($phase === null ? "(ctx_phase IS NULL OR ctx_phase = '')" : "ctx_phase = ?") . "
                  AND " . ($distance === null ? "(ctx_goal_distance IS NULL OR ctx_goal_distance = '')" : "ctx_goal_distance = ?");
        $params = [$coachId, $changeType];
        if ($phase !== null)    $params[] = $phase;
        if ($distance !== null) $params[] = $distance;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function markAdjustments(PDO $db, array $ids, int $decisionId): void
    {
        if (!$ids) return;
        $place = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE coach_adjustments SET proposed_decision_id = ? WHERE id IN ($place)")
           ->execute(array_merge([$decisionId], $ids));
    }

    // ── Coverage check ───────────────────────────────────────────────────────

    /** Active + proposed decisions for this coach, with decoded scope arrays. */
    private static function existingDecisions(int $coachId, PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT id, action_json, scope_distances, scope_phases, trigger_json
             FROM coaching_decisions
             WHERE created_by = ? AND status IN ("active","proposed")'
        );
        $stmt->execute([$coachId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $trig = json_decode((string)($d['trigger_json'] ?? ''), true) ?: [];
            $out[] = [
                'id'        => (int)$d['id'],
                'distances' => self::scopeList($d['scope_distances'], $trig['goal_distance'] ?? null),
                'phases'    => self::scopeList($d['scope_phases'], $trig['phase'] ?? null),
            ];
        }
        return $out;
    }

    private static function scopeList($scopeJson, $triggerVal): array
    {
        $a = json_decode((string)($scopeJson ?? ''), true);
        if (is_array($a) && $a) return array_map('strval', $a);
        if (is_array($triggerVal)) return array_map('strval', $triggerVal);
        if (is_string($triggerVal) && $triggerVal !== '') return [$triggerVal];
        return [];
    }

    /**
     * Does an existing decision already cover this (distance, phase) combination?
     * An empty scope list is a wildcard (covers everything). Returns its id or null.
     */
    private static function findCovering(array $existing, string $changeType, ?string $distance, ?string $phase): ?int
    {
        foreach ($existing as $d) {
            $distOk  = empty($d['distances']) || ($distance !== null && in_array($distance, $d['distances'], true));
            $phaseOk = empty($d['phases'])    || ($phase    !== null && in_array($phase,    $d['phases'],    true));
            if ($distOk && $phaseOk) return (int)$d['id'];
        }
        return null;
    }

    // ── Proposed-decision content ──────────────────────────────────────────────

    private static function title(string $changeType, array $rows, ?string $distance, ?string $phase): string
    {
        $dist  = $distance ?? 'all';
        $ph    = $phase ?? 'any';
        $wt    = self::mostCommon($rows, 'before_workout_type') ?: (self::mostCommon($rows, 'after_workout_type') ?: 'workout');
        switch ($changeType) {
            case 'archetype_substitution':
                $before = self::mostCommon($rows, 'before_archetype_code') ?: 'archetype';
                $after  = self::mostCommon($rows, 'after_archetype_code')  ?: 'archetype';
                return "Replace {$before} with {$after} for {$dist} athletes in {$ph} phase";
            case 'duration_change':
                return "Adjust {$wt} duration for {$dist} athletes in {$ph} phase";
            case 'day_swap':
                return "Reschedule {$wt} for {$dist} athletes in {$ph} phase";
            case 'instructions_edited':
                return "Modify {$wt} instructions for {$dist} athletes in {$ph} phase";
            default:
                return "Coaching rule for {$dist} athletes in {$ph} phase";
        }
    }

    /** trigger_json from the group keys, plus classification when consistent across the group. */
    private static function trigger(array $rows, ?string $distance, ?string $phase): array
    {
        $trigger = [];
        if ($distance !== null) $trigger['goal_distance'] = [$distance];
        if ($phase !== null)    $trigger['phase'] = [$phase];

        $classes = [];
        foreach ($rows as $r) {
            $c = ($r['ctx_classification'] !== null && $r['ctx_classification'] !== '') ? (string)$r['ctx_classification'] : null;
            if ($c !== null) $classes[$c] = true;
        }
        if (count($classes) === 1) {
            $trigger['classification'] = [array_key_first($classes)];
        }
        return $trigger;
    }

    /** action_json derived from the most common after-state in the group. */
    private static function action(string $changeType, array $rows): array
    {
        switch ($changeType) {
            case 'archetype_substitution':
                $action = [];
                $before = self::mostCommon($rows, 'before_archetype_code');
                $after  = self::mostCommon($rows, 'after_archetype_code');
                if ($before) $action['exclude_archetypes'] = [$before];
                if ($after)  $action['weight_multipliers'] = [$after => 2];
                return $action;
            case 'duration_change':
                $deltas = [];
                foreach ($rows as $r) {
                    if ($r['after_duration_mins'] === null || $r['before_duration_mins'] === null) continue;
                    $deltas[] = (int)$r['after_duration_mins'] - (int)$r['before_duration_mins'];
                }
                if (!$deltas) return [];
                return ['duration_adjustment' => self::median($deltas)];
            default:
                return []; // empty — flagged for coach review during the weekly flow
        }
    }

    private static function logInsight(PDO $db, int $coachId, int $decisionId, string $title, array $rows): void
    {
        $athleteIds = array_values(array_unique(array_map(static fn($r) => (int)$r['athlete_id'], $rows)));
        $detail = 'The system drafted a coaching rule from ' . count($rows)
            . ' similar adjustments: "' . $title . '". Review it to approve, modify, or dismiss.';
        $db->prepare(
            "INSERT INTO coach_roster_insights
               (coach_id, created_at, insight_type, title, detail, athlete_ids, severity, status)
             VALUES (?, NOW(), 'adjustment_pattern', ?, ?, ?, 'opportunity', 'open')"
        )->execute([$coachId, $title, $detail, json_encode($athleteIds)]);
    }

    // ── Small math/util helpers ─────────────────────────────────────────────────

    /** Most common non-empty value of $col across rows, or null. */
    private static function mostCommon(array $rows, string $col): ?string
    {
        $counts = [];
        foreach ($rows as $r) {
            $v = $r[$col] ?? null;
            if ($v === null || $v === '') continue;
            $v = (string)$v;
            $counts[$v] = ($counts[$v] ?? 0) + 1;
        }
        if (!$counts) return null;
        arsort($counts);
        return (string)array_key_first($counts);
    }

    private static function median(array $values): int
    {
        sort($values);
        $n = count($values);
        if ($n === 0) return 0;
        $mid = intdiv($n, 2);
        if ($n % 2) return (int)round($values[$mid]);
        return (int)round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    private static function scalar(PDO $db, string $sql, array $params)
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}

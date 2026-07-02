<?php
/**
 * ScheduleAdjacency - soft scheduling warnings for workout moves (architecture section 12).
 *
 * Evaluates what a proposed move (or two-leg swap) would create, WITHOUT persisting
 * anything, and returns warn-not-block advisories:
 *
 *   - long_run_proximity:  a quality session and a long run within 2 days of each other
 *   - consecutive_quality: two quality sessions on consecutive days
 *
 * Only pairs that INVOLVE a moved workout warn. The engine's own week layout is never
 * re-litigated: if the plan already has a quality session two days before the long run
 * and the athlete moves an unrelated easy run, no warning fires.
 *
 * Shared by the athlete day-swap (AthleteController::swapWorkout) and the coach
 * drag-reschedule (CoachController::rescheduleWorkout). Both use the same UX contract
 * as the existing must-off guard: return the warning once, client confirms, request is
 * re-sent with force=true.
 */
class ScheduleAdjacency
{
    /** Workout types that count as a quality (hard) session. */
    public const QUALITY_TYPES = ['interval', 'tempo', 'hill', 'fartlek', 'speed', 'race_pace'];

    /** A quality session within this many days of a long run warns. */
    private const LONG_RUN_PROXIMITY_DAYS = 2;

    /**
     * Soft warnings for moving one or two workouts.
     *
     * @param array $moves workout_id => new 'Y-m-d' date (one entry for a move,
     *                     two for a swap: both legs evaluated at their final dates)
     * @return array list of ['type' => string, 'message' => string], deduped by type,
     *               empty when the move is clean
     */
    public static function warningsForMove(PDO $db, int $planId, array $moves): array
    {
        if (empty($moves)) return [];

        $stmt = $db->prepare(
            'SELECT id, scheduled_date, workout_type FROM planned_workouts
             WHERE plan_id = ? AND (cancelled = 0 OR cancelled IS NULL)'
        );
        $stmt->execute([$planId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Apply the proposed move(s) in memory.
        $byId = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $byId[$id] = [
                'date' => isset($moves[$id]) ? (string)$moves[$id] : (string)$r['scheduled_date'],
                'type' => (string)$r['workout_type'],
            ];
        }

        $movedIds = array_keys($moves);
        $warnings = [];

        foreach ($movedIds as $movedId) {
            if (!isset($byId[$movedId])) continue;
            $moved   = $byId[$movedId];
            $movedTs = strtotime($moved['date']);

            foreach ($byId as $otherId => $other) {
                if ($otherId === $movedId) continue;
                $diff = (int)abs(round(($movedTs - strtotime($other['date'])) / 86400));
                if ($diff === 0 || $diff > self::LONG_RUN_PROXIMITY_DAYS) continue;

                $movedQuality = in_array($moved['type'], self::QUALITY_TYPES, true);
                $otherQuality = in_array($other['type'], self::QUALITY_TYPES, true);

                if ($diff === 1 && $movedQuality && $otherQuality) {
                    $warnings['consecutive_quality'] =
                        'This would put two hard workouts on back-to-back days.';
                }
                if (($movedQuality && $other['type'] === 'long') || ($moved['type'] === 'long' && $otherQuality)) {
                    $warnings['long_run_proximity'] =
                        'This would put a hard workout within 2 days of a long run.';
                }
            }
        }

        $out = [];
        foreach ($warnings as $type => $message) {
            $out[] = ['type' => $type, 'message' => $message];
        }
        return $out;
    }
}

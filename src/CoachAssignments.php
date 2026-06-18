<?php
/**
 * CoachAssignments — the authority for athlete → head-coach (+ optional assistant
 * coach) relationships and the permission model built on top of them.
 *
 * coach_assignments is the source of truth, but athletes.coach_id is kept in sync
 * with coach_assignments.coach_id on every write so that all existing reads (Auth,
 * Notifications, billing, the coach roster queries) keep working unchanged.
 *
 * Roles:
 *   - admin           full access to every athlete (and the admin panel).
 *   - coach           (head coach) access to athletes where coach_id = their id.
 *   - assistant_coach access to athletes where assistant_coach_id = their id.
 */
class CoachAssignments
{
    /**
     * Upsert the head-coach assignment for an athlete and mirror coach_id onto the
     * athletes row. Preserves any existing assistant_coach_id on re-assignment.
     */
    public static function assignCoach(int $athleteId, int $coachId, int $assignedBy, PDO $db): void
    {
        $db->prepare(
            'INSERT INTO coach_assignments (athlete_id, coach_id, assistant_coach_id, assigned_at, assigned_by)
             VALUES (?, ?, NULL, NOW(), ?)
             ON DUPLICATE KEY UPDATE coach_id = VALUES(coach_id), assigned_at = NOW(), assigned_by = VALUES(assigned_by)'
        )->execute([$athleteId, $coachId, $assignedBy]);

        // Keep the legacy column in sync so existing reads resolve the same coach.
        $db->prepare('UPDATE athletes SET coach_id = ? WHERE id = ?')->execute([$coachId, $athleteId]);
    }

    /**
     * Ensure a coach_assignments row exists for an athlete, seeding it from the
     * current athletes.coach_id (fallback to user 1 — the admin account).
     */
    public static function ensure(int $athleteId, int $assignedBy, PDO $db): void
    {
        $stmt = $db->prepare('SELECT 1 FROM coach_assignments WHERE athlete_id = ? LIMIT 1');
        $stmt->execute([$athleteId]);
        if ($stmt->fetchColumn()) return;

        $coachStmt = $db->prepare('SELECT coach_id FROM athletes WHERE id = ? LIMIT 1');
        $coachStmt->execute([$athleteId]);
        $coachId = (int)($coachStmt->fetchColumn() ?: 0) ?: 1;
        self::assignCoach($athleteId, $coachId, $assignedBy, $db);
    }

    /** Set (or clear, with null) the assistant coach for an athlete. Ensures a row exists first. */
    public static function setAssistant(int $athleteId, ?int $assistantCoachId, int $assignedBy, PDO $db): void
    {
        self::ensure($athleteId, $assignedBy, $db);
        $db->prepare('UPDATE coach_assignments SET assistant_coach_id = ? WHERE athlete_id = ?')
           ->execute([$assistantCoachId, $athleteId]);
    }

    /** The head coach user_id assigned to an athlete (null if none). */
    public static function coachId(int $athleteId, PDO $db): ?int
    {
        $stmt = $db->prepare('SELECT coach_id FROM coach_assignments WHERE athlete_id = ? LIMIT 1');
        $stmt->execute([$athleteId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** The assistant coach user_id assigned to an athlete (null if none). */
    public static function assistantCoachId(int $athleteId, PDO $db): ?int
    {
        $stmt = $db->prepare('SELECT assistant_coach_id FROM coach_assignments WHERE athlete_id = ? LIMIT 1');
        $stmt->execute([$athleteId]);
        $id = $stmt->fetchColumn();
        return ($id === false || $id === null) ? null : (int)$id;
    }

    /**
     * Can a (user, role) access this athlete? Admins always can; head coaches and
     * assistant coaches only their assigned athletes.
     */
    public static function canAccess(int $userId, ?string $role, int $athleteId, PDO $db): bool
    {
        if ($role === 'admin') return true;
        $col = $role === 'assistant_coach' ? 'assistant_coach_id' : 'coach_id';
        $stmt = $db->prepare("SELECT 1 FROM coach_assignments WHERE athlete_id = ? AND {$col} = ? LIMIT 1");
        $stmt->execute([$athleteId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Phase 4 dormancy gate: are there ≥2 active coaching accounts (coach or
     * assistant_coach)? With a single sole coach every Phase 4 surface
     * (sharing / assistant proposals / inheritance / analytics) stays hidden and
     * inert. The philosophy export is the one feature that does NOT consult this.
     */
    public static function multiCoach(PDO $db): bool
    {
        try {
            $n = (int)$db->query(
                "SELECT COUNT(*) FROM users WHERE active = 1 AND role IN ('coach','assistant_coach')"
            )->fetchColumn();
            return $n >= 2;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * SQL predicate + bound params restricting an `athletes` table reference to the
     * athletes a (user, role) manages. Head coaches/admins keep the existing
     * coach_id scope (athletes.coach_id is kept in sync); assistant coaches are
     * scoped through coach_assignments.assistant_coach_id.
     *
     * @return array{0:string,1:array} [sqlFragment, params]
     */
    public static function scope(int $userId, ?string $role, string $alias = 'a'): array
    {
        if ($role === 'assistant_coach') {
            return ["{$alias}.id IN (SELECT athlete_id FROM coach_assignments WHERE assistant_coach_id = ?)", [$userId]];
        }
        // coach / admin: athletes.coach_id is the head coach, kept in sync.
        return ["{$alias}.coach_id = ?", [$userId]];
    }
}

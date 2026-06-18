<?php
/**
 * Weekly cron: the Coaching Intelligence digest (Part 8).
 *
 * For each head coach with something to report — at least one open
 * coaching_intelligence_flag OR at least one flagged adjustment awaiting review —
 * sends one email summarizing the coaching week. Coaches with nothing to report are
 * skipped (no empty digests).
 *
 * NFSN scheduler: weekly, Monday, hour 8 UTC.
 *     php /home/private/app/scripts/cron_coaching_digest.php
 *
 * Flags: pass `verbose` to print per-coach detail; `--dry-run` to skip sending.
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

if (is_file(SCRIPT_ROOT . '/vendor/autoload.php')) {
    require SCRIPT_ROOT . '/vendor/autoload.php';
}
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Mailer.php';
require_once SCRIPT_ROOT . '/src/EmailTemplates.php';

$verbose = in_array('verbose', $argv ?? [], true);
$dryRun  = in_array('--dry-run', $argv ?? [], true);
$db      = Database::get();

echo date('Y-m-d H:i:s') . " — cron_coaching_digest starting" . ($dryRun ? ' [DRY RUN]' : '') . "\n";

// No-op cleanly if the Coaching Intelligence tables are not present yet.
$hasTables = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('coaching_intelligence_flags','coach_adjustments','athlete_behavior_log')"
)->fetchColumn();
if ($hasTables < 3) {
    echo "Coaching Intelligence tables missing (migration_027 not run yet) — nothing to do.\n";
    exit(0);
}

$mondayLabel = date('M j', strtotime('monday this week'));
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Head coaches with active athletes.
$coaches = $db->query(
    "SELECT u.id, u.name, u.email
     FROM users u
     WHERE u.email IS NOT NULL AND u.email <> ''
       AND u.id IN (SELECT DISTINCT coach_id FROM athletes WHERE coach_id IS NOT NULL AND status = 'active')"
)->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;
$skipped = 0;

foreach ($coaches as $c) {
    $coachId = (int)$c['id'];

    // Section 1 — warning-severity open flags (max 5).
    $warnStmt = $db->prepare(
        "SELECT cif.title, u.name AS athlete_name
         FROM coaching_intelligence_flags cif
         JOIN athletes a ON a.id = cif.athlete_id AND a.coach_id = ?
         JOIN users u ON u.id = a.user_id
         WHERE cif.status = 'open' AND cif.severity = 'warning'
         ORDER BY cif.created_at DESC LIMIT 6"
    );
    $warnStmt->execute([$coachId]);
    $warnings = $warnStmt->fetchAll(PDO::FETCH_ASSOC);

    // Section 2 — opportunity-severity open flags (max 3).
    $oppStmt = $db->prepare(
        "SELECT cif.title, u.name AS athlete_name
         FROM coaching_intelligence_flags cif
         JOIN athletes a ON a.id = cif.athlete_id AND a.coach_id = ?
         JOIN users u ON u.id = a.user_id
         WHERE cif.status = 'open' AND cif.severity = 'opportunity'
         ORDER BY cif.created_at DESC LIMIT 4"
    );
    $oppStmt->execute([$coachId]);
    $opps = $oppStmt->fetchAll(PDO::FETCH_ASSOC);

    // Section 3 — pending reviews count.
    $pending = (int)scalar($db,
        "SELECT COUNT(*) FROM coach_adjustments ca
         JOIN athletes a ON a.id = ca.athlete_id AND a.coach_id = ?
         WHERE ca.flagged_for_review = 1 AND ca.coaching_decision_id IS NULL",
        [$coachId]
    );

    // Total open flags (any severity) — drives the send guard.
    $totalOpen = (int)scalar($db,
        "SELECT COUNT(*) FROM coaching_intelligence_flags cif
         JOIN athletes a ON a.id = cif.athlete_id AND a.coach_id = ?
         WHERE cif.status = 'open'",
        [$coachId]
    );

    // GUARD: skip coaches with nothing to report.
    if ($totalOpen === 0 && $pending === 0) { $skipped++; continue; }

    // Section 4 — roster health.
    $activeAthletes = (int)scalar($db,
        "SELECT COUNT(*) FROM athletes WHERE coach_id = ? AND status = 'active'", [$coachId]);
    $avgCompletion = scalar($db,
        "SELECT AVG(latest.metric_value) FROM (
            SELECT abl.athlete_id, abl.metric_value
            FROM athlete_behavior_log abl
            JOIN (
                SELECT athlete_id, MAX(logged_at) ml
                FROM athlete_behavior_log WHERE metric_type = 'completion_rate' GROUP BY athlete_id
            ) m ON m.athlete_id = abl.athlete_id AND abl.logged_at = m.ml
            JOIN athletes a ON a.id = abl.athlete_id AND a.coach_id = ?
            WHERE abl.metric_type = 'completion_rate'
         ) latest",
        [$coachId]
    );
    $avgPct = ($avgCompletion !== null && $avgCompletion !== false) ? round((float)$avgCompletion * 100) . '%' : 'no data yet';

    // ── Build the body (no em dashes, no content tables) ──
    $body  = '<p style="font-weight:600;color:#1D9E75;margin:18px 0 6px;">Needs attention</p>';
    if ($warnings) {
        $body .= '<ul style="margin:0;padding-left:18px;">';
        foreach (array_slice($warnings, 0, 5) as $w) {
            $body .= '<li>' . $h($w['athlete_name']) . ': ' . $h($w['title']) . '</li>';
        }
        $body .= '</ul>';
        if (count($warnings) > 5) {
            $body .= '<p style="margin:6px 0 0;color:#666;">...and ' . (count($warnings) - 5) . ' more in the app.</p>';
        }
    } else {
        $body .= '<p style="margin:0;color:#666;">Nothing needs urgent attention this week.</p>';
    }

    $body .= '<p style="font-weight:600;color:#1D9E75;margin:18px 0 6px;">Opportunities</p>';
    if ($opps) {
        $body .= '<ul style="margin:0;padding-left:18px;">';
        foreach (array_slice($opps, 0, 3) as $o) {
            $body .= '<li>' . $h($o['athlete_name']) . ': ' . $h($o['title']) . '</li>';
        }
        $body .= '</ul>';
        if (count($opps) > 3) {
            $body .= '<p style="margin:6px 0 0;color:#666;">...and ' . (count($opps) - 3) . ' more in the app.</p>';
        }
    } else {
        $body .= '<p style="margin:0;color:#666;">No new opportunities flagged this week.</p>';
    }

    $body .= '<p style="font-weight:600;color:#1D9E75;margin:18px 0 6px;">Pending reviews</p>';
    if ($pending > 0) {
        $body .= '<p style="margin:0;">' . $pending . ' plan adjustment' . ($pending === 1 ? '' : 's')
               . ' flagged for review.</p>';
    } else {
        $body .= '<p style="margin:0;color:#666;">No adjustments waiting for review.</p>';
    }

    $body .= '<p style="font-weight:600;color:#1D9E75;margin:18px 0 6px;">Roster health</p>';
    $body .= '<p style="margin:0;">Active athletes: ' . $activeAthletes . '</p>';
    $body .= '<p style="margin:4px 0 0;">7 day average completion: ' . $h($avgPct) . '</p>';

    $email = EmailTemplates::build('coaching_digest', [
        'subject'     => 'Your coaching week: ' . $mondayLabel,
        'heading'     => 'Your coaching week',
        'detail_html' => $body,
    ], ['id' => $coachId, 'name' => $c['name'], 'email' => $c['email'], 'role' => 'coach']);

    if ($verbose) {
        echo "  {$c['name']}: warnings=" . count($warnings) . " opps=" . count($opps)
           . " pending={$pending} athletes={$activeAthletes}\n";
    }

    if ($dryRun) { $sent++; continue; }

    if (Mailer::send((string)$c['email'], $email['subject'], $email['html'], $email['text'])) {
        $sent++;
    } else {
        $skipped++;
    }
}

function scalar(PDO $db, string $sql, array $params)
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

echo date('Y-m-d H:i:s') . " — cron_coaching_digest complete. Sent {$sent}, skipped {$skipped}.\n";

<?php
/**
 * Verification: Flags tab full record (open + resolved across both flag tables, read-only).
 *
 * Seeds engine_flags + coaching_intelligence_flags across every status for a throwaway athlete,
 * then asserts CoachController::athleteFlagRecord() splits open vs resolved correctly, carries the
 * right resolution metadata (label / when / by-whom / reason; superseded → auto-resolved, never a
 * coach action), aggregates both tables, performs no writes, and that the Flags view renders no
 * dismiss/act controls. Also asserts the tab-strip badge still counts OPEN engine flags only.
 * Throwaway athlete + full teardown; Liam/live untouched.
 *
 * Run: php /home/private/app/scripts/verify_flags_tab.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
foreach ([SCRIPT_ROOT . '/config/config.local.php', '/home/public/config/config.local.php'] as $cfg) {
    if (file_exists($cfg)) { require $cfg; break; }
}
defined('DB_HOST')    || define('DB_HOST',    getenv('SRF_DB_HOST') ?: 'localhost');
defined('DB_NAME')    || define('DB_NAME',    getenv('SRF_DB_NAME') ?: 'simplyrunfaster');
defined('DB_USER')    || define('DB_USER',    getenv('SRF_DB_USER') ?: 'root');
defined('DB_PASS')    || define('DB_PASS',    getenv('SRF_DB_PASS') ?: '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8');

require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Timezone.php';
require_once SCRIPT_ROOT . '/views/layout/base.php';
require_once SCRIPT_ROOT . '/src/Controllers/CoachController.php';

$db = Database::get();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void { global $pass, $fail; echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n"; $ok ? $pass++ : $fail++; }
function findRow(array $rows, callable $f): ?array { foreach ($rows as $r) if ($f($r)) return $r; return null; }

$athleteId = null; $userId = null; $coachUserId = null;

try {
    $email = 'flagrec_' . substr(md5((string)mt_rand()), 0, 8) . '@example.test';
    $db->prepare("INSERT INTO users (email, password_hash, role, name, timezone) VALUES (?, ?, 'athlete', 'FlagRec Bot', 'America/New_York')")
       ->execute([$email, password_hash('x', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO users (email, password_hash, role, name, timezone) VALUES (?, ?, 'coach', 'Coach Carol', 'America/New_York')")
       ->execute(['flagrec_coach_' . substr(md5((string)mt_rand()), 0, 8) . '@example.test', password_hash('x', PASSWORD_DEFAULT)]);
    $coachUserId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO athletes (user_id, coach_id, onboarding_completed_at, status) VALUES (?, ?, NOW(), 'active')")->execute([$userId, $coachUserId]);
    $athleteId = (int)$db->lastInsertId();

    $now = time();
    $ts  = fn(int $daysAgo) => date('Y-m-d H:i:s', $now - $daysAgo * 86400);
    $d   = fn(int $daysAgo) => date('Y-m-d', $now - $daysAgo * 86400);

    // ── engine_flags: open / dismissed (by coach, with reason) / acted_on ──
    $ef = $db->prepare("INSERT INTO engine_flags (athlete_id, flag_type, severity, flag_date, message, status, reviewed_by, reviewed_at, dismiss_reason, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ef->execute([$athleteId, 'compliance_low', 'warning', $d(2), 'Open engine flag', 'open', null, null, null, $ts(2)]);
    $ef->execute([$athleteId, 'load_spike', 'critical', $d(10), 'Dismissed engine flag', 'dismissed', $coachUserId, $ts(8), 'looked fine on review', $ts(10)]);
    $ef->execute([$athleteId, 'missed_workouts', 'warning', $d(20), 'Acted-on engine flag', 'acted_on', $coachUserId, $ts(18), null, $ts(20)]);

    // ── coaching_intelligence_flags: open / actioned / dismissed / superseded ──
    $cif = $db->prepare("INSERT INTO coaching_intelligence_flags (athlete_id, coach_id, created_at, flag_type, severity, title, detail, suggested_action, status, actioned_at, dismissed_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $cif->execute([$athleteId, $coachUserId, $ts(3), 'rpe_trending_high', 'warning', 'Open intel flag', 'detail', null, 'open', null, null]);
    $cif->execute([$athleteId, $coachUserId, $ts(12), 'adaptation_ahead', 'opportunity', 'Actioned intel flag', 'detail', null, 'actioned', $ts(9), null]);
    $cif->execute([$athleteId, $coachUserId, $ts(15), 'compliance_dropping', 'warning', 'Dismissed intel flag', 'detail', null, 'dismissed', null, $ts(11)]);
    $cif->execute([$athleteId, $coachUserId, $ts(25), 'predicted_dropout', 'warning', 'Superseded intel flag', 'detail', 'Superseded: present-tense flag', 'superseded', null, $ts(22)]);

    // No-write baseline.
    $efc0 = (int)$db->query("SELECT COUNT(*) FROM engine_flags WHERE athlete_id={$athleteId}")->fetchColumn();
    $cic0 = (int)$db->query("SELECT COUNT(*) FROM coaching_intelligence_flags WHERE athlete_id={$athleteId}")->fetchColumn();

    $rec = CoachController::athleteFlagRecord($athleteId, $db);
    $open = $rec['open']; $resolved = $rec['resolved'];

    check("open: 2 flags (engine open + intel open)", count($open) === 2);
    check("open: both are is_open and span both sources",
        count(array_filter($open, fn($r) => $r['is_open'])) === 2
        && findRow($open, fn($r) => $r['source'] === 'engine') !== null
        && findRow($open, fn($r) => $r['source'] === 'intel') !== null);

    check("resolved: 5 flags (engine dismissed+acted_on; intel actioned+dismissed+superseded)", count($resolved) === 5);

    $engDis = findRow($resolved, fn($r) => $r['source'] === 'engine' && $r['status'] === 'dismissed');
    check("engine dismissed: label 'Dismissed', by coach name, reason recorded",
        $engDis && $engDis['resolution']['label'] === 'Dismissed'
        && $engDis['resolution']['by'] === 'Coach Carol'
        && $engDis['resolution']['reason'] === 'looked fine on review');

    $engAct = findRow($resolved, fn($r) => $r['source'] === 'engine' && $r['status'] === 'acted_on');
    check("engine acted_on: label 'Acted on', by coach name", $engAct && $engAct['resolution']['label'] === 'Acted on' && $engAct['resolution']['by'] === 'Coach Carol');

    $intAct = findRow($resolved, fn($r) => $r['source'] === 'intel' && $r['status'] === 'actioned');
    check("intel actioned: label 'Acted on'", $intAct && $intAct['resolution']['label'] === 'Acted on');

    $intDis = findRow($resolved, fn($r) => $r['source'] === 'intel' && $r['status'] === 'dismissed');
    check("intel dismissed: label 'Dismissed'", $intDis && $intDis['resolution']['label'] === 'Dismissed');

    $sup = findRow($resolved, fn($r) => $r['status'] === 'superseded');
    check("superseded: shows as 'Auto-resolved', NOT a coach action (no by)",
        $sup && $sup['resolution']['label'] === 'Auto-resolved' && empty($sup['resolution']['by']));

    // Resolved sorted by resolution time, most recent first.
    $sortedDesc = true;
    for ($i = 1; $i < count($resolved); $i++) {
        $prev = (string)($resolved[$i-1]['resolution']['at'] ?? $resolved[$i-1]['created_at']);
        $cur  = (string)($resolved[$i]['resolution']['at'] ?? $resolved[$i]['created_at']);
        if ($prev < $cur) { $sortedDesc = false; break; }
    }
    check("resolved: most-recently-resolved first", $sortedDesc);

    // Badge + dot now count ALL open flags across both tables (= the Open section), not history.
    $chrome = CoachController::athleteChromeData($athleteId, $db);
    check("badge counts ALL open flags across both tables (= Open section count, 2)",
        (int)$chrome['flag_count'] === 2 && (int)$chrome['flag_count'] === count($open));
    check("on-track dot reflects top open severity across both tables (amber: two warnings, no open critical)",
        $chrome['on_track'] === 'amber');

    // No writes.
    $efc1 = (int)$db->query("SELECT COUNT(*) FROM engine_flags WHERE athlete_id={$athleteId}")->fetchColumn();
    $cic1 = (int)$db->query("SELECT COUNT(*) FROM coaching_intelligence_flags WHERE athlete_id={$athleteId}")->fetchColumn();
    check("read-only: no rows written", $efc0 === $efc1 && $cic0 === $cic1);

    // Render: the Flags view has NO dismiss/act controls.
    $athlete = ['id' => $athleteId, 'name' => 'FlagRec Bot'];
    $flagRecord = $rec; $chromeActive = 'flags';
    ob_start(); include SCRIPT_ROOT . '/views/coach/athlete_flags.php'; $html = ob_get_clean();
    check("view renders OPEN + RESOLVED sections", strpos($html, 'OPEN') !== false && strpos($html, 'RESOLVED') !== false);
    check("view renders the auto-resolved (superseded) stamp", strpos($html, 'Auto-resolved') !== false);
    check("view has NO dismiss/act controls (no form/POST/dismiss button)",
        strpos($html, 'method="POST"') === false && stripos($html, '/dismiss') === false
        && strpos($html, '<button') === false);

    // ── Prior bug case: open intel flag, NO open engine flag → badge > 0 (was 0) ──
    $db->prepare("UPDATE engine_flags SET status='dismissed', reviewed_at=NOW() WHERE athlete_id=? AND status='open'")->execute([$athleteId]);
    $chromeNoEngine = CoachController::athleteChromeData($athleteId, $db);
    check("bug case fixed: open intel + no open engine → badge = 1 (was 0)", (int)$chromeNoEngine['flag_count'] === 1);
    check("bug case: dot still colored from the open intel warning (amber)", $chromeNoEngine['on_track'] === 'amber');

    // ── Critical dot: an open critical engine flag turns the dot red ──
    $db->prepare("INSERT INTO engine_flags (athlete_id, flag_type, severity, flag_date, message, status, created_at)
                  VALUES (?, 'plan_rebuild_needed', 'critical', CURDATE(), 'crit', 'open', NOW())")->execute([$athleteId]);
    $chromeCrit = CoachController::athleteChromeData($athleteId, $db);
    check("dot is red when an open critical flag exists", $chromeCrit['on_track'] === 'red' && (int)$chromeCrit['flag_count'] === 2);

    echo "\n================================\n";
    echo "  Flags tab full-record verification\n";
    echo "  PASS: {$pass}   FAIL: {$fail}\n";
    echo "================================\n";

} finally {
    if ($athleteId) {
        foreach (['engine_flags','coaching_intelligence_flags','athletes'] as $t) {
            $col = $t === 'athletes' ? 'id' : 'athlete_id';
            try { $db->prepare("DELETE FROM {$t} WHERE {$col} = ?")->execute([$athleteId]); } catch (\Throwable $e) {}
        }
    }
    foreach ([$userId, $coachUserId] as $uid) { if ($uid) { try { $db->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]); } catch (\Throwable $e) {} } }
}

exit($fail === 0 ? 0 : 1);

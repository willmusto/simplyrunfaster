<?php
/**
 * Verification: coach athlete-view shared chrome (tab strip + header meta).
 *
 * Asserts the pure CoachController::athleteChromeData() (open-flag count + on-track dot +
 * phase/week, no writes) and that the athlete_tabs.php strip marks exactly the active tab and
 * shows the Flags badge only when there are open flags. Throwaway athlete + full teardown.
 *
 * Run: php /home/private/app/scripts/verify_athlete_chrome.php
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

$athleteId = null; $userId = null;

try {
    $email = 'chrome_verify_' . substr(md5((string)mt_rand()), 0, 8) . '@example.test';
    $db->prepare("INSERT INTO users (email, password_hash, role, name, timezone) VALUES (?, ?, 'athlete', 'Chrome Bot', 'America/New_York')")
       ->execute([$email, password_hash('x', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO athletes (user_id, onboarding_completed_at, status) VALUES (?, NOW(), 'active')")->execute([$userId]);
    $athleteId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO athlete_profiles (athlete_id, plan_type, goal_race_distance) VALUES (?, 'development_plan', '10K')")->execute([$athleteId]);
    // Active plan: 12 weeks, started 2 weeks ago → "Week 3 of 12".
    $start = date('Y-m-d', strtotime('-14 days'));
    $end   = date('Y-m-d', strtotime('+70 days'));
    $db->prepare("INSERT INTO training_plans (athlete_id, status, plan_start_date, plan_end_date, generated_at, generation_trigger, plan_type)
                  VALUES (?, 'active', ?, ?, NOW(), 'onboarding', 'development_plan')")->execute([$athleteId, $start, $end]);

    $mkFlag = function (string $sev) use ($db, $athleteId) {
        $db->prepare("INSERT INTO engine_flags (athlete_id, flag_type, severity, status, message, created_at)
                      VALUES (?, 'compliance_low', ?, 'open', 'test', NOW())")->execute([$athleteId, $sev]);
    };

    // No flags → green, count 0.
    $c = CoachController::athleteChromeData($athleteId, $db);
    check("no flags: flag_count 0, on_track green", (int)$c['flag_count'] === 0 && $c['on_track'] === 'green');
    check("phase + week computed (Base · Week 3 of 12)", $c['phase'] === 'Base' && (int)$c['week'] === 3 && (int)$c['total_weeks'] === 12);

    // Warning → amber.
    $mkFlag('warning');
    $c = CoachController::athleteChromeData($athleteId, $db);
    check("one warning flag: flag_count 1, on_track amber", (int)$c['flag_count'] === 1 && $c['on_track'] === 'amber');
    $expected = (int)$db->query("SELECT COUNT(*) FROM engine_flags WHERE athlete_id={$athleteId} AND status='open'")->fetchColumn();
    check("flag_count matches the underlying open-flag query", (int)$c['flag_count'] === $expected);

    // Add critical → red (top severity).
    $mkFlag('critical');
    $c = CoachController::athleteChromeData($athleteId, $db);
    check("with a critical flag: flag_count 2, on_track red", (int)$c['flag_count'] === 2 && $c['on_track'] === 'red');

    // No writes by the chrome path.
    $efBefore = (int)$db->query("SELECT COUNT(*) FROM engine_flags WHERE athlete_id={$athleteId}")->fetchColumn();
    CoachController::athleteChromeData($athleteId, $db);
    $efAfter = (int)$db->query("SELECT COUNT(*) FROM engine_flags WHERE athlete_id={$athleteId}")->fetchColumn();
    check("read-only: chrome data writes nothing", $efBefore === $efAfter);

    // ── Tab strip: active-key marking + Flags badge ──
    $athlete = ['id' => $athleteId, 'name' => 'Chrome Bot'];
    $hrefs = [
        'plan' => '/app/coach/athlete/' . $athleteId,
        'log'  => '/app/coach/athlete/' . $athleteId . '/log',
        'messages' => '/app/coach/athlete/' . $athleteId . '/messages',
        'profile'  => '/app/coach/athlete/' . $athleteId . '/edit',
        'flags'    => '/app/coach/athlete/' . $athleteId . '/flags',
    ];
    foreach (array_keys($hrefs) as $key) {
        $chromeActive = $key;
        $chrome = ['flag_count' => 2];
        ob_start(); include SCRIPT_ROOT . '/views/coach/partials/athlete_tabs.php'; $html = ob_get_clean();
        $onlyOne   = substr_count($html, 'is-active') === 1;
        $rightOne  = strpos($html, 'href="' . $hrefs[$key] . '" class="av-tab is-active"') !== false;
        check("tab strip: active='{$key}' marks exactly that tab", $onlyOne && $rightOne);
    }
    // Flags badge: shown at >0, hidden at 0.
    $chromeActive = 'plan';
    $chrome = ['flag_count' => 2];
    ob_start(); include SCRIPT_ROOT . '/views/coach/partials/athlete_tabs.php'; $withBadge = ob_get_clean();
    $chrome = ['flag_count' => 0];
    ob_start(); include SCRIPT_ROOT . '/views/coach/partials/athlete_tabs.php'; $noBadge = ob_get_clean();
    check("Flags badge shows when open flags exist, hidden at zero",
        strpos($withBadge, 'av-tab-badge') !== false && strpos($noBadge, 'av-tab-badge') === false);

    echo "\n================================\n";
    echo "  Athlete-view chrome verification\n";
    echo "  PASS: {$pass}   FAIL: {$fail}\n";
    echo "================================\n";

} finally {
    if ($athleteId) {
        foreach (['engine_flags','training_plans','athlete_profiles'] as $t) {
            try { $db->prepare("DELETE FROM {$t} WHERE athlete_id=?")->execute([$athleteId]); } catch (\Throwable $e) {}
        }
        $db->prepare("DELETE FROM athletes WHERE id=?")->execute([$athleteId]);
    }
    if ($userId) $db->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
}

exit($fail === 0 ? 0 : 1);

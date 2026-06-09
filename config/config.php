<?php
/**
 * SimplyRunFaster — Application Configuration
 *
 * Copy this file to config/config.local.php on the server and fill in real values.
 * config.local.php is git-ignored and never committed.
 */

// Local config loaded first so its values take precedence over the defaults below.
// Falls back to the web-root copy for scripts running outside /home/public (e.g. CLI tools in /home/private/app).
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} elseif (file_exists('/home/public/config/config.local.php')) {
    require '/home/public/config/config.local.php';
}

// Database
defined('DB_HOST')    || define('DB_HOST',    getenv('SRF_DB_HOST') ?: 'localhost');
defined('DB_NAME')    || define('DB_NAME',    getenv('SRF_DB_NAME') ?: 'simplyrunfaster');
defined('DB_USER')    || define('DB_USER',    getenv('SRF_DB_USER') ?: 'root');
defined('DB_PASS')    || define('DB_PASS',    getenv('SRF_DB_PASS') ?: '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8');

// Application
defined('APP_NAME')    || define('APP_NAME',    'SimplyRunFaster');
defined('APP_URL')     || define('APP_URL',     getenv('SRF_APP_URL') ?: 'http://localhost/app');
defined('APP_VERSION') || define('APP_VERSION', '1.0.0');
defined('APP_ENV')     || define('APP_ENV',     getenv('SRF_ENV') ?: 'development');
defined('APP_DEBUG')   || define('APP_DEBUG',   APP_ENV === 'development');

// Security
defined('SESSION_NAME')         || define('SESSION_NAME',         'srf_session');
defined('SESSION_LIFETIME')     || define('SESSION_LIFETIME',     60 * 60 * 24 * 14); // 14 days
defined('CSRF_TOKEN_NAME')      || define('CSRF_TOKEN_NAME',      'srf_csrf');
defined('PASSWORD_MIN_LENGTH')  || define('PASSWORD_MIN_LENGTH',  8);

// Web Push (VAPID keys — generate with web-push library or openssl)
defined('VAPID_PUBLIC_KEY')  || define('VAPID_PUBLIC_KEY',  getenv('SRF_VAPID_PUBLIC')  ?: '');
defined('VAPID_PRIVATE_KEY') || define('VAPID_PRIVATE_KEY', getenv('SRF_VAPID_PRIVATE') ?: '');
defined('VAPID_SUBJECT')     || define('VAPID_SUBJECT',     'mailto:hello@simplyrunfaster.com');

// Invite links
defined('INVITE_DEFAULT_EXPIRY_DAYS') || define('INVITE_DEFAULT_EXPIRY_DAYS', 7);
defined('INVITE_DEFAULT_MAX_USES')    || define('INVITE_DEFAULT_MAX_USES',    1);

// Training engine
defined('ATHLETE_WINDOW_DAYS') || define('ATHLETE_WINDOW_DAYS', 10);  // rolling days visible to athlete
defined('PUSH_AHEAD_DAYS')     || define('PUSH_AHEAD_DAYS',     1);   // push workout to watch T-1 day before
defined('ATL_DAYS')            || define('ATL_DAYS',            7);   // acute training load window
defined('CTL_DAYS')            || define('CTL_DAYS',            42);  // chronic training load window
defined('TSB_RACE_TARGET_MIN') || define('TSB_RACE_TARGET_MIN', 20);  // target TSB range on race day
defined('TSB_RACE_TARGET_MAX') || define('TSB_RACE_TARGET_MAX', 25);

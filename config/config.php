<?php
/**
 * SimplyRunFaster — Application Configuration
 *
 * Copy this file to config/config.local.php on the server and fill in real values.
 * config.local.php is git-ignored and never committed.
 */

// Database
define('DB_HOST', getenv('SRF_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('SRF_DB_NAME') ?: 'simplyrunfaster');
define('DB_USER', getenv('SRF_DB_USER') ?: 'root');
define('DB_PASS', getenv('SRF_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Application
define('APP_NAME',    'SimplyRunFaster');
define('APP_URL',     getenv('SRF_APP_URL') ?: 'http://localhost');
define('APP_VERSION', '1.0.0');
define('APP_ENV',     getenv('SRF_ENV') ?: 'development');
define('APP_DEBUG',   APP_ENV === 'development');

// Security
define('SESSION_NAME',      'srf_session');
define('SESSION_LIFETIME',  60 * 60 * 24 * 14); // 14 days
define('CSRF_TOKEN_NAME',   'srf_csrf');
define('PASSWORD_MIN_LENGTH', 8);

// Web Push (VAPID keys — generate with web-push library or openssl)
define('VAPID_PUBLIC_KEY',  getenv('SRF_VAPID_PUBLIC')  ?: '');
define('VAPID_PRIVATE_KEY', getenv('SRF_VAPID_PRIVATE') ?: '');
define('VAPID_SUBJECT',     'mailto:hello@simplyrunfaster.com');

// Invite links
define('INVITE_DEFAULT_EXPIRY_DAYS', 7);
define('INVITE_DEFAULT_MAX_USES',    1);

// Training engine
define('ATHLETE_WINDOW_DAYS',   10);  // rolling days visible to athlete
define('PUSH_AHEAD_DAYS',       1);   // push workout to watch T-1 day before
define('ATL_DAYS',              7);   // acute training load window
define('CTL_DAYS',              42);  // chronic training load window
define('TSB_RACE_TARGET_MIN',   20);  // target TSB range on race day
define('TSB_RACE_TARGET_MAX',   25);

// Override with local config if present (production credentials)
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

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

// Base timezone: the server, DB, and all PHP date() math operate in UTC. Per-user
// local conversion happens explicitly via src/Timezone.php (never by changing this).
date_default_timezone_set('UTC');

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
defined('SESSION_LIFETIME')     || define('SESSION_LIFETIME',     60 * 60 * 24 * 30); // 30 days
defined('CSRF_TOKEN_NAME')      || define('CSRF_TOKEN_NAME',      'srf_csrf');
defined('PASSWORD_MIN_LENGTH')  || define('PASSWORD_MIN_LENGTH',  8);

// Web Push (VAPID keys — generate with web-push library or openssl)
defined('VAPID_PUBLIC_KEY')  || define('VAPID_PUBLIC_KEY',  getenv('SRF_VAPID_PUBLIC')  ?: '');
defined('VAPID_PRIVATE_KEY') || define('VAPID_PRIVATE_KEY', getenv('SRF_VAPID_PRIVATE') ?: '');
defined('VAPID_SUBJECT')     || define('VAPID_SUBJECT',     'mailto:hello@simplyrunfaster.com');

// Transactional email (Resend). Real key lives in config/config.local.php on the
// server (git-ignored). This placeholder documents the constant for deploys.
defined('RESEND_API_KEY')    || define('RESEND_API_KEY',    getenv('SRF_RESEND_API_KEY') ?: '');
defined('MAIL_FROM_ADDRESS') || define('MAIL_FROM_ADDRESS', 'noreply@simplyrunfaster.com');

// PRIVACY_EMAIL = privacy@simplyrunfaster.com
// Will needs to set up this email address and forward to his inbox.
// Required before beta launch. (Reminder only — not wired into any code yet;
// the Privacy Policy at /app/privacy already references this address.)

// Invite links
defined('INVITE_DEFAULT_EXPIRY_DAYS') || define('INVITE_DEFAULT_EXPIRY_DAYS', 7);
defined('INVITE_DEFAULT_MAX_USES')    || define('INVITE_DEFAULT_MAX_USES',    1);

// Stripe billing (Milestone 8). Real keys live in config/config.local.php on the
// server (git-ignored); these placeholders document the constants for deploys.
// Use TEST keys (sk_test_…/pk_test_…) until going live.
defined('STRIPE_SECRET_KEY')      || define('STRIPE_SECRET_KEY',      getenv('SRF_STRIPE_SECRET')      ?: '');
defined('STRIPE_PUBLISHABLE_KEY') || define('STRIPE_PUBLISHABLE_KEY', getenv('SRF_STRIPE_PUBLISHABLE') ?: '');
defined('STRIPE_WEBHOOK_SECRET')  || define('STRIPE_WEBHOOK_SECRET',  getenv('SRF_STRIPE_WEBHOOK')     ?: '');
// Recurring price IDs created in the Stripe dashboard (one product, two prices).
defined('STRIPE_PRICE_MONTHLY')   || define('STRIPE_PRICE_MONTHLY',   getenv('SRF_STRIPE_PRICE_MONTHLY') ?: '');
defined('STRIPE_PRICE_ANNUAL')    || define('STRIPE_PRICE_ANNUAL',    getenv('SRF_STRIPE_PRICE_ANNUAL')  ?: '');
// Display prices (cents) — purely cosmetic for the billing/admin UI.
defined('STRIPE_PRICE_MONTHLY_DISPLAY') || define('STRIPE_PRICE_MONTHLY_DISPLAY', '$39/month');
defined('STRIPE_PRICE_ANNUAL_DISPLAY')  || define('STRIPE_PRICE_ANNUAL_DISPLAY',  '$396/year');
// Grace window (days) after a first failed payment before access is cut off.
defined('BILLING_GRACE_DAYS')     || define('BILLING_GRACE_DAYS',     7);
// When true, athletes with subscription_status='none' are also gated (full
// enforcement). Default false so existing/grandfathered athletes keep access
// and onboarding (pre-checkout) is never locked out. Flip on once every active
// athlete has a live subscription.
defined('BILLING_GATE_STRICT')    || define('BILLING_GATE_STRICT',    false);

// Training engine
defined('ATHLETE_WINDOW_DAYS') || define('ATHLETE_WINDOW_DAYS', 10);  // rolling days visible to athlete
defined('PUSH_AHEAD_DAYS')     || define('PUSH_AHEAD_DAYS',     1);   // push workout to watch T-1 day before
defined('ATL_DAYS')            || define('ATL_DAYS',            7);   // acute training load window
defined('CTL_DAYS')            || define('CTL_DAYS',            42);  // chronic training load window
defined('TSB_RACE_TARGET_MIN') || define('TSB_RACE_TARGET_MIN', 20);  // target TSB range on race day
defined('TSB_RACE_TARGET_MAX') || define('TSB_RACE_TARGET_MAX', 25);

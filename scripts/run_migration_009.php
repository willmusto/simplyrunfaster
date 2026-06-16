<?php
/**
 * Migration 009 runner — notification system.
 *
 * Applies sql/migration_009_notification_system.sql (idempotent) and then seeds
 * default notification_preferences rows for every existing user, role-aware.
 * Safe to re-run: table creates use IF NOT EXISTS and seeding uses INSERT IGNORE.
 *
 *   php scripts/run_migration_009.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

// config.php loads config.local.php itself (web-root fallback), so don't also
// require it here or constants get defined twice.
require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';
require_once SCRIPT_ROOT . '/src/Timezone.php';
require_once SCRIPT_ROOT . '/src/Notifications.php';

$db = Database::get();

// ── Apply DDL ───────────────────────────────────────────────────────────────
// Strip all -- comment lines first (some contain semicolons), then split.
$sql  = file_get_contents(SCRIPT_ROOT . '/sql/migration_009_notification_system.sql');
$sql  = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if ($stmt !== '') $db->exec($stmt);
}
echo "DDL applied (notification_preferences, push_subscriptions present).\n";

// ── Seed defaults for all users ─────────────────────────────────────────────
$inserted = Notifications::seedDefaultsForAllUsers();
$total    = (int)$db->query('SELECT COUNT(*) FROM notification_preferences')->fetchColumn();
echo "Seeded {$inserted} new preference rows. Total rows now: {$total}.\n";

echo date('Y-m-d H:i:s') . " — migration 009 complete.\n";

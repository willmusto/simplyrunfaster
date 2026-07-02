<?php
/**
 * Migration 037 runner: generate-to-draft staging tables.
 *
 * Clones training_plans -> plan_drafts (+ coach_id / expires_at /
 * source_trigger) and planned_workouts -> plan_draft_workouts, both with
 * AUTO_INCREMENT = 1000000 so draft ids are disjoint from live ids.
 * Idempotent: tables are created and columns added only when missing.
 *
 *     php scripts/run_migration_037.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

$hasTable = function (string $t) use ($db): bool {
    $s = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->execute([$t]);
    return (int)$s->fetchColumn() > 0;
};
$hasColumn = function (string $t, string $c) use ($db): bool {
    $s = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$t, $c]);
    return (int)$s->fetchColumn() > 0;
};

if (!$hasTable('plan_drafts')) {
    echo "Creating plan_drafts (LIKE training_plans)...\n";
    $db->exec('CREATE TABLE `plan_drafts` LIKE `training_plans`');
    echo "  ok.\n";
} else {
    echo "plan_drafts already present.\n";
}
foreach ([
    'coach_id'       => "ADD COLUMN `coach_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'coach who generated the draft'",
    'expires_at'     => "ADD COLUMN `expires_at` DATETIME DEFAULT NULL COMMENT 'TTL; retention cron removes expired drafts'",
    'source_trigger' => "ADD COLUMN `source_trigger` VARCHAR(50) DEFAULT NULL",
] as $col => $ddl) {
    if (!$hasColumn('plan_drafts', $col)) {
        echo "Adding plan_drafts.{$col}...\n";
        $db->exec("ALTER TABLE `plan_drafts` {$ddl}");
        echo "  ok.\n";
    }
}
$db->exec('ALTER TABLE `plan_drafts` AUTO_INCREMENT = 1000000');

if (!$hasTable('plan_draft_workouts')) {
    echo "Creating plan_draft_workouts (LIKE planned_workouts)...\n";
    $db->exec('CREATE TABLE `plan_draft_workouts` LIKE `planned_workouts`');
    echo "  ok.\n";
} else {
    echo "plan_draft_workouts already present.\n";
}
$db->exec('ALTER TABLE `plan_draft_workouts` AUTO_INCREMENT = 1000000');

echo date('Y-m-d H:i:s') . " migration 037 complete.\n";

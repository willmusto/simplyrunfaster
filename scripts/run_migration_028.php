<?php
/**
 * Migration 028 runner — Coaching Intelligence Layer (Phase 2 of 4).
 *
 *  1) coaching_decisions.proposed_from_count, coaching_decisions.proposed_at
 *     (pattern-proposer bookkeeping).
 *  2) coach_adjustments.proposed_decision_id (so the proposer never reconsiders
 *     an adjustment it already grouped or that an existing rule covers).
 *  3) coach_roster_insights — cross-athlete patterns (the Roster Insights feed).
 *  4) weekly_review_log — one row per coach per week, the review-complete marker.
 *
 * Idempotent: tables created only when missing; columns added only when missing.
 * Safe to re-run.
 *
 *     php scripts/run_migration_028.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

$hasTable = function (string $table) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
};

$hasColumn = function (string $table, string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
};

$addColumn = function (string $table, string $col, string $ddl) use ($db, $hasColumn): void {
    if (!$hasColumn($table, $col)) {
        echo "Adding {$table}.{$col}…\n";
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
        echo "  ok.\n";
    } else {
        echo "{$table}.{$col} already present — skipping.\n";
    }
};

$addColumn('coaching_decisions', 'proposed_from_count',
    "`proposed_from_count` INT NULL COMMENT 'how many adjustments triggered this proposal'");
$addColumn('coaching_decisions', 'proposed_at',
    "`proposed_at` DATETIME NULL COMMENT 'when the pattern proposer generated this'");
$addColumn('coach_adjustments', 'proposed_decision_id',
    "`proposed_decision_id` INT NULL COMMENT 'set when this adjustment contributed to a proposal'");

if (!$hasTable('coach_roster_insights')) {
    echo "Creating coach_roster_insights…\n";
    $db->exec(
        "CREATE TABLE `coach_roster_insights` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`        INT NOT NULL,
            `created_at`      DATETIME NOT NULL,
            `insight_type`    ENUM(
                'compliance_cluster','engagement_cluster','upcoming_races',
                'adjustment_pattern','streak_cluster','workload_spike'
            ) NOT NULL,
            `title`           VARCHAR(255) NOT NULL,
            `detail`          TEXT NOT NULL,
            `athlete_ids`     LONGTEXT NOT NULL,
            `severity`        ENUM('info','warning','opportunity') NOT NULL DEFAULT 'info',
            `status`          ENUM('open','dismissed') NOT NULL DEFAULT 'open',
            `dismissed_at`    DATETIME NULL,
            KEY `idx_cri_coach_status` (`coach_id`, `status`),
            KEY `idx_cri_type_created` (`insight_type`, `created_at`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
    );
    echo "  ok.\n";
} else {
    echo "coach_roster_insights already present — skipping.\n";
}

if (!$hasTable('weekly_review_log')) {
    echo "Creating weekly_review_log…\n";
    $db->exec(
        "CREATE TABLE `weekly_review_log` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`        INT NOT NULL,
            `week_start`      DATE NOT NULL,
            `completed_at`    DATETIME NULL,
            `items_reviewed`  INT NOT NULL DEFAULT 0,
            `decisions_added` INT NOT NULL DEFAULT 0,
            `flags_actioned`  INT NOT NULL DEFAULT 0,
            `flags_dismissed` INT NOT NULL DEFAULT 0,
            UNIQUE KEY `unique_coach_week` (`coach_id`, `week_start`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8"
    );
    echo "  ok.\n";
} else {
    echo "weekly_review_log already present — skipping.\n";
}

echo date('Y-m-d H:i:s') . " — migration 028 complete.\n";

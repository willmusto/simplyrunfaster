<?php
/**
 * Migration 030 runner — Coaching Intelligence Layer (Phase 4 of 4).
 *
 *  1) coaching_decisions.status ENUM += 'proposed_by_assistant'.
 *  2) coaching_decisions.shared TINYINT(1) DEFAULT 0.
 *  3) coaching_decisions.rationale TEXT NULL.
 *  4) coaching_intelligence_flags.status ENUM += 'superseded', then data-migrate the
 *     Phase 3 [auto-resolved] marker rows (status='dismissed') to 'superseded'.
 *
 * Idempotent: ENUMs widened only when the value is absent; columns added only when
 * missing; the backfill is naturally a no-op once the markered rows are converted.
 *
 *     php scripts/run_migration_030.php
 */

define('SCRIPT_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

require_once SCRIPT_ROOT . '/config/config.php';
require_once SCRIPT_ROOT . '/config/database.php';

$db = Database::get();

$hasColumn = function (string $table, string $col) use ($db): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
};

$columnType = function (string $table, string $col) use ($db): string {
    $stmt = $db->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (string)$stmt->fetchColumn();
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

// 1) coaching_decisions.status += proposed_by_assistant
if (strpos($columnType('coaching_decisions', 'status'), 'proposed_by_assistant') === false) {
    echo "Widening coaching_decisions.status ENUM…\n";
    $db->exec("ALTER TABLE `coaching_decisions`
               MODIFY COLUMN `status` ENUM('active','inactive','proposed','proposed_by_assistant') NOT NULL DEFAULT 'proposed'");
    echo "  ok.\n";
} else {
    echo "coaching_decisions.status already has proposed_by_assistant — skipping.\n";
}

// 2) + 3) shared / rationale
$addColumn('coaching_decisions', 'shared',
    "`shared` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Phase 4: head coach shares this active rule across the whole roster'");
$addColumn('coaching_decisions', 'rationale',
    "`rationale` TEXT NULL COMMENT 'Phase 4: the \"why\", surfaced in the coaching philosophy export'");

// 4) coaching_intelligence_flags.status += superseded, then backfill the marker rows.
if (strpos($columnType('coaching_intelligence_flags', 'status'), 'superseded') === false) {
    echo "Widening coaching_intelligence_flags.status ENUM…\n";
    $db->exec("ALTER TABLE `coaching_intelligence_flags`
               MODIFY COLUMN `status` ENUM('open','actioned','dismissed','superseded') NOT NULL DEFAULT 'open'");
    echo "  ok.\n";
} else {
    echo "coaching_intelligence_flags.status already has superseded — skipping.\n";
}

$converted = $db->exec(
    "UPDATE coaching_intelligence_flags
        SET status = 'superseded'
      WHERE status = 'dismissed' AND suggested_action LIKE '[auto-resolved]%'"
);
echo "Backfilled " . (int)$converted . " [auto-resolved] flag(s) to status='superseded'.\n";

echo date('Y-m-d H:i:s') . " — migration 030 complete.\n";

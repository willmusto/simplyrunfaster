<?php
/**
 * Migration: add the "Diminishing Descending Ladder" variant row to
 * structured_fartlek_ladder so it appears in the Library variant list and is
 * coach-selectable (the family is built parametrically in code by
 * PlanGenerator::buildDiminishingLadder; this row just makes it pickable).
 *
 * Variant data is DB-seeded in workout_archetypes.variants (JSON column). This
 * UPDATEs that one row; no other field is touched. Idempotent: re-running is a
 * no-op once the variant is present.
 *
 * Run from /home/private/app: php scripts/migrate_fartlek_diminishing_variant.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = Database::get();

$NEW = [
    'code'     => 'diminishing_descending',
    'name'     => 'Diminishing Descending Ladder',
    'examples' => ['90-80-...-10, 80-...-10, 70-...-10'],
];

$sel = $pdo->prepare('SELECT variants FROM workout_archetypes WHERE code = :code LIMIT 1');
$sel->execute([':code' => 'structured_fartlek_ladder']);
$row = $sel->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "structured_fartlek_ladder not found.\n");
    exit(1);
}

$variants = json_decode((string)$row['variants'], true);
if (!is_array($variants)) {
    fwrite(STDERR, "Could not decode variants JSON.\n");
    exit(1);
}

foreach ($variants as $v) {
    if (($v['code'] ?? null) === 'diminishing_descending') {
        echo "Already present; nothing to do (" . count($variants) . " variants).\n";
        exit(0);
    }
}

$variants[] = $NEW;
$pdo->prepare('UPDATE workout_archetypes SET variants = :variants WHERE code = :code')
    ->execute([
        ':variants' => json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':code'     => 'structured_fartlek_ladder',
    ]);

echo "Added 'diminishing_descending' variant (now " . count($variants) . " variants). "
   . "No planned_workouts rows touched.\n";
exit(0);

<?php
/**
 * LIVE verification for the archetype-descriptions template update + even-or-3 snapping +
 * conditional checkpoint clause. Read-only (no DB writes). Run AFTER the migration so the
 * archetypes carry the new copy. From /home/private/app:
 *   php scripts/verify_archetype_descriptions.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Timezone.php';
require_once __DIR__ . '/../src/Engine/PaceZones.php';
require_once __DIR__ . '/../src/Engine/ArchetypeSelector.php';
require_once __DIR__ . '/../src/Engine/PlanGenerator.php';

$pdo        = Database::get();
$selector   = new ArchetypeSelector($pdo);
$addDerived = new ReflectionMethod(PlanGenerator::class, 'addDerivedParams'); $addDerived->setAccessible(true);
$renderTpl  = new ReflectionMethod(PlanGenerator::class, 'renderTemplate');  $renderTpl->setAccessible(true);
$ckClause   = new ReflectionMethod(PlanGenerator::class, 'checkpointClause'); $ckClause->setAccessible(true);

$fails = [];
function check(bool $cond, string $msg): void { global $fails; if (!$cond) $fails[] = $msg; }

/** Resolve + render an instance's athlete-facing description through the real path. */
function render($selector, $addDerived, $renderTpl, string $code, string $cls, string $phase, string $goal, int $target): array {
    $a = $selector->getByCode($code);
    $a = $selector->resolveParameters($a, $cls);
    $a = $addDerived->invoke(null, $a, $target, $phase, $goal, $cls);
    $desc = $renderTpl->invoke(null, $a['display']['description_template'] ?? '', $a);
    return [$a['resolved_params'] ?? [], $desc];
}

$VALID = fn(int $n) => $n === 3 || $n % 2 === 0;

echo "\n=== Archetype descriptions + even-or-3 + conditional checkpoint ===\n";

// ---------------------------------------------------------------------------
// 1. Tempo: only even-or-3 counts, variety preserved.
// ---------------------------------------------------------------------------
echo "\n[1] Tempo rep counts even-or-3 (well_trained, build, half, 200 samples):\n";
$counts = []; $structs = [];
for ($i = 0; $i < 200; $i++) {
    [$p] = render($selector, $addDerived, $renderTpl, 'tempo_intervals', 'well_trained', 'build', 'half', 80);
    $rc = (int)$p['rep_count']; $counts[$rc] = ($counts[$rc] ?? 0) + 1;
    $structs[$rc . 'x' . (int)$p['rep_duration_minutes']] = true;
    check($VALID($rc), "tempo rep_count $rc not even-or-3");
    check($rc !== 5 && $rc !== 7, "tempo produced disallowed $rc");
}
ksort($counts);
echo "  counts seen: " . implode(', ', array_map(fn($k, $v) => "{$k}:{$v}", array_keys($counts), $counts)) . "\n";
printf("  distinct structures: %d (variety preserved)\n", count($structs));
check(count($structs) >= 6, "tempo variety collapsed: " . count($structs));

// ---------------------------------------------------------------------------
// 2. checkpointClause thresholds + ordinals (direct unit check).
// ---------------------------------------------------------------------------
echo "\n[2] checkpointClause(rep_count) thresholds + ordinals:\n";
$action = 'take an extra 45 to 90 seconds of standing recovery if you need it';
foreach ([2, 3, 4, 6, 8, 10, 12, 14, 16, 18, 20, 24] as $rc) {
    $clause = $ckClause->invoke(null, $rc, $action);
    $n = substr_count($clause, 'After the');
    printf("  rep=%-2d -> %s\n", $rc, $clause === '' ? '(omitted)' : trim($clause));
    if ($rc <= 8)        check($clause === '',        "rep=$rc should omit, got: $clause");
    elseif ($rc <= 16)   check($n === 1,              "rep=$rc should have ONE checkpoint");
    else                 check($n === 1 && substr_count($clause, ' and ') === 1, "rep=$rc should have TWO checkpoints");
    if ($rc > 8 && $rc <= 16) check(str_contains($clause, 'the ' . ($rc / 2)), "rep=$rc halfway wrong");
    check(!preg_match('/\b\dth\b.*\b[123]th\b/', $clause) && !str_contains($clause, '1th') && !str_contains($clause, '2th') && !str_contains($clause, '3th'),
        "rep=$rc bad ordinal in: $clause");
    check(!str_contains($clause, '{{'), "rep=$rc literal token");
}

// ---------------------------------------------------------------------------
// 3. Hills + equal_distance: real generation, even-or-3, clause matches count.
// ---------------------------------------------------------------------------
echo "\n[3] Hills + equal_distance generated instances (count + clause):\n";
$cases = [
    ['sustained_hill_repeats', 'base',  45],
    ['sustained_hill_repeats', 'peak',  95],
    ['sustained_hill_repeats', 'peak',  200],
    ['equal_distance_repeats', 'base',  45],
    ['equal_distance_repeats', 'peak',  95],
];
foreach ($cases as [$code, $phase, $target]) {
    [$p, $desc] = render($selector, $addDerived, $renderTpl, $code, 'well_trained', $phase, '10K', $target);
    $rc = (int)$p['rep_count'];
    $hasClause = str_contains($desc, 'After the');
    printf("  %-24s %-5s t=%-3d  rep=%-2d  clause=%s\n", $code, $phase, $target, $rc, $hasClause ? 'yes' : 'no');
    printf("        ...%s\n", mb_substr($desc, max(0, mb_strlen($desc) - 95)));
    check($VALID($rc), "$code/$phase rep_count $rc not even-or-3");
    check(!str_contains($desc, '{{'), "$code/$phase literal token in desc");
    check(!str_contains($desc, "\u{2014}"), "$code/$phase em dash in desc");
    if ($rc <= 8)      check(!$hasClause, "$code/$phase rep=$rc should have NO clause");
    else               check($hasClause,  "$code/$phase rep=$rc should have a clause");
}

// ---------------------------------------------------------------------------
// 4. All 5 templated archetypes render REAL numbers, no literal {{...}}.
// ---------------------------------------------------------------------------
echo "\n[4] Templated archetypes render real numbers (no literal braces):\n";
$templated = [
    ['easy_with_strides',       'base', 50],
    ['equal_distance_repeats',  'peak', 95],
    ['sustained_hill_repeats',  'peak', 95],
    ['hill_sprints',            'base', 45],
    ['long_run_with_pickups',   'base', 90],
];
foreach ($templated as [$code, $phase, $target]) {
    [, $desc] = render($selector, $addDerived, $renderTpl, $code, 'well_trained', $phase, '10K', $target);
    printf("  %-24s %s\n", $code, mb_substr($desc, 0, 96));
    check(!str_contains($desc, '{{'), "$code literal token survived: $desc");
    check(!str_contains($desc, "\u{2014}"), "$code em dash");
}

// ---------------------------------------------------------------------------
// 5. A few non-templated archetypes render the new static copy (no braces).
// ---------------------------------------------------------------------------
echo "\n[5] Non-templated archetypes render new static copy:\n";
foreach (['continuous_easy', 'tempo_intervals', 'short_speed_repeats'] as $code) {
    [, $desc] = render($selector, $addDerived, $renderTpl, $code, 'well_trained', 'build', '10K', 50);
    printf("  %-24s %s\n", $code, mb_substr($desc, 0, 96));
    check(!str_contains($desc, '{{'), "$code literal token: $desc");
    check(!str_contains($desc, "\u{2014}"), "$code em dash");
}

echo "\n=== Verdict ===\n";
if (empty($fails)) { echo "PASS\n\n"; exit(0); }
echo "FAIL (" . count($fails) . "):\n";
foreach ($fails as $f) echo "  - $f\n";
echo "\n";
exit(1);

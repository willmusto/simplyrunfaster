<?php
/**
 * Coaching philosophy export (CIL Phase 4, B2). A standalone, print-styled page
 * (browser save-to-PDF) rendering the coach's active decisions — their own plus the
 * shared decisions they rely on — with title, plain-prose trigger/action, and rationale.
 *
 * Rendered directly by CoachController::philosophyExport (no app shell — print-clean).
 * Vars: $decisions, $coachName.
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$prose = static function (array $d) use ($h): string {
    $trig = json_decode((string)($d['trigger_json'] ?? ''), true) ?: [];
    $act  = json_decode((string)($d['action_json'] ?? ''), true) ?: [];

    $dists  = $trig['goal_distance'] ?? [];
    $phases = $trig['phase'] ?? [];
    $scope  = 'When generating plans';
    if ($dists)  $scope .= ' for ' . implode('/', (array)$dists) . ' athletes';
    if ($phases) $scope .= ($dists ? '' : ' for athletes') . ' in ' . implode('/', (array)$phases) . ' phase';
    if (!empty($trig['classification']) && is_array($trig['classification'])) {
        $scope .= ' (' . implode('/', $trig['classification']) . ')';
    }

    $bits = [];
    if (!empty($act['exclude_archetypes'])) $bits[] = 'exclude ' . implode(', ', (array)$act['exclude_archetypes']);
    if (!empty($act['weight_multipliers']) && is_array($act['weight_multipliers'])) {
        foreach ($act['weight_multipliers'] as $code => $mult) $bits[] = 'favour ' . $code . ' (' . $mult . '×)';
    }
    if (isset($act['duration_adjustment'])) {
        $delta = (int)$act['duration_adjustment'];
        $bits[] = 'adjust duration by ' . ($delta >= 0 ? '+' : '') . $delta . ' min';
    }
    if (!empty($act['force_archetype']))      $bits[] = 'strongly prefer ' . $act['force_archetype'];
    if (isset($act['max_quality_per_week']))  $bits[] = 'cap quality at ' . (int)$act['max_quality_per_week'] . '/week';
    if (!$bits) $bits[] = 'apply coach judgement (no automatic engine action)';

    return $h($scope . ': ' . implode(', ', $bits) . '.');
};

// Split: own vs relied-on shared (from another coach).
$ownDecisions    = [];
$sharedReliedOn  = [];
$selfId          = (int)Auth::userId();
foreach ($decisions as $d) {
    if ((int)$d['created_by'] === $selfId) $ownDecisions[] = $d;
    else $sharedReliedOn[] = $d;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Coaching Philosophy — <?= $h($coachName) ?></title>
<style>
  :root { --ink:#1a1a1a; --muted:#666; --rule:#ddd; --teal:#1D9E75; }
  * { box-sizing: border-box; }
  body { font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: var(--ink);
         max-width: 760px; margin: 0 auto; padding: 32px 24px 64px; line-height: 1.5; }
  h1 { font-size: 26px; margin: 0 0 2px; }
  .sub { color: var(--muted); font-size: 13px; margin-bottom: 20px; }
  h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted);
       border-bottom: 1px solid var(--rule); padding-bottom: 6px; margin: 28px 0 12px; }
  .rule { padding: 12px 0; border-bottom: 1px solid var(--rule); }
  .rule-title { font-size: 16px; font-weight: 600; margin: 0 0 4px; }
  .rule-logic { font-size: 13px; color: #333; margin: 0 0 6px; }
  .rule-why { font-size: 13px; }
  .rule-why .lbl { color: var(--muted); }
  .pill { display: inline-block; font-size: 10px; font-weight: 600; padding: 1px 7px; border-radius: 10px;
          background: #eef7f2; color: var(--teal); vertical-align: middle; margin-left: 6px; }
  .by { font-size: 11px; color: var(--muted); margin-top: 4px; }
  .empty { color: var(--muted); font-size: 14px; }
  .toolbar { margin-bottom: 18px; }
  .btn { display: inline-block; background: var(--teal); color: #fff; text-decoration: none; font-size: 13px;
         font-weight: 600; padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer; }
  .back { font-size: 13px; color: var(--muted); text-decoration: none; margin-left: 12px; }
  @media print {
    .toolbar { display: none; }
    body { padding: 0; max-width: none; }
    .rule { break-inside: avoid; }
    a[href]:after { content: ""; }
  }
</style>
</head>
<body>
  <div class="toolbar">
    <button class="btn" onclick="window.print()">Print / Save as PDF</button>
    <a class="back" href="/app/coach/intelligence">← Back to Intelligence</a>
  </div>

  <h1>Coaching Philosophy</h1>
  <div class="sub"><?= $h($coachName) ?> · <?= $h(date('F j, Y')) ?></div>

  <h2>My coaching rules</h2>
  <?php if (empty($ownDecisions)): ?>
  <p class="empty">No active coaching rules yet. Rules you approve in the Intelligence page appear here.</p>
  <?php else: foreach ($ownDecisions as $d): ?>
  <div class="rule">
    <div class="rule-title"><?= $h($d['title']) ?><?php if (!empty($d['shared'])): ?><span class="pill">Shared</span><?php endif; ?></div>
    <div class="rule-logic"><?= $prose($d) ?></div>
    <?php $why = trim((string)($d['rationale'] ?? '')) ?: trim((string)($d['reason'] ?? '')); if ($why !== ''): ?>
    <div class="rule-why"><span class="lbl">Why:</span> <?= $h($why) ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>

  <?php if (!empty($sharedReliedOn)): ?>
  <h2>Shared rules I rely on</h2>
  <?php foreach ($sharedReliedOn as $d): ?>
  <div class="rule">
    <div class="rule-title"><?= $h($d['title']) ?><span class="pill">Shared</span></div>
    <div class="rule-logic"><?= $prose($d) ?></div>
    <?php $why = trim((string)($d['rationale'] ?? '')) ?: trim((string)($d['reason'] ?? '')); if ($why !== ''): ?>
    <div class="rule-why"><span class="lbl">Why:</span> <?= $h($why) ?></div>
    <?php endif; ?>
    <div class="by">Shared by <?= $h($d['author_name'] ?? 'another coach') ?></div>
  </div>
  <?php endforeach; endif; ?>
</body>
</html>

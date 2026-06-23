<?php
/**
 * Coach training log (read-only). The athlete's ACTUAL completed_workouts, newest first,
 * grouped by Mon–Sun week with per-week rollups; matched (planned-vs-actual + thread link)
 * and unplanned ("off-plan") sessions in one stream. No edit affordances, no writes.
 *
 * Vars: $athlete, $log (from CoachController::athleteLogData), $units ('miles'|'km').
 */
$athleteId = (int)$athlete['id'];
$toKm = 1.60934;

$fmtDist = function ($miles) use ($units, $toKm): string {
    if ($miles === null || (float)$miles <= 0) return '';
    $v = $units === 'km' ? (float)$miles * $toKm : (float)$miles;
    return number_format($v, 1) . ' ' . ($units === 'km' ? 'km' : 'mi');
};
$fmtPace = function ($pace) use ($units, $toKm): string {
    if ($pace === null || (float)$pace <= 0) return '';
    $p = $units === 'km' ? (float)$pace / $toKm : (float)$pace; // stored min/mile
    $m = (int)$p; $s = (int)round(($p - $m) * 60); if ($s === 60) { $m++; $s = 0; }
    return sprintf('%d:%02d %s', $m, $s, $units === 'km' ? '/km' : '/mi');
};
$effortLabel = ['easy' => 'Easy', 'moderate' => 'Moderate', 'hard' => 'Hard', 'very_hard' => 'Very hard', 'discomfort' => 'Discomfort'];
$rowDate = static fn(string $d): string => date('D M j', strtotime($d));
?>
<div class="page-content av-log" style="max-width:780px;">

    <!-- Shared chrome: back + header + sub-nav tab strip -->
    <?php include __DIR__ . '/partials/athlete_chrome.php'; ?>
    <p class="body-text" style="margin:-6px 0 14px;color:var(--text-muted);font-size:13px;">
        What this athlete actually did, newest first. Read-only.
    </p>

    <!-- Coach re-sync: pull recent Intervals.icu activities without a reconnect (idempotent) -->
    <div class="card" data-intervals-backfill style="margin-bottom:18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 14px;">
        <div style="flex:1;min-width:180px;">
            <div style="font-size:13px;font-weight:500;">Re-sync activities from Intervals.icu</div>
            <div style="font-size:12px;color:var(--text-muted);">Pull this athlete's recent runs (idempotent — won't duplicate existing).</div>
        </div>
        <select data-backfill-days aria-label="Re-sync window">
            <option value="30">Last 30 days</option>
            <option value="60">Last 60 days</option>
            <option value="90">Last 90 days</option>
        </select>
        <button type="button" class="btn btn-secondary btn-sm" data-backfill-btn data-athlete="<?= $athleteId ?>">Re-sync activities</button>
        <div data-backfill-result style="display:none;width:100%;font-size:12px;margin-top:2px;"></div>
    </div>

    <?php if (empty($log['weeks'])): ?>
    <div class="card" style="margin-bottom:16px;">
        <div class="empty-state" style="padding:20px 0;">
            <div class="empty-state-title">No logged training in this window</div>
            <p class="body-text">Completed runs appear here as they sync (watch) or are logged (manual).</p>
        </div>
    </div>
    <?php else: ?>

    <?php foreach ($log['weeks'] as $wk): ?>
    <!-- Week rollup -->
    <div class="section-label" style="margin-top:18px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline;">
        <span><?= h($wk['label']) ?></span>
        <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-muted);font-size:12px;">
            <?= (int)$wk['runs'] ?> run<?= (int)$wk['runs'] === 1 ? '' : 's' ?>
            · <?= h(format_duration((int)$wk['total_minutes'])) ?>
            <?php if ((float)$wk['total_distance'] > 0): ?> · <?= h($fmtDist($wk['total_distance'])) ?><?php endif; ?>
            · compliance <?= $wk['avg_compliance'] !== null ? round((float)$wk['avg_compliance'] * 100) . '%' : '—' ?>
        </span>
    </div>

    <?php foreach ($wk['rows'] as $r):
        $wt        = (string)($r['workout_type'] ?? '') ?: (string)($r['planned_type'] ?? 'easy');
        $matched   = !empty($r['matched']);
        $isManual  = ($r['source'] ?? 'manual') === 'manual';
        $noteCount = (int)($r['note_count'] ?? 0);
        $effort    = $r['effort_descriptor'] !== null ? ($effortLabel[$r['effort_descriptor']] ?? ucfirst((string)$r['effort_descriptor'])) : null;
    ?>
    <div class="roster-row" style="margin-bottom:8px;<?= $matched ? '' : 'border-left:3px solid var(--text-muted);' ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                    <span style="font-size:12px;color:var(--text-muted);min-width:88px;"><?= h($rowDate((string)$r['activity_date'])) ?></span>
                    <span class="pill <?= pill_class($wt) ?>" style="font-size:10px;"><?= h(pill_label($wt)) ?></span>
                    <?php if (!$matched): ?>
                    <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--text-secondary);">Off-plan</span>
                    <?php endif; ?>
                    <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:var(--text-muted);"><?= $isManual ? 'Manual' : 'Watch' ?></span>
                    <?php if ($noteCount > 0): ?>
                    <span class="pill" style="font-size:10px;background:var(--recessed-bg);color:#1D9E75;" title="<?= (int)$noteCount ?> note<?= $noteCount === 1 ? '' : 's' ?> on this session">💬 <?= (int)$noteCount ?></span>
                    <?php endif; ?>
                </div>

                <div style="font-size:13px;color:var(--text-primary);">
                    <?php
                    $bits = [];
                    if ((int)($r['actual_duration'] ?? 0) > 0) $bits[] = format_duration((int)$r['actual_duration']);
                    if ($fmtDist($r['actual_distance'] ?? null) !== '') $bits[] = $fmtDist($r['actual_distance']);
                    if ($fmtPace($r['avg_pace'] ?? null) !== '')        $bits[] = $fmtPace($r['avg_pace']);
                    if (($r['avg_hr'] ?? null) !== null && (int)$r['avg_hr'] > 0) $bits[] = (int)$r['avg_hr'] . ' bpm';
                    if ($effort !== null) $bits[] = $effort;
                    echo h(implode(' · ', $bits) ?: '—');
                    ?>
                </div>

                <?php if ($matched): ?>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                    Planned: <?= h($r['planned_title'] ?: pill_label((string)($r['planned_type'] ?? $wt))) ?>
                    <?php if ((int)($r['planned_duration'] ?? 0) > 0): ?> · <?= h(format_duration((int)$r['planned_duration'])) ?><?php endif; ?>
                    <?php if ($r['compliance_score'] !== null): ?> · compliance <?= round((float)$r['compliance_score'] * 100) ?>%<?php endif; ?>
                </div>
                <?php else: ?>
                <details style="margin-top:6px;">
                    <summary style="font-size:12px;color:var(--text-secondary);cursor:pointer;">Off-plan session detail</summary>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:6px;line-height:1.7;">
                        <?php
                        $det = [];
                        if (($r['max_hr'] ?? null) !== null && (int)$r['max_hr'] > 0) $det[] = 'Max HR ' . (int)$r['max_hr'] . ' bpm';
                        if (($r['elevation_gain'] ?? null) !== null && (float)$r['elevation_gain'] > 0) $det[] = 'Elevation ' . round((float)$r['elevation_gain']) . ' ft';
                        if (($r['power_avg'] ?? null) !== null && (int)$r['power_avg'] > 0) $det[] = 'Avg power ' . (int)$r['power_avg'] . ' W';
                        if (($r['rpe'] ?? null) !== null) $det[] = 'RPE ' . (int)$r['rpe'];
                        if (!empty($r['completion_status'])) $det[] = 'Status: ' . h((string)$r['completion_status']);
                        if (!empty($r['external_activity_id'])) $det[] = 'Source id: ' . h((string)$r['external_activity_id']);
                        echo $det ? implode('<br>', $det) : 'No additional detail recorded.';
                        ?>
                        <div style="margin-top:6px;color:var(--text-muted);">Commenting on off-plan sessions isn't available yet.</div>
                    </div>
                </details>
                <?php endif; ?>
            </div>

            <?php if ($matched): ?>
            <a href="/app/coach/workout/<?= (int)$r['planned_workout_id'] ?>/thread" class="btn btn-secondary btn-sm" style="flex-shrink:0;">
                <?= $noteCount > 0 ? 'View thread' : 'Comment' ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Pagination (windowed, back through history) -->
    <?php if (!empty($log['has_newer']) || !empty($log['has_older'])): ?>
    <div style="display:flex;justify-content:space-between;gap:8px;margin-top:18px;">
        <?php if (!empty($log['has_newer'])): ?>
        <a href="/app/coach/athlete/<?= $athleteId ?>/log?page=<?= (int)$log['page'] - 1 ?>" class="btn btn-secondary btn-sm">← Newer weeks</a>
        <?php else: ?><span></span><?php endif; ?>
        <?php if (!empty($log['has_older'])): ?>
        <a href="/app/coach/athlete/<?= $athleteId ?>/log?page=<?= (int)$log['page'] + 1 ?>" class="btn btn-secondary btn-sm">Older weeks →</a>
        <?php else: ?><span></span><?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<script>
(function () {
    var wrap = document.querySelector('[data-intervals-backfill]');
    if (!wrap) return;
    var btn  = wrap.querySelector('[data-backfill-btn]');
    var sel  = wrap.querySelector('[data-backfill-days]');
    var out  = wrap.querySelector('[data-backfill-result]');
    var meta = document.querySelector('meta[name="csrf-token"]');
    var csrf = meta ? meta.content : '';
    var LABEL = 'Re-sync activities';

    btn.addEventListener('click', function () {
        if (btn.disabled) return;
        btn.disabled = true;
        btn.textContent = 'Syncing…';
        out.style.display = 'none';

        var body = 'athlete_id=' + encodeURIComponent(btn.getAttribute('data-athlete'))
                 + '&days='      + encodeURIComponent(sel.value);

        fetch('/app/integrations/intervals/backfill', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
            body:    body
        }).then(function (r) { return r.json(); })
          .then(function (res) {
              btn.disabled = false;
              btn.textContent = LABEL;
              out.style.display = 'block';
              out.textContent = (res && res.message) ? res.message
                              : (res && res.error ? res.error : 'Re-sync failed. Please try again.');
              out.style.color = (res && res.success) ? '#1D9E75' : 'var(--color-danger)';
              // Reload to surface freshly imported runs in the log below.
              if (res && res.success && res.imported_new > 0) {
                  setTimeout(function () { location.reload(); }, 1200);
              }
          })
          .catch(function () {
              btn.disabled = false;
              btn.textContent = LABEL;
              out.style.display = 'block';
              out.style.color = 'var(--color-danger)';
              out.textContent = 'Network error. Please try again.';
          });
    });
})();
</script>

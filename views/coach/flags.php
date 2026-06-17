<?php
// $flags
$bySeverity = ['critical' => [], 'warning' => [], 'info' => []];
foreach ($flags as $f) {
    $bySeverity[$f['severity'] ?? 'info'][] = $f;
}
?>
<div class="page-content">

    <div class="page-heading" style="margin-bottom:4px;">Alerts</div>
    <p class="body-text" style="margin-bottom:24px;">
        Engine-generated flags that need your attention.
    </p>

    <?php if (empty($flags)): ?>
    <div class="card" style="border-left:3px solid var(--color-success);">
        <div class="empty-state" style="padding:24px 0;">
            <div class="empty-state-title">No open alerts</div>
            <p class="body-text">All athletes are on track. Check back after the next plan engine run.</p>
        </div>
    </div>
    <?php else: ?>

    <?php foreach ([
        'critical' => ['CRITICAL', '#FDECEA', '#991B1B', 'severity-critical'],
        'warning'  => ['WARNINGS', '#FEF9C3', '#92400E', 'severity-warning'],
        'info'     => ['INFO',     'var(--recessed-bg)', 'var(--text-secondary)', ''],
    ] as $sev => [$label, $pillBg, $pillColor, $rowClass]):
        if (empty($bySeverity[$sev])) continue;
    ?>
    <div class="section-label" style="color:<?= $pillColor ?>;margin-top:20px;"><?= $label ?></div>
    <?php foreach ($bySeverity[$sev] as $flag): ?>
    <div class="roster-row <?= $rowClass ?>" style="margin-bottom:8px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <div>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                    <span style="font-size:14px;font-weight:600;"><?= h($flag['athlete_name']) ?></span>
                    <span class="pill" style="font-size:10px;background:<?= $pillBg ?>;color:<?= $pillColor ?>;">
                        <?= h($flag['flag_type']) ?>
                    </span>
                </div>
                <?php if ($flag['message']): ?>
                <p class="body-text" style="margin:0 0 8px;"><?= h($flag['message']) ?></p>
                <?php endif; ?>
                <?php if (($flag['flag_type'] ?? '') === 'profile_updated'): ?>
                <?= render_profile_diff($flag['details'] ?? null) ?>
                <?php endif; ?>
                <?php if (($flag['flag_type'] ?? '') === 'pace_recalibration' && !empty($flag['recal'])):
                    $recal   = $flag['recal'];
                    $cur     = json_decode((string)($recal['current_pace_zones'] ?? ''), true) ?: [];
                    $prop    = json_decode((string)($recal['proposed_pace_zones'] ?? ''), true) ?: [];
                    $fmt     = fn($s) => (is_numeric($s) ? PaceZones::formatPace((int)$s) : '—');
                    $fmtEasy = function ($z) {
                        if (!empty($z['easy']['min']) && !empty($z['easy']['max'])) {
                            return PaceZones::formatRange((int)$z['easy']['min'], (int)$z['easy']['max']);
                        }
                        return '—';
                    };
                ?>
                <div class="srf-recal" data-recal>
                    <div style="font-weight:600;margin-bottom:6px;">⚡ Pace Zone Update Available</div>
                    <div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:4px 14px;font-size:13px;align-items:center;">
                        <div></div>
                        <div style="font-weight:600;color:var(--text-muted);">Current</div>
                        <div style="font-weight:600;">Proposed</div>
                        <div>Easy</div><div><?= h($fmtEasy($cur)) ?></div><div><?= h($fmtEasy($prop)) ?></div>
                        <div>Tempo</div><div><?= h($fmt($cur['half_marathon'] ?? null)) ?></div><div><?= h($fmt($prop['half_marathon'] ?? null)) ?></div>
                        <div>10K</div><div><?= h($fmt($cur['10K'] ?? null)) ?></div><div><?= h($fmt($prop['10K'] ?? null)) ?></div>
                        <div>5K</div><div><?= h($fmt($cur['5K'] ?? null)) ?></div><div><?= h($fmt($prop['5K'] ?? null)) ?></div>
                    </div>
                    <?php if (empty($prop)): ?>
                    <p class="body-text" style="font-size:12px;color:var(--text-muted);margin:8px 0 0;">
                        No automatic proposal for this distance — enter zones manually on the profile if needed.
                    </p>
                    <?php endif; ?>
                    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
                        <?php if (!empty($prop)): ?>
                        <form method="POST" action="/app/coach/races/<?= (int)$recal['id'] ?>/recalibrate/approve" style="display:inline;">
                            <?= Auth::csrfField() ?>
                            <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                        </form>
                        <button type="button" class="btn btn-secondary btn-sm" data-recal-modify>Modify</button>
                        <?php endif; ?>
                        <form method="POST" action="/app/coach/races/<?= (int)$recal['id'] ?>/recalibrate/dismiss" style="display:inline;">
                            <?= Auth::csrfField() ?>
                            <button type="submit" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
                        </form>
                    </div>

                    <?php if (!empty($prop)): ?>
                    <!-- Modify: edit proposed zones (M:SS) then approve with changes. -->
                    <form method="POST" action="/app/coach/races/<?= (int)$recal['id'] ?>/recalibrate/approve"
                          data-recal-modify-form style="display:none;margin-top:10px;">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="zones_json" data-recal-json>
                        <div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:6px 10px;font-size:13px;align-items:center;">
                            <div>Easy</div>
                            <input type="text" class="form-input" data-z="easy_min" value="<?= h(!empty($prop['easy']['min']) ? PaceZones::formatPace((int)$prop['easy']['min']) : '') ?>" placeholder="min">
                            <input type="text" class="form-input" data-z="easy_max" value="<?= h(!empty($prop['easy']['max']) ? PaceZones::formatPace((int)$prop['easy']['max']) : '') ?>" placeholder="max">
                            <div>Tempo</div>
                            <input type="text" class="form-input" data-z="half_marathon" value="<?= h(isset($prop['half_marathon']) ? PaceZones::formatPace((int)$prop['half_marathon']) : '') ?>">
                            <div></div>
                            <div>10K</div>
                            <input type="text" class="form-input" data-z="10K" value="<?= h(isset($prop['10K']) ? PaceZones::formatPace((int)$prop['10K']) : '') ?>">
                            <div></div>
                            <div>5K</div>
                            <input type="text" class="form-input" data-z="5K" value="<?= h(isset($prop['5K']) ? PaceZones::formatPace((int)$prop['5K']) : '') ?>">
                            <div></div>
                        </div>
                        <input type="hidden" data-recal-base="<?= h((string)($recal['proposed_pace_zones'] ?? '{}')) ?>">
                        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px;">Save &amp; approve</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">
                    Raised <?= h(Timezone::format($flag['created_at'], 'M j, Y')) ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <a href="/app/coach/athlete/<?= (int)$flag['athlete_id'] ?>" class="btn btn-secondary btn-sm">View</a>
                <form method="POST" action="/app/coach/flags/<?= (int)$flag['id'] ?>/dismiss">
                    <?= Auth::csrfField() ?>
                    <button type="submit" class="btn btn-sm"
                            style="background:var(--recessed-bg);color:var(--text-muted);">Dismiss</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
    <?php endif; ?>

    <style>
    .srf-recal { background:var(--recessed-bg,rgba(0,0,0,0.04)); border-radius:10px; padding:12px; margin:8px 0; }
    .srf-recal .form-input { padding:4px 8px; font-size:13px; }
    </style>
    <script>
    (function () {
        function toSeconds(v){
            v = (v||'').trim(); if(!v) return null;
            if(!/^\d{1,2}(:\d{1,2}){0,2}$/.test(v)) return null;
            var p = v.split(':').map(function(n){ return parseInt(n,10); });
            if(p.length===3) return p[0]*3600+p[1]*60+p[2];
            if(p.length===2) return p[0]*60+p[1];
            return p[0];
        }
        document.querySelectorAll('[data-recal]').forEach(function(box){
            var modBtn  = box.querySelector('[data-recal-modify]');
            var modForm = box.querySelector('[data-recal-modify-form]');
            if(modBtn && modForm){
                modBtn.addEventListener('click', function(){
                    modForm.style.display = modForm.style.display==='none' ? '' : 'none';
                });
                modForm.addEventListener('submit', function(){
                    var base = {};
                    try { base = JSON.parse(modForm.querySelector('[data-recal-base]').value || '{}') || {}; } catch(e){}
                    var easyMin = toSeconds(modForm.querySelector('[data-z="easy_min"]').value);
                    var easyMax = toSeconds(modForm.querySelector('[data-z="easy_max"]').value);
                    if(easyMin && easyMax){ base.easy = {min:easyMin, max:easyMax}; base.long = {min:easyMin, max:easyMax}; }
                    ['half_marathon','10K','5K'].forEach(function(k){
                        var s = toSeconds(modForm.querySelector('[data-z="'+k+'"]').value);
                        if(s) base[k] = s;
                    });
                    base.source = 'race_result';
                    modForm.querySelector('[data-recal-json]').value = JSON.stringify(base);
                });
            }
        });
    })();
    </script>

</div>

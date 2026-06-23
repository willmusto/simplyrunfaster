<?php
// Read-only archetype browser. Vars from CoachController::library():
//   $archetypes (filtered card array), $totalCount, $filterType, $filterPhase,
//   $filterDistance, $filterSearch.
// Archetypes are managed via the seeder — there is no create/edit/delete here.

$libPhaseLabel = static fn(string $p): string => ucfirst($p);
$libDistLabel  = static function (string $d): string {
    return match ($d) {
        'half'     => 'Half',
        'marathon' => 'Marathon',
        default    => $d, // 5K / 10K
    };
};

// Filter option sets.
$typeOptions  = ['easy' => 'Easy', 'long' => 'Long', 'quality' => 'Quality', 'recovery' => 'Recovery'];
$phaseOptions = ['base' => 'Base', 'build' => 'Build', 'peak' => 'Peak', 'taper' => 'Taper'];
$distOptions  = ['5K' => '5K', '10K' => '10K', 'half' => 'Half', 'marathon' => 'Marathon', 'ultra' => 'Ultra', 'mile' => 'Mile'];
$hasFilter    = $filterType !== '' || $filterPhase !== '' || $filterDistance !== '' || $filterSearch !== '';
?>
<style>
    .lib-head-sub { color: var(--text-muted); font-size: 13px; margin: 2px 0 18px; }
    .form-label-sm {
        font-size: 11px; font-weight: 500; color: var(--text-muted);
        margin-bottom: 4px; text-transform: uppercase; letter-spacing: .05em;
    }

    .lib-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 12px;
    }
    .lib-card {
        position: relative;
        text-align: left;
        width: 100%;
        border: var(--card-border);
        border-radius: var(--radius-card);
        background: var(--card-bg);
        padding: 14px 16px;
        cursor: pointer;
        font: inherit;
        transition: border-color .12s, box-shadow .12s;
    }
    .lib-card:hover { border-color: var(--accent-mid); box-shadow: 0 2px 10px rgba(0,0,0,.06); }
    .lib-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
    .lib-card-name { font-size: 14px; font-weight: 600; line-height: 1.3; }
    .lib-card-code { font-size: 11px; color: var(--text-muted); margin-top: 2px; font-family: ui-monospace, monospace; }
    .lib-tags { display: flex; flex-wrap: wrap; gap: 4px; margin: 8px 0; }
    .lib-tag {
        font-size: 10px; padding: 2px 7px; border-radius: 999px;
        background: var(--recessed-bg); color: var(--text-secondary);
    }
    .lib-tag-dist { background: var(--accent-fill); color: var(--accent-strong); }
    .lib-meta { display: flex; flex-wrap: wrap; gap: 10px; font-size: 11px; color: var(--text-muted); margin-bottom: 6px; }
    .lib-desc { font-size: 12px; color: var(--text-secondary); line-height: 1.5; margin: 0; }
    .lib-desc.clamp { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .lib-more {
        background: none; border: none; padding: 0; margin-top: 4px; cursor: pointer;
        color: var(--accent-mid); font-size: 11px; font-weight: 500;
    }
    .lib-card-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; }
    .lib-variant-count { font-size: 11px; color: var(--text-muted); }
    .lib-sys { font-size: 10px; color: var(--text-muted); }

    .lib-empty { border-left: 3px solid var(--border-strong); }

    /* ── Detail drawer (right on desktop, bottom sheet on mobile) ── */
    #libDetailBd { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 9998; }
    #libDetailBd.is-open { display: block; }
    #libDetail {
        position: fixed; top: 0; right: 0; bottom: 0; z-index: 9999;
        width: min(440px, 100vw); background: var(--card-bg);
        border-left: var(--card-border); box-shadow: -8px 0 32px rgba(0,0,0,.18);
        transform: translateX(100%); transition: transform .2s ease;
        display: flex; flex-direction: column;
    }
    #libDetail.is-open { transform: translateX(0); }
    .lib-detail-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; padding: 18px 20px 12px; border-bottom: 1px solid var(--divider); }
    .lib-detail-body { overflow-y: auto; padding: 16px 20px 28px; }
    #libDetailClose { background: none; border: none; cursor: pointer; font-size: 22px; line-height: 1; color: var(--text-muted); padding: 0 4px; }
    #libDetailClose:hover { color: var(--text-primary); }
    .lib-section { margin-top: 18px; }
    .lib-section-label {
        font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em;
        color: var(--text-muted); margin-bottom: 8px;
    }
    .lib-variant { padding: 8px 0; border-bottom: 1px solid var(--divider); }
    .lib-variant:last-child { border-bottom: none; }
    .lib-variant-name { font-size: 13px; font-weight: 600; }
    .lib-variant-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
    .lib-kv { display: flex; justify-content: space-between; gap: 12px; font-size: 12px; padding: 4px 0; border-bottom: 1px solid var(--divider); }
    .lib-kv > span:first-child { color: var(--text-muted); }
    .lib-kv > span:last-child { font-weight: 500; text-align: right; }
    .lib-param { font-size: 12px; padding: 4px 0; }
    .lib-param-key { font-weight: 600; }
    .lib-detail-tmpl { font-size: 12px; color: var(--text-secondary); line-height: 1.6; white-space: pre-line; }

    @media (max-width: 767px) {
        #libDetail {
            top: auto; left: 0; right: 0; bottom: 0; width: 100%;
            max-height: 88vh; border-left: none; border-top: var(--card-border);
            border-radius: var(--radius-card) var(--radius-card) 0 0;
            transform: translateY(100%);
        }
        #libDetail.is-open { transform: translateY(0); }
    }

    /* ── Preview modal ── */
    #libPrev { display: none; position: fixed; inset: 0; z-index: 10000; align-items: center; justify-content: center; }
    #libPrev.is-open { display: flex; }
    #libPrev-bd { position: absolute; inset: 0; background: rgba(0,0,0,.5); }
    #libPrev-sheet {
        position: relative; z-index: 1; width: min(520px, calc(100vw - 32px)); max-height: 90vh; overflow-y: auto;
        background: var(--card-bg); border: var(--card-border); border-radius: var(--radius-card);
        padding: 20px 20px 24px; box-shadow: 0 20px 60px rgba(0,0,0,.25);
    }
    #libPrev-close { position: absolute; top: 12px; right: 14px; background: none; border: none; cursor: pointer; font-size: 22px; line-height: 1; color: var(--text-muted); padding: 2px 4px; }
    .lib-prev-inputs { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 12px 0 16px; }
    .lib-prev-out { display: none; margin-top: 8px; padding: 14px; border-radius: var(--radius-sm); background: var(--recessed-bg); }
    .lib-prev-out.is-open { display: block; }
    .lib-prev-title { font-size: 15px; font-weight: 600; margin-bottom: 4px; }
    .lib-prev-summary { font-size: 12px; color: var(--text-muted); margin-bottom: 10px; }
    .lib-prev-instr { font-size: 13px; color: var(--text-secondary); line-height: 1.6; white-space: pre-line; }
    .lib-prev-err { display: none; font-size: 12px; color: var(--color-danger); margin-top: 8px; }
    .lib-prev-struct { margin-top: 12px; }
    .lib-prev-struct pre { font-size: 11px; background: var(--card-bg); border: 1px solid var(--divider); border-radius: var(--radius-sm); padding: 10px; overflow-x: auto; max-height: 240px; }
</style>

<div class="page-content">

    <div class="page-heading">Workout Library</div>
    <p class="lib-head-sub">
        <?= (int)$totalCount ?> workout archetype<?= $totalCount !== 1 ? 's' : '' ?> available<?= $hasFilter ? ' · ' . count($archetypes) . ' matching filters' : '' ?>
    </p>

    <!-- Filter bar -->
    <form method="GET" action="/app/coach/library" id="libFilter" style="margin-bottom:20px;">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div>
                <div class="form-label-sm">Search</div>
                <input type="text" name="q" value="<?= h($filterSearch) ?>" class="form-input" style="width:200px;" placeholder="Name or description…">
            </div>
            <div>
                <div class="form-label-sm">Type</div>
                <select name="type" class="form-select" style="min-width:130px;" onchange="this.form.submit()">
                    <option value="">All types</option>
                    <?php foreach ($typeOptions as $val => $lbl): ?>
                    <option value="<?= h($val) ?>" <?= $filterType === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div class="form-label-sm">Phase</div>
                <select name="phase" class="form-select" style="min-width:120px;" onchange="this.form.submit()">
                    <option value="">All phases</option>
                    <?php foreach ($phaseOptions as $val => $lbl): ?>
                    <option value="<?= h($val) ?>" <?= $filterPhase === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div class="form-label-sm">Distance</div>
                <select name="distance" class="form-select" style="min-width:120px;" onchange="this.form.submit()">
                    <option value="">All distances</option>
                    <?php foreach ($distOptions as $val => $lbl): ?>
                    <option value="<?= h($val) ?>" <?= $filterDistance === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <?php if ($hasFilter): ?>
            <a href="/app/coach/library" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (empty($archetypes)): ?>
    <div class="card lib-empty">
        <div class="empty-state" style="padding:24px 0;">
            <div class="empty-state-title">No archetypes match</div>
            <p class="body-text">Try adjusting or clearing your filters.</p>
        </div>
    </div>
    <?php else: ?>

    <div class="lib-grid">
    <?php foreach ($archetypes as $a): ?>
        <button type="button" class="lib-card" data-arch-id="<?= (int)$a['id'] ?>">
            <div class="lib-card-head">
                <div>
                    <div class="lib-card-name"><?= h($a['name']) ?></div>
                    <div class="lib-card-code"><?= h($a['code']) ?></div>
                </div>
                <span class="pill <?= h(pill_class($a['workout_type'])) ?>" style="font-size:10px;flex-shrink:0;">
                    <?= h(pill_label($a['workout_type'])) ?>
                </span>
            </div>

            <div class="lib-tags">
                <?php foreach ($a['phases'] as $p): ?>
                <span class="lib-tag"><?= h($libPhaseLabel($p)) ?></span>
                <?php endforeach; ?>
                <?php foreach ($a['goal_distances'] as $d): ?>
                <span class="lib-tag lib-tag-dist"><?= h($libDistLabel($d)) ?></span>
                <?php endforeach; ?>
            </div>

            <div class="lib-meta">
                <?php if ($a['intensity_factor'] !== null): ?>
                <span>Intensity: <?= number_format($a['intensity_factor'], 2) ?></span>
                <?php endif; ?>
                <span><?= h($a['prescription_label']) ?></span>
            </div>

            <?php if ($a['description'] !== ''): ?>
            <p class="lib-desc clamp" data-desc><?= h($a['description']) ?></p>
            <button type="button" class="lib-more" data-more hidden>Show more</button>
            <?php endif; ?>

            <div class="lib-card-foot">
                <?php if ($a['variant_count'] > 1): ?>
                <span class="lib-variant-count"><?= (int)$a['variant_count'] ?> variants</span>
                <?php else: ?>
                <span class="lib-variant-count"></span>
                <?php endif; ?>
                <span class="lib-sys">System archetype</span>
            </div>
        </button>
    <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<!-- Detail drawer -->
<div id="libDetailBd"></div>
<aside id="libDetail" role="dialog" aria-modal="false" aria-label="Archetype detail">
    <div class="lib-detail-head">
        <div>
            <div id="libDetailName" style="font-size:16px;font-weight:600;line-height:1.3;"></div>
            <div id="libDetailCode" style="font-size:11px;color:var(--text-muted);font-family:ui-monospace,monospace;margin-top:2px;"></div>
        </div>
        <button id="libDetailClose" aria-label="Close">×</button>
    </div>
    <div class="lib-detail-body" id="libDetailBody"><!-- populated by JS --></div>
</aside>

<!-- Preview modal -->
<div id="libPrev" role="dialog" aria-modal="true" aria-label="Preview workout">
    <div id="libPrev-bd"></div>
    <div id="libPrev-sheet">
        <button id="libPrev-close" aria-label="Close">×</button>
        <div style="font-size:15px;font-weight:600;padding-right:28px;">Preview workout</div>
        <div id="libPrev-arch" style="font-size:12px;color:var(--text-muted);margin-top:2px;"></div>

        <div class="lib-prev-inputs">
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="libPrev-class">Classification</label>
                <select id="libPrev-class" class="form-select">
                    <option value="workable">Workable</option>
                    <option value="well_trained">Well-trained</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="libPrev-dur">Duration (minutes)</label>
                <input type="number" id="libPrev-dur" class="form-input" min="5" max="300" value="45">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="libPrev-dist">Goal distance</label>
                <select id="libPrev-dist" class="form-select">
                    <option value="5K">5K</option>
                    <option value="10K">10K</option>
                    <option value="half">Half</option>
                    <option value="marathon" selected>Marathon</option>
                    <option value="mile">Mile</option>
                    <option value="ultra">Ultra</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="libPrev-variant">Variant</label>
                <select id="libPrev-variant" class="form-select"></select>
            </div>
        </div>

        <button type="button" id="libPrev-go" class="btn btn-primary btn-sm">Generate preview</button>
        <div class="lib-prev-err" id="libPrev-err"></div>

        <div class="lib-prev-out" id="libPrev-out">
            <div class="lib-prev-title" id="libPrev-title"></div>
            <div class="lib-prev-summary" id="libPrev-summary"></div>
            <div class="lib-prev-instr" id="libPrev-instr"></div>
            <div class="lib-section" style="margin-top:14px;">
                <div class="lib-section-label">Generated parameters</div>
                <div id="libPrev-params" style="font-size:12px;"></div>
            </div>
            <details class="lib-prev-struct">
                <summary style="cursor:pointer;font-size:12px;color:var(--text-muted);">Structure JSON</summary>
                <pre id="libPrev-struct"></pre>
            </details>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var ARCHETYPES = <?= json_encode(array_column($archetypes, null, 'id'), JSON_UNESCAPED_SLASHES) ?>;
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
    var DIST_LABEL = { '5K': '5K', '10K': '10K', 'half': 'Half', 'marathon': 'Marathon' };
    var PHASE_LABEL = { base: 'Base', build: 'Build', peak: 'Peak', taper: 'Taper' };
    var current = null;

    function humanize(k) { return (k.charAt(0).toUpperCase() + k.slice(1)).replace(/_/g, ' '); }

    // ── Card description Show more / less ──
    Array.prototype.forEach.call(document.querySelectorAll('.lib-card'), function (card) {
        var desc = card.querySelector('[data-desc]');
        var more = card.querySelector('[data-more]');
        if (desc && more && desc.scrollHeight > desc.clientHeight + 2) {
            more.hidden = false;
            more.addEventListener('click', function (e) {
                e.stopPropagation();
                var clamped = desc.classList.toggle('clamp');
                more.textContent = clamped ? 'Show more' : 'Show less';
            });
        }
        card.addEventListener('click', function () { openDetail(card.getAttribute('data-arch-id')); });
    });

    // ── Detail drawer ──
    function renderParams(params) {
        var keys = Object.keys(params || {});
        if (!keys.length) return '<div style="color:var(--text-muted);font-size:12px;">No tunable parameters.</div>';
        return keys.map(function (k) {
            var spec = params[k];
            if (spec === null || typeof spec !== 'object') {
                return '<div class="lib-param"><span class="lib-param-key">' + esc(humanize(k)) + ':</span> ' + esc(spec) + '</div>';
            }
            var parts = [];
            ['workable', 'well_trained'].forEach(function (cls) {
                var r = spec[cls];
                if (r && typeof r === 'object' && r.min != null && r.max != null) {
                    var lbl = cls === 'well_trained' ? 'well-trained' : 'workable';
                    parts.push((r.min === r.max ? r.min : r.min + '–' + r.max) + ' (' + lbl + ')');
                }
            });
            if (!parts.length) {
                if (spec.default != null) parts.push('default ' + spec.default);
                else if (Array.isArray(spec.allowed_values)) parts.push(spec.allowed_values.join(', '));
                else if (spec.min != null && spec.max != null) parts.push(spec.min + '–' + spec.max);
            }
            return '<div class="lib-param"><span class="lib-param-key">' + esc(humanize(k)) + ':</span> ' + esc(parts.join(' / ')) + '</div>';
        }).join('');
    }

    function openDetail(id) {
        var a = ARCHETYPES[id];
        if (!a) return;
        current = a;
        $('libDetailName').textContent = a.name;
        $('libDetailCode').textContent = a.code;

        var phaseTags = (a.phases || []).map(function (p) { return '<span class="lib-tag">' + esc(PHASE_LABEL[p] || p) + '</span>'; }).join('');
        var distTags = (a.goal_distances || []).map(function (d) { return '<span class="lib-tag lib-tag-dist">' + esc(DIST_LABEL[d] || d) + '</span>'; }).join('');
        var planTags = (a.plan_types || []).map(function (p) { return '<span class="lib-tag">' + esc(humanize(p)) + '</span>'; }).join('');

        var variantsHtml = (a.variants || []).map(function (v) {
            return '<div class="lib-variant"><div class="lib-variant-name">' + esc(v.name) +
                (v.intensity_factor != null ? ' <span style="font-weight:400;color:var(--text-muted);font-size:11px;">IF ' + esc(v.intensity_factor) + '</span>' : '') +
                '</div>' + (v.description ? '<div class="lib-variant-desc">' + esc(v.description) + '</div>' : '') + '</div>';
        }).join('');

        var html = '';
        html += '<div class="lib-tags">' + phaseTags + distTags + '</div>';

        html += '<div class="lib-section"><div class="lib-section-label">Generation</div>';
        html += '<div class="lib-kv"><span>Intensity factor</span><span>' + esc(a.intensity_factor != null ? Number(a.intensity_factor).toFixed(2) : '—') + '</span></div>';
        html += '<div class="lib-kv"><span>Prescription</span><span>' + esc(a.prescription_label) + '</span></div>';
        html += '<div class="lib-kv"><span>Recovery model</span><span>' + esc(a.recovery_model || '—') + '</span></div>';
        html += '<div class="lib-kv"><span>Min classification</span><span>' + esc(humanize(a.min_classification)) + '</span></div>';
        html += '</div>';

        if (planTags) html += '<div class="lib-section"><div class="lib-section-label">Plan types</div><div class="lib-tags">' + planTags + '</div></div>';

        if (variantsHtml) html += '<div class="lib-section"><div class="lib-section-label">Variants (' + (a.variants || []).length + ')</div>' + variantsHtml + '</div>';

        html += '<div class="lib-section"><div class="lib-section-label">Parameters</div>' + renderParams(a.parameters) + '</div>';

        if (a.description_template) html += '<div class="lib-section"><div class="lib-section-label">Description template</div><div class="lib-detail-tmpl">' + esc(a.description_template) + '</div></div>';

        html += '<div class="lib-section"><button type="button" id="libDetailPreview" class="btn btn-primary btn-sm">Preview workout</button></div>';

        $('libDetailBody').innerHTML = html;
        $('libDetailPreview').addEventListener('click', function () { openPreview(a); });

        $('libDetailBd').classList.add('is-open');
        $('libDetail').classList.add('is-open');
    }

    function closeDetail() {
        $('libDetailBd').classList.remove('is-open');
        $('libDetail').classList.remove('is-open');
    }
    $('libDetailClose').addEventListener('click', closeDetail);
    $('libDetailBd').addEventListener('click', closeDetail);

    // ── Preview modal ──
    function openPreview(a) {
        $('libPrev-arch').textContent = a.name + ' · ' + a.code;
        var sel = $('libPrev-variant');
        sel.innerHTML = '<option value="auto">Auto (first variant)</option>' +
            (a.variants || []).map(function (v) { return '<option value="' + esc(v.code) + '">' + esc(v.name) + '</option>'; }).join('');
        $('libPrev-go').setAttribute('data-arch-id', a.id);
        $('libPrev-out').classList.remove('is-open');
        $('libPrev-err').style.display = 'none';
        $('libPrev').classList.add('is-open');
    }
    function closePreview() { $('libPrev').classList.remove('is-open'); }
    $('libPrev-close').addEventListener('click', closePreview);
    $('libPrev-bd').addEventListener('click', closePreview);

    $('libPrev-go').addEventListener('click', function () {
        var id = this.getAttribute('data-arch-id');
        var err = $('libPrev-err');
        err.style.display = 'none';
        this.disabled = true;
        var btn = this;
        var qs = new URLSearchParams({
            archetype_id: id,
            classification: $('libPrev-class').value,
            duration: $('libPrev-dur').value || '45',
            goal_distance: $('libPrev-dist').value,
            variant: $('libPrev-variant').value
        });
        fetch('/app/coach/library/preview?' + qs.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                btn.disabled = false;
                if (!res.ok || !res.j || !res.j.preview) {
                    err.textContent = (res.j && res.j.error) || 'Could not generate preview.';
                    err.style.display = 'block';
                    return;
                }
                var p = res.j.preview;
                $('libPrev-title').textContent = p.display_title || '';
                $('libPrev-summary').textContent = p.display_summary || '';
                $('libPrev-instr').textContent = p.athlete_instructions || '';
                var params = p.generated_parameters || {};
                $('libPrev-params').innerHTML = Object.keys(params).length
                    ? Object.keys(params).map(function (k) {
                        return '<div class="lib-param"><span class="lib-param-key">' + esc(humanize(k)) + ':</span> ' + esc(params[k]) + '</div>';
                      }).join('')
                    : '<span style="color:var(--text-muted);">—</span>';
                $('libPrev-struct').textContent = p.structure ? JSON.stringify(p.structure, null, 2) : '(none)';
                $('libPrev-out').classList.add('is-open');
            })
            .catch(function () {
                btn.disabled = false;
                err.textContent = 'Network error generating preview.';
                err.style.display = 'block';
            });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if ($('libPrev').classList.contains('is-open')) closePreview();
            else if ($('libDetail').classList.contains('is-open')) closeDetail();
        }
    });
})();
</script>

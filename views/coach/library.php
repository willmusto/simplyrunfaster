<?php
// $templates, $filterType, $filterPhase, $filterDistance, $filterSearch, $success, $error

$typeColors = [
    'easy'       => ['#E6F4EA', '#1A7340'],
    'long'       => ['#E8F0FE', '#1A4DAD'],
    'tempo'      => ['#FFF3E0', '#B45309'],
    'interval'   => ['#FDECEA', '#991B1B'],
    'hill'       => ['#F3E8FF', '#6D28D9'],
    'fartlek'    => ['#FFF9C4', '#78610A'],
    'race_pace'  => ['#E1F5EE', '#0F6E56'],
    'recovery'   => ['#F0FDF4', '#15803D'],
    'rest'       => ['#F5F5F5', '#6B6B6B'],
    'cross_train'=> ['#E0F7FA', '#0E7490'],
];

$allTypes = ['easy','long','tempo','interval','hill','fartlek','race_pace','recovery','rest','cross_train'];
$allPhases = ['base','build','peak','taper'];
$allDistances = ['5K','10K','half','marathon'];

function type_label(string $type): string {
    return match($type) {
        'race_pace'  => 'Race pace',
        'cross_train' => 'Cross-train',
        default      => ucfirst($type),
    };
}

function parse_tags(?string $json): array {
    if (!$json) return [];
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}
?>
<div class="page-content">

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:4px;">
        <div class="page-heading">Workout Library</div>
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('addTemplateForm').style.display='block';this.style.display='none';">
            + Add template
        </button>
    </div>
    <p class="body-text" style="margin-bottom:20px;">
        <?= count($templates) ?> template<?= count($templates) !== 1 ? 's' : '' ?><?= $filterType || $filterPhase || $filterDistance || $filterSearch ? ' matching filters' : '' ?>
    </p>

    <?php if ($success): ?>
    <div class="flash flash-success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- Add template form (hidden by default) -->
    <div id="addTemplateForm" style="display:none;" class="card" style="margin-bottom:24px;">
        <div class="card-title" style="margin-bottom:16px;">New workout template</div>
        <form method="POST" action="/app/coach/library">
            <?= Auth::csrfField() ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label class="form-label" for="wl_name">Internal name <span style="color:var(--color-danger);">*</span></label>
                    <input type="text" id="wl_name" name="name" class="form-input" placeholder="e.g. E2: 45-min easy" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="wl_athlete_name">Athlete-facing name</label>
                    <input type="text" id="wl_athlete_name" name="athlete_facing_name" class="form-input" placeholder="e.g. Easy run">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label class="form-label" for="wl_type">Workout type</label>
                    <select id="wl_type" name="workout_type" class="form-input">
                        <?php foreach ($allTypes as $t): ?>
                        <option value="<?= h($t) ?>"><?= type_label($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="wl_presc">Prescription type</label>
                    <select id="wl_presc" name="prescription_type" class="form-input">
                        <option value="time">Time</option>
                        <option value="distance">Distance</option>
                        <option value="count">Count</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="wl_track">Track required</label>
                    <select id="wl_track" name="track_required" class="form-input">
                        <option value="no">No</option>
                        <option value="preferred">Preferred</option>
                        <option value="yes">Yes</option>
                    </select>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label class="form-label">Phase tags</label>
                    <input type="text" name="phase_tags" class="form-input" placeholder="base, build, peak, taper">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Comma-separated</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Distance tags</label>
                    <input type="text" name="distance_tags" class="form-input" placeholder="5K, 10K, half, marathon">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Comma-separated</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="wl_intensity">Intensity factor (0–1)</label>
                    <input type="number" id="wl_intensity" name="intensity_factor" class="form-input"
                           step="0.05" min="0" max="1" value="0.5">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="wl_desc">Athlete-facing description</label>
                <textarea id="wl_desc" name="description" class="form-textarea" rows="3"
                          placeholder="Instructions visible to the athlete…"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label" for="wl_engine">Engine notes (internal)</label>
                <textarea id="wl_engine" name="engine_notes" class="form-textarea" rows="2"
                          placeholder="Notes for the plan engine…"></textarea>
            </div>

            <div style="display:flex;align-items:center;gap:16px;margin-top:8px;">
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="coach_clearance_required" value="1">
                    Coach clearance required
                </label>
                <div style="margin-left:auto;display:flex;gap:8px;">
                    <button type="button" class="btn btn-secondary btn-sm"
                            onclick="document.getElementById('addTemplateForm').style.display='none';
                                     document.querySelector('.btn-primary.btn-sm').style.display='';">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm">Save template</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="/app/coach/library" style="margin-bottom:20px;">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div>
                <div style="font-size:11px;font-weight:500;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Search</div>
                <input type="text" name="q" value="<?= h($filterSearch) ?>" class="form-input"
                       style="width:200px;" placeholder="Search templates…">
            </div>
            <div>
                <div style="font-size:11px;font-weight:500;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Type</div>
                <select name="type" class="form-input" style="min-width:140px;">
                    <option value="">All types</option>
                    <?php foreach ($allTypes as $t): ?>
                    <option value="<?= h($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= type_label($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div style="font-size:11px;font-weight:500;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Phase</div>
                <select name="phase" class="form-input" style="min-width:120px;">
                    <option value="">All phases</option>
                    <?php foreach ($allPhases as $p): ?>
                    <option value="<?= h($p) ?>" <?= $filterPhase === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div style="font-size:11px;font-weight:500;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Distance</div>
                <select name="distance" class="form-input" style="min-width:120px;">
                    <option value="">All distances</option>
                    <?php foreach ($allDistances as $d): ?>
                    <option value="<?= h($d) ?>" <?= $filterDistance === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <?php if ($filterType || $filterPhase || $filterDistance || $filterSearch): ?>
            <a href="/app/coach/library" class="btn btn-sm" style="background:var(--recessed-bg);color:var(--text-muted);">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Template cards -->
    <?php if (empty($templates)): ?>
    <div class="card" style="border-left:3px solid var(--border-strong);">
        <div class="empty-state" style="padding:24px 0;">
            <div class="empty-state-title">No templates found</div>
            <p class="body-text">Try adjusting your filters or add a new template above.</p>
        </div>
    </div>
    <?php else: ?>

    <?php
    // Group by workout type for display
    $byType = [];
    foreach ($templates as $t) {
        $byType[$t['workout_type']][] = $t;
    }
    ?>

    <?php foreach ($byType as $type => $group): ?>
    <?php [$pillBg, $pillColor] = $typeColors[$type] ?? ['var(--recessed-bg)', 'var(--text-secondary)']; ?>
    <div class="section-label" style="margin-top:20px;color:<?= $pillColor ?>;">
        <?= strtoupper(type_label($type)) ?> (<?= count($group) ?>)
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px;margin-bottom:4px;">
    <?php foreach ($group as $tmpl): ?>
    <?php
        $phases    = parse_tags($tmpl['phase_tags']);
        $distances = parse_tags($tmpl['distance_tags']);
    ?>
    <div class="card" style="position:relative;">
        <!-- Type pill -->
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:8px;">
            <div style="font-size:14px;font-weight:600;line-height:1.3;">
                <?= h($tmpl['name']) ?>
                <?php if ($tmpl['library_code']): ?>
                <span style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:4px;"><?= h($tmpl['library_code']) ?></span>
                <?php endif; ?>
            </div>
            <span class="pill" style="background:<?= $pillBg ?>;color:<?= $pillColor ?>;font-size:10px;flex-shrink:0;">
                <?= type_label($type) ?>
            </span>
        </div>

        <?php if ($tmpl['athlete_facing_name'] && $tmpl['athlete_facing_name'] !== $tmpl['name']): ?>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">
            Shown as: <?= h($tmpl['athlete_facing_name']) ?>
        </div>
        <?php endif; ?>

        <!-- Tags row -->
        <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px;">
            <?php foreach ($phases as $phase): ?>
            <span class="pill" style="background:var(--recessed-bg);color:var(--text-secondary);font-size:10px;">
                <?= h(ucfirst($phase)) ?>
            </span>
            <?php endforeach; ?>
            <?php foreach ($distances as $dist): ?>
            <span class="pill" style="background:var(--accent-fill);color:var(--accent-strong);font-size:10px;">
                <?= h($dist) ?>
            </span>
            <?php endforeach; ?>
        </div>

        <!-- Meta row -->
        <div style="display:flex;gap:12px;font-size:11px;color:var(--text-muted);margin-bottom:8px;flex-wrap:wrap;">
            <span><?= ucfirst($tmpl['prescription_type']) ?>-based</span>
            <?php if ($tmpl['track_required'] !== 'no'): ?>
            <span>Track: <?= $tmpl['track_required'] ?></span>
            <?php endif; ?>
            <span>Intensity: <?= number_format((float)$tmpl['intensity_factor'], 2) ?></span>
            <?php if ($tmpl['coach_clearance_required']): ?>
            <span style="color:var(--color-warning);">Coach clearance required</span>
            <?php endif; ?>
        </div>

        <?php if ($tmpl['description']): ?>
        <p class="body-text" style="font-size:12px;color:var(--text-secondary);margin:0;
                                    display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">
            <?= h($tmpl['description']) ?>
        </p>
        <?php endif; ?>

        <?php if (!$tmpl['created_by']): ?>
        <div style="font-size:10px;color:var(--text-muted);margin-top:8px;">System template</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

</div>

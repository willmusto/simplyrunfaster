<?php
// $weeklyTrend = array of {wk, weekly_stress, ctl}
?>
<div class="page-content">
    <div class="page-heading" style="margin-bottom:20px;">Progress</div>

    <div class="section-label">WEEKLY TRAINING LOAD</div>
    <div class="card" style="margin-bottom:16px;">
        <?php if (empty($weeklyTrend)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📊</div>
            <div class="empty-state-title">No training data yet</div>
            <p class="body-text">Start logging workouts and your progress will appear here.</p>
        </div>
        <?php else: ?>
        <?php
        $maxStress = max(array_column($weeklyTrend, 'weekly_stress') ?: [1]);
        ?>
        <div style="display:flex;align-items:flex-end;gap:6px;height:80px;margin-bottom:8px;">
            <?php foreach ($weeklyTrend as $wk):
                $pct = $maxStress > 0 ? round(($wk['weekly_stress'] / $maxStress) * 100) : 0;
            ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;">
                <div style="width:100%;height:<?= $pct ?>%;min-height:2px;background:var(--accent-mid);border-radius:2px 2px 0 0;"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:6px;font-size:10px;color:var(--text-muted);">
            <?php foreach ($weeklyTrend as $wk): ?>
            <div style="flex:1;text-align:center;">
                W<?= substr((string)$wk['wk'], -2) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="section-label" style="margin-top:24px;">PERSONAL BESTS</div>
    <div class="card">
        <?php
        $db   = Database::get();
        $stmt = $db->prepare('SELECT * FROM personal_bests WHERE athlete_id = ? ORDER BY FIELD(distance,"5K","10K","15K","half","marathon","ultra","mile","other")');
        $stmt->execute([(int)$athlete['id']]);
        $pbs  = $stmt->fetchAll();
        ?>
        <?php if (empty($pbs)): ?>
        <div class="empty-state" style="padding:24px 0;">
            <div class="empty-state-title">No personal bests recorded</div>
            <p class="body-text">After your first race or time trial, your PBs will show here.</p>
        </div>
        <?php else: ?>
        <?php foreach ($pbs as $pb):
            $secs = (int)$pb['time_seconds'];
            $t    = sprintf('%d:%02d:%02d', intdiv($secs,3600), intdiv($secs%3600,60), $secs%60);
            if ($secs < 3600) $t = sprintf('%d:%02d', intdiv($secs,60), $secs%60);
        ?>
        <div class="week-row">
            <span class="week-row-day" style="width:60px;font-size:12px;"><?= h($pb['distance']) ?></span>
            <div class="week-row-body">
                <span style="font-size:16px;font-weight:500;"><?= h($t) ?></span>
                <?php if ($pb['notes']): ?>
                <span style="font-size:12px;color:var(--text-muted);margin-left:8px;"><?= h($pb['notes']) ?></span>
                <?php endif; ?>
            </div>
            <span class="pill <?= $pb['source'] === 'system' ? 'pill-active' : 'pill-info' ?>" style="font-size:10px;">
                <?= $pb['source'] === 'system' ? '✓ verified' : '✎ manual' ?>
            </span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

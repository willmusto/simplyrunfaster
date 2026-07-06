<?php
// $pendingPlans, $planWorkouts (keyed by plan_id)
?>
<div class="page-content">

    <div style="margin-bottom:var(--space-6);">
        <div class="page-heading" style="margin-bottom:0;">Plan Approvals</div>
        <div style="font-size:var(--text-sm);color:var(--text-muted);margin-top:2px;">
            Review and approve generated training plans before athletes can see them<?= !empty($pendingPlans) ? ' &middot; ' . count($pendingPlans) . ' waiting' : '' ?>.
        </div>
    </div>

    <?php if (empty($pendingPlans)): ?>
    <div class="card-allclear">All caught up. No plans are waiting for review.</div>
    <?php else: ?>

    <?php foreach ($pendingPlans as $plan): ?>
    <div class="card" style="margin-bottom:var(--space-4);">
        <div style="display:flex;align-items:flex-start;gap:var(--space-2);flex-wrap:wrap;margin-bottom:var(--space-3);">
            <div class="row-main">
                <div class="row-name"><?= h($plan['athlete_name']) ?></div>
                <div class="row-meta">
                    <?= h(ucfirst(str_replace('_', ' ', $plan['plan_type']))) ?>
                    &middot;
                    <?= date('M j, Y', strtotime($plan['plan_start_date'])) ?>
                    &ndash;
                    <?= date('M j, Y', strtotime($plan['plan_end_date'])) ?>
                </div>
                <div style="font-size:var(--text-xs);color:var(--text-muted);margin-top:4px;">
                    Requested <?= h(Timezone::format($plan['requested_at'], 'M j')) ?>
                </div>
            </div>
            <div class="row-side">
                <a href="/app/coach/athlete/<?= (int)$plan['athlete_id'] ?>" class="btn btn-secondary btn-sm">
                    View athlete
                </a>
            </div>
        </div>

        <div class="divider" style="margin:0 0 12px;"></div>

        <!-- Calendar preview -->
        <?php
        $calWorkouts  = $planWorkouts[(int)$plan['plan_id']] ?? [];
        $calMode      = 'preview';
        // Plan context lets the bubble partial mark the lead-in week and number code
        // weeks exactly like the macro view (views/coach/athlete_view.php).
        $calPlanStart = $plan['plan_start_date'] ?? null;
        $calPlanEnd   = $plan['plan_end_date']   ?? null;
        $calPlanType  = $plan['plan_type']       ?? null;
        if (!empty($calWorkouts)):
        ?>
        <div style="margin-bottom:16px;">
            <?php include __DIR__ . '/../partials/calendar_week.php'; ?>
        </div>
        <?php endif; ?>

        <div class="divider" style="margin:0 0 12px;"></div>

        <!-- Approve form -->
        <form method="POST" action="/app/coach/plans/<?= (int)$plan['plan_id'] ?>/approve" style="margin-bottom:8px;">
            <?= Auth::csrfField() ?>
            <div class="form-group" style="margin-bottom:10px;">
                <label class="form-label" for="notes_a_<?= (int)$plan['plan_id'] ?>">
                    Coach notes
                    <span style="font-weight:400;color:var(--text-muted);"> (optional)</span>
                </label>
                <textarea id="notes_a_<?= (int)$plan['plan_id'] ?>" name="coach_notes"
                          class="form-textarea" rows="2"
                          placeholder="Notes visible to athlete with the approved plan…"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Approve plan</button>
        </form>

        <!-- Reject form -->
        <form method="POST" action="/app/coach/plans/<?= (int)$plan['plan_id'] ?>/reject">
            <?= Auth::csrfField() ?>
            <div class="form-group" style="margin-bottom:10px;">
                <textarea name="coach_notes" class="form-textarea" rows="2"
                          placeholder="Reason for rejection (required for athlete notification)…"></textarea>
            </div>
            <button type="submit" class="btn btn-danger btn-sm">Reject &amp; regenerate</button>
        </form>

    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

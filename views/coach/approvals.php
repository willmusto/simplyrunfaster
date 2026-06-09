<?php
// $pendingPlans
?>
<div class="page-content">

    <div class="page-heading" style="margin-bottom:4px;">Plan Approvals</div>
    <p class="body-text" style="margin-bottom:12px;">
        Review and approve generated training plans before athletes can see them.
    </p>

    <?php if (empty($pendingPlans)): ?>
    <div class="card" style="border-left:3px solid var(--color-success);">
        <div class="empty-state" style="padding:24px 0;">
            <div class="empty-state-title">All caught up</div>
            <p class="body-text">No plans are waiting for review.</p>
        </div>
    </div>
    <?php else: ?>

    <?php foreach ($pendingPlans as $plan): ?>
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
            <div>
                <div style="font-size:15px;font-weight:600;"><?= h($plan['athlete_name']) ?></div>
                <div style="font-size:13px;color:var(--text-secondary);margin-top:2px;">
                    <?= h(ucfirst(str_replace('_', ' ', $plan['plan_type']))) ?>
                    &nbsp;·&nbsp;
                    <?= date('M j, Y', strtotime($plan['plan_start_date'])) ?>
                    –
                    <?= date('M j, Y', strtotime($plan['plan_end_date'])) ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                    Requested <?= date('M j', strtotime($plan['requested_at'])) ?>
                </div>
            </div>
            <a href="/app/coach/athlete/<?= (int)$plan['athlete_id'] ?>" class="btn btn-secondary btn-sm">
                View athlete
            </a>
        </div>

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

<?php
/**
 * Admin: reassign athletes between head coaches. Vars: $athleteRows, $coaches,
 * $flashSuccess, $flashError.
 */
?>
<div class="page-content">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
        <a href="/app/admin/users" style="color:var(--text-muted);text-decoration:none;font-size:20px;">←</a>
        <div class="page-heading" style="margin-bottom:0;">Athlete assignments</div>
    </div>

    <?php if (!empty($flashSuccess)): ?>
    <div class="flash flash-success" style="margin-bottom:16px;"><?= h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
    <div class="flash flash-error" style="margin-bottom:16px;"><?= h($flashError) ?></div>
    <?php endif; ?>

    <div class="card" style="padding:0;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="text-align:left;color:var(--text-muted);border-bottom:1px solid var(--border-color);">
                    <th style="padding:10px 12px;">Athlete</th>
                    <th style="padding:10px 12px;">Email</th>
                    <th style="padding:10px 12px;">Head coach</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($athleteRows as $a): ?>
                <tr style="border-bottom:1px solid var(--border-color);">
                    <td style="padding:10px 12px;font-weight:500;"><?= h($a['name']) ?></td>
                    <td style="padding:10px 12px;color:var(--text-secondary);"><?= h($a['email']) ?></td>
                    <td style="padding:10px 12px;">
                        <form method="POST" action="/app/admin/athletes/reassign"
                              style="display:flex;gap:6px;align-items:center;">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="athlete_id" value="<?= (int)$a['id'] ?>">
                            <select name="coach_id" class="form-select" style="font-size:12px;padding:4px 6px;width:auto;">
                                <?php foreach ($coaches as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= (int)($a['coach_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= h($c['name']) ?><?= $c['role'] === 'admin' ? ' (admin)' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

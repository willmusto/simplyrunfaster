<?php
/**
 * Admin user management. Vars: $users, $coaches, $flashSuccess, $flashError.
 */
$roleLabels = [
    'admin'           => 'Admin',
    'coach'           => 'Head coach',
    'assistant_coach' => 'Assistant coach',
    'athlete'         => 'Athlete',
];
$selfId = (int)Auth::userId();
?>
<div class="page-content">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;">
        <div class="page-heading" style="margin-bottom:0;">User management</div>
        <div style="display:flex;gap:8px;">
            <a href="/app/admin/coaches" class="btn btn-secondary btn-sm">Coach analytics</a>
            <a href="/app/admin/users/create" class="btn btn-primary btn-sm">+ New coach</a>
        </div>
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
                    <th style="padding:10px 12px;">Name</th>
                    <th style="padding:10px 12px;">Email</th>
                    <th style="padding:10px 12px;">Role</th>
                    <th style="padding:10px 12px;">Status</th>
                    <th style="padding:10px 12px;">Created</th>
                    <th style="padding:10px 12px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php
                $isAdmin  = $u['role'] === 'admin';
                $isSelf   = (int)$u['id'] === $selfId;
                $editable = !$isAdmin && !$isSelf;
                ?>
                <tr style="border-bottom:1px solid var(--border-color);vertical-align:top;">
                    <td style="padding:10px 12px;font-weight:500;"><?= h($u['name']) ?></td>
                    <td style="padding:10px 12px;color:var(--text-secondary);"><?= h($u['email']) ?></td>
                    <td style="padding:10px 12px;">
                        <?= h($roleLabels[$u['role']] ?? $u['role']) ?>
                        <?php if ($u['role'] === 'assistant_coach' && !empty($u['manager_name'])): ?>
                        <div style="font-size:11px;color:var(--text-muted);">under <?= h($u['manager_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 12px;">
                        <?php if ((int)$u['active'] === 1): ?>
                        <span class="pill" style="background:var(--accent-fill);color:var(--accent-strong);">Active</span>
                        <?php else: ?>
                        <span class="pill" style="background:var(--danger-fill);color:var(--color-danger);">Deactivated</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 12px;color:var(--text-muted);">
                        <?= h(date('M j, Y', strtotime((string)$u['created_at']))) ?>
                    </td>
                    <td style="padding:10px 12px;">
                        <?php if (!$editable): ?>
                        <span style="color:var(--text-muted);">—</span>
                        <?php else: ?>
                        <form method="POST" action="/app/admin/users/role"
                              style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px;"
                              onsubmit="return true;">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <select name="role" class="form-select" style="font-size:12px;padding:4px 6px;width:auto;"
                                    onchange="this.form.querySelector('[data-mgr]').style.display = this.value==='assistant_coach' ? '' : 'none';">
                                <?php foreach (['coach','assistant_coach','athlete'] as $r): ?>
                                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= h($roleLabels[$r]) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="managed_by" data-mgr class="form-select"
                                    style="font-size:12px;padding:4px 6px;width:auto;<?= $u['role'] === 'assistant_coach' ? '' : 'display:none;' ?>">
                                <option value="">Head coach…</option>
                                <?php foreach ($coaches as $c): if ((int)$c['id'] === (int)$u['id']) continue; ?>
                                <option value="<?= (int)$c['id'] ?>" <?= (int)($u['managed_by'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-secondary btn-sm">Save role</button>
                        </form>
                        <form method="POST" action="/app/admin/users/deactivate" style="margin:0;"
                              onsubmit="return confirm('Deactivate <?= h(addslashes($u['name'])) ?>? They will no longer be able to log in.');">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Deactivate</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p style="margin-top:14px;font-size:13px;">
        <a href="/app/admin/athletes" style="color:var(--accent-mid);">Manage athlete assignments →</a>
    </p>
</div>

<?php
/**
 * Admin: create a coach / assistant-coach account. Vars: $coaches, $flashError.
 */
?>
<div class="page-content">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
        <a href="/app/admin/users" style="color:var(--text-muted);text-decoration:none;font-size:20px;">←</a>
        <div class="page-heading" style="margin-bottom:0;">Create user</div>
    </div>
    <p class="body-text" style="margin-bottom:20px;color:var(--text-muted);">
        Creates the account with a temporary password and emails it to them. They are prompted to choose
        a new password on first login.
    </p>

    <?php if (!empty($flashError)): ?>
    <div class="flash flash-error" style="margin-bottom:16px;"><?= h($flashError) ?></div>
    <?php endif; ?>

    <form method="POST" action="/app/admin/users/create" class="card" style="max-width:480px;">
        <?= Auth::csrfField() ?>

        <div class="form-group">
            <label class="form-label" for="name">Name</label>
            <input type="text" id="name" name="name" class="form-input" required>
        </div>

        <div class="form-group">
            <label class="form-label" for="email">Email</label>
            <input type="email" id="email" name="email" class="form-input" required autocomplete="off">
        </div>

        <div class="form-group">
            <label class="form-label">Role</label>
            <div class="pill-choices">
                <label class="pill-choice selected">
                    <input type="radio" name="role" value="coach" checked
                           onchange="document.getElementById('mgrField').style.display='none';">
                    Head coach
                </label>
                <label class="pill-choice">
                    <input type="radio" name="role" value="assistant_coach"
                           onchange="document.getElementById('mgrField').style.display='';">
                    Assistant coach
                </label>
            </div>
        </div>

        <div class="form-group" id="mgrField" style="display:none;">
            <label class="form-label" for="managed_by">Assign to head coach</label>
            <select id="managed_by" name="managed_by" class="form-select">
                <option value="">Select a head coach…</option>
                <?php foreach ($coaches as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?><?= $c['role'] === 'admin' ? ' (admin)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top:8px;">Create and email password</button>
    </form>
</div>
<script>
// Keep the pill-choice selected styling in sync with the radios.
document.querySelectorAll('.pill-choices input[name="role"]').forEach(function (r) {
    r.addEventListener('change', function () {
        document.querySelectorAll('.pill-choices .pill-choice').forEach(function (l) { l.classList.remove('selected'); });
        if (r.checked) r.closest('.pill-choice').classList.add('selected');
    });
});
</script>

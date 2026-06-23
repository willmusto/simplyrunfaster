<?php
/**
 * Shared timezone selector. Caller sets in scope before including:
 *   $selectedTz   — currently stored IANA id (defaults to Timezone::DEFAULT_TZ)
 *   $tzFieldLabel — optional label text (defaults to "Timezone")
 *   $tzFieldHint  — optional hint text
 *
 * Renders a <select name="timezone"> inside a form-group. Offsets are computed
 * live so DST is reflected at render time.
 */
$selectedTz   = (isset($selectedTz) && Timezone::isValid($selectedTz)) ? $selectedTz : Timezone::DEFAULT_TZ;
$tzFieldLabel = $tzFieldLabel ?? 'Timezone';
$tzFieldHint  = $tzFieldHint  ?? null;
?>
<div class="form-group" style="margin-bottom:0;">
    <label class="form-label" for="timezone"><?= h($tzFieldLabel) ?></label>
    <select id="timezone" name="timezone" class="form-select">
        <?php foreach (Timezone::selectOptions() as $tzId => $tzLabel): ?>
        <option value="<?= h($tzId) ?>" <?= $tzId === $selectedTz ? 'selected' : '' ?>><?= h($tzLabel) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($tzFieldHint): ?>
    <div class="form-hint"><?= h($tzFieldHint) ?></div>
    <?php endif; ?>
</div>

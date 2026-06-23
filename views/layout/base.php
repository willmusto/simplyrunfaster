<?php
/**
 * Base layout helper — outputs full HTML shell.
 * Usage: include this file, then define $pageTitle, $bodyClass,
 * set $theme before including.
 *
 * Alternatively use render_page() below.
 */

function render_page(string $title, string $content, string $nav = 'athlete'): void
{
    $theme = Auth::theme();
    // Workout type → pill class mapping
    $GLOBALS['pill_map'] = [
        'easy'        => 'pill-easy',
        'long'        => 'pill-long',
        'interval'    => 'pill-interval',
        'tempo'       => 'pill-tempo',
        'hill'        => 'pill-hill',
        'fartlek'     => 'pill-fartlek',
        'recovery'    => 'pill-recovery',
        'rest'        => 'pill-rest',
        'race'        => 'pill-race',
        'cross_train' => 'pill-cross_train',
        'race_pace'   => 'pill-interval',
        'speed'       => 'pill-speed',
        'plyometric'  => 'pill-plyometric',
    ];
    ob_start();
    include __DIR__ . '/html_open.php';
    echo $content;
    include __DIR__ . '/html_close.php';
    echo ob_get_clean();
}

function pill_class(string $type, ?string $archetypeCode = null): string
{
    // Run/walk sessions store workout_type='easy' but should not read as a teal easy
    // run; give them the neutral recovery-badge style. Keyed on archetype_code.
    if ($archetypeCode === 'run_walk_intervals') return 'pill-recovery';

    $map = [
        'easy' => 'pill-easy', 'long' => 'pill-long',
        'interval' => 'pill-interval', 'tempo' => 'pill-tempo',
        'hill' => 'pill-hill', 'fartlek' => 'pill-fartlek',
        'recovery' => 'pill-recovery', 'rest' => 'pill-rest',
        'race' => 'pill-race', 'cross_train' => 'pill-cross_train',
        'race_pace' => 'pill-interval',
        'speed' => 'pill-speed', 'plyometric' => 'pill-plyometric',
    ];
    return $map[$type] ?? 'pill-rest';
}

function pill_label(string $type, ?string $archetypeCode = null): string
{
    if ($archetypeCode === 'run_walk_intervals') return 'Run/walk';

    $map = [
        'easy' => 'Easy run', 'long' => 'Long run',
        'interval' => 'Workout', 'tempo' => 'Tempo run',
        'hill' => 'Hill session', 'fartlek' => 'Fartlek',
        'recovery' => 'Recovery run', 'rest' => 'Rest day',
        'race' => 'Race', 'cross_train' => 'Cross-training',
        'race_pace' => 'Race pace',
        'speed' => 'Speed', 'plyometric' => 'Plyometric',
    ];
    return $map[$type] ?? ucfirst($type);
}

function avatar_initials(string $name): string
{
    $parts = explode(' ', trim($name));
    $init  = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $init .= strtoupper(substr(end($parts), 0, 1));
    return htmlspecialchars($init);
}

function format_duration(int $minutes): string
{
    if ($minutes < 60) return $minutes . ' min';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m ? "{$h}h {$m}min" : "{$h}h";
}

/**
 * Athlete-facing label for a stored goal_race_distance. Standard distances are
 * already stored as display strings ("5K", "Marathon") and pass through; ultra
 * distances are stored as canonical keys and map to friendly labels (ultra spec Part 14).
 */
function race_distance_label(?string $value): string
{
    if ($value === null || $value === '') return '';
    $map = [
        '50k'       => '50K Ultra',
        '50_miler'  => '50-Mile Ultra',
        '100k'      => '100K Ultra',
        '100_miler' => '100-Mile Ultra',
        'mile'      => 'Mile / 1500m',
    ];
    return $map[strtolower(trim($value))] ?? $value;
}

/**
 * Goal label that respects the Hyrox facade (mile spec Part 14). A 'mile' goal with
 * is_hyrox shows "Hyrox" to the athlete and "Hyrox (Mile training)" to the coach, so the
 * coach always sees the engine logic underneath. Everything else falls back to
 * race_distance_label().
 */
function goal_distance_label(?string $value, bool $isHyrox = false, bool $forCoach = false): string
{
    if (strtolower(trim((string)$value)) === 'mile' && $isHyrox) {
        return $forCoach ? 'Hyrox (Mile training)' : 'Hyrox';
    }
    return race_distance_label($value);
}

/**
 * "Message coach about this workout" button for the Today/Plan workout cards.
 * $replyCount: null = no thread yet, 0 = one message awaiting reply, >0 = active thread.
 */
function render_workout_thread_button(int $plannedWorkoutId, ?int $replyCount): string
{
    $href = '/app/messages/workout/' . $plannedWorkoutId;
    if ($replyCount === null) {
        $label = 'Ask your coach about this workout';
        $style = '';
    } elseif ($replyCount <= 0) {
        $label = '1 message · waiting for reply';
        $style = 'color:var(--text-muted);';
    } else {
        $label = 'View thread (' . $replyCount . ' ' . ($replyCount === 1 ? 'reply' : 'replies') . ')';
        $style = '';
    }
    return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
        . '" class="btn btn-secondary btn-sm" style="display:inline-block;margin-top:10px;' . $style . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}

function format_pace(float $pace): string
{
    $min = intval($pace);
    $sec = round(($pace - $min) * 60);
    return sprintf('%d:%02d /mi', $min, $sec);
}

function h(mixed $val): string
{
    return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
}

/**
 * Render a profile_updated flag's details diff as a before/after list.
 * Accepts the raw engine_flags.details JSON string. Returns '' if the
 * payload isn't a recognised profile diff.
 */
function render_profile_diff(?string $detailsJson): string
{
    if (!$detailsJson) return '';
    $data = json_decode($detailsJson, true);
    if (!is_array($data) || empty($data['changes']) || !is_array($data['changes'])) {
        return '';
    }
    // Single representation of a profile change: tight "Label → value" rows
    // (.profile-diff in app.css). One clean arrow per row — no prose restatement.
    // When there's no prior value (newly-set field), render just "Label → new";
    // ProfileForm::format() emits an em-dash placeholder for null/empty, so an
    // old_display of '—' (or blank) means "no old value" — don't strike it through
    // (that produced the stray "=" glyph). Views suppress the flag's prose message
    // for profile_updated so this is the only place the change is shown.
    $rows = '';
    foreach ($data['changes'] as $c) {
        $label  = h($c['label'] ?? ($c['field'] ?? 'Field'));
        $oldRaw = trim((string)($c['old_display'] ?? ''));
        $newRaw = trim((string)($c['new_display'] ?? ''));
        $new    = h($newRaw === '' ? '—' : $newRaw);
        $hasOld = $oldRaw !== '' && $oldRaw !== '—';
        $tag    = !empty($c['coach_only'])
            ? ' <span class="pill" style="font-size:9px;background:var(--recessed-bg);color:var(--text-muted);">coach</span>'
            : '';
        $val = $hasOld
            ? '<span class="profile-diff-old">' . h($oldRaw) . '</span> '
              . '<span class="profile-diff-arrow">&rarr;</span> '
              . '<span class="profile-diff-new">' . $new . '</span>'
            : '<span class="profile-diff-arrow">&rarr;</span> '
              . '<span class="profile-diff-new">' . $new . '</span>';
        $rows .= '<div class="profile-diff-row">'
               . '<span class="profile-diff-label">' . $label . $tag . '</span>'
               . '<span class="profile-diff-val">' . $val . '</span>'
               . '</div>';
    }
    return '<div class="profile-diff">' . $rows . '</div>';
}

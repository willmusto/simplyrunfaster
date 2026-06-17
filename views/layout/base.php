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

function pill_class(string $type): string
{
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

function pill_label(string $type): string
{
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
    ];
    return $map[strtolower(trim($value))] ?? $value;
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
    $rows = '';
    foreach ($data['changes'] as $c) {
        $label = h($c['label'] ?? ($c['field'] ?? 'Field'));
        $old   = h($c['old_display'] ?? '–');
        $new   = h($c['new_display'] ?? '–');
        $tag   = !empty($c['coach_only'])
            ? ' <span class="pill" style="font-size:9px;background:var(--recessed-bg);color:var(--text-muted);">coach</span>'
            : '';
        $rows .= '<div style="display:flex;justify-content:space-between;gap:10px;font-size:12px;padding:4px 0;border-bottom:1px solid var(--divider);">'
               . '<span style="color:var(--text-muted);">' . $label . $tag . '</span>'
               . '<span style="text-align:right;"><span style="color:var(--text-muted);text-decoration:line-through;">' . $old . '</span>'
               . ' <span style="color:var(--text-muted);">→</span> <span style="font-weight:600;">' . $new . '</span></span>'
               . '</div>';
    }
    return '<div style="margin-top:6px;">' . $rows . '</div>';
}

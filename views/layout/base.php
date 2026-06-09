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

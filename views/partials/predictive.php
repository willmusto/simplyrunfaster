<?php
/**
 * Shared render helpers for Coaching Intelligence Phase 3 (predictive flags +
 * response profile). Included by the Intelligence page and the athlete context
 * panel; guarded against double-declaration.
 */

if (!function_exists('pf_is_predictive')) {
    /** True for the four Phase 3 predictive flag types. */
    function pf_is_predictive(string $flagType): bool
    {
        return in_array($flagType, ['predicted_fatigue', 'injury_risk_pattern', 'predicted_dropout', 'adaptation_ahead'], true);
    }

    /** Confidence + horizon pill HTML for a predictive flag (empty for non-predictive). */
    function pf_confidence_badge(?string $confidence, $horizon): string
    {
        if (!$confidence) return '';
        $color = match ($confidence) {
            'high'   => 'var(--accent-mid)',
            'medium' => 'var(--color-warning)',
            default  => 'var(--text-muted)',
        };
        $label = ucfirst($confidence) . ' confidence';
        if ($horizon !== null && $horizon !== '') {
            $label .= ' · ~' . (int)$horizon . 'd';
        }
        return '<span class="pill" style="font-size:10px;background:var(--recessed-bg);color:' . $color . ';">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /** Human one-liner for a single response-profile metric value. */
    function pf_metric_value(array $metric): string
    {
        $v = $metric['value'] ?? null;
        if ($v === null) return 'Not enough data yet';
        $unit = $metric['unit'] ?? '';
        switch ($unit) {
            case 'effort_pts':
                $s = ($v >= 0 ? '+' : '') . number_format((float)$v, 1);
                return $s . ' vs prescribed';
            case 'mins':
                return (int)$v . ' min/week';
            case 'days':
                return number_format((float)$v, 1) . ' days';
            case 'ratio':
                $s = ($v >= 0 ? '+' : '') . round((float)$v * 100) . '%';
                return $s . ' compliance';
            default:
                return (string)$v;
        }
    }

    /**
     * Response-profile summary block. Shows "Not enough data yet" below the
     * 4-week minimum; otherwise lists each metric with its value + confidence.
     */
    function pf_response_profile_html(?array $profile): string
    {
        $min = class_exists('PredictiveConstants') ? PredictiveConstants::MIN_WEEKS_DATA : 4;
        $weeks = (int)($profile['weeks_of_data'] ?? 0);
        if (!$profile || $weeks < $min) {
            return '<p class="body-text" style="margin:0;color:var(--text-muted);">Not enough data yet — '
                . $weeks . ' of ' . $min . ' weeks of history. Predictions appear once the athlete has enough logged training.</p>';
        }
        $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $out = '<div style="display:flex;flex-direction:column;gap:8px;">';
        foreach (($profile['metrics'] ?? []) as $m) {
            $conf = (string)($m['confidence'] ?? 'none');
            $confColor = match ($conf) { 'high' => 'var(--accent-mid)', 'medium' => 'var(--color-warning)', 'low' => 'var(--text-muted)', default => 'var(--text-muted)' };
            $out .= '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;">'
                . '<span style="font-size:12px;color:var(--text-secondary);">' . $h($m['label'] ?? '') . '</span>'
                . '<span style="font-size:12px;font-weight:600;text-align:right;">' . $h(pf_metric_value($m));
            if (($m['value'] ?? null) !== null && $conf !== 'none') {
                $out .= ' <span style="font-weight:400;color:' . $confColor . ';">(' . $h($conf) . ')</span>';
            }
            $out .= '</span></div>';
        }
        $out .= '</div>';
        if (!empty($profile['computed_at'])) {
            $out .= '<div style="font-size:11px;color:var(--text-muted);margin-top:8px;">Updated '
                . $h(date('M j', strtotime((string)$profile['computed_at']))) . '</div>';
        }
        return $out;
    }
}

<?php
/**
 * EmailTemplates — HTML + plain-text bodies for transactional notification emails.
 *
 * Every template shares one wrapper: a text wordmark (no image asset), a teal
 * (#1D9E75) heading + CTA button, and a footer with a manage-preferences link.
 * build() maps a notification type to a subject + html + text; types without a
 * dedicated template fall back to a generic single-CTA layout.
 *
 * Links are absolute, derived from APP_URL (which already ends in /app), so the
 * path arguments here are relative to that (e.g. '/dashboard', '/coach/flags').
 */
class EmailTemplates
{
    private const TEAL = '#1D9E75';

    /**
     * @param string $type Notification type.
     * @param array  $data Presentation data (names, snippets, counts).
     * @param array  $user Recipient row: id, name, email, role.
     * @return array{subject:string,html:string,text:string}
     */
    public static function build(string $type, array $data, array $user): array
    {
        $audience = ($user['role'] ?? 'athlete') === 'athlete' ? 'athlete' : 'coach';
        $name     = $data['athlete_name'] ?? ($data['sender_name'] ?? '');
        $sender   = $data['sender_name']  ?? 'your coach';
        $workout  = $data['workout_name'] ?? 'your workout';

        switch ($type) {
            case 'plan_approved':
                return self::compose($audience, 'Your plan is ready',
                    'Your plan is ready 🎉',
                    '<p>Your coach has approved your training plan. Open the app to see your upcoming schedule and get started.</p>',
                    'View my plan', '/dashboard');

            case 'plan_pending_approval':
                return self::compose($audience, $name . "'s plan needs your review",
                    'A plan needs your review',
                    '<p><strong>' . self::h($name) . "</strong>'s training plan has been generated and is waiting for your approval.</p>",
                    'Review the plan', '/coach/approvals');

            case 'message_from_coach':
            case 'message_from_athlete':
                $who = $type === 'message_from_coach' ? $sender : $name;
                $url = $type === 'message_from_coach'
                    ? '/messages'
                    : '/coach/athlete/' . (int)($data['athlete_id'] ?? 0) . '/messages';
                return self::compose($audience, 'New message from ' . $who,
                    'New message from ' . self::h($who),
                    '<p>' . self::h($who) . ' sent you a message:</p>'
                    . '<blockquote style="margin:0;border-left:3px solid ' . self::TEAL . ';padding:8px 14px;color:#444;">'
                    . self::h(Notifications::snip($data['message'] ?? '', 240)) . '</blockquote>',
                    'Open messages', $url);

            case 'critical_flag':
            case 'warning_flag':
            case 'info_flag':
                $sev = $type === 'critical_flag' ? 'Attention needed' : ($type === 'warning_flag' ? 'Heads up' : 'For your information');
                return self::compose($audience, $sev . ': ' . $name,
                    $sev . ': ' . self::h($name),
                    '<p>' . self::h($data['flag_message'] ?? 'A flag was raised for this athlete.') . '</p>',
                    'View alerts', '/coach/flags');

            case 'weekly_summary':
                return self::compose($audience, 'Your week in review',
                    'Your week in review',
                    '<p>You completed <strong>' . (int)($data['completed'] ?? 0) . '</strong> of <strong>'
                    . (int)($data['planned'] ?? 0) . '</strong> planned workouts this week.</p>'
                    . (isset($data['next_week_start']) ? '<p>Your next week starts ' . self::h($data['next_week_start']) . '.</p>' : '')
                    . (isset($data['detail_html']) ? $data['detail_html'] : ''),
                    'See my progress', '/progress');

            case 'weekly_athlete_digest':
                $rows = $data['detail_html'] ?? '';
                return self::compose($audience, 'Weekly athlete digest',
                    'Weekly athlete digest',
                    '<p>This week across your roster: <strong>' . (int)($data['athletes'] ?? 0) . '</strong> athletes, <strong>'
                    . (int)($data['open_flags'] ?? 0) . '</strong> open flags, <strong>'
                    . (int)($data['upcoming_races'] ?? 0) . '</strong> upcoming races.</p>' . $rows,
                    'Open dashboard', '/coach/dashboard');

            case 'coach_session_comment':
                return self::compose($audience, 'Your coach commented on a workout',
                    'New comment from your coach',
                    '<p>Your coach left a comment on <strong>' . self::h($workout) . '</strong>.</p>',
                    'View the comment', '/log');

            case 'pre_race_reminder':
                $n = (int)($data['days_out'] ?? 0);
                return self::compose($audience, 'Race day approaching',
                    "You're racing in " . $n . ' day' . ($n === 1 ? '' : 's'),
                    '<p><strong>' . self::h($data['race_name'] ?? 'Your race') . '</strong> is ' . $n . ' day'
                    . ($n === 1 ? '' : 's') . ' away. Trust your training.</p>',
                    'View my plan', '/plan');

            case 'payment_failed_athlete':
                return self::compose($audience, 'There was a problem with your payment',
                    'Payment problem',
                    '<p>There was a problem with your most recent payment. Please update your billing '
                    . 'information to keep your access to SimplyRunFaster.</p>',
                    'Update billing', '/billing');

            case 'payment_failed_coach':
                return self::compose($audience, $name . "'s payment failed",
                    'A payment failed',
                    '<p><strong>' . self::h($name) . "</strong>'s subscription payment failed. "
                    . 'They have been asked to update their billing information.</p>',
                    'View athlete', '/coach/athlete/' . (int)($data['athlete_id'] ?? 0));

            default:
                // Generic single-CTA fallback for any other emailable type.
                return self::compose($audience, $data['subject'] ?? 'SimplyRunFaster',
                    $data['heading'] ?? 'SimplyRunFaster',
                    '<p>' . self::h($data['body'] ?? '') . '</p>',
                    $data['cta'] ?? 'Open the app', $data['path'] ?? '/dashboard');
        }
    }

    /** Assemble subject + html + text from the shared wrapper. */
    private static function compose(string $audience, string $subject, string $heading, string $bodyHtml, string $ctaText, string $ctaPath): array
    {
        $ctaUrl = self::url($ctaPath);
        return [
            'subject' => $subject,
            'html'    => self::wrapHtml($audience, $heading, $bodyHtml, $ctaText, $ctaUrl),
            'text'    => self::wrapText($audience, $heading, $bodyHtml, $ctaText, $ctaUrl),
        ];
    }

    private static function wrapHtml(string $audience, string $heading, string $bodyHtml, string $ctaText, string $ctaUrl): string
    {
        $prefsUrl = self::url($audience === 'athlete' ? '/settings/notifications' : '/coach/settings/notifications');
        $teal     = self::TEAL;
        return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f6f5;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
        <tr><td style="padding:24px 32px 8px;">
          <div style="font-size:18px;font-weight:700;color:#FFFFFF;">Simply<span style="color:{$teal};">Run</span><span style="color:#FFFFFF;">Faster</span></div>
        </td></tr>
        <tr><td style="padding:8px 32px 0;">
          <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:{$teal};">{$heading}</h1>
          <div style="font-size:15px;line-height:1.6;color:#333;">{$bodyHtml}</div>
        </td></tr>
        <tr><td style="padding:20px 32px 28px;">
          <a href="{$ctaUrl}" style="display:inline-block;background:{$teal};color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:12px 22px;border-radius:8px;">{$ctaText}</a>
        </td></tr>
        <tr><td style="padding:18px 32px 26px;border-top:1px solid #eee;">
          <p style="margin:0;font-size:12px;line-height:1.5;color:#999;">
            You're receiving this because you're a SimplyRunFaster {$audience}.<br>
            <a href="{$prefsUrl}" style="color:#999;">Manage your notification preferences</a>.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
    }

    private static function wrapText(string $audience, string $heading, string $bodyHtml, string $ctaText, string $ctaUrl): string
    {
        $prefsUrl = self::url($audience === 'athlete' ? '/settings/notifications' : '/coach/settings/notifications');
        $body     = trim(html_entity_decode(strip_tags(str_replace(['</p>', '</blockquote>'], "\n\n", $bodyHtml)), ENT_QUOTES));
        $body     = preg_replace("/\n{3,}/", "\n\n", $body);
        return "SimplyRunFaster\n\n{$heading}\n\n{$body}\n\n{$ctaText}: {$ctaUrl}\n\n—\nYou're receiving this because you're a SimplyRunFaster {$audience}.\nManage your notification preferences: {$prefsUrl}\n";
    }

    /** Absolute URL from a path relative to APP_URL (which already ends in /app). */
    private static function url(string $path): string
    {
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://simplyrunfaster.com/app';
        return $base . $path;
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

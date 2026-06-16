<?php
/**
 * Mailer — thin wrapper around the Resend transactional-email client.
 *
 * The API key is read from the RESEND_API_KEY config constant and is never
 * hardcoded. All sends originate from a verified-domain address; the default
 * is noreply@simplyrunfaster.com (simplyrunfaster.com is verified in Resend).
 */
class Mailer
{
    private const DEFAULT_FROM = 'SimplyRunFaster <noreply@simplyrunfaster.com>';

    /**
     * Send a single transactional email.
     *
     * @param string      $to      Recipient address.
     * @param string      $subject Subject line.
     * @param string      $html    HTML body.
     * @param string|null $text    Optional plain-text fallback part.
     * @param string|null $from    Optional from override; defaults to noreply@simplyrunfaster.com.
     * @return bool                True on success, false on failure (failures are logged).
     */
    public static function send(string $to, string $subject, string $html, ?string $text = null, ?string $from = null): bool
    {
        $apiKey = defined('RESEND_API_KEY') ? RESEND_API_KEY : '';
        if ($apiKey === '') {
            error_log('Mailer: RESEND_API_KEY is not configured; email to ' . $to . ' not sent.');
            return false;
        }

        if (!class_exists('Resend')) {
            error_log('Mailer: Resend SDK not loaded (run composer install); email to ' . $to . ' not sent.');
            return false;
        }

        try {
            $resend  = Resend::client($apiKey);
            $payload = [
                'from'    => $from ?: self::DEFAULT_FROM,
                'to'      => [$to],
                'subject' => $subject,
                'html'    => $html,
            ];
            if ($text !== null && $text !== '') {
                $payload['text'] = $text;
            }
            $response = $resend->emails->send($payload);

            error_log('Mailer: sent email to ' . $to . ' (id: ' . ($response->id ?? 'unknown') . ')');
            return true;
        } catch (\Throwable $e) {
            error_log('Mailer: failed to send email to ' . $to . ': ' . $e->getMessage());
            return false;
        }
    }
}

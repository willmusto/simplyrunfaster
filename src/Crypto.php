<?php
/**
 * Crypto — symmetric at-rest encryption for sensitive strings (OAuth access
 * tokens). Authenticated encryption via AES-256-GCM, keyed by APP_ENCRYPTION_KEY.
 *
 * APP_ENCRYPTION_KEY is a 64-hex-character (32-byte) key that lives ONLY in
 * config/config.local.php on the server. It is never committed. Generate one with:
 *
 *     php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 *
 * Storage format (single base64 blob): nonce(12) || tag(16) || ciphertext.
 * Decrypt() returns null on any failure (bad key, tampering, malformed input) so
 * callers degrade to "not connected" rather than crashing.
 */
class Crypto
{
    private const CIPHER     = 'aes-256-gcm';
    private const NONCE_LEN  = 12;
    private const TAG_LEN    = 16;

    /** True when a usable 32-byte key is configured. */
    public static function isConfigured(): bool
    {
        return self::key() !== null;
    }

    /** Resolve the raw 32-byte key from APP_ENCRYPTION_KEY, or null if unusable. */
    private static function key(): ?string
    {
        if (!defined('APP_ENCRYPTION_KEY') || APP_ENCRYPTION_KEY === '') {
            return null;
        }
        $hex = trim((string)APP_ENCRYPTION_KEY);
        // Prefer a 64-hex-char key (32 bytes). Fall back to a raw 32-byte string.
        if (preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            return hex2bin($hex);
        }
        return strlen($hex) === 32 ? $hex : null;
    }

    /**
     * Encrypt a plaintext string. Returns a base64 blob suitable for a TEXT column,
     * or null when no key is configured (caller must check before persisting).
     */
    public static function encrypt(string $plaintext): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $nonce = random_bytes(self::NONCE_LEN);
        $tag   = '';
        $cipher = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            return null;
        }
        return base64_encode($nonce . $tag . $cipher);
    }

    /**
     * Decrypt a blob produced by encrypt(). Returns null on any failure so callers
     * can treat a corrupt/untrusted value as absent.
     */
    public static function decrypt(?string $blob): ?string
    {
        $key = self::key();
        if ($key === null || $blob === null || $blob === '') {
            return null;
        }
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < self::NONCE_LEN + self::TAG_LEN) {
            return null;
        }
        $nonce  = substr($raw, 0, self::NONCE_LEN);
        $tag    = substr($raw, self::NONCE_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::NONCE_LEN + self::TAG_LEN);
        $plain  = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? null : $plain;
    }
}

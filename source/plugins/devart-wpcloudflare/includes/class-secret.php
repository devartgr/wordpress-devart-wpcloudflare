<?php
/**
 * Sodium secretbox helpers for the API token at rest (enc:v2:).
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Secret
{
    /**
     * @throws \RuntimeException When sodium is unavailable.
     */
    public static function encrypt(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (!function_exists('sodium_crypto_secretbox') || !function_exists('random_bytes')) {
            throw new \RuntimeException(
                __('Cannot store the API token securely: sodium encryption is unavailable.', 'devart-wpcloudflare')
            );
        }

        $key = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($value, $nonce, $key);

        return 'enc:v2:' . base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $value): string
    {
        $value = trim($value);

        if ($value === '' || !str_starts_with($value, 'enc:v2:')) {
            return '';
        }

        if (!function_exists('sodium_crypto_secretbox_open')) {
            return '';
        }

        $decoded = base64_decode(substr($value, 7), true);

        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        return is_string($plain) ? $plain : '';
    }

    public static function is_v2_blob(string $value): bool
    {
        return str_starts_with(trim($value), 'enc:v2:');
    }

    private static function key(): string
    {
        return hash('sha256', wp_salt('auth'), true);
    }
}

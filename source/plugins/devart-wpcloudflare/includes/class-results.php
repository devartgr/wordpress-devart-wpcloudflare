<?php
/**
 * Last purge result / error / active failure options.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Results
{
    public const LAST_RESULT_KEY = 'devart_wpcloudflare_last_result';
    public const LAST_ERROR_KEY = 'devart_wpcloudflare_last_error';
    public const ACTIVE_FAILURE_KEY = 'devart_wpcloudflare_active_failure';

    /**
     * @param array{success: bool, message: string, context?: string} $result
     */
    public static function store(array $result): void
    {
        $payload = [
            'success' => !empty($result['success']),
            'message' => sanitize_text_field((string) ($result['message'] ?? '')),
            'context' => sanitize_key((string) ($result['context'] ?? 'manual')),
            'time'    => time(),
        ];

        update_option(self::LAST_RESULT_KEY, $payload, false);

        if ($payload['success']) {
            return;
        }

        update_option(self::LAST_ERROR_KEY, $payload, false);
    }

    public static function set_active_failure(string $message, string $context = 'auto_retry'): void
    {
        $payload = [
            'success' => false,
            'message' => sanitize_text_field($message),
            'context' => sanitize_key($context),
            'time'    => time(),
        ];

        update_option(self::ACTIVE_FAILURE_KEY, $payload, false);
        update_option(self::LAST_ERROR_KEY, $payload, false);
        update_option(self::LAST_RESULT_KEY, $payload, false);
    }

    public static function clear_active_failure(): void
    {
        delete_option(self::ACTIVE_FAILURE_KEY);
    }

    /**
     * @return array{success: bool, message: string, context: string, time: int}|null
     */
    public static function active_failure(): ?array
    {
        return self::read(self::ACTIVE_FAILURE_KEY);
    }

    /**
     * @return array{success: bool, message: string, context: string, time: int}|null
     */
    public static function last(): ?array
    {
        return self::read(self::LAST_RESULT_KEY);
    }

    /**
     * @return array{success: bool, message: string, context: string, time: int}|null
     */
    public static function last_error(): ?array
    {
        return self::read(self::LAST_ERROR_KEY);
    }

    /**
     * @return array{success: bool, message: string, context: string, time: int}|null
     */
    private static function read(string $key): ?array
    {
        $stored = get_option($key, null);

        if (!is_array($stored)) {
            return null;
        }

        return [
            'success' => !empty($stored['success']),
            'message' => (string) ($stored['message'] ?? ''),
            'context' => (string) ($stored['context'] ?? ''),
            'time'    => (int) ($stored['time'] ?? 0),
        ];
    }
}

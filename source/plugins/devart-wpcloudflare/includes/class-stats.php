<?php
/**
 * Lightweight operational counters (rolling calendar day, UTC).
 *
 * Buffers deltas in-request and writes once on shutdown (priority 1000).
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Stats
{
    public const OPTION_KEY = 'devart_wpcloudflare_stats';

    private static int $purged_delta = 0;
    private static int $failures_delta = 0;
    private static int $max_pending_seen = 0;
    private static bool $shutdown_registered = false;

    /**
     * @return array{day: string, purged_urls: int, failures: int, max_pending: int}
     */
    public static function snapshot(): array
    {
        $data = self::read_stored();
        $data['purged_urls'] += self::$purged_delta;
        $data['failures'] += self::$failures_delta;
        $data['max_pending'] = max($data['max_pending'], self::$max_pending_seen);

        return $data;
    }

    public static function record_success(int $url_count): void
    {
        self::$purged_delta += max(0, $url_count);
        self::register_shutdown();
    }

    public static function record_failure(): void
    {
        ++self::$failures_delta;
        self::register_shutdown();
    }

    public static function note_queue_depth(int $pending): void
    {
        $pending = max(0, $pending);

        if ($pending > self::$max_pending_seen) {
            self::$max_pending_seen = $pending;
            self::register_shutdown();
        }
    }

    /**
     * Persist buffered deltas (also callable from tests).
     */
    public static function flush(): void
    {
        if (self::$purged_delta === 0 && self::$failures_delta === 0 && self::$max_pending_seen === 0) {
            return;
        }

        $data = self::read_stored();
        $data['purged_urls'] += self::$purged_delta;
        $data['failures'] += self::$failures_delta;
        $data['max_pending'] = max($data['max_pending'], self::$max_pending_seen);

        update_option(self::OPTION_KEY, $data, false);

        self::$purged_delta = 0;
        self::$failures_delta = 0;
        self::$max_pending_seen = 0;
    }

    /**
     * Reset buffers without writing (tests only).
     */
    public static function reset_buffer(): void
    {
        self::$purged_delta = 0;
        self::$failures_delta = 0;
        self::$max_pending_seen = 0;
        self::$shutdown_registered = false;
    }

    private static function register_shutdown(): void
    {
        if (self::$shutdown_registered) {
            return;
        }

        self::$shutdown_registered = true;
        add_action('shutdown', [self::class, 'flush'], 1000);
    }

    /**
     * @return array{day: string, purged_urls: int, failures: int, max_pending: int}
     */
    private static function read_stored(): array
    {
        $today = gmdate('Y-m-d');
        $stored = get_option(self::OPTION_KEY, null);

        if (!is_array($stored) || (string) ($stored['day'] ?? '') !== $today) {
            return [
                'day'         => $today,
                'purged_urls' => 0,
                'failures'    => 0,
                'max_pending' => 0,
            ];
        }

        return [
            'day'         => $today,
            'purged_urls' => max(0, (int) ($stored['purged_urls'] ?? 0)),
            'failures'    => max(0, (int) ($stored['failures'] ?? 0)),
            'max_pending' => max(0, (int) ($stored['max_pending'] ?? 0)),
        ];
    }
}

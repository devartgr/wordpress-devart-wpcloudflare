<?php
/**
 * Durable auto-purge URL queue (custom table via Queue_Store).
 *
 * Prefer a rare double-purge over a lost purge. Destination writes are confirmed
 * before clearing claim rows.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Purge_Queue
{
    public const HOOK = 'devart_wpcloudflare_retry';
    public const MAX_ATTEMPTS = 8;

    private const STALE_SECONDS = 300;
    private const DEFAULT_CLAIM = 100;
    private const SCHEDULE_MAX = 3600;
    /** Overdue pending without a worker is flagged after this grace (seconds). */
    private const STALL_GRACE = 300;

    private static ?Queue_Store $store = null;
    private static bool $unavailable = false;

    /**
     * Inject a store (selftest / integration). Null restores production resolution.
     */
    public static function use_store(?Queue_Store $store): void
    {
        self::$store = $store;
        self::$unavailable = false;
    }

    /**
     * Merge URLs into the pending queue and ensure a worker is scheduled.
     *
     * @param list<string> $urls
     * @return bool True when work is durably stored (or nothing to store).
     */
    public static function enqueue(array $urls): bool
    {
        $normalized = self::normalize_urls($urls);

        if ($normalized === []) {
            return true;
        }

        $store = self::store();

        if ($store === null) {
            return false;
        }

        if (!$store->add($normalized)) {
            return false;
        }

        self::ensure_scheduled();

        return true;
    }

    /**
     * Claim up to $limit due URLs.
     *
     * @return array{batch_id: string, urls: list<string>}|null
     */
    public static function claim(int $limit = self::DEFAULT_CLAIM): ?array
    {
        $store = self::store();

        if ($store === null) {
            return null;
        }

        $limit = max(1, $limit);
        $batch_id = bin2hex(random_bytes(8));
        $rows = $store->claim($limit, $batch_id, time(), self::STALE_SECONDS);

        if ($rows === []) {
            return null;
        }

        $urls = [];

        foreach ($rows as $row) {
            $urls[] = $row['url'];
        }

        return [
            'batch_id' => $batch_id,
            'urls'     => $urls,
        ];
    }

    public static function ack(string $batch_id): void
    {
        $batch_id = trim($batch_id);

        if ($batch_id === '') {
            return;
        }

        $store = self::store();

        if ($store === null) {
            self::ensure_scheduled();

            return;
        }

        $store->release($batch_id, [], [], 0);
        self::ensure_scheduled();
    }

    /**
     * Return failed URLs to pending (or drop after max attempts).
     *
     * @param list<string> $urls
     */
    public static function nack(string $batch_id, array $urls, bool $permanent = false, int $retry_after = 0): void
    {
        $batch_id = trim($batch_id);
        $normalized = self::normalize_urls($urls);

        if ($batch_id === '') {
            return;
        }

        $store = self::store();

        if ($store === null) {
            self::ensure_scheduled(self::backoff_seconds(1, $retry_after));

            return;
        }

        $claimed = $store->claimed($batch_id);

        if ($claimed === []) {
            return;
        }

        $requeue = [];
        $dropped = [];
        $now = time();
        $dirty_floor = $now + self::backoff_seconds(1, $retry_after);

        foreach ($normalized as $url) {
            if (!isset($claimed[$url])) {
                continue;
            }

            $prior = (int) $claimed[$url];
            $next_attempts = $prior + 1;

            if ($permanent || $next_attempts >= self::MAX_ATTEMPTS) {
                $dropped[] = $url;

                continue;
            }

            $next_at = $now + self::backoff_seconds($next_attempts, $retry_after);
            $dirty_floor = max($dirty_floor, $next_at);
            $requeue[$url] = [
                'attempts'        => $next_attempts,
                'next_attempt_at' => $next_at,
            ];
        }

        // Destination first: drop/retry rows confirmed in the same release().
        if (!$store->release($batch_id, $requeue, $dropped, $dirty_floor)) {
            Results::store([
                'success' => false,
                'message' => __('Could not release the purge claim after failure handling; rows remain claimed for recovery.', 'devart-wpcloudflare'),
                'context' => 'auto_retry',
            ]);

            return;
        }

        if ($dropped !== []) {
            self::record_give_up_notice($permanent, $dropped);
        }

        self::ensure_scheduled(self::backoff_seconds(1, $retry_after));
    }

    /**
     * @param list<string> $urls
     */
    public static function append_dropped(array $urls): bool
    {
        $normalized = self::normalize_urls($urls);

        if ($normalized === []) {
            return true;
        }

        $store = self::store();

        if ($store === null) {
            return false;
        }

        return $store->mark_dropped($normalized);
    }

    /**
     * @return array{urls: list<string>, count: int, time: int, truncated: bool}|null
     */
    public static function get_dropped(): ?array
    {
        $store = self::store();

        if ($store === null) {
            return null;
        }

        $list = $store->dropped_list(Queue_Store::DROPPED_MAX);

        if ($list['count'] < 1) {
            return null;
        }

        return $list;
    }

    /**
     * @param list<string> $urls Empty clears all dropped rows.
     */
    public static function clear_dropped(array $urls = []): bool
    {
        $store = self::store();

        if ($store === null) {
            return false;
        }

        return $store->clear_dropped(self::normalize_urls($urls));
    }

    /**
     * @return array{readable: bool, pending: int, inflight: int, dropped: int, earliest_due: ?int, oldest_claim: ?int, scheduled: int|false}
     */
    public static function health_snapshot(): array
    {
        $store = self::store();
        $scheduled = wp_next_scheduled(self::HOOK);

        if ($store === null) {
            return [
                'readable'     => false,
                'pending'      => 0,
                'inflight'     => 0,
                'dropped'      => 0,
                'earliest_due' => null,
                'oldest_claim' => null,
                'scheduled'    => $scheduled,
            ];
        }

        $stats = $store->stats(time());

        return [
            'readable'     => !empty($stats['ok']),
            'pending'      => (int) $stats['pending'],
            'inflight'     => (int) $stats['inflight'],
            'dropped'      => (int) ($stats['dropped'] ?? 0),
            'earliest_due' => $stats['earliest_due'],
            'oldest_claim' => $stats['oldest_claim'],
            'scheduled'    => $scheduled,
        ];
    }

    public static function stale_seconds(): int
    {
        return self::STALE_SECONDS;
    }

    public static function stall_grace_seconds(): int
    {
        return self::STALL_GRACE;
    }

    /**
     * @param list<string> $urls
     */
    private static function record_give_up_notice(bool $permanent, array $urls): void
    {
        $batch_count = count($urls);
        $dropped = self::get_dropped();
        $total = is_array($dropped) ? (int) ($dropped['count'] ?? $batch_count) : $batch_count;
        $recoverable = is_array($dropped) ? count($dropped['urls']) : $batch_count;

        $message = $permanent
            ? sprintf(
                /* translators: 1: URLs in this give-up batch, 2: total dropped count, 3: recoverable stored */
                __('Auto-purge stopped: permanent Cloudflare API error (check token/zone permissions). %1$d URL(s) in this batch; %2$d total dropped (%3$d recoverable).', 'devart-wpcloudflare'),
                $batch_count,
                $total,
                $recoverable
            )
            : sprintf(
                /* translators: 1: max attempts, 2: URLs in this give-up batch, 3: total dropped, 4: recoverable */
                __('Auto-purge retry gave up after %1$d attempts. %2$d URL(s) in this batch; %3$d total dropped (%4$d recoverable).', 'devart-wpcloudflare'),
                self::MAX_ATTEMPTS,
                $batch_count,
                $total,
                $recoverable
            );

        Results::set_active_failure($message, 'auto_retry');
    }

    /**
     * Delay before the next retry for a given attempt count.
     *
     * Exponential base (60s…900s). Cloudflare Retry-After is never shortened.
     */
    public static function backoff_seconds(int $attempts, int $retry_after = 0): int
    {
        $attempts = max(0, $attempts);
        $exponential = (int) min(900, 60 * (2 ** min($attempts, 4)));

        return max(15, $exponential, max(0, $retry_after));
    }

    public static function pending_count(): int
    {
        $store = self::store();

        if ($store === null) {
            return 0;
        }

        return (int) $store->stats(time())['pending'];
    }

    /**
     * @return array{count: int, attempts: int}
     */
    public static function pending_status(): array
    {
        $store = self::store();

        if ($store === null) {
            return ['count' => 0, 'attempts' => 0];
        }

        $stats = $store->stats(time());

        return [
            'count'    => (int) $stats['pending'],
            'attempts' => (int) $stats['max_attempts'],
        ];
    }

    public static function clear(): void
    {
        $store = self::store();

        if ($store !== null) {
            $store->clear();
        }

        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Schedule the retry worker for the earliest due work.
     *
     * Never postpones an already-scheduled earlier run. May pull an event forward.
     */
    public static function ensure_scheduled(int $delay_seconds = 60): void
    {
        $store = self::store();

        if ($store === null) {
            return;
        }

        $stats = $store->stats(time());

        // Unreadable stats must not look like an empty queue: keep a worker scheduled.
        if (empty($stats['ok'])) {
            $delay_seconds = max(15, min(self::SCHEDULE_MAX, $delay_seconds > 0 ? $delay_seconds : 60));
            self::schedule_at(time() + $delay_seconds);

            return;
        }

        Stats::note_queue_depth((int) $stats['pending']);

        $has_pending = (int) $stats['pending'] > 0;
        $has_inflight = (int) $stats['inflight'] > 0;

        if (!$has_pending && !$has_inflight) {
            return;
        }

        if ($has_pending) {
            $earliest = $stats['earliest_due'];
            $delay_seconds = $earliest !== null
                ? max(0, (int) $earliest - time())
                : $delay_seconds;
        } elseif ($has_inflight) {
            $oldest = $stats['oldest_claim'];
            $delay_seconds = $oldest !== null
                ? max(15, ($oldest + self::STALE_SECONDS) - time())
                : 15;
        } else {
            $delay_seconds = 15;
        }

        $delay_seconds = max(15, min(self::SCHEDULE_MAX, $delay_seconds));
        self::schedule_at(time() + $delay_seconds);
    }

    /**
     * Schedule HOOK at $desired unless an earlier run is already booked.
     */
    private static function schedule_at(int $desired): void
    {
        $next = wp_next_scheduled(self::HOOK);

        if ($next !== false) {
            if ($next <= $desired) {
                return;
            }

            wp_unschedule_event($next, self::HOOK);
        }

        $scheduled = wp_schedule_single_event($desired, self::HOOK);

        if ($scheduled === false) {
            Results::store([
                'success' => false,
                'message' => __('Pending purge URLs exist but WP-Cron could not schedule a retry worker.', 'devart-wpcloudflare'),
                'context' => 'auto_retry',
            ]);
        }
    }

    public static function is_available(): bool
    {
        if (self::$store !== null) {
            return self::$store->available();
        }

        return self::store() !== null;
    }

    private static function store(): ?Queue_Store
    {
        if (self::$store !== null) {
            return self::$store->available() ? self::$store : null;
        }

        if (self::$unavailable) {
            return null;
        }

        try {
            if (!Queue_Schema::ensure()) {
                self::$unavailable = true;

                return null;
            }

            self::$store = new Queue_Table_Store(null, true);

            return self::$store;
        } catch (\Throwable $e) {
            unset($e);
            self::$unavailable = true;

            return null;
        }
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private static function normalize_urls(array $urls): array
    {
        $out = [];

        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }

            $url = esc_url_raw(trim($url));

            if ($url !== '') {
                $out[$url] = $url;
            }
        }

        return array_values($out);
    }
}

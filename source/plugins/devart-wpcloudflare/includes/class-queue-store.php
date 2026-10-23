<?php
/**
 * Durable purge-queue storage seam (SQL or in-memory for tests).
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

interface Queue_Store
{
    public const DROPPED_MAX = 2000;

    /**
     * @param list<string> $urls
     */
    public function add(array $urls): bool;

    /**
     * @return list<array{url: string, attempts: int}>
     */
    public function claim(int $limit, string $claim_id, int $now, int $stale_seconds): array;

    /**
     * @return array<string, int>
     */
    public function claimed(string $claim_id): array;

    /**
     * @param array<string, array{attempts: int, next_attempt_at: int}> $retry
     * @param list<string>                                               $drop
     */
    public function release(string $claim_id, array $retry, array $drop = [], int $dirty_next_floor = 0): bool;

    /**
     * @return array{
     *   ok: bool,
     *   pending: int,
     *   inflight: int,
     *   dropped: int,
     *   max_attempts: int,
     *   earliest_due: ?int,
     *   oldest_claim: ?int
     * } `ok` is false when the store could not be read (not the same as an empty queue).
     */
    public function stats(int $now): array;

    /**
     * @param list<string> $urls
     */
    public function mark_dropped(array $urls): bool;

    /**
     * @return array{urls: list<string>, count: int, time: int, truncated: bool}
     */
    public function dropped_list(int $limit = self::DROPPED_MAX): array;

    /**
     * @param list<string> $urls
     */
    public function clear_dropped(array $urls = []): bool;

    public function clear(): bool;

    public function available(): bool;
}

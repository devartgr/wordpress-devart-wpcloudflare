<?php
/**
 * MySQL-backed purge queue store.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Queue_Table_Store implements Queue_Store
{
    private string $table;

    /** @var bool|null */
    private ?bool $available = null;

    public function __construct(?string $table = null, bool $known_available = false)
    {
        $this->table = $table ?? Queue_Schema::table_name();

        if ($known_available) {
            $this->available = true;
        }
    }

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        global $wpdb;

        $this->available = isset($wpdb) && is_object($wpdb) && Queue_Schema::table_exists($this->table);

        return $this->available;
    }

    public function add(array $urls): bool
    {
        global $wpdb;

        if ($urls === [] || !$this->available()) {
            return $urls === [];
        }

        $placeholders = [];
        $params = [];

        foreach ($urls as $url) {
            $placeholders[] = '(%s,%s,0,0,NULL,0,0,0)';
            $params[] = sha1($url);
            $params[] = $url;
        }

        // dropped=0 LAST so IF(dropped=1, ...) still sees the old value.
        $sql = 'INSERT INTO `' . $this->escaped_table() . '` '
            . '(url_hash,url,attempts,next_attempt_at,claim_id,claimed_at,dirty,dropped) VALUES '
            . implode(',', $placeholders)
            . ' ON DUPLICATE KEY UPDATE '
            . 'attempts = IF(dropped = 1, 0, attempts), '
            . 'next_attempt_at = IF(dropped = 1, 0, next_attempt_at), '
            . 'claimed_at = IF(dropped = 1, 0, claimed_at), '
            . 'dirty = IF(claim_id IS NULL, 0, 1), '
            . 'dropped = 0';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->query($wpdb->prepare($sql, $params)) !== false;
    }

    public function claim(int $limit, string $claim_id, int $now, int $stale_seconds): array
    {
        global $wpdb;

        $limit = max(1, $limit);
        $claim_id = substr($claim_id, 0, 16);

        if ($claim_id === '' || !$this->available()) {
            return [];
        }

        $stale_before = $now - max(1, $stale_seconds);
        $table = $this->escaped_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET claim_id = %s, claimed_at = %d, dirty = 0
				WHERE dropped = 0 AND (claim_id IS NULL OR claimed_at < %d) AND next_attempt_at <= %d
				ORDER BY id ASC LIMIT %d",
                $claim_id,
                $now,
                $stale_before,
                $now,
                $limit
            )
        );

        if ($updated === false || (int) $updated < 1) {
            return [];
        }

        return $this->select_claim_rows($claim_id);
    }

    public function claimed(string $claim_id): array
    {
        $claim_id = substr($claim_id, 0, 16);

        if ($claim_id === '' || !$this->available()) {
            return [];
        }

        $out = [];

        foreach ($this->select_claim_rows($claim_id) as $row) {
            $out[$row['url']] = $row['attempts'];
        }

        return $out;
    }

    public function release(string $claim_id, array $retry, array $drop = [], int $dirty_next_floor = 0): bool
    {
        global $wpdb;

        $claim_id = substr($claim_id, 0, 16);

        if ($claim_id === '' || !$this->available()) {
            return false;
        }

        $table = $this->escaped_table();
        $dirty_next_floor = max(0, $dirty_next_floor);
        $groups = [];

        foreach ($retry as $url => $meta) {
            if (!is_string($url) || $url === '' || !is_array($meta)) {
                continue;
            }

            $attempts = (int) ($meta['attempts'] ?? 0);
            $next_at = (int) ($meta['next_attempt_at'] ?? 0);
            $groups[$attempts . ':' . $next_at][] = sha1($url);
        }

        foreach ($groups as $key => $hashes) {
            [$attempts, $next_at] = array_map('intval', explode(':', $key, 2));
            $hashes = array_values(array_unique($hashes));

            if ($hashes === []) {
                continue;
            }

            $params = array_merge([$attempts, $next_at, $claim_id], $hashes);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $ok = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}`
					SET attempts = %d, next_attempt_at = %d, claim_id = NULL, claimed_at = 0, dirty = 0, dropped = 0
					WHERE claim_id = %s AND dirty = 0 AND url_hash IN (" . $this->in_clause(count($hashes)) . ')',
                    $params
                )
            );

            if ($ok === false) {
                return false;
            }
        }

        $drop_hashes = $this->hashes($drop);

        if ($drop_hashes !== []) {
            $now = time();
            $params = array_merge([$now, $claim_id], $drop_hashes);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $ok = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}`
					SET dropped = 1, claim_id = NULL, claimed_at = %d, dirty = 0
					WHERE claim_id = %s AND dirty = 0 AND url_hash IN (" . $this->in_clause(count($drop_hashes)) . ')',
                    $params
                )
            );

            if ($ok === false) {
                return false;
            }

            $this->trim_dropped();
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE claim_id = %s AND dirty = 0", $claim_id)) === false) {
            return false;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
				SET claim_id = NULL, claimed_at = 0, dirty = 0, dropped = 0, attempts = 0,
					next_attempt_at = GREATEST(%d, next_attempt_at)
				WHERE claim_id = %s",
                $dirty_next_floor,
                $claim_id
            )
        ) !== false;
    }

    public function stats(int $now): array
    {
        global $wpdb;

        unset($now);

        if (!$this->available()) {
            return self::empty_stats(false);
        }

        $table = $this->escaped_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            "SELECT
				SUM(CASE WHEN dropped = 0 AND claim_id IS NULL THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN dropped = 0 AND claim_id IS NOT NULL THEN 1 ELSE 0 END) AS inflight,
				SUM(CASE WHEN dropped = 1 THEN 1 ELSE 0 END) AS dropped,
				MAX(CASE WHEN dropped = 0 AND claim_id IS NULL THEN attempts ELSE 0 END) AS max_attempts,
				MIN(CASE WHEN dropped = 0 AND claim_id IS NULL THEN next_attempt_at ELSE NULL END) AS earliest_due,
				MIN(CASE WHEN dropped = 0 AND claim_id IS NOT NULL THEN claimed_at ELSE NULL END) AS oldest_claim
			FROM `{$table}`",
            ARRAY_A
        );

        // Aggregate SELECT always returns a row when the query succeeds (empty table → NULLs).
        // null means failure (see wpdb::get_row).
        if (!is_array($row)) {
            return self::empty_stats(false);
        }

        return [
            'ok'           => true,
            'pending'      => (int) ($row['pending'] ?? 0),
            'inflight'     => (int) ($row['inflight'] ?? 0),
            'dropped'      => (int) ($row['dropped'] ?? 0),
            'max_attempts' => (int) ($row['max_attempts'] ?? 0),
            'earliest_due' => isset($row['earliest_due']) && $row['earliest_due'] !== null ? (int) $row['earliest_due'] : null,
            'oldest_claim' => isset($row['oldest_claim']) && $row['oldest_claim'] !== null ? (int) $row['oldest_claim'] : null,
        ];
    }

    public function mark_dropped(array $urls): bool
    {
        global $wpdb;

        if ($urls === [] || !$this->available()) {
            return $urls === [];
        }

        if (!$this->add($urls)) {
            return false;
        }

        $hashes = $this->hashes($urls);

        if ($hashes === []) {
            return true;
        }

        $now = time();
        $params = array_merge([$now], $hashes);
        $table = $this->escaped_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $ok = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
				SET dropped = 1, claim_id = NULL, claimed_at = %d, dirty = 0
				WHERE url_hash IN (" . $this->in_clause(count($hashes)) . ')',
                $params
            )
        );

        if ($ok === false) {
            return false;
        }

        $this->trim_dropped();

        return true;
    }

    public function dropped_list(int $limit = self::DROPPED_MAX): array
    {
        global $wpdb;

        $limit = max(1, $limit);
        $empty = ['urls' => [], 'count' => 0, 'time' => 0, 'truncated' => false];

        if (!$this->available()) {
            return $empty;
        }

        $table = $this->escaped_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE dropped = 1");

        if ($count < 1) {
            return $empty;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT url FROM `{$table}` WHERE dropped = 1 ORDER BY id DESC LIMIT %d",
                $limit
            )
        );
        $urls = [];

        if (is_array($rows)) {
            foreach ($rows as $url) {
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $time = (int) $wpdb->get_var("SELECT MAX(claimed_at) FROM `{$table}` WHERE dropped = 1");

        return [
            'urls'      => $urls,
            'count'     => $count,
            'time'      => $time,
            'truncated' => $count > count($urls),
        ];
    }

    public function clear_dropped(array $urls = []): bool
    {
        global $wpdb;

        if (!$this->available()) {
            return true;
        }

        $table = $this->escaped_table();

        if ($urls === []) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return $wpdb->query("DELETE FROM `{$table}` WHERE dropped = 1") !== false;
        }

        $hashes = $this->hashes($urls);

        if ($hashes === []) {
            return true;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE dropped = 1 AND url_hash IN (" . $this->in_clause(count($hashes)) . ')',
                $hashes
            )
        ) !== false;
    }

    public function clear(): bool
    {
        global $wpdb;

        if (!$this->available()) {
            return true;
        }

        $table = $this->escaped_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->query("TRUNCATE TABLE `{$table}`") !== false;
    }

    /**
     * @return array{ok: bool, pending: int, inflight: int, dropped: int, max_attempts: int, earliest_due: null, oldest_claim: null}
     */
    private static function empty_stats(bool $ok = true): array
    {
        return [
            'ok'           => $ok,
            'pending'      => 0,
            'inflight'     => 0,
            'dropped'      => 0,
            'max_attempts' => 0,
            'earliest_due' => null,
            'oldest_claim' => null,
        ];
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function hashes(array $urls): array
    {
        $out = [];

        foreach ($urls as $url) {
            if (is_string($url) && $url !== '') {
                $out[sha1($url)] = sha1($url);
            }
        }

        return array_values($out);
    }

    private function in_clause(int $count): string
    {
        return implode(',', array_fill(0, max(0, $count), '%s'));
    }

    private function trim_dropped(): void
    {
        global $wpdb;

        $max = self::DROPPED_MAX;
        $table = $this->escaped_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE dropped = 1");

        if ($count <= $max) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE dropped = 1 ORDER BY id ASC LIMIT %d",
                $count - $max
            )
        );
    }

    /**
     * @return list<array{url: string, attempts: int}>
     */
    private function select_claim_rows(string $claim_id): array
    {
        global $wpdb;

        $table = $this->escaped_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT url, attempts FROM `{$table}` WHERE claim_id = %s", $claim_id),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['url']) || !is_string($row['url'])) {
                continue;
            }

            $out[] = [
                'url'      => $row['url'],
                'attempts' => (int) ($row['attempts'] ?? 0),
            ];
        }

        return $out;
    }

    private function escaped_table(): string
    {
        return str_replace('`', '``', $this->table);
    }
}

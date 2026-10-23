<?php
/**
 * Custom table schema for the purge queue.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Queue_Schema
{
    public const DB_VERSION = 2;
    public const DB_VERSION_OPTION = 'devart_wpcloudflare_db_version';
    public const TABLE_SUFFIX = 'devart_cf_queue';

    /** @var list<string> */
    private const REQUIRED_COLUMNS = [
        'id',
        'url_hash',
        'url',
        'attempts',
        'next_attempt_at',
        'claim_id',
        'claimed_at',
        'dirty',
        'dropped',
    ];

    private static bool $ensured = false;
    private static bool $ok = false;

    public static function table_name(?string $override = null): string
    {
        global $wpdb;

        if ($override !== null && $override !== '') {
            return $override;
        }

        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function ensure(): bool
    {
        if (self::$ensured) {
            return self::$ok;
        }

        self::$ensured = true;

        $current = (int) get_option(self::DB_VERSION_OPTION, 0);
        $table = self::table_name();

        if ($current >= self::DB_VERSION && self::table_exists($table) && self::has_required_columns($table)) {
            self::$ok = true;

            return true;
        }

        if (!self::install($table) || !self::has_required_columns($table)) {
            self::$ok = false;

            return false;
        }

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, true);
        self::$ok = true;

        return true;
    }

    public static function install(string $table): bool
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || $table === '') {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(40) NOT NULL,
			url text NOT NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			next_attempt_at int(10) unsigned NOT NULL DEFAULT 0,
			claim_id char(16) DEFAULT NULL,
			claimed_at int(10) unsigned NOT NULL DEFAULT 0,
			dirty tinyint(1) NOT NULL DEFAULT 0,
			dropped tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY claim_due (claim_id,next_attempt_at),
			KEY dropped_id (dropped,id)
		) {$charset};";

        dbDelta($sql);

        return self::table_exists($table);
    }

    public static function drop(string $table): void
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || $table === '') {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
    }

    public static function table_exists(string $table): bool
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || $table === '') {
            return false;
        }

        $like = $wpdb->esc_like($table);
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));

        return is_string($found) && $found === $table;
    }

    public static function has_required_columns(string $table): bool
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || $table === '') {
            return false;
        }

        $escaped = str_replace('`', '``', $table);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $columns = $wpdb->get_col("DESCRIBE `{$escaped}`");

        if (!is_array($columns)) {
            return false;
        }

        foreach (self::REQUIRED_COLUMNS as $needed) {
            if (!in_array($needed, $columns, true)) {
                return false;
            }
        }

        return true;
    }

    public static function reset_memo(): void
    {
        self::$ensured = false;
        self::$ok = false;
    }
}

<?php
/**
 * Uninstall cleanup for DevArt WordPress Cloudflare.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('devart_wpcloudflare_settings');
delete_option('devart_wpcloudflare_last_result');
delete_option('devart_wpcloudflare_last_error');
delete_option('devart_wpcloudflare_active_failure');
delete_option('devart_wpcloudflare_dropped');
delete_option('devart_wpcloudflare_retry');
delete_option('devart_wpcloudflare_db_version');
delete_option('devart_wpcloudflare_stats');

global $wpdb;

if (isset($wpdb) && is_object($wpdb)) {
    $table = $wpdb->prefix . 'devart_cf_queue';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall drop of known plugin table.
    $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

wp_clear_scheduled_hook('devart_wpcloudflare_scheduled_purge');
wp_clear_scheduled_hook('devart_wpcloudflare_retry');

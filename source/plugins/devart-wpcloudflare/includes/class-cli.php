<?php
/**
 * WP-CLI commands: wp devart-cf status|purge|retry-dropped
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Cli
{
    public static function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('\WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('devart-cf', self::class);
    }

    /**
     * Show queue and credential status (no secrets).
     *
     * ## EXAMPLES
     *
     *     wp devart-cf status
     *
     * @when after_wp_load
     */
    public function status(array $args, array $assoc_args): void
    {
        unset($args, $assoc_args);

        $pending = Purge_Queue::pending_status();
        $dropped = Purge_Queue::get_dropped();
        $active = Results::active_failure();
        $stats = Stats::snapshot();
        $last = Results::last();

        \WP_CLI::line('version: ' . (defined('DEVART_WPCLOUDFLARE_VERSION') ? DEVART_WPCLOUDFLARE_VERSION : ''));
        \WP_CLI::line('credentials: ' . (Options::has_credentials() ? 'yes' : 'no'));
        \WP_CLI::line('token_source: ' . (Options::uses_config_token() ? 'wp-config' : (Options::has_credentials() ? 'stored' : 'none')));
        \WP_CLI::line('queue_table: ' . (Purge_Queue::is_available() ? 'available' : 'unavailable'));
        \WP_CLI::line('pending: ' . $pending['count']);
        \WP_CLI::line('pending_max_attempts: ' . $pending['attempts']);
        \WP_CLI::line('dropped_recoverable: ' . (is_array($dropped) ? count($dropped['urls']) : 0));
        \WP_CLI::line('dropped_total: ' . (is_array($dropped) ? (int) $dropped['count'] : 0));
        \WP_CLI::line('active_failure: ' . ($active !== null ? 'yes' : 'no'));
        \WP_CLI::line('purged_urls_today_utc: ' . $stats['purged_urls']);
        \WP_CLI::line('failures_today_utc: ' . $stats['failures']);
        \WP_CLI::line('max_pending_today_utc: ' . $stats['max_pending']);
        \WP_CLI::line('last_result: ' . ($last['message'] ?? '(none)'));
    }

    /**
     * Purge Cloudflare cache by URL or entire zone.
     *
     * ## OPTIONS
     *
     * [--url=<url>]
     * : Absolute same-site URL to purge.
     *
     * [--everything]
     * : Purge the entire zone (requires --yes).
     *
     * [--yes]
     * : Confirm a destructive --everything purge.
     *
     * ## EXAMPLES
     *
     *     wp devart-cf purge --url=https://example.com/
     *     wp devart-cf purge --everything --yes
     *
     * @when after_wp_load
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc_args
     */
    public function purge(array $args, array $assoc_args): void
    {
        unset($args);

        if (!Options::has_credentials()) {
            \WP_CLI::error('Cloudflare credentials are not configured.');
        }

        $everything = isset($assoc_args['everything']);
        $url = isset($assoc_args['url']) ? (string) $assoc_args['url'] : '';

        if ($everything) {
            \WP_CLI::confirm('Purge the entire Cloudflare zone?', $assoc_args);
            $result = Purge_Service::purge_everything('cli');
        } elseif ($url !== '') {
            $result = Purge_Service::purge_urls([$url], 'cli');
        } else {
            \WP_CLI::error('Provide --url=<url> or --everything --yes.');
        }

        if (!empty($result['success'])) {
            \WP_CLI::success((string) ($result['message'] ?? 'Purge accepted.'));

            return;
        }

        \WP_CLI::error((string) ($result['message'] ?? 'Purge failed.'));
    }

    /**
     * Re-queue recoverable dropped URLs.
     *
     * ## EXAMPLES
     *
     *     wp devart-cf retry-dropped
     *
     * @when after_wp_load
     */
    public function retry_dropped(array $args, array $assoc_args): void
    {
        unset($args, $assoc_args);

        $dropped = Purge_Queue::get_dropped();
        $urls = is_array($dropped) ? $dropped['urls'] : [];

        if ($urls === []) {
            Purge_Queue::clear_dropped();
            Results::clear_active_failure();
            \WP_CLI::success('No dropped URLs to retry.');

            return;
        }

        if (!Purge_Queue::enqueue($urls)) {
            \WP_CLI::error('Could not queue dropped URLs; the dropped list was left unchanged.');
        }

        if (Purge_Queue::get_dropped() === null) {
            Results::clear_active_failure();
        }

        \WP_CLI::success(sprintf('Queued %d dropped URL(s).', count($urls)));
    }
}

<?php
/**
 * Site Health test and Info debug section (no secrets).
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Site_Health
{
    public static function register(): void
    {
        add_filter('site_status_tests', [self::class, 'register_tests']);
        add_filter('debug_information', [self::class, 'debug_information']);
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $tests
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function register_tests(array $tests): array
    {
        $tests['direct']['devart_wpcloudflare_queue'] = [
            'label' => __('DevArt Cloudflare purge queue', 'devart-wpcloudflare'),
            'test'  => [self::class, 'test_queue'],
        ];

        return $tests;
    }

    /**
     * @return array<string, mixed>
     */
    public static function test_queue(): array
    {
        $result = [
            'label'       => __('DevArt Cloudflare purge queue is healthy', 'devart-wpcloudflare'),
            'status'      => 'good',
            'badge'       => [
                'label' => __('DevArt Cloudflare', 'devart-wpcloudflare'),
                'color' => 'blue',
            ],
            'description' => '<p>' . esc_html__('Credentials and the durable purge queue look ready.', 'devart-wpcloudflare') . '</p>',
            'actions'     => '',
            'test'        => 'devart_wpcloudflare_queue',
        ];

        if (!Options::has_credentials()) {
            $result['status'] = 'recommended';
            $result['label'] = __('DevArt Cloudflare is not fully configured', 'devart-wpcloudflare');
            $result['description'] = '<p>' . esc_html__('Add a Zone ID and API token (or DEVART_CF_API_TOKEN) under Settings → DevArt Cloudflare.', 'devart-wpcloudflare') . '</p>';
            $result['actions'] = self::settings_action();

            return $result;
        }

        if (!Purge_Queue::is_available()) {
            $result['status'] = 'critical';
            $result['badge']['color'] = 'red';
            $result['label'] = __('DevArt Cloudflare queue table is unavailable', 'devart-wpcloudflare');
            $result['description'] = '<p>' . esc_html__('The durable queue table could not be created. Auto-purge falls back to one-shot sends without retry.', 'devart-wpcloudflare') . '</p>';
            $result['actions'] = self::settings_action();

            return $result;
        }

        $dropped = Purge_Queue::get_dropped();
        $active = Results::active_failure();
        $has_dropped = is_array($dropped) && $dropped['count'] > 0;
        $has_active = $active !== null && $active['message'] !== '';

        if ($has_active || $has_dropped) {
            $result['status'] = 'critical';
            $result['badge']['color'] = 'red';
            $result['label'] = __('DevArt Cloudflare has unresolved purge failures', 'devart-wpcloudflare');
            $parts = [];

            if ($has_active) {
                $parts[] = esc_html((string) $active['message']);
            }

            if ($has_dropped) {
                $parts[] = esc_html(
                    sprintf(
                        /* translators: %d: recoverable dropped URL count */
                        __('%d dropped URL(s) await Retry dropped URLs.', 'devart-wpcloudflare'),
                        count($dropped['urls'])
                    )
                );
            }

            $result['description'] = '<p>' . implode('</p><p>', $parts) . '</p>';
            $result['actions'] = self::settings_action();

            return $result;
        }

        $health = Purge_Queue::health_snapshot();

        if (!$health['readable']) {
            $result['status'] = 'recommended';
            $result['badge']['color'] = 'orange';
            $result['label'] = __('DevArt Cloudflare could not read the purge queue', 'devart-wpcloudflare');
            $result['description'] = '<p>' . esc_html__(
                'The queue table is present but its status could not be read. A retry worker is still scheduled conservatively when possible. Check the database connection and table health.',
                'devart-wpcloudflare'
            ) . '</p>';
            $result['actions'] = self::settings_action();

            return $result;
        }

        $stall = self::stall_message();

        if ($stall !== null) {
            $result['status'] = 'recommended';
            $result['badge']['color'] = 'orange';
            $result['label'] = __('DevArt Cloudflare queue may be stalled', 'devart-wpcloudflare');
            $result['description'] = '<p>' . esc_html($stall) . '</p>';
            $result['actions'] = self::settings_action();

            return $result;
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $info
     * @return array<string, array<string, mixed>>
     */
    public static function debug_information(array $info): array
    {
        $pending = Purge_Queue::pending_status();
        $health = Purge_Queue::health_snapshot();
        $stats = Stats::snapshot();
        $dropped = Purge_Queue::get_dropped();

        $info['devart-wpcloudflare'] = [
            'label'       => __('DevArt WordPress Cloudflare', 'devart-wpcloudflare'),
            'description' => __('Operational status only. API tokens and Zone secrets are never included.', 'devart-wpcloudflare'),
            'fields'      => [
                'version' => [
                    'label' => __('Plugin version', 'devart-wpcloudflare'),
                    'value' => defined('DEVART_WPCLOUDFLARE_VERSION') ? DEVART_WPCLOUDFLARE_VERSION : '',
                ],
                'credentials' => [
                    'label' => __('Credentials', 'devart-wpcloudflare'),
                    'value' => Options::has_credentials()
                        ? (Options::uses_config_token()
                            ? __('Configured (wp-config token)', 'devart-wpcloudflare')
                            : __('Configured (stored token)', 'devart-wpcloudflare'))
                        : __('Not configured', 'devart-wpcloudflare'),
                ],
                'queue_table' => [
                    'label' => __('Queue table', 'devart-wpcloudflare'),
                    'value' => Purge_Queue::is_available()
                        ? __('Available', 'devart-wpcloudflare')
                        : __('Unavailable', 'devart-wpcloudflare'),
                ],
                'queue_readable' => [
                    'label' => __('Queue status readable', 'devart-wpcloudflare'),
                    'value' => !empty($health['readable'])
                        ? __('Yes', 'devart-wpcloudflare')
                        : __('No', 'devart-wpcloudflare'),
                ],
                'pending' => [
                    'label' => __('Pending URLs', 'devart-wpcloudflare'),
                    'value' => (string) $pending['count'],
                ],
                'inflight' => [
                    'label' => __('In-flight URLs', 'devart-wpcloudflare'),
                    'value' => (string) $health['inflight'],
                ],
                'retry_scheduled' => [
                    'label' => __('Retry worker scheduled', 'devart-wpcloudflare'),
                    'value' => $health['scheduled'] === false
                        ? __('No', 'devart-wpcloudflare')
                        : wp_date('c', (int) $health['scheduled']),
                ],
                'dropped' => [
                    'label' => __('Dropped URLs (recoverable)', 'devart-wpcloudflare'),
                    'value' => is_array($dropped) ? (string) count($dropped['urls']) : '0',
                ],
                'purged_today' => [
                    'label' => __('URLs purged today (UTC)', 'devart-wpcloudflare'),
                    'value' => (string) $stats['purged_urls'],
                ],
                'failures_today' => [
                    'label' => __('Purge failures today (UTC)', 'devart-wpcloudflare'),
                    'value' => (string) $stats['failures'],
                ],
                'max_pending_today' => [
                    'label' => __('Max pending depth today (UTC)', 'devart-wpcloudflare'),
                    'value' => (string) $stats['max_pending'],
                ],
            ],
        ];

        return $info;
    }

    private static function stall_message(): ?string
    {
        $h = Purge_Queue::health_snapshot();
        $now = time();
        $grace = Purge_Queue::stall_grace_seconds();
        $stale = Purge_Queue::stale_seconds();

        if ($h['pending'] > 0 && $h['scheduled'] === false) {
            $due = $h['earliest_due'];

            if ($due === null || $due <= ($now - $grace)) {
                return __('Pending purge URLs exist but no retry worker is scheduled.', 'devart-wpcloudflare');
            }
        }

        if ($h['inflight'] > 0 && $h['oldest_claim'] !== null) {
            $age = $now - (int) $h['oldest_claim'];

            if ($age > ($stale + $grace)) {
                return __('An in-flight purge claim is older than the stale recovery window.', 'devart-wpcloudflare');
            }
        }

        if ($h['pending'] > 0 && $h['earliest_due'] !== null && $h['scheduled'] !== false) {
            $overdue = $now - (int) $h['earliest_due'];
            $scheduled = (int) $h['scheduled'];
            $worker_late = $now - $scheduled;

            if ($overdue > $grace && $scheduled > $now + 60) {
                return __('Due purge URLs are waiting while the next worker is scheduled far in the future.', 'devart-wpcloudflare');
            }

            if ($overdue > $grace && $worker_late > $grace) {
                return __(
                    'Due purge URLs are waiting and the scheduled retry worker is overdue. Behind Cloudflare, use system cron (for example: wp cron event run --due-now) and set DISABLE_WP_CRON in wp-config.php.',
                    'devart-wpcloudflare'
                );
            }
        }

        return null;
    }

    private static function settings_action(): string
    {
        $url = admin_url('options-general.php?page=devart-wpcloudflare');

        return sprintf(
            '<p><a href="%s">%s</a></p>',
            esc_url($url),
            esc_html__('Open DevArt Cloudflare settings', 'devart-wpcloudflare')
        );
    }
}

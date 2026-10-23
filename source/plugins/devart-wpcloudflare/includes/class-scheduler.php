<?php
/**
 * WP-Cron scheduled Cloudflare purge.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Scheduler
{
    public const HOOK = 'devart_wpcloudflare_scheduled_purge';

    public static function register(): void
    {
        add_filter('cron_schedules', [self::class, 'register_schedules']);
        add_action(self::HOOK, [self::class, 'run']);
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function register_schedules(array $schedules): array
    {
        $schedules['devart_cf_15min'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __('Every 15 minutes (DevArt Cloudflare)', 'devart-wpcloudflare'),
        ];
        $schedules['devart_cf_30min'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => __('Every 30 minutes (DevArt Cloudflare)', 'devart-wpcloudflare'),
        ];
        $schedules['devart_cf_2hours'] = [
            'interval' => 2 * HOUR_IN_SECONDS,
            'display'  => __('Every 2 hours (DevArt Cloudflare)', 'devart-wpcloudflare'),
        ];

        return $schedules;
    }

    public static function activate(): void
    {
        self::resync();
    }

    public static function deactivate(): void
    {
        self::clear();
        Auto_Purge::clear_retry();
    }

    public static function resync(): void
    {
        $settings = Options::get();

        $should_schedule = $settings['schedule_enabled']
            && Options::has_credentials()
            && !(
                $settings['schedule_mode'] === Options::MODE_URLS
                && Options::schedule_urls() === []
            );

        if (!$should_schedule) {
            self::clear();

            return;
        }

        $recurrence = $settings['schedule_recurrence'];
        $event = function_exists('wp_get_scheduled_event')
            ? wp_get_scheduled_event(self::HOOK)
            : false;

        if (
            is_object($event)
            && isset($event->schedule)
            && is_string($event->schedule)
            && $event->schedule === $recurrence
        ) {
            return;
        }

        self::clear();
        $scheduled = wp_schedule_event(time() + MINUTE_IN_SECONDS, $recurrence, self::HOOK);

        if ($scheduled === false) {
            Results::store([
                'success' => false,
                'message' => __('Scheduled purge could not be registered with WP-Cron.', 'devart-wpcloudflare'),
                'context' => 'settings',
            ]);
        }
    }

    public static function clear(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run(): void
    {
        $settings = Options::get();

        if (!$settings['schedule_enabled'] || !Options::has_credentials()) {
            return;
        }

        if ($settings['schedule_mode'] === Options::MODE_EVERYTHING) {
            Purge_Service::purge_everything('scheduled');

            return;
        }

        $urls = Options::schedule_urls();

        if ($urls === []) {
            return;
        }

        Purge_Service::purge_urls($urls, 'scheduled', 20);
    }

    public static function next_run_label(): string
    {
        $timestamp = wp_next_scheduled(self::HOOK);

        if ($timestamp === false) {
            return __('Not scheduled.', 'devart-wpcloudflare');
        }

        return sprintf(
            /* translators: %s: localized date/time */
            __('Next run: %s', 'devart-wpcloudflare'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)
        );
    }
}

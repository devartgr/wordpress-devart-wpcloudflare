<?php
/**
 * Plugin bootstrap.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('init', [self::class, 'load_textdomain']);
        add_action('update_option_' . Options::OPTION_KEY, [Options::class, 'flush_memo']);
        add_action('delete_option_' . Options::OPTION_KEY, [Options::class, 'flush_memo']);

        Admin::register();
        Auto_Purge::register();
        Scheduler::register();
        Site_Health::register();
        Cli::register();
    }

    public static function load_textdomain(): void
    {
        load_plugin_textdomain(
            'devart-wpcloudflare',
            false,
            dirname(plugin_basename(DEVART_WPCLOUDFLARE_FILE)) . '/languages'
        );
    }
}

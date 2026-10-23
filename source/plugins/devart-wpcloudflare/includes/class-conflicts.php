<?php
/**
 * Detect other active Cloudflare / page-cache plugins (informational only).
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Conflicts
{
    /**
     * Human-readable names of active plugins that may also purge Cloudflare.
     *
     * @return list<string>
     */
    public static function active_names(): array
    {
        $found = [];

        $by_file = [
            'cloudflare/cloudflare.php'                         => 'Cloudflare',
            'wp-cloudflare-page-cache/wp-cloudflare-super-page-cache.php' => 'Super Page Cache for Cloudflare',
            'wp-cloudflare-super-page-cache/wp-cloudflare-super-page-cache.php' => 'Super Page Cache for Cloudflare',
            'wp-rocket/wp-rocket.php'                           => 'WP Rocket',
            'litespeed-cache/litespeed-cache.php'               => 'LiteSpeed Cache',
            'nginx-helper/nginx-helper.php'                     => 'Nginx Helper',
        ];

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ($by_file as $file => $label) {
            if (is_plugin_active($file)) {
                $found[$label] = $label;
            }
        }

        if (defined('CLOUDFLARE_PLUGIN_DIR') || defined('CLOUDFLARE_VERSION')) {
            $found['Cloudflare'] = 'Cloudflare';
        }

        if (defined('WP_ROCKET_VERSION')) {
            $found['WP Rocket'] = 'WP Rocket';
        }

        if (defined('LSCWP_V') || defined('LITESPEED_ON')) {
            $found['LiteSpeed Cache'] = 'LiteSpeed Cache';
        }

        if (defined('SWCFPC_PLUGIN_FILE') || defined('SWCFPC_VERSION')) {
            $found['Super Page Cache for Cloudflare'] = 'Super Page Cache for Cloudflare';
        }

        return array_values($found);
    }
}

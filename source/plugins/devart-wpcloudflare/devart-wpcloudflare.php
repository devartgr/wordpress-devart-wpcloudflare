<?php
/**
 * Plugin Name:       DevArt WordPress Cloudflare
 * Plugin URI:        https://github.com/devartgr?tab=repositories
 * Description:       Cloudflare cache purge for WordPress: settings, manual purge, auto purge on content change, and scheduled purge.
 * Version:           1.0.0
 * Requires at least: 7.0.0
 * Requires PHP:      8.3.0
 * Author:            Kostas Stathopoulos - DevArt
 * Author URI:        https://github.com/devartgr?tab=repositories
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       devart-wpcloudflare
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('DEVART_WPCLOUDFLARE_VERSION', '1.0.0');
define('DEVART_WPCLOUDFLARE_FILE', __FILE__);
define('DEVART_WPCLOUDFLARE_PATH', plugin_dir_path(__FILE__));
define('DEVART_WPCLOUDFLARE_URL', plugin_dir_url(__FILE__));

require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-secret.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-results.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-url-filter.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-options.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-url-builder.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-cloudflare-api.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-stats.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-purge-service.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-queue-store.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-queue-schema.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-queue-table-store.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-purge-queue.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-auto-purge.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-scheduler.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-conflicts.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-site-health.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-cli.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-admin.php';
require_once DEVART_WPCLOUDFLARE_PATH . 'includes/class-plugin.php';

DevArt\WpCloudflare\Plugin::instance()->boot();

register_activation_hook(__FILE__, static function (): void {
    DevArt\WpCloudflare\Queue_Schema::ensure();
    DevArt\WpCloudflare\Scheduler::activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    DevArt\WpCloudflare\Scheduler::deactivate();
});

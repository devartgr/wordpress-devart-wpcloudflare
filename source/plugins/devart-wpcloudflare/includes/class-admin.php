<?php
/**
 * Settings screen and manual purge actions.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin
{
    public const PAGE_SLUG = 'devart-wpcloudflare';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_post_devart_wpcloudflare_save_settings', [self::class, 'handle_save_settings']);
        add_action('admin_post_devart_wpcloudflare_purge_everything', [self::class, 'handle_purge_everything']);
        add_action('admin_post_devart_wpcloudflare_purge_url', [self::class, 'handle_purge_url']);
        add_action('admin_post_devart_wpcloudflare_test_connection', [self::class, 'handle_test_connection']);
        add_action('admin_post_devart_wpcloudflare_retry_dropped', [self::class, 'handle_retry_dropped']);
        add_action('admin_notices', [self::class, 'maybe_show_notices']);
        add_filter(
            'plugin_action_links_' . plugin_basename(DEVART_WPCLOUDFLARE_FILE),
            [self::class, 'plugin_action_links']
        );
    }

    /**
     * @param list<string> $links
     * @return list<string>
     */
    public static function plugin_action_links(array $links): array
    {
        $url = self::page_url();
        array_unshift(
            $links,
            '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'devart-wpcloudflare') . '</a>'
        );

        return $links;
    }

    public static function register_menu(): void
    {
        add_options_page(
            __('DevArt Cloudflare', 'devart-wpcloudflare'),
            __('DevArt Cloudflare', 'devart-wpcloudflare'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function enqueue_assets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'devart-wpcloudflare-admin',
            DEVART_WPCLOUDFLARE_URL . 'assets/css/admin.css',
            [],
            DEVART_WPCLOUDFLARE_VERSION
        );
    }

    public static function handle_save_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save these settings.', 'devart-wpcloudflare'));
        }

        check_admin_referer('devart_wpcloudflare_save_settings');

        $raw = isset($_POST[Options::OPTION_KEY]) && is_array($_POST[Options::OPTION_KEY])
            ? wp_unslash($_POST[Options::OPTION_KEY])
            : [];

        /** @var array<string, mixed> $raw */
        $zone_raw = trim(Options::scalar_string($raw['zone_id'] ?? null));
        $zone_invalid = $zone_raw !== '' && !Options::is_valid_zone_id_input($zone_raw);

        try {
            $saved = Options::save(Options::sanitize($raw));
        } catch (\RuntimeException $e) {
            Results::store([
                'success' => false,
                'message' => $e->getMessage(),
                'context' => 'settings',
            ]);
            wp_safe_redirect(self::page_url(['settings-error' => 'token_encrypt']));
            exit;
        }

        if (!$saved) {
            wp_safe_redirect(self::page_url(['settings-error' => 'save']));
            exit;
        }

        Scheduler::resync();

        if ($zone_invalid) {
            wp_safe_redirect(self::page_url(['settings-error' => 'zone_id']));
            exit;
        }

        wp_safe_redirect(self::page_url(['settings-updated' => '1']));
        exit;
    }

    public static function handle_purge_everything(): void
    {
        self::assert_can_purge();
        check_admin_referer('devart_wpcloudflare_purge_everything');

        if (empty($_POST['confirm_everything'])) {
            wp_safe_redirect(self::page_url(['settings-error' => 'confirm']));
            exit;
        }

        Purge_Service::purge_everything('manual_everything');

        wp_safe_redirect(self::page_url(['purged' => '1']));
        exit;
    }

    public static function handle_purge_url(): void
    {
        self::assert_can_purge();
        check_admin_referer('devart_wpcloudflare_purge_url');

        $url = '';

        if (isset($_POST['purge_url'])) {
            $url = esc_url_raw(wp_unslash(Options::scalar_string($_POST['purge_url'])));
        }

        if ($url === '' || !wp_http_validate_url($url)) {
            wp_safe_redirect(self::page_url(['settings-error' => 'invalid_url']));
            exit;
        }

        Purge_Service::purge_urls([$url], 'manual_url');

        wp_safe_redirect(self::page_url(['purged' => '1']));
        exit;
    }

    public static function handle_test_connection(): void
    {
        self::assert_can_purge();
        check_admin_referer('devart_wpcloudflare_test_connection');

        Purge_Service::purge_urls([home_url('/')], 'test');

        wp_safe_redirect(self::page_url(['purged' => '1']));
        exit;
    }

    public static function handle_retry_dropped(): void
    {
        self::assert_can_purge();
        check_admin_referer('devart_wpcloudflare_retry_dropped');

        $dropped = Purge_Queue::get_dropped();
        $urls = is_array($dropped) ? $dropped['urls'] : [];

        if ($urls === []) {
            Purge_Queue::clear_dropped();
            Results::clear_active_failure();
            wp_safe_redirect(self::page_url(['settings-updated' => 'dropped_queued']));
            exit;
        }

        // enqueue() clears dropped=0 on those rows atomically in the queue table.
        if (!Purge_Queue::enqueue($urls)) {
            wp_safe_redirect(self::page_url(['settings-error' => 'enqueue_failed']));
            exit;
        }

        if (Purge_Queue::get_dropped() === null) {
            Results::clear_active_failure();
        }

        wp_safe_redirect(self::page_url(['settings-updated' => 'dropped_queued']));
        exit;
    }

    public static function maybe_show_notices(): void
    {
        if (!isset($_GET['page']) || (string) $_GET['page'] !== self::PAGE_SLUG) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            $updated = (string) $_GET['settings-updated'];
            $ok_messages = [
                '1'              => __('Settings saved.', 'devart-wpcloudflare'),
                'dropped_queued' => __('Dropped URLs were queued again.', 'devart-wpcloudflare'),
            ];

            if (isset($ok_messages[$updated])) {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    esc_html($ok_messages[$updated])
                );
            }
        }

        if (isset($_GET['settings-error'])) {
            $code = (string) $_GET['settings-error'];
            $messages = [
                'token_encrypt' => __('Could not store the API token securely. Sodium encryption is unavailable.', 'devart-wpcloudflare'),
                'zone_id'       => __('Zone ID must be 32 hexadecimal characters.', 'devart-wpcloudflare'),
                'confirm'       => __('Please confirm that you want to purge the whole zone.', 'devart-wpcloudflare'),
                'save'          => __('Settings could not be written to the database. Try again.', 'devart-wpcloudflare'),
                'invalid_url'     => __('Enter a valid absolute URL to purge.', 'devart-wpcloudflare'),
                'enqueue_failed'  => __('Could not queue the dropped URLs. They remain listed so you can retry.', 'devart-wpcloudflare'),
            ];

            if (isset($messages[$code])) {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    esc_html($messages[$code])
                );
            }
        }

        if (Options::has_unreadable_stored_token()) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__('The stored API token cannot be decrypted (WordPress salts may have changed). Enter a new token or define DEVART_CF_API_TOKEN in wp-config.php.', 'devart-wpcloudflare')
            );
        }

        $active_failure = Results::active_failure();

        if ($active_failure !== null && $active_failure['message'] !== '') {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %s: active failure message */
                        __('Unresolved purge failure: %s Use “Retry dropped URLs” after fixing credentials, or clear the failure from that action.', 'devart-wpcloudflare'),
                        $active_failure['message']
                    )
                )
            );
        }

        $conflicts = Conflicts::active_names();

        if ($conflicts !== []) {
            printf(
                '<div class="notice notice-info"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %s: comma-separated plugin names */
                        __('Other active cache/Cloudflare plugins detected (%s). Use only one automatic Cloudflare purge system per site to avoid conflicting purge order.', 'devart-wpcloudflare'),
                        implode(', ', $conflicts)
                    )
                )
            );
        }

        if (!isset($_GET['purged']) || (string) $_GET['purged'] !== '1') {
            return;
        }

        $result = Results::last();

        if ($result === null || $result['message'] === '') {
            return;
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            $result['success'] ? 'success' : 'error',
            esc_html($result['message'])
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'devart-wpcloudflare'));
        }

        $settings = Options::get();
        $configured = Options::has_credentials();
        $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $uses_config_token = Options::uses_config_token();
        $last = Results::last();
        $last_error = Results::last_error();
        $active_failure = Results::active_failure();
        $dropped = Purge_Queue::get_dropped();
        $pending = Purge_Queue::pending_status();
        $pending_urls = $pending['count'];
        $retry_attempts = $pending['attempts'];
        ?>
        <div class="wrap devart-wpcloudflare-wrap">
            <h1><?php echo esc_html__('DevArt WordPress Cloudflare', 'devart-wpcloudflare'); ?></h1>
            <p class="description">
                <?php echo esc_html__('Configure Cloudflare credentials and purge cache manually, on content change, or on a schedule.', 'devart-wpcloudflare'); ?>
            </p>
            <?php
            self::render_status(
                $last,
                $last_error,
                $active_failure,
                $dropped,
                $pending_urls,
                $retry_attempts,
                $uses_config_token
            );
            self::render_settings_form($settings, $uses_config_token, $wp_cron_disabled);
            self::render_manual_purge($configured);
            ?>
        </div>
        <?php
    }

    /**
     * @param array{success: bool, message: string, context: string, time: int}|null $last
     * @param array{success: bool, message: string, context: string, time: int}|null $last_error
     * @param array{success: bool, message: string, context: string, time: int}|null $active_failure
     * @param array{urls: list<string>, count: int, time: int, truncated: bool}|null $dropped
     */
    private static function render_status(
        ?array $last,
        ?array $last_error,
        ?array $active_failure,
        ?array $dropped,
        int $pending_urls,
        int $retry_attempts,
        bool $uses_config_token
    ): void {
        ?>
            <div class="devart-wpcloudflare-card">
                <h2><?php echo esc_html__('Status', 'devart-wpcloudflare'); ?></h2>
                <ul class="devart-wpcloudflare-status">
                    <?php if (!Purge_Queue::is_available()) : ?>
                        <li>
                            <?php echo esc_html__('Purge queue table is unavailable. Auto-purge falls back to a one-shot send without retry until the table can be created.', 'devart-wpcloudflare'); ?>
                        </li>
                    <?php endif; ?>
                    <li>
                        <?php
                        if ($last === null || $last['message'] === '') {
                            echo esc_html__('Last result: none yet.', 'devart-wpcloudflare');
                        } else {
                            $when = $last['time'] > 0
                                ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last['time'])
                                : '—';
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: success/failure label, 2: datetime, 3: message */
                                    __('Last result (%1$s) at %2$s: %3$s', 'devart-wpcloudflare'),
                                    $last['success'] ? __('success', 'devart-wpcloudflare') : __('failure', 'devart-wpcloudflare'),
                                    $when,
                                    $last['message']
                                )
                            );
                        }
                        ?>
                    </li>
                    <?php if ($active_failure !== null && $active_failure['message'] !== '') : ?>
                        <li>
                            <?php
                            $active_when = $active_failure['time'] > 0
                                ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $active_failure['time'])
                                : '—';
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: datetime, 2: failure message */
                                    __('Active failure at %1$s: %2$s', 'devart-wpcloudflare'),
                                    $active_when,
                                    $active_failure['message']
                                )
                            );
                            ?>
                        </li>
                    <?php elseif ($last !== null && $last['success'] && $last_error !== null && $last_error['message'] !== '') : ?>
                        <li>
                            <?php
                            $err_when = $last_error['time'] > 0
                                ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_error['time'])
                                : '—';
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: datetime, 2: error message */
                                    __('Last historical error at %1$s: %2$s', 'devart-wpcloudflare'),
                                    $err_when,
                                    $last_error['message']
                                )
                            );
                            ?>
                        </li>
                    <?php endif; ?>
                    <li><?php echo esc_html(Scheduler::next_run_label()); ?></li>
                    <li>
                        <?php
                        if ($pending_urls > 0) {
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: pending URL count, 2: retry attempt count, 3: max attempts */
                                    __('Pending retry: %1$d URL(s), attempt %2$d of %3$d.', 'devart-wpcloudflare'),
                                    $pending_urls,
                                    $retry_attempts,
                                    Purge_Queue::MAX_ATTEMPTS
                                )
                            );
                        } else {
                            echo esc_html__('Pending retry: none.', 'devart-wpcloudflare');
                        }
                        ?>
                    </li>
                    <li>
                        <?php
                        $stats = Stats::snapshot();
                        echo esc_html(
                            sprintf(
                                /* translators: 1: purged URL count today, 2: failure count today, 3: max pending depth today */
                                __('Today (UTC): %1$d URL(s) purged, %2$d failure(s), max pending depth %3$d.', 'devart-wpcloudflare'),
                                $stats['purged_urls'],
                                $stats['failures'],
                                $stats['max_pending']
                            )
                        );
                        ?>
                    </li>
                    <?php if ($dropped !== null && $dropped['count'] > 0) : ?>
                        <li>
                            <?php
                            $dropped_when = $dropped['time'] > 0
                                ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $dropped['time'])
                                : '—';
                            if (!empty($dropped['truncated'])) {
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: recoverable stored, 2: lifetime dropped count, 3: datetime */
                                        __('Dropped URLs: %1$d recoverable of %2$d total since %3$s (older entries overflowed).', 'devart-wpcloudflare'),
                                        count($dropped['urls']),
                                        (int) $dropped['count'],
                                        $dropped_when
                                    )
                                );
                            } else {
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: recoverable URL count stored, 2: datetime */
                                        __('Dropped URLs: %1$d recoverable since %2$s.', 'devart-wpcloudflare'),
                                        count($dropped['urls']),
                                        $dropped_when
                                    )
                                );
                            }
                            ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="devart-wpcloudflare-inline-form devart-wpcloudflare-inline-form--beside">
                                <input type="hidden" name="action" value="devart_wpcloudflare_retry_dropped" />
                                <?php wp_nonce_field('devart_wpcloudflare_retry_dropped'); ?>
                                <?php submit_button(__('Retry dropped URLs', 'devart-wpcloudflare'), 'secondary', 'submit', false); ?>
                            </form>
                        </li>
                    <?php endif; ?>
                    <li>
                        <?php
                        if ($uses_config_token) {
                            echo esc_html__('Token source: DEVART_CF_API_TOKEN in wp-config.php.', 'devart-wpcloudflare');
                        } elseif (Options::has_stored_token()) {
                            echo esc_html__('Token source: encrypted database option.', 'devart-wpcloudflare');
                        } elseif (Options::has_unreadable_stored_token()) {
                            echo esc_html__('Token source: stored blob is unreadable.', 'devart-wpcloudflare');
                        } else {
                            echo esc_html__('Token source: not configured.', 'devart-wpcloudflare');
                        }
                        ?>
                    </li>
                </ul>
            </div>
        <?php
    }

    /**
     * @param array{
     *     api_token: string,
     *     zone_id: string,
     *     auto_purge_on_save: bool,
     *     extra_urls: string,
     *     schedule_enabled: bool,
     *     schedule_mode: string,
     *     schedule_urls: string,
     *     schedule_recurrence: string
     * } $settings
     */
    private static function render_settings_form(array $settings, bool $uses_config_token, bool $wp_cron_disabled): void
    {
        ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="devart-wpcloudflare-card" id="devart-wpcloudflare-settings-form">
                <input type="hidden" name="action" value="devart_wpcloudflare_save_settings" />
                <?php wp_nonce_field('devart_wpcloudflare_save_settings'); ?>
                <h2><?php echo esc_html__('Settings', 'devart-wpcloudflare'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="devart_cf_api_token"><?php echo esc_html__('API Token', 'devart-wpcloudflare'); ?></label>
                        </th>
                        <td>
                            <?php if ($uses_config_token) : ?>
                                <p>
                                    <code>DEVART_CF_API_TOKEN</code>
                                    <?php echo esc_html__('is defined in wp-config.php. The token is not stored in the database.', 'devart-wpcloudflare'); ?>
                                </p>
                            <?php else : ?>
                                <input
                                    type="password"
                                    class="regular-text"
                                    autocomplete="new-password"
                                    name="<?php echo esc_attr(Options::OPTION_KEY); ?>[api_token]"
                                    id="devart_cf_api_token"
                                    value=""
                                    placeholder="<?php echo Options::has_stored_token() ? esc_attr__('Stored token (leave blank to keep)', 'devart-wpcloudflare') : ''; ?>"
                                />
                                <p class="description">
                                    <?php
                                    if (Options::has_stored_token()) {
                                        echo esc_html__('A token is stored encrypted (sodium). Leave blank to keep it, or enter a new token to replace it. Prefer Zone → Cache Purge for this zone only.', 'devart-wpcloudflare');
                                    } else {
                                        echo esc_html__('Cloudflare API token with Zone Cache Purge for this zone only. Stored encrypted at rest (sodium). Optional: define DEVART_CF_API_TOKEN in wp-config.php instead.', 'devart-wpcloudflare');
                                    }
                                    ?>
                                </p>
                                <?php if (Options::has_stored_token() || Options::has_unreadable_stored_token()) : ?>
                                    <p>
                                        <label>
                                            <input
                                                type="checkbox"
                                                name="<?php echo esc_attr(Options::OPTION_KEY); ?>[remove_api_token]"
                                                value="1"
                                            />
                                            <?php echo esc_html__('Remove stored API token', 'devart-wpcloudflare'); ?>
                                        </label>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="devart_cf_zone_id"><?php echo esc_html__('Zone ID', 'devart-wpcloudflare'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                class="regular-text"
                                name="<?php echo esc_attr(Options::OPTION_KEY); ?>[zone_id]"
                                id="devart_cf_zone_id"
                                value="<?php echo esc_attr($settings['zone_id']); ?>"
                                maxlength="32"
                                pattern="[a-fA-F0-9]{32}"
                                placeholder="32 hexadecimal characters"
                            />
                            <p class="description">
                                <?php echo esc_html__('Cloudflare Zone ID: exactly 32 hexadecimal characters.', 'devart-wpcloudflare'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Auto purge on save', 'devart-wpcloudflare'); ?></th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(Options::OPTION_KEY); ?>[auto_purge_on_save]"
                                    value="1"
                                    <?php checked($settings['auto_purge_on_save']); ?>
                                />
                                <?php echo esc_html__('Purge related URLs when published public content changes (permalink, home, archives, terms, author, feed, comments, and extra URLs).', 'devart-wpcloudflare'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="devart_cf_extra_urls"><?php echo esc_html__('Extra auto-purge URLs', 'devart-wpcloudflare'); ?></label>
                        </th>
                        <td>
                            <textarea
                                class="large-text code"
                                rows="4"
                                name="<?php echo esc_attr(Options::OPTION_KEY); ?>[extra_urls]"
                                id="devart_cf_extra_urls"
                            ><?php echo esc_textarea($settings['extra_urls']); ?></textarea>
                            <p class="description">
                                <?php echo esc_html__('One absolute URL per line. Always purged when published content changes. Hosts must match this site (filterable via devart_wpcloudflare_allowed_hosts).', 'devart-wpcloudflare'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Scheduled purge', 'devart-wpcloudflare'); ?></th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(Options::OPTION_KEY); ?>[schedule_enabled]"
                                    value="1"
                                    <?php checked($settings['schedule_enabled']); ?>
                                />
                                <?php echo esc_html__('Enable WP-Cron scheduled purge.', 'devart-wpcloudflare'); ?>
                            </label>
                            <p class="description"><?php echo esc_html(Scheduler::next_run_label()); ?></p>
                            <?php if (!$wp_cron_disabled) : ?>
                                <p class="description">
                                    <?php echo esc_html__('WP-Cron only runs when the site receives uncached requests. Behind Cloudflare, use a system cron every minute (for example: wp cron event run --due-now) and set DISABLE_WP_CRON in wp-config.php.', 'devart-wpcloudflare'); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="devart_cf_schedule_mode"><?php echo esc_html__('Schedule mode', 'devart-wpcloudflare'); ?></label>
                        </th>
                        <td>
                            <select
                                name="<?php echo esc_attr(Options::OPTION_KEY); ?>[schedule_mode]"
                                id="devart_cf_schedule_mode"
                            >
                                <option value="<?php echo esc_attr(Options::MODE_URLS); ?>" <?php selected($settings['schedule_mode'], Options::MODE_URLS); ?>>
                                    <?php echo esc_html__('URL list', 'devart-wpcloudflare'); ?>
                                </option>
                                <option value="<?php echo esc_attr(Options::MODE_EVERYTHING); ?>" <?php selected($settings['schedule_mode'], Options::MODE_EVERYTHING); ?>>
                                    <?php echo esc_html__('Everything', 'devart-wpcloudflare'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php echo esc_html__('Purging everything makes every visitor hit the origin at once. Prefer URL list (home and main sections) on news sites. Everything clears the whole Cloudflare zone, which may include other hostnames.', 'devart-wpcloudflare'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="devart_cf_schedule_urls"><?php echo esc_html__('Scheduled URLs', 'devart-wpcloudflare'); ?></label>
                        </th>
                        <td>
                            <textarea
                                class="large-text code"
                                rows="6"
                                name="<?php echo esc_attr(Options::OPTION_KEY); ?>[schedule_urls]"
                                id="devart_cf_schedule_urls"
                            ><?php echo esc_textarea($settings['schedule_urls']); ?></textarea>
                            <p class="description">
                                <?php echo esc_html__('One absolute URL per line. Used when schedule mode is URL list. Hosts must match this site.', 'devart-wpcloudflare'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="devart_cf_schedule_recurrence"><?php echo esc_html__('Recurrence', 'devart-wpcloudflare'); ?></label>
                        </th>
                        <td>
                            <select
                                name="<?php echo esc_attr(Options::OPTION_KEY); ?>[schedule_recurrence]"
                                id="devart_cf_schedule_recurrence"
                            >
                                <option value="devart_cf_15min" <?php selected($settings['schedule_recurrence'], 'devart_cf_15min'); ?>>
                                    <?php echo esc_html__('Every 15 minutes', 'devart-wpcloudflare'); ?>
                                </option>
                                <option value="devart_cf_30min" <?php selected($settings['schedule_recurrence'], 'devart_cf_30min'); ?>>
                                    <?php echo esc_html__('Every 30 minutes', 'devart-wpcloudflare'); ?>
                                </option>
                                <option value="hourly" <?php selected($settings['schedule_recurrence'], 'hourly'); ?>>
                                    <?php echo esc_html__('Hourly', 'devart-wpcloudflare'); ?>
                                </option>
                                <option value="devart_cf_2hours" <?php selected($settings['schedule_recurrence'], 'devart_cf_2hours'); ?>>
                                    <?php echo esc_html__('Every 2 hours', 'devart-wpcloudflare'); ?>
                                </option>
                                <option value="twicedaily" <?php selected($settings['schedule_recurrence'], 'twicedaily'); ?>>
                                    <?php echo esc_html__('Twice daily', 'devart-wpcloudflare'); ?>
                                </option>
                                <option value="daily" <?php selected($settings['schedule_recurrence'], 'daily'); ?>>
                                    <?php echo esc_html__('Daily', 'devart-wpcloudflare'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save settings', 'devart-wpcloudflare')); ?>
            </form>
        <?php
    }

    private static function render_manual_purge(bool $configured): void
    {
        ?>
            <div class="devart-wpcloudflare-card">
                <h2><?php echo esc_html__('Manual purge', 'devart-wpcloudflare'); ?></h2>
                <?php if (!$configured) : ?>
                    <p><?php echo esc_html__('Save an API token and Zone ID (or define DEVART_CF_API_TOKEN) before running a purge.', 'devart-wpcloudflare'); ?></p>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="devart-wpcloudflare-inline-form">
                        <input type="hidden" name="action" value="devart_wpcloudflare_test_connection" />
                        <?php wp_nonce_field('devart_wpcloudflare_test_connection'); ?>
                        <?php submit_button(__('Test connection', 'devart-wpcloudflare'), 'secondary', 'submit', false); ?>
                    </form>
                    <p class="description">
                        <?php echo esc_html__('Sends a real purge request for the site homepage to verify Cache Purge permission.', 'devart-wpcloudflare'); ?>
                    </p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="devart-wpcloudflare-inline-form">
                        <input type="hidden" name="action" value="devart_wpcloudflare_purge_everything" />
                        <?php wp_nonce_field('devart_wpcloudflare_purge_everything'); ?>
                        <p>
                            <label>
                                <input type="checkbox" name="confirm_everything" value="1" required />
                                <?php echo esc_html__('I understand this clears the whole Cloudflare zone.', 'devart-wpcloudflare'); ?>
                            </label>
                        </p>
                        <?php submit_button(__('Purge everything', 'devart-wpcloudflare'), 'delete', 'submit', false); ?>
                    </form>
                    <p class="description">
                        <?php echo esc_html__('Advanced: clears the entire Cloudflare zone, not only this WordPress site.', 'devart-wpcloudflare'); ?>
                    </p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="devart-wpcloudflare-url-form">
                        <input type="hidden" name="action" value="devart_wpcloudflare_purge_url" />
                        <?php wp_nonce_field('devart_wpcloudflare_purge_url'); ?>
                        <label for="devart_cf_purge_url" class="screen-reader-text">
                            <?php echo esc_html__('URL to purge', 'devart-wpcloudflare'); ?>
                        </label>
                        <input
                            type="url"
                            class="regular-text"
                            name="purge_url"
                            id="devart_cf_purge_url"
                            placeholder="https://example.com/path/"
                            required
                        />
                        <?php submit_button(__('Purge URL', 'devart-wpcloudflare'), 'secondary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>
        <?php
    }

    private static function assert_can_purge(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to purge Cloudflare cache.', 'devart-wpcloudflare'));
        }
    }

    /**
     * @param array<string, string> $args
     */
    private static function page_url(array $args = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::PAGE_SLUG], $args),
            admin_url('options-general.php')
        );
    }
}

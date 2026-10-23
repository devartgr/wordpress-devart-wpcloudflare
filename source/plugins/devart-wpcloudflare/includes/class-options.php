<?php
/**
 * Plugin settings (get / sanitize / save). Secrets and results live elsewhere.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Options
{
    public const OPTION_KEY = 'devart_wpcloudflare_settings';
    public const MODE_EVERYTHING = 'everything';
    public const MODE_URLS = 'urls';

    /** @var array{
     *     api_token: string,
     *     zone_id: string,
     *     auto_purge_on_save: bool,
     *     extra_urls: string,
     *     schedule_enabled: bool,
     *     schedule_mode: string,
     *     schedule_urls: string,
     *     schedule_recurrence: string
     * }|null
     */
    private static ?array $memo = null;

    /**
     * @return array{
     *     api_token: string,
     *     zone_id: string,
     *     auto_purge_on_save: bool,
     *     extra_urls: string,
     *     schedule_enabled: bool,
     *     schedule_mode: string,
     *     schedule_urls: string,
     *     schedule_recurrence: string
     * }
     */
    public static function defaults(): array
    {
        return [
            'api_token'           => '',
            'zone_id'             => '',
            'auto_purge_on_save'  => false,
            'extra_urls'          => '',
            'schedule_enabled'    => false,
            'schedule_mode'       => self::MODE_URLS,
            'schedule_urls'       => '',
            'schedule_recurrence' => 'devart_cf_30min',
        ];
    }

    public static function flush_memo(): void
    {
        self::$memo = null;
    }

    public static function uses_config_token(): bool
    {
        return defined('DEVART_CF_API_TOKEN')
            && is_string(DEVART_CF_API_TOKEN)
            && trim(DEVART_CF_API_TOKEN) !== '';
    }

    /**
     * @return array{
     *     api_token: string,
     *     zone_id: string,
     *     auto_purge_on_save: bool,
     *     extra_urls: string,
     *     schedule_enabled: bool,
     *     schedule_mode: string,
     *     schedule_urls: string,
     *     schedule_recurrence: string
     * }
     */
    public static function get(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $stored = get_option(self::OPTION_KEY, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = array_merge(self::defaults(), $stored);
        $stored_token = Secret::decrypt((string) ($stored['api_token'] ?? ''));

        if (self::uses_config_token()) {
            $settings['api_token'] = trim((string) DEVART_CF_API_TOKEN);
        } else {
            $settings['api_token'] = $stored_token;
        }

        $settings['zone_id'] = self::normalize_zone_id(sanitize_text_field((string) $settings['zone_id']));
        $settings['auto_purge_on_save'] = !empty($settings['auto_purge_on_save']);
        $settings['extra_urls'] = (string) $settings['extra_urls'];
        $settings['schedule_enabled'] = !empty($settings['schedule_enabled']);
        $settings['schedule_mode'] = self::normalize_mode((string) $settings['schedule_mode']);
        $settings['schedule_urls'] = (string) $settings['schedule_urls'];
        $settings['schedule_recurrence'] = self::normalize_recurrence((string) $settings['schedule_recurrence']);

        self::$memo = $settings;

        return $settings;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     api_token: string,
     *     zone_id: string,
     *     auto_purge_on_save: bool,
     *     extra_urls: string,
     *     schedule_enabled: bool,
     *     schedule_mode: string,
     *     schedule_urls: string,
     *     schedule_recurrence: string
     * }
     */
    public static function sanitize(array $input): array
    {
        $current = self::get();
        $defaults = self::defaults();

        if (!empty($input['remove_api_token'])) {
            $token = '';
        } elseif (self::uses_config_token()) {
            $token = '';
        } else {
            $token = trim(self::scalar_string($input['api_token'] ?? null));

            if ($token === '') {
                $token = $current['api_token'];
            }
        }

        $schedule_urls = Url_Filter::normalize_url_list(
            self::scalar_string($input['schedule_urls'] ?? null, $defaults['schedule_urls'])
        );
        $extra_urls = Url_Filter::normalize_url_list(
            self::scalar_string($input['extra_urls'] ?? null, $defaults['extra_urls'])
        );
        $zone_raw = sanitize_text_field(self::scalar_string($input['zone_id'] ?? null));

        if ($zone_raw !== '' && !self::is_valid_zone_id_input($zone_raw)) {
            $zone_id = $current['zone_id'];
        } else {
            $zone_id = self::normalize_zone_id($zone_raw);
        }

        return [
            'api_token'           => $token,
            'zone_id'             => $zone_id,
            'auto_purge_on_save'  => !empty($input['auto_purge_on_save']),
            'extra_urls'          => $extra_urls,
            'schedule_enabled'    => !empty($input['schedule_enabled']),
            'schedule_mode'       => self::normalize_mode(
                self::scalar_string($input['schedule_mode'] ?? null, self::MODE_URLS)
            ),
            'schedule_urls'       => $schedule_urls,
            'schedule_recurrence' => self::normalize_recurrence(
                self::scalar_string(
                    $input['schedule_recurrence'] ?? null,
                    $defaults['schedule_recurrence']
                )
            ),
        ];
    }

    public static function scalar_string(mixed $value, string $default = ''): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    public static function normalize_zone_id(string $zone_id): string
    {
        $zone_id = trim($zone_id);

        if ($zone_id === '') {
            return '';
        }

        return preg_match('/^[a-f0-9]{32}$/i', $zone_id) === 1 ? $zone_id : '';
    }

    public static function is_valid_zone_id_input(string $raw): bool
    {
        $raw = trim($raw);

        return $raw === '' || preg_match('/^[a-f0-9]{32}$/i', $raw) === 1;
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
     * @return array<string, mixed>
     */
    public static function to_storage(array $settings): array
    {
        $payload = $settings;

        if (self::uses_config_token()) {
            $payload['api_token'] = '';
        } else {
            $payload['api_token'] = Secret::encrypt($settings['api_token']);
        }

        return $payload;
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
     *
     * @throws \RuntimeException When the token cannot be encrypted safely.
     */
    public static function save(array $settings): bool
    {
        $storage = self::to_storage($settings);
        $written = update_option(self::OPTION_KEY, $storage, false);
        self::flush_memo();

        if ($written) {
            return true;
        }

        $stored = get_option(self::OPTION_KEY, null);

        return is_array($stored) && $stored == $storage;
    }

    public static function has_credentials(): bool
    {
        $settings = self::get();

        return $settings['api_token'] !== '' && $settings['zone_id'] !== '';
    }

    public static function has_stored_token(): bool
    {
        if (self::uses_config_token()) {
            return false;
        }

        return self::get()['api_token'] !== '';
    }

    public static function has_unreadable_stored_token(): bool
    {
        if (self::uses_config_token()) {
            return false;
        }

        $stored = get_option(self::OPTION_KEY, []);

        if (!is_array($stored)) {
            return false;
        }

        $raw = trim((string) ($stored['api_token'] ?? ''));

        if ($raw === '' || !Secret::is_v2_blob($raw)) {
            return $raw !== '';
        }

        return Secret::decrypt($raw) === '';
    }

    /**
     * @return list<string>
     */
    public static function schedule_urls(): array
    {
        return Url_Filter::parse_urls(self::get()['schedule_urls']);
    }

    public static function normalize_mode(string $mode): string
    {
        return $mode === self::MODE_EVERYTHING ? self::MODE_EVERYTHING : self::MODE_URLS;
    }

    public static function normalize_recurrence(string $recurrence): string
    {
        $allowed = [
            'devart_cf_15min',
            'devart_cf_30min',
            'hourly',
            'devart_cf_2hours',
            'twicedaily',
            'daily',
        ];

        return in_array($recurrence, $allowed, true) ? $recurrence : 'devart_cf_30min';
    }
}

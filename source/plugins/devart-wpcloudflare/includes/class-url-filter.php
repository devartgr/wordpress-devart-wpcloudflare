<?php
/**
 * URL parsing and same-site host filtering for purges.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Url_Filter
{
    /**
     * @return list<string>
     */
    public static function parse_urls(string $raw): array
    {
        $lines = preg_split('/\R+/', $raw) ?: [];
        $urls = [];

        foreach ($lines as $line) {
            $url = esc_url_raw(trim($line));

            if ($url === '' || !wp_http_validate_url($url)) {
                continue;
            }

            $urls[$url] = $url;
        }

        return array_values($urls);
    }

    public static function normalize_url_list(string $raw): string
    {
        return implode("\n", self::parse_urls($raw));
    }

    /**
     * @return list<string>
     */
    public static function allowed_hosts(): array
    {
        $hosts = [];

        foreach ([home_url('/'), site_url('/')] as $base) {
            $host = wp_parse_url($base, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[strtolower($host)] = true;
            }
        }

        $list = array_keys($hosts);

        /**
         * Filter allowed purge hostnames (e.g. domain mapping / multilingual).
         *
         * @param list<string> $list Lowercase hostnames.
         */
        $filtered = apply_filters('devart_wpcloudflare_allowed_hosts', $list);

        if (!is_array($filtered)) {
            return $list;
        }

        $out = [];

        foreach ($filtered as $host) {
            if (is_string($host) && $host !== '') {
                $out[strtolower($host)] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param list<string> $urls
     * @return array{kept: list<string>, dropped: int}
     */
    public static function filter_site_urls(array $urls): array
    {
        $allowed = self::allowed_hosts();
        $kept = [];
        $dropped = 0;

        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }

            $url = esc_url_raw(trim($url));

            if ($url === '' || !wp_http_validate_url($url)) {
                continue;
            }

            $host = wp_parse_url($url, PHP_URL_HOST);

            if (!is_string($host) || $host === '') {
                continue;
            }

            if (!in_array(strtolower($host), $allowed, true)) {
                ++$dropped;

                continue;
            }

            $kept[$url] = $url;
        }

        return [
            'kept'    => array_values($kept),
            'dropped' => $dropped,
        ];
    }
}

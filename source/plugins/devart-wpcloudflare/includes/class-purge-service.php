<?php
/**
 * High-level purge orchestration.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Purge_Service
{
    /**
     * @return array{success: bool, message: string, context: string, failed: list<string>, http_status: int, retry_after: int, non_retryable: bool}
     */
    public static function purge_everything(string $context = 'manual'): array
    {
        $api = new Cloudflare_Api();
        $result = $api->purge_everything();

        return self::finalize($result, $context, 1);
    }

    /**
     * @param list<string>|string $urls
     * @return array{success: bool, message: string, context: string, failed: list<string>, http_status: int, retry_after: int, non_retryable: bool}
     */
    public static function purge_urls(array|string $urls, string $context = 'manual', int $timeout = 20): array
    {
        if (is_string($urls)) {
            $urls = Url_Filter::parse_urls($urls);
        }

        $filtered = Url_Filter::filter_site_urls($urls);
        $kept = $filtered['kept'];
        $dropped = $filtered['dropped'];

        if ($kept === []) {
            $hosts = Url_Filter::allowed_hosts();
            $host_list = $hosts !== [] ? implode(', ', $hosts) : home_url('/');

            return self::finalize(
                [
                    'success'       => false,
                    'message'       => sprintf(
                        /* translators: %s: allowed hostname list */
                        __('No URLs matched this site hostname (%s). Off-site URLs are rejected.', 'devart-wpcloudflare'),
                        $host_list
                    ),
                    'failed'        => [],
                    'http_status'   => 0,
                    'retry_after'   => 0,
                    'non_retryable' => true,
                ],
                $context,
                0
            );
        }

        $api = new Cloudflare_Api();
        $result = $api->purge_urls($kept, $timeout);

        if ($dropped > 0) {
            $result['message'] = sprintf(
                /* translators: 1: original message, 2: dropped URL count */
                __('%1$s (%2$d URL(s) skipped: hostname mismatch.)', 'devart-wpcloudflare'),
                (string) ($result['message'] ?? ''),
                $dropped
            );
        }

        return self::finalize($result, $context, count($kept));
    }

    public static function is_permanent_failure(int $http_status): bool
    {
        return in_array($http_status, [401, 403], true);
    }

    /**
     * @param array{success: bool, message: string, failed?: list<string>, http_status?: int, retry_after?: int, non_retryable?: bool} $result
     * @return array{success: bool, message: string, context: string, failed: list<string>, http_status: int, retry_after: int, non_retryable: bool}
     */
    private static function finalize(array $result, string $context, int $url_count): array
    {
        $failed = [];

        if (isset($result['failed']) && is_array($result['failed'])) {
            foreach ($result['failed'] as $url) {
                if (is_string($url)) {
                    $failed[] = $url;
                }
            }
        }

        $payload = [
            'success'       => !empty($result['success']),
            'message'       => (string) ($result['message'] ?? ''),
            'context'       => sanitize_key($context),
            'failed'        => $failed,
            'http_status'   => isset($result['http_status']) ? (int) $result['http_status'] : 0,
            'retry_after'   => isset($result['retry_after']) ? max(0, (int) $result['retry_after']) : 0,
            'non_retryable' => !empty($result['non_retryable']),
        ];

        Results::store($payload);

        if ($payload['success']) {
            Stats::record_success(max(0, $url_count));
        } elseif (!$payload['non_retryable'] || $failed !== []) {
            Stats::record_failure();
        }

        /**
         * Fires after a Cloudflare purge attempt.
         *
         * @param array{success: bool, message: string, context: string, failed: list<string>, http_status: int, retry_after: int, non_retryable: bool} $payload
         */
        do_action('devart_wpcloudflare_purge_result', $payload);

        return $payload;
    }
}

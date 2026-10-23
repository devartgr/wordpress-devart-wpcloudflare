<?php
/**
 * Minimal Cloudflare Cache Purge API client.
 *
 * @package DevArt\WpCloudflare
 * @see https://developers.cloudflare.com/api/resources/cache/methods/purge/
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Cloudflare_Api
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/';
    private const CHUNK_SIZE = 100;

    private string $token;
    private string $zone_id;

    public function __construct(?string $token = null, ?string $zone_id = null)
    {
        $settings = Options::get();
        $this->token = $token !== null ? trim($token) : $settings['api_token'];
        $this->zone_id = $zone_id !== null ? trim($zone_id) : $settings['zone_id'];
    }

    public function has_credentials(): bool
    {
        return $this->token !== '' && $this->zone_id !== '';
    }

    /**
     * @return array{success: bool, message: string, http_status: int, retry_after: int}
     */
    public function purge_everything(): array
    {
        return $this->request_purge(['purge_everything' => true], 20);
    }

    /**
     * @param list<string> $urls
     * @return array{success: bool, message: string, http_status: int, files: list<string>, failed: list<string>, retry_after: int}
     */
    public function purge_urls(array $urls, int $timeout = 20): array
    {
        $files = [];

        foreach ($urls as $url) {
            $url = esc_url_raw(trim((string) $url));

            if ($url === '') {
                continue;
            }

            $files[$url] = $url;
        }

        $files = array_values($files);

        if ($files === []) {
            return [
                'success'     => false,
                'message'     => __('No valid URLs were provided for purge.', 'devart-wpcloudflare'),
                'http_status' => 0,
                'files'       => [],
                'failed'      => [],
                'retry_after' => 0,
            ];
        }

        $chunks = array_chunk($files, self::CHUNK_SIZE);
        $purged = 0;
        $chunk_count = count($chunks);

        for ($i = 0; $i < $chunk_count; $i++) {
            $chunk = $chunks[$i];
            $result = $this->request_purge(['files' => $chunk], $timeout);

            if (!$result['success']) {
                $failed = [];

                for ($j = $i; $j < $chunk_count; $j++) {
                    foreach ($chunks[$j] as $url) {
                        $failed[] = $url;
                    }
                }

                return [
                    'success'     => false,
                    'message'     => $result['message'],
                    'http_status' => $result['http_status'],
                    'files'       => $files,
                    'failed'      => $failed,
                    'retry_after' => $result['retry_after'],
                ];
            }

            $purged += count($chunk);
        }

        return [
            'success'     => true,
            'message'     => sprintf(
                /* translators: %d: number of purged URLs */
                _n(
                    'Purged %d URL from Cloudflare cache.',
                    'Purged %d URLs from Cloudflare cache.',
                    $purged,
                    'devart-wpcloudflare'
                ),
                $purged
            ),
            'http_status' => 200,
            'files'       => $files,
            'failed'      => [],
            'retry_after' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{success: bool, message: string, http_status: int, retry_after: int}
     */
    private function request_purge(array $body, int $timeout): array
    {
        if (!$this->has_credentials()) {
            return [
                'success'     => false,
                'message'     => __('Cloudflare API token and Zone ID are required.', 'devart-wpcloudflare'),
                'http_status' => 0,
                'retry_after' => 0,
            ];
        }

        $result = $this->request_json(
            'POST',
            'zones/' . rawurlencode($this->zone_id) . '/purge_cache',
            $body,
            $timeout
        );

        return [
            'success'     => $result['success'],
            'message'     => $result['success']
                ? __('Cloudflare cache purge accepted.', 'devart-wpcloudflare')
                : $result['message'],
            'http_status' => $result['http_status'],
            'retry_after' => $result['retry_after'],
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{success: bool, message: string, http_status: int, retry_after: int, data: array<string, mixed>}
     */
    private function request_json(string $method, string $path, ?array $body, int $timeout): array
    {
        $url = self::ENDPOINT . ltrim($path, '/');
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'User-Agent'    => 'DevArt-WpCloudflare/' . DEVART_WPCLOUDFLARE_VERSION,
        ];

        $args = [
            'method'  => $method,
            'timeout' => max(1, $timeout),
            'headers' => $headers,
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'success'     => false,
                'message'     => sprintf(
                    /* translators: %s: transport error message */
                    __('Cloudflare request failed: %s', 'devart-wpcloudflare'),
                    $response->get_error_message()
                ),
                'http_status' => 0,
                'retry_after' => 0,
                'data'        => [],
            ];
        }

        $http_status = (int) wp_remote_retrieve_response_code($response);
        $retry_after = self::parse_retry_after_header($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($decoded)) {
            return [
                'success'     => false,
                'message'     => __('Invalid JSON response from Cloudflare.', 'devart-wpcloudflare'),
                'http_status' => $http_status,
                'retry_after' => $retry_after,
                'data'        => [],
            ];
        }

        $http_ok = $http_status >= 200 && $http_status < 300;

        if ($http_ok && !empty($decoded['success'])) {
            return [
                'success'     => true,
                'message'     => __('OK', 'devart-wpcloudflare'),
                'http_status' => $http_status,
                'retry_after' => 0,
                'data'        => $decoded,
            ];
        }

        $error_message = __('Cloudflare API request failed.', 'devart-wpcloudflare');
        $parts = [];

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            foreach ($decoded['errors'] as $error) {
                if (is_array($error) && isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
                    $parts[] = $error['message'];
                }
            }
        }

        if ($parts !== []) {
            $error_message = implode(' | ', $parts);
        } elseif (!$http_ok) {
            $error_message = sprintf(
                /* translators: %d: HTTP status code */
                __('Cloudflare returned HTTP %d.', 'devart-wpcloudflare'),
                $http_status
            );
        }

        return [
            'success'     => false,
            'message'     => $error_message,
            'http_status' => $http_status,
            'retry_after' => $retry_after,
            'data'        => $decoded,
        ];
    }

    /**
     * @param array<string, mixed>|\WP_HTTP_Requests_Response|mixed $response
     */
    private static function parse_retry_after_header(mixed $response): int
    {
        $header = wp_remote_retrieve_header($response, 'retry-after');

        if (is_array($header)) {
            $header = $header[0] ?? '';
        }

        if (!is_string($header) || $header === '') {
            return 0;
        }

        $header = trim($header);

        if (ctype_digit($header)) {
            return max(0, (int) $header);
        }

        $when = strtotime($header);

        if ($when === false) {
            return 0;
        }

        return max(0, $when - time());
    }
}

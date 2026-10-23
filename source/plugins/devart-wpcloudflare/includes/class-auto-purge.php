<?php
/**
 * Automatic Cloudflare purge when published content changes.
 *
 * Hooks record what changed; URLs are resolved once on shutdown, persisted
 * to Purge_Queue, then sent (with durable retry).
 *
 * Why several post hooks:
 * - transition_post_status covers publish / unpublish / trash / future→publish
 * - wp_after_insert_post runs after terms/meta are saved (Gutenberg REST)
 * - pre_post_update captures the public permalink (and author archive) before changes
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Auto_Purge
{
    private const MAX_URLS = 300;
    private const INLINE_CHUNK = 100;

    /** @var array<int, true> */
    private static array $post_ids = [];

    /** @var array<string, true> */
    private static array $urls = [];

    /** @var array<int, true> Permalink already captured this request (term add/remove). */
    private static array $permalink_captured = [];

    private static bool $flush_registered = false;

    public static function register(): void
    {
        add_action('transition_post_status', [self::class, 'on_transition_post_status'], 10, 3);
        add_action('wp_after_insert_post', [self::class, 'on_after_insert_post'], 10, 4);
        add_action('pre_post_update', [self::class, 'on_pre_post_update'], 10, 1);
        add_action('set_object_terms', [self::class, 'on_set_object_terms'], 10, 6);
        add_action('add_term_relationship', [self::class, 'on_add_term_relationship'], 10, 3);
        add_action('delete_term_relationships', [self::class, 'on_delete_term_relationships'], 10, 2);
        add_action('deleted_term_relationships', [self::class, 'on_deleted_term_relationships'], 10, 3);
        add_action('before_delete_post', [self::class, 'on_before_delete_post'], 10, 2);
        add_action('transition_comment_status', [self::class, 'on_transition_comment_status'], 10, 3);
        add_action('comment_post', [self::class, 'on_comment_post'], 10, 3);
        add_action('edit_comment', [self::class, 'on_edit_comment'], 10, 2);
        add_action(Purge_Queue::HOOK, [self::class, 'run_retry']);
    }

    public static function clear_retry(): void
    {
        Purge_Queue::clear();
    }

    public static function on_transition_post_status(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ($new_status !== 'publish' && $old_status !== 'publish') {
            return;
        }

        if (!self::should_record($post)) {
            return;
        }

        self::record_post((int) $post->ID);
    }

    public static function on_after_insert_post(int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before): void
    {
        unset($update, $post_before);

        if ($post->post_status !== 'publish') {
            return;
        }

        if (!self::should_record($post)) {
            return;
        }

        self::record_post($post_id);
    }

    /**
     * Capture public permalink and author archive before DB write.
     */
    public static function on_pre_post_update(int $post_id): void
    {
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return;
        }

        if (!self::should_record($post)) {
            return;
        }

        $permalink = get_permalink($post);

        if (is_string($permalink)) {
            self::record_url($permalink);
        }

        self::record_url(get_author_posts_url((int) $post->post_author));
    }

    /**
     * @param list<int>|array<int, int> $terms
     * @param list<int>|array<int, int> $tt_ids
     * @param list<int>|array<int, int> $old_tt_ids
     */
    public static function on_set_object_terms(
        int $object_id,
        array $terms,
        array $tt_ids,
        string $taxonomy,
        bool $append,
        array $old_tt_ids
    ): void {
        unset($terms, $tt_ids, $append);

        $post = get_post($object_id);

        if (!$post instanceof \WP_Post) {
            return;
        }

        if ($post->post_status !== 'publish' && !isset(self::$post_ids[$object_id])) {
            return;
        }

        if (!self::guards_pass() || !self::is_public_post_type($post->post_type)) {
            return;
        }

        if ($post->post_status === 'publish') {
            self::record_post($object_id);
        }

        foreach ($old_tt_ids as $tt_id) {
            $term = get_term_by('term_taxonomy_id', (int) $tt_id, $taxonomy);

            if (!$term instanceof \WP_Term) {
                continue;
            }

            foreach (Url_Builder::for_term($term) as $url) {
                self::record_url($url);
            }
        }
    }

    /**
     * Before a term is added — capture the current public permalink once per post/request.
     */
    public static function on_add_term_relationship(int $object_id, int $tt_id, string $taxonomy = ''): void
    {
        unset($tt_id, $taxonomy);
        self::capture_permalink_once($object_id);
    }

    /**
     * Relationships still exist in the DB — capture the real public permalink.
     *
     * @param list<int>|array<int, int> $tt_ids
     */
    public static function on_delete_term_relationships(int $object_id, array $tt_ids): void
    {
        unset($tt_ids);
        self::capture_permalink_once($object_id);
    }

    private static function capture_permalink_once(int $object_id): void
    {
        if (isset(self::$permalink_captured[$object_id])) {
            return;
        }

        $post = get_post($object_id);

        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return;
        }

        if (!self::guards_pass() || !self::is_public_post_type($post->post_type)) {
            return;
        }

        self::$permalink_captured[$object_id] = true;
        $permalink = get_permalink($post);

        if (is_string($permalink)) {
            self::record_url($permalink);
        }
    }

    /**
     * @param list<int>|array<int, int> $tt_ids
     */
    public static function on_deleted_term_relationships(int $object_id, array $tt_ids, string $taxonomy = ''): void
    {
        $post = get_post($object_id);

        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return;
        }

        if (!self::guards_pass() || !self::is_public_post_type($post->post_type)) {
            return;
        }

        self::record_post($object_id);

        if ($taxonomy === '') {
            return;
        }

        foreach ($tt_ids as $tt_id) {
            $term = get_term_by('term_taxonomy_id', (int) $tt_id, $taxonomy);

            if (!$term instanceof \WP_Term) {
                continue;
            }

            foreach (Url_Builder::for_term($term) as $url) {
                self::record_url($url);
            }
        }
    }

    public static function on_before_delete_post(int $post_id, \WP_Post $post): void
    {
        unset($post_id);

        if ($post->post_status !== 'publish') {
            return;
        }

        if (!self::should_record($post)) {
            return;
        }

        foreach (Url_Builder::for_post($post) as $url) {
            self::record_url($url);
        }
    }

    /**
     * @param \WP_Comment|null $comment
     */
    public static function on_transition_comment_status(string $new_status, string $old_status, $comment): void
    {
        if ($new_status !== 'approved' && $old_status !== 'approved') {
            return;
        }

        if (!$comment instanceof \WP_Comment) {
            return;
        }

        self::record_comment_post((int) $comment->comment_post_ID);
    }

    /**
     * @param int|string $approved
     */
    public static function on_comment_post(int $comment_id, $approved, array $commentdata = []): void
    {
        unset($commentdata);

        if ((int) $approved !== 1) {
            return;
        }

        $comment = get_comment($comment_id);

        if (!$comment instanceof \WP_Comment) {
            return;
        }

        self::record_comment_post((int) $comment->comment_post_ID);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function on_edit_comment(int $comment_id, array $data = []): void
    {
        unset($data);

        $comment = get_comment($comment_id);

        if (!$comment instanceof \WP_Comment) {
            return;
        }

        if ((string) $comment->comment_approved !== '1') {
            return;
        }

        self::record_comment_post((int) $comment->comment_post_ID);
    }

    public static function flush_queue(): void
    {
        try {
            self::flush_queue_inner();
        } catch (\Throwable $e) {
            Results::store([
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: exception message */
                    __('Auto-purge failed unexpectedly: %s', 'devart-wpcloudflare'),
                    $e->getMessage()
                ),
                'context' => 'auto_save',
            ]);
        }
    }

    public static function run_retry(): void
    {
        if (!Options::has_credentials()) {
            return;
        }

        if (!Purge_Queue::is_available()) {
            return;
        }

        $batch = Purge_Queue::claim(self::INLINE_CHUNK);

        if ($batch === null) {
            Purge_Queue::ensure_scheduled();

            return;
        }

        self::process_batch($batch, 'auto_retry', 10);
    }

    private static function flush_queue_inner(): void
    {
        if (self::$post_ids === [] && self::$urls === []) {
            return;
        }

        $urls = self::$urls;
        $post_ids = array_keys(self::$post_ids);

        self::$post_ids = [];
        self::$urls = [];
        self::$flush_registered = false;

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);

            if (!$post instanceof \WP_Post) {
                continue;
            }

            foreach (Url_Builder::for_post($post) as $url) {
                $urls[$url] = true;
            }
        }

        $list = array_keys($urls);

        /**
         * Filter the URL list about to be purged after content changes.
         *
         * @param list<string> $list     Absolute URLs.
         * @param list<int>    $post_ids Recorded post IDs for this request.
         */
        $filtered = apply_filters('devart_wpcloudflare_urls_to_purge', $list, $post_ids);

        if (!is_array($filtered)) {
            $filtered = $list;
        }

        $deduped = [];

        foreach ($filtered as $url) {
            if (!is_string($url)) {
                continue;
            }

            $url = esc_url_raw(trim($url));

            if ($url === '') {
                continue;
            }

            $deduped[$url] = true;
        }

        $all = array_keys($deduped);

        if ($all === []) {
            return;
        }

        // Persist first so a crash mid-request does not lose work.
        if (!Purge_Queue::enqueue($all)) {
            self::fallback_direct_purge($all, 'auto_save');

            return;
        }

        $async = self::finish_client_response();
        $limit = $async ? self::MAX_URLS : self::INLINE_CHUNK;
        $timeout = $async ? 10 : 5;
        $batch = Purge_Queue::claim($limit);

        if ($batch === null) {
            Purge_Queue::ensure_scheduled();

            return;
        }

        self::process_batch($batch, 'auto_save', $timeout, $async);
    }

    /**
     * Best-effort purge when the durable queue table is unavailable (no retry).
     *
     * @param list<string> $urls
     */
    private static function fallback_direct_purge(array $urls, string $context): void
    {
        Purge_Service::purge_urls($urls, $context, 5);
        Results::store([
            'success' => false,
            'message' => __('Queue table unavailable; purged without retry.', 'devart-wpcloudflare'),
            'context' => $context,
        ]);
    }

    /**
     * @param array{batch_id: string, urls: list<string>} $batch
     */
    private static function process_batch(array $batch, string $context, int $timeout, bool $note_remaining = false): void
    {
        $pending_before = Purge_Queue::pending_count();
        $result = Purge_Service::purge_urls($batch['urls'], $context, $timeout);

        if (!empty($result['success']) || !empty($result['non_retryable'])) {
            Purge_Queue::ack($batch['batch_id']);

            if ($note_remaining && !empty($result['success']) && $pending_before > 0) {
                Results::store([
                    'success' => true,
                    'message' => sprintf(
                        /* translators: 1: original message, 2: remaining URL count */
                        __('%1$s (%2$d URL(s) still queued.)', 'devart-wpcloudflare'),
                        (string) ($result['message'] ?? ''),
                        $pending_before
                    ),
                    'context' => $context,
                ]);
                Purge_Queue::ensure_scheduled();
            }

            return;
        }

        $failed = isset($result['failed']) && is_array($result['failed']) && $result['failed'] !== []
            ? array_values(array_filter($result['failed'], 'is_string'))
            : $batch['urls'];

        $permanent = Purge_Service::is_permanent_failure((int) ($result['http_status'] ?? 0));
        Purge_Queue::nack(
            $batch['batch_id'],
            $failed,
            $permanent,
            (int) ($result['retry_after'] ?? 0)
        );
    }

    private static function finish_client_response(): bool
    {
        if (function_exists('fastcgi_finish_request')) {
            $done = fastcgi_finish_request();

            return $done === true || $done === null;
        }

        if (function_exists('litespeed_finish_request')) {
            $done = litespeed_finish_request();

            return $done === true || $done === null;
        }

        return false;
    }

    private static function record_comment_post(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }

        $post = get_post($post_id);

        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return;
        }

        if (!self::should_record($post)) {
            return;
        }

        // A comment only changes the article page — not home/archives/feed.
        $permalink = get_permalink($post);

        if (is_string($permalink)) {
            self::record_url($permalink);
        }
    }

    private static function record_post(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }

        self::$post_ids[$post_id] = true;
        self::ensure_flush_registered();
    }

    private static function record_url(string $url): void
    {
        $url = esc_url_raw(trim($url));

        if ($url === '') {
            return;
        }

        self::$urls[$url] = true;
        self::ensure_flush_registered();
    }

    private static function ensure_flush_registered(): void
    {
        if (self::$flush_registered) {
            return;
        }

        self::$flush_registered = true;
        add_action('shutdown', [self::class, 'flush_queue'], 999);
    }

    private static function should_record(\WP_Post $post): bool
    {
        if (!self::guards_pass()) {
            return false;
        }

        if (wp_is_post_autosave($post->ID) || wp_is_post_revision($post->ID)) {
            return false;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }

        return self::is_public_post_type($post->post_type);
    }

    private static function guards_pass(): bool
    {
        if (!Options::get()['auto_purge_on_save']) {
            return false;
        }

        if (!Options::has_credentials()) {
            return false;
        }

        if (function_exists('wp_installing') && wp_installing()) {
            return false;
        }

        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return false;
        }

        return true;
    }

    private static function is_public_post_type(string $post_type): bool
    {
        if (!post_type_exists($post_type)) {
            return false;
        }

        $post_type_object = get_post_type_object($post_type);

        return $post_type_object !== null && !empty($post_type_object->public);
    }
}

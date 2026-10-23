<?php
/**
 * Build the Cloudflare purge URL set for a post.
 *
 * @package DevArt\WpCloudflare
 */

declare(strict_types=1);

namespace DevArt\WpCloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Url_Builder
{
    /** @var array{0: string, 1: list<string>}|null */
    private static ?array $extra_urls_memo = null;

    /**
     * Absolute URLs related to a post that should be purged after editorial changes.
     *
     * Page 1 only — no pagination paths.
     *
     * @return list<string>
     */
    public static function for_post(\WP_Post $post): array
    {
        $urls = [];

        self::add_url($urls, get_permalink($post));
        self::add_url($urls, home_url('/'));

        $posts_page_id = (int) get_option('page_for_posts');

        if ($posts_page_id > 0) {
            self::add_url($urls, get_permalink($posts_page_id));
        }

        self::add_url($urls, get_feed_link());

        $taxonomies = get_object_taxonomies($post->post_type, 'objects');

        if (is_array($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                if (!is_object($taxonomy) || empty($taxonomy->public) || empty($taxonomy->name)) {
                    continue;
                }

                $terms = get_the_terms($post, (string) $taxonomy->name);

                if (!is_array($terms)) {
                    continue;
                }

                foreach ($terms as $term) {
                    if (!$term instanceof \WP_Term) {
                        continue;
                    }

                    self::add_term_urls($urls, $term);
                }
            }
        }

        self::add_url($urls, get_author_posts_url((int) $post->post_author));

        $archive = get_post_type_archive_link($post->post_type);

        if (is_string($archive)) {
            self::add_url($urls, $archive);
        }

        foreach (self::extra_urls() as $extra) {
            self::add_url($urls, $extra);
        }

        return array_keys($urls);
    }

    /**
     * @return list<string>
     */
    private static function extra_urls(): array
    {
        $raw = Options::get()['extra_urls'];

        if (self::$extra_urls_memo !== null && self::$extra_urls_memo[0] === $raw) {
            return self::$extra_urls_memo[1];
        }

        $parsed = Url_Filter::parse_urls($raw);
        self::$extra_urls_memo = [$raw, $parsed];

        return $parsed;
    }

    /**
     * Term archive URL plus hierarchical ancestors (page 1 only).
     *
     * @return list<string>
     */
    public static function for_term(\WP_Term $term): array
    {
        $urls = [];
        self::add_term_urls($urls, $term);

        return array_keys($urls);
    }

    /**
     * @param array<string, true> $urls
     */
    private static function add_term_urls(array &$urls, \WP_Term $term): void
    {
        $link = get_term_link($term);

        if (!is_wp_error($link)) {
            self::add_url($urls, $link);
        }

        if (!is_taxonomy_hierarchical($term->taxonomy)) {
            return;
        }

        $ancestors = get_ancestors($term->term_id, $term->taxonomy, 'taxonomy');

        foreach ($ancestors as $ancestor_id) {
            $ancestor_link = get_term_link((int) $ancestor_id, $term->taxonomy);

            if (!is_wp_error($ancestor_link)) {
                self::add_url($urls, $ancestor_link);
            }
        }
    }

    /**
     * @param array<string, true> $urls
     */
    private static function add_url(array &$urls, mixed $url): void
    {
        if (!is_string($url)) {
            return;
        }

        $url = esc_url_raw(trim($url));

        if ($url === '') {
            return;
        }

        $urls[$url] = true;
    }
}

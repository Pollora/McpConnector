<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Formatting;

use WP_Post;

defined('ABSPATH') || exit;

/**
 * Renders posts into the flat, predictable shape abilities return.
 *
 * Every content ability answers with the same keys so a model only has to learn
 * one record layout. Two levels of detail are offered because the difference
 * matters: post content is unbounded, and returning it for a fifty-item listing
 * is the fastest way to exhaust a context window.
 */
final class PostFormatter
{
    /**
     * Not instantiable: every member is a static factory.
     */
    private function __construct()
    {
    }

    /**
     * Summarise a post, without its body.
     *
     * @param WP_Post $post The post to render.
     *
     * @return array<string, mixed> The summary record.
     */
    public static function summary(WP_Post $post): array
    {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'author' => get_the_author_meta('display_name', (int) $post->post_author),
            'excerpt' => $post->post_excerpt,
            'parent' => $post->post_parent,
            'menu_order' => $post->menu_order,
            'url' => get_permalink($post) ?: null,
            'edit_url' => get_edit_post_link($post->ID, 'raw'),
        ];
    }

    /**
     * Render a post in full, body and taxonomy terms included.
     *
     * @param WP_Post $post The post to render.
     *
     * @return array<string, mixed> The complete record.
     */
    public static function full(WP_Post $post): array
    {
        return self::summary($post) + [
            'content' => $post->post_content,
            'featured_image_id' => get_post_thumbnail_id($post) ?: null,
            'featured_image_url' => get_the_post_thumbnail_url($post, 'full') ?: null,
            'page_template' => get_post_meta($post->ID, '_wp_page_template', true) ?: null,
            'terms' => self::terms($post),
            // Only what the site declares. See PostMeta for why guessing at the
            // rest is worse than omitting it.
            'meta' => PostMeta::read($post),
        ];
    }

    /**
     * Summarise an attachment.
     *
     * @param WP_Post $attachment The attachment to render.
     *
     * @return array<string, mixed> The attachment record.
     */
    public static function attachment(WP_Post $attachment): array
    {
        $metadata = wp_get_attachment_metadata($attachment->ID);

        return [
            'id' => $attachment->ID,
            'title' => $attachment->post_title,
            'url' => wp_get_attachment_url($attachment->ID) ?: null,
            'mime_type' => $attachment->post_mime_type,
            'alt_text' => get_post_meta($attachment->ID, '_wp_attachment_image_alt', true) ?: '',
            'caption' => $attachment->post_excerpt,
            'description' => $attachment->post_content,
            'width' => is_array($metadata) ? ($metadata['width'] ?? null) : null,
            'height' => is_array($metadata) ? ($metadata['height'] ?? null) : null,
            'date' => $attachment->post_date,
        ];
    }

    /**
     * Collect a post's terms, grouped by taxonomy and reported by slug.
     *
     * Slugs rather than identifiers, because slugs are what the write abilities
     * accept: a model can read a post, copy its terms onto another, and never
     * need a lookup in between.
     *
     * @param WP_Post $post The post whose terms to collect.
     *
     * @return array<string, list<string>> Taxonomy name to list of term slugs.
     */
    private static function terms(WP_Post $post): array
    {
        $grouped = [];

        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $terms = get_the_terms($post, $taxonomy);

            if (! is_array($terms) || $terms === []) {
                continue;
            }

            $grouped[$taxonomy] = array_values(array_map(
                static fn (\WP_Term $term): string => $term->slug,
                $terms
            ));
        }

        return $grouped;
    }
}

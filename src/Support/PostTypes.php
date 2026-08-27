<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Support;

use Pollora\McpConnector\Settings;
use WP_Post_Type;

defined('ABSPATH') || exit;

/**
 * Decides which post types a client may address, and which capability guards each.
 *
 * Two problems, both of which come from treating `post_type` as a free string.
 *
 * The first is reach. A site accumulates post types nobody thinks of as content:
 * `od_url_metrics` holds several hundred rows of performance telemetry,
 * `conversational_form` holds form definitions, and a RAG corpus may sit in a
 * non-public type of its own. None of them should be reachable by a client that
 * happens to guess the slug, and none of them are content a model has any
 * business listing. The addressable set is therefore explicit.
 *
 * The second is permissions. Checking `edit_posts` for every type is wrong the
 * moment a type declares its own `capability_type` — a common pattern for
 * editorial or internal content, and one this site uses. The capability to check
 * lives on the post type object; asking it is both more correct and less code
 * than maintaining a mapping.
 */
final class PostTypes
{
    /**
     * Filter through which the addressable set can be adjusted in code.
     *
     * @var string
     */
    public const FILTER = 'mcp_connector_addressable_post_types';

    /**
     * Post types never offered, whatever the configuration says.
     *
     * Revisions and menu items are addressed through their own abilities or not
     * at all; exposing them as ordinary content produces confusing results
     * rather than useful ones.
     *
     * @var list<string>
     */
    private const NEVER = [
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_global_styles',
    ];

    /**
     * Not instantiable: every member is a static helper.
     */
    private function __construct()
    {
    }

    /**
     * The post types a client may address.
     *
     * @return list<string> Post type slugs.
     */
    public static function addressable(): array
    {
        $settings = Settings::current();

        $types = $settings->postTypesReviewed
            ? $settings->addressablePostTypes
            : self::defaults();

        /** @var list<string> $types */
        $types = apply_filters(self::FILTER, $types);

        return array_values(array_filter(
            array_unique(array_map('strval', $types)),
            static fn (string $type): bool => post_type_exists($type) && ! in_array($type, self::NEVER, true)
        ));
    }

    /**
     * The addressable set for a site that has never configured one.
     *
     * Public *and* declared to the REST API. Either condition alone lets
     * something through that should not be there: `piece` is non-public but
     * would pass a REST-only test on some sites, and `pdf_resource` is public
     * while declaring nothing to REST. Requiring both is the conservative
     * reading, and anything wrongly excluded can be added back deliberately.
     *
     * Attachments are added regardless: the media abilities need them, and they
     * are already governed by `upload_files`.
     *
     * @return list<string> Post type slugs.
     */
    public static function defaults(): array
    {
        $types = get_post_types(['public' => true, 'show_in_rest' => true], 'names');

        $types['attachment'] = 'attachment';

        return array_values($types);
    }

    /**
     * Every post type that could be offered on the settings screen.
     *
     * @return list<WP_Post_Type> The post type objects, excluding the never-offered ones.
     */
    public static function offerable(): array
    {
        return array_values(array_filter(
            get_post_types([], 'objects'),
            static fn (WP_Post_Type $type): bool => ! in_array($type->name, self::NEVER, true)
        ));
    }

    /**
     * Whether a post type may be addressed at all.
     *
     * @param string $type Post type slug.
     *
     * @return bool True when the type is in the addressable set.
     */
    public static function isAddressable(string $type): bool
    {
        return in_array($type, self::addressable(), true);
    }

    /**
     * Whether the current user may list and read a post type's items.
     *
     * @param string $type Post type slug.
     *
     * @return bool True when reading is permitted.
     */
    public static function canRead(string $type): bool
    {
        $object = get_post_type_object($type);

        return self::isAddressable($type)
            && $object instanceof WP_Post_Type
            && current_user_can($object->cap->edit_posts);
    }

    /**
     * Whether the current user may create items of a post type.
     *
     * @param string $type Post type slug.
     *
     * @return bool True when creation is permitted.
     */
    public static function canCreate(string $type): bool
    {
        $object = get_post_type_object($type);

        return self::isAddressable($type)
            && $object instanceof WP_Post_Type
            && current_user_can($object->cap->create_posts);
    }

    /**
     * Whether the current user may edit one specific post.
     *
     * Goes through `edit_post` rather than the type's blanket `edit_posts`, so
     * ownership and status rules apply — an author editing somebody else's
     * published post is refused here exactly as it would be in wp-admin.
     *
     * @param int $postId Post identifier.
     *
     * @return bool True when editing is permitted.
     */
    public static function canEditPost(int $postId): bool
    {
        $type = get_post_type($postId);

        return $type !== false
            && self::isAddressable($type)
            && current_user_can('edit_post', $postId);
    }

    /**
     * Whether the current user may read one specific post.
     *
     * @param int $postId Post identifier.
     *
     * @return bool True when reading is permitted.
     */
    public static function canReadPost(int $postId): bool
    {
        $type = get_post_type($postId);

        return $type !== false
            && self::isAddressable($type)
            && current_user_can('read_post', $postId);
    }

    /**
     * Whether the current user may delete one specific post.
     *
     * @param int $postId Post identifier.
     *
     * @return bool True when deletion is permitted.
     */
    public static function canDeletePost(int $postId): bool
    {
        $type = get_post_type($postId);

        return $type !== false
            && self::isAddressable($type)
            && current_user_can('delete_post', $postId);
    }

    /**
     * An error explaining that a post type is not addressable.
     *
     * Names what *is* available, because "unknown post type" with no list is a
     * dead end for a caller that cannot see the settings screen.
     *
     * @param string $type The refused post type slug.
     *
     * @return \WP_Error The error.
     */
    public static function notAddressable(string $type): \WP_Error
    {
        return Failure::invalid(sprintf(
            post_type_exists($type)
                ? 'The post type "%1$s" is not exposed through this connector. Available types: %2$s.'
                : 'There is no post type "%1$s" on this site. Available types: %2$s.',
            $type,
            implode(', ', self::addressable())
        ));
    }
}

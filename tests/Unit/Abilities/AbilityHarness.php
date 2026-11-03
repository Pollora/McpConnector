<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Tests\Unit\Abilities;

use Brain\Monkey\Functions;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\McpConnector\Abilities\AbilityDefinition;
use Pollora\McpConnector\Abilities\AbilityGroup;

/**
 * Shared plumbing for the ability group tests: find a definition, run it the way
 * the registry would, and fake the current user's capabilities.
 */
final class AbilityHarness
{
    /**
     * Capabilities the fake current user holds. A bare name grants the capability
     * whatever its arguments; `name:id` grants it for that object only.
     *
     * @var list<string>
     */
    public static array $granted = [];

    private function __construct()
    {
    }

    /**
     * Find one definition of a group by its unnamespaced slug.
     */
    public static function definition(AbilityGroup $group, string $slug): AbilityDefinition
    {
        foreach ($group->definitions() as $definition) {
            if ($definition->slug === $slug) {
                return $definition;
            }
        }

        throw new \RuntimeException(sprintf('No ability "%s" in group "%s".', $slug, $group->key()));
    }

    /**
     * Run an ability body on the given raw input.
     *
     * @param array<string, mixed> $input
     */
    public static function run(AbilityDefinition $definition, array $input = []): mixed
    {
        return ($definition->execute)(Input::wrap($input));
    }

    /**
     * Run an ability's permission callback on the given raw input.
     *
     * @param array<string, mixed> $input
     */
    public static function permitted(AbilityDefinition $definition, array $input = []): bool|\WP_Error
    {
        return ($definition->permission)(Input::wrap($input));
    }

    /**
     * Fake the current user and the helpers every ability leans on.
     *
     * @param list<string> $capabilities See {@see self::$granted}.
     */
    public static function actAs(array $capabilities): void
    {
        self::$granted = $capabilities;

        Functions\when('current_user_can')->alias(
            static function (string $capability, mixed ...$args): bool {
                if (in_array($capability, AbilityHarness::$granted, true)) {
                    return true;
                }

                return isset($args[0]) && is_scalar($args[0])
                    && in_array($capability . ':' . $args[0], AbilityHarness::$granted, true);
            },
        );
        Functions\when('is_wp_error')->alias(static fn (mixed $value): bool => $value instanceof \WP_Error);
    }

    /**
     * Let the formatter helpers run: they only need to return something stable.
     */
    public static function stubPostFormatting(): void
    {
        Functions\when('get_the_author_meta')->justReturn('Ada');
        Functions\when('get_permalink')->alias(static fn (\WP_Post $post): string => 'https://example.test/?p=' . $post->ID);
        Functions\when('get_edit_post_link')->alias(static fn (int $id): string => 'https://example.test/wp-admin/post.php?post=' . $id);
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_object_taxonomies')->justReturn([]);
        Functions\when('get_registered_meta_keys')->justReturn([]);
        Functions\when('wp_get_attachment_metadata')->justReturn(false);
        Functions\when('wp_get_attachment_url')->justReturn('https://example.test/up/a.jpg');
    }

    /**
     * Build a post double.
     *
     * @param array<string, int|string> $properties
     */
    public static function post(int $id, array $properties = []): \WP_Post
    {
        return new \WP_Post(['ID' => $id, 'post_title' => 'Post ' . $id, 'post_name' => 'post-' . $id] + $properties);
    }

    /**
     * Make get_post() and get_post_type() resolve exactly these posts.
     */
    public static function posts(\WP_Post ...$posts): void
    {
        $byId = [];

        foreach ($posts as $post) {
            $byId[$post->ID] = $post;
        }

        Functions\when('get_post')->alias(
            static fn (mixed $id): ?\WP_Post => $byId[$id instanceof \WP_Post ? $id->ID : (int) $id] ?? null,
        );
        Functions\when('get_post_type')->alias(
            static fn (mixed $id): string|false => isset($byId[(int) $id]) ? $byId[(int) $id]->post_type : false,
        );
    }

    /**
     * Make taxonomy_exists() true for exactly these taxonomies.
     *
     * @param list<string> $taxonomies
     */
    public static function taxonomies(array $taxonomies): void
    {
        Functions\when('taxonomy_exists')->alias(static fn (string $name): bool => in_array($name, $taxonomies, true));
    }

    /**
     * Make the given post types the addressable set.
     *
     * @param list<string> $types
     */
    public static function addressable(array $types): void
    {
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $name === 'mcp_connector_settings' ? ['post_types_reviewed' => true, 'addressable_post_types' => $types] : $default);
        \Pollora\McpConnector\Settings::forget();

        Functions\when('post_type_exists')->alias(static fn (string $type): bool => in_array($type, ['post', 'page', 'attachment', 'book', 'revision'], true));
        Functions\when('get_post_type_object')->alias(static function (string $type): ?\WP_Post_Type {
            if (! in_array($type, ['post', 'page', 'attachment', 'book', 'revision'], true)) {
                return null;
            }

            $object = new \WP_Post_Type($type, ucfirst($type));
            $object->cap->create_posts = $type === 'book' ? 'create_books' : 'edit_posts';

            if ($type === 'book') {
                $object->cap->edit_posts = 'edit_books';
            }

            return $object;
        });
    }
}

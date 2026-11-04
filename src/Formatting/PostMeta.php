<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Formatting;

use Pollora\McpConnector\Support\Failure;

defined('ABSPATH') || exit;

/**
 * Reads and writes the custom fields a site has *declared*.
 *
 * Declaration means `register_post_meta()` with `show_in_rest`. That is the only
 * contract this plugin will act on, and the restriction is deliberate rather
 * than conservative.
 *
 * The alternative — writing any key that does not begin with an underscore, as
 * an earlier version of this plugin did — creates a nasty asymmetry. On this
 * site it meant a client could set `telephone` on a directory listing and had no
 * way to read it back, because nothing knew the field existed. Writing values
 * you cannot verify, into fields whose type and meaning you are guessing, is
 * worse than refusing.
 *
 * Declaring a field costs three lines and buys a type, a permission callback and
 * automatic exposure here and in the REST API. Fields belonging to a framework
 * that manages its own schema — Meta Box, and any other plugin publishing
 * abilities — are better reached through that framework's own abilities, which
 * this connector republishes.
 */
final class PostMeta
{
    /**
     * Not instantiable: every member is a static helper.
     */
    private function __construct()
    {
    }

    /**
     * The declared meta keys for a post type, with their schemas.
     *
     * Merges the keys registered for this specific post type with those
     * registered for posts in general, which is where core puts `footnotes` and
     * where a plugin lands when it calls `register_meta()` without a subtype.
     *
     * @param string $postType Post type slug.
     *
     * @return array<string, array<string, mixed>> Meta key to its registration arguments.
     */
    public static function declaredFor(string $postType): array
    {
        $specific = get_registered_meta_keys('post', $postType);
        $general = get_registered_meta_keys('post');

        $declared = array_merge($general, $specific);

        return array_filter($declared, static fn (array $args): bool => ! empty($args['show_in_rest']));
    }

    /**
     * Read a post's declared meta.
     *
     * @param \WP_Post $post The post.
     *
     * @return array<string, mixed> Meta key to value; empty when the type declares nothing.
     */
    public static function read(\WP_Post $post): array
    {
        $values = [];

        foreach (self::declaredFor($post->post_type) as $key => $args) {
            $single = (bool) ($args['single'] ?? false);

            $values[$key] = get_post_meta($post->ID, $key, $single);
        }

        return $values;
    }

    /**
     * Describe the declared meta of a post type, for a model deciding what to send.
     *
     * @param string $postType Post type slug.
     *
     * @return list<array<string, mixed>> One entry per declared key.
     */
    public static function describe(string $postType): array
    {
        $described = [];

        foreach (self::declaredFor($postType) as $key => $args) {
            $described[] = [
                'key' => $key,
                'type' => (string) ($args['type'] ?? 'string'),
                'single' => (bool) ($args['single'] ?? false),
                'description' => (string) ($args['description'] ?? ''),
                'protected' => is_protected_meta($key, 'post'),
            ];
        }

        return $described;
    }

    /**
     * Check that declared meta could be written, without writing any of it.
     *
     * Undeclared keys are refused, and so are keys the current user may not
     * edit (`edit_post_meta`, which core maps through to the `auth_callback`
     * given at registration). That second check needs a post to ask about, so
     * it is skipped when `$postId` is null — a post about to be created — and
     * {@see self::write()} repeats it once the post exists.
     *
     * @param string               $postType Type of the post the meta is for.
     * @param array<string, mixed> $values   Meta key to value.
     * @param int|null             $postId   The post, null when it does not exist yet.
     *
     * @return true|\WP_Error True when every key is acceptable, or an error naming the ones that are not.
     */
    public static function check(string $postType, array $values, ?int $postId): true|\WP_Error
    {
        if ($values === []) {
            return true;
        }

        $declared = self::declaredFor($postType);
        $undeclared = [];
        $refused = [];

        foreach (array_keys($values) as $key) {
            $key = (string) $key;

            if (! isset($declared[$key])) {
                $undeclared[] = $key;

                continue;
            }

            if ($postId !== null && ! current_user_can('edit_post_meta', $postId, $key)) {
                $refused[] = $key;
            }
        }

        if ($refused !== []) {
            return Failure::forbidden(sprintf(
                'write the field(s) %s on post %d',
                implode(', ', $refused),
                $postId,
            ));
        }

        if ($undeclared !== []) {
            $available = array_keys($declared);

            return Failure::invalid(sprintf(
                'The post type "%s" does not declare the field(s): %s. %s',
                $postType,
                implode(', ', $undeclared),
                $available === []
                    ? 'It declares no custom fields at all; fields managed by a framework such as Meta Box are reachable through that plugin\'s own tools.'
                    : 'Declared fields are: ' . implode(', ', $available) . '.',
            ));
        }

        return true;
    }

    /**
     * Write declared meta onto a post.
     *
     * Every key is checked first ({@see self::check()}) and nothing is written
     * unless all of them pass, so a refusal never leaves half the fields saved.
     *
     * Refusals are reported rather than skipped. A silently ignored field is how
     * a model comes to believe it saved something it did not.
     *
     * @param int                  $postId Post to write to.
     * @param array<string, mixed> $values Meta key to value.
     *
     * @return true|\WP_Error True on success, or an error naming the keys that were refused.
     */
    public static function write(int $postId, array $values): true|\WP_Error
    {
        if ($values === []) {
            return true;
        }

        $postType = get_post_type($postId);

        if ($postType === false) {
            return Failure::notFound('post', $postId);
        }

        $checked = self::check($postType, $values, $postId);

        if ($checked instanceof \WP_Error) {
            return $checked;
        }

        foreach ($values as $key => $value) {
            update_post_meta($postId, (string) $key, $value);
        }

        return true;
    }
}

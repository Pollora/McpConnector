<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities\Group;

use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\AbilityDefinition;
use Pollora\McpConnector\Abilities\AbilityGroup;
use Pollora\McpConnector\Abilities\Annotations;
use Pollora\McpConnector\Abilities\Input;
use Pollora\McpConnector\Abilities\Schema;
use Pollora\McpConnector\Formatting\FieldDiscovery;
use Pollora\McpConnector\Formatting\PostMeta;
use Pollora\McpConnector\Support\Failure;

defined('ABSPATH') || exit;

/**
 * Site-wide information and a narrow slice of the options table.
 *
 * Option writing is restricted to an explicit allow-list, not to a capability.
 * `manage_options` covers `siteurl`, `home`, `users_can_register`,
 * `default_role` and every serialised blob a plugin has ever stashed in
 * `wp_options`: one confused tool call against that surface can lock everyone
 * out of the site. Sites that need more can widen the list through the
 * {@see SiteGroup::WRITABLE_OPTIONS_FILTER} filter, deliberately and one key at
 * a time.
 */
final class SiteGroup implements AbilityGroup
{
    /**
     * Stable group key stored in the settings option.
     *
     * @var string
     */
    public const KEY = 'site';

    /**
     * Filter through which a site widens the set of writable options.
     *
     * @var string
     */
    public const WRITABLE_OPTIONS_FILTER = 'mcp_connector_writable_options';

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('Site information', 'amphibee-mcp-connector');
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return __(
            'Read site information, registered post types and the active theme, and update the site title and tagline.',
            'amphibee-mcp-connector',
        );
    }

    /**
     * {@inheritDoc}
     */
    public function definitions(): array
    {
        return [
            $this->getSiteInfo(),
            $this->listPostTypes(),
            $this->updateOption(),
        ];
    }

    /**
     * Options an ability may write, after filtering.
     *
     * @return list<string> Allowed option names.
     */
    public static function writableOptions(): array
    {
        /** @var list<string> $options */
        $options = apply_filters(self::WRITABLE_OPTIONS_FILTER, ['blogname', 'blogdescription']);

        return array_values(array_unique(array_map('strval', $options)));
    }

    /**
     * Describe the site.
     *
     * @return AbilityDefinition The ability.
     */
    private function getSiteInfo(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-site-info',
            label: __('Read site information', 'amphibee-mcp-connector'),
            description: 'Describe the site: title, tagline, URL, language, timezone, WordPress version, '
                . 'active theme, and which options this connector is allowed to write. Call this first '
                . 'when you know nothing about the site.',
            category: AbilityCategory::Site,
            inputSchema: Schema::object(),
            execute: static function (): array {
                $theme = wp_get_theme();

                return [
                    'name' => get_bloginfo('name'),
                    'description' => get_bloginfo('description'),
                    'url' => home_url(),
                    'admin_url' => admin_url(),
                    'language' => get_bloginfo('language'),
                    'timezone' => wp_timezone_string(),
                    'wp_version' => get_bloginfo('version'),
                    'is_block_theme' => wp_is_block_theme(),
                    'theme' => [
                        'name' => $theme->get('Name'),
                        'version' => $theme->get('Version'),
                        'stylesheet' => $theme->get_stylesheet(),
                    ],
                    'writable_options' => self::writableOptions(),
                ];
            },
            permission: static fn (): bool => current_user_can('edit_posts'),
        );
    }

    /**
     * List the post types registered on the site.
     *
     * @return AbilityDefinition The ability.
     */
    private function listPostTypes(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-post-types',
            label: __('List post types', 'amphibee-mcp-connector'),
            description: 'List the post types this site registers, with their labels, hierarchy, taxonomies '
                . 'and custom fields. Call this before creating content, so you use a post type that '
                . 'exists here and only send fields it accepts. Fields under declared_meta go in the meta '
                . 'map of create-post and update-post; fields under framework_fields are read and written '
                . 'with the owning plugin\'s own tools.',
            category: AbilityCategory::Site,
            inputSchema: Schema::object([
                'public_only' => Schema::boolean('Return only publicly queryable post types.', true),
            ]),
            execute: static function (Input $input): array {
                $args = $input->boolean('public_only', true) ? ['public' => true] : [];

                $types = array_map(
                    static fn (\WP_Post_Type $type): array => [
                        'name' => $type->name,
                        'label' => $type->label,
                        'hierarchical' => $type->hierarchical,
                        'supports' => array_keys(get_all_post_type_supports($type->name)),
                        'taxonomies' => array_values(get_object_taxonomies($type->name)),
                        // The custom fields this type declares, so a caller
                        // knows what it may put in the `meta` map of create-post
                        // and update-post without having to try one and be
                        // refused. An empty list does not mean the type has no
                        // custom fields — only that none are declared to the
                        // REST API; fields owned by a framework such as Meta Box
                        // are described by that plugin's own tools.
                        'declared_meta' => PostMeta::describe($type->name),
                        // Fields a framework such as Meta Box owns. They are
                        // reported here because nothing else would reveal that
                        // they exist, but they are read and written through
                        // that framework's own tools, not through the `meta`
                        // map — it knows their types and this does not.
                        'framework_fields' => FieldDiscovery::frameworkFields($type->name),
                    ],
                    array_values(get_post_types($args, 'objects')),
                );

                return ['post_types' => $types];
            },
            permission: static fn (): bool => current_user_can('edit_posts'),
        );
    }

    /**
     * Update one allow-listed option.
     *
     * @return AbilityDefinition The ability.
     */
    private function updateOption(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'update-option',
            label: __('Update a site option', 'amphibee-mcp-connector'),
            description: 'Update one of the site options this connector is allowed to write. '
                . 'Call get-site-info for the current list; by default it is the site title and tagline only.',
            category: AbilityCategory::Site,
            inputSchema: Schema::object([
                'option_name' => Schema::string('Option to update. Must be one of the writable_options reported by get-site-info.'),
                'option_value' => Schema::string('New value.'),
            ], ['option_name', 'option_value']),
            execute: static function (Input $input): array|\WP_Error {
                $name = $input->string('option_name');
                $allowed = self::writableOptions();

                if (! in_array($name, $allowed, true)) {
                    return Failure::forbidden(sprintf(
                        'write the option "%s". This connector may only write: %s',
                        $name,
                        implode(', ', $allowed),
                    ));
                }

                update_option($name, sanitize_text_field($input->string('option_value')));

                return ['option' => $name, 'value' => get_option($name)];
            },
            permission: static fn (): bool => current_user_can('manage_options'),
            annotations: Annotations::updates(),
        );
    }
}

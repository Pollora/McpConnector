<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities;

use Pollora\McpConnector\Abilities\Group\ContentGroup;
use Pollora\McpConnector\Abilities\Group\MediaGroup;
use Pollora\McpConnector\Abilities\Group\NavigationGroup;
use Pollora\McpConnector\Abilities\Group\SiteGroup;
use Pollora\McpConnector\Abilities\Group\TaxonomyGroup;
use Pollora\McpConnector\Abilities\Group\UserGroup;
use Pollora\McpConnector\OAuth\Scope;
use Pollora\McpConnector\Settings;
use Pollora\McpConnector\Support\Failure;

defined('ABSPATH') || exit;

/**
 * Turns {@see AbilityGroup} declarations into registered WordPress abilities.
 *
 * This is the only place that knows about the Abilities API, which keeps the
 * groups themselves free of registration boilerplate and makes the namespacing
 * rules — ability prefix, category prefix, MCP metadata — apply uniformly.
 *
 * Registration hooks are attached eagerly. The abilities registry initialises
 * lazily, the first time anything asks for an ability, so there is no single
 * later moment that is reliably early enough to hook from.
 */
final class AbilityRegistry
{
    /**
     * Filter through which third parties add or remove ability groups.
     *
     * @var string
     */
    public const GROUPS_FILTER = 'mcp_connector_ability_groups';

    /**
     * Fully-qualified names of every ability registered during this request.
     *
     * Collected so {@see \Pollora\McpConnector\Server\ServerRegistry} can hand
     * the exact list to the MCP server without rediscovering it, and so the
     * settings screen can report what is actually live.
     *
     * @var list<string>
     */
    private static array $registered = [];

    /**
     * @param Settings $settings Resolved plugin configuration.
     */
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Attach the two registration hooks.
     */
    public function register(): void
    {
        add_action('wp_abilities_api_categories_init', $this->registerCategories(...));
        add_action('wp_abilities_api_init', $this->registerAbilities(...));
    }

    /**
     * Every group the plugin knows about, before the enabled/disabled filter.
     *
     * @return list<AbilityGroup> The available groups, in display order.
     */
    public static function availableGroups(): array
    {
        /** @var list<AbilityGroup> $groups */
        $groups = apply_filters(self::GROUPS_FILTER, [
            new ContentGroup(),
            new TaxonomyGroup(),
            new MediaGroup(),
            new NavigationGroup(),
            new UserGroup(),
            new SiteGroup(),
        ]);

        return array_values(array_filter(
            $groups,
            static fn (mixed $group): bool => $group instanceof AbilityGroup
        ));
    }

    /**
     * Group keys enabled on a fresh install.
     *
     * Everything except user listing, which exposes login names and role
     * assignments that most integrations never need. Whether these groups may
     * write is a separate question, answered by the read-only switch.
     *
     * @return list<string> The default group keys.
     */
    public static function defaultGroupKeys(): array
    {
        return [
            ContentGroup::KEY,
            TaxonomyGroup::KEY,
            MediaGroup::KEY,
            NavigationGroup::KEY,
            SiteGroup::KEY,
        ];
    }

    /**
     * Ability names registered during this request.
     *
     * @return list<string> Fully-qualified ability names, e.g. `wp-mcp/get-posts`.
     */
    public static function registeredAbilityNames(): array
    {
        return self::$registered;
    }

    /**
     * Register one namespaced category per category the enabled groups use.
     *
     * Category slugs are global, and core itself already claims `site` and
     * `user`. Prefixing with the configured ability namespace keeps us clear of
     * core and of any other plugin, at the cost of a slightly uglier slug that
     * no human reads anyway.
     */
    public function registerCategories(): void
    {
        foreach (AbilityCategory::cases() as $category) {
            $slug = $this->categorySlug($category);

            // Belt and braces: a second registration of the same slug is a
            // _doing_it_wrong() notice, and on a site running with WP_DEBUG on
            // that lands in the REST response body and breaks the MCP transport.
            if (wp_has_ability_category($slug)) {
                continue;
            }

            wp_register_ability_category($slug, [
                'label' => $category->label(),
                'description' => $category->description(),
            ]);
        }
    }

    /**
     * Register the abilities of every enabled group.
     */
    public function registerAbilities(): void
    {
        foreach (self::availableGroups() as $group) {
            if (! $this->settings->isGroupEnabled($group->key())) {
                continue;
            }

            foreach ($group->definitions() as $definition) {
                // Read-only mode is enforced by declining to register the
                // ability at all, rather than by failing its permission check.
                // A tool a client never sees cannot be called by mistake, and
                // cannot be described to the model as available.
                if ($this->settings->readOnlyMode && ! $definition->annotations->readonly) {
                    continue;
                }

                $this->registerAbility($definition);
            }
        }
    }

    /**
     * Register a single ability.
     *
     * @param AbilityDefinition $definition The ability to register.
     */
    private function registerAbility(AbilityDefinition $definition): void
    {
        $name = $this->settings->abilityNamespace . '/' . $definition->slug;

        if (wp_has_ability($name)) {
            return;
        }

        $execute = $definition->execute;
        $permission = $definition->permission;

        $registered = wp_register_ability($name, [
            'label' => $definition->label,
            'description' => $definition->description,
            'category' => $this->categorySlug($definition->category),
            'input_schema' => $definition->inputSchema,
            // The Abilities API hands the raw, schema-validated input straight
            // through; wrapping it here means no ability body has to defend
            // against a null or a numeric string on its own.
            'execute_callback' => static fn (mixed $input = null): mixed => $execute(Input::wrap($input)),
            // The permission callback receives the same input as the execute
            // callback, which is what allows per-object checks — `edit_post` on
            // the identifier being edited, rather than a blanket `edit_posts`.
            'permission_callback' => static function (mixed $input = null) use ($permission, $definition): mixed {
                // A token granted only the `read` scope may not reach an ability
                // that changes the site, however capable the user behind it is.
                // This cannot be expressed as a capability check: the read
                // abilities require `edit_posts` too, so stripping write
                // capabilities would take reading down with it.
                if (! $definition->annotations->readonly && ! Scope::currentAllowsWrite()) {
                    return Failure::forbidden(
                        'change the site: the connected application was granted read-only access'
                    );
                }

                return $permission(Input::wrap($input));
            },
            'meta' => [
                'annotations' => $definition->annotations->toArray(),
                // `mcp.public` is what the MCP Adapter's discovery looks for, and
                // `show_in_rest` exposes the ability through the core abilities
                // REST controllers, which is how you test one without an MCP client.
                'show_in_rest' => true,
                'mcp' => [
                    'public' => true,
                    'type' => 'tool',
                ],
            ],
        ]);

        if ($registered !== null) {
            self::$registered[] = $name;
        }
    }

    /**
     * Namespaced slug of an ability category.
     *
     * @param AbilityCategory $category The category.
     *
     * @return string The prefixed slug, e.g. `wp-mcp-content`.
     */
    private function categorySlug(AbilityCategory $category): string
    {
        return $this->settings->abilityNamespace . '-' . $category->value;
    }
}

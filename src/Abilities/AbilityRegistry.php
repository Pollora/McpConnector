<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities;

use Pollora\Abilities\Adapter\Out\WordPress\WordPressAbilityCategoryRegistrar;
use Pollora\Abilities\Adapter\Out\WordPress\WordPressAbilityRegistrar;
use Pollora\Abilities\Application\Service\RegisterAbilityService;
use Pollora\Abilities\Domain\Model\Ability;
use Pollora\Abilities\Domain\Model\Input;
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
 * Registration itself is delegated to the `pollora/abilities` package: this class
 * decides *what* to publish and under which name, the package decides *how* and
 * *when*. What stays here is the policy the package deliberately has no opinion
 * about — the configurable namespace, read-only mode, OAuth scope enforcement,
 * and which groups a site has enabled.
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
     * The package service the declarations are queued on.
     */
    private readonly RegisterAbilityService $service;

    /**
     * @param Settings $settings Resolved plugin configuration.
     */
    public function __construct(private readonly Settings $settings)
    {
        $this->service = new RegisterAbilityService(
            new WordPressAbilityRegistrar(),
            new WordPressAbilityCategoryRegistrar(),
        );
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
            static fn (mixed $group): bool => $group instanceof AbilityGroup,
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
            // ensureCategory() skips a slug already registered by somebody else,
            // where queueCategory() would attempt a second registration — which
            // is a _doing_it_wrong() notice, and on a site running with WP_DEBUG
            // on that lands in the REST response body and breaks the transport.
            $this->service->ensureCategory($category->toPackageCategory($this->settings->abilityNamespace));
        }

        $this->service->flushCategories();
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
                if ($this->settings->readOnlyMode && ! $definition->isReadOnly()) {
                    continue;
                }

                $this->service->queue($this->toAbility($definition));
            }
        }

        self::$registered = [...self::$registered, ...$this->service->flushAbilities()];
    }

    /**
     * Turn a plugin definition into the package's ability model.
     *
     * @param AbilityDefinition $definition The ability to describe.
     *
     * @return Ability The package model, ready to queue.
     */
    private function toAbility(AbilityDefinition $definition): Ability
    {
        return Ability::create(
            name: $this->settings->abilityNamespace . '/' . $definition->slug,
            label: $definition->label,
            description: $definition->description,
            category: $this->settings->abilityNamespace . '-' . $definition->category->value,
            inputSchema: $definition->inputSchema,
            execute: $definition->execute,
            permission: $this->guard($definition),
            behaviour: $definition->behaviour,
            meta: [
                // `mcp.public` is what the MCP Adapter's discovery looks for. The
                // package sets `show_in_rest` itself, which exposes the ability
                // through the core abilities REST controllers — that is how you
                // test one without an MCP client.
                'mcp' => [
                    'public' => true,
                    'type' => 'tool',
                ],
            ],
        );
    }

    /**
     * Wrap a definition's permission check with the OAuth scope gate.
     *
     * A token granted only the `read` scope may not reach an ability that
     * changes the site, however capable the user behind it is. This cannot be
     * expressed as a capability check: the read abilities require `edit_posts`
     * too, so stripping write capabilities would take reading down with it.
     *
     * @param AbilityDefinition $definition The ability being registered.
     *
     * @return \Closure(Input): mixed The guarded permission callback.
     */
    private function guard(AbilityDefinition $definition): \Closure
    {
        $permission = $definition->permission;
        $readOnly = $definition->isReadOnly();

        return static function (Input $input) use ($permission, $readOnly): mixed {
            if (! $readOnly && ! Scope::currentAllowsWrite()) {
                return Failure::forbidden(
                    'change the site: the connected application was granted read-only access',
                );
            }

            return $permission($input);
        };
    }
}

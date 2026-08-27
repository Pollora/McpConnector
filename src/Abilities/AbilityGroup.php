<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities;

defined('ABSPATH') || exit;

/**
 * A cohesive set of abilities that can be switched on or off as a unit.
 *
 * Grouping is what makes the plugin safe to deploy incrementally: a site can
 * expose reading only, verify the connection end to end, then enable writing.
 * It is also the extension point — a site-specific plugin implements this
 * interface and appends itself through the `mcp_connector_ability_groups`
 * filter to publish its own abilities alongside the built-in ones.
 */
interface AbilityGroup
{
    /**
     * Stable identifier used in the settings option and the admin UI.
     *
     * Changing it orphans the stored preference, which silently disables the
     * group on existing installs. Treat it as permanent.
     *
     * @return string The group key, lowercase with dashes.
     */
    public function key(): string;

    /**
     * Human-readable name shown on the settings screen.
     *
     * @return string The translated label.
     */
    public function label(): string;

    /**
     * One-line summary of what enabling the group grants.
     *
     * @return string The translated description.
     */
    public function description(): string;

    /**
     * The abilities this group publishes.
     *
     * Called during `wp_abilities_api_init`, so it may safely consult
     * registered post types and taxonomies, but not the current user.
     *
     * @return list<AbilityDefinition> The definitions to register.
     */
    public function definitions(): array;
}

<?php

declare(strict_types=1);

namespace Pollora\McpConnector;

defined('ABSPATH') || exit;

/**
 * Immutable view of the plugin configuration.
 *
 * The configuration lives in a single option so that a site can be reconfigured
 * without touching code, but every value is also exposed through a filter for
 * sites that would rather pin their configuration in a mu-plugin. Reading is
 * memoised because the ability registry consults these values dozens of times
 * per request.
 *
 * Instances are immutable, but the class is not marked `@psalm-immutable`: the
 * memo below is mutable static state, and the annotation would make every
 * analyser treat `$current` as a readonly property that may not have a default.
 */
final class Settings
{
    /**
     * Option name holding the serialised configuration.
     *
     * @var string
     */
    public const OPTION = 'mcp_connector_settings';

    /**
     * Filter applied to the resolved configuration array.
     *
     * @var string
     */
    public const FILTER = 'mcp_connector_settings';

    /**
     * Memoised instance for the current request.
     */
    private static ?self $current = null;

    /**
     * @param string        $abilityNamespace         Namespace prefix given to every ability this plugin
     *                                                registers, e.g. `wp-mcp` yields `wp-mcp/get-posts`.
     *                                                It also prefixes the MCP tool names.
     * @param string        $serverRoute              REST route segment of the MCP server, mounted under
     *                                                the `mcp` namespace: `/wp-json/mcp/{serverRoute}`.
     * @param string        $requiredCapability       Capability a user must hold for the MCP transport to
     *                                                answer at all, and for the OAuth authorization
     *                                                endpoint to issue a code. Individual abilities still
     *                                                run their own, finer-grained permission checks.
     * @param list<string>  $enabledGroups            Keys of the ability groups to register. An empty list
     *                                                registers none, which effectively disables the server.
     * @param bool          $readOnlyMode             When true, abilities that modify the site are not
     *                                                registered at all, whatever their group. This is the
     *                                                switch to reach for when bringing a connector up on a
     *                                                production site: prove the transport and the
     *                                                authentication work before granting write access.
     * @param list<string>  $externalAbilities        Fully-qualified names of abilities registered by *other*
     *                                                plugins that this connector republishes. Meaningful only
     *                                                once `$externalAbilitiesReviewed` is true.
     * @param bool          $externalAbilitiesReviewed Whether an administrator has ever saved a third-party
     *                                                selection. Until they have, the recommended defaults
     *                                                apply — which is what makes a fresh install useful
     *                                                without a configuration step. Once saved, the stored
     *                                                list is authoritative, including when it is empty:
     *                                                deselecting everything has to be a decision the plugin
     *                                                honours, not one it overwrites on the next request.
     * @param list<string>  $addressablePostTypes     Post types a client may address. Meaningful only once
     *                                                `$postTypesReviewed` is true.
     * @param bool          $postTypesReviewed        Whether an administrator has ever saved a post type
     *                                                selection. Until then the computed default applies —
     *                                                public types declared to the REST API.
     * @param bool          $oauthEnabled             Whether the built-in OAuth 2.1 provider is served.
     * @param bool          $dynamicRegistrationOpen  Whether unauthenticated clients may register
     *                                                themselves through RFC 7591. Claude needs this on the
     *                                                first connection unless a client is created by hand.
     */
    private function __construct(
        public readonly string $abilityNamespace,
        public readonly string $serverRoute,
        public readonly string $requiredCapability,
        public readonly array $enabledGroups,
        public readonly bool $readOnlyMode,
        public readonly array $externalAbilities,
        public readonly bool $externalAbilitiesReviewed,
        public readonly array $addressablePostTypes,
        public readonly bool $postTypesReviewed,
        public readonly bool $oauthEnabled,
        public readonly bool $dynamicRegistrationOpen,
    ) {
    }

    /**
     * Resolve the configuration for the current request.
     *
     * @return self The memoised configuration.
     */
    public static function current(): self
    {
        return self::$current ??= self::fromArray(
            (array) get_option(self::OPTION, []),
        );
    }

    /**
     * Drop the memoised instance so the next read hits the database again.
     *
     * Called after the settings screen saves, and useful in tests.
     */
    public static function forget(): void
    {
        self::$current = null;
    }

    /**
     * Build a configuration object from a raw, possibly partial, array.
     *
     * Unknown keys are ignored and missing keys fall back to the defaults, so a
     * stored option written by an older version of the plugin stays readable.
     *
     * @param array<string, mixed> $raw Raw configuration, typically straight from the option.
     *
     * @return self The normalised configuration.
     */
    public static function fromArray(array $raw): self
    {
        /** @var array<string, mixed> $merged */
        $merged = apply_filters(self::FILTER, array_merge(self::defaults(), $raw));

        $namespace = sanitize_key((string) ($merged['ability_namespace'] ?? ''));
        $route = sanitize_key((string) ($merged['server_route'] ?? ''));
        $capability = sanitize_key((string) ($merged['required_capability'] ?? ''));

        return new self(
            // An ability name must match `^[a-z0-9-]+/[a-z0-9-]+$`. sanitize_key()
            // also allows underscores, which would make every registration fail
            // silently, so they are folded to hyphens.
            abilityNamespace: str_replace('_', '-', $namespace) ?: 'wp-mcp',
            serverRoute: str_replace('_', '-', $route) ?: 'connector',
            requiredCapability: $capability ?: 'edit_posts',
            enabledGroups: array_values(array_filter(
                array_map('strval', (array) ($merged['enabled_groups'] ?? [])),
            )),
            readOnlyMode: (bool) ($merged['read_only_mode'] ?? false),
            externalAbilities: array_values(array_filter(
                array_map('strval', (array) ($merged['external_abilities'] ?? [])),
            )),
            externalAbilitiesReviewed: (bool) ($merged['external_abilities_reviewed'] ?? false),
            addressablePostTypes: array_values(array_filter(
                array_map('sanitize_key', array_map('strval', (array) ($merged['addressable_post_types'] ?? []))),
            )),
            postTypesReviewed: (bool) ($merged['post_types_reviewed'] ?? false),
            oauthEnabled: (bool) ($merged['oauth_enabled'] ?? true),
            dynamicRegistrationOpen: (bool) ($merged['dynamic_registration_open'] ?? true),
        );
    }

    /**
     * Default configuration, used on a fresh install and as a fallback.
     *
     * @return array<string, mixed> The default configuration.
     */
    public static function defaults(): array
    {
        return [
            'ability_namespace' => 'wp-mcp',
            'server_route' => 'connector',
            'required_capability' => 'edit_posts',
            'enabled_groups' => Abilities\AbilityRegistry::defaultGroupKeys(),
            'read_only_mode' => false,
            'external_abilities' => [],
            'external_abilities_reviewed' => false,
            'addressable_post_types' => [],
            'post_types_reviewed' => false,
            'oauth_enabled' => true,
            'dynamic_registration_open' => true,
        ];
    }

    /**
     * Persist a configuration array, normalising it first.
     *
     * @param array<string, mixed> $raw Raw configuration to store.
     */
    public static function save(array $raw): void
    {
        $settings = self::fromArray($raw);

        update_option(self::OPTION, $settings->toArray(), false);

        self::forget();
    }

    /**
     * Whether an ability group is enabled.
     *
     * @param string $key Group key, as returned by the group's `key()` method.
     *
     * @return bool True when the group should be registered.
     */
    public function isGroupEnabled(string $key): bool
    {
        return in_array($key, $this->enabledGroups, true);
    }

    /**
     * Serialise back to the array shape stored in the option.
     *
     * @return array<string, mixed> The storable configuration.
     */
    public function toArray(): array
    {
        return [
            'ability_namespace' => $this->abilityNamespace,
            'server_route' => $this->serverRoute,
            'required_capability' => $this->requiredCapability,
            'enabled_groups' => $this->enabledGroups,
            'read_only_mode' => $this->readOnlyMode,
            'external_abilities' => $this->externalAbilities,
            'external_abilities_reviewed' => $this->externalAbilitiesReviewed,
            'addressable_post_types' => $this->addressablePostTypes,
            'post_types_reviewed' => $this->postTypesReviewed,
            'oauth_enabled' => $this->oauthEnabled,
            'dynamic_registration_open' => $this->dynamicRegistrationOpen,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Server;

use Pollora\McpConnector\OAuth\Scope;
use Pollora\McpConnector\Settings;
use Pollora\McpConnector\Support\Failure;

defined('ABSPATH') || exit;

/**
 * Applies the OAuth scope gate to the abilities other plugins register.
 *
 * The abilities of this plugin refuse a write to a token granted only the
 * `read` scope. An ability published by another plugin, and selected for the
 * MCP server, goes through that plugin's own permission callback, which knows
 * nothing about scopes: a read-only token could call it, whatever it changes.
 *
 * Every ability that does not claim `readonly` in its annotations is therefore
 * wrapped, as it is registered, with the same check. A request that carries no
 * token is not affected: the scope is only recorded for a bearer token.
 */
final class ExternalScopeGate
{
    /**
     * @param Settings $settings The plugin settings, for the namespace of its own abilities.
     */
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Attach the filter that wraps the permission callback of an ability being registered.
     */
    public function register(): void
    {
        add_filter('wp_register_ability_args', $this->gate(...), 10, 2);
    }

    /**
     * Wrap the permission callback of an ability that may change the site.
     *
     * @param mixed $args The registration arguments of the ability.
     * @param mixed $name The ability name, `namespace/slug`.
     *
     * @return mixed The arguments, with a gated permission callback when the ability is not read-only.
     */
    public function gate(mixed $args, mixed $name): mixed
    {
        if (! is_array($args) || ! is_string($name) || str_starts_with($name, $this->settings->abilityNamespace . '/')) {
            return $args;
        }

        $permission = $args['permission_callback'] ?? null;

        if (! is_callable($permission) || self::claimsReadOnly($args)) {
            return $args;
        }

        $args['permission_callback'] = static function (mixed ...$arguments) use ($permission): mixed {
            if (! Scope::currentAllowsWrite()) {
                return Failure::forbidden(
                    'change the site: the connected application was granted read-only access',
                );
            }

            return $permission(...$arguments);
        };

        return $args;
    }

    /**
     * Whether the ability says, in its annotations, that it changes nothing.
     *
     * An ability that does not say so is treated as one that writes.
     *
     * @param array<mixed> $args The registration arguments.
     */
    private static function claimsReadOnly(array $args): bool
    {
        $meta = $args['meta'] ?? null;
        $annotations = is_array($meta) ? ($meta['annotations'] ?? null) : null;

        return is_array($annotations) && isset($annotations['readonly']) && (bool) $annotations['readonly'];
    }
}

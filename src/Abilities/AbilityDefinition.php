<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities;

defined('ABSPATH') || exit;

/**
 * A single ability, described independently of how it will be registered.
 *
 * Groups return these; {@see AbilityRegistry} decides what namespace to give
 * them, which category slug they land in, and what MCP metadata to attach. That
 * separation is what lets the same group be reused on a site that wants a
 * different ability namespace, or exposed over something other than MCP.
 *
 * @psalm-immutable
 */
final class AbilityDefinition
{
    /**
     * @param string                     $slug        Unnamespaced ability slug, lowercase with dashes,
     *                                                e.g. `get-posts`. The registry prefixes it.
     * @param string                     $label       Short human-readable title, surfaced to MCP clients.
     * @param string                     $description What the ability does, written for a model deciding
     *                                                whether to call it. This is the tool description.
     * @param AbilityCategory            $category    Category the ability is filed under.
     * @param array<string, mixed>       $inputSchema JSON Schema of the accepted input; must be an object
     *                                                schema, see {@see Schema::object()}.
     * @param \Closure(Input): mixed     $execute     Ability body. Receives wrapped input, returns any
     *                                                JSON-serialisable value, or a `WP_Error` to fail.
     * @param \Closure(Input): (bool|\WP_Error) $permission Capability check run before `$execute`, on the
     *                                                same wrapped input. Returning a `WP_Error` lets the
     *                                                ability explain the refusal instead of just denying it.
     * @param Annotations                $annotations Behaviour hints passed through to the MCP client.
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly string $description,
        public readonly AbilityCategory $category,
        public readonly array $inputSchema,
        public readonly \Closure $execute,
        public readonly \Closure $permission,
        public readonly Annotations $annotations,
    ) {
    }

    /**
     * Define an ability that only reads from the site.
     *
     * @param string                 $slug        Unnamespaced ability slug.
     * @param string                 $label       Short human-readable title.
     * @param string                 $description What the ability returns, and when to reach for it.
     * @param AbilityCategory        $category    Category the ability is filed under.
     * @param array<string, mixed>   $inputSchema JSON Schema of the accepted input.
     * @param \Closure(Input): mixed $execute     Ability body.
     * @param \Closure(Input): (bool|\WP_Error) $permission Capability check.
     *
     * @return self The definition.
     */
    public static function reading(
        string $slug,
        string $label,
        string $description,
        AbilityCategory $category,
        array $inputSchema,
        \Closure $execute,
        \Closure $permission,
    ): self {
        return new self(
            $slug,
            $label,
            $description,
            $category,
            $inputSchema,
            $execute,
            $permission,
            Annotations::readOnly()
        );
    }

    /**
     * Define an ability that writes to the site.
     *
     * @param string                 $slug        Unnamespaced ability slug.
     * @param string                 $label       Short human-readable title.
     * @param string                 $description What the ability changes, and what it returns.
     * @param AbilityCategory        $category    Category the ability is filed under.
     * @param array<string, mixed>   $inputSchema JSON Schema of the accepted input.
     * @param \Closure(Input): mixed $execute     Ability body.
     * @param \Closure(Input): (bool|\WP_Error) $permission Capability check.
     * @param Annotations            $annotations How the write behaves; see the named constructors on
     *                                            {@see Annotations}. Defaults to a non-idempotent create.
     *
     * @return self The definition.
     */
    public static function writing(
        string $slug,
        string $label,
        string $description,
        AbilityCategory $category,
        array $inputSchema,
        \Closure $execute,
        \Closure $permission,
        ?Annotations $annotations = null,
    ): self {
        return new self(
            $slug,
            $label,
            $description,
            $category,
            $inputSchema,
            $execute,
            $permission,
            $annotations ?? Annotations::creates()
        );
    }
}

<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Server;

defined('ABSPATH') || exit;

/**
 * An ability registered by some other plugin, described well enough to decide
 * whether to publish it.
 *
 * The decision cannot be automated, and that is the whole reason this class
 * exists rather than a predicate somewhere. There is no field in the Abilities
 * API that separates "writes a value" from "restructures the site": Meta Box
 * files all twenty-three of its abilities under one category, and annotates
 * `update-field-value` and `create-post-type` identically — both
 * `readonly=false, destructive=false`. Other providers annotate nothing at all.
 * Any rule inferred from names or categories would be a guess that survives
 * exactly as long as the plugin that inspired it.
 *
 * So the choice is explicit and per-ability. What this class does is surface
 * everything an administrator needs to make it: what the ability claims about
 * itself, and whether that claim is a safe default.
 *
 * @psalm-immutable
 */
final class ExternalAbility
{
    /**
     * @param string    $name        Fully-qualified ability name, e.g. `meta-box/update-field-value`.
     * @param string    $provider    Namespace portion, used to group abilities by the plugin providing them.
     * @param string    $label       Human-readable label declared by the provider.
     * @param string    $description What the provider says the ability does.
     * @param bool|null $readonly    The provider's `readonly` annotation: true, false, or null when unstated.
     * @param bool|null $destructive The provider's `destructive` annotation.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $provider,
        public readonly string $label,
        public readonly string $description,
        public readonly ?bool $readonly,
        public readonly ?bool $destructive,
    ) {
    }

    /**
     * Describe a registered ability.
     *
     * @param \WP_Ability $ability The ability.
     *
     * @return self The description.
     */
    public static function fromAbility(\WP_Ability $ability): self
    {
        $meta = $ability->get_meta();
        $annotations = $meta['annotations'] ?? [];

        return new self(
            name: $ability->get_name(),
            provider: explode('/', $ability->get_name())[0],
            label: $ability->get_label(),
            description: $ability->get_description(),
            readonly: isset($annotations['readonly']) ? (bool) $annotations['readonly'] : null,
            destructive: isset($annotations['destructive']) ? (bool) $annotations['destructive'] : null,
        );
    }

    /**
     * Whether this ability is safe to publish without anybody having looked at it.
     *
     * Only an explicit `readonly` claim qualifies. An unstated annotation is not
     * an implicit "harmless" — `ai-visibility/regenerate` rewrites files on disk
     * and annotates nothing — so null is treated exactly like false.
     *
     * @return bool True when the ability may be selected automatically.
     */
    public function isSafeByDefault(): bool
    {
        return $this->readonly === true;
    }

    /**
     * A short, human-readable statement of what the provider claims.
     *
     * Shown beside the checkbox, because "the provider says this only reads" is
     * the single most useful thing an administrator can know when deciding.
     *
     * @return string The translated claim.
     */
    public function claim(): string
    {
        if ($this->readonly === true) {
            return __('Reads only', 'amphibee-mcp-connector');
        }

        if ($this->destructive === true) {
            return __('Writes — may delete or overwrite', 'amphibee-mcp-connector');
        }

        if ($this->readonly === false) {
            return __('Writes', 'amphibee-mcp-connector');
        }

        return __('Undeclared — the provider says nothing about what this does', 'amphibee-mcp-connector');
    }
}

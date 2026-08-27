<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities;

defined('ABSPATH') || exit;

/**
 * Behaviour hints attached to an ability, mapped by the MCP Adapter to the
 * `readOnlyHint`, `destructiveHint` and `idempotentHint` tool annotations.
 *
 * Clients use these to decide how much ceremony an invocation deserves: a
 * read-only tool can run unattended, a destructive one should be confirmed with
 * the user first. Getting them wrong is worse than omitting them, so the named
 * constructors below encode the three combinations that actually occur rather
 * than leaving each ability to reason it out.
 *
 * The hints describe intent and are advisory; they are not enforced by the
 * Abilities API. The permission callback is what actually protects the site.
 *
 * @psalm-immutable
 */
final class Annotations
{
    /**
     * @param bool $readonly    Whether the ability leaves the site unchanged.
     * @param bool $destructive Whether the ability may remove or overwrite existing data, as opposed to
     *                          only adding to it.
     * @param bool $idempotent  Whether repeating the call with identical arguments has no further effect.
     */
    private function __construct(
        public readonly bool $readonly,
        public readonly bool $destructive,
        public readonly bool $idempotent,
    ) {
    }

    /**
     * An ability that only reads.
     *
     * Safe to repeat and safe to run without asking, which is what makes it
     * worth distinguishing at all.
     *
     * @return self Read-only annotations.
     */
    public static function readOnly(): self
    {
        return new self(readonly: true, destructive: false, idempotent: true);
    }

    /**
     * An ability that creates something new on each call.
     *
     * Not idempotent: calling it twice yields two posts, two terms, two
     * attachments. Clients should not silently retry it after a timeout.
     *
     * @return self Additive, non-idempotent annotations.
     */
    public static function creates(): self
    {
        return new self(readonly: false, destructive: false, idempotent: false);
    }

    /**
     * An ability that overwrites part of an existing record.
     *
     * Idempotent — sending the same update twice leaves the same state — but
     * destructive, because the previous value is gone.
     *
     * @return self Overwriting annotations.
     */
    public static function updates(): self
    {
        return new self(readonly: false, destructive: true, idempotent: true);
    }

    /**
     * An ability that removes a record.
     *
     * @return self Deleting annotations.
     */
    public static function deletes(): self
    {
        return new self(readonly: false, destructive: true, idempotent: true);
    }

    /**
     * Render in the shape the Abilities API expects under `meta.annotations`.
     *
     * @return array<string, bool> The annotation map.
     */
    public function toArray(): array
    {
        return [
            'readonly' => $this->readonly,
            'destructive' => $this->destructive,
            'idempotent' => $this->idempotent,
        ];
    }
}

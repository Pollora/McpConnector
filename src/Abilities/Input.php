<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities;

defined('ABSPATH') || exit;

/**
 * Typed, defensive reader over the raw input an ability receives.
 *
 * The Abilities API validates input against the declared schema before the
 * execute callback runs, but it does not guarantee a shape: an ability whose
 * every property is optional can legitimately be called with `null`. Reading
 * through this wrapper means an ability body never has to repeat the same
 * `is_array()`, `?? default`, `absint()` dance, and never has to guess whether
 * a missing key means "absent" or "empty".
 *
 * Accessors coerce rather than throw. A model that sends `"12"` where an
 * integer was asked for should get a working call, not an error it cannot act on.
 */
final class Input
{
    /**
     * @param array<string, mixed> $values Normalised input values.
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * Wrap whatever the Abilities API handed the callback.
     *
     * @param mixed $raw Raw input; anything that is not an array becomes an empty set.
     *
     * @return self The wrapped input.
     */
    public static function wrap(mixed $raw): self
    {
        return new self(is_array($raw) ? $raw : []);
    }

    /**
     * Whether a key was supplied with a non-empty value.
     *
     * Uses the "meaningful value" test rather than `array_key_exists()`: callers
     * routinely send empty strings and empty arrays for properties they mean to
     * leave alone, and treating those as present produces empty search terms and
     * cleared taxonomies.
     *
     * @param string $key Property name.
     *
     * @return bool True when the value is set and not empty.
     */
    public function filled(string $key): bool
    {
        return isset($this->values[$key])
            && $this->values[$key] !== ''
            && $this->values[$key] !== [];
    }

    /**
     * Whether a key is present at all, even holding an empty or false value.
     *
     * Use this for properties where "explicitly set to zero/false/empty" is
     * distinct from "not mentioned", such as `menu_order` or `parent`.
     *
     * @param string $key Property name.
     *
     * @return bool True when the key exists and is not null.
     */
    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /**
     * Read a string.
     *
     * @param string $key     Property name.
     * @param string $default Value returned when the property is absent or empty.
     *
     * @return string The string value.
     */
    public function string(string $key, string $default = ''): string
    {
        if (! $this->filled($key)) {
            return $default;
        }

        $value = $this->values[$key];

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Read an integer, optionally clamped.
     *
     * @param string   $key     Property name.
     * @param int      $default Value returned when the property is absent.
     * @param int|null $min     Lower bound applied after coercion, or null to leave it unbounded.
     * @param int|null $max     Upper bound applied after coercion, or null to leave it unbounded. Set this on
     *                          anything that sizes a query: a model asking for 100000 posts should silently
     *                          get the ceiling rather than time the request out.
     *
     * @return int The integer value.
     */
    public function integer(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $value = $this->has($key) && is_numeric($this->values[$key])
            ? (int) $this->values[$key]
            : $default;

        if ($min !== null) {
            $value = max($min, $value);
        }

        if ($max !== null) {
            $value = min($max, $value);
        }

        return $value;
    }

    /**
     * Read a post, term or attachment identifier.
     *
     * Identifiers are always non-negative, and zero reliably means "none", which
     * makes this a distinct concept from a plain integer.
     *
     * @param string $key Property name.
     *
     * @return int The identifier, or 0 when absent or invalid.
     */
    public function id(string $key): int
    {
        return $this->has($key) && is_numeric($this->values[$key])
            ? absint($this->values[$key])
            : 0;
    }

    /**
     * Read a boolean.
     *
     * Accepts the JSON booleans a well-behaved client sends, and the `"true"` /
     * `"1"` strings that leak through less careful ones.
     *
     * @param string $key     Property name.
     * @param bool   $default Value returned when the property is absent.
     *
     * @return bool The boolean value.
     */
    public function boolean(string $key, bool $default = false): bool
    {
        if (! $this->has($key)) {
            return $default;
        }

        return filter_var($this->values[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Read a list of strings, dropping anything that is not scalar or is blank.
     *
     * @param string $key Property name.
     *
     * @return list<string> The cleaned list, empty when the property is absent.
     */
    public function stringList(string $key): array
    {
        if (! $this->filled($key) || ! is_array($this->values[$key])) {
            return [];
        }

        $strings = array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $this->values[$key]
        );

        return array_values(array_filter($strings, static fn (string $item): bool => $item !== ''));
    }

    /**
     * Read a free-form associative array.
     *
     * @param string $key Property name.
     *
     * @return array<string, mixed> The map, empty when the property is absent or not an array.
     */
    public function map(string $key): array
    {
        return $this->filled($key) && is_array($this->values[$key])
            ? $this->values[$key]
            : [];
    }

    /**
     * Expose the underlying values, for the rare ability that needs them whole.
     *
     * @return array<string, mixed> The normalised input.
     */
    public function all(): array
    {
        return $this->values;
    }
}

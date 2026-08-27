<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities;

defined('ABSPATH') || exit;

/**
 * Small builder for the JSON Schema fragments that describe ability input.
 *
 * These schemas are the only documentation a language model gets about a tool,
 * so descriptions are not optional decoration — they are the interface. The
 * builder exists to make writing them terse enough that nobody is tempted to
 * skip them, and to keep the shape consistent across every ability.
 *
 * The MCP specification requires the top-level input schema of a tool to be of
 * type `object`; {@see Schema::object()} is therefore the only valid root.
 */
final class Schema
{
    /**
     * Not instantiable: every member is a static factory.
     */
    private function __construct()
    {
    }

    /**
     * Build an object schema.
     *
     * @param array<string, array<string, mixed>> $properties Property name to property schema.
     * @param list<string>                        $required   Names of the properties the caller must supply.
     *
     * @return array<string, mixed> The object schema.
     */
    public static function object(array $properties = [], array $required = []): array
    {
        $schema = ['type' => 'object'];

        // An empty PHP array encodes to `[]`, not `{}`, so a tool that takes no
        // input would advertise `"properties": []` — a JSON array where the
        // specification calls for an object. Omitting the key entirely says the
        // same thing without the malformed schema.
        if ($properties !== []) {
            $schema['properties'] = $properties;
        } else {
            $schema['additionalProperties'] = false;
        }

        if ($required !== []) {
            $schema['required'] = array_values($required);
        }

        return $schema;
    }

    /**
     * Build a string property.
     *
     * @param string       $description What the value means, written for a model that has never seen this site.
     * @param string|null  $default     Value assumed when the caller omits the property, or null for none.
     * @param list<string> $enum        Closed set of accepted values, or an empty list to accept any string.
     *
     * @return array<string, mixed> The property schema.
     */
    public static function string(string $description, ?string $default = null, array $enum = []): array
    {
        return self::decorate(['type' => 'string'], $description, $default, $enum);
    }

    /**
     * Build an integer property.
     *
     * @param string   $description What the value means.
     * @param int|null $default     Value assumed when the caller omits the property, or null for none.
     * @param int|null $minimum     Smallest accepted value, or null for unbounded.
     * @param int|null $maximum     Largest accepted value, or null for unbounded. Worth setting on anything
     *                              that sizes a query, so a model cannot ask for every row in the table.
     *
     * @return array<string, mixed> The property schema.
     */
    public static function integer(
        string $description,
        ?int $default = null,
        ?int $minimum = null,
        ?int $maximum = null,
    ): array {
        $schema = ['type' => 'integer'];

        if ($minimum !== null) {
            $schema['minimum'] = $minimum;
        }

        if ($maximum !== null) {
            $schema['maximum'] = $maximum;
        }

        return self::decorate($schema, $description, $default);
    }

    /**
     * Build a boolean property.
     *
     * @param string    $description What the flag turns on.
     * @param bool|null $default     Value assumed when the caller omits the property, or null for none.
     *
     * @return array<string, mixed> The property schema.
     */
    public static function boolean(string $description, ?bool $default = null): array
    {
        return self::decorate(['type' => 'boolean'], $description, $default);
    }

    /**
     * Build an array property.
     *
     * @param string               $description What the collection holds.
     * @param array<string, mixed> $items       Schema every element must satisfy.
     *
     * @return array<string, mixed> The property schema.
     */
    public static function listOf(string $description, array $items): array
    {
        return self::decorate(['type' => 'array', 'items' => $items], $description);
    }

    /**
     * Build a free-form object property, used for open-ended key/value input.
     *
     * @param string $description What the map holds.
     *
     * @return array<string, mixed> The property schema.
     */
    public static function map(string $description): array
    {
        return self::decorate(['type' => 'object'], $description);
    }

    /**
     * Attach the description, default and enum shared by every property type.
     *
     * @param array<string, mixed> $schema      Type-specific schema to decorate.
     * @param string               $description Human-readable explanation.
     * @param mixed                $default     Default value, or null to omit the keyword.
     * @param list<string>         $enum        Closed value set, or an empty list to omit the keyword.
     *
     * @return array<string, mixed> The decorated schema.
     */
    private static function decorate(
        array $schema,
        string $description,
        mixed $default = null,
        array $enum = [],
    ): array {
        $schema['description'] = $description;

        if ($default !== null) {
            $schema['default'] = $default;
        }

        if ($enum !== []) {
            $schema['enum'] = array_values($enum);
        }

        return $schema;
    }
}

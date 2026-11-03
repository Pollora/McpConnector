<?php
/**
 * Helpers shared by the ability registry and ability group tests.
 *
 * @package Pollora\McpConnector
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\McpConnector\Abilities\AbilityDefinition;
use Pollora\McpConnector\Abilities\AbilityGroup;

/**
 * Replace the Abilities API with an in-memory recorder.
 *
 * @return \ArrayObject<string, array<string, mixed>> Registered abilities, keyed by name, holding the arguments given to wp_register_ability().
 */
function abcoreAbilitiesApi(): \ArrayObject
{
    /** @var \ArrayObject<string, array<string, mixed>> $abilities */
    $abilities = new \ArrayObject();
    $GLOBALS['abcore_categories'] = new \ArrayObject();

    Functions\when('wp_has_ability')->alias(static fn (string $name): bool => isset($abilities[$name]));
    Functions\when('wp_register_ability')->alias(
        static function (string $name, array $args) use ($abilities): object {
            $abilities[$name] = $args;

            return new \stdClass();
        },
    );
    Functions\when('wp_has_ability_category')->alias(
        static fn (string $slug): bool => isset($GLOBALS['abcore_categories'][$slug]),
    );
    Functions\when('wp_register_ability_category')->alias(
        static function (string $slug, array $args): object {
            $GLOBALS['abcore_categories'][$slug] = $args;

            return new \stdClass();
        },
    );

    return $abilities;
}

/**
 * Forget the ability names the registry accumulated earlier in the process.
 */
function abcoreResetRegistry(): void
{
    $property = new \ReflectionProperty(\Pollora\McpConnector\Abilities\AbilityRegistry::class, 'registered');
    $property->setValue(null, []);
}

/**
 * Pick one definition out of a group by slug.
 */
function abcoreDefinition(AbilityGroup $group, string $slug): AbilityDefinition
{
    foreach ($group->definitions() as $definition) {
        if ($definition->slug === $slug) {
            return $definition;
        }
    }

    throw new \LogicException("No definition named {$slug}.");
}

/**
 * Wrap an input the way the registrar does before calling an ability.
 *
 * @param array<string, mixed> $values
 */
function abcoreInput(array $values = []): Input
{
    return Input::wrap($values);
}

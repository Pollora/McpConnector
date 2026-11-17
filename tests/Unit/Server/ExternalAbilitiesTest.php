<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\McpConnector\Server\ExternalAbilities;
use Pollora\McpConnector\Settings;

/**
 * @param array<string, mixed> $meta
 */
function extabilitiesMake(string $name, array $meta = ['mcp' => ['public' => true]]): WP_Ability
{
    return new WP_Ability($name, 'Label ' . $name, 'Does ' . $name, 'cat', $meta);
}

function extabilitiesRead(string $name): WP_Ability
{
    return extabilitiesMake($name, ['mcp' => ['public' => true], 'annotations' => ['readonly' => true]]);
}

/**
 * @param list<WP_Ability> $abilities
 */
function extabilitiesProvide(array $abilities): void
{
    Functions\when('wp_get_abilities')->justReturn($abilities);
}

/**
 * @param array<string, mixed> $settings
 */
function extabilitiesFor(array $settings = []): ExternalAbilities
{
    return new ExternalAbilities(Settings::fromArray($settings));
}

/**
 * @param array<string, list<Pollora\McpConnector\Server\ExternalAbility>> $grouped
 *
 * @return array<string, list<string>>
 */
function extabilitiesNames(array $grouped): array
{
    return array_map(static fn (array $abilities): array => array_map(static fn ($a): string => $a->name, $abilities), $grouped);
}

describe('what is on offer', function (): void {
    it('groups by provider, providers alphabetically, reads first then by name', function (): void {
        extabilitiesProvide([
            extabilitiesMake('zeta/b'),
            extabilitiesRead('zeta/z-read'),
            extabilitiesMake('alpha/b'),
            extabilitiesMake('zeta/a'),
            extabilitiesRead('zeta/a-read'),
            extabilitiesMake('alpha/a'),
        ]);

        $grouped = extabilitiesFor()->available();

        expect(extabilitiesNames($grouped))->toBe([
            'alpha' => ['alpha/a', 'alpha/b'],
            'zeta' => ['zeta/a-read', 'zeta/z-read', 'zeta/a', 'zeta/b'],
        ]);
    });

    it('leaves out our own namespace, the adapter, what is not public, what is not a tool and what is not an ability', function (): void {
        extabilitiesProvide([
            extabilitiesMake('wp-mcp/own'),
            extabilitiesMake('mcp-adapter/execute-ability'),
            extabilitiesMake('acme/private', ['mcp' => ['public' => false]]),
            extabilitiesMake('acme/silent', []),
            extabilitiesMake('acme/resource', ['mcp' => ['public' => true, 'type' => 'resource']]),
            extabilitiesMake('acme/tool', ['mcp' => ['public' => true, 'type' => 'tool']]),
            extabilitiesMake('acme/default'),
            new stdClass(),
            'a string',
        ]);

        expect(extabilitiesNames(extabilitiesFor(['ability_namespace' => 'wp-mcp'])->available()))->toBe(['acme' => ['acme/default', 'acme/tool']]);
    });

    it('takes the provider from the first segment of the name, not the second', function (): void {
        extabilitiesProvide([extabilitiesMake('first/second')]);

        expect(array_keys(extabilitiesFor(['ability_namespace' => 'second'])->available()))->toBe(['first']);
    });
});

describe('what is selected', function (): void {
    beforeEach(function (): void {
        extabilitiesProvide([
            extabilitiesRead('acme/read'),
            extabilitiesMake('acme/write'),
            extabilitiesRead('other/read'),
        ]);
    });

    it('falls back to every provider\'s safe defaults until the list has been reviewed', function (): void {
        expect(extabilitiesFor(['external_abilities' => ['acme/write']])->selected())->toBe(['acme/read', 'other/read']);
    });

    it('follows the stored list, empty included, once it has been reviewed', function (): void {
        expect(extabilitiesFor(['external_abilities_reviewed' => true, 'external_abilities' => ['acme/write']])->selected())->toBe(['acme/write'])
            ->and(extabilitiesFor(['external_abilities_reviewed' => true, 'external_abilities' => []])->selected())->toBe([]);
    });

    it('drops stored names that are no longer offered and reindexes the rest', function (): void {
        $selected = extabilitiesFor(['external_abilities_reviewed' => true, 'external_abilities' => ['gone/x', 'acme/write', 'also/gone', 'other/read']])->selected();

        expect($selected)->toBe(['acme/write', 'other/read'])
            ->and(array_is_list($selected))->toBeTrue();
    });

    it('hands the filter the stored list and everything on offer, and honours its answer', function (): void {
        $seen = null;
        Filters\expectApplied(ExternalAbilities::SELECTION_FILTER)->once()->andReturnUsing(function (array $stored, array $available) use (&$seen): array {
            $seen = [$stored, $available];

            return ['other/read', 'unknown/x'];
        });

        $selected = extabilitiesFor(['external_abilities_reviewed' => true, 'external_abilities' => ['acme/write']])->selected();

        expect($seen)->toBe([['acme/write'], ['acme/read', 'acme/write', 'other/read']])
            ->and($selected)->toBe(['other/read']);
    });
});

describe('the defaults of one provider', function (): void {
    it('are its read-only abilities, in offered order, as a list', function (): void {
        extabilitiesProvide([extabilitiesRead('acme/b'), extabilitiesMake('acme/w'), extabilitiesRead('acme/a')]);

        expect(extabilitiesFor()->defaultsFor('acme'))->toBe(['acme/a', 'acme/b'])
            ->and(extabilitiesFor()->defaultsFor('nobody'))->toBe([]);
    });

    it('follow a profile where one exists, restricted to what is actually offered', function (): void {
        extabilitiesProvide([
            extabilitiesMake('meta-box/get-field-value'),
            extabilitiesMake('meta-box/create-field-group'),
            extabilitiesMake('meta-box/list-fields'),
        ]);

        expect(extabilitiesFor()->defaultsFor('meta-box'))->toBe(['meta-box/get-field-value', 'meta-box/list-fields']);
    });

    it('follow a profile a plugin declares through the filter', function (): void {
        extabilitiesProvide([extabilitiesMake('acme/a'), extabilitiesRead('acme/b')]);
        Filters\expectApplied(ExternalAbilities::PROFILES_FILTER)->andReturnUsing(static fn (array $profiles): array => $profiles + ['acme' => ['acme/a']]);

        expect(extabilitiesFor()->defaultsFor('acme'))->toBe(['acme/a']);
    });
});

describe('the shipped profiles', function (): void {
    it('recommend value and definition access for Meta Box, and no structure editing', function (): void {
        expect(ExternalAbilities::profiles())->toBe(['meta-box' => [
            'meta-box/get-field-value',
            'meta-box/get-field-value-format',
            'meta-box/update-field-value',
            'meta-box/delete-field-value',
            'meta-box/list-fields',
            'meta-box/get-field',
            'meta-box/list-field-groups',
            'meta-box/get-field-group',
            'meta-box/get-post-types',
            'meta-box/get-taxonomies',
        ]]);
    });
});

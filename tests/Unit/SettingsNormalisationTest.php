<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Pollora\McpConnector\Abilities\AbilityRegistry;
use Pollora\McpConnector\Settings;

describe('the defaults', function (): void {
    it('are exactly this configuration', function (): void {
        expect(Settings::defaults())->toBe([
            'ability_namespace' => 'wp-mcp',
            'server_route' => 'connector',
            'required_capability' => 'edit_posts',
            'enabled_groups' => AbilityRegistry::defaultGroupKeys(),
            'read_only_mode' => false,
            'external_abilities' => [],
            'external_abilities_reviewed' => false,
            'addressable_post_types' => [],
            'post_types_reviewed' => false,
            'oauth_enabled' => true,
            'dynamic_registration_open' => true,
        ]);
    });

    it('are what an empty record resolves to', function (): void {
        expect(Settings::fromArray([])->toArray())->toBe(Settings::defaults());
    });
});

describe('normalising a raw record', function (): void {
    it('reads every field and serialises it back unchanged', function (): void {
        $raw = [
            'ability_namespace' => 'my-site',
            'server_route' => 'route-one',
            'required_capability' => 'manage_options',
            'enabled_groups' => ['content', 'media'],
            'read_only_mode' => true,
            'external_abilities' => ['acme/read'],
            'external_abilities_reviewed' => true,
            'addressable_post_types' => ['post', 'book'],
            'post_types_reviewed' => true,
            'oauth_enabled' => false,
            'dynamic_registration_open' => false,
        ];

        $settings = Settings::fromArray($raw);

        expect($settings->toArray())->toBe($raw)
            ->and($settings->abilityNamespace)->toBe('my-site')
            ->and($settings->readOnlyMode)->toBeTrue()
            ->and($settings->oauthEnabled)->toBeFalse()
            ->and($settings->dynamicRegistrationOpen)->toBeFalse()
            ->and($settings->postTypesReviewed)->toBeTrue()
            ->and($settings->externalAbilitiesReviewed)->toBeTrue();
    });

    it('keeps a missing flag at its own default, on or off', function (): void {
        $settings = Settings::fromArray(['read_only_mode' => true]);

        expect($settings->readOnlyMode)->toBeTrue()
            ->and($settings->oauthEnabled)->toBeTrue()
            ->and($settings->dynamicRegistrationOpen)->toBeTrue()
            ->and($settings->externalAbilitiesReviewed)->toBeFalse()
            ->and($settings->postTypesReviewed)->toBeFalse();
    });

    it('reads loosely typed flags as booleans', function (): void {
        $settings = Settings::fromArray(['read_only_mode' => 1, 'oauth_enabled' => 0, 'dynamic_registration_open' => '', 'post_types_reviewed' => '1', 'external_abilities_reviewed' => 'yes']);

        expect($settings->readOnlyMode)->toBeTrue()
            ->and($settings->oauthEnabled)->toBeFalse()
            ->and($settings->dynamicRegistrationOpen)->toBeFalse()
            ->and($settings->postTypesReviewed)->toBeTrue()
            ->and($settings->externalAbilitiesReviewed)->toBeTrue();
    });

    it('turns underscores into hyphens in the names an ability and a route are built from', function (): void {
        $settings = Settings::fromArray(['ability_namespace' => 'My_Site_Name', 'server_route' => 'Route_One']);

        expect($settings->abilityNamespace)->toBe('my-site-name')
            ->and($settings->serverRoute)->toBe('route-one');
    });

    it('falls back to the default for a name that sanitises to nothing, and keeps a capability as it is', function (): void {
        $settings = Settings::fromArray(['ability_namespace' => '!!!', 'server_route' => '', 'required_capability' => '???']);

        expect($settings->abilityNamespace)->toBe('wp-mcp')
            ->and($settings->serverRoute)->toBe('connector')
            ->and($settings->requiredCapability)->toBe('edit_posts')
            ->and(Settings::fromArray(['required_capability' => 'Do_Thing'])->requiredCapability)->toBe('do_thing');
    });

    it('reads a non-string name as text', function (): void {
        $settings = Settings::fromArray(['ability_namespace' => 42, 'server_route' => 7, 'required_capability' => 9]);

        expect($settings->abilityNamespace)->toBe('42')
            ->and($settings->serverRoute)->toBe('7')
            ->and($settings->requiredCapability)->toBe('9');
    });

    it('drops empty entries from the lists, as strings, reindexed', function (): void {
        $settings = Settings::fromArray([
            'enabled_groups' => [3 => 'content', 4 => '', 7 => 'media', 9 => 0],
            'external_abilities' => [2 => 'acme/a', 5 => null, 8 => 'acme/b'],
            'addressable_post_types' => [1 => 'Post', 2 => '', 6 => 'Boo k!', 7 => 12],
        ]);

        expect($settings->enabledGroups)->toBe(['content', 'media'])
            ->and($settings->externalAbilities)->toBe(['acme/a', 'acme/b'])
            ->and($settings->addressablePostTypes)->toBe(['post', 'book', '12']);
    });

    it('reads a list given as a single value as a list of one', function (): void {
        $settings = Settings::fromArray(['enabled_groups' => 'content', 'external_abilities' => 'acme/a', 'addressable_post_types' => 'book']);

        expect($settings->enabledGroups)->toBe(['content'])
            ->and($settings->externalAbilities)->toBe(['acme/a'])
            ->and($settings->addressablePostTypes)->toBe(['book']);
    });

    it('lets a record override the defaults and hands the merged record to the filter', function (): void {
        $seen = null;
        Filters\expectApplied(Settings::FILTER)->once()->andReturnUsing(function (array $merged) use (&$seen): array {
            $seen = $merged;

            return $merged + ['server_route' => 'filtered'];
        });

        $settings = Settings::fromArray(['ability_namespace' => 'mine']);

        expect($seen)->toBe(['ability_namespace' => 'mine'] + Settings::defaults())
            ->and($settings->abilityNamespace)->toBe('mine');
    });

    it('lets the filter have the last word', function (): void {
        Filters\expectApplied(Settings::FILTER)->andReturn(['server_route' => 'from_filter']);

        $settings = Settings::fromArray(['server_route' => 'stored']);

        expect($settings->serverRoute)->toBe('from-filter')
            ->and($settings->requiredCapability)->toBe('edit_posts')
            ->and($settings->oauthEnabled)->toBeTrue()
            ->and($settings->readOnlyMode)->toBeFalse()
            ->and($settings->enabledGroups)->toBe([]);
    });

    it('tells whether a group is enabled by exact key', function (): void {
        $settings = Settings::fromArray(['enabled_groups' => ['content']]);

        expect($settings->isGroupEnabled('content'))->toBeTrue()
            ->and($settings->isGroupEnabled('Content'))->toBeFalse()
            ->and($settings->isGroupEnabled('media'))->toBeFalse();
    });
});

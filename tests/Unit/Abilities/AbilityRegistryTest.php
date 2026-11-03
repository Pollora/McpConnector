<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\AbilityDefinition;
use Pollora\McpConnector\Abilities\AbilityGroup;
use Pollora\McpConnector\Abilities\AbilityRegistry;
use Pollora\McpConnector\Abilities\Group\SiteGroup;
use Pollora\McpConnector\Abilities\Group\UserGroup;
use Pollora\McpConnector\OAuth\Scope;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/abcore-helpers.php';

/**
 * A group publishing one reading and one writing ability, with spy permissions.
 *
 * @param list<string> $log Receives the slug of every permission check that ran.
 */
function abcoreSpyGroup(array &$log, string $key = 'spy'): AbilityGroup
{
    return new class ($log, $key) implements AbilityGroup {
        /** @param list<string> $log */
        public function __construct(private array &$log, private string $key)
        {
        }

        public function key(): string
        {
            return $this->key;
        }

        public function label(): string
        {
            return 'Spy';
        }

        public function description(): string
        {
            return 'Spy group';
        }

        public function definitions(): array
        {
            $log = &$this->log;
            $prefix = $this->key === 'spy' ? '' : $this->key . '-';

            return [
                AbilityDefinition::reading(
                    $prefix . 'peek',
                    'Peek',
                    'Reads something.',
                    AbilityCategory::Content,
                    ['type' => 'object', 'properties' => []],
                    static fn (): string => 'peeked',
                    static function (Input $input) use (&$log): bool {
                        $log[] = 'peek';

                        return true;
                    },
                ),
                AbilityDefinition::writing(
                    $prefix . 'poke',
                    'Poke',
                    'Changes something.',
                    AbilityCategory::Content,
                    ['type' => 'object', 'properties' => []],
                    static fn (): string => 'poked',
                    static function (Input $input) use (&$log): bool {
                        $log[] = 'poke:' . $input->id('id');

                        return $input->id('id') === 7;
                    },
                ),
            ];
        }
    };
}

/**
 * Register the abilities of the given groups under the given settings.
 *
 * @param list<AbilityGroup>   $groups
 * @param array<string, mixed> $settings
 *
 * @return ArrayObject<string, array<string, mixed>>
 */
function abcoreRegister(array $groups, array $settings = []): ArrayObject
{
    $api = abcoreAbilitiesApi();
    Filters\expectApplied(AbilityRegistry::GROUPS_FILTER)->andReturn($groups);

    (new AbilityRegistry(Settings::fromArray($settings)))->registerAbilities();

    return $api;
}

beforeEach(function (): void {
    abcoreResetRegistry();
    Scope::setCurrent(null);
});

afterEach(function (): void {
    Scope::setCurrent(null);
    unset($GLOBALS['abcore_categories']);
});

describe('hooking', function (): void {
    it('attaches the category and ability registration to the Abilities API hooks', function (): void {
        $hooks = [];
        Functions\when('add_action')->alias(static function (string $hook, callable $callback) use (&$hooks): bool {
            $hooks[] = $hook;

            return true;
        });

        (new AbilityRegistry(Settings::fromArray([])))->register();

        expect($hooks)->toBe(['wp_abilities_api_categories_init', 'wp_abilities_api_init']);
    });
});

describe('the available groups', function (): void {
    it('lists the six built-in groups in display order', function (): void {
        $keys = array_map(static fn (AbilityGroup $g): string => $g->key(), AbilityRegistry::availableGroups());

        expect($keys)->toBe(['content', 'taxonomy', 'media', 'navigation', 'users', 'site']);
    });

    it('lets a filter add a group and drops anything that is not one', function (): void {
        $log = [];
        Filters\expectApplied(AbilityRegistry::GROUPS_FILTER)
            ->andReturn([abcoreSpyGroup($log), 'not a group', new stdClass()]);

        $keys = array_map(static fn (AbilityGroup $g): string => $g->key(), AbilityRegistry::availableGroups());

        expect($keys)->toBe(['spy']);
    });

    it('leaves user listing off on a fresh install', function (): void {
        expect(AbilityRegistry::defaultGroupKeys())
            ->toBe(['content', 'taxonomy', 'media', 'navigation', 'site'])
            ->not->toContain(UserGroup::KEY);
    });

    it('enables the default groups when settings are empty', function (): void {
        expect(Settings::fromArray([])->enabledGroups)->toBe(AbilityRegistry::defaultGroupKeys());
    });
});

describe('publishing abilities', function (): void {
    it('prefixes the ability name and category with the configured namespace', function (): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy'], 'ability_namespace' => 'acme_mcp']);

        expect(array_keys($api->getArrayCopy()))->toBe(['acme-mcp/peek', 'acme-mcp/poke'])
            ->and($api['acme-mcp/peek']['category'])->toBe('acme-mcp-content')
            ->and(AbilityRegistry::registeredAbilityNames())->toBe(['acme-mcp/peek', 'acme-mcp/poke']);
    });

    it('defaults to the wp-mcp namespace', function (): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy']]);

        expect($api->getArrayCopy())->toHaveKeys(['wp-mcp/peek', 'wp-mcp/poke']);
    });

    it('marks each ability public for the MCP adapter and exposes it through REST', function (): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy']]);

        expect($api['wp-mcp/peek']['meta'])->toMatchArray([
            'show_in_rest' => true,
            'mcp' => ['public' => true, 'type' => 'tool'],
        ]);
    });

    it('publishes the label, description and input schema of the definition', function (): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy']]);

        expect($api['wp-mcp/poke'])->toMatchArray([
            'label' => 'Poke',
            'description' => 'Changes something.',
            'input_schema' => ['type' => 'object', 'properties' => []],
        ])->and($api['wp-mcp/poke']['meta']['annotations'])->toBeArray();
    });

    it('skips groups the site has not enabled', function (): void {
        $log = [];
        $api = abcoreRegister(
            [abcoreSpyGroup($log, 'spy'), abcoreSpyGroup($log, 'other')],
            ['enabled_groups' => ['other']],
        );

        expect(array_keys($api->getArrayCopy()))->toBe(['wp-mcp/other-peek', 'wp-mcp/other-poke']);
    });

    it('publishes nothing when no group is enabled', function (): void {
        $log = [];

        expect(abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['site']]))->toHaveCount(0)
            ->and(AbilityRegistry::registeredAbilityNames())->toBe([]);
    });

    it('does not publish the writing abilities in read-only mode', function (): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy'], 'read_only_mode' => true]);

        expect(array_keys($api->getArrayCopy()))->toBe(['wp-mcp/peek'])
            ->and(AbilityRegistry::registeredAbilityNames())->toBe(['wp-mcp/peek']);
    });

    it('keeps update-option out of the registry in read-only mode but not otherwise', function (): void {
        $site = [new SiteGroup()];

        $readOnly = abcoreRegister($site, ['enabled_groups' => ['site'], 'read_only_mode' => true]);
        expect(array_keys($readOnly->getArrayCopy()))->toBe(['wp-mcp/get-site-info', 'wp-mcp/get-post-types']);

        abcoreResetRegistry();
        $writable = abcoreRegister($site, ['enabled_groups' => ['site']]);
        expect($writable->getArrayCopy())->toHaveKey('wp-mcp/update-option');
    });
});

describe('the permission gate', function (): void {
    it('runs the definition permission with the wrapped input', function (): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy']]);

        expect($api['wp-mcp/poke']['permission_callback'](['id' => 7]))->toBeTrue()
            ->and($api['wp-mcp/poke']['permission_callback'](['id' => 8]))->toBeFalse()
            ->and($log)->toBe(['poke:7', 'poke:8']);
    });

    it('runs the ability body through the same wrapped input', function (): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy']]);

        expect($api['wp-mcp/peek']['execute_callback'](null))->toBe('peeked');
    });

    it('refuses a writing ability to a read-only token before the capability check runs', function (): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy']]);
        Scope::setCurrent(['read']);

        $result = $api['wp-mcp/poke']['permission_callback'](['id' => 7]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_forbidden')
            ->and($result->get_error_data())->toBe(['status' => 403])
            ->and($log)->toBe([]);
    });

    it('lets a read-only token reach a reading ability', function (): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy']]);
        Scope::setCurrent(['read']);

        expect($api['wp-mcp/peek']['permission_callback']([]))->toBeTrue()
            ->and($log)->toBe(['peek']);
    });

    it('lets a write token through to the capability check', function (array $scope): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy']]);
        Scope::setCurrent($scope);

        expect($api['wp-mcp/poke']['permission_callback'](['id' => 7]))->toBeTrue();
    })->with([
        'read and write' => [['read', 'write']],
        'write alone' => [['write']],
    ]);

    it('does not widen the capability check for a write token', function (): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy']]);
        Scope::setCurrent(['read', 'write']);

        expect($api['wp-mcp/poke']['permission_callback'](['id' => 99]))->toBeFalse();
    });

    it('treats a request with no token as allowed to write, leaving the capability check in charge', function (): void {
        $log = [];
        $api = abcoreRegister([abcoreSpyGroup($log)], ['enabled_groups' => ['spy']]);
        Scope::setCurrent(null);

        expect($api['wp-mcp/poke']['permission_callback'](['id' => 7]))->toBeTrue();
    });
});

describe('categories', function (): void {
    it('registers one namespaced category per enum case', function (): void {
        abcoreAbilitiesApi();

        (new AbilityRegistry(Settings::fromArray(['ability_namespace' => 'acme'])))->registerCategories();

        expect(array_keys($GLOBALS['abcore_categories']->getArrayCopy()))->toBe([
            'acme-content',
            'acme-taxonomy',
            'acme-media',
            'acme-navigation',
            'acme-users',
            'acme-site',
        ]);
    });

    it('leaves a category another plugin already registered alone', function (): void {
        abcoreAbilitiesApi();
        $GLOBALS['abcore_categories']['wp-mcp-site'] = ['label' => 'Theirs'];

        (new AbilityRegistry(Settings::fromArray([])))->registerCategories();

        expect($GLOBALS['abcore_categories']['wp-mcp-site'])->toBe(['label' => 'Theirs'])
            ->and($GLOBALS['abcore_categories'])->toHaveCount(6);
    });
});

describe('registering the real site and user groups together', function (): void {
    it('publishes users abilities only when the group is switched on', function (): void {
        $groups = [new SiteGroup(), new UserGroup()];

        $off = abcoreRegister($groups, ['enabled_groups' => ['site']]);
        expect($off->getArrayCopy())->not->toHaveKey('wp-mcp/get-users');

        abcoreResetRegistry();
        $on = abcoreRegister($groups, ['enabled_groups' => ['site', 'users'], 'read_only_mode' => true]);
        expect(array_keys($on->getArrayCopy()))->toBe([
            'wp-mcp/get-site-info',
            'wp-mcp/get-post-types',
            'wp-mcp/get-users',
            'wp-mcp/get-user',
        ]);
    });
});

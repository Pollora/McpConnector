<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\Group\UserGroup;

require_once __DIR__ . '/../../Fixtures/abcore-helpers.php';

/**
 * Make current_user_can() answer true for exactly the given capabilities.
 *
 * @param list<string> $capabilities
 */
function abcoreUsersCan(array $capabilities): void
{
    Functions\when('current_user_can')->alias(
        static fn (string $capability): bool => in_array($capability, $capabilities, true),
    );
}

/**
 * A user as WordPress would load it, including the columns a careless
 * formatter could leak.
 */
function abcoreUser(int $id = 5): WP_User
{
    $user = new class (['ID' => 5, 'user_login' => 'jane', 'user_email' => 'jane@example.test', 'display_name' => 'Jane', 'roles' => ['editor']]) extends WP_User {
        public string $user_registered = '2026-01-02 03:04:05';

        public string $user_pass = '$P$hash';

        public string $user_activation_key = 'activation-secret';
    };
    $user->ID = $id;

    return $user;
}

beforeEach(function (): void {
    abcoreUsersCan([]);
    Functions\when('get_author_posts_url')->alias(
        static fn (int $id): string => 'https://example.test/author/' . $id . '/',
    );
});

describe('the group', function (): void {
    it('is the users group, filed under users, and never writes', function (): void {
        $group = new UserGroup();
        $definitions = $group->definitions();

        expect($group->key())->toBe('users')
            ->and($group->label())->not->toBe('')
            ->and($group->description())->toContain('Never writes')
            ->and(array_map(static fn ($d): string => $d->slug, $definitions))->toBe(['get-users', 'get-user']);

        foreach ($definitions as $definition) {
            expect($definition->isReadOnly())->toBeTrue()
                ->and($definition->category)->toBe(AbilityCategory::Users);
        }
    });

    it('bounds the number of users returned in its schema', function (): void {
        $schema = abcoreDefinition(new UserGroup(), 'get-users')->inputSchema;

        expect($schema['properties']['number'])->toMatchArray(['minimum' => 1, 'maximum' => 100, 'default' => 20]);
    });
});

describe('permissions', function (): void {
    it('lets only users who may list users reach either ability', function (): void {
        $group = new UserGroup();

        abcoreUsersCan(['edit_posts', 'manage_options']);
        foreach ($group->definitions() as $definition) {
            expect(($definition->permission)(abcoreInput(['id' => 1])))->toBeFalse();
        }

        abcoreUsersCan(['list_users']);
        foreach ($group->definitions() as $definition) {
            expect(($definition->permission)(abcoreInput(['id' => 1])))->toBeTrue();
        }
    });
});

describe('get-users', function (): void {
    beforeEach(function (): void {
        $this->queries = [];
        Functions\when('get_users')->alias(function (array $args): array {
            $this->queries[] = $args;

            return [abcoreUser(5)];
        });
    });

    it('asks for twenty users by default', function (): void {
        (abcoreDefinition(new UserGroup(), 'get-users')->execute)(abcoreInput());

        expect($this->queries)->toBe([['number' => 20]]);
    });

    it('clamps the page size to the schema bounds', function (int $asked, int $expected): void {
        (abcoreDefinition(new UserGroup(), 'get-users')->execute)(abcoreInput(['number' => $asked]));

        expect($this->queries[0]['number'])->toBe($expected);
    })->with([
        'too many' => [10_000, 100],
        'zero' => [0, 1],
        'negative' => [-5, 1],
        'in range' => [37, 37],
    ]);

    it('filters by role and wraps the search term in wildcards', function (): void {
        abcoreUsersCan(['list_users', 'edit_users']);
        (abcoreDefinition(new UserGroup(), 'get-users')->execute)(abcoreInput(['role' => 'editor', 'search' => 'jan']));

        expect($this->queries[0])->toBe(['number' => 20, 'role' => 'editor', 'search' => '*jan*']);
    });

    it('searches the email only for a caller who may see it', function (bool $canEdit, array $expected): void {
        abcoreUsersCan($canEdit ? ['list_users', 'edit_users'] : ['list_users']);

        (abcoreDefinition(new UserGroup(), 'get-users')->execute)(abcoreInput(['search' => '@gmail']));

        expect($this->queries[0])->toBe(['number' => 20, 'search' => '*@gmail*'] + $expected);
    })->with([
        'without edit_users' => [false, ['search_columns' => ['user_login', 'display_name']]],
        'with edit_users' => [true, []],
    ]);

    it('ignores blank filters', function (): void {
        (abcoreDefinition(new UserGroup(), 'get-users')->execute)(abcoreInput(['role' => '', 'search' => '']));

        expect($this->queries[0])->toBe(['number' => 20]);
    });

    it('withholds email addresses from a caller without edit_users', function (): void {
        abcoreUsersCan(['list_users']);

        $users = (abcoreDefinition(new UserGroup(), 'get-users')->execute)(abcoreInput())['users'];

        expect($users[0])->not->toHaveKey('email')
            ->and(json_encode($users))->not->toContain('jane@example.test');
    });

    it('includes the email for a caller who holds edit_users', function (): void {
        abcoreUsersCan(['list_users', 'edit_users']);

        $users = (abcoreDefinition(new UserGroup(), 'get-users')->execute)(abcoreInput())['users'];

        expect($users[0]['email'])->toBe('jane@example.test');
    });
});

describe('get-user', function (): void {
    it('returns a public profile and never the password hash or activation key', function (): void {
        abcoreUsersCan(['list_users', 'edit_users']);
        Functions\when('get_userdata')->justReturn(abcoreUser(5));

        $record = (abcoreDefinition(new UserGroup(), 'get-user')->execute)(abcoreInput(['id' => 5]));

        expect($record)->toBe([
            'id' => 5,
            'login' => 'jane',
            'display_name' => 'Jane',
            'roles' => ['editor'],
            'url' => 'https://example.test/author/5/',
            'registered' => '2026-01-02 03:04:05',
            'email' => 'jane@example.test',
        ])->and(json_encode($record))->not->toContain('hash')->not->toContain('activation-secret');
    });

    it('omits the email without edit_users', function (): void {
        abcoreUsersCan(['list_users']);
        Functions\when('get_userdata')->justReturn(abcoreUser(5));

        $record = (abcoreDefinition(new UserGroup(), 'get-user')->execute)(abcoreInput(['id' => 5]));

        expect($record)->not->toHaveKey('email');
    });

    it('reports an unknown user as a 404 error rather than an empty record', function (): void {
        Functions\when('get_userdata')->justReturn(false);

        $result = (abcoreDefinition(new UserGroup(), 'get-user')->execute)(abcoreInput(['id' => 999]));

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_not_found')
            ->and($result->get_error_data())->toBe(['status' => 404]);
    });

    it('looks the user up by the id given', function (): void {
        $asked = [];
        Functions\when('get_userdata')->alias(function (int $id) use (&$asked): false {
            $asked[] = $id;

            return false;
        });

        (abcoreDefinition(new UserGroup(), 'get-user')->execute)(abcoreInput(['id' => '12']));

        expect($asked)->toBe([12]);
    });
});

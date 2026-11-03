<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\Group\NavigationGroup;
use Pollora\McpConnector\Tests\Unit\Abilities\AbilityHarness as H;

beforeEach(function (): void {
    H::actAs([]);
});

function navAbility(string $slug): \Pollora\McpConnector\Abilities\AbilityDefinition
{
    return H::definition(new NavigationGroup(), $slug);
}

/**
 * Make wp_get_nav_menu_object() resolve these menus by id, slug or name.
 */
function knownMenus(WP_Term ...$menus): void
{
    Functions\when('wp_get_nav_menu_object')->alias(static function (mixed $handle) use ($menus): WP_Term|false {
        foreach ($menus as $menu) {
            if ((string) $handle === (string) $menu->term_id || $handle === $menu->slug || $handle === $menu->name) {
                return $menu;
            }
        }

        return false;
    });
}

function menuTerm(int $id, string $name): WP_Term
{
    return new WP_Term(['term_id' => $id, 'name' => $name, 'slug' => strtolower($name), 'count' => 3]);
}

/**
 * A nav_menu_item post, with the properties wp_setup_nav_menu_item() adds.
 *
 * @param array<string, mixed> $overrides
 */
function menuItem(int $id, array $overrides = []): WP_Post
{
    $item = new WP_Post(['ID' => $id, 'post_type' => 'nav_menu_item', 'menu_order' => 2]);

    $properties = $overrides + [
        'title' => 'Home',
        'url' => 'https://example.test/',
        'menu_item_parent' => '0',
        'type' => 'custom',
        'object' => 'custom',
        'object_id' => (string) $id,
        'target' => '',
        'classes' => ['', 'primary'],
        'attr_title' => 'tt',
        'xfn' => 'me',
        'description' => 'old description',
    ];

    foreach ($properties as $name => $value) {
        $item->{$name} = $value;
    }

    return $item;
}

describe('the group', function (): void {
    it('publishes eight abilities under a stable key', function (): void {
        $group = new NavigationGroup();

        expect($group->key())->toBe('navigation')
            ->and($group->label())->not->toBe('')
            ->and($group->description())->not->toBe('')
            ->and(array_map(static fn ($d): string => $d->slug, $group->definitions()))->toBe([
                'get-menus', 'get-menu-items', 'get-menu-locations', 'create-menu',
                'add-menu-item', 'update-menu-item', 'delete-menu-item', 'assign-menu-location',
            ]);
    });

    it('files every ability under the navigation category', function (): void {
        foreach ((new NavigationGroup())->definitions() as $definition) {
            expect($definition->category)->toBe(AbilityCategory::Navigation);
        }
    });

    it('declares what each ability does to the site', function (string $slug, Behaviour $behaviour): void {
        expect(navAbility($slug)->behaviour)->toBe($behaviour);
    })->with([
        ['get-menus', Behaviour::Reads],
        ['get-menu-items', Behaviour::Reads],
        ['get-menu-locations', Behaviour::Reads],
        ['create-menu', Behaviour::Creates],
        ['add-menu-item', Behaviour::Creates],
        ['update-menu-item', Behaviour::Updates],
        ['delete-menu-item', Behaviour::Deletes],
        ['assign-menu-location', Behaviour::Updates],
    ]);

    it('withholds every write in read-only mode', function (): void {
        $readOnly = array_filter((new NavigationGroup())->definitions(), static fn ($d): bool => $d->isReadOnly());

        expect(array_map(static fn ($d): string => $d->slug, array_values($readOnly)))
            ->toBe(['get-menus', 'get-menu-items', 'get-menu-locations']);
    });

    it('guards every ability with edit_theme_options and nothing weaker', function (): void {
        foreach ((new NavigationGroup())->definitions() as $definition) {
            H::actAs(['edit_posts', 'manage_options', 'upload_files']);
            expect(H::permitted($definition))->toBeFalse();

            H::actAs(['edit_theme_options']);
            expect(H::permitted($definition))->toBeTrue();
        }
    });

    it('advertises the item types a menu item can have', function (): void {
        $properties = navAbility('add-menu-item')->inputSchema['properties'];

        expect($properties['type']['enum'])->toBe(['custom', 'post_type', 'taxonomy'])
            ->and($properties['target']['enum'])->toBe(['', '_blank'])
            ->and(navAbility('add-menu-item')->inputSchema['required'])->toBe(['menu']);
    });
});

describe('get-menus', function (): void {
    it('lists the menus and says whether the theme is block-based', function (): void {
        Functions\when('wp_get_nav_menus')->justReturn([menuTerm(2, 'Main')]);
        Functions\when('wp_is_block_theme')->justReturn(true);

        expect(H::run(navAbility('get-menus')))->toBe([
            'menus' => [['id' => 2, 'name' => 'Main', 'slug' => 'main', 'count' => 3]],
            'theme_is_block_based' => true,
        ]);
    });

    it('takes no input', function (): void {
        expect(navAbility('get-menus')->inputSchema)->toBe(['type' => 'object', 'additionalProperties' => false]);
    });
});

describe('get-menu-items', function (): void {
    it('lists the items of a menu', function (): void {
        Functions\expect('wp_get_nav_menu_items')->once()->with('main')->andReturn([
            menuItem(30, ['menu_item_parent' => '12', 'object_id' => '44', 'type' => 'post_type', 'object' => 'page', 'target' => '_blank']),
        ]);

        expect(H::run(navAbility('get-menu-items'), ['menu' => 'main'])['items'])->toBe([[
            'id' => 30,
            'title' => 'Home',
            'url' => 'https://example.test/',
            'parent' => 12,
            'order' => 2,
            'type' => 'post_type',
            'object' => 'page',
            'object_id' => 44,
            'target' => '_blank',
            'classes' => ['primary'],
        ]]);
    });

    it('answers with a 404 error for a menu that does not exist', function (): void {
        Functions\when('wp_get_nav_menu_items')->justReturn(false);

        $result = H::run(navAbility('get-menu-items'), ['menu' => 'ghost']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_not_found')
            ->and($result->get_error_message())->toContain('ghost');
    });

    it('returns an empty list for an empty menu', function (): void {
        Functions\when('wp_get_nav_menu_items')->justReturn([]);

        expect(H::run(navAbility('get-menu-items'), ['menu' => 'main']))->toBe(['items' => []]);
    });
});

describe('get-menu-locations', function (): void {
    it('maps each registered location to the menu assigned to it', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        Functions\when('get_registered_nav_menus')->justReturn(['primary' => 'Primary', 'footer' => 'Footer', 'stale' => 'Stale']);
        Functions\when('get_nav_menu_locations')->justReturn(['primary' => 2, 'stale' => 99]);

        expect(H::run(navAbility('get-menu-locations'))['locations'])->toBe([
            ['slug' => 'primary', 'label' => 'Primary', 'menu_id' => 2, 'menu_name' => 'Main'],
            ['slug' => 'footer', 'label' => 'Footer', 'menu_id' => null, 'menu_name' => null],
            ['slug' => 'stale', 'label' => 'Stale', 'menu_id' => 99, 'menu_name' => null],
        ]);
    });
});

describe('create-menu', function (): void {
    it('creates a menu and returns its id', function (): void {
        knownMenus();
        Functions\expect('wp_create_nav_menu')->once()->with('Footer links')->andReturn(14);

        expect(H::run(navAbility('create-menu'), ['name' => 'Footer links']))->toBe(['id' => 14, 'name' => 'Footer links']);
    });

    it('refuses a name that is already taken', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        Functions\expect('wp_create_nav_menu')->never();

        $result = H::run(navAbility('create-menu'), ['name' => 'Main']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_invalid_input')
            ->and($result->get_error_message())->toBe('A menu named "Main" already exists.');
    });

    it('relays an error from WordPress', function (): void {
        knownMenus();
        Functions\when('wp_create_nav_menu')->justReturn(new WP_Error('menu_exists', 'The menu name is taken.'));

        $result = H::run(navAbility('create-menu'), ['name' => 'X']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('menu_exists')
            ->and($result->get_error_message())->toBe('Could not create the menu: The menu name is taken.');
    });
});

describe('add-menu-item', function (): void {
    it('adds a custom link to the menu', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        $captured = [];
        Functions\when('wp_update_nav_menu_item')->alias(function (int $menuId, int $itemId, array $data) use (&$captured): int {
            $captured = [$menuId, $itemId, $data];

            return 77;
        });

        $result = H::run(navAbility('add-menu-item'), ['menu' => 'Main', 'title' => 'Blog', 'url' => 'https://example.test/blog']);

        expect($result)->toBe(['id' => 77, 'menu_id' => 2])
            ->and($captured)->toBe([2, 0, [
                'menu-item-title' => 'Blog',
                'menu-item-type' => 'custom',
                'menu-item-status' => 'publish',
                'menu-item-parent-id' => 0,
                'menu-item-target' => '',
                'menu-item-description' => '',
                'menu-item-url' => 'https://example.test/blog',
            ]]);
    });

    it('runs the URL through esc_url_raw', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        $captured = [];
        $sanitised = [];
        Functions\when('esc_url_raw')->alias(function (string $url) use (&$sanitised): string {
            $sanitised[] = $url;

            return 'sanitised';
        });
        Functions\when('wp_update_nav_menu_item')->alias(function (int $menuId, int $itemId, array $data) use (&$captured): int {
            $captured = $data;

            return 77;
        });

        H::run(navAbility('add-menu-item'), ['menu' => 'Main', 'url' => 'javascript:alert(1)']);

        expect($sanitised)->toBe(['javascript:alert(1)'])
            ->and($captured['menu-item-url'])->toBe('sanitised');
    });

    it('links to a post or term by object and id, with position, parent and target', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        $captured = [];
        Functions\when('wp_update_nav_menu_item')->alias(function (int $menuId, int $itemId, array $data) use (&$captured): int {
            $captured = $data;

            return 77;
        });

        H::run(navAbility('add-menu-item'), [
            'menu' => '2', 'type' => 'post_type', 'object' => 'page', 'object_id' => 44,
            'parent' => 9, 'position' => 0, 'target' => '_blank', 'url' => 'https://ignored.test',
        ]);

        expect($captured)->toMatchArray([
            'menu-item-type' => 'post_type',
            'menu-item-object' => 'page',
            'menu-item-object-id' => 44,
            'menu-item-parent-id' => 9,
            'menu-item-position' => 0,
            'menu-item-target' => '_blank',
        ])->not->toHaveKey('menu-item-url');
    });

    it('answers with a 404 error for a menu that does not exist', function (): void {
        knownMenus();
        Functions\expect('wp_update_nav_menu_item')->never();

        $result = H::run(navAbility('add-menu-item'), ['menu' => 'ghost', 'url' => 'https://example.test']);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_not_found');
    });

    it('requires a url for a custom link', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        Functions\expect('wp_update_nav_menu_item')->never();

        $result = H::run(navAbility('add-menu-item'), ['menu' => 'Main', 'title' => 'x']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('A custom menu item needs a url.');
    });

    it('requires both object and object_id for a linked item', function (array $input): void {
        knownMenus(menuTerm(2, 'Main'));
        Functions\expect('wp_update_nav_menu_item')->never();

        $result = H::run(navAbility('add-menu-item'), ['menu' => 'Main', 'type' => 'taxonomy'] + $input);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('A menu item of type "taxonomy" needs both object and object_id.');
    })->with([
        'neither' => [[]],
        'no id' => [['object' => 'category']],
        'no object' => [['object_id' => 5]],
        'an id of zero' => [['object' => 'category', 'object_id' => 0]],
        'an id that is not a number' => [['object' => 'category', 'object_id' => 'x']],
    ]);

    it('relays an error from WordPress', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        Functions\when('wp_update_nav_menu_item')->justReturn(new WP_Error('invalid_menu_item', 'Bad item.'));

        $result = H::run(navAbility('add-menu-item'), ['menu' => 'Main', 'url' => 'https://example.test']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('Could not add the menu item: Bad item.');
    });
});

describe('update-menu-item', function (): void {
    it('rewrites the item from its current values, changing only what was given', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        $existing = menuItem(30);
        Functions\when('get_post')->justReturn($existing);
        Functions\when('wp_setup_nav_menu_item')->returnArg(1);
        $captured = [];
        Functions\when('wp_update_nav_menu_item')->alias(function (int $menuId, int $itemId, array $data) use (&$captured): int {
            $captured = [$menuId, $itemId, $data];

            return 30;
        });

        $result = H::run(navAbility('update-menu-item'), ['menu' => 'Main', 'id' => 30, 'title' => 'Start']);

        expect($result)->toBe(['id' => 30, 'menu_id' => 2])
            ->and($captured)->toBe([2, 30, [
                'menu-item-title' => 'Start',
                'menu-item-url' => 'https://example.test/',
                'menu-item-parent-id' => 0,
                'menu-item-position' => 2,
                'menu-item-target' => '',
                'menu-item-description' => 'old description',
                'menu-item-type' => 'custom',
                'menu-item-object' => 'custom',
                'menu-item-object-id' => 30,
                'menu-item-attr-title' => 'tt',
                'menu-item-classes' => ' primary',
                'menu-item-xfn' => 'me',
                'menu-item-status' => 'publish',
            ]]);
    });

    it('applies every field that was given, and moves an item to the top level with parent 0', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        Functions\when('get_post')->justReturn(menuItem(30, ['menu_item_parent' => '8']));
        Functions\when('wp_setup_nav_menu_item')->returnArg(1);
        $captured = [];
        Functions\when('wp_update_nav_menu_item')->alias(function (int $menuId, int $itemId, array $data) use (&$captured): int {
            $captured = $data;

            return 30;
        });

        H::run(navAbility('update-menu-item'), [
            'menu' => 'Main', 'id' => 30, 'title' => 'T', 'url' => 'https://new.test', 'parent' => 0,
            'position' => 5, 'target' => '_blank', 'description' => 'new',
        ]);

        expect($captured)->toMatchArray([
            'menu-item-title' => 'T',
            'menu-item-url' => 'https://new.test',
            'menu-item-parent-id' => 0,
            'menu-item-position' => 5,
            'menu-item-target' => '_blank',
            'menu-item-description' => 'new',
        ]);
    });

    it('runs a new URL through esc_url_raw', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        Functions\when('get_post')->justReturn(menuItem(30));
        Functions\when('wp_setup_nav_menu_item')->returnArg(1);
        $sanitised = [];
        Functions\when('esc_url_raw')->alias(function (string $url) use (&$sanitised): string {
            $sanitised[] = $url;

            return 'sanitised';
        });
        $captured = [];
        Functions\when('wp_update_nav_menu_item')->alias(function (int $menuId, int $itemId, array $data) use (&$captured): int {
            $captured = $data;

            return 30;
        });

        H::run(navAbility('update-menu-item'), ['menu' => 'Main', 'id' => 30, 'url' => 'javascript:alert(1)']);

        expect($sanitised)->toBe(['javascript:alert(1)'])
            ->and($captured['menu-item-url'])->toBe('sanitised');
    });

    it('answers with a 404 error for a missing menu', function (): void {
        knownMenus();

        $result = H::run(navAbility('update-menu-item'), ['menu' => 'ghost', 'id' => 30]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_message())->toContain('menu');
    });

    it('refuses an id that is not a menu item, whatever else it is', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        Functions\expect('wp_update_nav_menu_item')->never();

        foreach ([null, new WP_Post(['ID' => 30, 'post_type' => 'page'])] as $post) {
            Functions\when('get_post')->justReturn($post);

            $result = H::run(navAbility('update-menu-item'), ['menu' => 'Main', 'id' => 30]);

            expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_not_found');
        }
    });

    it('relays an error from WordPress', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        Functions\when('get_post')->justReturn(menuItem(30));
        Functions\when('wp_setup_nav_menu_item')->returnArg(1);
        Functions\when('wp_update_nav_menu_item')->justReturn(new WP_Error('invalid_menu_item', 'Bad item.'));

        $result = H::run(navAbility('update-menu-item'), ['menu' => 'Main', 'id' => 30]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('Could not update the menu item: Bad item.');
    });
});

describe('delete-menu-item', function (): void {
    it('deletes a menu item permanently', function (): void {
        Functions\when('get_post')->justReturn(menuItem(30));
        Functions\when('is_nav_menu_item')->justReturn(true);
        Functions\expect('wp_delete_post')->once()->with(30, true)->andReturn(menuItem(30));

        expect(H::run(navAbility('delete-menu-item'), ['id' => 30]))->toBe(['deleted' => true, 'id' => 30]);
    });

    it('refuses anything that is not a menu item, so it cannot be used to delete a page', function (): void {
        Functions\when('is_nav_menu_item')->justReturn(true);
        Functions\expect('wp_delete_post')->never();

        foreach ([null, new WP_Post(['ID' => 30, 'post_type' => 'page'])] as $post) {
            Functions\when('get_post')->justReturn($post);

            $result = H::run(navAbility('delete-menu-item'), ['id' => 30]);

            expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_not_found');
        }
    });

    it('refuses a post WordPress does not recognise as a menu item', function (): void {
        Functions\when('get_post')->justReturn(menuItem(30));
        Functions\when('is_nav_menu_item')->justReturn(false);
        Functions\expect('wp_delete_post')->never();

        $result = H::run(navAbility('delete-menu-item'), ['id' => 30]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_data())->toBe(['status' => 404]);
    });

    it('reports a refusal from WordPress', function (): void {
        Functions\when('get_post')->justReturn(menuItem(30));
        Functions\when('is_nav_menu_item')->justReturn(true);
        Functions\when('wp_delete_post')->justReturn(false);

        $result = H::run(navAbility('delete-menu-item'), ['id' => 30]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('WordPress refused to delete menu item 30.');
    });
});

describe('assign-menu-location', function (): void {
    beforeEach(function (): void {
        Functions\when('get_registered_nav_menus')->justReturn(['primary' => 'Primary', 'footer' => 'Footer']);
        Functions\when('get_nav_menu_locations')->justReturn(['footer' => 5]);
    });

    it('assigns a menu to a location, keeping the other locations', function (): void {
        knownMenus(menuTerm(2, 'Main'));
        Functions\expect('set_theme_mod')->once()->with('nav_menu_locations', ['footer' => 5, 'primary' => 2]);

        expect(H::run(navAbility('assign-menu-location'), ['location' => 'primary', 'menu' => 'Main']))
            ->toBe(['location' => 'primary', 'menu_id' => 2, 'menu_name' => 'Main']);
    });

    it('clears a location when the menu is empty, keeping the others', function (): void {
        Functions\expect('set_theme_mod')->once()->with('nav_menu_locations', []);

        expect(H::run(navAbility('assign-menu-location'), ['location' => 'footer', 'menu' => '']))
            ->toBe(['location' => 'footer', 'menu_id' => null]);
    });

    it('refuses a location the theme does not declare', function (string $location): void {
        knownMenus(menuTerm(2, 'Main'));
        Functions\expect('set_theme_mod')->never();

        $result = H::run(navAbility('assign-menu-location'), ['location' => $location, 'menu' => 'Main']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_invalid_input')
            ->and($result->get_error_message())->toContain('"' . $location . '"');
    })->with(['sidebar', 'Primary', '', '../primary']);

    it('answers with a 404 error for a menu that does not exist', function (): void {
        knownMenus();
        Functions\expect('set_theme_mod')->never();

        $result = H::run(navAbility('assign-menu-location'), ['location' => 'primary', 'menu' => 'ghost']);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_not_found');
    });
});

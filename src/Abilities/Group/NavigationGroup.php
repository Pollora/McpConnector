<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities\Group;

use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\AbilityDefinition;
use Pollora\McpConnector\Abilities\AbilityGroup;
use Pollora\McpConnector\Abilities\Annotations;
use Pollora\McpConnector\Abilities\Input;
use Pollora\McpConnector\Abilities\Schema;
use Pollora\McpConnector\Support\Failure;
use WP_Error;
use WP_Post;
use WP_Term;

defined('ABSPATH') || exit;

/**
 * Reading and writing classic navigation menus and their items.
 *
 * These abilities target the classic menu system — `wp_nav_menu` and the
 * `nav_menu` taxonomy. Block themes may instead use the `wp_navigation` post
 * type, whose items live in block markup and are edited through the content
 * abilities. {@see NavigationGroup::listMenus()} reports which system a site is
 * using so a caller does not spend three calls discovering it.
 */
final class NavigationGroup implements AbilityGroup
{
    /**
     * Stable group key stored in the settings option.
     *
     * @var string
     */
    public const KEY = 'navigation';

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('Navigation menus', 'amphibee-mcp-connector');
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return __(
            'Read and edit classic navigation menus, their items and their theme locations.',
            'amphibee-mcp-connector'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function definitions(): array
    {
        return [
            $this->listMenus(),
            $this->getMenu(),
            $this->listMenuLocations(),
            $this->createMenu(),
            $this->addMenuItem(),
            $this->updateMenuItem(),
            $this->deleteMenuItem(),
            $this->assignMenuLocation(),
        ];
    }

    /**
     * List the classic menus registered on the site.
     *
     * @return AbilityDefinition The ability.
     */
    private function listMenus(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-menus',
            label: __('List menus', 'amphibee-mcp-connector'),
            description: 'List the site\'s classic navigation menus with their item counts. Also reports '
                . 'whether the active theme is block-based, in which case navigation may live in '
                . 'wp_navigation posts instead and these abilities will not see it.',
            category: AbilityCategory::Navigation,
            inputSchema: Schema::object(),
            execute: static function (): array {
                $menus = array_map(
                    static fn (WP_Term $menu): array => [
                        'id' => $menu->term_id,
                        'name' => $menu->name,
                        'slug' => $menu->slug,
                        'count' => $menu->count,
                    ],
                    wp_get_nav_menus()
                );

                return [
                    'menus' => $menus,
                    'theme_is_block_based' => wp_is_block_theme(),
                ];
            },
            permission: static fn (): bool => current_user_can('edit_theme_options'),
        );
    }

    /**
     * Read one menu's items, in order.
     *
     * @return AbilityDefinition The ability.
     */
    private function getMenu(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-menu-items',
            label: __('Read menu items', 'amphibee-mcp-connector'),
            description: 'List the items of one menu in display order, with their URLs, parents and the '
                . 'objects they point at. Item IDs from this list are what update-menu-item and '
                . 'delete-menu-item expect.',
            category: AbilityCategory::Navigation,
            inputSchema: Schema::object([
                'menu' => Schema::string('Menu ID, slug or name.'),
            ], ['menu']),
            execute: static function (Input $input): array|WP_Error {
                $items = wp_get_nav_menu_items($input->string('menu'));

                if ($items === false) {
                    return Failure::notFound('menu', $input->string('menu'));
                }

                return [
                    'items' => array_map(
                        static fn (WP_Post $item): array => [
                            'id' => $item->ID,
                            'title' => $item->title,
                            'url' => $item->url,
                            'parent' => (int) $item->menu_item_parent,
                            'order' => $item->menu_order,
                            'type' => $item->type,
                            'object' => $item->object,
                            'object_id' => (int) $item->object_id,
                            'target' => $item->target,
                            'classes' => array_values(array_filter((array) $item->classes)),
                        ],
                        $items
                    ),
                ];
            },
            permission: static fn (): bool => current_user_can('edit_theme_options'),
        );
    }

    /**
     * List the theme's menu locations and what is assigned to each.
     *
     * @return AbilityDefinition The ability.
     */
    private function listMenuLocations(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-menu-locations',
            label: __('List menu locations', 'amphibee-mcp-connector'),
            description: 'List the menu locations the active theme declares, and which menu is currently '
                . 'assigned to each.',
            category: AbilityCategory::Navigation,
            inputSchema: Schema::object(),
            execute: static function (): array {
                $assigned = get_nav_menu_locations();

                $locations = [];

                foreach (get_registered_nav_menus() as $slug => $label) {
                    $menuId = (int) ($assigned[$slug] ?? 0);
                    $menu = $menuId > 0 ? wp_get_nav_menu_object($menuId) : false;

                    $locations[] = [
                        'slug' => $slug,
                        'label' => $label,
                        'menu_id' => $menuId ?: null,
                        'menu_name' => $menu instanceof WP_Term ? $menu->name : null,
                    ];
                }

                return ['locations' => $locations];
            },
            permission: static fn (): bool => current_user_can('edit_theme_options'),
        );
    }

    /**
     * Create an empty menu.
     *
     * @return AbilityDefinition The ability.
     */
    private function createMenu(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'create-menu',
            label: __('Create a menu', 'amphibee-mcp-connector'),
            description: 'Create an empty navigation menu. Add items with add-menu-item, then put it on '
                . 'the site with assign-menu-location.',
            category: AbilityCategory::Navigation,
            inputSchema: Schema::object([
                'name' => Schema::string('Menu name, as shown in the admin.'),
            ], ['name']),
            execute: static function (Input $input): array|WP_Error {
                $name = $input->string('name');

                if (wp_get_nav_menu_object($name) instanceof WP_Term) {
                    return Failure::invalid(sprintf('A menu named "%s" already exists.', $name));
                }

                $menuId = wp_create_nav_menu($name);

                if (is_wp_error($menuId)) {
                    return Failure::fromWordPress($menuId, 'create the menu');
                }

                return ['id' => (int) $menuId, 'name' => $name];
            },
            permission: static fn (): bool => current_user_can('edit_theme_options'),
        );
    }

    /**
     * Add an item to a menu.
     *
     * @return AbilityDefinition The ability.
     */
    private function addMenuItem(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'add-menu-item',
            label: __('Add a menu item', 'amphibee-mcp-connector'),
            description: 'Add an item to a menu. Pass object_id with type=post_type or type=taxonomy to '
                . 'link to a page or term and keep the link in step with its permalink, or pass url for '
                . 'a custom link.',
            category: AbilityCategory::Navigation,
            inputSchema: Schema::object([
                'menu' => Schema::string('Menu ID, slug or name to add the item to.'),
                'title' => Schema::string('Link text. Falls back to the linked object\'s title when omitted.'),
                'type' => Schema::string('What the item links to.', 'custom', ['custom', 'post_type', 'taxonomy']),
                'object' => Schema::string('Post type or taxonomy slug, required when type is post_type or taxonomy.'),
                'object_id' => Schema::integer('ID of the linked post or term, required when type is post_type or taxonomy.', minimum: 1),
                'url' => Schema::string('Destination URL, required when type is custom.'),
                'parent' => Schema::integer('Menu item ID to nest this item under.', minimum: 0),
                'position' => Schema::integer('Sort position within the menu.', minimum: 0),
                'target' => Schema::string('Link target. Use _blank to open in a new tab.', enum: ['', '_blank']),
                'description' => Schema::string('Item description, shown by themes that support it.'),
            ], ['menu']),
            execute: static function (Input $input): array|WP_Error {
                $menu = wp_get_nav_menu_object($input->string('menu'));

                if (! $menu instanceof WP_Term) {
                    return Failure::notFound('menu', $input->string('menu'));
                }

                $type = $input->string('type', 'custom');

                if ($type === 'custom' && ! $input->filled('url')) {
                    return Failure::invalid('A custom menu item needs a url.');
                }

                if ($type !== 'custom' && ($input->id('object_id') === 0 || ! $input->filled('object'))) {
                    return Failure::invalid(sprintf(
                        'A menu item of type "%s" needs both object and object_id.',
                        $type
                    ));
                }

                $itemData = [
                    'menu-item-title' => $input->string('title'),
                    'menu-item-type' => $type,
                    'menu-item-status' => 'publish',
                    'menu-item-parent-id' => $input->id('parent'),
                    'menu-item-target' => $input->string('target'),
                    'menu-item-description' => $input->string('description'),
                ];

                if ($type === 'custom') {
                    $itemData['menu-item-url'] = esc_url_raw($input->string('url'));
                } else {
                    $itemData['menu-item-object'] = $input->string('object');
                    $itemData['menu-item-object-id'] = $input->id('object_id');
                }

                if ($input->has('position')) {
                    $itemData['menu-item-position'] = $input->integer('position', min: 0);
                }

                $itemId = wp_update_nav_menu_item($menu->term_id, 0, $itemData);

                if (is_wp_error($itemId)) {
                    return Failure::fromWordPress($itemId, 'add the menu item');
                }

                return ['id' => (int) $itemId, 'menu_id' => $menu->term_id];
            },
            permission: static fn (): bool => current_user_can('edit_theme_options'),
        );
    }

    /**
     * Change an existing menu item.
     *
     * @return AbilityDefinition The ability.
     */
    private function updateMenuItem(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'update-menu-item',
            label: __('Update a menu item', 'amphibee-mcp-connector'),
            description: 'Change a menu item\'s title, URL, parent or position. Read the menu first with '
                . 'get-menu-items: every field is rewritten on save, so anything omitted here is restored '
                . 'from the item\'s current value.',
            category: AbilityCategory::Navigation,
            inputSchema: Schema::object([
                'menu' => Schema::string('Menu ID, slug or name the item belongs to.'),
                'id' => Schema::integer('ID of the menu item to update.', minimum: 1),
                'title' => Schema::string('New link text.'),
                'url' => Schema::string('New destination URL, for custom links.'),
                'parent' => Schema::integer('New parent menu item ID. Pass 0 to move it to the top level.', minimum: 0),
                'position' => Schema::integer('New sort position.', minimum: 0),
                'target' => Schema::string('New link target.', enum: ['', '_blank']),
                'description' => Schema::string('New item description.'),
            ], ['menu', 'id']),
            execute: static function (Input $input): array|WP_Error {
                $menu = wp_get_nav_menu_object($input->string('menu'));

                if (! $menu instanceof WP_Term) {
                    return Failure::notFound('menu', $input->string('menu'));
                }

                $itemId = $input->id('id');
                $existing = get_post($itemId);

                if (! $existing instanceof WP_Post || $existing->post_type !== 'nav_menu_item') {
                    return Failure::notFound('menu item', $itemId);
                }

                // wp_update_nav_menu_item() rewrites every field it is given and
                // clears the ones it is not, so the current values have to be
                // read back and used as the baseline for a partial update.
                $current = wp_setup_nav_menu_item($existing);

                $itemData = [
                    'menu-item-title' => $input->has('title') ? $input->string('title') : $current->title,
                    'menu-item-url' => $input->has('url') ? esc_url_raw($input->string('url')) : $current->url,
                    'menu-item-parent-id' => $input->has('parent') ? $input->id('parent') : (int) $current->menu_item_parent,
                    'menu-item-position' => $input->has('position') ? $input->integer('position', min: 0) : $current->menu_order,
                    'menu-item-target' => $input->has('target') ? $input->string('target') : $current->target,
                    'menu-item-description' => $input->has('description') ? $input->string('description') : $current->description,
                    'menu-item-type' => $current->type,
                    'menu-item-object' => $current->object,
                    'menu-item-object-id' => (int) $current->object_id,
                    'menu-item-attr-title' => $current->attr_title,
                    'menu-item-classes' => implode(' ', (array) $current->classes),
                    'menu-item-xfn' => $current->xfn,
                    'menu-item-status' => 'publish',
                ];

                $result = wp_update_nav_menu_item($menu->term_id, $itemId, $itemData);

                if (is_wp_error($result)) {
                    return Failure::fromWordPress($result, 'update the menu item');
                }

                return ['id' => $itemId, 'menu_id' => $menu->term_id];
            },
            permission: static fn (): bool => current_user_can('edit_theme_options'),
            annotations: Annotations::updates(),
        );
    }

    /**
     * Remove an item from a menu.
     *
     * @return AbilityDefinition The ability.
     */
    private function deleteMenuItem(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'delete-menu-item',
            label: __('Delete a menu item', 'amphibee-mcp-connector'),
            description: 'Remove an item from a menu. Its children are promoted to the item\'s own parent '
                . 'rather than deleted.',
            category: AbilityCategory::Navigation,
            inputSchema: Schema::object([
                'id' => Schema::integer('ID of the menu item to delete.', minimum: 1),
            ], ['id']),
            execute: static function (Input $input): array|WP_Error {
                $itemId = $input->id('id');
                $item = get_post($itemId);

                if (! $item instanceof WP_Post || $item->post_type !== 'nav_menu_item') {
                    return Failure::notFound('menu item', $itemId);
                }

                if (! is_nav_menu_item($itemId)) {
                    return Failure::notFound('menu item', $itemId);
                }

                $result = wp_delete_post($itemId, true);

                if (! $result instanceof WP_Post) {
                    return Failure::invalid(sprintf('WordPress refused to delete menu item %d.', $itemId));
                }

                return ['deleted' => true, 'id' => $itemId];
            },
            permission: static fn (): bool => current_user_can('edit_theme_options'),
            annotations: Annotations::deletes(),
        );
    }

    /**
     * Put a menu in one of the theme's locations.
     *
     * @return AbilityDefinition The ability.
     */
    private function assignMenuLocation(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'assign-menu-location',
            label: __('Assign a menu to a location', 'amphibee-mcp-connector'),
            description: 'Display a menu in one of the theme\'s menu locations. Call get-menu-locations '
                . 'first for the location slugs this theme declares.',
            category: AbilityCategory::Navigation,
            inputSchema: Schema::object([
                'location' => Schema::string('Theme menu location slug.'),
                'menu' => Schema::string('Menu ID, slug or name to display there. Pass an empty string to clear the location.'),
            ], ['location', 'menu']),
            execute: static function (Input $input): array|WP_Error {
                $location = $input->string('location');

                if (! array_key_exists($location, get_registered_nav_menus())) {
                    return Failure::invalid(sprintf(
                        'The active theme does not declare a menu location named "%s". Call get-menu-locations to list them.',
                        $location
                    ));
                }

                $locations = get_nav_menu_locations();

                if (! $input->filled('menu')) {
                    unset($locations[$location]);
                    set_theme_mod('nav_menu_locations', $locations);

                    return ['location' => $location, 'menu_id' => null];
                }

                $menu = wp_get_nav_menu_object($input->string('menu'));

                if (! $menu instanceof WP_Term) {
                    return Failure::notFound('menu', $input->string('menu'));
                }

                $locations[$location] = $menu->term_id;
                set_theme_mod('nav_menu_locations', $locations);

                return ['location' => $location, 'menu_id' => $menu->term_id, 'menu_name' => $menu->name];
            },
            permission: static fn (): bool => current_user_can('edit_theme_options'),
            annotations: Annotations::updates(),
        );
    }
}

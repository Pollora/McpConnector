<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\Group\SiteGroup;

require_once __DIR__ . '/../../Fixtures/abcore-helpers.php';

/**
 * Make current_user_can() answer true for exactly the given capabilities.
 *
 * @param list<string> $capabilities
 */
function abcoreUserCan(array $capabilities): void
{
    Functions\when('current_user_can')->alias(
        static fn (string $capability): bool => in_array($capability, $capabilities, true),
    );
}

describe('the group', function (): void {
    it('is the site group, with one read-only ability less than it has abilities', function (): void {
        $group = new SiteGroup();
        $slugs = array_map(static fn ($d): string => $d->slug, $group->definitions());

        expect($group->key())->toBe('site')
            ->and($group->label())->not->toBe('')
            ->and($group->description())->not->toBe('')
            ->and($slugs)->toBe(['get-site-info', 'get-post-types', 'update-option']);
    });

    it('files every ability under the site category and only update-option writes', function (): void {
        foreach ((new SiteGroup())->definitions() as $definition) {
            expect($definition->category)->toBe(AbilityCategory::Site)
                ->and($definition->isReadOnly())->toBe($definition->slug !== 'update-option');
        }

        expect(abcoreDefinition(new SiteGroup(), 'update-option')->behaviour)->toBe(Behaviour::Updates);
    });

    it('declares an object schema for every ability', function (): void {
        foreach ((new SiteGroup())->definitions() as $definition) {
            expect($definition->inputSchema['type'])->toBe('object');
        }
    });

    it('requires both option_name and option_value to update an option', function (): void {
        $schema = abcoreDefinition(new SiteGroup(), 'update-option')->inputSchema;

        expect($schema['required'])->toEqualCanonicalizing(['option_name', 'option_value']);
    });
});

describe('the writable options', function (): void {
    it('are the title and the tagline by default', function (): void {
        expect(SiteGroup::writableOptions())->toBe(['blogname', 'blogdescription']);
    });

    it('can be widened by a filter, and are normalised to unique strings', function (): void {
        Filters\expectApplied(SiteGroup::WRITABLE_OPTIONS_FILTER)
            ->andReturn(['blogname', 'blogname', 'acme_flag', 42]);

        expect(SiteGroup::writableOptions())->toBe(['blogname', 'acme_flag', '42']);
    });
});

describe('permissions', function (): void {
    it('lets contributors read site information and post types, not change options', function (): void {
        abcoreUserCan(['edit_posts']);
        $group = new SiteGroup();

        expect((abcoreDefinition($group, 'get-site-info')->permission)(abcoreInput()))->toBeTrue()
            ->and((abcoreDefinition($group, 'get-post-types')->permission)(abcoreInput()))->toBeTrue()
            ->and((abcoreDefinition($group, 'update-option')->permission)(abcoreInput(['option_name' => 'blogname'])))->toBeFalse();
    });

    it('refuses every ability to a user without edit_posts or manage_options', function (): void {
        abcoreUserCan(['read']);
        $group = new SiteGroup();

        foreach ($group->definitions() as $definition) {
            expect(($definition->permission)(abcoreInput()))->toBeFalse();
        }
    });

    it('requires manage_options to update an option', function (): void {
        abcoreUserCan(['manage_options']);

        expect((abcoreDefinition(new SiteGroup(), 'update-option')->permission)(abcoreInput()))->toBeTrue();
    });
});

describe('get-site-info', function (): void {
    beforeEach(function (): void {
        $theme = new class () {
            public function get(string $key): string
            {
                return ['Name' => 'Buzz', 'Version' => '1.1.0'][$key] ?? '';
            }

            public function get_stylesheet(): string
            {
                return 'buzz';
            }
        };
        Functions\when('wp_get_theme')->justReturn($theme);
        Functions\when('wp_timezone_string')->justReturn('Europe/Paris');
        Functions\when('wp_is_block_theme')->justReturn(true);
    });

    it('describes the site and lists what may be written', function (): void {
        $info = (abcoreDefinition(new SiteGroup(), 'get-site-info')->execute)(abcoreInput());

        expect($info)->toMatchArray([
            'name' => 'Example Site',
            'description' => 'Just another example',
            'url' => 'https://example.test/',
            'language' => 'en-US',
            'timezone' => 'Europe/Paris',
            'is_block_theme' => true,
            'theme' => ['name' => 'Buzz', 'version' => '1.1.0', 'stylesheet' => 'buzz'],
            'writable_options' => ['blogname', 'blogdescription'],
        ]);
    });

    it('does not leak the administrator email or other sensitive options', function (): void {
        $info = (abcoreDefinition(new SiteGroup(), 'get-site-info')->execute)(abcoreInput());

        expect(array_keys($info))->toBe([
            'name', 'description', 'url', 'admin_url', 'language', 'timezone',
            'wp_version', 'is_block_theme', 'theme', 'writable_options',
        ])->and(json_encode($info))->not->toContain('admin_email');
    });
});

describe('get-post-types', function (): void {
    beforeEach(function (): void {
        $this->queries = [];
        $page = new WP_Post_Type('page', 'Pages');
        $page->hierarchical = true;
        $secret = new WP_Post_Type('internal', 'Internal');
        $secret->public = false;
        $secret->cap->edit_posts = 'edit_internals';

        Functions\when('get_post_types')->alias(function (array $args) use ($page, $secret): array {
            $this->queries[] = $args;

            return $args === ['public' => true] ? ['page' => $page] : ['page' => $page, 'internal' => $secret];
        });
        Functions\when('get_all_post_type_supports')->justReturn(['title' => true, 'editor' => true]);
        Functions\when('get_object_taxonomies')->justReturn(['category']);
        Functions\when('get_registered_meta_keys')->alias(
            static fn (string $type, string $subtype = ''): array => $subtype === 'page' ? [
                'subtitle' => ['type' => 'string', 'single' => true, 'description' => 'A subtitle', 'show_in_rest' => true],
                '_private' => ['type' => 'string', 'show_in_rest' => true],
                'hidden' => ['type' => 'string', 'show_in_rest' => false],
            ] : [],
        );
    });

    it('lists only public post types by default', function (): void {
        $result = (abcoreDefinition(new SiteGroup(), 'get-post-types')->execute)(abcoreInput());

        expect($this->queries)->toBe([['public' => true]])
            ->and(array_column($result['post_types'], 'name'))->toBe(['page']);
    });

    it('lists every post type when public_only is false', function (): void {
        abcoreUserCan(['edit_posts', 'edit_internals']);
        $result = (abcoreDefinition(new SiteGroup(), 'get-post-types')->execute)(abcoreInput(['public_only' => false]));

        expect($this->queries)->toBe([[]])
            ->and(array_column($result['post_types'], 'name'))->toBe(['page', 'internal']);
    });

    it('keeps a type the caller cannot manage out of the full listing', function (): void {
        abcoreUserCan(['edit_posts']);

        $result = (abcoreDefinition(new SiteGroup(), 'get-post-types')->execute)(abcoreInput(['public_only' => false]));

        expect(array_column($result['post_types'], 'name'))->toBe(['page']);
    });

    it('withholds the protected meta of an internal type', function (): void {
        abcoreUserCan(['edit_posts', 'edit_internals']);
        Functions\when('get_registered_meta_keys')->justReturn([
            'plain' => ['type' => 'string', 'show_in_rest' => true],
            '_secret' => ['type' => 'string', 'show_in_rest' => true],
        ]);

        $internal = (abcoreDefinition(new SiteGroup(), 'get-post-types')->execute)(abcoreInput(['public_only' => false]))['post_types'][1];

        expect($internal['name'])->toBe('internal')
            ->and(array_column($internal['declared_meta'], 'key'))->toBe(['plain']);
    });

    it('describes the type, its supports, taxonomies and only the meta declared to REST', function (): void {
        $result = (abcoreDefinition(new SiteGroup(), 'get-post-types')->execute)(abcoreInput());
        $page = $result['post_types'][0];

        expect($page)->toMatchArray([
            'name' => 'page',
            'label' => 'Pages',
            'hierarchical' => true,
            'supports' => ['title', 'editor'],
            'taxonomies' => ['category'],
        ])->and(array_column($page['declared_meta'], 'key'))->toBe(['subtitle', '_private'])
            ->and($page['declared_meta'][0])->toBe([
                'key' => 'subtitle', 'type' => 'string', 'single' => true,
                'description' => 'A subtitle', 'protected' => false,
            ])
            ->and($page['declared_meta'][1]['protected'])->toBeTrue()
            ->and($page['framework_fields'])->toBe([]);
    });
});

describe('update-option', function (): void {
    beforeEach(function (): void {
        $this->writes = [];
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->writes[$name] = $value;
            $this->optionValues[$name] = $value;

            return true;
        });
    });

    it('writes an allow-listed option and returns the stored value', function (): void {
        $result = (abcoreDefinition(new SiteGroup(), 'update-option')->execute)(
            abcoreInput(['option_name' => 'blogname', 'option_value' => 'New title']),
        );

        expect($result)->toBe(['option' => 'blogname', 'value' => 'New title'])
            ->and($this->writes)->toBe(['blogname' => 'New title']);
    });

    it('strips markup from the value before saving it', function (): void {
        (abcoreDefinition(new SiteGroup(), 'update-option')->execute)(
            abcoreInput(['option_name' => 'blogdescription', 'option_value' => '  <script>alert(1)</script>Hello ']),
        );

        expect($this->writes['blogdescription'])->toBe('alert(1)Hello');
    });

    it('refuses options outside the allow-list without touching the database', function (string $option): void {
        $result = (abcoreDefinition(new SiteGroup(), 'update-option')->execute)(
            abcoreInput(['option_name' => $option, 'option_value' => 'x']),
        );

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_forbidden')
            ->and($result->get_error_message())->toContain($option)->toContain('blogname, blogdescription')
            ->and($this->writes)->toBe([]);
    })->with([
        'site url' => ['siteurl'],
        'home' => ['home'],
        'registration switch' => ['users_can_register'],
        'default role' => ['default_role'],
        'admin email' => ['admin_email'],
        'empty name' => [''],
        'case variation' => ['BLOGNAME'],
    ]);

    it('allows an option a site added through the filter, and nothing else', function (): void {
        Filters\expectApplied(SiteGroup::WRITABLE_OPTIONS_FILTER)->andReturn(['blogname', 'acme_flag']);
        $execute = abcoreDefinition(new SiteGroup(), 'update-option')->execute;

        $allowed = $execute(abcoreInput(['option_name' => 'acme_flag', 'option_value' => 'on']));
        $refused = $execute(abcoreInput(['option_name' => 'siteurl', 'option_value' => 'https://evil.test']));

        expect($allowed)->toBe(['option' => 'acme_flag', 'value' => 'on'])
            ->and($refused)->toBeInstanceOf(WP_Error::class)
            ->and($this->writes)->toBe(['acme_flag' => 'on']);
    });
});

<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Admin\SettingsPage;
use Pollora\McpConnector\Admin\Wizard;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\TokenRepository;
use Pollora\McpConnector\Settings;

/**
 * Thrown by the mocked wp_safe_redirect(), so that the `exit` that follows it in
 * the code under test is never reached.
 */
final class AdminsettingsRedirected extends RuntimeException
{
}

/**
 * Run handleActions() and return the URL it redirected to, or null when it
 * returned without redirecting.
 */
function adminsettingsRun(): ?string
{
    try {
        (new SettingsPage())->handleActions();
    } catch (AdminsettingsRedirected $redirect) {
        return $redirect->getMessage();
    }

    return null;
}

/**
 * Render the whole screen and return its HTML.
 */
function adminsettingsRender(): string
{
    ob_start();

    try {
        (new SettingsPage())->render();
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}

function adminsettingsClient(string $id): array
{
    return (new Client($id, '', 'App ' . $id, [], 'none', 100, false))->toArray();
}

beforeEach(function (): void {
    $_POST = [];
    $_GET = [];

    $this->capable = true;
    $this->nonces = [];
    $this->postTypes = [
        'post' => new WP_Post_Type('post', 'Post'),
        'page' => new WP_Post_Type('page', 'Page'),
        'revision' => new WP_Post_Type('revision', 'Revision'),
    ];

    Settings::forget();

    Functions\when('current_user_can')->alias(fn (): bool => $this->capable);
    Functions\when('check_admin_referer')->alias(function (string $action): int {
        $this->nonces[] = $action;

        return 1;
    });
    Functions\when('wp_safe_redirect')->alias(static function (string $url): never {
        throw new AdminsettingsRedirected($url);
    });
    Functions\when('add_query_arg')->alias(
        static fn (array $args, string $url): string => $args === [] ? $url : $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args),
    );
    Functions\when('get_post_types')->alias(
        fn (array $args = [], string $output = 'names'): array => $output === 'objects'
            ? $this->postTypes
            : array_combine(array_keys($this->postTypes), array_keys($this->postTypes)),
    );
    Functions\when('wp_get_abilities')->alias(static fn (): array => [
        new WP_Ability('acme/read-things', 'Read things', 'Reads', 'acme', ['mcp' => ['public' => true], 'annotations' => ['readonly' => true]]),
    ]);
    Functions\when('wp_generate_uuid4')->justReturn('client-uuid');
    Functions\when('wp_roles')->justReturn((object) ['roles' => [
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['edit_posts' => true]],
    ]]);
    Functions\when('translate_user_role')->returnArg(1);
    Functions\when('_n')->alias(static fn ($single, $plural, $number) => $number === 1 ? $single : $plural);
    Functions\when('wp_nonce_field')->alias(static function (): void {
        echo '<input type="hidden" name="_wpnonce" value="nonce">';
    });
    Functions\when('wp_nonce_url')->alias(static fn (string $url, string $action): string => $url . '&_wpnonce=nonce');
    Functions\when('wp_create_nonce')->justReturn('nonce');
    Functions\when('wp_count_posts')->justReturn((object) ['publish' => 3]);
    Functions\when('number_format_i18n')->alias(static fn ($number) => (string) $number);
    Functions\when('post_type_exists')->justReturn(true);
    Functions\when('wp_remote_get')->justReturn([]);
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('{}');
    Functions\when('wp_remote_retrieve_header')->justReturn('');
    Functions\when('wp_hash_password')->alias(static fn (string $p): string => 'hashed:' . $p);
});

afterEach(function (): void {
    $_POST = [];
    $_GET = [];
    Settings::forget();
});

describe('the guard in front of every action', function (): void {
    it('does nothing, and checks no nonce, for a user without manage_options', function (): void {
        $this->capable = false;
        $_POST = ['mcp_connector_save' => '1', 'server_route' => 'hijacked'];
        $_GET = ['mcp_connector_delete_client' => 'abc'];

        expect(adminsettingsRun())->toBeNull()
            ->and($this->nonces)->toBe([])
            ->and($this->optionValues)->not->toHaveKey('mcp_connector_settings');
    });

    it('verifies the settings nonce before saving', function (): void {
        $_POST = ['mcp_connector_save' => '1'];

        adminsettingsRun();

        expect($this->nonces)->toBe([SettingsPage::NONCE_SETTINGS]);
    });

    it('writes nothing when the nonce check dies', function (): void {
        Functions\when('check_admin_referer')->alias(static function (): never {
            throw new RuntimeException('nonce rejected');
        });
        $_POST = ['mcp_connector_save' => '1', 'server_route' => 'hijacked'];

        expect(static fn () => (new SettingsPage())->handleActions())->toThrow(RuntimeException::class, 'nonce rejected')
            ->and($this->optionValues)->not->toHaveKey('mcp_connector_settings');
    });

    it('leaves the stored clients alone when the delete nonce is rejected', function (): void {
        $this->setOption(ClientRepository::OPTION, ['abc' => adminsettingsClient('abc')]);
        Functions\when('check_admin_referer')->alias(static function (): never {
            throw new RuntimeException('nonce rejected');
        });
        $_GET = ['mcp_connector_delete_client' => 'abc'];

        expect(static fn () => (new SettingsPage())->handleActions())->toThrow(RuntimeException::class)
            ->and($this->optionValues[ClientRepository::OPTION])->toHaveKey('abc');
    });

    it('ignores a request that carries no action at all', function (): void {
        expect(adminsettingsRun())->toBeNull()->and($this->nonces)->toBe([]);
    });
});

describe('saving the configuration', function (): void {
    it('stores each submitted value, cleaned, and redirects with a saved notice', function (): void {
        $_POST = [
            'mcp_connector_save' => '1',
            'ability_namespace' => 'My_Site',
            'server_route' => 'Route One',
            'required_capability' => 'Edit_Others_Posts',
            'enabled_groups' => ['posts', 'Media'],
            'read_only_mode' => '1',
            'oauth_enabled' => '1',
        ];

        $url = adminsettingsRun();
        $stored = $this->optionValues['mcp_connector_settings'];

        expect($stored['ability_namespace'])->toBe('my-site')
            ->and($stored['server_route'])->toBe('routeone')
            ->and($stored['required_capability'])->toBe('edit_others_posts')
            ->and($stored['enabled_groups'])->toBe(['posts', 'media'])
            ->and($stored['read_only_mode'])->toBeTrue()
            ->and($stored['oauth_enabled'])->toBeTrue()
            ->and($stored['dynamic_registration_open'])->toBeFalse()
            ->and($stored['post_types_reviewed'])->toBeTrue()
            ->and($stored['external_abilities_reviewed'])->toBeTrue()
            ->and($url)->toContain('saved=1')->toContain('page=mcp-connector');
    });

    it('treats an unchecked box as off, since browsers do not submit it', function (): void {
        $this->setSettings(['read_only_mode' => true, 'oauth_enabled' => true, 'dynamic_registration_open' => true]);
        $_POST = ['mcp_connector_save' => '1'];

        adminsettingsRun();

        $stored = $this->optionValues['mcp_connector_settings'];

        expect($stored['read_only_mode'])->toBeFalse()
            ->and($stored['oauth_enabled'])->toBeFalse()
            ->and($stored['dynamic_registration_open'])->toBeFalse();
    });

    it('falls back to the defaults for blank names rather than storing empty ones', function (): void {
        $_POST = ['mcp_connector_save' => '1', 'ability_namespace' => '', 'server_route' => '  ', 'required_capability' => ''];

        adminsettingsRun();
        $stored = $this->optionValues['mcp_connector_settings'];

        expect($stored['ability_namespace'])->toBe('wp-mcp')
            ->and($stored['server_route'])->toBe('connector')
            ->and($stored['required_capability'])->toBe('edit_posts');
    });

    it('keeps only post types that are on offer', function (): void {
        $_POST = [
            'mcp_connector_save' => '1',
            'addressable_post_types' => ['post', 'revision', 'does_not_exist', 'PAGE'],
        ];

        adminsettingsRun();

        expect($this->optionValues['mcp_connector_settings']['addressable_post_types'])->toBe(['post', 'page']);
    });

    it('keeps only third-party abilities that were actually offered', function (): void {
        $_POST = [
            'mcp_connector_save' => '1',
            'external_abilities' => ['acme/read-things', 'evil/delete-everything'],
        ];

        adminsettingsRun();

        expect($this->optionValues['mcp_connector_settings']['external_abilities'])->toBe(['acme/read-things']);
    });

    it('survives a scalar where a list is expected', function (): void {
        $_POST = [
            'mcp_connector_save' => '1',
            'enabled_groups' => 'posts',
            'addressable_post_types' => 'post',
            'external_abilities' => 'acme/read-things',
        ];

        adminsettingsRun();
        $stored = $this->optionValues['mcp_connector_settings'];

        expect($stored['enabled_groups'])->toBe(['posts'])
            ->and($stored['addressable_post_types'])->toBe(['post'])
            ->and($stored['external_abilities'])->toBe(['acme/read-things']);
    });

    it('does not trip over an array posted where a string is expected', function (): void {
        $_POST = [
            'mcp_connector_save' => '1',
            'ability_namespace' => ['a'],
            'server_route' => ['b'],
            'required_capability' => ['c'],
            'mcpc_tab' => ['security'],
        ];

        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            adminsettingsRun();
        } finally {
            restore_error_handler();
        }

        expect($warnings)->toBe([]);
    });

    it('stores nothing for a posted array instead of the word array', function (): void {
        $_POST = ['mcp_connector_save' => '1', 'server_route' => ['b'], 'ability_namespace' => ['a']];

        adminsettingsRun();

        expect(($this->optionValues['mcp_connector_settings']['server_route'] ?? ''))->not->toBe('array');
    });

    it('returns to the panel the form was submitted from', function (): void {
        $_POST = ['mcp_connector_save' => '1', 'mcpc_tab' => 'Security'];

        expect(adminsettingsRun())->toContain('tab=security');
    });

    it('ignores a panel name it does not know', function (): void {
        $_POST = ['mcp_connector_save' => '1', 'mcpc_tab' => '"><script>'];

        expect(adminsettingsRun())->not->toContain('tab=')->not->toContain('script');
    });

    it('returns to the assistant, without a notice, when it came from a wizard step', function (): void {
        $_POST = ['mcp_connector_save' => '1', 'mcpc_step' => 'check'];

        expect(adminsettingsRun())->toContain('step=check')->not->toContain('saved=1');
    });
});

describe('saving from the setup assistant', function (): void {
    it('changes two switches and leaves every other setting as stored', function (): void {
        $this->setSettings([
            'ability_namespace' => 'kept',
            'enabled_groups' => ['posts'],
            'addressable_post_types' => ['page'],
            'read_only_mode' => false,
            'dynamic_registration_open' => false,
        ]);
        $_POST = ['mcp_connector_wizard_save' => '1', 'read_only_mode' => '1'];

        adminsettingsRun();
        $stored = $this->optionValues['mcp_connector_settings'];

        expect($this->nonces)->toBe([SettingsPage::NONCE_SETTINGS])
            ->and($stored['read_only_mode'])->toBeTrue()
            ->and($stored['dynamic_registration_open'])->toBeFalse()
            ->and($stored['ability_namespace'])->toBe('kept')
            ->and($stored['enabled_groups'])->toBe(['posts'])
            ->and($stored['addressable_post_types'])->toBe(['page']);
    });
});

describe('creating a client by hand', function (): void {
    it('verifies its own nonce, stores the client and hands the secret over once', function (): void {
        $_POST = [
            'mcp_connector_create_client' => '1',
            'client_name' => '<b>My app</b>',
            'redirect_uris' => "https://app.example/cb\r\nhttps://app.example/other\n\n",
        ];

        $url = adminsettingsRun();
        $stored = $this->optionValues[ClientRepository::OPTION];

        expect($this->nonces)->toBe(['mcp_connector_create_client'])
            ->and($stored)->toHaveKey('client-uuid')
            ->and($stored['client-uuid']['name'])->toBe('My app')
            ->and($this->transientValues)->toHaveKey('mcp_connector_new_secret_client-uuid')
            ->and($url)->toContain('created=client-uuid')
            ->and($url)->not->toContain($this->transientValues['mcp_connector_new_secret_client-uuid']);
    });

    it('refuses a client with no usable redirect URI and stores nothing', function (): void {
        $_POST = ['mcp_connector_create_client' => '1', 'client_name' => 'App', 'redirect_uris' => " \n \n"];

        expect(adminsettingsRun())->toContain('error=redirect_uris')
            ->and($this->optionValues)->not->toHaveKey(ClientRepository::OPTION)
            ->and($this->transientValues)->toBe([]);
    });

    it('names an unnamed client', function (): void {
        $_POST = ['mcp_connector_create_client' => '1', 'redirect_uris' => 'https://app.example/cb'];

        adminsettingsRun();

        expect($this->optionValues[ClientRepository::OPTION]['client-uuid']['name'])->toBe('Manually created client');
    });
});

describe('deleting a client', function (): void {
    it('removes the client and its tokens, and says so', function (): void {
        $this->setOption(ClientRepository::OPTION, [
            'abc' => adminsettingsClient('abc'),
            'keep' => adminsettingsClient('keep'),
        ]);
        $this->setOption(TokenRepository::ACCESS_OPTION, [
            't1' => ['client_id' => 'abc', 'user_id' => 1, 'expires_at' => time() + 100],
            't2' => ['client_id' => 'keep', 'user_id' => 1, 'expires_at' => time() + 100],
        ]);
        $_GET = ['mcp_connector_delete_client' => 'abc'];

        $url = adminsettingsRun();

        expect($this->nonces)->toBe(['mcp_connector_delete_client'])
            ->and(array_keys($this->optionValues[ClientRepository::OPTION]))->toBe(['keep'])
            ->and(array_keys($this->optionValues[TokenRepository::ACCESS_OPTION]))->toBe(['t2'])
            ->and($url)->toContain('deleted=1');
    });

    it('treats an unknown client as a no-op, not an error', function (): void {
        $_GET = ['mcp_connector_delete_client' => 'ghost'];

        expect(adminsettingsRun())->toContain('deleted=1');
    });
});

describe('the first-visit redirect', function (): void {
    it('sends the first visitor to the assistant when nothing is connected', function (): void {
        $_GET = ['page' => 'mcp-connector'];

        expect(adminsettingsRun())->toContain('step=check');
    });

    it('does not when a panel was asked for explicitly', function (): void {
        $_GET = ['page' => 'mcp-connector', 'tab' => 'tools'];

        expect(adminsettingsRun())->toBeNull();
    });

    it('does not on another admin page', function (): void {
        $_GET = ['page' => 'something-else'];

        expect(adminsettingsRun())->toBeNull();
    });

    it('does not once a client holds a live token', function (): void {
        $this->setOption(TokenRepository::ACCESS_OPTION, [
            't1' => ['client_id' => 'abc', 'user_id' => 1, 'expires_at' => time() + 100, 'created_at' => time()],
        ]);
        $_GET = ['page' => 'mcp-connector'];

        expect(adminsettingsRun())->toBeNull();
    });
});

describe('panel selection and URLs', function (): void {
    it('offers six panels', function (): void {
        expect(array_keys(SettingsPage::panels()))->toBe(['dashboard', 'connect', 'tools', 'security', 'applications', 'advanced']);
    });

    it('opens the dashboard unless the URL names a real panel', function (string|array|null $tab, string $expected): void {
        if ($tab !== null) {
            $_GET['tab'] = $tab;
        }

        expect(SettingsPage::activePanel())->toBe($expected);
    })->with([
        'none' => [null, 'dashboard'],
        'known' => ['security', 'security'],
        'uppercase' => ['SECURITY', 'security'],
        'unknown' => ['nope', 'dashboard'],
        'markup' => ['"><script>alert(1)</script>', 'dashboard'],
        'array' => [['security'], 'dashboard'],
    ]);

    it('builds the URL of a panel', function (): void {
        expect(SettingsPage::url())->toBe('https://example.test/wp-admin/options-general.php?page=mcp-connector')
            ->and(SettingsPage::url('tools', ['saved' => '1']))->toContain('tab=tools')->toContain('saved=1');
    });

    it('adds a Settings link to the plugin row', function (): void {
        $links = (new SettingsPage())->addSettingsLink(['<a>Deactivate</a>']);

        expect($links)->toHaveCount(2)
            ->and(implode('', $links))->toContain('page=mcp-connector');
    });
});

describe('rendering the screen', function (): void {
    it('draws all six panels and the nonce', function (): void {
        $html = adminsettingsRender();

        expect($html)->toContain('data-active-panel="dashboard"')
            ->and($html)->toContain('name="_wpnonce"')
            ->and($html)->toContain('id="mcpc-settings"');
    });

    it('opens on the panel named in the URL', function (): void {
        $_GET['tab'] = 'security';

        expect(adminsettingsRender())->toContain('data-active-panel="security"');
    });

    it('never lets a hostile panel name reach the markup', function (): void {
        $_GET['tab'] = '"><script>alert(1)</script>';
        $html = adminsettingsRender();

        expect($html)->toContain('data-active-panel="dashboard"')
            ->and($html)->not->toContain('alert(1)');
    });

    it('escapes a client name and its redirect URIs', function (): void {
        $client = (new Client(
            'abc',
            '',
            '<script>alert("name")</script>',
            ['https://app.example/cb?a=1&b="<img src=x onerror=alert(1)>'],
            'none',
            100,
            false,
        ))->toArray();
        $this->setOption(ClientRepository::OPTION, ['abc' => $client]);

        $html = adminsettingsRender();

        expect($html)->not->toContain('<script>alert("name")')
            ->and($html)->not->toContain('<img src=x')
            ->and($html)->toContain('&lt;script&gt;alert(&quot;name&quot;)&lt;/script&gt;');
    });

    it('links a client\'s removal through a nonce-bearing URL', function (): void {
        $this->setOption(ClientRepository::OPTION, ['abc' => adminsettingsClient('abc')]);

        expect(adminsettingsRender())
            ->toContain('mcp_connector_delete_client=abc')
            ->toContain('_wpnonce=nonce');
    });

    it('shows the stored names, escaped, in the advanced panel', function (): void {
        $this->setSettings(['ability_namespace' => 'my-site', 'server_route' => 'route-one']);

        expect(adminsettingsRender())->toContain('value="my-site"')->toContain('value="route-one"');
    });
});

describe('the notices after an action', function (): void {
    it('says nothing on a plain load', function (): void {
        $html = adminsettingsRender();

        expect($html)->not->toContain('Settings saved.')
            ->and($html)->not->toContain('Application removed')
            ->and($html)->not->toContain('At least one valid redirect URI');
    });

    it('confirms a save, a removal and a refused client', function (string $key, string $message): void {
        $_GET[$key] = '1';

        expect(adminsettingsRender())->toContain($message);
    })->with([
        'saved' => ['saved', 'Settings saved.'],
        'deleted' => ['deleted', 'Application removed and its tokens revoked.'],
        'error' => ['error', 'At least one valid redirect URI is required.'],
    ]);

    it('shows a new client secret once, escaped, and burns it', function (): void {
        $this->setOption(ClientRepository::OPTION, ['abc' => adminsettingsClient('abc')]);
        $this->transientValues['mcp_connector_new_secret_abc'] = 's3cret"><script>x()</script>';
        $_GET['created'] = 'abc';

        $first = adminsettingsRender();
        $second = adminsettingsRender();

        expect($first)->toContain('Application created')
            ->and($first)->toContain('s3cret&quot;&gt;&lt;script&gt;')
            ->and($first)->not->toContain('<script>x()')
            ->and($second)->not->toContain('Application created')
            ->and($this->transientValues)->not->toHaveKey('mcp_connector_new_secret_abc');
    });

    it('shows no secret for a client that does not exist', function (): void {
        $this->transientValues['mcp_connector_new_secret_ghost'] = 'topsecret';
        $_GET['created'] = 'ghost';

        expect(adminsettingsRender())->not->toContain('topsecret');
    });
});

describe('lists posted with nested arrays', function (): void {
    it('keeps the string members of a list and drops anything nested, without a warning', function (): void {
        $_POST = [
            'mcp_connector_save' => '1',
            'enabled_groups' => ['posts', ['nested'], 'media'],
            'addressable_post_types' => [['post'], 'page'],
            'external_abilities' => ['acme/read-things', ['acme/read-things'], 'evil/delete-everything'],
        ];

        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            adminsettingsRun();
        } finally {
            restore_error_handler();
        }

        $stored = $this->optionValues['mcp_connector_settings'];

        expect($warnings)->toBe([])
            ->and($stored['enabled_groups'])->toBe(['posts', 'media'])
            ->and($stored['addressable_post_types'])->toBe(['page'])
            ->and($stored['external_abilities'])->toBe(['acme/read-things']);
    });

    it('treats a list posted as a nested array alone as empty', function (): void {
        $_POST = ['mcp_connector_save' => '1', 'enabled_groups' => [['x']], 'addressable_post_types' => [[]], 'external_abilities' => [['x']]];

        adminsettingsRun();
        $stored = $this->optionValues['mcp_connector_settings'];

        expect($stored['enabled_groups'])->toBe([])
            ->and($stored['addressable_post_types'])->toBe([])
            ->and($stored['external_abilities'])->toBe([]);
    });
});

describe('registering the hooks', function (): void {
    it('attaches the menu, the actions, the assets, the plugin row link and the connection test', function (): void {
        $actions = [];
        $filters = [];
        Functions\when('add_action')->alias(static function (string $hook) use (&$actions): void {
            $actions[] = $hook;
        });
        Functions\when('add_filter')->alias(static function (string $hook) use (&$filters): void {
            $filters[] = $hook;
        });

        (new SettingsPage())->register();

        expect($actions)->toBe(['admin_menu', 'admin_init', 'admin_enqueue_scripts', 'wp_ajax_mcp_connector_test'])
            ->and($filters)->toBe(['plugin_action_links_mcp-connector/mcp-connector.php']);
    });

    it('takes the admin furniture off only on the assistant\'s own screen', function (): void {
        $_GET['page'] = 'mcp-connector-setup';
        $removed = [];
        $filters = [];
        $actions = [];
        Functions\when('add_action')->alias(static function (string $hook, callable $callback) use (&$actions): void {
            $actions[$hook] = $callback;
        });
        Functions\when('add_filter')->alias(static function (string $hook, callable $callback) use (&$filters): void {
            $filters[$hook] = $callback;
        });
        Functions\when('remove_action')->alias(static function (string $hook, string $callback) use (&$removed): void {
            $removed[] = $hook . ':' . $callback;
        });
        Functions\when('remove_all_actions')->alias(static function (string $hook) use (&$removed): void {
            $removed[] = $hook;
        });

        (new SettingsPage())->register();

        expect($removed)->toBe(['in_admin_header:wp_admin_bar_render'])
            ->and($filters['admin_body_class']('folded'))->toContain('folded')->toContain(Wizard::BODY_CLASS);

        $actions['in_admin_header']();

        expect($removed)->toBe([
            'in_admin_header:wp_admin_bar_render',
            'admin_notices',
            'all_admin_notices',
            'user_admin_notices',
            'network_admin_notices',
        ]);
    });

    it('leaves every other screen furnished', function (): void {
        $_GET['page'] = 'mcp-connector';
        $removed = [];
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('remove_action')->alias(static function () use (&$removed): void {
            $removed[] = 'remove_action';
        });

        (new SettingsPage())->register();

        expect($removed)->toBe([]);
    });

    it('adds the settings page and the assistant, then unlists the assistant', function (): void {
        $calls = [];
        Functions\when('add_options_page')->alias(static function (...$args) use (&$calls): string {
            $calls[] = ['options', $args[3]];

            return 'settings_page';
        });
        Functions\when('add_submenu_page')->alias(static function (...$args) use (&$calls): string {
            $calls[] = ['submenu', $args[4]];

            return 'assistant_page';
        });
        Functions\when('remove_submenu_page')->alias(static function (string $parent, string $slug) use (&$calls): void {
            $calls[] = ['remove', $slug];
        });

        (new SettingsPage())->addMenuPage();

        expect($calls)->toBe([
            ['options', SettingsPage::MENU_SLUG],
            ['submenu', Wizard::MENU_SLUG],
            ['remove', Wizard::MENU_SLUG],
        ]);
    });
});

describe('loading the assets', function (): void {
    it('loads them on both screens of the plugin', function (string $hookSuffix): void {
        $enqueued = [];
        $localised = null;
        Functions\when('wp_enqueue_style')->alias(static function (string $handle, string $src) use (&$enqueued): void {
            $enqueued[] = ['style', $src];
        });
        Functions\when('wp_enqueue_script')->alias(static function (string $handle, string $src) use (&$enqueued): void {
            $enqueued[] = ['script', $src];
        });
        Functions\when('wp_localize_script')->alias(static function (string $handle, string $name, array $data) use (&$localised): void {
            $localised = [$handle, $name, $data];
        });

        (new SettingsPage())->enqueueAssets($hookSuffix);

        expect($enqueued)->toBe([
            ['style', 'https://example.test/plugin/assets/admin.css'],
            ['script', 'https://example.test/plugin/assets/admin.js'],
        ])->and($localised[1])->toBe('mcpConnectorAdmin')
            ->and($localised[2]['testAction'])->toBe('mcp_connector_test')
            ->and($localised[2]['testNonce'])->toBe('nonce')
            ->and($localised[2]['ajaxUrl'])->toBe('https://example.test/wp-admin/admin-ajax.php')
            ->and($localised[2]['panelsWithoutSettings'])->toBe('dashboard,connect,applications');
    })->with(['settings' => ['settings_page_mcp-connector'], 'assistant' => ['settings_page_mcp-connector-setup']]);

    it('loads nothing on any other admin page', function (): void {
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_localize_script')->never();

        (new SettingsPage())->enqueueAssets('edit.php');
        (new SettingsPage())->enqueueAssets('settings_page_other');
    });
});

describe('the Tools panel', function (): void {
    /**
     * @param array<string, mixed> $meta
     */
    function adminsettingsAbility(string $name, array $meta): WP_Ability
    {
        return new WP_Ability($name, 'Label ' . $name, 'Does ' . $name, 'cat', ['mcp' => ['public' => true]] + $meta);
    }

    it('says so when no other plugin publishes abilities', function (): void {
        Functions\when('wp_get_abilities')->justReturn([]);

        expect(adminsettingsRender())->toContain('No other active plugin publishes abilities for MCP.');
    });

    it('groups a provider\'s abilities by what it declares about them, escaping their text', function (): void {
        Functions\when('wp_get_abilities')->justReturn([
            adminsettingsAbility('acme/read', ['annotations' => ['readonly' => true]]),
            adminsettingsAbility('acme/write', ['annotations' => ['readonly' => false]]),
            adminsettingsAbility('acme/delete', ['annotations' => ['readonly' => false, 'destructive' => true]]),
            adminsettingsAbility('acme/silent', []),
            new WP_Ability('acme/xss', '<script>alert(1)</script>', 'd', 'cat', ['mcp' => ['public' => true]]),
        ]);

        $html = adminsettingsRender();

        expect($html)->toContain('Reads only')
            ->and($html)->toContain('Writes, and may delete or overwrite')
            ->and($html)->toContain('Undeclared')
            ->and($html)->toContain('5 abilities offered')
            ->and($html)->toContain('These are the recommended defaults, in force until you save this screen.')
            ->and($html)->not->toContain('<script>alert(1)</script>');
    });

    it('stops saying the defaults are in force once the list has been reviewed', function (): void {
        $this->setSettings(['external_abilities_reviewed' => true, 'external_abilities' => []]);

        expect(adminsettingsRender())->not->toContain('These are the recommended defaults');
    });
});

describe('rendering the clients and the assistant', function (): void {
    it('badges a self-registered client and dates its last token', function (): void {
        $client = (new Client('abc', '', 'Self', [], 'none', 100, true))->toArray();
        $this->setOption(ClientRepository::OPTION, ['abc' => $client]);

        expect(adminsettingsRender())->toContain('self-registered')->toContain('never');
    });

    it('draws the assistant through the page callback', function (): void {
        Functions\when('add_query_arg')->alias(static function (mixed ...$arguments): string {
            [$args, $url] = is_array($arguments[0])
                ? [$arguments[0], (string) $arguments[1]]
                : [[(string) $arguments[0] => $arguments[1]], (string) $arguments[2]];

            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        });

        ob_start();
        (new SettingsPage())->renderSetup();
        $html = (string) ob_get_clean();

        expect($html)->toContain('mcpc-setup')->toContain('Step 1 of 4');
    });
});

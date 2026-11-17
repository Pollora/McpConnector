<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Admin\SettingsPage;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\TokenRepository;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/adminsmall-stubs.php';
require_once __DIR__ . '/../../Fixtures/mcp-adapter.php';

function settingsgoldenRender(): string
{
    ob_start();

    try {
        (new SettingsPage())->render();
    } finally {
        $html = (string) ob_get_clean();
    }

    // The version belongs to the release, not to the markup.
    return str_replace(\Pollora\McpConnector\VERSION, 'VERSION', $html);
}

beforeEach(function (): void {
    // Clears what Diagnostics and the ability registry memoise across tests.
    adminsmallPrepare();
    $_POST = [];
    $_GET = [];
    Settings::forget();

    Functions\when('wp_register_ability')->justReturn(true);
    Functions\when('wp_register_ability_category')->justReturn(true);
    Functions\when('current_user_can')->justReturn(true);
    $this->setOption('permalink_structure', '/%postname%/');
    Functions\when('add_query_arg')->alias(
        static fn (array $args, string $url): string => $args === [] ? $url : $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args),
    );
    Functions\when('get_post_types')->alias(static fn (array $args = [], string $output = 'names'): array => $output === 'objects'
        ? ['post' => new WP_Post_Type('post', 'Post'), 'page' => new WP_Post_Type('page', 'Page'), 'book' => new WP_Post_Type('book', 'Book')]
        : ['post' => 'post', 'page' => 'page', 'book' => 'book']);
    Functions\when('wp_roles')->justReturn((object) ['roles' => [
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['edit_posts' => true, 'manage_options' => true]],
        'editor' => ['name' => 'Editor', 'capabilities' => ['edit_posts' => true]],
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
    Functions\when('get_userdata')->alias(static fn (int $id) => (object) ['display_name' => 'User ' . $id]);
    Functions\when('wp_remote_get')->justReturn([]);
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('{}');
    Functions\when('wp_remote_retrieve_header')->justReturn('');
    Functions\when('wp_get_abilities')->alias(static fn (): array => [
        new WP_Ability('acme/read', 'Read things', 'Reads <b>things</b>', 'acme', ['mcp' => ['public' => true], 'annotations' => ['readonly' => true]]),
        new WP_Ability('acme/write', 'Write things', 'Writes', 'acme', ['mcp' => ['public' => true], 'annotations' => ['readonly' => false]]),
        new WP_Ability('acme/delete', 'Delete things', 'Deletes', 'acme', ['mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => true]]),
        new WP_Ability('acme/quiet', 'Quiet', 'Says nothing', 'acme', ['mcp' => ['public' => true]]),
    ]);
});

afterEach(function (): void {
    $_GET = [];
    Settings::forget();
});

describe('the settings screen, whole', function (): void {
    it('renders the untouched screen', function (): void {
        mcpcAssertGolden('settings-default', settingsgoldenRender());
    });

    it('renders a configured site with clients, live tokens and notices', function (): void {
        $this->setSettings([
            'ability_namespace' => 'my-site',
            'server_route' => 'route-one',
            'required_capability' => 'edit_posts',
            'enabled_groups' => ['posts', 'media'],
            'read_only_mode' => true,
            'oauth_enabled' => true,
            'dynamic_registration_open' => true,
            'external_abilities_reviewed' => true,
            'external_abilities' => ['acme/read', 'acme/write'],
            'addressable_post_types' => ['post'],
            'post_types_reviewed' => true,
        ]);
        $this->setOption(ClientRepository::OPTION, [
            'one' => (new Client('one', '', 'First app', ['https://one.test/cb', 'https://one.test/other'], 'none', 100, false))->toArray(),
            'two' => (new Client('two', '', 'Second <app>', ['https://two.test/cb'], 'none', 1700000000, true))->toArray(),
        ]);
        $this->setOption(TokenRepository::ACCESS_OPTION, [
            'h1' => ['client_id' => 'one', 'user_id' => 1, 'created_at' => 1700000000, 'expires_at' => 4102444800],
            'h2' => ['client_id' => 'one', 'user_id' => 2, 'created_at' => 1700003600, 'expires_at' => 4102448400],
            'h3' => ['client_id' => 'one', 'user_id' => 2, 'created_at' => 1700000100, 'expires_at' => 4102444900],
        ]);
        $this->transientValues['mcp_connector_new_secret_one'] = 's3cret';
        $_GET = ['saved' => '1', 'deleted' => '1', 'error' => '1', 'created' => 'one', 'tab' => 'applications'];

        mcpcAssertGolden('settings-configured', settingsgoldenRender());
    });

    it('renders a site with the defaults reviewed and nothing on offer', function (): void {
        Functions\when('wp_get_abilities')->justReturn([]);
        $this->setSettings([
            'enabled_groups' => [],
            'oauth_enabled' => false,
            'external_abilities_reviewed' => true,
            'post_types_reviewed' => true,
            'addressable_post_types' => [],
        ]);

        mcpcAssertGolden('settings-empty', settingsgoldenRender());
    });
});

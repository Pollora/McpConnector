<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Admin\SettingsPage;
use Pollora\McpConnector\Admin\Wizard;
use Pollora\McpConnector\Settings;

final class SettingsdetailsRedirected extends RuntimeException
{
}

function settingsdetailsRun(): ?string
{
    try {
        (new SettingsPage())->handleActions();
    } catch (SettingsdetailsRedirected $redirect) {
        return $redirect->getMessage();
    }

    return null;
}

beforeEach(function (): void {
    $_POST = [];
    $_GET = [];
    Settings::forget();

    Functions\when('current_user_can')->justReturn(true);
    Functions\when('check_admin_referer')->justReturn(1);
    Functions\when('wp_safe_redirect')->alias(static function (string $url): never {
        throw new SettingsdetailsRedirected($url);
    });
    Functions\when('add_query_arg')->alias(
        static fn (array $args, string $url): string => $args === [] ? $url : $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args),
    );
    Functions\when('get_post_types')->alias(static fn (array $args = [], string $output = 'names'): array => $output === 'objects'
        ? ['post' => new WP_Post_Type('post', 'Post'), 'page' => new WP_Post_Type('page', 'Page'), 'book' => new WP_Post_Type('book', 'Book')]
        : ['post' => 'post', 'page' => 'page', 'book' => 'book']);
    Functions\when('wp_get_abilities')->alias(static fn (): array => [
        new WP_Ability('acme/a', 'A', 'a', 'acme', ['mcp' => ['public' => true]]),
        new WP_Ability('acme/b', 'B', 'b', 'acme', ['mcp' => ['public' => true]]),
        new WP_Ability('acme/c', 'C', 'c', 'acme', ['mcp' => ['public' => true]]),
    ]);
    Functions\when('wp_generate_uuid4')->justReturn('client-uuid');
    Functions\when('wp_hash_password')->alias(static fn (string $p): string => 'hashed:' . $p);
});

afterEach(function (): void {
    $_POST = [];
    $_GET = [];
    Settings::forget();
});

describe('what the hooks are given', function (): void {
    it('unhooks the admin bar at priority 0 and notices at priority 1 on the assistant', function (): void {
        $_GET['page'] = 'mcp-connector-setup';
        $calls = [];
        Functions\when('add_action')->alias(static function (string $hook, callable $cb, int $priority = 10) use (&$calls): void {
            $calls['add_action:' . $hook] = $priority;
        });
        Functions\when('add_filter')->alias(static function (string $hook, callable $cb, int $priority = 10) use (&$calls): void {
            $calls['add_filter:' . $hook] = [$priority, $cb];
        });
        Functions\when('remove_action')->alias(static function (string $hook, string $cb, int $priority = 10) use (&$calls): void {
            $calls['remove_action:' . $hook] = [$cb, $priority];
        });

        (new SettingsPage())->register();

        expect($calls['remove_action:in_admin_header'])->toBe(['wp_admin_bar_render', 0])
            ->and($calls['add_action:in_admin_header'])->toBe(1)
            ->and($calls['add_filter:admin_body_class'][0])->toBe(10)
            ->and($calls['add_filter:admin_body_class'][1]('folded'))->toBe('folded ' . Wizard::BODY_CLASS . ' ');
    });

    it('enqueues the stylesheet and script with the plugin version, the script in the footer', function (): void {
        $calls = [];
        Functions\when('wp_enqueue_style')->alias(static function (...$args) use (&$calls): void {
            $calls['style'] = $args;
        });
        Functions\when('wp_enqueue_script')->alias(static function (...$args) use (&$calls): void {
            $calls['script'] = $args;
        });
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_localize_script')->alias(static function (...$args) use (&$calls): void {
            $calls['localize'] = $args;
        });

        (new SettingsPage())->enqueueAssets('settings_page_mcp-connector');

        expect($calls['style'])->toBe(['mcp-connector-admin', 'https://example.test/plugin/assets/admin.css', [], \Pollora\McpConnector\VERSION])
            ->and($calls['script'])->toBe(['mcp-connector-admin', 'https://example.test/plugin/assets/admin.js', [], \Pollora\McpConnector\VERSION, true])
            ->and($calls['localize'][0])->toBe('mcp-connector-admin')
            ->and($calls['localize'][2])->toBe([
                'ajaxUrl' => 'https://example.test/wp-admin/admin-ajax.php',
                'testAction' => 'mcp_connector_test',
                'testNonce' => 'nonce',
                'panelsWithoutSettings' => 'dashboard,connect,applications',
                'testing' => 'Running the whole chain…',
                'testFailed' => 'The test could not be completed.',
                'copied' => 'Copied',
                'copyFailed' => 'Press Ctrl+C',
            ]);
    });
});

describe('what an action stores and redirects to', function (): void {
    it('stores the lists reindexed, whatever gaps the filtering leaves', function (): void {
        $_POST = [
            'mcp_connector_save' => '1',
            'enabled_groups' => [3 => 'posts', 7 => 'media'],
            'addressable_post_types' => ['post', 'bogus', 'page'],
            'external_abilities' => ['acme/a', 'evil/x', 'acme/c'],
        ];

        settingsdetailsRun();
        $stored = $this->optionValues['mcp_connector_settings'];

        expect(array_is_list($stored['enabled_groups']))->toBeTrue()
            ->and($stored['enabled_groups'])->toBe(['posts', 'media'])
            ->and($stored['addressable_post_types'])->toBe(['post', 'page'])
            ->and($stored['external_abilities'])->toBe(['acme/a', 'acme/c']);
    });

    it('redirects a saved wizard switch to the settings screen with a saved notice', function (): void {
        $_POST = ['mcp_connector_wizard_save' => '1'];

        expect(settingsdetailsRun())->toBe(SettingsPage::url() . '&saved=1');
    });

    it('redirects a refused client with the error flag and the panel', function (): void {
        $_POST = ['mcp_connector_create_client' => '1', 'redirect_uris' => '   ', 'mcpc_tab' => 'applications'];

        expect(settingsdetailsRun())->toBe(SettingsPage::url() . '&error=redirect_uris&tab=applications');
    });

    it('holds a new secret for five minutes, trims and reindexes the redirect URIs', function (): void {
        Functions\when('esc_url_raw')->alias(static fn ($url) => (string) $url);
        $ttl = null;
        Functions\when('set_transient')->alias(function (string $name, mixed $value, int $expiration) use (&$ttl): bool {
            $ttl = $expiration;
            $this->transientValues[$name] = $value;

            return true;
        });
        $_POST = [
            'mcp_connector_create_client' => '1',
            'client_name' => 'App',
            'redirect_uris' => "\n  https://a.test/cb  \n\nhttps://b.test/cb",
        ];

        settingsdetailsRun();
        $stored = $this->optionValues[Pollora\McpConnector\OAuth\ClientRepository::OPTION]['client-uuid'];

        expect($ttl)->toBe(300)
            ->and($stored['redirect_uris'])->toBe(['https://a.test/cb', 'https://b.test/cb']);
    });
});

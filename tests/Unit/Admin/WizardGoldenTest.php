<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Admin\SettingsPage;
use Pollora\McpConnector\Admin\Wizard;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/adminsmall-stubs.php';
require_once __DIR__ . '/../../Fixtures/mcp-adapter.php';

beforeEach(function (): void {
    adminsmallPrepare();
    $_GET = [];

    // The Abilities API is probed for, not called: declare it present so the
    // preparation step does not depend on what another test file defined.
    Functions\when('wp_register_ability')->justReturn(true);
    Functions\when('wp_register_ability_category')->justReturn(true);
    Functions\when('wp_roles')->justReturn((object) ['roles' => [
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['edit_posts' => true]],
    ]]);
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('wp_nonce_url')->alias(static fn (string $url, string $action): string => $url . '&_wpnonce=nonce-' . $action);
    Functions\when('wp_verify_nonce')->alias(static fn (string $nonce, string $action): int|false => $nonce === 'nonce-' . $action ? 1 : false);
    $this->setOption('permalink_structure', '/%postname%/');
    $this->transientValues['mcp_connector_discovery_probe'] = [
        'https://example.test/.well-known/oauth-protected-resource' => 200,
        'https://example.test/.well-known/oauth-authorization-server' => 200,
    ];
});

afterEach(function (): void {
    $_GET = [];
});

/**
 * @param array<string, mixed> $query
 * @param array<string, mixed> $settings
 * @param list<Client>         $clients
 * @param array<string, array{count: int, users: list<int>, issued: int, expires: int}> $tokens
 */
function wizardgoldenRender(array $query = [], array $settings = [], array $clients = [], array $tokens = []): string
{
    $_GET = $query;

    return adminsmallCapture(fn () => (new Wizard(Settings::fromArray($settings), $clients, $tokens))->render());
}

describe('the assistant, whole', function (): void {
    it('renders the preparation step on a ready site', function (): void {
        mcpcAssertGolden('wizard-check-ready', wizardgoldenRender());
    });

    it('renders the preparation step on a blocked site', function (): void {
        $this->setOption('permalink_structure', '');

        mcpcAssertGolden('wizard-check-blocked', wizardgoldenRender());
    });

    it('renders the preparation step after a fresh probe', function (): void {
        Functions\when('wp_remote_get')->justReturn(['code' => 200]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        mcpcAssertGolden('wizard-check-probed', wizardgoldenRender(['probe' => '1', '_wpnonce' => 'nonce-' . SettingsPage::NONCE_PROBE]));
    });

    it('renders the reach step', function (): void {
        mcpcAssertGolden('wizard-expose', wizardgoldenRender(['step' => 'expose'], ['read_only_mode' => true, 'required_capability' => 'edit_pages']));
    });

    it('renders the URL step with and without waiting applications', function (): void {
        $client = new Client('c1', 'h', 'App', ['https://a.test/cb'], 'none', 0, true);

        mcpcAssertGolden('wizard-connect', wizardgoldenRender(['step' => 'connect'], [], [$client, $client]));
    });

    it('renders the verification step before and after a token', function (): void {
        mcpcAssertGolden('wizard-verify-waiting', wizardgoldenRender(['step' => 'verify']));
        mcpcAssertGolden('wizard-verify-connected', wizardgoldenRender(
            ['step' => 'verify'],
            [],
            [],
            ['c1' => ['count' => 1, 'users' => [1], 'issued' => 0, 'expires' => 0]],
        ));
    });

    it('renders the progress rail with a live token on an early step', function (): void {
        mcpcAssertGolden('wizard-rail-live', wizardgoldenRender(
            ['step' => 'connect'],
            [],
            [],
            ['c1' => ['count' => 2, 'users' => [1], 'issued' => 0, 'expires' => 0]],
        ));
    });
});

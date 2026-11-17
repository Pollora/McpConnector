<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Abilities\AbilityRegistry;
use Pollora\McpConnector\Admin\Diagnostics;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/adminsmall-stubs.php';
require_once __DIR__ . '/../../Fixtures/mcp-adapter.php';

const DIAGGOLDEN_KEY = 'mcp_connector_discovery_probe';
const DIAGGOLDEN_RESOURCE = 'https://example.test/.well-known/oauth-protected-resource';
const DIAGGOLDEN_SERVER = 'https://example.test/.well-known/oauth-authorization-server';

beforeEach(function (): void {
    adminsmallPrepare();
    Functions\when('wp_roles')->justReturn((object) ['roles' => [
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['edit_posts' => true, 'manage_options' => true]],
        'nameless' => ['capabilities' => ['edit_posts' => true]],
        'subscriber' => ['name' => 'Subscriber', 'capabilities' => ['read' => true, 'edit_posts' => false]],
    ]]);
    Functions\when('current_user_can')->justReturn(true);
    $this->setOption('permalink_structure', '/%postname%/');
    $this->transientValues[DIAGGOLDEN_KEY] = [DIAGGOLDEN_RESOURCE => 200, DIAGGOLDEN_SERVER => 200];
});

/**
 * Every check except the Abilities API one, which depends on what other test
 * files left defined in the process, as pretty JSON.
 *
 * @param array<string, mixed> $settings
 */
function diaggoldenChecks(array $settings = []): string
{
    $checks = array_values(array_filter(
        Diagnostics::all(Settings::fromArray($settings)),
        static fn (array $check): bool => $check['id'] !== 'abilities-api',
    ));

    return (string) json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

describe('the diagnostics, whole', function (): void {
    it('reports a healthy site', function (): void {
        mcpcAssertGolden('diagnostics-healthy', diaggoldenChecks());
    });

    it('reports plain permalinks over HTTP with a capability nobody holds', function (): void {
        $this->setOption('permalink_structure', '');
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'http://example.test/' . ltrim($path, '/'));

        mcpcAssertGolden('diagnostics-broken', diaggoldenChecks(['required_capability' => 'do_nothing', 'enabled_groups' => []]));
    });

    it('reports a reader without the capability, a blocked discovery document and a registered ability', function (): void {
        Functions\when('current_user_can')->justReturn(false);
        $this->transientValues[DIAGGOLDEN_KEY] = [DIAGGOLDEN_RESOURCE => 200, DIAGGOLDEN_SERVER => 403];
        (new ReflectionProperty(AbilityRegistry::class, 'registered'))->setValue(null, ['wp-mcp/a']);

        mcpcAssertGolden('diagnostics-blocked', diaggoldenChecks());
    });

    it('names the first of several unreachable documents', function (): void {
        $this->transientValues[DIAGGOLDEN_KEY] = [DIAGGOLDEN_RESOURCE => 0, DIAGGOLDEN_SERVER => 0];

        mcpcAssertGolden('diagnostics-unreachable', diaggoldenChecks());
    });

    it('names the first of several refused documents', function (): void {
        $this->transientValues[DIAGGOLDEN_KEY] = [DIAGGOLDEN_RESOURCE => 404, DIAGGOLDEN_SERVER => 500];

        mcpcAssertGolden('diagnostics-refused', diaggoldenChecks());
    });

    it('reports the provider off and the groups counted', function (): void {
        mcpcAssertGolden('diagnostics-oauth-off', diaggoldenChecks(['oauth_enabled' => false, 'enabled_groups' => ['content', 'media']]));
    });
});

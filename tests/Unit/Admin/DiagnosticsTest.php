<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Admin\Diagnostics;
use Pollora\McpConnector\Settings;
use Pollora\McpConnector\Support\Environment;

require_once __DIR__ . '/../../Fixtures/adminsmall-stubs.php';
require_once __DIR__ . '/../../Fixtures/mcp-adapter.php';

const ADMINSMALL_PROBE_KEY = 'mcp_connector_discovery_probe';
const ADMINSMALL_RESOURCE_URL = 'https://example.test/.well-known/oauth-protected-resource';
const ADMINSMALL_SERVER_URL = 'https://example.test/.well-known/oauth-authorization-server';

beforeEach(function (): void {
    adminsmallPrepare();
    Functions\when('wp_roles')->justReturn((object) ['roles' => [
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['edit_posts' => true, 'manage_options' => true]],
        'subscriber' => ['name' => 'Subscriber', 'capabilities' => ['read' => true, 'edit_posts' => false]],
    ]]);
    Functions\when('current_user_can')->justReturn(true);
    $this->setOption('permalink_structure', '/%postname%/');
    $this->transientValues[ADMINSMALL_PROBE_KEY] = [ADMINSMALL_RESOURCE_URL => 200, ADMINSMALL_SERVER_URL => 200];
});

/**
 * @return array<string, array<string, mixed>> Results keyed by check id.
 */
function adminsmallChecks(?Settings $settings = null, bool $probe = false): array
{
    $results = [];

    foreach (Diagnostics::all($settings ?? Settings::fromArray([]), $probe) as $check) {
        $results[$check['id']] = $check;
    }

    return $results;
}

describe('the dependency checks', function (): void {
    it('report the Abilities API as whatever the environment really offers', function (): void {
        $check = adminsmallChecks()['abilities-api'];
        $available = Environment::hasAbilitiesApi();

        expect($check['status'])->toBe($available ? Diagnostics::PASS : Diagnostics::FAIL)
            ->and($check['detail'])->toStartWith($available ? 'Available' : 'Unavailable');
    });

    it('pass the MCP Adapter when its entry point is loaded', function (): void {
        $check = adminsmallChecks()['mcp-adapter'];

        expect($check['status'])->toBe(Diagnostics::PASS)
            ->and($check)->not->toHaveKey('fix');
    });
});

describe('the permalink and HTTPS checks', function (): void {
    it('pass with a permalink structure', function (): void {
        expect(adminsmallChecks()['permalinks']['status'])->toBe(Diagnostics::PASS);
    });

    it('fail under plain permalinks and name the screen to change', function (): void {
        $this->setOption('permalink_structure', '');

        $check = adminsmallChecks()['permalinks'];

        expect($check['status'])->toBe(Diagnostics::FAIL)
            ->and($check['detail'])->toContain('options-permalink.php');
    });

    it('pass over TLS and only warn over plain HTTP', function (): void {
        expect(adminsmallChecks()['https']['status'])->toBe(Diagnostics::PASS);

        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'http://example.test/' . ltrim($path, '/'));
        $reflection = new ReflectionProperty(Diagnostics::class, 'memo');
        $reflection->setValue(null, []);

        expect(adminsmallChecks()['https']['status'])->toBe(Diagnostics::WARN);
    });
});

describe('the capability check', function (): void {
    it('passes when the reader holds the capability, and lists the roles that grant it', function (): void {
        $check = adminsmallChecks()['capability'];

        expect($check['status'])->toBe(Diagnostics::PASS)
            ->and($check['detail'])->toContain('edit_posts')->toContain('Administrator')
            ->and($check['detail'])->not->toContain('Subscriber');
    });

    it('only warns when roles grant it but the reader does not hold it', function (): void {
        Functions\when('current_user_can')->justReturn(false);

        $check = adminsmallChecks()['capability'];

        expect($check['status'])->toBe(Diagnostics::WARN)
            ->and($check['detail'])->toContain('You do not hold');
    });

    it('fails when no role grants the configured capability', function (): void {
        $check = adminsmallChecks(Settings::fromArray(['required_capability' => 'do_nothing']))['capability'];

        expect($check['status'])->toBe(Diagnostics::FAIL)
            ->and($check['detail'])->toContain('do_nothing');
    });
});

describe('the discovery check', function (): void {
    it('passes when both documents answer 200', function (): void {
        expect(adminsmallChecks()['discovery']['status'])->toBe(Diagnostics::PASS);
    });

    it('fails on a 403 and states the nginx lines to swap', function (): void {
        $this->transientValues[ADMINSMALL_PROBE_KEY][ADMINSMALL_SERVER_URL] = 403;

        $check = adminsmallChecks()['discovery'];

        expect($check['status'])->toBe(Diagnostics::FAIL)
            ->and($check['detail'])->toContain('403')->toContain(ADMINSMALL_SERVER_URL)
            ->and(array_column($check['fix']['lines'], 'kind'))->toBe(['wrong', 'right']);
    });

    it('only warns when the site cannot reach itself', function (): void {
        $this->transientValues[ADMINSMALL_PROBE_KEY][ADMINSMALL_RESOURCE_URL] = 0;

        $check = adminsmallChecks()['discovery'];

        expect($check['status'])->toBe(Diagnostics::WARN)
            ->and($check)->not->toHaveKey('fix');
    });

    it('re-requests both documents on a fresh probe and records the answer', function (): void {
        $requested = [];
        Functions\when('wp_remote_get')->alias(function (string $url) use (&$requested) {
            $requested[] = $url;

            return $url === ADMINSMALL_SERVER_URL ? new WP_Error('http_request_failed', 'no route') : ['code' => 200];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $response): int => $response['code']);

        $check = adminsmallChecks(null, true)['discovery'];

        expect($requested)->toBe([ADMINSMALL_RESOURCE_URL, ADMINSMALL_SERVER_URL])
            ->and($check['status'])->toBe(Diagnostics::WARN)
            ->and($this->transientValues[ADMINSMALL_PROBE_KEY])->toBe([ADMINSMALL_RESOURCE_URL => 200, ADMINSMALL_SERVER_URL => 0]);
    });

    it('is replaced by a warning, and not probed, when the provider is off', function (): void {
        Functions\expect('wp_remote_get')->never();
        $this->transientValues = [];

        $checks = adminsmallChecks(Settings::fromArray(['oauth_enabled' => false]), true);

        expect($checks)->not->toHaveKey('discovery')
            ->and($checks['oauth']['status'])->toBe(Diagnostics::WARN);
    });
});

describe('the published tools check', function (): void {
    it('warns when no group and no third-party ability is enabled', function (): void {
        $check = adminsmallChecks(Settings::fromArray(['enabled_groups' => []]))['tools'];

        expect($check['status'])->toBe(Diagnostics::WARN);
    });

    it('passes with a selected third-party ability even without any group', function (): void {
        $check = adminsmallChecks(Settings::fromArray(['enabled_groups' => [], 'external_abilities' => ['acme/thing']]))['tools'];

        expect($check['status'])->toBe(Diagnostics::PASS);
    });

    it('counts the enabled groups when nothing is registered yet', function (): void {
        $check = adminsmallChecks(Settings::fromArray(['enabled_groups' => ['content']]))['tools'];

        expect($check['status'])->toBe(Diagnostics::PASS)
            ->and($check['detail'])->toStartWith('1 ability group enabled');
    });

    it('reports the live count once abilities are registered', function (): void {
        (new ReflectionProperty(Pollora\McpConnector\Abilities\AbilityRegistry::class, 'registered'))
            ->setValue(null, ['wp-mcp/a', 'wp-mcp/b']);

        expect(adminsmallChecks()['tools']['detail'])->toBe('2 abilities registered in this request.');
    });
});

describe('the aggregate status', function (): void {
    it('is the worst status among the individual checks', function (): void {
        $settings = Settings::fromArray(['oauth_enabled' => false]);
        $statuses = array_column(Diagnostics::all($settings), 'status');

        $expected = in_array(Diagnostics::FAIL, $statuses, true)
            ? Diagnostics::FAIL
            : (in_array(Diagnostics::WARN, $statuses, true) ? Diagnostics::WARN : Diagnostics::PASS);

        expect($statuses)->toContain(Diagnostics::WARN)
            ->and(Diagnostics::worst($settings))->toBe($expected)
            ->and(Diagnostics::blocked($settings))->toBe($expected === Diagnostics::FAIL);
    });

    it('is fail, and blocks, as soon as one check fails — even beside warnings', function (): void {
        $this->setOption('permalink_structure', '');

        expect(Diagnostics::worst(Settings::fromArray(['oauth_enabled' => false])))->toBe(Diagnostics::FAIL)
            ->and(Diagnostics::blocked(Settings::fromArray([])))->toBeTrue();
    });

    it('answers a cached reading from a fresh probe without asking twice', function (): void {
        Functions\expect('wp_remote_get')->twice()->andReturn(['code' => 200]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        $first = Diagnostics::all(Settings::fromArray([]), true);
        $second = Diagnostics::all(Settings::fromArray([]));

        expect($second)->toBe($first);
    });
});

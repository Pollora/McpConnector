<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Support\Environment;

describe('the discovery probe', function (): void {
    it('trusts a cached answer without making a request', function (): void {
        $cached = ['https://example.test/a' => 200, 'https://example.test/b' => 200];
        Functions\when('get_transient')->justReturn($cached);
        Functions\expect('wp_remote_get')->never();

        expect(Environment::discoveryDocumentStatuses())->toBe($cached)
            ->and(Environment::servesDiscoveryDocuments())->toBeTrue();
    });

    it('reports a failure when either document is not served with 200', function (array $statuses, bool $served): void {
        Functions\when('get_transient')->justReturn($statuses);

        expect(Environment::servesDiscoveryDocuments())->toBe($served);
    })->with([
        'one forbidden' => [['a' => 200, 'b' => 403], false],
        'transport failure' => [['a' => 0, 'b' => 200], false],
        'both missing' => [['a' => 404, 'b' => 404], false],
        'nothing probed' => [[], false],
    ]);

    it('probes both documents afresh, records transport failures as 0, and caches the result', function (): void {
        $requested = [];
        $stored = null;
        Functions\when('get_transient')->justReturn(['stale' => 200]);
        Functions\when('is_wp_error')->alias(static fn ($v): bool => $v === 'error');
        Functions\when('wp_remote_get')->alias(function (string $url) use (&$requested) {
            $requested[] = $url;

            return count($requested) === 1 ? 'error' : ['response' => ['code' => 403]];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(403);
        Functions\when('set_transient')->alias(function (string $key, array $value) use (&$stored): bool {
            $stored = [$key, $value];

            return true;
        });

        $statuses = Environment::discoveryDocumentStatuses(true);

        expect($requested)->toHaveCount(2)
            ->and(array_values($statuses))->toBe([0, 403])
            ->and($stored[0])->toBe('mcp_connector_discovery_probe')
            ->and($stored[1])->toBe($statuses);
    });
});

describe('the unmet requirements', function (): void {
    it('flags disabled pretty permalinks, and stops flagging them once a structure is set', function (): void {
        Functions\when('get_option')->justReturn('');
        expect(Environment::unmetRequirements())->toHaveKey('permalinks');

        Functions\when('get_option')->justReturn('/%postname%/');
        expect(Environment::unmetRequirements())->not->toHaveKey('permalinks');
    });

    it('flags the MCP Adapter exactly when it is not loaded', function (): void {
        Functions\when('get_option')->justReturn('/%postname%/');

        expect(array_key_exists('mcp-adapter', Environment::unmetRequirements()))
            ->toBe(! Environment::hasMcpAdapter());
    });
});

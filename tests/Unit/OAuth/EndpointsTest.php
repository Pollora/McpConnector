<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\Endpoints;

beforeEach(function (): void {
    Functions\when('untrailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\'));
});

describe('the OAuth endpoint addresses', function (): void {
    it('puts the two metadata documents and the authorization endpoint on the site root', function (): void {
        expect(Endpoints::protectedResourceMetadata())->toBe('https://example.test/.well-known/oauth-protected-resource')
            ->and(Endpoints::serverMetadata())->toBe('https://example.test/.well-known/oauth-authorization-server')
            ->and(Endpoints::authorize())->toBe('https://example.test/oauth/authorize');
    });

    it('puts the machine-facing endpoints in the REST API, under the plugin namespace', function (): void {
        expect(Endpoints::token())->toBe('https://example.test/wp-json/mcp-connector/v1/token')
            ->and(Endpoints::register())->toBe('https://example.test/wp-json/mcp-connector/v1/register')
            ->and(Endpoints::revoke())->toBe('https://example.test/wp-json/mcp-connector/v1/revoke');
    });

    it('builds every address from the constants the router matches on', function (): void {
        expect(Endpoints::protectedResourceMetadata())->toEndWith('/' . Endpoints::PROTECTED_RESOURCE_PATH)
            ->and(Endpoints::serverMetadata())->toEndWith('/' . Endpoints::SERVER_METADATA_PATH)
            ->and(Endpoints::authorize())->toEndWith('/' . Endpoints::AUTHORIZE_PATH)
            ->and(Endpoints::token())->toContain(Endpoints::REST_NAMESPACE . '/token');
    });

    it('keeps the well-known paths free of a leading slash, as the router compares them', function (): void {
        expect(Endpoints::PROTECTED_RESOURCE_PATH)->not->toStartWith('/')
            ->and(Endpoints::SERVER_METADATA_PATH)->not->toStartWith('/')
            ->and(Endpoints::AUTHORIZE_PATH)->not->toStartWith('/');
    });
});

describe('the issuer', function (): void {
    it('is the home URL without its trailing slash', function (): void {
        Functions\when('home_url')->justReturn('https://example.test/');

        expect(Endpoints::issuer())->toBe('https://example.test');
    });

    it('keeps the subdirectory of an installation that lives in one', function (): void {
        Functions\when('home_url')->justReturn('https://example.test/blog/');

        expect(Endpoints::issuer())->toBe('https://example.test/blog');
    });
});

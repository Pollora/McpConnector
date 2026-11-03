<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\Controller\DiscoveryController;
use Pollora\McpConnector\OAuth\Endpoints;
use Pollora\McpConnector\Settings;

beforeEach(function (): void {
    Settings::forget();
    Functions\when('untrailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\'));
});

afterEach(function (): void {
    Settings::forget();
});

describe('the authorization server metadata', function (): void {
    it('says how a client authenticates at the revocation endpoint, as at the token endpoint', function (): void {
        $metadata = (new DiscoveryController(Settings::fromArray([])))->authorizationServer()->get_data();

        expect($metadata['revocation_endpoint_auth_methods_supported'])
            ->toBe($metadata['token_endpoint_auth_methods_supported'])
            ->toContain('client_secret_post', 'client_secret_basic', 'none');
    });
});

describe('the protected resource metadata (RFC 9728)', function (): void {
    beforeEach(function (): void {
        $this->response = (new DiscoveryController(Settings::fromArray([])))->protectedResource();
        $this->metadata = $this->response->get_data();
    });

    it('names the MCP endpoint as the resource and the issuer as its authorization server', function (): void {
        expect($this->metadata['resource'])->toBe('https://example.test/wp-json/mcp/connector')
            ->and($this->metadata['authorization_servers'])->toBe([Endpoints::issuer()]);
    });

    it('follows the configured MCP server route', function (): void {
        $this->setSettings(['server_route' => 'my-site']);
        Settings::forget();

        $metadata = (new DiscoveryController(Settings::current()))->protectedResource()->get_data();

        expect($metadata['resource'])->toBe('https://example.test/wp-json/mcp/my-site');
    });

    it('accepts bearer tokens in the Authorization header only', function (): void {
        expect($this->metadata['bearer_methods_supported'])->toBe(['header']);
    });

    it('lists the supported scopes', function (): void {
        expect($this->metadata['scopes_supported'])->toBe(\Pollora\McpConnector\OAuth\Scope::supported());
    });

    it('is served as 200, uncacheable and readable cross-origin', function (): void {
        expect($this->response->get_status())->toBe(200)
            ->and($this->response->get_headers())
            ->toHaveKey('Cache-Control', 'no-store')
            ->toHaveKey('Access-Control-Allow-Origin', '*');
    });
});

describe('the authorization server metadata (RFC 8414)', function (): void {
    beforeEach(function (): void {
        $this->response = (new DiscoveryController(Settings::fromArray([])))->authorizationServer();
        $this->metadata = $this->response->get_data();
    });

    it('carries every field the RFC requires and the flow needs', function (): void {
        expect($this->metadata)->toHaveKeys([
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'revocation_endpoint',
            'response_types_supported',
            'grant_types_supported',
            'code_challenge_methods_supported',
            'token_endpoint_auth_methods_supported',
            'scopes_supported',
        ]);
    });

    it('points at the same addresses the endpoints actually live at', function (): void {
        expect($this->metadata['issuer'])->toBe(Endpoints::issuer())
            ->and($this->metadata['authorization_endpoint'])->toBe(Endpoints::authorize())
            ->and($this->metadata['token_endpoint'])->toBe(Endpoints::token())
            ->and($this->metadata['revocation_endpoint'])->toBe(Endpoints::revoke());
    });

    it('uses the issuer that the protected resource document names, character for character', function (): void {
        $resource = (new DiscoveryController(Settings::fromArray([])))->protectedResource()->get_data();

        expect($this->metadata['issuer'])->toBe($resource['authorization_servers'][0])
            ->and($this->metadata['issuer'])->not->toEndWith('/');
    });

    it('supports the authorization code flow with refresh tokens, and nothing else', function (): void {
        expect($this->metadata['response_types_supported'])->toBe(['code'])
            ->and($this->metadata['grant_types_supported'])->toBe(['authorization_code', 'refresh_token']);
    });

    it('advertises PKCE S256 only, never plain', function (): void {
        expect($this->metadata['code_challenge_methods_supported'])->toBe(['S256']);
    });

    it('is served as 200, uncacheable and readable cross-origin', function (): void {
        expect($this->response->get_status())->toBe(200)
            ->and($this->response->get_headers())
            ->toHaveKey('Cache-Control', 'no-store')
            ->toHaveKey('Access-Control-Allow-Origin', '*');
    });

    it('advertises the registration endpoint when dynamic registration is open', function (): void {
        $metadata = (new DiscoveryController(Settings::fromArray(['dynamic_registration_open' => true])))
            ->authorizationServer()->get_data();

        expect($metadata['registration_endpoint'])->toBe(Endpoints::register());
    });

    it('leaves the registration endpoint out when dynamic registration is closed', function (): void {
        $metadata = (new DiscoveryController(Settings::fromArray(['dynamic_registration_open' => false])))
            ->authorizationServer()->get_data();

        expect($metadata)->not->toHaveKey('registration_endpoint');
    });
});

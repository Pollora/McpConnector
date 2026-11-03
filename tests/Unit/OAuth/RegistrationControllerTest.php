<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\Controller\RegistrationController;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/oauth-request.php';

beforeEach(function (): void {
    Functions\when('wp_generate_uuid4')->justReturn('client-1');
    Functions\when('wp_hash_password')->alias(static fn (string $p): string => 'hashed:' . $p);

    $this->register = function (array $body, bool $open = true): WP_REST_Response {
        $controller = new RegistrationController(
            Settings::fromArray(['dynamic_registration_open' => $open]),
            new ClientRepository(),
        );

        return $controller->handle(mcpcTokenRequest([], [], $body));
    };
});

describe('dynamic client registration', function (): void {
    it('registers a client and returns its secret exactly once', function (): void {
        $response = ($this->register)([
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.example/callback'],
        ]);
        $body = $response->get_data();

        expect($response->get_status())->toBe(201)
            ->and($body)->toMatchArray([
                'client_id' => 'client-1',
                'client_name' => 'Claude',
                'redirect_uris' => ['https://claude.example/callback'],
                'token_endpoint_auth_method' => 'client_secret_post',
                'client_secret_expires_at' => 0,
            ])
            ->and($body['client_secret'])->toHaveLength(48)
            ->and($response->get_headers())->toMatchArray(['Cache-Control' => 'no-store'])
            ->and(serialize($this->optionValues))->not->toContain('"' . $body['client_secret'] . '"');
    });

    it('is refused outright when self-registration is switched off', function (): void {
        $response = ($this->register)(['redirect_uris' => ['https://claude.example/cb']], false);

        expect($response->get_status())->toBe(403)
            ->and($response->get_data()['error'])->toBe('access_denied')
            ->and($this->optionValues)->not->toHaveKey(ClientRepository::OPTION);
    });

    it('accepts https and loopback http redirect URIs only', function (string $uri, bool $accepted): void {
        $response = ($this->register)(['redirect_uris' => [$uri]]);

        if ($accepted) {
            expect($response->get_status())->toBe(201)
                ->and($response->get_data()['redirect_uris'])->toBe([$uri]);
        } else {
            expect($response->get_status())->toBe(400)
                ->and($response->get_data()['error'])->toBe('invalid_redirect_uri');
        }
    })->with([
        'https' => ['https://app.example/cb', true],
        'https with a port and query' => ['https://app.example:8443/cb?a=1', true],
        'http on 127.0.0.1' => ['http://127.0.0.1:8080/cb', true],
        'http on localhost' => ['http://localhost:3000/cb', true],
        'http on a remote host' => ['http://app.example/cb', false],
        'http on a host that merely starts with localhost' => ['http://localhost.evil.test/cb', false],
        'http on a host that merely starts with 127.0.0.1' => ['http://127.0.0.1.evil.test/cb', false],
        'a javascript: URI' => ['javascript:alert(1)', false],
        'a data: URI' => ['data:text/html,x', false],
        'a custom scheme' => ['myapp://callback', false],
        'a relative path' => ['/callback', false],
        'a protocol-relative URL' => ['//evil.example/cb', false],
        'a fragment' => ['https://app.example/cb#frag', false],
        'garbage' => ['not a url', false],
    ]);

    it('drops the bad URIs and keeps the good ones, without duplicates', function (): void {
        $response = ($this->register)(['redirect_uris' => [
            'https://a.example/cb',
            'http://evil.example/cb',
            'https://a.example/cb',
            'https://b.example/cb',
        ]]);

        expect($response->get_data()['redirect_uris'])->toBe(['https://a.example/cb', 'https://b.example/cb']);
    });

    it('reads no more than ten redirect URIs', function (): void {
        $uris = array_map(static fn (int $i): string => "https://app{$i}.example/cb", range(1, 15));

        $response = ($this->register)(['redirect_uris' => $uris]);

        expect($response->get_data()['redirect_uris'])->toHaveCount(10);
    });

    it('requires at least one valid redirect URI', function (array $body): void {
        expect(($this->register)($body)->get_data()['error'])->toBe('invalid_redirect_uri');
    })->with([
        'none' => [[]],
        'an empty list' => [['redirect_uris' => []]],
        'only invalid ones' => [['redirect_uris' => ['http://evil.example/cb']]],
    ]);

    it('truncates the name, strips markup and falls back for a blank one', function (): void {
        $long = ($this->register)(['client_name' => str_repeat('a', 300), 'redirect_uris' => ['https://a.example/cb']]);
        $markup = ($this->register)(['client_name' => '<b>Evil</b>', 'redirect_uris' => ['https://a.example/cb']]);
        $blank = ($this->register)(['client_name' => '   ', 'redirect_uris' => ['https://a.example/cb']]);

        expect(mb_strlen($long->get_data()['client_name']))->toBe(100)
            ->and($markup->get_data()['client_name'])->toBe('Evil')
            ->and($blank->get_data()['client_name'])->toBe('Unnamed MCP client');
    });

    it('registers a public client without a secret', function (): void {
        $body = ($this->register)([
            'redirect_uris' => ['https://a.example/cb'],
            'token_endpoint_auth_method' => 'none',
        ])->get_data();

        expect($body['token_endpoint_auth_method'])->toBe('none')
            ->and($body)->not->toHaveKey('client_secret');
    });

    it('falls back to client_secret_post for an unsupported auth method', function (): void {
        $body = ($this->register)([
            'redirect_uris' => ['https://a.example/cb'],
            'token_endpoint_auth_method' => 'private_key_jwt',
        ])->get_data();

        expect($body['token_endpoint_auth_method'])->toBe('client_secret_post');
    });

    it('marks the stored client as self-registered', function (): void {
        ($this->register)(['redirect_uris' => ['https://a.example/cb']]);

        expect((new ClientRepository())->find('client-1')?->selfRegistered)->toBeTrue();
    });
});

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
        'http on the IPv6 loopback' => ['http://[::1]:8080/cb', true],
        'http on the IPv6 loopback without a port' => ['http://[::1]/cb', true],
        'http on the IPv6 loopback written in full' => ['http://[0:0:0:0:0:0:0:1]:8080/cb', true],
        'http on another IPv6 address' => ['http://[::2]:8080/cb', false],
        'http on an IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/cb', false],
        'http on a bracketed name that is not an address' => ['http://[localhost]/cb', false],
        'https on the IPv6 loopback' => ['https://[::1]:8443/cb', true],
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

    it('ignores a redirect URI that is not a string, without a warning', function (): void {
        $response = ($this->register)(['redirect_uris' => [
            ['https://nested.example/cb'],
            'https://good.example/cb',
            null,
            42,
            ['key' => 'https://keyed.example/cb'],
        ]]);

        expect($response->get_status())->toBe(201)
            ->and($response->get_data()['redirect_uris'])->toBe(['https://good.example/cb']);
    });

    it('refuses a registration whose redirect URIs are none of them strings', function (): void {
        $response = ($this->register)(['redirect_uris' => [['https://nested.example/cb'], ['x']]]);

        expect($response->get_status())->toBe(400)
            ->and($response->get_data()['error'])->toBe('invalid_redirect_uri');
    });

    it('still accepts a single redirect URI sent as a bare string', function (): void {
        $response = ($this->register)(['redirect_uris' => 'https://one.example/cb']);

        expect($response->get_status())->toBe(201)
            ->and($response->get_data()['redirect_uris'])->toBe(['https://one.example/cb']);
    });

    it('treats a client name that is not a scalar as absent, without a warning', function (): void {
        $response = ($this->register)([
            'client_name' => ['Claude'],
            'redirect_uris' => ['https://claude.example/cb'],
        ]);

        expect($response->get_status())->toBe(201)
            ->and($response->get_data()['client_name'])->toBe('Unnamed MCP client');
    });

    it('falls back to the default authentication method for a value that is not a string', function (): void {
        $response = ($this->register)([
            'token_endpoint_auth_method' => ['none'],
            'redirect_uris' => ['https://claude.example/cb'],
        ]);

        expect($response->get_status())->toBe(201)
            ->and($response->get_data()['token_endpoint_auth_method'])->toBe('client_secret_post');
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

describe('the exact shape of the answers', function (): void {
    it('answers a registration with every documented field and no cache headers missing', function (): void {
        $response = ($this->register)(['client_name' => 'Claude', 'redirect_uris' => ['https://claude.example/callback']]);
        $body = $response->get_data();

        expect(array_keys($body))->toBe([
            'client_id', 'client_id_issued_at', 'client_name', 'redirect_uris', 'grant_types', 'response_types',
            'token_endpoint_auth_method', 'client_secret', 'client_secret_expires_at',
        ])->and($body['grant_types'])->toBe(['authorization_code', 'refresh_token'])
            ->and($body['response_types'])->toBe(['code'])
            ->and($body['client_id_issued_at'])->toBeInt()
            ->and($response->get_headers())->toBe(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    });

    it('leaves the secret out of a public client\'s answer', function (): void {
        $body = ($this->register)(['redirect_uris' => ['https://claude.example/cb'], 'token_endpoint_auth_method' => 'none'])->get_data();

        expect(array_keys($body))->toBe([
            'client_id', 'client_id_issued_at', 'client_name', 'redirect_uris', 'grant_types', 'response_types', 'token_endpoint_auth_method',
        ])->and($body['token_endpoint_auth_method'])->toBe('none');
    });

    it('accepts each supported authentication method', function (): void {
        foreach (['client_secret_post', 'client_secret_basic', 'none'] as $method) {
            $body = ($this->register)(['redirect_uris' => ['https://claude.example/cb'], 'token_endpoint_auth_method' => $method])->get_data();

            expect($body['token_endpoint_auth_method'])->toBe($method);
        }
    });

    it('words both refusals as OAuth errors that nothing caches', function (): void {
        $closed = ($this->register)(['redirect_uris' => ['https://claude.example/cb']], false);
        $invalid = ($this->register)(['redirect_uris' => ['ftp://nope']]);

        expect($closed->get_data())->toBe(['error' => 'access_denied', 'error_description' => 'This site does not accept self-registration. An administrator must create the client.'])
            ->and($closed->get_headers())->toBe(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'])
            ->and($invalid->get_status())->toBe(400)
            ->and($invalid->get_data())->toBe(['error' => 'invalid_redirect_uri', 'error_description' => 'At least one valid https redirect URI is required.'])
            ->and($invalid->get_headers())->toBe(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    });

    it('returns the accepted URIs as a list, whatever keys the request used or gaps it left', function (): void {
        $body = ($this->register)(['redirect_uris' => [4 => 'ftp://bad', 'x' => 'https://a.test/cb', 9 => 'https://a.test/cb#frag', 12 => 'https://b.test/cb', 13 => 'https://a.test/cb']])->get_data();

        expect($body['redirect_uris'])->toBe(['https://a.test/cb', 'https://b.test/cb']);
    });

    it('keeps looking past a URI it has to skip', function (): void {
        $body = ($this->register)(['redirect_uris' => ['not a url', 'https://a.test/cb#frag', 'http://example.com/cb', 'https://ok.test/cb']])->get_data();

        expect($body['redirect_uris'])->toBe(['https://ok.test/cb']);
    });

    it('accepts the loopback in its IPv6 forms and refuses what only looks like it', function (): void {
        $cases = [
            'short form' => ['http://[::1]:8080/cb', true],
            'long form' => ['http://[0:0:0:0:0:0:0:1]/cb', true],
            'another address' => ['http://[2001:db8::1]/cb', false],
            'a bracketed name' => ['http://[localhost]/cb', false],
            'open bracket only' => ['http://[::1/cb', false],
            'a name that ends with a bracket' => ['http://evil]/cb', false],
        ];

        foreach ($cases as $label => [$uri, $accepted]) {
            expect(($this->register)(['redirect_uris' => [$uri]])->get_status())->toBe($accepted ? 201 : 400, $label);
        }
    });

    it('trims the name after cutting it', function (): void {
        $body = ($this->register)(['client_name' => str_repeat('a', 99) . ' bbb', 'redirect_uris' => ['https://a.test/cb']])->get_data();

        expect($body['client_name'])->toBe(str_repeat('a', 99));
    });

    it('names an unnamed client with the default, and reads a numeric name as text', function (): void {
        $unnamed = ($this->register)(['redirect_uris' => ['https://a.test/cb']])->get_data();
        $numeric = ($this->register)(['client_name' => 42, 'redirect_uris' => ['https://a.test/cb']])->get_data();

        expect($unnamed['client_name'])->toBe('Unnamed MCP client')
            ->and($numeric['client_name'])->toBe('42');
    });
});

describe('the limits of an open registration', function (): void {
    afterEach(function (): void {
        unset($_SERVER['REMOTE_ADDR']);
    });

    it('limits the attempts of one address, and tells it when to come back', function (): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $ids = 0;
        Functions\when('wp_generate_uuid4')->alias(static function () use (&$ids): string {
            return 'client-' . ++$ids;
        });
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook === RegistrationController::RATE_LIMIT_FILTER ? ['limit' => 2, 'window' => 600] : $value);
        $body = ['redirect_uris' => ['https://claude.example/cb']];

        $statuses = [($this->register)($body)->get_status(), ($this->register)($body)->get_status()];
        $third = ($this->register)($body);

        expect($statuses)->toBe([201, 201])
            ->and($third->get_status())->toBe(429)
            ->and($third->get_data()['error'])->toBe('temporarily_unavailable')
            ->and((int) $third->get_headers()['Retry-After'])->toBeBetween(1, 600)
            ->and($this->optionValues[ClientRepository::OPTION])->toHaveCount(2);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';

        expect(($this->register)($body)->get_status())->toBe(201);
    });

    it('counts a rejected attempt too, since the work is done either way', function (): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook === RegistrationController::RATE_LIMIT_FILTER ? ['limit' => 1, 'window' => 600] : $value);

        ($this->register)(['redirect_uris' => ['ftp://nope']]);

        expect(($this->register)(['redirect_uris' => ['https://claude.example/cb']])->get_status())->toBe(429);
    });

    it('lets the site switch the limit off', function (): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook === RegistrationController::RATE_LIMIT_FILTER ? ['limit' => 0] : $value);

        $statuses = array_map(fn (): int => ($this->register)(['redirect_uris' => ['https://claude.example/cb']])->get_status(), range(1, 25));

        expect(array_unique($statuses))->toBe([201]);
    });

    it('answers 503 once the ceiling is reached and every registered client is connected', function (): void {
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook === ClientRepository::MAX_FILTER ? 1 : $value);
        Functions\when('wp_generate_password')->justReturn(str_repeat('p', 48));
        $first = ($this->register)(['redirect_uris' => ['https://claude.example/cb']]);
        (new Pollora\McpConnector\OAuth\TokenRepository())->issue(7, $first->get_data()['client_id'], ['read']);

        $second = ($this->register)(['redirect_uris' => ['https://other.example/cb']]);

        expect($second->get_status())->toBe(503)
            ->and($second->get_data()['error'])->toBe('temporarily_unavailable');
    });

    it('keeps the registered clients under the ceiling however many register', function (): void {
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook === ClientRepository::MAX_FILTER ? 3 : ($hook === RegistrationController::RATE_LIMIT_FILTER ? ['limit' => 0] : $value));
        $ids = 0;
        Functions\when('wp_generate_uuid4')->alias(static function () use (&$ids): string {
            return 'client-' . ++$ids;
        });

        foreach (range(1, 10) as $_) {
            ($this->register)(['redirect_uris' => ['https://claude.example/cb']]);
        }

        expect(array_keys($this->optionValues[ClientRepository::OPTION]))->toBe(['client-8', 'client-9', 'client-10']);
    });
});

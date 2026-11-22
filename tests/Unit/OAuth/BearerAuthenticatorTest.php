<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\BearerAuthenticator;
use Pollora\McpConnector\OAuth\Scope;
use Pollora\McpConnector\OAuth\TokenRepository;

beforeEach(function (): void {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    Scope::setCurrent(null);

    $counter = 0;
    Functions\when('wp_generate_password')->alias(
        static function (int $length = 12) use (&$counter): string {
            return substr(str_pad('t' . ++$counter, $length, 'x'), 0, $length);
        },
    );

    $this->tokens = new TokenRepository();
    $this->authenticator = new BearerAuthenticator($this->tokens);
});

afterEach(function (): void {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    Scope::setCurrent(null);
});

describe('bearer authentication', function (): void {
    it('hooks in after the core cookie resolution', function (): void {
        $registered = [];
        Functions\when('add_filter')->alias(function (string $hook, mixed $callback, int $priority = 10) use (&$registered): bool {
            $registered[] = [$hook, $priority];

            return true;
        });

        $this->authenticator->register();

        expect($registered)->toBe([['determine_current_user', 20], ['rest_pre_dispatch', 10]]);
    });

    it('resolves a valid token to its user and records the token scope', function (string $key): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER[$key] = 'Bearer ' . $issued['access_token'];

        expect($this->authenticator->authenticate(false))->toBe(7)
            ->and(Scope::current())->toBe(['read'])
            ->and(Scope::currentAllowsWrite())->toBeFalse();
    })->with(['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION']);

    it('accepts the scheme in any case', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'bearer ' . $issued['access_token'];

        expect($this->authenticator->authenticate(false))->toBe(7);
    });

    it('keeps the user a cookie already resolved and applies the scope of a token issued to the same user', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];

        expect($this->authenticator->authenticate(7))->toBe(7)
            ->and(Scope::current())->toBe(['read'])
            ->and(Scope::currentAllowsWrite())->toBeFalse();
    });

    it('leaves a cookie user unrestricted when the token belongs to someone else', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];

        expect($this->authenticator->authenticate(3))->toBe(3)
            ->and(Scope::current())->toBeNull()
            ->and(Scope::currentAllowsWrite())->toBeTrue();
    });

    it('leaves a cookie user unrestricted when the bearer token is unknown, expired or revoked', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $this->tokens->revoke($issued['access_token']);

        foreach ([$issued['access_token'], 'unknown'] as $token) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

            expect($this->authenticator->authenticate(7))->toBe(7)
                ->and(Scope::current())->toBeNull();
        }
    });

    it('leaves a cookie user without a bearer header unrestricted', function (): void {
        expect($this->authenticator->authenticate(7))->toBe(7)
            ->and(Scope::current())->toBeNull();
    });

    it('does not authenticate with a refresh token', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['refresh_token'];

        expect($this->authenticator->authenticate(false))->toBeFalse();
    });

    it('does not authenticate with an expired, revoked or unknown token', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $this->tokens->revoke($issued['access_token']);

        foreach ([$issued['access_token'], 'unknown', ''] as $token) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

            expect($this->authenticator->authenticate(false))->toBeFalse();
        }

        expect(Scope::current())->toBeNull();
    });

    it('ignores other schemes', function (string $header): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = str_replace('TOKEN', $issued['access_token'], $header);

        expect($this->authenticator->authenticate(false))->toBeFalse();
    })->with([
        'Basic' => ['Basic TOKEN'],
        'no scheme' => ['TOKEN'],
        'Bearer without a space' => ['BearerTOKEN'],
    ]);

    it('keeps the value it was given when the request carries no token', function (): void {
        expect($this->authenticator->authenticate(false))->toBeFalse();
    });

    it('does not authenticate a token whose record names no user', function (): void {
        $issued = $this->tokens->issue(0, 'client-1', ['read', 'write']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];

        expect($this->authenticator->authenticate(false))->toBeFalse()
            ->and(Scope::current())->toBeNull();
    });
});

describe('reading the header', function (): void {
    it('falls back to the server-provided header list, whatever the case of the name', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        Functions\when('apache_request_headers')->justReturn(['Accept' => 'x', 'authorization' => 'Bearer ' . $issued['access_token']]);

        expect($this->authenticator->authenticate(false))->toBe(7)
            ->and(Scope::current())->toBe(['read']);
    });

    it('stops at the first Authorization header of that list', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        Functions\when('apache_request_headers')->justReturn([
            'AUTHORIZATION' => 'Bearer ' . $issued['access_token'],
            'Authorization' => 'Bearer unknown',
        ]);

        expect($this->authenticator->authenticate(false))->toBe(7);
    });

    it('prefers the plain server variable over that list, and the first variable over the second', function (): void {
        $first = $this->tokens->issue(7, 'client-1', ['read']);
        $second = $this->tokens->issue(8, 'client-1', ['read']);
        $third = $this->tokens->issue(9, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $first['access_token'];
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $second['access_token'];
        Functions\when('apache_request_headers')->justReturn(['Authorization' => 'Bearer ' . $third['access_token']]);

        expect($this->authenticator->authenticate(false))->toBe(7);

        unset($_SERVER['HTTP_AUTHORIZATION']);

        expect($this->authenticator->authenticate(false))->toBe(8);
    });

    it('skips an empty first variable for the second', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = '';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];

        expect($this->authenticator->authenticate(false))->toBe(7);
    });

    it('does not consult the header list when a variable already answered', function (): void {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc';
        $calls = 0;
        Functions\when('apache_request_headers')->alias(static function () use (&$calls): array {
            $calls++;

            return [];
        });

        $this->authenticator->authenticate(false);

        expect($calls)->toBe(0);
    });

    it('ignores spaces around the token', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer   ' . $issued['access_token'];

        expect($this->authenticator->authenticate(false))->toBe(7);
    });

    it('accepts user 1, the lowest real identifier', function (): void {
        $issued = $this->tokens->issue(1, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];

        expect($this->authenticator->authenticate(false))->toBe(1);
    });
});

describe('route restriction', function (): void {
    function dispatchTo(BearerAuthenticator $authenticator, string $route): mixed
    {
        $request = new class ($route) extends WP_REST_Request {
            public function __construct(private readonly string $route)
            {
                parent::__construct();
            }

            public function get_route(): string
            {
                return $this->route;
            }
        };

        return $authenticator->restrictRoute(null, new WP_REST_Server(), $request);
    }

    it('refuses a route outside the MCP endpoint to a request a bearer token alone authenticated', function (string $route): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];
        $this->authenticator->authenticate(false);

        $result = dispatchTo($this->authenticator, $route);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_data())->toBe(['status' => 403]);
    })->with(['/wp/v2/posts', '/wp/v2/plugins', '/mcp-evil/x', '/']);

    it('lets a token reach the MCP and abilities endpoints', function (string $route): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];
        $this->authenticator->authenticate(false);

        expect(dispatchTo($this->authenticator, $route))->toBeNull();
    })->with(['/mcp/mcp-adapter-default-server', '/mcp', '/wp-abilities/v1/abilities']);

    it('does not restrict a request a login cookie authenticated', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];
        $this->authenticator->authenticate(7);

        expect(dispatchTo($this->authenticator, '/wp/v2/posts'))->toBeNull();
    });

    it('does not restrict a request without a token', function (): void {
        expect(dispatchTo($this->authenticator, '/wp/v2/posts'))->toBeNull();
    });
});

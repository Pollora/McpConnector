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

        expect($registered)->toBe([['determine_current_user', 20]]);
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

    it('leaves a user already resolved by a cookie alone, and does not narrow their scope', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];

        expect($this->authenticator->authenticate(3))->toBe(3)
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

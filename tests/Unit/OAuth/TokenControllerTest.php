<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\AuthorizationCodeRepository;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\Controller\TokenController;
use Pollora\McpConnector\OAuth\Pkce;
use Pollora\McpConnector\OAuth\TokenRepository;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/oauth-request.php';

const MCPC_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
const MCPC_REDIRECT = 'https://app.example/cb';

beforeEach(function (): void {
    $counter = 0;
    Functions\when('wp_generate_password')->alias(
        static function (int $length = 12) use (&$counter): string {
            return substr(str_pad('t' . ++$counter, $length, 'x'), 0, $length);
        },
    );
    Functions\when('wp_generate_uuid4')->justReturn('client-1');
    Functions\when('wp_hash_password')->alias(static fn (string $p): string => 'hashed:' . $p);
    Functions\when('wp_check_password')->alias(static fn (string $p, string $h): bool => $h === 'hashed:' . $p);
    Functions\when('user_can')->justReturn(true);

    $this->clients = new ClientRepository();
    $this->codes = new AuthorizationCodeRepository();
    $this->tokens = new TokenRepository();
    $this->controller = new TokenController(Settings::fromArray([]), $this->clients, $this->codes, $this->tokens);

    $created = $this->clients->create('App', [MCPC_REDIRECT]);
    $this->client = $created['client'];
    $this->secret = $created['secret'];

    $this->issueCode = fn (string $clientId = 'client-1', string $redirect = MCPC_REDIRECT): string => $this->codes->issue(
        7,
        $clientId,
        $redirect,
        Pkce::challengeFor(MCPC_VERIFIER),
        ['read', 'write'],
    );

    $this->exchange = fn (array $overrides = []): WP_REST_Response => $this->controller->handle(mcpcTokenRequest($overrides + [
        'grant_type' => 'authorization_code',
        'client_id' => 'client-1',
        'client_secret' => $this->secret,
        'code' => ($this->issueCode)(),
        'redirect_uri' => MCPC_REDIRECT,
        'code_verifier' => MCPC_VERIFIER,
    ]));
});

describe('the authorization code grant', function (): void {
    it('issues a token pair for a valid code, redirect URI and verifier', function (): void {
        $response = ($this->exchange)();
        $body = $response->get_data();

        expect($response->get_status())->toBe(200)
            ->and($body)->toMatchArray(['token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'read write'])
            ->and($this->tokens->findAccessToken($body['access_token']))->toMatchArray(['user_id' => 7, 'client_id' => 'client-1'])
            ->and($response->get_headers())->toMatchArray(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    });

    it('refuses to redeem the same code twice', function (): void {
        $code = ($this->issueCode)();

        expect(($this->exchange)(['code' => $code])->get_status())->toBe(200);

        $second = ($this->exchange)(['code' => $code]);

        expect($second->get_status())->toBe(400)
            ->and($second->get_data()['error'])->toBe('invalid_grant');
    });

    it('refuses an unknown or missing code', function (string $code): void {
        $response = ($this->exchange)(['code' => $code]);

        expect($response->get_status())->toBe(400)
            ->and($response->get_data()['error'])->toBe('invalid_grant');
    })->with(['unknown' => ['nope'], 'empty' => ['']]);

    it('refuses a code that was issued to another client', function (): void {
        Functions\when('wp_generate_uuid4')->justReturn('client-2');
        $this->clients->create('Other', [MCPC_REDIRECT]);
        $stolen = ($this->issueCode)('client-2');

        $response = ($this->exchange)(['code' => $stolen]);

        expect($response->get_status())->toBe(400)
            ->and($response->get_data()['error'])->toBe('invalid_grant');
    });

    it('refuses a redirect URI that differs from the one used to obtain the code', function (string $redirect): void {
        $response = ($this->exchange)(['redirect_uri' => $redirect]);

        expect($response->get_status())->toBe(400)
            ->and($response->get_data()['error'])->toBe('invalid_grant');
    })->with([
        'another host' => ['https://evil.example/cb'],
        'a trailing slash' => [MCPC_REDIRECT . '/'],
        'omitted' => [''],
    ]);

    it('refuses a wrong PKCE verifier and spends the code anyway', function (): void {
        $code = ($this->issueCode)();

        $bad = ($this->exchange)(['code' => $code, 'code_verifier' => str_repeat('z', 43)]);
        $retry = ($this->exchange)(['code' => $code]);

        expect($bad->get_data()['error'])->toBe('invalid_grant')
            ->and($retry->get_data()['error'])->toBe('invalid_grant');
    });

    it('refuses a missing verifier', function (): void {
        expect(($this->exchange)(['code_verifier' => ''])->get_data()['error'])->toBe('invalid_grant');
    });

    it('refuses to issue tokens once the user has lost the required capability', function (): void {
        $checked = [];
        Functions\when('user_can')->alias(function (int $user, string $capability) use (&$checked): bool {
            $checked[] = [$user, $capability];

            return false;
        });

        $response = ($this->exchange)();

        expect($checked)->toBe([[7, 'edit_posts']])
            ->and($response->get_status())->toBe(400)
            ->and($response->get_data()['error'])->toBe('invalid_grant')
            ->and($this->optionValues)->not->toHaveKey(TokenRepository::ACCESS_OPTION);
    });
});

describe('client authentication', function (): void {
    it('refuses a wrong secret, an unknown client and a missing secret alike', function (array $override): void {
        $response = ($this->exchange)($override);

        expect($response->get_status())->toBe(401)
            ->and($response->get_data()['error'])->toBe('invalid_client')
            ->and($response->get_headers())->toHaveKey('WWW-Authenticate');
    })->with([
        'wrong secret' => [['client_secret' => 'wrong']],
        'no secret' => [['client_secret' => '']],
        'unknown client' => [['client_id' => 'nobody']],
        'no client at all' => [['client_id' => '']],
    ]);

    it('gives the same answer for an unknown client and a wrong secret', function (): void {
        $unknown = ($this->exchange)(['client_id' => 'nobody'])->get_data();
        $wrong = ($this->exchange)(['client_secret' => 'wrong'])->get_data();

        expect($unknown)->toBe($wrong);
    });

    it('accepts HTTP Basic credentials, urldecoding both halves', function (): void {
        $header = 'Basic ' . base64_encode(rawurlencode('client-1') . ':' . rawurlencode($this->secret));

        $response = $this->controller->handle(mcpcTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => ($this->issueCode)(),
            'redirect_uri' => MCPC_REDIRECT,
            'code_verifier' => MCPC_VERIFIER,
        ], ['authorization' => $header]));

        expect($response->get_status())->toBe(200);
    });

    it('refuses malformed Basic credentials', function (string $header): void {
        $response = $this->controller->handle(mcpcTokenRequest(['grant_type' => 'authorization_code'], ['authorization' => $header]));

        expect($response->get_status())->toBe(401);
    })->with([
        'not base64' => ['Basic !!!'],
        'no colon' => ['Basic ' . 'Y2xpZW50LTE='],
        'another scheme' => ['Bearer abc'],
    ]);

    it('lets a public client authenticate with its identifier alone, relying on PKCE', function (): void {
        Functions\when('wp_generate_uuid4')->justReturn('public-1');
        $this->clients->create('Public', [MCPC_REDIRECT], Client::AUTH_METHOD_NONE);

        $response = $this->controller->handle(mcpcTokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => 'public-1',
            'code' => ($this->issueCode)('public-1'),
            'redirect_uri' => MCPC_REDIRECT,
            'code_verifier' => MCPC_VERIFIER,
        ]));

        expect($response->get_status())->toBe(200);

        $bad = $this->controller->handle(mcpcTokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => 'public-1',
            'code' => ($this->issueCode)('public-1'),
            'redirect_uri' => MCPC_REDIRECT,
            'code_verifier' => 'wrong-verifier-wrong-verifier-wrong-verifier',
        ]));

        expect($bad->get_status())->toBe(400);
    });

    it('reads JSON bodies as well as form bodies', function (): void {
        $response = $this->controller->handle(mcpcTokenRequest([], [], [
            'grant_type' => 'authorization_code',
            'client_id' => 'client-1',
            'client_secret' => $this->secret,
            'code' => ($this->issueCode)(),
            'redirect_uri' => MCPC_REDIRECT,
            'code_verifier' => MCPC_VERIFIER,
        ]));

        expect($response->get_status())->toBe(200);
    });

    it('refuses an unsupported grant type', function (string $grant): void {
        $response = ($this->exchange)(['grant_type' => $grant]);

        expect($response->get_status())->toBe(400)
            ->and($response->get_data()['error'])->toBe('unsupported_grant_type');
    })->with(['password' => ['password'], 'client_credentials' => ['client_credentials'], 'empty' => ['']]);
});

describe('the refresh token grant', function (): void {
    beforeEach(function (): void {
        $this->refresh = fn (string $token, array $overrides = []): WP_REST_Response => $this->controller->handle(mcpcTokenRequest($overrides + [
            'grant_type' => 'refresh_token',
            'client_id' => 'client-1',
            'client_secret' => $this->secret,
            'refresh_token' => $token,
        ]));
    });

    it('rotates the pair, keeping user and scope, and cannot be replayed', function (): void {
        $first = ($this->exchange)()->get_data();

        $second = ($this->refresh)($first['refresh_token']);
        $body = $second->get_data();

        expect($second->get_status())->toBe(200)
            ->and($body['refresh_token'])->not->toBe($first['refresh_token'])
            ->and($body['scope'])->toBe('read write')
            ->and($this->tokens->findAccessToken($body['access_token']))->toMatchArray(['user_id' => 7]);

        $replay = ($this->refresh)($first['refresh_token']);

        expect($replay->get_status())->toBe(400)
            ->and($replay->get_data()['error'])->toBe('invalid_grant');
    });

    it('refuses an access token presented as a refresh token', function (): void {
        $first = ($this->exchange)()->get_data();

        expect(($this->refresh)($first['access_token'])->get_data()['error'])->toBe('invalid_grant');
    });

    it('refuses a refresh token that belongs to another client', function (): void {
        $issued = $this->tokens->issue(7, 'someone-else', ['read']);

        $response = ($this->refresh)($issued['refresh_token']);

        expect($response->get_status())->toBe(400)
            ->and($response->get_data()['error'])->toBe('invalid_grant');
    });

    it('re-checks the capability, so a demoted user cannot keep refreshing', function (): void {
        $first = ($this->exchange)()->get_data();
        Functions\when('user_can')->justReturn(false);

        $response = ($this->refresh)($first['refresh_token']);

        expect($response->get_data()['error'])->toBe('invalid_grant');
    });

    it('refuses an expired refresh token', function (): void {
        $first = ($this->exchange)()->get_data();
        $stored = get_option(TokenRepository::REFRESH_OPTION);
        foreach ($stored as $hash => $record) {
            $stored[$hash]['expires_at'] = time() - 1;
        }
        update_option(TokenRepository::REFRESH_OPTION, $stored);

        expect(($this->refresh)($first['refresh_token'])->get_data()['error'])->toBe('invalid_grant');
    });
});

describe('revocation', function (): void {
    it('revokes a token and answers 200', function (): void {
        $issued = $this->tokens->issue(7, 'client-1', ['read']);

        $response = $this->controller->revoke(mcpcTokenRequest(['token' => $issued['access_token']]));

        expect($response->get_status())->toBe(200)
            ->and($this->tokens->findAccessToken($issued['access_token']))->toBeNull();
    });

    it('answers 200 for an unknown token, so it is no oracle', function (): void {
        expect($this->controller->revoke(mcpcTokenRequest(['token' => 'nope']))->get_status())->toBe(200)
            ->and($this->controller->revoke(mcpcTokenRequest([]))->get_status())->toBe(200);
    });
});

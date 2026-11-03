<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Http\HttpResponse;
use Pollora\McpConnector\OAuth\AuthorizationCodeRepository;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\ConsentScreen;
use Pollora\McpConnector\OAuth\Controller\AuthorizationController;
use Pollora\McpConnector\OAuth\Pkce;
use Pollora\McpConnector\Settings;

const MCPC_AUTH_REDIRECT = 'https://app.example/cb';

/**
 * Query string of a redirect, decoded.
 *
 * @return array<string, string>
 */
function mcpcQueryOf(HttpResponse $response): array
{
    parse_str((string) parse_url($response->headers['Location'], PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

beforeEach(function (): void {
    $_GET = [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->loggedIn = true;
    $this->canConnect = true;
    $this->nonceValid = true;

    Functions\when('wp_generate_uuid4')->justReturn('client-1');
    Functions\when('wp_hash_password')->alias(static fn (string $p): string => 'hashed:' . $p);
    Functions\when('get_locale')->justReturn('en_US');
    Functions\when('is_user_logged_in')->alias(fn (): bool => $this->loggedIn);
    Functions\when('current_user_can')->alias(fn (): bool => $this->canConnect);
    Functions\when('get_current_user_id')->justReturn(7);
    Functions\when('wp_get_current_user')->justReturn(new WP_User(['ID' => 7, 'display_name' => 'Ada', 'user_login' => 'ada']));
    Functions\when('wp_nonce_field')->justReturn('<input type="hidden" name="_wpnonce" value="n">');
    Functions\when('wp_verify_nonce')->alias(fn (): bool => $this->nonceValid);
    Functions\when('wp_login_url')->alias(static fn (string $redirect = ''): string => 'https://example.test/wp-login.php?redirect_to=' . rawurlencode($redirect));
    // Faithful to core: values are appended verbatim, which is why the controller encodes them itself.
    Functions\when('add_query_arg')->alias(static function (array $args, string $url): string {
        $pairs = [];
        foreach ($args as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . implode('&', $pairs);
    });

    $this->codes = new AuthorizationCodeRepository();
    $this->controller = new AuthorizationController(
        Settings::fromArray([]),
        new ClientRepository(),
        $this->codes,
        new ConsentScreen(),
    );

    (new ClientRepository())->create('Claude <script>', [MCPC_AUTH_REDIRECT]);

    $this->challenge = Pkce::challengeFor(str_repeat('v', 64));
    $this->request = fn (array $overrides = []): array => $overrides + [
        'client_id' => 'client-1',
        'redirect_uri' => MCPC_AUTH_REDIRECT,
        'response_type' => 'code',
        'code_challenge' => $this->challenge,
        'code_challenge_method' => 'S256',
        'scope' => 'read write',
        'state' => 'xyz',
    ];
});

afterEach(function (): void {
    $_GET = [];
    $_POST = [];
    unset($_SERVER['REQUEST_METHOD']);
});

describe('requests that must never be redirected', function (): void {
    it('shows an error page for an unknown client', function (): void {
        $_GET = ($this->request)(['client_id' => 'nobody']);

        $response = $this->controller->handle();

        expect($response->status)->toBe(400)
            ->and($response->headers)->not->toHaveKey('Location');
    });

    it('shows an error page when the redirect URI is not exactly a registered one', function (string $uri): void {
        $_GET = ($this->request)(['redirect_uri' => $uri]);

        $response = $this->controller->handle();

        expect($response->status)->toBe(400)
            ->and($response->headers)->not->toHaveKey('Location');
    })->with([
        'another host' => ['https://evil.example/cb'],
        'a path prefix' => [MCPC_AUTH_REDIRECT . '/../evil'],
        'an added query' => [MCPC_AUTH_REDIRECT . '?x=1'],
        'a trailing slash' => [MCPC_AUTH_REDIRECT . '/'],
        'empty' => [''],
    ]);

    it('does the same on the POST that would issue the code', function (): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ($this->request)(['redirect_uri' => 'https://evil.example/cb']) + ['mcpc_decision' => 'approve', '_wpnonce' => 'n'];

        $response = $this->controller->handle();

        expect($response->headers)->not->toHaveKey('Location')
            ->and($this->transientValues)->toBe([]);
    });

    it('treats array-valued parameters as absent rather than fatal', function (): void {
        $_GET = ($this->request)(['client_id' => ['client-1'], 'redirect_uri' => [MCPC_AUTH_REDIRECT]]);

        expect($this->controller->handle()->status)->toBe(400);
    });
});

describe('requests reported back to the verified client', function (): void {
    it('refuses a response type other than code', function (string $type): void {
        $_GET = ($this->request)(['response_type' => $type]);

        $query = mcpcQueryOf($this->controller->handle());

        expect($query)->toMatchArray(['error' => 'unsupported_response_type', 'state' => 'xyz']);
    })->with(['token' => ['token'], 'empty' => ['']]);

    it('refuses PKCE that is missing, plain or too short', function (array $override): void {
        $_GET = ($this->request)($override);

        $response = $this->controller->handle();

        expect($response->status)->toBe(302)
            ->and($response->headers['Location'])->toStartWith(MCPC_AUTH_REDIRECT)
            ->and(mcpcQueryOf($response)['error'])->toBe('invalid_request');
    })->with([
        'no challenge' => [['code_challenge' => '']],
        'plain method' => [['code_challenge_method' => 'plain']],
        'no method' => [['code_challenge_method' => '']],
        'a short challenge' => [['code_challenge' => 'short']],
    ]);

    it('omits state when the client sent none', function (): void {
        $_GET = ($this->request)(['response_type' => 'token', 'state' => '']);

        expect(mcpcQueryOf($this->controller->handle()))->not->toHaveKey('state');
    });

    it('sends an anonymous visitor to log in, then back with the request intact', function (): void {
        $this->loggedIn = false;
        $_GET = ($this->request)();

        $location = $this->controller->handle()->headers['Location'];

        expect($location)->toStartWith('https://example.test/wp-login.php?redirect_to=')
            ->and(rawurldecode($location))->toContain('client_id=client-1')
            ->and(rawurldecode($location))->toContain('scope=read%20write');
    });

    it('denies a user without the required capability', function (): void {
        $this->canConnect = false;
        $_GET = ($this->request)();

        expect(mcpcQueryOf($this->controller->handle())['error'])->toBe('access_denied');
    });
});

describe('the consent screen', function (): void {
    it('is shown on GET, with the client name escaped, and issues nothing', function (): void {
        $_GET = ($this->request)();

        $response = $this->controller->handle();

        expect($response->status)->toBe(200)
            ->and($response->body)->toContain('Claude &lt;script&gt;')
            ->and($response->body)->not->toContain('Claude <script>')
            ->and($response->headers['X-Frame-Options'])->toBe('DENY')
            ->and($this->transientValues)->toBe([]);
    });
});

describe('approval', function (): void {
    beforeEach(function (): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->post = fn (array $overrides = []): array => ($this->request)($overrides) + ['mcpc_decision' => 'approve', '_wpnonce' => 'n'];
    });

    it('issues a code bound to the user, client, redirect URI, challenge and scopes', function (): void {
        $_POST = ($this->post)();

        $response = $this->controller->handle();
        $query = mcpcQueryOf($response);

        expect($response->status)->toBe(302)
            ->and($response->headers['Location'])->toStartWith(MCPC_AUTH_REDIRECT . '?')
            ->and($query['state'])->toBe('xyz')
            ->and($this->codes->consume($query['code']))->toBe([
                'user_id' => 7,
                'client_id' => 'client-1',
                'redirect_uri' => MCPC_AUTH_REDIRECT,
                'code_challenge' => $this->challenge,
                'scopes' => ['read', 'write'],
            ]);
    });

    it('grants read only when write was not asked for', function (): void {
        $_POST = ($this->post)(['scope' => 'read']);

        $query = mcpcQueryOf($this->controller->handle());

        expect($this->codes->consume($query['code'])['scopes'])->toBe(['read']);
    });

    it('encodes a hostile state so it cannot graft a parameter onto the redirect', function (): void {
        $_POST = ($this->post)(['state' => 'a&code=attacker']);

        $response = $this->controller->handle();
        $query = mcpcQueryOf($response);

        expect($query['state'])->toBe('a&code=attacker')
            ->and($query['code'])->not->toBe('attacker')
            ->and(substr_count($response->headers['Location'], 'code='))->toBe(1);
    });

    it('issues nothing without a valid nonce', function (): void {
        $this->nonceValid = false;
        $_POST = ($this->post)();

        $response = $this->controller->handle();

        expect($response->status)->toBe(403)
            ->and($response->headers)->not->toHaveKey('Location')
            ->and($this->transientValues)->toBe([]);
    });

    it('issues nothing when the user declines, or when no decision is sent', function (?string $decision): void {
        $_POST = ($this->post)();
        unset($_POST['mcpc_decision']);

        if ($decision !== null) {
            $_POST['mcpc_decision'] = $decision;
        }

        $query = mcpcQueryOf($this->controller->handle());

        expect($query['error'])->toBe('access_denied')
            ->and($query)->not->toHaveKey('code')
            ->and($this->transientValues)->toBe([]);
    })->with([
        'denied' => ['deny'],
        'garbage' => ['APPROVE-ish'],
        'nothing' => [null],
    ]);

    it('issues nothing when the user lacks the capability, even with a valid nonce', function (): void {
        $this->canConnect = false;
        $_POST = ($this->post)();

        expect(mcpcQueryOf($this->controller->handle())['error'])->toBe('access_denied')
            ->and($this->transientValues)->toBe([]);
    });

    it('issues nothing when the PKCE challenge is not S256', function (): void {
        $_POST = ($this->post)(['code_challenge_method' => 'plain']);

        expect(mcpcQueryOf($this->controller->handle()))->not->toHaveKey('code')
            ->and($this->transientValues)->toBe([]);
    });
});

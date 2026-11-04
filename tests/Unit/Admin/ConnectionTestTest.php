<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Admin\ConnectionTest;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\Endpoints;
use Pollora\McpConnector\Server\ServerRegistry;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/adminsmall-stubs.php';

defined('LOGGED_IN_COOKIE') || define('LOGGED_IN_COOKIE', 'wordpress_logged_in_test');

/**
 * Thrown by the doubled wp_send_json_*() functions, which would `die` in WordPress.
 */
final class ConnectiontestResponded extends RuntimeException
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public readonly string $kind, public readonly array $payload, public readonly ?int $status)
    {
        parent::__construct($kind);
    }
}

/**
 * A response in the shape wp_remote_*() returns.
 *
 * @param array<string, string>|string $body
 * @param array<string, string>        $headers
 *
 * @return array{code: int, body: string, headers: array<string, string>}
 */
function connectiontestResponse(int $code, array|string $body = '', array $headers = []): array
{
    return ['code' => $code, 'body' => is_array($body) ? (string) json_encode($body) : $body, 'headers' => $headers];
}

/**
 * Run the handler and return what it answered.
 *
 * @return array{kind: string, payload: array<string, mixed>, status: int|null}
 */
function connectiontestRun(): array
{
    try {
        (new ConnectionTest())->handle();
    } catch (ConnectiontestResponded $answer) {
        return ['kind' => $answer->kind, 'payload' => $answer->payload, 'status' => $answer->status];
    }

    throw new LogicException('The handler answered nothing.');
}

/**
 * @param array<string, mixed> $payload
 *
 * @return array<string, string> Step label to state.
 */
function connectiontestStates(array $payload): array
{
    return array_column($payload['steps'], 'state', 'label');
}

/**
 * @param array<string, mixed> $payload
 */
function connectiontestDetail(array $payload, string $label): string
{
    return (string) array_column($payload['steps'], 'detail', 'label')[$label];
}

beforeEach(function (): void {
    adminsmallPrepare();
    $_COOKIE = [];

    $this->capable = true;
    $this->nonceChecks = [];
    $this->http = [];
    $this->routes = [
        'discovery' => static fn (): array => connectiontestResponse(200, '{}'),
        'register' => function (string $url, array $args): array {
            $created = (new ClientRepository())->create('Registered', ['http://127.0.0.1/x']);
            $this->clientSecret = $created['secret'];

            return connectiontestResponse(201, ['client_id' => $created['client']->id, 'client_secret' => $created['secret']]);
        },
        'consent' => static fn (): array => connectiontestResponse(200, '<form><input type="hidden" name="_wpnonce" value="abc123"></form>'),
        'approve' => static fn (string $url, array $args): array => connectiontestResponse(302, '', [
            'location' => 'http://127.0.0.1/mcp-connector/self-test?code=the-code&state=' . $args['body']['state'],
        ]),
        'token' => static fn (): array => connectiontestResponse(200, ['access_token' => 'tok', 'expires_in' => 3600]),
        'initialize' => static fn (): array => connectiontestResponse(200, ['result' => ['serverInfo' => ['name' => 'Example MCP']]], ['mcp-session-id' => 'session-1']),
        'notify' => static fn (): array => connectiontestResponse(202),
        'tools' => static fn (): array => connectiontestResponse(200, ['result' => ['tools' => [['name' => 'a'], ['name' => 'b']]]]),
    ];

    $this->setSettings(['oauth_enabled' => true, 'dynamic_registration_open' => true]);

    $route = function (string $verb, string $url, array $args): array|WP_Error {
        $this->http[] = ['verb' => $verb, 'url' => $url, 'args' => $args];

        $name = match (true) {
            str_contains($url, '.well-known') => 'discovery',
            $url === Endpoints::register() => 'register',
            $url === Endpoints::token() => 'token',
            $verb === 'GET' && str_starts_with($url, Endpoints::authorize()) => 'consent',
            $url === Endpoints::authorize() => 'approve',
            $url === ServerRegistry::endpointUrl() => match (json_decode($args['body'], true)['method']) {
                'initialize' => 'initialize',
                'tools/list' => 'tools',
                default => 'notify',
            },
            default => throw new LogicException('Unexpected request to ' . $url),
        };

        $response = ($this->routes[$name])($url, $args);

        return is_string($response) ? new WP_Error('http_error', $response) : $response;
    };

    Functions\when('wp_remote_get')->alias(fn (string $url, array $args = []) => $route('GET', $url, $args));
    Functions\when('wp_remote_post')->alias(fn (string $url, array $args = []) => $route('POST', $url, $args));
    Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $r): int => $r['code']);
    Functions\when('wp_remote_retrieve_body')->alias(static fn (array $r): string => $r['body']);
    Functions\when('wp_remote_retrieve_header')->alias(static fn (array $r, string $h): string => $r['headers'][$h] ?? '');
    Functions\when('wp_generate_uuid4')->justReturn('throwaway-client');
    Functions\when('wp_hash_password')->alias(static fn (string $p): string => 'hashed:' . $p);
    Functions\when('wp_parse_url')->alias(static fn (string $url, int $component = -1): mixed => parse_url($url, $component));
    Functions\when('current_user_can')->alias(fn (): bool => $this->capable);
    Functions\when('check_ajax_referer')->alias(function (string $action): int {
        $this->nonceChecks[] = $action;

        return 1;
    });
    Functions\when('wp_send_json_success')->alias(static function (array $payload = []): never {
        throw new ConnectiontestResponded('success', $payload, null);
    });
    Functions\when('wp_send_json_error')->alias(static function (array $payload = [], ?int $status = null): never {
        throw new ConnectiontestResponded('error', $payload, $status);
    });
});

afterEach(function (): void {
    $_COOKIE = [];
    Settings::forget();
});

describe('the entry point', function (): void {
    it('hooks the admin-ajax action for signed-in users', function (): void {
        $hooked = [];
        Functions\when('add_action')->alias(static function (string $hook, callable $callback) use (&$hooked): void {
            $hooked[] = $hook;
        });

        (new ConnectionTest())->register();

        expect($hooked)->toBe(['wp_ajax_mcp_connector_test']);
    });

    it('refuses a user without manage_options before it looks at the nonce', function (): void {
        $this->capable = false;

        $answer = connectiontestRun();

        expect($answer['kind'])->toBe('error')
            ->and($answer['status'])->toBe(403)
            ->and($this->nonceChecks)->toBe([])
            ->and($this->http)->toBe([]);
    });

    it('verifies the nonce named after the action', function (): void {
        connectiontestRun();

        expect($this->nonceChecks)->toBe([ConnectionTest::ACTION]);
    });

    it('has no chain to walk when the OAuth provider is off', function (): void {
        $this->setSettings(['oauth_enabled' => false]);

        $answer = connectiontestRun();

        expect($answer['kind'])->toBe('error')
            ->and($answer['payload']['steps'])->toBe([])
            ->and($answer['payload']['message'])->toContain('turned off')
            ->and($this->http)->toBe([]);
    });
});

describe('a healthy chain', function (): void {
    it('passes every step, registers over HTTP and removes what it created', function (): void {
        $answer = connectiontestRun();

        expect($answer['kind'])->toBe('success')
            ->and(array_unique(array_values(connectiontestStates($answer['payload']))))->toBe(['pass'])
            ->and(array_keys(connectiontestStates($answer['payload'])))->toBe([
                'Discovery documents answer',
                'Application registers',
                'Consent screen renders',
                'Approval issues a code',
                'Code exchanges for a token',
                'MCP transport accepts the token',
                'Tool list comes back',
                'Temporary application removed',
            ])
            ->and($answer['payload']['message'])->toContain('whole chain works')
            ->and(connectiontestDetail($answer['payload'], 'MCP transport accepts the token'))->toBe('Handshake completed with Example MCP.')
            ->and(connectiontestDetail($answer['payload'], 'Tool list comes back'))->toBe('2 tools published.')
            ->and(connectiontestDetail($answer['payload'], 'Code exchanges for a token'))->toContain('3600')
            ->and($this->optionValues[ClientRepository::OPTION] ?? [])->toBe([]);
    });

    it('creates the client directly when self-registration is closed', function (): void {
        $this->setSettings(['oauth_enabled' => true, 'dynamic_registration_open' => false]);

        $answer = connectiontestRun();

        $registerCalls = array_filter($this->http, static fn (array $call): bool => $call['url'] === Endpoints::register());

        expect($answer['kind'])->toBe('success')
            ->and($registerCalls)->toBe([])
            ->and(connectiontestDetail($answer['payload'], 'Application registers'))->toContain('Self-registration is closed')
            ->and($this->optionValues[ClientRepository::OPTION] ?? [])->toBe([]);
    });

    it('forwards the session id of the handshake and the bearer token to the tool call', function (): void {
        connectiontestRun();

        $calls = array_values(array_filter($this->http, static fn (array $call): bool => $call['url'] === ServerRegistry::endpointUrl()));
        $methods = array_map(static fn (array $call): string => json_decode($call['args']['body'], true)['method'], $calls);
        $list = $calls[array_search('tools/list', $methods, true)]['args']['headers'];
        $notification = json_decode($calls[array_search('notifications/initialized', $methods, true)]['args']['body'], true);

        expect($methods)->toBe(['initialize', 'notifications/initialized', 'tools/list'])
            ->and($list['Mcp-Session-Id'])->toBe('session-1')
            ->and($list['Authorization'])->toBe('Bearer tok')
            ->and($notification)->not->toHaveKey('id')
            ->and($calls[0]['args']['headers'])->not->toHaveKey('Mcp-Session-Id');
    });

    it('forwards only the sign-in cookie, and never follows a redirect', function (): void {
        $_COOKIE = [LOGGED_IN_COOKIE => 'user|123|sig', 'other_plugin_cookie' => 'private'];

        connectiontestRun();

        $consent = array_values(array_filter($this->http, static fn (array $call): bool => $call['verb'] === 'GET' && str_contains($call['url'], 'authorize')))[0];

        expect($consent['args']['headers']['Cookie'])->toBe(LOGGED_IN_COOKIE . '=user|123|sig')
            ->and($consent['args']['redirection'])->toBe(0);
    });

    it('reads a handshake answered as a server-sent event', function (): void {
        $this->routes['initialize'] = static fn (): array => connectiontestResponse(
            200,
            "event: message\ndata: {\"result\":{\"serverInfo\":{}}}\n\n",
            ['mcp-session-id' => 's'],
        );
        $this->routes['tools'] = static fn (): array => connectiontestResponse(200, "data: {\"result\":{\"tools\":[{\"name\":\"only\"}]}}\n");

        $answer = connectiontestRun();

        expect($answer['kind'])->toBe('success')
            ->and(connectiontestDetail($answer['payload'], 'MCP transport accepts the token'))->toBe('Handshake completed.')
            ->and(connectiontestDetail($answer['payload'], 'Tool list comes back'))->toBe('1 tool published.');
    });
});

describe('a broken chain', function (): void {
    it('stops at the failing step, skips the rest and still cleans up', function (string $route, array|string $response, string $failing, string $detail): void {
        $this->routes[$route] = static fn (): array|string => is_array($response) ? connectiontestResponse(...$response) : $response;

        $answer = connectiontestRun();
        $states = connectiontestStates($answer['payload']);
        $labels = array_keys($states);
        $failedAt = array_search($failing, $labels, true);

        expect($answer['kind'])->toBe('error')
            ->and($answer['payload']['message'])->toBe('Stopped at: ' . $failing . '.')
            ->and($states[$failing])->toBe('fail')
            ->and(connectiontestDetail($answer['payload'], $failing))->toContain($detail)
            ->and(array_slice($states, 0, $failedAt))->each->toBe('pass')
            ->and(array_slice($states, $failedAt + 1, -1))->each->toBe('skipped')
            ->and($states['Temporary application removed'])->toBe('pass');
    })->with([
        'discovery blocked' => ['discovery', [403], 'Discovery documents answer', '403 from'],
        'discovery unreachable' => ['discovery', 'no route to host', 'Discovery documents answer', 'could not be reached'],
        'registration transport error' => ['register', 'timed out', 'Application registers', 'timed out'],
        'registration refused' => ['register', [400, ['error_description' => 'redirect_uri rejected']], 'Application registers', '400 — redirect_uri rejected'],
        'registration without a client id' => ['register', [201, '{}'], 'Application registers', '201, with nothing usable'],
        'consent transport error' => ['consent', 'timed out', 'Consent screen renders', 'timed out'],
        'consent redirects to login' => ['consent', [302, '', ['location' => 'https://example.test/wp-login.php']], 'Consent screen renders', 'wp-login.php'],
        'consent server error' => ['consent', [500, 'boom'], 'Consent screen renders', '500, with nothing usable'],
        'consent without a form' => ['consent', [200, '<p>hello</p>'], 'Consent screen renders', 'no approval form'],
        'approval transport error' => ['approve', 'timed out', 'Approval issues a code', 'timed out'],
        'approval without a redirect' => ['approve', [200, ''], 'Approval issues a code', 'answered 200 with no redirect'],
        'approval refused' => ['approve', [302, '', ['location' => 'http://127.0.0.1/x?error=access_denied&error_description=nope']], 'Approval issues a code', 'access_denied: nope'],
        'approval without a code' => ['approve', [302, '', ['location' => 'http://127.0.0.1/x?state=a1b2c3d4a1b2c3d4']], 'Approval issues a code', 'no authorization code'],
        'state altered' => ['approve', [302, '', ['location' => 'http://127.0.0.1/x?code=c&state=altered']], 'Approval issues a code', 'state parameter came back altered'],
        'token transport error' => ['token', 'timed out', 'Code exchanges for a token', 'timed out'],
        'token refused' => ['token', [400, ['error' => 'invalid_grant', 'error_description' => 'bad verifier']], 'Code exchanges for a token', '400 — bad verifier'],
        'initialize transport error' => ['initialize', 'timed out', 'MCP transport accepts the token', 'timed out'],
        'initialize unauthorised' => ['initialize', [401], 'MCP transport accepts the token', '401 from the MCP endpoint'],
        'initialize forbidden' => ['initialize', [403], 'MCP transport accepts the token', '403 from the MCP endpoint'],
        'initialize not mounted' => ['initialize', [404], 'MCP transport accepts the token', 'No server is mounted'],
        'initialize json-rpc error' => ['initialize', [200, ['error' => ['message' => 'Unsupported version']]], 'MCP transport accepts the token', '200 — Unsupported version'],
        'initialize unreadable' => ['initialize', [200, 'not json'], 'MCP transport accepts the token', '200, with nothing usable'],
        'tools transport error' => ['tools', 'timed out', 'Tool list comes back', 'timed out'],
        'tools json-rpc error' => ['tools', [200, ['error' => ['message' => 'Not initialised']]], 'Tool list comes back', 'Not initialised'],
        'tools without a list' => ['tools', [200, ['result' => []]], 'Tool list comes back', 'no tool list'],
        'tools list empty' => ['tools', [200, ['result' => ['tools' => []]]], 'Tool list comes back', 'empty tool list'],
    ]);

    it('says the site could not reach itself when the request never completed', function (): void {
        $this->routes['token'] = static fn (): array => connectiontestResponse(0);

        $answer = connectiontestRun();

        expect(connectiontestDetail($answer['payload'], 'Code exchanges for a token'))->toContain('could not reach itself');
    });

    it('creates nothing, and says so, when the very first step fails', function (): void {
        $this->routes['discovery'] = static fn (): array => connectiontestResponse(404);

        $answer = connectiontestRun();

        expect(connectiontestDetail($answer['payload'], 'Temporary application removed'))->toContain('Nothing was created')
            ->and($this->http)->each->toHaveKey('url');
    });

    it('reports a client it could not remove, since that one holds a live token', function (): void {
        $this->routes['tools'] = function (): array {
            (new ClientRepository())->delete('throwaway-client');

            return connectiontestResponse(200, ['result' => ['tools' => [['name' => 'a']]]]);
        };
        $this->routes['register'] = function (): array {
            $created = (new ClientRepository())->create('Registered', ['http://127.0.0.1/x']);

            return connectiontestResponse(201, ['client_id' => $created['client']->id, 'client_secret' => $created['secret']]);
        };

        $answer = connectiontestRun();
        $states = connectiontestStates($answer['payload']);

        expect($answer['kind'])->toBe('error')
            ->and($states['Temporary application removed'])->toBe('fail')
            ->and(connectiontestDetail($answer['payload'], 'Temporary application removed'))->toContain('Could not remove throwaway-client')
            ->and($answer['payload']['message'])->toBe('Stopped at: Temporary application removed.');
    });
});

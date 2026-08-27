<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Admin;

use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\Endpoints;
use Pollora\McpConnector\OAuth\Pkce;
use Pollora\McpConnector\Server\ServerRegistry;
use Pollora\McpConnector\Settings;
use Pollora\McpConnector\Support\Environment;

defined('ABSPATH') || exit;

/**
 * Walks the whole chain the way a client does, and reports where it stops.
 *
 * The screen's checks establish what can be established from PHP: whether the
 * dependencies are there, whether the discovery documents answer. What they
 * cannot establish is whether the seven steps *compose* — whether the code the
 * authorization endpoint issues is one the token endpoint will accept, whether
 * the token it returns is one the MCP transport will honour, whether the
 * capability holds all the way through. Every one of those has broken in
 * practice, and each of them presents to the client as the same generic failure.
 *
 * So this does the real thing: registers an application, authorises it as the
 * administrator running the test, redeems the code, and calls the endpoint with
 * the resulting token. Nothing is simulated. That has a price, and the price is
 * that the run creates a client and a live token, so it deletes both when it
 * finishes — on the failure path as well as the success path.
 *
 * Two details make it possible at all:
 *
 * The requests are loopback HTTP rather than direct calls into the controllers.
 * That is the whole point: a direct call would prove the PHP works while saying
 * nothing about the web server in front of it, which is where the failure
 * usually is.
 *
 * The authorization step forwards the administrator's own session cookies to
 * that loopback request. It has to — the consent screen is a page a signed-in
 * user approves, and there is no signed-in user in a request the server makes to
 * itself. Forging a session with {@see wp_generate_auth_cookie()} would work and
 * would also leave a real session token behind; passing on the one already in
 * this request does not.
 */
final class ConnectionTest
{
    /**
     * Admin-ajax action name, also used as the nonce action.
     *
     * @var string
     */
    public const ACTION = 'mcp_connector_test';

    /**
     * Redirect URI registered for the throwaway client.
     *
     * A loopback address, and never actually followed: the run reads the code
     * out of the `Location` header rather than chasing it. Loopback because
     * RFC 8252 blesses it and the registration endpoint therefore accepts it on
     * a site that is itself served over plain HTTP — which local installs are.
     *
     * @var string
     */
    private const REDIRECT_URI = 'http://127.0.0.1/mcp-connector/self-test';

    /**
     * The protocol version the test announces.
     *
     * @var string
     */
    private const PROTOCOL_VERSION = '2025-06-18';

    /**
     * Results accumulated by the run.
     *
     * @var list<array{key: string, label: string, state: string, detail: string, ms: int}>
     */
    private array $steps = [];

    /**
     * Identifier of the throwaway client, once it exists.
     */
    private string $clientId = '';

    /**
     * Secret of the throwaway client, when it was issued one.
     */
    private string $clientSecret = '';

    /**
     * The PKCE verifier this run will redeem its code with.
     */
    private string $verifier = '';

    /**
     * The authorization request parameters, carried through the consent form.
     *
     * @var array<string, string>
     */
    private array $params = [];

    /**
     * The authorization code, once issued.
     */
    private string $code = '';

    /**
     * The access token, once issued.
     */
    private string $accessToken = '';

    /**
     * The MCP session identifier handed back by `initialize`.
     */
    private string $sessionId = '';

    /**
     * Attach the admin-ajax handler.
     */
    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, $this->handle(...));
    }

    /**
     * Run the test and answer with its report.
     */
    public function handle(): void
    {
        // Capability first: somebody who may not do this should be told so,
        // rather than told their nonce expired.
        if (! current_user_can('manage_options')) {
            wp_send_json_error(
                ['message' => __('You are not allowed to run this test.', 'amphibee-mcp-connector')],
                403
            );
        }

        check_ajax_referer(self::ACTION);

        $settings = Settings::current();

        if (! $settings->oauthEnabled) {
            wp_send_json_error([
                'message' => __('The built-in OAuth provider is turned off, so there is no chain to walk. Turn it on under Security, or authenticate some other way.', 'amphibee-mcp-connector'),
                'steps' => [],
            ]);
        }

        $this->run($settings);

        $failed = array_filter($this->steps, static fn (array $s): bool => $s['state'] === 'fail');

        $payload = [
            'steps' => array_map(
                static fn (array $s): array => [
                    'label' => $s['label'],
                    'state' => $s['state'],
                    'detail' => $s['detail'],
                    'ms' => $s['ms'],
                ],
                $this->steps
            ),
            'message' => $failed === []
                ? __('The whole chain works. A client given the URL will connect.', 'amphibee-mcp-connector')
                : sprintf(
                    /* translators: %s: the label of the first failing step. */
                    __('Stopped at: %s.', 'amphibee-mcp-connector'),
                    (string) (reset($failed)['label'] ?? '')
                ),
        ];

        if ($failed === []) {
            wp_send_json_success($payload);
        }

        wp_send_json_error($payload);
    }

    /**
     * Execute every step, stopping at the first failure.
     *
     * @param Settings $settings The current configuration.
     */
    private function run(Settings $settings): void
    {
        $sequence = [
            ['discovery', __('Discovery documents answer', 'amphibee-mcp-connector'), $this->stepDiscovery(...)],
            ['register', __('Application registers', 'amphibee-mcp-connector'), fn (): array => $this->stepRegister($settings)],
            ['consent', __('Consent screen renders', 'amphibee-mcp-connector'), $this->stepConsent(...)],
            ['approve', __('Approval issues a code', 'amphibee-mcp-connector'), $this->stepApprove(...)],
            ['token', __('Code exchanges for a token', 'amphibee-mcp-connector'), $this->stepToken(...)],
            ['initialize', __('MCP transport accepts the token', 'amphibee-mcp-connector'), $this->stepInitialize(...)],
            ['tools', __('Tool list comes back', 'amphibee-mcp-connector'), $this->stepTools(...)],
        ];

        $stopped = false;

        foreach ($sequence as [$key, $label, $callable]) {
            if ($stopped) {
                $this->steps[] = [
                    'key' => $key,
                    'label' => $label,
                    'state' => 'skipped',
                    'detail' => __('Not reached.', 'amphibee-mcp-connector'),
                    'ms' => 0,
                ];

                continue;
            }

            $started = microtime(true);
            [$ok, $detail] = $callable();
            $elapsed = (int) round((microtime(true) - $started) * 1000);

            $this->steps[] = [
                'key' => $key,
                'label' => $label,
                'state' => $ok ? 'pass' : 'fail',
                'detail' => $detail,
                'ms' => $elapsed,
            ];

            $stopped = ! $ok;
        }

        $this->cleanUp();
    }

    // -- Steps --

    /**
     * Both discovery documents answer with JSON.
     *
     * Probed afresh rather than read from the cached status: the point of the
     * test is what is true now.
     *
     * @return array{0: bool, 1: string} Whether it passed, and what to say.
     */
    private function stepDiscovery(): array
    {
        $statuses = Environment::discoveryDocumentStatuses(true);
        $broken = array_filter($statuses, static fn (int $status): bool => $status !== 200);

        if ($broken === []) {
            return [true, __('Both are served at their well-known paths.', 'amphibee-mcp-connector')];
        }

        $url = (string) array_key_first($broken);
        $status = (int) reset($broken);

        return [
            false,
            $status === 0
                ? sprintf(
                    /* translators: %s: the discovery document URL. */
                    __('%s could not be reached from the site itself, so the rest of this test cannot run either. That may only mean the server has no route back to its own hostname.', 'amphibee-mcp-connector'),
                    $url
                )
                : sprintf(
                    /* translators: 1: HTTP status, 2: the discovery document URL. */
                    __('%1$d from %2$s. The web server is blocking /.well-known/; see the fix on the dashboard.', 'amphibee-mcp-connector'),
                    $status,
                    $url
                ),
        ];
    }

    /**
     * A client can be registered.
     *
     * Uses the registration endpoint when self-registration is open, because
     * that is the path a real client takes. When it is closed the endpoint is
     * *supposed* to refuse, so refusing is not a failure — the run creates the
     * client the way an administrator would instead, and says so.
     *
     * @param Settings $settings The current configuration.
     *
     * @return array{0: bool, 1: string} Whether it passed, and what to say.
     */
    private function stepRegister(Settings $settings): array
    {
        if (! $settings->dynamicRegistrationOpen) {
            $created = (new ClientRepository())->create(
                name: __('Connection test (temporary)', 'amphibee-mcp-connector'),
                redirectUris: [self::REDIRECT_URI],
            );

            $this->clientId = $created['client']->id;
            $this->clientSecret = $created['secret'];

            return [
                true,
                __('Self-registration is closed, so the test created the client directly — which is what you would do by hand for a client that cannot register itself.', 'amphibee-mcp-connector'),
            ];
        }

        $response = wp_remote_post(Endpoints::register(), self::requestArguments([
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode([
                'client_name' => __('Connection test (temporary)', 'amphibee-mcp-connector'),
                'redirect_uris' => [self::REDIRECT_URI],
                'token_endpoint_auth_method' => 'client_secret_post',
            ]) ?: '',
        ]));

        if (is_wp_error($response)) {
            return [false, $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status !== 201 || ! is_array($body) || ! isset($body['client_id'])) {
            return [false, self::describeHttpFailure($status, $body)];
        }

        $this->clientId = (string) $body['client_id'];
        $this->clientSecret = (string) ($body['client_secret'] ?? '');

        return [
            true,
            sprintf(
                /* translators: %s: the client identifier. */
                __('Registered as %s. It is deleted at the end of this run.', 'amphibee-mcp-connector'),
                $this->clientId
            ),
        ];
    }

    /**
     * The authorization endpoint renders a consent screen to a signed-in user.
     *
     * This is the step that proves the endpoint is served in front of the REST
     * API rather than inside it. In a REST callback the cookies forwarded here
     * would be discarded — `rest_cookie_check_errors()` calls
     * `wp_set_current_user(0)` for any cookie-bearing REST request without a
     * `wp_rest` nonce — and the response would be a redirect to the login page.
     *
     * @return array{0: bool, 1: string} Whether it passed, and what to say.
     */
    private function stepConsent(): array
    {
        $this->verifier = self::randomVerifier();

        $this->params = [
            'client_id' => $this->clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'code_challenge' => Pkce::challengeFor($this->verifier),
            'code_challenge_method' => Pkce::METHOD,
            'scope' => 'mcp',
            'state' => wp_generate_password(16, false),
        ];

        $response = wp_remote_get(
            add_query_arg(array_map('rawurlencode', $this->params), Endpoints::authorize()),
            self::requestArguments(['headers' => ['Cookie' => self::sessionCookies()]])
        );

        if (is_wp_error($response)) {
            return [false, $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status === 302 || $status === 301) {
            return [
                false,
                sprintf(
                    /* translators: %s: the URL the endpoint redirected to. */
                    __('The endpoint redirected to %s instead of rendering the form. The forwarded session was not recognised, which usually means a cache or a proxy in front of the site stripped the cookies.', 'amphibee-mcp-connector'),
                    (string) wp_remote_retrieve_header($response, 'location')
                ),
            ];
        }

        if ($status !== 200) {
            return [false, self::describeHttpFailure($status, null)];
        }

        if (preg_match('/name="_wpnonce"\s+value="([^"]+)"/', $body, $matches) !== 1) {
            return [
                false,
                __('The endpoint answered, but the response carries no approval form. Something is rendering a page other than the consent screen at that address.', 'amphibee-mcp-connector'),
            ];
        }

        $this->params['_wpnonce'] = $matches[1];

        return [true, __('The form rendered, and the forwarded session was recognised.', 'amphibee-mcp-connector')];
    }

    /**
     * Approving the request issues an authorization code.
     *
     * @return array{0: bool, 1: string} Whether it passed, and what to say.
     */
    private function stepApprove(): array
    {
        $response = wp_remote_post(Endpoints::authorize(), self::requestArguments([
            'headers' => ['Cookie' => self::sessionCookies()],
            'body' => $this->params + ['mcpc_decision' => 'approve'],
        ]));

        if (is_wp_error($response)) {
            return [false, $response->get_error_message()];
        }

        $location = (string) wp_remote_retrieve_header($response, 'location');
        $status = (int) wp_remote_retrieve_response_code($response);

        if ($location === '') {
            return [
                false,
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('The approval answered %d with no redirect. An expired form nonce reports itself this way.', 'amphibee-mcp-connector'),
                    $status
                ),
            ];
        }

        $query = [];
        parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);

        if (isset($query['error'])) {
            return [
                false,
                sprintf(
                    /* translators: 1: OAuth error code, 2: the description returned with it. */
                    __('The endpoint refused with %1$s: %2$s', 'amphibee-mcp-connector'),
                    (string) $query['error'],
                    (string) ($query['error_description'] ?? '')
                ),
            ];
        }

        if (! isset($query['code']) || ! is_string($query['code'])) {
            return [false, __('The redirect carries no authorization code.', 'amphibee-mcp-connector')];
        }

        // The state a client sends must come back untouched. Checking it here is
        // not ceremony: this is the parameter that once carried an unencoded
        // ampersand straight into the redirect.
        if ((string) ($query['state'] ?? '') !== $this->params['state']) {
            return [false, __('The state parameter came back altered.', 'amphibee-mcp-connector')];
        }

        $this->code = $query['code'];

        return [true, __('A single-use code was issued and returned on the redirect.', 'amphibee-mcp-connector')];
    }

    /**
     * The code exchanges for an access token.
     *
     * @return array{0: bool, 1: string} Whether it passed, and what to say.
     */
    private function stepToken(): array
    {
        $response = wp_remote_post(Endpoints::token(), self::requestArguments([
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $this->code,
                'redirect_uri' => self::REDIRECT_URI,
                'code_verifier' => $this->verifier,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]));

        if (is_wp_error($response)) {
            return [false, $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status !== 200 || ! is_array($body) || ! isset($body['access_token'])) {
            return [false, self::describeHttpFailure($status, $body)];
        }

        $this->accessToken = (string) $body['access_token'];

        return [
            true,
            sprintf(
                /* translators: %d: token lifetime in seconds. */
                __('A bearer token was issued, valid for %d seconds.', 'amphibee-mcp-connector'),
                (int) ($body['expires_in'] ?? 0)
            ),
        ];
    }

    /**
     * The MCP transport accepts the token and opens a session.
     *
     * @return array{0: bool, 1: string} Whether it passed, and what to say.
     */
    private function stepInitialize(): array
    {
        $response = $this->callMcp('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'mcp-connector-self-test', 'version' => '1.0'],
        ]);

        if (is_wp_error($response)) {
            return [false, $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status === 401 || $status === 403) {
            return [
                false,
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('%d from the MCP endpoint with a token that was just issued. The user this grant belongs to does not hold the required capability, or a security layer is stripping the Authorization header before PHP sees it.', 'amphibee-mcp-connector'),
                    $status
                ),
            ];
        }

        if ($status === 404) {
            return [
                false,
                __('404 from the MCP endpoint. No server is mounted there: either the MCP Adapter is inactive, or every ability group is turned off, in which case no server is created at all.', 'amphibee-mcp-connector'),
            ];
        }

        $decoded = self::decodeMcp((string) wp_remote_retrieve_body($response));

        if ($status !== 200 || $decoded === null || isset($decoded['error'])) {
            return [false, self::describeHttpFailure($status, $decoded)];
        }

        $this->sessionId = (string) wp_remote_retrieve_header($response, 'mcp-session-id');

        // Announcing the handshake is complete. A notification carries no
        // identifier and expects no answer, so nothing here is checked; a server
        // that does not require it simply ignores it.
        $this->callMcp('notifications/initialized', []);

        $server = $decoded['result']['serverInfo']['name'] ?? '';

        return [
            true,
            is_string($server) && $server !== ''
                ? sprintf(
                    /* translators: %s: the MCP server name. */
                    __('Handshake completed with %s.', 'amphibee-mcp-connector'),
                    $server
                )
                : __('Handshake completed.', 'amphibee-mcp-connector'),
        ];
    }

    /**
     * The tool list comes back, and is not empty.
     *
     * @return array{0: bool, 1: string} Whether it passed, and what to say.
     */
    private function stepTools(): array
    {
        $response = $this->callMcp('tools/list', (object) []);

        if (is_wp_error($response)) {
            return [false, $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = self::decodeMcp((string) wp_remote_retrieve_body($response));

        if ($status !== 200 || $decoded === null || isset($decoded['error'])) {
            return [false, self::describeHttpFailure($status, $decoded)];
        }

        $tools = $decoded['result']['tools'] ?? null;

        if (! is_array($tools)) {
            return [false, __('The answer carries no tool list.', 'amphibee-mcp-connector')];
        }

        if ($tools === []) {
            return [
                false,
                __('The endpoint answered with an empty tool list. A client would connect and find nothing to call.', 'amphibee-mcp-connector'),
            ];
        }

        return [
            true,
            sprintf(
                /* translators: %d: number of tools. */
                _n('%d tool published.', '%d tools published.', count($tools), 'amphibee-mcp-connector'),
                count($tools)
            ),
        ];
    }

    /**
     * Delete everything the run created.
     *
     * Reported as its own step rather than done silently, because a run that
     * left a working client and a live token behind and did not say so would be
     * worse than one that failed.
     */
    private function cleanUp(): void
    {
        $started = microtime(true);

        if ($this->clientId === '') {
            $this->steps[] = [
                'key' => 'cleanup',
                'label' => __('Temporary application removed', 'amphibee-mcp-connector'),
                'state' => 'pass',
                'detail' => __('Nothing was created, so there is nothing to remove.', 'amphibee-mcp-connector'),
                'ms' => 0,
            ];

            return;
        }

        // Deleting the client revokes its tokens with it, which is what puts the
        // access token this run obtained out of use.
        $deleted = (new ClientRepository())->delete($this->clientId);

        $this->steps[] = [
            'key' => 'cleanup',
            'label' => __('Temporary application removed', 'amphibee-mcp-connector'),
            'state' => $deleted ? 'pass' : 'fail',
            'detail' => $deleted
                ? __('The client and every token issued to it during this run are gone.', 'amphibee-mcp-connector')
                : sprintf(
                    /* translators: %s: the client identifier. */
                    __('Could not remove %s. Delete it under Applications — it holds a live token until you do.', 'amphibee-mcp-connector'),
                    $this->clientId
                ),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    // -- Internals --

    /**
     * Send one JSON-RPC call to the MCP endpoint.
     *
     * @param string             $method The JSON-RPC method.
     * @param array<mixed>|object $params The method parameters.
     *
     * @return array<string, mixed>|\WP_Error The HTTP response.
     */
    private function callMcp(string $method, array|object $params): array|\WP_Error
    {
        $payload = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];

        // A notification is a call without an identifier, and that absence is
        // what tells the server not to answer it.
        if (! str_starts_with($method, 'notifications/')) {
            $payload['id'] = wp_rand(1, 100000);
        }

        $headers = [
            'Content-Type' => 'application/json',
            // The streamable HTTP transport may answer either way; saying we
            // accept both is what lets it choose.
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer ' . $this->accessToken,
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
        ];

        if ($this->sessionId !== '') {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        return wp_remote_post(ServerRegistry::endpointUrl(), self::requestArguments([
            'headers' => $headers,
            'body' => wp_json_encode($payload) ?: '',
        ]));
    }

    /**
     * Read a JSON-RPC answer, whether it arrived as JSON or as a single event.
     *
     * @param string $body The response body.
     *
     * @return array<string, mixed>|null The decoded message, or null when there is none.
     */
    private static function decodeMcp(string $body): ?array
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // An event stream: the payload is on the `data:` lines. Only the first
        // message matters here — every call this class makes is a single
        // request with a single answer.
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $decoded = json_decode(trim(substr($line, 5)), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Arguments shared by every request the test makes.
     *
     * @param array<string, mixed> $arguments Request-specific arguments.
     *
     * @return array<string, mixed> The merged arguments.
     */
    private static function requestArguments(array $arguments): array
    {
        return $arguments + [
            'timeout' => 15,
            // Redirects are the answer here, not a detour to be followed: the
            // authorization code arrives in a Location header.
            'redirection' => 0,
            // A site whose certificate does not validate against its own
            // loopback address is a local-development norm, and refusing to
            // reach it would make the test useless exactly where it is most
            // often needed.
            'sslverify' => false,
        ];
    }

    /**
     * The administrator's own authentication cookies, as a request header.
     *
     * Only the cookies WordPress uses to establish who is signed in are
     * forwarded. Passing the whole jar on would send along whatever else the
     * browser happens to hold — including cookies belonging to other plugins —
     * to no purpose.
     *
     * @return string The `Cookie` header value.
     */
    private static function sessionCookies(): string
    {
        $wanted = array_filter([
            defined('LOGGED_IN_COOKIE') ? LOGGED_IN_COOKIE : null,
            defined('SECURE_AUTH_COOKIE') ? SECURE_AUTH_COOKIE : null,
            defined('AUTH_COOKIE') ? AUTH_COOKIE : null,
        ]);

        $pairs = [];

        foreach ($wanted as $name) {
            if (! isset($_COOKIE[$name]) || ! is_string($_COOKIE[$name])) {
                continue;
            }

            // Read raw: this value is a signature over its own contents, and
            // sanitising it would break the signature it exists to carry.
            $pairs[] = $name . '=' . $_COOKIE[$name]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        return implode('; ', $pairs);
    }

    /**
     * A random PKCE verifier of the length RFC 7636 asks for.
     *
     * @return string The verifier.
     */
    private static function randomVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    /**
     * Say what came back, preferring the server's own words.
     *
     * @param int        $status HTTP status code.
     * @param mixed      $body   The decoded response body, when there was one.
     *
     * @return string The explanation.
     */
    private static function describeHttpFailure(int $status, mixed $body): string
    {
        if (is_array($body)) {
            $description = $body['error_description']
                ?? $body['message']
                ?? ($body['error']['message'] ?? null);

            if (is_string($description) && $description !== '') {
                return sprintf(
                    /* translators: 1: HTTP status code, 2: the message returned by the server. */
                    __('%1$d — %2$s', 'amphibee-mcp-connector'),
                    $status,
                    $description
                );
            }
        }

        if ($status === 0) {
            return __('The request did not complete. The site could not reach itself over HTTP.', 'amphibee-mcp-connector');
        }

        return sprintf(
            /* translators: %d: HTTP status code. */
            __('%d, with nothing usable in the body.', 'amphibee-mcp-connector'),
            $status
        );
    }
}

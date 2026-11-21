<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth\Controller;

use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\OAuth\ClientLimitReachedException;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\Settings;

defined('ABSPATH') || exit;

/**
 * Dynamic client registration, per RFC 7591.
 *
 * This endpoint is unauthenticated, which is what the specification intends and
 * what makes "paste a URL into Claude and press connect" work at all: the client
 * has no credentials yet, so it cannot present any.
 *
 * Registering a client grants nothing. It creates an identifier that can ask a
 * user for consent — and the consent screen, the capability check behind it and
 * PKCE are what actually decide whether anything happens. Sites that would still
 * rather not leave it open can turn it off and create clients by hand from the
 * settings screen.
 *
 * Being open, it is also bounded: a rate limit per address, and a ceiling on the
 * clients that register themselves (see ClientRepository), so that a loop of
 * requests cannot grow the option every OAuth request reads.
 */
final class RegistrationController
{
    /**
     * Longest client name accepted.
     *
     * The name is rendered on the consent screen, where an over-long or
     * multi-line value would be used to push the actual permissions being
     * granted out of view.
     */
    private const int MAX_NAME_LENGTH = 100;

    /**
     * Most redirect URIs accepted for one client.
     */
    private const int MAX_REDIRECT_URIS = 10;

    /**
     * Filter on the registration rate limit, per IP address: `['limit' => 20, 'window' => 3600]`.
     * A limit of zero switches it off.
     */
    public const string RATE_LIMIT_FILTER = 'mcp_connector_registration_rate_limit';

    /**
     * Registrations one address may attempt per window, by default.
     */
    private const int DEFAULT_RATE_LIMIT = 20;

    /**
     * Length of the rate limit window in seconds, by default.
     */
    private const int DEFAULT_RATE_WINDOW = HOUR_IN_SECONDS;

    /**
     * Transient key prefix of the per-address counters.
     */
    private const string RATE_PREFIX = 'mcp_connector_reg_rate_';

    /**
     * @param Settings         $settings Resolved plugin configuration.
     * @param ClientRepository $clients  Client store.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ClientRepository $clients,
    ) {
    }

    /**
     * Register a client and return its credentials.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The registration request.
     *
     * @return \WP_REST_Response The registration response, or an OAuth error.
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        if (! $this->settings->dynamicRegistrationOpen) {
            return self::error(
                'access_denied',
                __('This site does not accept self-registration. An administrator must create the client.', 'amphibee-mcp-connector'),
                403,
            );
        }

        $retryAfter = $this->rateLimited();

        if ($retryAfter > 0) {
            $response = self::error(
                'temporarily_unavailable',
                __('Too many registrations from this address. Try again later.', 'amphibee-mcp-connector'),
                429,
            );
            $response->header('Retry-After', (string) $retryAfter);

            return $response;
        }

        /** @var array<string, mixed> $body */
        $body = $request->get_json_params() ?? [];

        $redirectUris = $this->readRedirectUris($body);

        if ($redirectUris === []) {
            return self::error(
                'invalid_redirect_uri',
                __('At least one valid https redirect URI is required.', 'amphibee-mcp-connector'),
            );
        }

        $authMethod = $this->readAuthMethod($body);

        try {
            $created = $this->clients->create(
                name: $this->readName($body),
                redirectUris: $redirectUris,
                authMethod: $authMethod,
                selfRegistered: true,
            );
        } catch (ClientLimitReachedException) {
            return self::error(
                'temporarily_unavailable',
                __('This site cannot register more clients right now. Try again later, or ask an administrator to create one.', 'amphibee-mcp-connector'),
                503,
            );
        }

        $client = $created['client'];

        $response = [
            'client_id' => $client->id,
            'client_id_issued_at' => $client->createdAt,
            'client_name' => $client->name,
            'redirect_uris' => $client->redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $client->authMethod,
        ];

        if ($created['secret'] !== '') {
            $response['client_secret'] = $created['secret'];
            // Zero means "does not expire". The secret is stored only as a hash,
            // so there is no rotation mechanism to promise a expiry against.
            $response['client_secret_expires_at'] = 0;
        }

        return self::json($response, 201);
    }

    /**
     * Count this attempt against the caller's address.
     *
     * The address is `REMOTE_ADDR`, the only one a client cannot choose: behind a
     * proxy, the site must restore the real one (as WordPress hosts do) for the
     * limit to tell visitors apart. The counter is a transient, so the limit is
     * approximate under concurrency, and an object-cache flush resets it.
     *
     * @return int Seconds to wait when the limit is exceeded, zero when the attempt may go on.
     */
    private function rateLimited(): int
    {
        $config = apply_filters(self::RATE_LIMIT_FILTER, ['limit' => self::DEFAULT_RATE_LIMIT, 'window' => self::DEFAULT_RATE_WINDOW]);
        $limit = is_array($config) && is_numeric($config['limit'] ?? null) ? (int) $config['limit'] : self::DEFAULT_RATE_LIMIT;
        $window = is_array($config) && is_numeric($config['window'] ?? null) ? max(1, (int) $config['window']) : self::DEFAULT_RATE_WINDOW;

        if ($limit <= 0) {
            return 0;
        }

        // The address only feeds a hash, it is never output or stored as is.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $address = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $key = self::RATE_PREFIX . hash('sha256', $address);
        $now = time();
        $stored = get_transient($key);
        $count = is_array($stored) ? (int) ($stored['count'] ?? 0) : 0;
        $resetAt = is_array($stored) ? (int) ($stored['reset_at'] ?? 0) : 0;

        if ($resetAt <= $now) {
            $count = 0;
            $resetAt = $now + $window;
        }

        if ($count >= $limit) {
            return max(1, $resetAt - $now);
        }

        set_transient($key, ['count' => $count + 1, 'reset_at' => $resetAt], max(1, $resetAt - $now));

        return 0;
    }

    /**
     * Extract and validate the requested redirect URIs.
     *
     * Only absolute https URLs are accepted, with one exception: loopback http
     * addresses, which RFC 8252 section 7.3 blesses for native applications that
     * listen on a local port and cannot obtain a certificate.
     *
     * @param array<string, mixed> $body The registration request body.
     *
     * @return list<string> The accepted redirect URIs.
     */
    private function readRedirectUris(array $body): array
    {
        // A URI is a string. Anything else, such as a nested array a client sent by
        // mistake or on purpose, is not one, and casting it would only raise a
        // PHP warning on an endpoint that needs no authentication.
        $candidates = array_slice(
            array_values(array_filter((array) ($body['redirect_uris'] ?? []), is_string(...))),
            0,
            self::MAX_REDIRECT_URIS,
        );

        $accepted = [];

        foreach ($candidates as $uri) {
            $parts = wp_parse_url($uri);

            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                continue;
            }

            // A fragment on a redirect URI is forbidden by RFC 6749 section 3.1.2,
            // and is also how a code gets appended somewhere it will not be read.
            if (isset($parts['fragment'])) {
                continue;
            }

            if ($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && self::isLoopbackHost($parts['host']))) {
                $accepted[] = $uri;
            }
        }

        return array_values(array_unique($accepted));
    }

    /**
     * Whether a URL host is a loopback address.
     *
     * An IPv6 host comes out of the URL parser with its brackets (`[::1]`), so
     * it is compared as an address rather than as a string: that also accepts
     * the long form of the loopback, and refuses a bracketed name that is not
     * an address, such as `[localhost]`.
     *
     * @param string $host The host part of a redirect URI.
     *
     * @return bool True for 127.0.0.1, localhost and the IPv6 loopback.
     */
    private static function isLoopbackHost(string $host): bool
    {
        if (in_array($host, ['127.0.0.1', 'localhost'], true)) {
            return true;
        }

        if (! str_starts_with($host, '[') || ! str_ends_with($host, ']')) {
            return false;
        }

        $address = substr($host, 1, -1);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        return inet_pton($address) === inet_pton('::1');
    }

    /**
     * Extract the client name, falling back to something recognisable.
     *
     * @param array<string, mixed> $body The registration request body.
     *
     * @return string The client name.
     */
    private function readName(array $body): string
    {
        $raw = $body['client_name'] ?? '';
        $name = sanitize_text_field(is_scalar($raw) ? (string) $raw : '');
        $name = trim(mb_substr($name, 0, self::MAX_NAME_LENGTH));

        return $name !== '' ? $name : __('Unnamed MCP client', 'amphibee-mcp-connector');
    }

    /**
     * Extract the requested token endpoint authentication method.
     *
     * @param array<string, mixed> $body The registration request body.
     *
     * @return string One of the supported methods.
     */
    private function readAuthMethod(array $body): string
    {
        $requested = $body['token_endpoint_auth_method'] ?? 'client_secret_post';

        $supported = ['client_secret_post', 'client_secret_basic', Client::AUTH_METHOD_NONE];

        return is_string($requested) && in_array($requested, $supported, true) ? $requested : 'client_secret_post';
    }

    /**
     * Build a JSON response that nothing will cache.
     *
     * @param array<string, mixed> $body   The response body.
     * @param int                  $status HTTP status code.
     *
     * @return \WP_REST_Response The response.
     */
    private static function json(array $body, int $status): \WP_REST_Response
    {
        $response = new \WP_REST_Response($body, $status);

        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Build an OAuth-shaped error response.
     *
     * @param string $code        The OAuth error code.
     * @param string $description Human-readable explanation.
     * @param int    $status      HTTP status code.
     *
     * @return \WP_REST_Response The response.
     */
    private static function error(string $code, string $description, int $status = 400): \WP_REST_Response
    {
        return self::json(['error' => $code, 'error_description' => $description], $status);
    }
}

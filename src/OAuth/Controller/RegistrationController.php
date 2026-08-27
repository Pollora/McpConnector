<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth\Controller;

use Pollora\McpConnector\OAuth\Client;
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
 */
final class RegistrationController
{
    /**
     * Longest client name accepted.
     *
     * The name is rendered on the consent screen, where an over-long or
     * multi-line value would be used to push the actual permissions being
     * granted out of view.
     *
     * @var int
     */
    private const MAX_NAME_LENGTH = 100;

    /**
     * Most redirect URIs accepted for one client.
     *
     * @var int
     */
    private const MAX_REDIRECT_URIS = 10;

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

        $created = $this->clients->create(
            name: $this->readName($body),
            redirectUris: $redirectUris,
            authMethod: $authMethod,
            selfRegistered: true,
        );

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
        $candidates = array_slice(
            array_map('strval', (array) ($body['redirect_uris'] ?? [])),
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

            $isLoopback = in_array($parts['host'], ['127.0.0.1', '::1', 'localhost'], true);

            if ($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && $isLoopback)) {
                $accepted[] = $uri;
            }
        }

        return array_values(array_unique($accepted));
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
        $name = sanitize_text_field((string) ($body['client_name'] ?? ''));
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
        $requested = (string) ($body['token_endpoint_auth_method'] ?? 'client_secret_post');

        $supported = ['client_secret_post', 'client_secret_basic', Client::AUTH_METHOD_NONE];

        return in_array($requested, $supported, true) ? $requested : 'client_secret_post';
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

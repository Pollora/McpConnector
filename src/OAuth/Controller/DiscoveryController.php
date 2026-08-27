<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth\Controller;

use Pollora\McpConnector\OAuth\Endpoints;
use Pollora\McpConnector\OAuth\Pkce;
use Pollora\McpConnector\OAuth\Scope;
use Pollora\McpConnector\Server\ServerRegistry;
use Pollora\McpConnector\Settings;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * Serves the two metadata documents a client reads before it can connect.
 *
 * A remote MCP client is given one URL — the MCP endpoint — and has to work
 * everything else out from there. It calls the endpoint, gets a 401 naming the
 * protected resource document, reads that to find the authorization server, and
 * reads the server's own document to find where to register and where to send
 * the user. Every field below is part of that chain; getting one wrong strands
 * the client at that step with nothing useful to report.
 */
final class DiscoveryController
{
    /**
     * @param Settings $settings Resolved plugin configuration.
     */
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * The protected resource metadata document, per RFC 9728.
     *
     * Names the MCP endpoint being protected and points at the authorization
     * server that guards it.
     *
     * @return WP_REST_Response The metadata document.
     */
    public function protectedResource(): WP_REST_Response
    {
        return self::respond([
            'resource' => ServerRegistry::endpointUrl(),
            'authorization_servers' => [Endpoints::issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => Scope::supported(),
            'resource_documentation' => Endpoints::issuer(),
        ]);
    }

    /**
     * The authorization server metadata document, per RFC 8414.
     *
     * @return WP_REST_Response The metadata document.
     */
    public function authorizationServer(): WP_REST_Response
    {
        $metadata = [
            'issuer' => Endpoints::issuer(),
            'authorization_endpoint' => Endpoints::authorize(),
            'token_endpoint' => Endpoints::token(),
            'revocation_endpoint' => Endpoints::revoke(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            // S256 only. Advertising `plain` as well would let a client choose
            // the variant in which the challenge and the verifier are the same
            // string, which is no protection at all.
            'code_challenge_methods_supported' => [Pkce::METHOD],
            'token_endpoint_auth_methods_supported' => [
                'client_secret_post',
                'client_secret_basic',
                'none',
            ],
            'scopes_supported' => Scope::supported(),
        ];

        // Only advertised when open: a client that reads this field and finds an
        // endpoint will use it, and will report a bare 403 as a broken server
        // rather than as a deliberate policy.
        if ($this->settings->dynamicRegistrationOpen) {
            $metadata['registration_endpoint'] = Endpoints::register();
        }

        return self::respond($metadata);
    }

    /**
     * Wrap a metadata document in a response that nothing will cache.
     *
     * These documents change when the site's URL or configuration changes, and a
     * client that holds a stale copy fails in ways that are very hard to see
     * from the outside.
     *
     * @param array<string, mixed> $metadata The document.
     *
     * @return WP_REST_Response The response.
     */
    private static function respond(array $metadata): WP_REST_Response
    {
        $response = new WP_REST_Response($metadata, 200);

        $response->header('Cache-Control', 'no-store');
        // These are read cross-origin by browser-based clients.
        $response->header('Access-Control-Allow-Origin', '*');

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

use Pollora\McpConnector\Http\HttpResponse;
use Pollora\McpConnector\OAuth\Controller\AuthorizationController;
use Pollora\McpConnector\OAuth\Controller\DiscoveryController;
use Pollora\McpConnector\OAuth\Controller\RegistrationController;
use Pollora\McpConnector\OAuth\Controller\TokenController;
use Pollora\McpConnector\Server\ServerRegistry;
use Pollora\McpConnector\Settings;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * Wires the OAuth 2.1 provider into WordPress.
 *
 * Front-end paths — the two `.well-known` documents and the authorization
 * endpoint — are matched on `parse_request` against the raw request path rather
 * than through rewrite rules. Rewrite rules would be the idiomatic choice, but
 * they only work once they have been flushed, and a rewrite flush that silently
 * did not happen is a failure mode that presents as "the connector cannot find
 * the authorization server" with nothing in any log. Matching the path directly
 * has no such state: it works on the first request after the plugin is copied
 * onto a server, which is exactly how this tends to be deployed.
 *
 * Machine-facing endpoints are ordinary REST routes; see {@see Endpoints} for
 * why the authorization endpoint cannot be.
 */
final class OAuthServer
{
    /**
     * @param Settings $settings Resolved plugin configuration.
     */
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Attach every hook the provider needs.
     */
    public function register(): void
    {
        (new BearerAuthenticator(new TokenRepository()))->register();

        add_action('parse_request', $this->routeFrontEnd(...));
        add_action('rest_api_init', $this->registerRestRoutes(...));
        add_filter('rest_post_dispatch', $this->decorateResponse(...), 10, 3);
    }

    /**
     * Serve the front-end OAuth paths, if this request is one of them.
     *
     * @param \WP $wp The WordPress environment, unused but supplied by the action.
     */
    public function routeFrontEnd(\WP $wp): void
    {
        unset($wp);

        $path = self::requestPath();

        $response = match ($path) {
            Endpoints::PROTECTED_RESOURCE_PATH => self::asHttpResponse(
                $this->discovery()->protectedResource(),
            ),
            Endpoints::SERVER_METADATA_PATH => self::asHttpResponse(
                $this->discovery()->authorizationServer(),
            ),
            Endpoints::AUTHORIZE_PATH => $this->authorization()->handle(),
            default => null,
        };

        $response?->send();
    }

    /**
     * Register the machine-facing REST routes.
     */
    public function registerRestRoutes(): void
    {
        register_rest_route(Endpoints::REST_NAMESPACE, '/token', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => fn (\WP_REST_Request $request): \WP_REST_Response => $this->token()->handle($request),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(Endpoints::REST_NAMESPACE, '/revoke', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => fn (\WP_REST_Request $request): \WP_REST_Response => $this->token()->revoke($request),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(Endpoints::REST_NAMESPACE, '/register', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => fn (\WP_REST_Request $request): \WP_REST_Response => $this->registration()->handle($request),
            'permission_callback' => '__return_true',
        ]);

        // Mirrors of the two discovery documents. Some hosts intercept
        // `/.well-known/` before PHP sees it — it is where ACME challenges live,
        // and more than one control panel maps it to a static directory. These
        // give a client something to fall back on, and give an administrator
        // something to test against when the canonical paths return the wrong thing.
        register_rest_route(Endpoints::REST_NAMESPACE, '/protected-resource', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => fn (): \WP_REST_Response => $this->discovery()->protectedResource(),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(Endpoints::REST_NAMESPACE, '/server-metadata', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => fn (): \WP_REST_Response => $this->discovery()->authorizationServer(),
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Add the headers that let a client discover how to authenticate.
     *
     * Two jobs. On a refused MCP request it attaches the RFC 9728
     * `WWW-Authenticate` challenge naming the protected resource document —
     * this is the thread a client pulls to find the authorization server, and
     * without it the only recourse is guessing at well-known paths. On the
     * OAuth routes it opens up CORS, since WordPress restricts REST responses to
     * same-origin by default and browser-based clients would otherwise be unable
     * to read a token response.
     *
     * @param mixed                                 $result  The response being returned.
     * @param \WP_REST_Server                        $server  The REST server, unused.
     * @param \WP_REST_Request<array<string, mixed>> $request The dispatched request.
     *
     * @return mixed The response, possibly with headers added.
     */
    public function decorateResponse(mixed $result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        unset($server);

        if (! $result instanceof \WP_REST_Response) {
            return $result;
        }

        $route = ltrim($request->get_route(), '/');

        if (str_starts_with($route, Endpoints::REST_NAMESPACE . '/')) {
            $result->header('Access-Control-Allow-Origin', '*');
            $result->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $result->header('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        }

        $isMcpRoute = str_starts_with($route, ServerRegistry::ROUTE_NAMESPACE . '/');
        $isRefusal = in_array($result->get_status(), [401, 403], true);

        if ($isMcpRoute && $isRefusal) {
            $result->header('WWW-Authenticate', sprintf(
                'Bearer realm="%s", resource_metadata="%s"',
                esc_url_raw(Endpoints::issuer()),
                esc_url_raw(Endpoints::protectedResourceMetadata()),
            ));
        }

        return $result;
    }

    /**
     * The requested path, relative to the WordPress installation root.
     *
     * Derived from `REQUEST_URI` rather than from query vars, because the point
     * is to match before WordPress has resolved the request into anything. The
     * home path is stripped so this keeps working for an installation in a
     * subdirectory.
     *
     * ⚠️ Unslashed, but deliberately **not** passed through
     * `sanitize_text_field()`. That function strips every `%XX` sequence it
     * finds, and a request URI is percent-encoded by definition — a path
     * containing one would silently stop matching, and the OAuth endpoints
     * would answer 404 for a reason nothing would explain. What stands in for
     * sanitisation is what follows: the value is reduced to its path component,
     * decoded, trimmed, and then compared against a fixed set of literal route
     * names. Nothing arbitrary survives that comparison.
     *
     * @return string The path, with no leading or trailing slash.
     */
    private static function requestPath(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See the note above: sanitising would corrupt the percent-encoding; the value is matched against literal routes.
        $raw = wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? ''));

        $uri = (string) wp_parse_url($raw, PHP_URL_PATH);
        $uri = trim(rawurldecode($uri), '/');

        $base = trim((string) wp_parse_url((string) home_url(), PHP_URL_PATH), '/');

        if ($base !== '' && str_starts_with($uri, $base . '/')) {
            $uri = substr($uri, strlen($base) + 1);
        }

        return $uri;
    }

    /**
     * Flatten a REST response into one that can be sent from the front end.
     *
     * The discovery documents are served from both places and built once, in
     * `WP_REST_Response` form; this renders that form for the front-end path.
     *
     * @param \WP_REST_Response $response The REST response.
     *
     * @return HttpResponse The equivalent front-end response.
     */
    private static function asHttpResponse(\WP_REST_Response $response): HttpResponse
    {
        $body = (string) wp_json_encode($response->get_data());

        return HttpResponse::json($body, $response->get_status(), $response->get_headers());
    }

    /**
     * Build the discovery controller.
     *
     * Controllers are built per call rather than held as properties: all but one
     * request to this site will touch none of them, and constructing four
     * objects plus their repositories on every page load to serve an endpoint
     * that is hit twice a month is not a trade worth making.
     *
     * @return DiscoveryController The controller.
     */
    private function discovery(): DiscoveryController
    {
        return new DiscoveryController($this->settings);
    }

    /**
     * Build the authorization controller.
     *
     * @return AuthorizationController The controller.
     */
    private function authorization(): AuthorizationController
    {
        return new AuthorizationController(
            $this->settings,
            new ClientRepository(),
            new AuthorizationCodeRepository(),
            new ConsentScreen(),
        );
    }

    /**
     * Build the token controller.
     *
     * @return TokenController The controller.
     */
    private function token(): TokenController
    {
        return new TokenController(
            $this->settings,
            new ClientRepository(),
            new AuthorizationCodeRepository(),
            new TokenRepository(),
        );
    }

    /**
     * Build the registration controller.
     *
     * @return RegistrationController The controller.
     */
    private function registration(): RegistrationController
    {
        return new RegistrationController($this->settings, new ClientRepository());
    }
}

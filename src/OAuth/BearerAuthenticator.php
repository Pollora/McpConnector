<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

defined('ABSPATH') || exit;

/**
 * Resolves a bearer token into the WordPress user it acts for.
 *
 * This is what makes the MCP endpoint work: the adapter's transport and every
 * ability's permission callback ask `current_user_can()`, and this filter is
 * what puts a user behind that question when the request arrived with an OAuth
 * token instead of a cookie.
 *
 * It runs on every request, so it does as little as possible until it has seen
 * an `Authorization: Bearer` header — reading it is a superglobal lookup, and
 * anything without one returns before touching the database.
 */
final class BearerAuthenticator
{
    /**
     * REST namespaces a request authenticated by a bearer token alone may reach.
     *
     * The MCP endpoint, and the core abilities endpoints, whose abilities all
     * pass through the scope gate. Anything else on the REST API is refused.
     */
    private const array TOKEN_NAMESPACES = ['mcp', 'wp-abilities'];

    /**
     * Whether the user of this request was resolved from a bearer token alone,
     * with no login cookie behind it.
     */
    private bool $byTokenAlone = false;

    /**
     * @param TokenRepository $tokens Token store.
     */
    public function __construct(private readonly TokenRepository $tokens)
    {
    }

    /**
     * Attach the authentication filter.
     */
    public function register(): void
    {
        // Priority 20, after the core cookie resolution at 10: a request that
        // already has a signed-in user keeps that user, and only has the
        // scope of a token issued to the same user applied to it.
        add_filter('determine_current_user', $this->authenticate(...), 20);

        // The scope of a token is only enforced by the abilities. Without this,
        // the same token would also open the rest of the REST API as that user,
        // wherever no scope is looked at: a read-only grant could create posts
        // or install plugins through `wp/v2`.
        add_filter('rest_pre_dispatch', $this->restrictRoute(...), 10, 3);
    }

    /**
     * Refuse a REST route outside the MCP endpoint to a request that only a bearer token authenticates.
     *
     * @param mixed            $result  The short-circuit value, null to carry on.
     * @param \WP_REST_Server  $server  The REST server.
     * @param \WP_REST_Request $request The request about to be dispatched.
     *
     * @return mixed The value passed in, or a 403 error.
     */
    public function restrictRoute(mixed $result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        unset($server);

        if (! $this->byTokenAlone || $result !== null) {
            return $result;
        }

        $route = ltrim($request->get_route(), '/');

        foreach (self::TOKEN_NAMESPACES as $namespace) {
            if ($route === $namespace || str_starts_with($route, $namespace . '/')) {
                return $result;
            }
        }

        return new \WP_Error(
            'rest_forbidden',
            __('An access token issued by the MCP connector only works with the MCP endpoint.', 'amphibee-mcp-connector'),
            ['status' => 403],
        );
    }

    /**
     * Resolve the current user from a bearer token, if there is one.
     *
     * A request that already has a signed-in user keeps that user. When it also
     * carries a valid token issued to the same user, the token's scope is still
     * recorded: a client that declared itself read-only must not write because a
     * cookie happened to be sent along. A token issued to someone else, or an
     * invalid one, changes nothing.
     *
     * @param int|false $userId The user identifier resolved so far, or false.
     *
     * @return int|false The resolved user identifier, or the value passed in.
     */
    public function authenticate(int|false $userId): int|false
    {
        $token = self::readBearerToken();

        if ($token === '') {
            return $userId;
        }

        $record = $this->tokens->findAccessToken($token);

        if ($record === null || $record['user_id'] <= 0) {
            return $userId;
        }

        if ($userId) {
            if ($record['user_id'] === (int) $userId) {
                Scope::setCurrent($record['scopes']);
            }

            return $userId;
        }

        // Record what the token is allowed to do, so the ability layer can
        // refuse writes to a read-only grant. Capabilities cannot express this:
        // the read abilities check `edit_posts` too, so removing write
        // capabilities would break reading as well.
        Scope::setCurrent($record['scopes']);
        $this->byTokenAlone = true;

        return $record['user_id'];
    }

    /**
     * Read the bearer token from the request.
     *
     * Several server configurations are covered because the `Authorization`
     * header is unusually fragile in shared hosting. Apache running PHP as CGI
     * or FastCGI drops it unless it is rewritten, which is what leaves it in
     * `REDIRECT_HTTP_AUTHORIZATION`; some stacks expose it only through
     * `apache_request_headers()`.
     *
     * @return string The token, or an empty string when the request carries none.
     */
    private static function readBearerToken(): string
    {
        $header = '';

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (! empty($_SERVER[$key])) {
                $header = sanitize_text_field(wp_unslash((string) $_SERVER[$key]));

                break;
            }
        }

        if ($header === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = (string) $value;

                    break;
                }
            }
        }

        if (stripos($header, 'Bearer ') !== 0) {
            return '';
        }

        return trim(substr($header, 7));
    }
}

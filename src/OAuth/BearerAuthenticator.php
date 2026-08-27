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
        // already has a signed-in user is left alone rather than overridden.
        add_filter('determine_current_user', $this->authenticate(...), 20);
    }

    /**
     * Resolve the current user from a bearer token, if there is one.
     *
     * @param int|false $userId The user identifier resolved so far, or false.
     *
     * @return int|false The resolved user identifier, or the value passed in.
     */
    public function authenticate(int|false $userId): int|false
    {
        if ($userId) {
            return $userId;
        }

        $token = self::readBearerToken();

        if ($token === '') {
            return $userId;
        }

        $record = $this->tokens->findAccessToken($token);

        if ($record === null || $record['user_id'] <= 0) {
            return $userId;
        }

        // Record what the token is allowed to do, so the ability layer can
        // refuse writes to a read-only grant. Capabilities cannot express this:
        // the read abilities check `edit_posts` too, so removing write
        // capabilities would break reading as well.
        Scope::setCurrent($record['scopes']);

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

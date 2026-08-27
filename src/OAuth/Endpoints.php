<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

defined('ABSPATH') || exit;

/**
 * The addresses that make up the OAuth provider.
 *
 * They are split across two mechanisms, for a reason that is not cosmetic.
 *
 * The token, registration and revocation endpoints are machine-to-machine POSTs
 * carrying no cookies, so they live in the REST API where they belong.
 *
 * The authorization endpoint cannot. It is a browser request that must recognise
 * a logged-in user, and WordPress's `rest_cookie_check_errors()` calls
 * `wp_set_current_user( 0 )` for any REST request that carries login cookies
 * without a `wp_rest` nonce — which is precisely the shape of a browser arriving
 * from a client's redirect. Inside a REST callback the visitor would appear
 * permanently logged out. It is therefore served as an ordinary front-end route.
 *
 * The two `.well-known` documents have no choice at all: RFC 8414 and RFC 9728
 * pin them to the site root.
 *
 * One consequence is worth recording for anyone deploying behind a CDN.
 * `/oauth/authorize` is a dotless GET path, which is exactly what a "cache HTML
 * pages" edge rule matches. It is only ever served to logged-in users, so a rule
 * that excludes session cookies — as most do — already skips it, and the
 * responses carry `no-store` besides. A rule with an explicit edge TTL overrides
 * origin headers, though, so on such a setup this path must be excluded
 * explicitly. A cached authorization redirect carries somebody else's one-time
 * code.
 */
final class Endpoints
{
    /**
     * REST namespace the OAuth routes are registered under.
     *
     * @var string
     */
    public const REST_NAMESPACE = 'mcp-connector/v1';

    /**
     * Path of the protected resource metadata document, relative to the site root.
     *
     * @var string
     */
    public const PROTECTED_RESOURCE_PATH = '.well-known/oauth-protected-resource';

    /**
     * Path of the authorization server metadata document, relative to the site root.
     *
     * @var string
     */
    public const SERVER_METADATA_PATH = '.well-known/oauth-authorization-server';

    /**
     * Path of the authorization endpoint, relative to the site root.
     *
     * @var string
     */
    public const AUTHORIZE_PATH = 'oauth/authorize';

    /**
     * Not instantiable: every member is a static factory.
     */
    private function __construct()
    {
    }

    /**
     * The issuer identifier, which is also the resource identifier.
     *
     * Must match the `issuer` field of the metadata document exactly, character
     * for character, or a conforming client will reject the discovery response.
     *
     * @return string The issuer URL, without a trailing slash.
     */
    public static function issuer(): string
    {
        return untrailingslashit(home_url());
    }

    /**
     * URL of the protected resource metadata document.
     *
     * @return string The absolute URL.
     */
    public static function protectedResourceMetadata(): string
    {
        return home_url('/' . self::PROTECTED_RESOURCE_PATH);
    }

    /**
     * URL of the authorization server metadata document.
     *
     * @return string The absolute URL.
     */
    public static function serverMetadata(): string
    {
        return home_url('/' . self::SERVER_METADATA_PATH);
    }

    /**
     * URL of the authorization endpoint, where the user grants consent.
     *
     * A front-end URL, not a REST one; see the class documentation.
     *
     * @return string The absolute URL.
     */
    public static function authorize(): string
    {
        return home_url('/' . self::AUTHORIZE_PATH);
    }

    /**
     * URL of the token endpoint.
     *
     * @return string The absolute URL.
     */
    public static function token(): string
    {
        return rest_url(self::REST_NAMESPACE . '/token');
    }

    /**
     * URL of the dynamic client registration endpoint.
     *
     * @return string The absolute URL.
     */
    public static function register(): string
    {
        return rest_url(self::REST_NAMESPACE . '/register');
    }

    /**
     * URL of the token revocation endpoint.
     *
     * @return string The absolute URL.
     */
    public static function revoke(): string
    {
        return rest_url(self::REST_NAMESPACE . '/revoke');
    }
}

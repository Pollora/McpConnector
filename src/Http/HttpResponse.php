<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Http;

defined('ABSPATH') || exit;

/**
 * A response the plugin sends directly, outside the REST API.
 *
 * The authorization endpoint is the one part of this provider a human looks at,
 * and it has to run as an ordinary front-end request rather than a REST route.
 * The reason is WordPress's own REST cookie handling: `rest_cookie_check_errors()`
 * calls `wp_set_current_user( 0 )` for any request that carries login cookies but
 * no `wp_rest` nonce. A browser arriving from the client's redirect has cookies
 * and no nonce, so inside a REST callback the visitor would appear permanently
 * logged out and the flow could never complete.
 *
 * Modelling the response as a value object rather than echoing keeps the
 * controller free of output, and confines `exit` to one place.
 *
 * @psalm-immutable
 */
final class HttpResponse
{
    /**
     * @param string                $body    Response body, already escaped by whoever built it.
     * @param int                   $status  HTTP status code.
     * @param array<string, string> $headers Response headers.
     */
    private function __construct(
        public readonly string $body,
        public readonly int $status,
        public readonly array $headers,
    ) {
    }

    /**
     * An HTML page.
     *
     * @param string $body   Fully-formed HTML document.
     * @param int    $status HTTP status code.
     *
     * @return self The response.
     */
    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, [
            'Content-Type' => 'text/html; charset=' . get_bloginfo('charset'),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            // Understood by CDNs that separate edge caching from browser
            // caching, and ignored harmlessly by those that do not.
            'CDN-Cache-Control' => 'no-store',
            // The consent screen must not be embeddable: framed inside another
            // page, a user can be induced to approve a grant they cannot see.
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * A JSON document.
     *
     * Used for the two discovery documents, which are built once as REST
     * responses and served from both the REST API and the `.well-known` paths
     * the specifications pin them to.
     *
     * @param string                $body    Encoded JSON.
     * @param int                   $status  HTTP status code.
     * @param array<string, string> $headers Headers carried over from the REST response.
     *
     * @return self The response.
     */
    public static function json(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers + [
            'Content-Type' => 'application/json; charset=' . get_bloginfo('charset'),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * A redirect.
     *
     * @param string $location Absolute URL to redirect to.
     *
     * @return self The response.
     */
    public static function redirect(string $location): self
    {
        return new self('', 302, [
            'Location' => $location,
            // This URL carries a one-time authorization code. Nothing, at any
            // layer, should keep a copy.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'CDN-Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * Send the response and stop.
     *
     * Terminating here is deliberate: this runs on `parse_request`, long before
     * a template would be chosen, and letting WordPress continue would append a
     * theme's output to an OAuth redirect.
     *
     */
    public function send(): never
    {
        status_header($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->body !== '') {
            echo $this->body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the builder.
        }

        exit;
    }
}

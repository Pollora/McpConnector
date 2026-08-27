<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth\Controller;

use Pollora\McpConnector\Http\HttpResponse;
use Pollora\McpConnector\OAuth\AuthorizationCodeRepository;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\ConsentScreen;
use Pollora\McpConnector\OAuth\Endpoints;
use Pollora\McpConnector\OAuth\Pkce;
use Pollora\McpConnector\OAuth\Scope;
use Pollora\McpConnector\Settings;

defined('ABSPATH') || exit;

/**
 * The authorization endpoint: where a user decides whether a client may act for them.
 *
 * Handles both halves of the interaction. A GET renders the consent screen; the
 * form posts back here, and the POST issues the code.
 *
 * Error handling splits along one line, which is the only subtle thing in this
 * class. Until the client identifier and redirect URI have both been verified
 * against the register, there is no address we are willing to send anything to,
 * so failures are shown to the user. Once they have been verified, failures go
 * back to the client as `error` parameters on the redirect, per RFC 6749
 * section 4.1.2.1 — otherwise the client sits waiting for a callback that never
 * arrives, and the user is looking at a page the client will never see.
 */
final class AuthorizationController
{
    /**
     * Request parameters carried through the consent form and back.
     *
     * @var list<string>
     */
    private const CARRIED_PARAMS = [
        'client_id',
        'redirect_uri',
        'response_type',
        'code_challenge',
        'code_challenge_method',
        'scope',
        'state',
    ];

    /**
     * @param Settings                    $settings Resolved plugin configuration.
     * @param ClientRepository            $clients  Client store.
     * @param AuthorizationCodeRepository $codes    Authorization code store.
     * @param ConsentScreen               $screen   Renderer for the pages shown to the user.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ClientRepository $clients,
        private readonly AuthorizationCodeRepository $codes,
        private readonly ConsentScreen $screen,
    ) {
    }

    /**
     * Handle an authorization request.
     *
     * @return HttpResponse The page to render or the redirect to follow.
     */
    public function handle(): HttpResponse
    {
        $isPost = strtoupper(sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')))) === 'POST';

        // phpcs:disable WordPress.Security.NonceVerification -- The GET branch
        // only reads parameters to render a form; the POST branch verifies the
        // nonce below, before anything is issued. Neither sniff can see that
        // far. The raw array goes straight to readParams(), which reads only a
        // fixed list of names, unslashes and trims each one, and hands them on
        // to the checks above: the client must be registered, the redirect URI
        // must match exactly, and the response type and PKCE method must be the
        // supported ones.
        $source = $isPost ? $_POST : $_GET;
        // phpcs:enable WordPress.Security.NonceVerification

        $params = $this->readParams($source);

        $client = $this->clients->find($params['client_id']);

        // Unverified client: nothing may be redirected anywhere.
        if (! $client instanceof Client) {
            return $this->screen->failure(
                __('Unknown application', 'amphibee-mcp-connector'),
                __('The application that sent you here is not registered on this site. Nothing has been granted.', 'amphibee-mcp-connector'),
            );
        }

        // Unverified redirect URI: likewise. Redirecting to an address that is
        // not on the client's register is how an authorization code ends up in
        // somebody else's hands.
        if (! $client->allowsRedirectUri($params['redirect_uri'])) {
            return $this->screen->failure(
                __('Invalid redirect address', 'amphibee-mcp-connector'),
                __('The address the application asked to be returned to is not one it registered. Nothing has been granted.', 'amphibee-mcp-connector'),
            );
        }

        // From here on the redirect URI is trusted, so errors can be reported to
        // the client the way the specification expects.

        if ($params['response_type'] !== 'code') {
            return $this->deny($params, 'unsupported_response_type', 'Only the authorization code flow is supported.');
        }

        if (! Pkce::isSupported($params['code_challenge'], $params['code_challenge_method'])) {
            return $this->deny(
                $params,
                'invalid_request',
                'A PKCE code_challenge with code_challenge_method=S256 is required.',
            );
        }

        if (! is_user_logged_in()) {
            // Back here afterwards, with every parameter intact — `scope` holds
            // a space, so it has to be encoded on the way through as well.
            return HttpResponse::redirect(
                wp_login_url(self::withQuery(Endpoints::authorize(), $params)),
            );
        }

        if (! current_user_can($this->settings->requiredCapability)) {
            return $this->deny(
                $params,
                'access_denied',
                'The signed-in user does not have permission to connect applications to this site.',
            );
        }

        $scopes = Scope::parse($params['scope']);

        if (! $isPost) {
            return $this->screen->approval($client, $scopes, $params);
        }

        // The nonce is what stops a third-party page from silently submitting
        // this form on a logged-in user's behalf. It is checked before the
        // decision is read, so a forged approval never reaches the issuing path.
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_POST['_wpnonce'])) : '';

        if (! wp_verify_nonce($nonce, ConsentScreen::NONCE_ACTION)) {
            return $this->screen->failure(
                __('Expired request', 'amphibee-mcp-connector'),
                __('This authorisation request has expired or was not started here. Return to the application and try connecting again.', 'amphibee-mcp-connector'),
                403,
            );
        }

        $decision = isset($_POST['mcpc_decision'])
            ? sanitize_key(wp_unslash((string) $_POST['mcpc_decision']))
            : 'deny';

        if ($decision !== 'approve') {
            return $this->deny($params, 'access_denied', 'The user declined the request.');
        }

        $code = $this->codes->issue(
            userId: get_current_user_id(),
            clientId: $client->id,
            redirectUri: $params['redirect_uri'],
            codeChallenge: $params['code_challenge'],
            scopes: $scopes,
        );

        return HttpResponse::redirect($this->callback($params, [
            'code' => $code,
            'state' => $params['state'],
        ]));
    }

    /**
     * Read and normalise the authorization request parameters.
     *
     * @param array<string, mixed> $source The request superglobal to read from.
     *
     * @return array<string, string> The carried parameters, every key present.
     */
    private function readParams(array $source): array
    {
        $params = [];

        foreach (self::CARRIED_PARAMS as $name) {
            $value = isset($source[$name]) ? wp_unslash($source[$name]) : '';

            $params[$name] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $params;
    }

    /**
     * Report a failure to the client, as redirect parameters.
     *
     * @param array<string, string> $params      The authorization request parameters.
     * @param string                $error       The OAuth error code.
     * @param string                $description Human-readable explanation, for the client's logs.
     *
     * @return HttpResponse The redirect.
     */
    private function deny(array $params, string $error, string $description): HttpResponse
    {
        return HttpResponse::redirect($this->callback($params, [
            'error' => $error,
            'error_description' => $description,
            'state' => $params['state'],
        ]));
    }

    /**
     * Build the redirect back to the client.
     *
     * `state` is echoed back only when the client sent one; adding an empty one
     * would make a client that checks for its absence fail.
     *
     * @param array<string, string> $params    The authorization request parameters.
     * @param array<string, string> $arguments Parameters to append to the redirect URI.
     *
     * @return string The absolute callback URL.
     */
    private function callback(array $params, array $arguments): string
    {
        return self::withQuery($params['redirect_uri'], array_filter(
            $arguments,
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * Append query parameters to a URL, encoding the values.
     *
     * `add_query_arg()` cannot be trusted to do this. It runs `urlencode_deep()`
     * over the URL's *existing* query string and only then merges in the new
     * arguments, which are handed to `build_query()` — and that calls
     * `_http_build_query()` with encoding switched off. Values passed in are
     * therefore emitted verbatim.
     *
     * That matters twice over here. An unencoded `error_description` puts raw
     * spaces in a `Location` header, which RFC 9110 does not permit. Worse, the
     * `state` value is chosen by the client and echoed back: one containing
     * `&code=…` would graft an extra parameter onto the redirect, and a client
     * whose parser takes the last occurrence would read the attacker's code
     * instead of ours.
     *
     * @param string                $url    Base URL, which may already carry a query string.
     * @param array<string, string> $params Parameters to append.
     *
     * @return string The URL with the parameters appended.
     */
    private static function withQuery(string $url, array $params): string
    {
        return add_query_arg(array_map('rawurlencode', $params), $url);
    }
}

<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth\Controller;

use Pollora\McpConnector\OAuth\AuthorizationCodeRepository;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\Pkce;
use Pollora\McpConnector\OAuth\TokenRepository;
use Pollora\McpConnector\Settings;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * The token endpoint: exchanges a code, or a refresh token, for an access token.
 *
 * Both grants converge on the same outcome, so both go through the same
 * issuance path. What differs is the proof required. The authorization code
 * grant proves possession of the PKCE verifier; the refresh grant proves
 * possession of a token that was previously issued, and consumes it in the
 * process so a stolen refresh token is good for at most one use before the
 * legitimate client's next refresh fails and the theft becomes visible.
 */
final class TokenController
{
    /**
     * @param Settings                    $settings Resolved plugin configuration.
     * @param ClientRepository            $clients  Client store.
     * @param AuthorizationCodeRepository $codes    Authorization code store.
     * @param TokenRepository             $tokens   Token store.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ClientRepository $clients,
        private readonly AuthorizationCodeRepository $codes,
        private readonly TokenRepository $tokens,
    ) {
    }

    /**
     * Handle a token request.
     *
     * @param WP_REST_Request<array<string, mixed>> $request The token request.
     *
     * @return WP_REST_Response The token response, or an OAuth error.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $params = $this->readParams($request);
        [$clientId, $clientSecret] = $this->readCredentials($request, $params);

        $client = $this->clients->find($clientId);

        if (! $client instanceof Client || ! $client->verifySecret($clientSecret)) {
            // One message for "no such client" and for "wrong secret": telling
            // them apart lets an attacker enumerate valid client identifiers.
            return self::error('invalid_client', __('Client authentication failed.', 'amphibee-mcp-connector'), 401);
        }

        return match ((string) ($params['grant_type'] ?? '')) {
            'authorization_code' => $this->exchangeCode($params, $client),
            'refresh_token' => $this->refresh($params, $client),
            default => self::error(
                'unsupported_grant_type',
                __('Supported grant types are authorization_code and refresh_token.', 'amphibee-mcp-connector')
            ),
        };
    }

    /**
     * Handle a revocation request, per RFC 7009.
     *
     * @param WP_REST_Request<array<string, mixed>> $request The revocation request.
     *
     * @return WP_REST_Response An empty 200, whatever the outcome.
     */
    public function revoke(WP_REST_Request $request): WP_REST_Response
    {
        $params = $this->readParams($request);

        $this->tokens->revoke((string) ($params['token'] ?? ''));

        // RFC 7009 section 2.2 requires 200 even for a token that was already
        // invalid or never existed. Distinguishing the cases would turn this
        // endpoint into an oracle for guessing valid tokens.
        return self::json([], 200);
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @param array<string, mixed> $params The request parameters.
     * @param Client               $client The authenticated client.
     *
     * @return WP_REST_Response The token response, or an OAuth error.
     */
    private function exchangeCode(array $params, Client $client): WP_REST_Response
    {
        $record = $this->codes->consume((string) ($params['code'] ?? ''));

        if ($record === null) {
            return self::error(
                'invalid_grant',
                __('The authorization code is unknown, expired, or has already been used.', 'amphibee-mcp-connector')
            );
        }

        if (! hash_equals($record['client_id'], $client->id)) {
            return self::error(
                'invalid_grant',
                __('The authorization code was issued to a different client.', 'amphibee-mcp-connector')
            );
        }

        // RFC 6749 section 4.1.3: when a redirect URI was used to obtain the
        // code, the same one must be presented here.
        if (! hash_equals($record['redirect_uri'], (string) ($params['redirect_uri'] ?? ''))) {
            return self::error(
                'invalid_grant',
                __('The redirect URI does not match the one used to obtain this code.', 'amphibee-mcp-connector')
            );
        }

        if (! Pkce::verify((string) ($params['code_verifier'] ?? ''), $record['code_challenge'])) {
            return self::error(
                'invalid_grant',
                __('The PKCE code verifier does not match the challenge.', 'amphibee-mcp-connector')
            );
        }

        return $this->issue($record['user_id'], $client->id, $record['scopes']);
    }

    /**
     * Exchange a refresh token for a new pair.
     *
     * @param array<string, mixed> $params The request parameters.
     * @param Client               $client The authenticated client.
     *
     * @return WP_REST_Response The token response, or an OAuth error.
     */
    private function refresh(array $params, Client $client): WP_REST_Response
    {
        $record = $this->tokens->consumeRefreshToken((string) ($params['refresh_token'] ?? ''));

        if ($record === null) {
            return self::error(
                'invalid_grant',
                __('The refresh token is unknown, expired, or has already been used.', 'amphibee-mcp-connector')
            );
        }

        if (! hash_equals($record['client_id'], $client->id)) {
            return self::error(
                'invalid_grant',
                __('The refresh token was issued to a different client.', 'amphibee-mcp-connector')
            );
        }

        return $this->issue($record['user_id'], $client->id, $record['scopes']);
    }

    /**
     * Issue tokens, having checked that the user is still entitled to them.
     *
     * The capability is re-checked on every refresh, not just at consent. A user
     * demoted or deleted a fortnight ago must not keep a working connector for
     * the remaining fortnight of their refresh token's life.
     *
     * @param int          $userId   The user the tokens act for.
     * @param string       $clientId The client the tokens are issued to.
     * @param list<string> $scopes   The granted scopes.
     *
     * @return WP_REST_Response The token response, or an OAuth error.
     */
    private function issue(int $userId, string $clientId, array $scopes): WP_REST_Response
    {
        if (! user_can($userId, $this->settings->requiredCapability)) {
            return self::error(
                'invalid_grant',
                __('The user this grant belongs to no longer has permission to use this connector.', 'amphibee-mcp-connector')
            );
        }

        $issued = $this->tokens->issue($userId, $clientId, $scopes);

        return self::json([
            'access_token' => $issued['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $issued['expires_in'],
            'refresh_token' => $issued['refresh_token'],
            'scope' => $issued['scope'],
        ], 200);
    }

    /**
     * Read the request body, accepting either form encoding or JSON.
     *
     * The specification mandates `application/x-www-form-urlencoded`, and most
     * clients comply, but enough send JSON that refusing it only produces
     * support requests.
     *
     * @param WP_REST_Request<array<string, mixed>> $request The request.
     *
     * @return array<string, mixed> The parameters.
     */
    private function readParams(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();

        if (is_array($json) && $json !== []) {
            return $json;
        }

        return $request->get_body_params() ?: $request->get_params();
    }

    /**
     * Extract the client credentials, from the body or from HTTP Basic auth.
     *
     * @param WP_REST_Request<array<string, mixed>> $request The request.
     * @param array<string, mixed>                  $params  The parsed body parameters.
     *
     * @return array{0: string, 1: string} The client identifier and secret.
     */
    private function readCredentials(WP_REST_Request $request, array $params): array
    {
        $id = (string) ($params['client_id'] ?? '');
        $secret = (string) ($params['client_secret'] ?? '');

        if ($id !== '') {
            return [$id, $secret];
        }

        $header = (string) $request->get_header('authorization');

        if (stripos($header, 'Basic ') !== 0) {
            return [$id, $secret];
        }

        $decoded = base64_decode(substr($header, 6), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return [$id, $secret];
        }

        // RFC 6749 section 2.3.1 requires both halves to be form-urlencoded
        // before being joined, so both have to be decoded after splitting.
        [$rawId, $rawSecret] = explode(':', $decoded, 2);

        return [urldecode($rawId), urldecode($rawSecret)];
    }

    /**
     * Build a JSON response that nothing will cache.
     *
     * @param array<string, mixed> $body   The response body.
     * @param int                  $status HTTP status code.
     *
     * @return WP_REST_Response The response.
     */
    private static function json(array $body, int $status): WP_REST_Response
    {
        $response = new WP_REST_Response($body, $status);

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
     * @return WP_REST_Response The response.
     */
    private static function error(string $code, string $description, int $status = 400): WP_REST_Response
    {
        $response = self::json(['error' => $code, 'error_description' => $description], $status);

        if ($status === 401) {
            $response->header('WWW-Authenticate', 'Basic realm="OAuth"');
        }

        return $response;
    }
}

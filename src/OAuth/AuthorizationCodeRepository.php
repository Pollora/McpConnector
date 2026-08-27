<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

defined('ABSPATH') || exit;

/**
 * Stores the short-lived codes handed back from the authorization endpoint.
 *
 * Unlike tokens, these do belong in transients: they live for five minutes, they
 * are consumed once, and an object-cache flush that loses one costs the user a
 * single retry of a flow they are actively standing in front of. In exchange,
 * expiry is handled for us and nothing accumulates.
 *
 * The code itself is never stored — only its SHA-256 digest — so the window in
 * which a database read could be turned into a token is closed as well.
 */
final class AuthorizationCodeRepository
{
    /**
     * Transient key prefix.
     *
     * @var string
     */
    private const PREFIX = 'mcp_connector_oauth_code_';

    /**
     * How long a code stays valid, in seconds.
     *
     * RFC 6749 section 4.1.2 recommends a maximum of ten minutes; five is ample
     * for a redirect the user is watching happen.
     *
     * @var int
     */
    private const TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * Issue a code binding a user's consent to one client and one PKCE challenge.
     *
     * @param int          $userId        User who granted consent.
     * @param string       $clientId      Client the code is for.
     * @param string       $redirectUri   Redirect URI the code will be delivered to.
     * @param string       $codeChallenge The PKCE S256 challenge to verify at the token endpoint.
     * @param list<string> $scopes        Scopes the user consented to.
     *
     * @return string The plaintext authorization code.
     */
    public function issue(
        int $userId,
        string $clientId,
        string $redirectUri,
        string $codeChallenge,
        array $scopes,
    ): string {
        $code = wp_generate_password(40, false);

        set_transient(self::key($code), [
            'user_id' => $userId,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'scopes' => $scopes,
        ], self::TTL);

        return $code;
    }

    /**
     * Redeem a code, deleting it so it cannot be presented twice.
     *
     * Deletion happens before any validation, so a code that fails PKCE
     * verification is still spent. An attacker who intercepts a code gets one
     * attempt at guessing the verifier, not unlimited ones.
     *
     * @param string $code The plaintext authorization code.
     *
     * @return array{user_id: int, client_id: string, redirect_uri: string, code_challenge: string, scopes: list<string>}|null
     *         The code record, or null when the code is unknown or expired.
     */
    public function consume(string $code): ?array
    {
        if ($code === '') {
            return null;
        }

        $key = self::key($code);
        $record = get_transient($key);

        delete_transient($key);

        if (! is_array($record)) {
            return null;
        }

        return [
            'user_id' => (int) ($record['user_id'] ?? 0),
            'client_id' => (string) ($record['client_id'] ?? ''),
            'redirect_uri' => (string) ($record['redirect_uri'] ?? ''),
            'code_challenge' => (string) ($record['code_challenge'] ?? ''),
            'scopes' => array_values(array_map('strval', (array) ($record['scopes'] ?? []))),
        ];
    }

    /**
     * Transient key for a code.
     *
     * @param string $code The plaintext code.
     *
     * @return string The transient key.
     */
    private static function key(string $code): string
    {
        return self::PREFIX . hash('sha256', $code);
    }
}

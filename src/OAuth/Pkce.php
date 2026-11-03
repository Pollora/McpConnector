<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

defined('ABSPATH') || exit;

/**
 * Proof Key for Code Exchange, as defined by RFC 7636.
 *
 * PKCE is what makes the authorization code flow safe for a client that cannot
 * keep a secret. The client sends the hash of a random verifier when it asks for
 * a code, and the verifier itself when it redeems one; an attacker who steals
 * the code in transit cannot use it without the verifier.
 *
 * Only the `S256` method is accepted. `plain` is permitted by the RFC and
 * defeats the entire mechanism, since the challenge and the verifier are then
 * the same value travelling over the same channel. OAuth 2.1 removes it.
 */
final class Pkce
{
    /**
     * The only challenge method this provider accepts.
     */
    public const string METHOD = 'S256';

    /**
     * Not instantiable: every member is a static helper.
     */
    private function __construct()
    {
    }

    /**
     * Whether a challenge and method pair is usable.
     *
     * @param string $challenge The code challenge from the authorization request.
     * @param string $method    The challenge method from the authorization request.
     *
     * @return bool True when the challenge can be verified later.
     */
    public static function isSupported(string $challenge, string $method): bool
    {
        // RFC 7636 section 4.2: an S256 challenge is the unpadded base64url of a
        // SHA-256 digest, so exactly 43 characters. Anything else is not a real
        // challenge, and it is stored with the code, so its size must be bounded.
        return $method === self::METHOD && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $challenge) === 1;
    }

    /**
     * Verify a code verifier against the challenge stored with the code.
     *
     * @param string $verifier  The verifier presented at the token endpoint.
     * @param string $challenge The challenge recorded at the authorization endpoint.
     *
     * @return bool True when the verifier produces the challenge.
     */
    public static function verify(string $verifier, string $challenge): bool
    {
        // RFC 7636 section 4.1: 43 to 128 characters of [A-Za-z0-9-._~].
        if (preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/', $verifier) !== 1 || $challenge === '') {
            return false;
        }

        return hash_equals($challenge, self::challengeFor($verifier));
    }

    /**
     * Derive the S256 challenge for a verifier.
     *
     * Base64url without padding, per RFC 7636 appendix A.
     *
     * @param string $verifier The code verifier.
     *
     * @return string The code challenge.
     */
    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}

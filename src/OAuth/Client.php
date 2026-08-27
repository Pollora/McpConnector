<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

defined('ABSPATH') || exit;

/**
 * A registered OAuth client.
 *
 * Secrets are stored as password hashes, never in the clear: the plaintext is
 * returned exactly once, in the registration response, and is unrecoverable
 * afterwards. A database dump therefore does not hand over the ability to
 * impersonate a registered client.
 *
 * @psalm-immutable
 */
final class Client
{
    /**
     * Authentication method for clients that hold no secret and rely on PKCE alone.
     *
     * @var string
     */
    public const AUTH_METHOD_NONE = 'none';

    /**
     * @param string       $id             Client identifier, generated at registration.
     * @param string       $secretHash     Hash of the client secret, or an empty string for public clients.
     * @param string       $name           Human-readable client name, shown on the consent screen.
     * @param list<string> $redirectUris   Exact URIs the authorization code may be delivered to.
     * @param string       $authMethod     How the client authenticates at the token endpoint: one of
     *                                     `client_secret_post`, `client_secret_basic` or `none`.
     * @param int          $createdAt      Unix timestamp of registration.
     * @param bool         $selfRegistered Whether the client registered itself through RFC 7591, as opposed
     *                                     to being created by an administrator.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $secretHash,
        public readonly string $name,
        public readonly array $redirectUris,
        public readonly string $authMethod,
        public readonly int $createdAt,
        public readonly bool $selfRegistered,
    ) {
    }

    /**
     * Rebuild a client from its stored representation.
     *
     * @param array<string, mixed> $stored The stored record.
     *
     * @return self The client.
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            id: (string) ($stored['id'] ?? ''),
            secretHash: (string) ($stored['secret_hash'] ?? ''),
            name: (string) ($stored['name'] ?? ''),
            redirectUris: array_values(array_map('strval', (array) ($stored['redirect_uris'] ?? []))),
            authMethod: (string) ($stored['auth_method'] ?? 'client_secret_post'),
            createdAt: (int) ($stored['created_at'] ?? 0),
            selfRegistered: (bool) ($stored['self_registered'] ?? false),
        );
    }

    /**
     * Render for storage.
     *
     * @return array<string, mixed> The storable record.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'secret_hash' => $this->secretHash,
            'name' => $this->name,
            'redirect_uris' => $this->redirectUris,
            'auth_method' => $this->authMethod,
            'created_at' => $this->createdAt,
            'self_registered' => $this->selfRegistered,
        ];
    }

    /**
     * Whether this client authenticates with a secret at the token endpoint.
     *
     * @return bool True when a secret must be presented and verified.
     */
    public function isConfidential(): bool
    {
        return $this->authMethod !== self::AUTH_METHOD_NONE && $this->secretHash !== '';
    }

    /**
     * Verify a presented client secret.
     *
     * @param string $secret The plaintext secret presented at the token endpoint.
     *
     * @return bool True when the secret matches, or when this is a public client.
     */
    public function verifySecret(string $secret): bool
    {
        if (! $this->isConfidential()) {
            return true;
        }

        return $secret !== '' && wp_check_password($secret, $this->secretHash);
    }

    /**
     * Whether a redirect URI was registered for this client.
     *
     * Comparison is exact, as RFC 6749 section 3.1.2 requires. Prefix matching
     * is what turns an open redirect elsewhere on the site into a stolen
     * authorization code.
     *
     * @param string $uri The redirect URI presented in the authorization request.
     *
     * @return bool True when the URI was registered.
     */
    public function allowsRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirectUris, true);
    }
}

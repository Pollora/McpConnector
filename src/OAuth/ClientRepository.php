<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

defined('ABSPATH') || exit;

/**
 * Stores registered OAuth clients in a single option.
 *
 * One option rather than one per client: the whole set is read on every token
 * request and listed on the settings screen, and the population is a handful of
 * entries, not a table. Autoloading is off, so the cost is one query on the
 * requests that actually need it and nothing on the rest.
 */
final class ClientRepository
{
    /**
     * Option holding the client map, keyed by client identifier.
     *
     * @var string
     */
    public const OPTION = 'mcp_connector_oauth_clients';

    /**
     * Register a new client and return it alongside its one-time plaintext secret.
     *
     * The secret is hashed before storage, so this is the only moment it can be
     * read. Callers must hand it to the client immediately or lose it.
     *
     * @param string       $name         Human-readable client name.
     * @param list<string> $redirectUris Exact redirect URIs to accept.
     * @param string       $authMethod   Token endpoint authentication method.
     * @param bool         $selfRegistered Whether this came in through RFC 7591.
     *
     * @return array{client: Client, secret: string} The stored client and its plaintext secret.
     */
    public function create(
        string $name,
        array $redirectUris,
        string $authMethod = 'client_secret_post',
        bool $selfRegistered = false,
    ): array {
        $secret = $authMethod === Client::AUTH_METHOD_NONE ? '' : wp_generate_password(48, false);

        $client = new Client(
            id: wp_generate_uuid4(),
            secretHash: $secret === '' ? '' : wp_hash_password($secret),
            name: $name,
            redirectUris: $redirectUris,
            authMethod: $authMethod,
            createdAt: time(),
            selfRegistered: $selfRegistered,
        );

        $clients = $this->allRaw();
        $clients[$client->id] = $client->toArray();

        update_option(self::OPTION, $clients, false);

        return ['client' => $client, 'secret' => $secret];
    }

    /**
     * Look up a client by identifier.
     *
     * @param string $clientId The client identifier.
     *
     * @return Client|null The client, or null when it is not registered.
     */
    public function find(string $clientId): ?Client
    {
        $clients = $this->allRaw();

        return isset($clients[$clientId]) && is_array($clients[$clientId])
            ? Client::fromArray($clients[$clientId])
            : null;
    }

    /**
     * Every registered client, newest first.
     *
     * @return list<Client> The registered clients.
     */
    public function all(): array
    {
        $clients = array_map(
            static fn (array $stored): Client => Client::fromArray($stored),
            array_filter($this->allRaw(), 'is_array'),
        );

        // usort() discards the client-identifier keys array_filter() preserved,
        // so what comes back is already a list. An array_values() here would be
        // a no-op that reads as if it were doing something.
        usort($clients, static fn (Client $a, Client $b): int => $b->createdAt <=> $a->createdAt);

        return $clients;
    }

    /**
     * Remove a client.
     *
     * Tokens issued to it are revoked at the same time: leaving them live would
     * mean deleting a client from the settings screen changed nothing about what
     * that client could still do for the next thirty days.
     *
     * @param string $clientId The client identifier.
     *
     * @return bool True when a client was removed.
     */
    public function delete(string $clientId): bool
    {
        $clients = $this->allRaw();

        if (! isset($clients[$clientId])) {
            return false;
        }

        unset($clients[$clientId]);

        update_option(self::OPTION, $clients, false);

        (new TokenRepository())->revokeClient($clientId);

        return true;
    }

    /**
     * The raw stored map.
     *
     * @return array<string, mixed> Client identifier to stored record.
     */
    private function allRaw(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }
}

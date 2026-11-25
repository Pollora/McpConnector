<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

use Pollora\McpConnector\Support\OptionLock;

defined('ABSPATH') || exit;

/**
 * Stores registered OAuth clients in a single option.
 *
 * One option rather than one per client: the whole set is read on every token
 * request and listed on the settings screen, and the population is a handful of
 * entries, not a table. Autoloading is off, so the cost is one query on the
 * requests that actually need it and nothing on the rest.
 *
 * That only holds while the population stays small, and an unauthenticated
 * registration endpoint can grow it without limit. So the clients that register
 * themselves are capped (`mcp_connector_max_registered_clients`, 100 by default),
 * the oldest that hold no live token being purged to make room, and the daily
 * sweep drops the ones that never connected. Every change to the option is made
 * under a lock, as for tokens, so concurrent registrations do not lose one another.
 */
final class ClientRepository
{
    /**
     * Option holding the client map, keyed by client identifier.
     */
    public const string OPTION = 'mcp_connector_oauth_clients';

    /**
     * Filter on the most clients that may register themselves, whatever their age.
     * Clients an administrator created by hand are not counted and never purged.
     */
    public const string MAX_FILTER = 'mcp_connector_max_registered_clients';

    /**
     * Default ceiling on self-registered clients.
     */
    public const int DEFAULT_MAX_SELF_REGISTERED = 100;

    /**
     * How long a self-registered client that holds no token is kept, in seconds.
     */
    public const int STALE_TTL = 7 * DAY_IN_SECONDS;

    /**
     * Name of the lock that serialises changes to the option.
     */
    private const string LOCK = 'clients';

    /**
     * @param OptionLock $lock Serialises the changes made to the client option.
     */
    public function __construct(private readonly OptionLock $lock = new OptionLock())
    {
    }

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
     *
     * @throws ClientLimitReachedException When a self-registering client finds the ceiling reached
     *                                     with nothing to purge, or the store busy.
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

        $max = self::maxSelfRegistered();

        $stored = $this->mutate(
            function (array $clients) use ($client, $selfRegistered, $max): array {
                if ($selfRegistered) {
                    $clients = $this->makeRoom($clients, $max);
                }

                $clients[$client->id] = $client->toArray();

                return $clients;
            },
            strict: $selfRegistered,
        );

        if (! $stored) {
            throw new ClientLimitReachedException('The client store is busy.');
        }

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
            Client::fromArray(...),
            array_filter($this->allRaw(), is_array(...)),
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
        $removed = false;

        $this->mutate(function (array $clients) use ($clientId, &$removed): array {
            $removed = isset($clients[$clientId]);
            unset($clients[$clientId]);

            return $clients;
        });

        if ($removed) {
            (new TokenRepository())->revokeClient($clientId);
        }

        return $removed;
    }

    /**
     * Drop the self-registered clients that never connected.
     *
     * A client is dropped when it registered itself, is older than `$maxAge`
     * seconds and holds no live token. Clients created by an administrator are
     * kept, and so is any client that is connected.
     *
     * @param int $maxAge Age in seconds past which a client without a token goes.
     *
     * @return int How many clients were removed.
     */
    public function purgeStale(int $maxAge = self::STALE_TTL): int
    {
        $removed = 0;
        $cutoff = time() - $maxAge;
        $connected = (new TokenRepository())->clientsWithLiveTokens();

        $this->mutate(static function (array $clients) use ($cutoff, $connected, &$removed): array {
            foreach ($clients as $id => $record) {
                if (! is_array($record)) {
                    continue;
                }

                if (($record['self_registered'] ?? false) === true && (int) ($record['created_at'] ?? 0) < $cutoff && ! isset($connected[(string) $id])) {
                    unset($clients[$id]);
                    $removed++;
                }
            }

            return $clients;
        });

        return $removed;
    }

    /**
     * The ceiling on self-registered clients, after the filter.
     *
     * @return int Zero or more.
     */
    public static function maxSelfRegistered(): int
    {
        $max = apply_filters(self::MAX_FILTER, self::DEFAULT_MAX_SELF_REGISTERED);

        return is_numeric($max) ? max(0, (int) $max) : self::DEFAULT_MAX_SELF_REGISTERED;
    }

    /**
     * Make room for one more self-registered client.
     *
     * While the ceiling is reached, the oldest self-registered client that holds
     * no live token is removed. When every one of them is connected there is
     * nothing to remove and the registration is refused.
     *
     * @param array<string, mixed> $clients The stored map.
     * @param int                  $max     The ceiling.
     *
     * @return array<string, mixed> The map, with room for one.
     *
     * @throws ClientLimitReachedException When no client can be purged.
     */
    private function makeRoom(array $clients, int $max): array
    {
        $registered = [];

        foreach ($clients as $id => $record) {
            if (is_array($record) && ($record['self_registered'] ?? false) === true) {
                $registered[(string) $id] = (int) ($record['created_at'] ?? 0);
            }
        }

        if ($max === 0) {
            throw new ClientLimitReachedException('Self-registration is capped at zero clients.');
        }

        if (count($registered) < $max) {
            return $clients;
        }

        $connected = (new TokenRepository())->clientsWithLiveTokens();
        $candidates = array_diff_key($registered, $connected);
        asort($candidates);

        while (count($registered) >= $max) {
            $oldest = array_key_first($candidates);

            if ($oldest === null) {
                throw new ClientLimitReachedException('Too many clients are registered.');
            }

            unset($clients[$oldest], $candidates[$oldest], $registered[$oldest]);
        }

        return $clients;
    }

    /**
     * Change the stored map while holding the lock.
     *
     * The map is read again once the lock is held, past the object cache. A
     * change that cannot get the lock in time is still made, unprotected, as it
     * was before the lock existed, unless `$strict`: a registration that
     * cannot be serialised is refused rather than risk losing another.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change Receives the stored map, returns the map to store.
     * @param bool                                                 $strict Give up rather than change it without the lock.
     *
     * @return bool False when `$strict` and the lock could not be taken, so nothing was changed.
     */
    private function mutate(callable $change, bool $strict = false): bool
    {
        $locked = $this->lock->acquire(self::LOCK);

        if (! $locked && $strict) {
            return false;
        }

        try {
            if ($locked) {
                wp_cache_delete(self::OPTION, 'options');
            }

            update_option(self::OPTION, $change($this->allRaw()), false);
        } finally {
            if ($locked) {
                $this->lock->release(self::LOCK);
            }
        }

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

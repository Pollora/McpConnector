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
 * themselves are capped (`mcp_connector_max_registered_clients`, 100 by default).
 * A new self-registration is marked as awaiting its first token, and the mark is
 * cleared before that token is issued. Only marked clients, the ones that never
 * connected, are ever removed automatically: the oldest to make room at the cap,
 * and by the daily sweep once older than a week. A client without the mark has
 * connected, was created by an administrator, or was stored before the mark
 * existed and cannot be told apart: only an administrator removes it.
 * Changes to the option are made under a lock, as for tokens, so concurrent
 * registrations do not lose one another.
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
     * Connected clients are counted and never purged either.
     */
    public const string MAX_FILTER = 'mcp_connector_max_registered_clients';

    /**
     * Default ceiling on self-registered clients.
     */
    public const int DEFAULT_MAX_SELF_REGISTERED = 100;

    /**
     * How long a self-registered client that never connected is kept, in seconds.
     */
    public const int STALE_TTL = 7 * DAY_IN_SECONDS;

    /**
     * Key of a stored record set on a self-registration until its first token is issued.
     * Its absence means "keep": a record is only purged when it is known to be unconnected.
     */
    private const string UNCONNECTED = 'awaiting_first_token';

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

                $clients[$client->id] = $client->toArray() + ($selfRegistered ? [self::UNCONNECTED => true] : []);

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
     * Record that a client is about to be issued a token, so that it is never purged.
     *
     * Call it before storing the tokens: once it has returned true no purge can
     * remove the client. A client that carries no mark is left alone, without
     * taking the lock or writing, which is every refresh.
     *
     * @param string $clientId The client identifier.
     *
     * @return bool False when the client is not registered, or when the lock stayed
     *              busy and nothing was recorded: no token must then be issued.
     */
    public function markConnected(string $clientId): bool
    {
        $record = $this->allRaw()[$clientId] ?? null;

        if (! is_array($record)) {
            return false;
        }

        if (! isset($record[self::UNCONNECTED])) {
            return true;
        }

        $marked = false;

        $this->mutate(
            static function (array $clients) use ($clientId, &$marked): array {
                if (is_array($clients[$clientId] ?? null)) {
                    unset($clients[$clientId][self::UNCONNECTED]);
                    $marked = true;
                }

                return $clients;
            },
            strict: true,
        );

        return $marked;
    }

    /**
     * Drop the self-registered clients that never connected.
     *
     * A client is dropped when it is still awaiting its first token and is older
     * than `$maxAge` seconds. Clients that have connected, however long ago, are
     * kept, and so are those created by an administrator or stored before the
     * mark existed. Nothing is removed while the lock is busy: the next sweep will.
     *
     * @param int $maxAge Age in seconds past which a client that never connected goes.
     *
     * @return int How many clients were removed.
     */
    public function purgeStale(int $maxAge = self::STALE_TTL): int
    {
        $removed = 0;
        $cutoff = time() - $maxAge;
        $connected = (new TokenRepository())->clientsWithLiveTokens();

        $this->mutate(
            static function (array $clients) use ($cutoff, $connected, &$removed): array {
                foreach ($clients as $id => $record) {
                    if (self::isUnconnected($record) && (int) ($record['created_at'] ?? 0) < $cutoff && ! isset($connected[(string) $id])) {
                        unset($clients[$id]);
                        $removed++;
                    }
                }

                return $clients;
            },
            strict: true,
        );

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
     * Every self-registered client counts towards the ceiling. While it is reached,
     * the oldest one that never connected is removed. When there are not enough of
     * those the registration is refused and nothing is removed: an administrator
     * frees a place by removing a client, or raises the ceiling with the filter.
     *
     * @param array<string, mixed> $clients The stored map.
     * @param int                  $max     The ceiling.
     *
     * @return array<string, mixed> The map, with room for one.
     *
     * @throws ClientLimitReachedException When not enough clients can be purged.
     */
    private function makeRoom(array $clients, int $max): array
    {
        $registered = [];
        $unconnected = [];

        foreach ($clients as $id => $record) {
            if (is_array($record) && ($record['self_registered'] ?? false) === true) {
                $registered[(string) $id] = (int) ($record['created_at'] ?? 0);

                if (self::isUnconnected($record)) {
                    $unconnected[(string) $id] = true;
                }
            }
        }

        if ($max === 0) {
            throw new ClientLimitReachedException('Self-registration is capped at zero clients.');
        }

        if (count($registered) < $max) {
            return $clients;
        }

        $connected = (new TokenRepository())->clientsWithLiveTokens();
        $candidates = array_diff_key(array_intersect_key($registered, $unconnected), $connected);
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
     * Whether a stored record is a self-registration known never to have connected.
     *
     * @param mixed $record What the option holds under a client identifier.
     *
     * @return bool True when the record may be purged.
     *
     * @phpstan-assert-if-true array<string, mixed> $record
     */
    private static function isUnconnected(mixed $record): bool
    {
        return is_array($record)
            && ($record['self_registered'] ?? false) === true
            && ($record[self::UNCONNECTED] ?? false) === true;
    }

    /**
     * Change the stored map while holding the lock.
     *
     * The map is read again once the lock is held, past the object cache. A
     * change that cannot get the lock in time is still made, unprotected, as it
     * was before the lock existed, unless `$strict`: a registration, a purge or a
     * first connection that cannot be serialised is given up rather than risk
     * losing another change.
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

<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

use Pollora\McpConnector\Support\OptionLock;

defined('ABSPATH') || exit;

/**
 * Issues, verifies and revokes access and refresh tokens.
 *
 * Tokens are stored as SHA-256 hashes, never in the clear, so a database dump
 * does not confer the ability to act as any connected client. The hash is
 * unsalted on purpose: the lookup has to go from a presented token to a record
 * in one step, and the pre-image is 48 random characters, which is far beyond
 * what a rainbow table reaches.
 *
 * Both kinds live in options rather than transients. Transients would expire
 * themselves, which is tidier, but on a site with a persistent object cache they
 * also evaporate whenever anything calls `wp_cache_flush()` — which cache
 * plugins do on every content purge. An access token that dies each time an
 * editor saves a post turns into a refresh storm.
 *
 * Each option holds every token of its kind as one array, so every change is a
 * read, a modification and a write of the whole array. Two requests doing that
 * at once lose one of the two changes, and for a refresh token that means it can
 * be spent twice, or a token revoked a moment ago comes back. Every change
 * therefore runs under a lock per option, and reads what is stored once it
 * holds the lock rather than what it read before.
 *
 * Every pair issued from one authorization, and every pair a refresh then
 * produces, shares a lineage identifier. A refresh token is not forgotten once
 * it is spent: a marker takes its place for a while, so that if the same token
 * is presented again, which only a copy of it can do, the whole lineage is
 * revoked. Either the legitimate client or whoever copied the token is the one
 * holding a dead token; there is no telling which, and the safe answer is to
 * make both start over (RFC 6819 section 5.2.2.3, OAuth 2.0 Security BCP 4.14).
 */
final class TokenRepository
{
    /**
     * Option holding issued access tokens, keyed by token hash.
     */
    public const string ACCESS_OPTION = 'mcp_connector_oauth_access_tokens';

    /**
     * Option holding issued refresh tokens, keyed by token hash.
     */
    public const string REFRESH_OPTION = 'mcp_connector_oauth_refresh_tokens';

    /**
     * How long an access token stays valid, in seconds.
     */
    public const int ACCESS_TTL = HOUR_IN_SECONDS;

    /**
     * How long a refresh token stays valid, in seconds.
     *
     * @var int
     */
    public const REFRESH_TTL = 30 * DAY_IN_SECONDS;

    /**
     * How long a spent refresh token is remembered, in seconds, at most.
     *
     * Long enough for the legitimate client to come back with the token it
     * believes is current, short enough that the option does not fill up with a
     * marker per refresh over the whole life of a refresh token. A spent token
     * presented after this is simply unknown.
     */
    public const SPENT_TTL = 7 * DAY_IN_SECONDS;

    /**
     * @param OptionLock $lock Serialises the changes made to each token option.
     */
    public function __construct(private readonly OptionLock $lock = new OptionLock())
    {
    }

    /**
     * Issue a fresh access and refresh token pair.
     *
     * @param int          $userId   User the tokens act on behalf of.
     * @param string       $clientId Client the tokens were issued to.
     * @param list<string> $scopes   Granted scopes.
     * @param string|null  $family   Lineage the pair belongs to: the one of the refresh token it replaces,
     *                               or null for a pair issued from a fresh authorization, which starts one.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     *         The token response body, with both secrets in plaintext for the one time they can be read.
     */
    public function issue(int $userId, string $clientId, array $scopes, ?string $family = null): array
    {
        $accessToken = wp_generate_password(48, false);
        $refreshToken = wp_generate_password(48, false);
        $now = time();

        $record = [
            'user_id' => $userId,
            'client_id' => $clientId,
            'scopes' => $scopes,
            'created_at' => $now,
            'family' => $family ?? self::hash($refreshToken),
        ];

        $this->put(self::ACCESS_OPTION, $accessToken, $record + ['expires_at' => $now + self::ACCESS_TTL]);
        $this->put(self::REFRESH_OPTION, $refreshToken, $record + ['expires_at' => $now + self::REFRESH_TTL]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => self::ACCESS_TTL,
            'scope' => Scope::toString($scopes),
        ];
    }

    /**
     * Resolve a presented access token.
     *
     * @param string $token The plaintext bearer token.
     *
     * @return array{user_id: int, client_id: string, scopes: list<string>, expires_at: int, family: string|null}|null
     *         The token record, or null when the token is unknown or expired.
     */
    public function findAccessToken(string $token): ?array
    {
        return $this->find(self::ACCESS_OPTION, $token);
    }

    /**
     * Consume a refresh token, removing it so it cannot be replayed.
     *
     * Refresh tokens rotate: the caller is expected to issue a new pair straight
     * away. The token is looked up and removed in a single locked step, so a
     * token presented twice concurrently only succeeds once, which is the
     * behaviour RFC 6749 section 10.4 asks for.
     *
     * When the lock cannot be taken in time the token is refused and left in
     * place, never accepted unchecked: the client can present it again.
     *
     * A token that was already spent is refused, and revokes every token of its
     * lineage, the pair that replaced it and whatever came after.
     *
     * @param string $token The plaintext refresh token.
     *
     * @return array{user_id: int, client_id: string, scopes: list<string>, expires_at: int, family: string|null}|null
     *         The token record, or null when the token is unknown, expired or already spent.
     */
    public function consumeRefreshToken(string $token): ?array
    {
        $key = self::hash($token);

        // A token that is not even stored is turned away without taking the lock.
        // An expired one is not: presenting it removes it, as it always did.
        if ($token === '' || ! isset($this->allRaw(self::REFRESH_OPTION)[$key])) {
            return null;
        }

        $record = null;
        $replayedFamily = null;
        $now = time();

        $locked = $this->mutate(
            self::REFRESH_OPTION,
            static function (array $tokens) use ($key, $now, &$record, &$replayedFamily): array {
                $stored = $tokens[$key] ?? null;

                if (self::isSpent($stored)) {
                    $replayedFamily = $stored['family'] ?? null;

                    return $tokens;
                }

                $record = self::describe($stored);

                // An expired token, or one issued before lineages existed, leaves nothing
                // behind. Any other leaves a marker in its place until it would have expired.
                if ($record === null || $record['family'] === null) {
                    unset($tokens[$key]);

                    return $tokens;
                }

                $tokens[$key] = [
                    'spent' => true,
                    'family' => $record['family'],
                    'client_id' => $record['client_id'],
                    'user_id' => $record['user_id'],
                    'expires_at' => min($record['expires_at'], $now + self::SPENT_TTL),
                ];

                return $tokens;
            },
            strict: true,
        );

        if ($locked && is_string($replayedFamily) && $replayedFamily !== '') {
            $this->revokeLineage($replayedFamily);
        }

        return $locked ? $record : null;
    }

    /**
     * Revoke a single token, whichever kind it is.
     *
     * @param string      $token    The plaintext token to revoke.
     * @param string|null $clientId When given, the token is only revoked if it was issued to this
     *                              client; a token of another client is left alone.
     *
     * @return bool True when a token was removed.
     */
    public function revoke(string $token, ?string $clientId = null): bool
    {
        $removed = $this->forget(self::ACCESS_OPTION, $token, $clientId);

        return $this->forget(self::REFRESH_OPTION, $token, $clientId) || $removed;
    }

    /**
     * Revoke every token issued to a client.
     *
     * @param string $clientId The client identifier.
     */
    public function revokeClient(string $clientId): void
    {
        foreach ([self::ACCESS_OPTION, self::REFRESH_OPTION] as $option) {
            $this->mutate(
                $option,
                static fn (array $tokens): array => array_filter(
                    $tokens,
                    static fn (mixed $record): bool => ! is_array($record) || ($record['client_id'] ?? '') !== $clientId,
                ),
            );
        }
    }

    /**
     * Revoke every token issued on behalf of a user.
     *
     * Called when a user is deleted, and available from the settings screen for
     * the "I no longer trust what I connected" case.
     *
     * @param int $userId The user identifier.
     */
    public function revokeUser(int $userId): void
    {
        foreach ([self::ACCESS_OPTION, self::REFRESH_OPTION] as $option) {
            $this->mutate(
                $option,
                static fn (array $tokens): array => array_filter(
                    $tokens,
                    static fn (mixed $record): bool => ! is_array($record) || (int) ($record['user_id'] ?? 0) !== $userId,
                ),
            );
        }
    }

    /**
     * Drop every token that has passed its expiry.
     *
     * @return int How many token records were removed.
     */
    public function purgeExpired(): int
    {
        $now = time();
        $removed = 0;

        foreach ([self::ACCESS_OPTION, self::REFRESH_OPTION] as $option) {
            $this->mutate(
                $option,
                static function (array $tokens) use ($now, &$removed): array {
                    $live = array_filter(
                        $tokens,
                        static fn (mixed $record): bool => is_array($record) && (int) ($record['expires_at'] ?? 0) > $now,
                    );

                    $removed += count($tokens) - count($live);

                    return $live;
                },
            );
        }

        return $removed;
    }

    /**
     * Count the live tokens issued to each client.
     *
     * @return array<string, int> Client identifier to number of unexpired access tokens.
     */
    public function liveAccessTokenCounts(): array
    {
        $now = time();
        $counts = [];

        foreach ($this->allRaw(self::ACCESS_OPTION) as $record) {
            if (! is_array($record) || (int) ($record['expires_at'] ?? 0) <= $now) {
                continue;
            }

            $clientId = (string) ($record['client_id'] ?? '');
            $counts[$clientId] = ($counts[$clientId] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Describe the live tokens issued to each client.
     *
     * The settings screen wants to say when a client was last active, and this
     * is the closest honest answer available: the newest token it holds. Reading
     * a token does not record anything — deliberately, since the alternative is
     * an option write on every call an MCP client makes — so "last issued" is
     * what there is, and the screen labels it as such rather than dressing it up
     * as activity.
     *
     * @return array<string, array{count: int, users: list<int>, issued: int, expires: int}> Client identifier to a summary of its live access tokens.
     */
    public function liveAccessTokenSummaries(): array
    {
        $now = time();
        $summaries = [];

        foreach ($this->allRaw(self::ACCESS_OPTION) as $record) {
            if (! is_array($record) || (int) ($record['expires_at'] ?? 0) <= $now) {
                continue;
            }

            $clientId = (string) ($record['client_id'] ?? '');
            $userId = (int) ($record['user_id'] ?? 0);
            $issued = (int) ($record['created_at'] ?? 0);
            $expires = (int) ($record['expires_at'] ?? 0);

            $summary = $summaries[$clientId] ?? ['count' => 0, 'users' => [], 'issued' => 0, 'expires' => 0];

            $summary['count']++;
            $summary['issued'] = max($summary['issued'], $issued);
            $summary['expires'] = max($summary['expires'], $expires);

            if ($userId > 0 && ! in_array($userId, $summary['users'], true)) {
                $summary['users'][] = $userId;
            }

            $summaries[$clientId] = $summary;
        }

        return $summaries;
    }

    /**
     * Store a token record under the hash of its plaintext.
     *
     * @param string               $option Option to write to.
     * @param string               $token  Plaintext token.
     * @param array<string, mixed> $record The record to store.
     */
    private function put(string $option, string $token, array $record): void
    {
        $this->mutate(
            $option,
            static function (array $tokens) use ($token, $record): array {
                $tokens[self::hash($token)] = $record;

                return $tokens;
            },
        );
    }

    /**
     * Look up a token record, treating an expired record as absent.
     *
     * @param string $option Option to read from.
     * @param string $token  Plaintext token.
     *
     * @return array{user_id: int, client_id: string, scopes: list<string>, expires_at: int, family: string|null}|null The record.
     */
    private function find(string $option, string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        return self::describe($this->allRaw($option)[self::hash($token)] ?? null);
    }

    /**
     * Shape a stored record, treating an expired or malformed one as absent.
     *
     * @param mixed $record What the option holds under a token's hash.
     *
     * @return array{user_id: int, client_id: string, scopes: list<string>, expires_at: int, family: string|null}|null The record.
     */
    private static function describe(mixed $record): ?array
    {
        if (! is_array($record) || ($record['spent'] ?? false) === true || (int) ($record['expires_at'] ?? 0) <= time()) {
            return null;
        }

        $family = $record['family'] ?? null;

        return [
            'user_id' => (int) ($record['user_id'] ?? 0),
            'client_id' => (string) ($record['client_id'] ?? ''),
            'scopes' => array_values(array_map(strval(...), (array) ($record['scopes'] ?? []))),
            'expires_at' => (int) $record['expires_at'],
            'family' => is_string($family) && $family !== '' ? $family : null,
        ];
    }

    /**
     * Whether a stored record is the marker of a refresh token that was spent and
     * is still remembered.
     *
     * @param mixed $record What the option holds under a token's hash.
     *
     * @return bool True for a live marker.
     */
    private static function isSpent(mixed $record): bool
    {
        return is_array($record)
            && ($record['spent'] ?? false) === true
            && (int) ($record['expires_at'] ?? 0) > time();
    }

    /**
     * Revoke every token of a lineage, the spent markers included.
     *
     * @param string $family The lineage identifier.
     */
    private function revokeLineage(string $family): void
    {
        foreach ([self::ACCESS_OPTION, self::REFRESH_OPTION] as $option) {
            $this->mutate(
                $option,
                static fn (array $tokens): array => array_filter(
                    $tokens,
                    static fn (mixed $record): bool => ! is_array($record) || ($record['family'] ?? null) !== $family,
                ),
            );
        }
    }

    /**
     * Remove a token record.
     *
     * @param string      $option   Option to write to.
     * @param string      $token    Plaintext token.
     * @param string|null $clientId When given, only a record issued to this client is removed.
     *
     * @return bool True when a record was removed.
     */
    private function forget(string $option, string $token, ?string $clientId = null): bool
    {
        $key = self::hash($token);

        if (! self::isIssuedTo($this->allRaw($option)[$key] ?? null, $clientId)) {
            return false;
        }

        $removed = false;

        $this->mutate(
            $option,
            static function (array $tokens) use ($key, $clientId, &$removed): array {
                // Checked again under the lock: the record may have been replaced since.
                $removed = self::isIssuedTo($tokens[$key] ?? null, $clientId);

                if ($removed) {
                    unset($tokens[$key]);
                }

                return $tokens;
            },
        );

        return $removed;
    }

    /**
     * Whether a stored record exists and, when a client is named, belongs to it.
     *
     * @param mixed       $record   What the option holds under a token's hash.
     * @param string|null $clientId The client the record must have been issued to, if any.
     *
     * @return bool True when the record is there and passes the client check.
     */
    private static function isIssuedTo(mixed $record, ?string $clientId): bool
    {
        if ($record === null) {
            return false;
        }

        if ($clientId === null) {
            return true;
        }

        return is_array($record) && hash_equals((string) ($record['client_id'] ?? ''), $clientId);
    }

    /**
     * Change the tokens stored in an option while holding that option's lock.
     *
     * The stored value is read again once the lock is held, past the object
     * cache, since anything read earlier may already be out of date: that is the
     * whole point of the lock.
     *
     * A change that cannot get the lock in time is still made, unprotected, as it
     * was before the lock existed, unless `$strict` says it is only safe under
     * the lock. Dropping it instead would have a token issued that was never
     * stored, or a revocation silently lost.
     *
     * @param string                                                  $option Option to change.
     * @param callable(array<string, mixed>): array<string, mixed>    $change Receives the stored map, returns the map to store.
     * @param bool                                                    $strict Give up rather than change it without the lock.
     *
     * @return bool False when `$strict` and the lock could not be taken, so nothing was changed.
     */
    private function mutate(string $option, callable $change, bool $strict = false): bool
    {
        $lockName = $option;
        $locked = $this->lock->acquire($lockName);

        if (! $locked && $strict) {
            return false;
        }

        try {
            if ($locked) {
                wp_cache_delete($option, 'options');
            }

            update_option($option, $change($this->allRaw($option)), false);
        } finally {
            if ($locked) {
                $this->lock->release($lockName);
            }
        }

        return true;
    }

    /**
     * The raw stored map for an option.
     *
     * @param string $option Option to read.
     *
     * @return array<string, mixed> Token hash to stored record.
     */
    private function allRaw(string $option): array
    {
        $stored = get_option($option, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Hash a plaintext token into its storage key.
     *
     * @param string $token The plaintext token.
     *
     * @return string The hexadecimal digest.
     */
    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

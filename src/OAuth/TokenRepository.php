<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

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
 */
final class TokenRepository
{
    /**
     * Option holding issued access tokens, keyed by token hash.
     *
     * @var string
     */
    public const ACCESS_OPTION = 'mcp_connector_oauth_access_tokens';

    /**
     * Option holding issued refresh tokens, keyed by token hash.
     *
     * @var string
     */
    public const REFRESH_OPTION = 'mcp_connector_oauth_refresh_tokens';

    /**
     * How long an access token stays valid, in seconds.
     *
     * @var int
     */
    public const ACCESS_TTL = HOUR_IN_SECONDS;

    /**
     * How long a refresh token stays valid, in seconds.
     *
     * @var int
     */
    public const REFRESH_TTL = 30 * DAY_IN_SECONDS;

    /**
     * Issue a fresh access and refresh token pair.
     *
     * @param int          $userId   User the tokens act on behalf of.
     * @param string       $clientId Client the tokens were issued to.
     * @param list<string> $scopes   Granted scopes.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     *         The token response body, with both secrets in plaintext for the one time they can be read.
     */
    public function issue(int $userId, string $clientId, array $scopes): array
    {
        $accessToken = wp_generate_password(48, false);
        $refreshToken = wp_generate_password(48, false);
        $now = time();

        $record = [
            'user_id' => $userId,
            'client_id' => $clientId,
            'scopes' => $scopes,
            'created_at' => $now,
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
     * @return array{user_id: int, client_id: string, scopes: list<string>, expires_at: int}|null
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
     * away. Deleting before issuing means a token presented twice concurrently
     * only succeeds once, which is the behaviour RFC 6749 section 10.4 asks for.
     *
     * @param string $token The plaintext refresh token.
     *
     * @return array{user_id: int, client_id: string, scopes: list<string>, expires_at: int}|null
     *         The token record, or null when the token is unknown or expired.
     */
    public function consumeRefreshToken(string $token): ?array
    {
        $record = $this->find(self::REFRESH_OPTION, $token);

        $this->forget(self::REFRESH_OPTION, $token);

        return $record;
    }

    /**
     * Revoke a single token, whichever kind it is.
     *
     * @param string $token The plaintext token to revoke.
     *
     * @return bool True when a token was removed.
     */
    public function revoke(string $token): bool
    {
        $removed = $this->forget(self::ACCESS_OPTION, $token);

        return $this->forget(self::REFRESH_OPTION, $token) || $removed;
    }

    /**
     * Revoke every token issued to a client.
     *
     * @param string $clientId The client identifier.
     */
    public function revokeClient(string $clientId): void
    {
        foreach ([self::ACCESS_OPTION, self::REFRESH_OPTION] as $option) {
            $tokens = array_filter(
                $this->allRaw($option),
                static fn (mixed $record): bool => ! is_array($record) || ($record['client_id'] ?? '') !== $clientId
            );

            update_option($option, $tokens, false);
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
            $tokens = array_filter(
                $this->allRaw($option),
                static fn (mixed $record): bool => ! is_array($record) || (int) ($record['user_id'] ?? 0) !== $userId
            );

            update_option($option, $tokens, false);
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
            $tokens = $this->allRaw($option);
            $live = array_filter(
                $tokens,
                static fn (mixed $record): bool => is_array($record) && (int) ($record['expires_at'] ?? 0) > $now
            );

            $removed += count($tokens) - count($live);

            if (count($live) !== count($tokens)) {
                update_option($option, $live, false);
            }
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
        $tokens = $this->allRaw($option);
        $tokens[self::hash($token)] = $record;

        update_option($option, $tokens, false);
    }

    /**
     * Look up a token record, treating an expired record as absent.
     *
     * @param string $option Option to read from.
     * @param string $token  Plaintext token.
     *
     * @return array{user_id: int, client_id: string, scopes: list<string>, expires_at: int}|null The record.
     */
    private function find(string $option, string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $record = $this->allRaw($option)[self::hash($token)] ?? null;

        if (! is_array($record) || (int) ($record['expires_at'] ?? 0) <= time()) {
            return null;
        }

        return [
            'user_id' => (int) ($record['user_id'] ?? 0),
            'client_id' => (string) ($record['client_id'] ?? ''),
            'scopes' => array_values(array_map('strval', (array) ($record['scopes'] ?? []))),
            'expires_at' => (int) $record['expires_at'],
        ];
    }

    /**
     * Remove a token record.
     *
     * @param string $option Option to write to.
     * @param string $token  Plaintext token.
     *
     * @return bool True when a record was removed.
     */
    private function forget(string $option, string $token): bool
    {
        $tokens = $this->allRaw($option);
        $key = self::hash($token);

        if (! isset($tokens[$key])) {
            return false;
        }

        unset($tokens[$key]);

        update_option($option, $tokens, false);

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

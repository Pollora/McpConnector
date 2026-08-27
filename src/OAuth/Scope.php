<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

defined('ABSPATH') || exit;

/**
 * The scopes a token can carry, and what the current request is allowed to do.
 *
 * Two scopes, because two is what can be explained on a consent screen and
 * therefore what a user can meaningfully agree to. `read` lets a client see the
 * site; `write` lets it change the site. A longer list would be more precise and
 * less understood.
 *
 * Enforcement happens at the ability boundary rather than through capabilities.
 * Filtering `user_has_cap` would be the obvious approach and is the wrong one:
 * the read abilities themselves check `edit_posts`, so revoking write
 * capabilities would break reading too. Comparing the token's scope against the
 * ability's own read-only annotation asks the question that actually matters.
 */
final class Scope
{
    /**
     * Permission to read the site.
     *
     * @var string
     */
    public const READ = 'read';

    /**
     * Permission to change the site.
     *
     * @var string
     */
    public const WRITE = 'write';

    /**
     * Scope of the token that authenticated the current request.
     *
     * Null means the request was not authenticated by a bearer token at all —
     * an administrator in wp-admin, or WP-CLI — in which case scopes do not
     * apply and the capability system is the only authority.
     *
     * @var list<string>|null
     */
    private static ?array $current = null;

    /**
     * Not instantiable: this is request-scoped state plus static helpers.
     */
    private function __construct()
    {
    }

    /**
     * Every scope this provider issues.
     *
     * @return list<string> The supported scopes.
     */
    public static function supported(): array
    {
        return [self::READ, self::WRITE];
    }

    /**
     * Normalise a space-delimited scope string into known scopes.
     *
     * Unknown scopes are dropped rather than rejected, as RFC 6749 section 3.3
     * allows, and `read` is always implied: a token that can write but not read
     * is not a case worth modelling.
     *
     * @param string $raw The requested scope string.
     *
     * @return list<string> The granted scopes.
     */
    public static function parse(string $raw): array
    {
        $requested = array_filter(preg_split('/\s+/', trim($raw)) ?: []);
        $granted = array_values(array_intersect(self::supported(), $requested));

        if ($granted === []) {
            return [self::READ];
        }

        if (! in_array(self::READ, $granted, true)) {
            $granted[] = self::READ;
        }

        return $granted;
    }

    /**
     * Render scopes back into the space-delimited form used on the wire.
     *
     * @param list<string> $scopes The scopes.
     *
     * @return string The scope string.
     */
    public static function toString(array $scopes): string
    {
        return implode(' ', $scopes);
    }

    /**
     * Record the scope of the token that authenticated this request.
     *
     * @param list<string>|null $scopes Granted scopes, or null to clear.
     */
    public static function setCurrent(?array $scopes): void
    {
        self::$current = $scopes;
    }

    /**
     * The scope of the current request, if it was bearer-authenticated.
     *
     * @return list<string>|null The granted scopes, or null when no token is involved.
     */
    public static function current(): ?array
    {
        return self::$current;
    }

    /**
     * Whether the current request may invoke an ability that changes the site.
     *
     * Requests that carry no token are unaffected: this guards what a delegated
     * token can do, not what a logged-in administrator can do.
     *
     * @return bool True when writes are permitted.
     */
    public static function currentAllowsWrite(): bool
    {
        return self::$current === null || in_array(self::WRITE, self::$current, true);
    }

    /**
     * Human-readable description of a scope, for the consent screen.
     *
     * @param string $scope The scope.
     *
     * @return string The translated description.
     */
    public static function describe(string $scope): string
    {
        return match ($scope) {
            self::READ => __('Read your posts, pages, media and site settings.', 'amphibee-mcp-connector'),
            self::WRITE => __('Create, change and delete your posts, pages, media and menus.', 'amphibee-mcp-connector'),
            default => $scope,
        };
    }
}

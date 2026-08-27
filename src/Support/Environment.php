<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Support;

use Pollora\McpConnector\OAuth;

defined('ABSPATH') || exit;

/**
 * Answers what the surrounding WordPress install can actually support.
 *
 * The plugin degrades rather than fatals when a dependency is absent: without
 * the Abilities API there is nothing to expose, and without the MCP Adapter
 * there is no transport to expose it over — but the OAuth provider is useful on
 * its own and keeps working either way. Every check is a cheap, side-effect-free
 * predicate so callers can use them freely in admin notices and diagnostics.
 */
final class Environment
{
    /**
     * Whether the Abilities API is available.
     *
     * Shipped in WordPress core since 6.9; earlier installs may provide it
     * through the Abilities API feature plugin. Either satisfies us.
     *
     * @return bool True when abilities can be registered.
     */
    public static function hasAbilitiesApi(): bool
    {
        return function_exists('wp_register_ability')
            && function_exists('wp_register_ability_category');
    }

    /**
     * Whether the MCP Adapter plugin is active.
     *
     * The adapter turns registered abilities into MCP tools and mounts the
     * JSON-RPC transport on the REST API. It is distributed from GitHub rather
     * than wordpress.org, so it cannot be installed from the plugin screen.
     *
     * @return bool True when an MCP server can be created.
     */
    public static function hasMcpAdapter(): bool
    {
        return class_exists(\WP\MCP\Core\McpAdapter::class);
    }

    /**
     * Whether pretty permalinks are enabled.
     *
     * The REST API works under plain permalinks, but only through the
     * `?rest_route=` form, which no MCP client will guess. The discovery
     * documents would advertise URLs that 404.
     *
     * @return bool True when a permalink structure is set.
     */
    public static function hasPrettyPermalinks(): bool
    {
        return (string) get_option('permalink_structure') !== '';
    }

    /**
     * Whether the site is served over TLS.
     *
     * OAuth 2.1 requires it, and remote MCP clients refuse plain HTTP endpoints.
     * Local development over HTTP is common, hence a warning rather than a hard
     * failure.
     *
     * @return bool True when the site URL uses the https scheme.
     */
    public static function isHttps(): bool
    {
        return str_starts_with((string) home_url(), 'https://');
    }

    /**
     * Whether the site actually serves the OAuth discovery documents.
     *
     * This is the failure that costs the most time to diagnose, because it
     * leaves no trace: the near-universal `location ~ /\.` rule that blocks
     * `.htaccess` also blocks `/.well-known/`, so the request is refused by the
     * web server and never reaches PHP. RFC 8414 and RFC 9728 pin both discovery
     * documents to that prefix, so a client gets a 403 where it expected JSON
     * and reports the server as unreachable.
     *
     * Establishing it needs a real HTTP request back to ourselves, so the answer
     * is cached. The cache is short because the usual sequence is "read this
     * warning, fix the server, come back".
     *
     * @param bool $refresh Whether to discard the cached answer and probe again.
     *
     * @return array<string, int> Map of discovery URL to the HTTP status it returned, 0 on transport failure.
     */
    public static function discoveryDocumentStatuses(bool $refresh = false): array
    {
        $cacheKey = 'mcp_connector_discovery_probe';

        if (! $refresh) {
            $cached = get_transient($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $statuses = [];

        foreach ([OAuth\Endpoints::protectedResourceMetadata(), OAuth\Endpoints::serverMetadata()] as $url) {
            $response = wp_remote_get($url, [
                'timeout' => 5,
                'redirection' => 0,
                // A site whose certificate does not validate against its own
                // loopback address is a local-development norm, and refusing to
                // probe there would make the check useless exactly where it is
                // most often needed.
                'sslverify' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);

            $statuses[$url] = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        }

        set_transient($cacheKey, $statuses, 5 * MINUTE_IN_SECONDS);

        return $statuses;
    }

    /**
     * Whether both discovery documents are reachable.
     *
     * @param bool $refresh Whether to probe again rather than trust the cache.
     *
     * @return bool True when both answer with 200.
     */
    public static function servesDiscoveryDocuments(bool $refresh = false): bool
    {
        $statuses = self::discoveryDocumentStatuses($refresh);

        return $statuses !== [] && ! in_array(true, array_map(
            static fn (int $status): bool => $status !== 200,
            $statuses,
        ), true);
    }

    /**
     * Collect every unmet requirement, keyed by a stable identifier.
     *
     * Deliberately excludes the discovery probe: this runs on the plugins screen
     * as well, and a loopback HTTP request on every one of those page loads is
     * not a reasonable price for a warning. The settings screen reports it.
     *
     * @return array<string, string> Map of requirement key to a human-readable explanation.
     */
    public static function unmetRequirements(): array
    {
        $problems = [];

        if (! self::hasAbilitiesApi()) {
            $problems['abilities-api'] = __(
                'The Abilities API is unavailable. It ships with WordPress 6.9 and later; on older versions, install the Abilities API feature plugin.',
                'amphibee-mcp-connector',
            );
        }

        if (! self::hasMcpAdapter()) {
            $problems['mcp-adapter'] = __(
                'The MCP Adapter plugin is not active, so no MCP endpoint is served. It is not on wordpress.org and cannot be installed from the plugin screen — download it from https://github.com/WordPress/mcp-adapter and unpack it into wp-content/plugins/mcp-adapter/.',
                'amphibee-mcp-connector',
            );
        }

        if (! self::hasPrettyPermalinks()) {
            $problems['permalinks'] = __(
                'Pretty permalinks are disabled. The MCP and OAuth endpoints are only reachable at the URLs advertised to clients when a permalink structure is set.',
                'amphibee-mcp-connector',
            );
        }

        return $problems;
    }
}

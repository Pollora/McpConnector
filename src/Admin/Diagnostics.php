<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Admin;

use Pollora\McpConnector\Abilities\AbilityRegistry;
use Pollora\McpConnector\Settings;
use Pollora\McpConnector\Support\Environment;

defined('ABSPATH') || exit;

/**
 * The checks that answer "why will this not connect?".
 *
 * Connecting an MCP client runs through five or six independent things —
 * dependencies, permalinks, TLS, the discovery documents, a capability — and
 * when one of them is wrong the client reports a generic failure. Worse, the
 * most common one leaves no trace anywhere in WordPress: a web server that
 * blocks `/.well-known/` refuses the request before PHP is reached, so there is
 * nothing to find in a log.
 *
 * Every check here therefore does two things a status table usually does not.
 * It states the remedy verbatim rather than describing it — the nginx line to
 * change, the directory to unpack an archive into — because a configuration
 * change described in prose is a riddle, and the change itself is not. And it
 * separates *blocking* from *worth knowing*: a failure means no client can
 * connect at all, a warning means something is worth a look but the chain
 * holds.
 */
final class Diagnostics
{
    /**
     * Every requirement is met.
     *
     * @var string
     */
    public const PASS = 'pass';

    /**
     * Worth attention, but a client can still connect.
     *
     * @var string
     */
    public const WARN = 'warn';

    /**
     * Nothing will connect until this is fixed.
     *
     * @var string
     */
    public const FAIL = 'fail';

    /**
     * Results already established in this request.
     *
     * The masthead pill asks for the worst status and the dashboard asks for the
     * list, on the same page load. Without this they would be established twice,
     * and the capability check walks every role each time.
     *
     * @var array<string, list<array{id: string, status: self::*, label: string, detail: string, fix?: array{title: string, lines: list<array{text: string, kind: string}>}}>>
     */
    private static array $memo = [];

    /**
     * Run every check.
     *
     * @param Settings $settings The current configuration.
     * @param bool     $probe    Whether to re-request the discovery documents rather than trust the cache.
     *
     * @return list<array{id: string, status: self::*, label: string, detail: string, fix?: array{title: string, lines: list<array{text: string, kind: string}>}}> The results, in the order they should be read.
     */
    public static function all(Settings $settings, bool $probe = false): array
    {
        $key = $probe ? 'probe' : 'cached';

        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $checks = [
            self::abilitiesApi(),
            self::mcpAdapter(),
            self::permalinks(),
            self::https(),
            self::capability($settings),
        ];

        // Only meaningful when this plugin is the one answering for
        // authentication. With the provider off, the well-known paths are
        // somebody else's problem and probing them would report a failure the
        // administrator has no reason to act on.
        if ($settings->oauthEnabled) {
            $checks[] = self::discovery($probe);
        } else {
            $checks[] = self::oauthDisabled();
        }

        $checks[] = self::tools($settings);

        // A fresh probe answers for the cached reading too: it has just written
        // the transient the cached reading would go on to consult.
        self::$memo['cached'] = $checks;
        self::$memo[$key] = $checks;

        return $checks;
    }

    /**
     * The single worst status across every check, for the masthead pill.
     *
     * @param Settings $settings The current configuration.
     *
     * @return self::* The worst status found.
     */
    public static function worst(Settings $settings): string
    {
        $statuses = array_column(self::all($settings), 'status');

        if (in_array(self::FAIL, $statuses, true)) {
            return self::FAIL;
        }

        return in_array(self::WARN, $statuses, true) ? self::WARN : self::PASS;
    }

    /**
     * Whether anything is currently stopping a client from connecting.
     *
     * @param Settings $settings The current configuration.
     *
     * @return bool True when at least one check is a hard failure.
     */
    public static function blocked(Settings $settings): bool
    {
        return self::worst($settings) === self::FAIL;
    }

    /**
     * Without the Abilities API there is nothing to expose.
     *
     * @return array{id: string, status: self::*, label: string, detail: string} The result.
     */
    private static function abilitiesApi(): array
    {
        $available = Environment::hasAbilitiesApi();

        return [
            'id' => 'abilities-api',
            'status' => $available ? self::PASS : self::FAIL,
            'label' => __('Abilities API', 'amphibee-mcp-connector'),
            'detail' => $available
                ? __('Available. Abilities can be registered and published as tools.', 'amphibee-mcp-connector')
                : __('Unavailable. It ships with WordPress 6.9 and later; on an older install, activate the Abilities API feature plugin. Nothing is published until it is there.', 'amphibee-mcp-connector'),
        ];
    }

    /**
     * Without the adapter there is no transport to expose anything over.
     *
     * @return array{id: string, status: self::*, label: string, detail: string, fix?: array{title: string, lines: list<array{text: string, kind: string}>}} The result.
     */
    private static function mcpAdapter(): array
    {
        if (Environment::hasMcpAdapter()) {
            return [
                'id' => 'mcp-adapter',
                'status' => self::PASS,
                'label' => __('MCP Adapter', 'amphibee-mcp-connector'),
                'detail' => __('Active. It turns the registered abilities into MCP tools and serves the transport.', 'amphibee-mcp-connector'),
            ];
        }

        return [
            'id' => 'mcp-adapter',
            'status' => self::FAIL,
            'label' => __('MCP Adapter', 'amphibee-mcp-connector'),
            'detail' => __('Not active, so no MCP endpoint is served. Everything else on this screen keeps working — the OAuth provider, the abilities — but a client would have nothing to connect to. It is distributed from GitHub rather than wordpress.org, so it cannot be installed from the plugin screen.', 'amphibee-mcp-connector'),
            'fix' => [
                'title' => __('Install it by hand', 'amphibee-mcp-connector'),
                'lines' => [
                    ['text' => 'https://github.com/WordPress/mcp-adapter', 'kind' => 'plain'],
                    ['text' => '', 'kind' => 'plain'],
                    ['text' => '# unpack the archive so that this path exists', 'kind' => 'plain'],
                    ['text' => 'wp-content/plugins/mcp-adapter/mcp-adapter.php', 'kind' => 'right'],
                    ['text' => '', 'kind' => 'plain'],
                    ['text' => '# then build its autoloader without the development packages', 'kind' => 'plain'],
                    ['text' => 'cd wp-content/plugins/mcp-adapter', 'kind' => 'right'],
                    ['text' => 'composer dump-autoload --no-dev --optimize', 'kind' => 'right'],
                ],
            ],
        ];
    }

    /**
     * Under plain permalinks the advertised URLs 404.
     *
     * @return array{id: string, status: self::*, label: string, detail: string} The result.
     */
    private static function permalinks(): array
    {
        $pretty = Environment::hasPrettyPermalinks();

        return [
            'id' => 'permalinks',
            'status' => $pretty ? self::PASS : self::FAIL,
            'label' => __('Pretty permalinks', 'amphibee-mcp-connector'),
            'detail' => $pretty
                ? __('A permalink structure is set, so the advertised URLs resolve.', 'amphibee-mcp-connector')
                : sprintf(
                    /* translators: %s: the Settings → Permalinks screen URL. */
                    __('Disabled. The REST API still answers through its ?rest_route= form, which no client will guess, and the discovery documents would advertise URLs that 404. Set any structure but "Plain" under %s.', 'amphibee-mcp-connector'),
                    admin_url('options-permalink.php')
                ),
        ];
    }

    /**
     * OAuth 2.1 requires TLS, and remote clients enforce it.
     *
     * @return array{id: string, status: self::*, label: string, detail: string} The result.
     */
    private static function https(): array
    {
        $secure = Environment::isHttps();

        return [
            'id' => 'https',
            'status' => $secure ? self::PASS : self::WARN,
            'label' => __('HTTPS', 'amphibee-mcp-connector'),
            'detail' => $secure
                ? __('The site is served over TLS.', 'amphibee-mcp-connector')
                : __('The site is served over plain HTTP. A remote client will refuse the endpoint outright; this is only workable for local development.', 'amphibee-mcp-connector'),
        ];
    }

    /**
     * A capability nobody holds means an authorization nobody can grant.
     *
     * Reported as a warning rather than a failure because the check runs against
     * the administrator reading the screen, and the site may well intend the
     * connector for somebody else entirely.
     *
     * @param Settings $settings The current configuration.
     *
     * @return array{id: string, status: self::*, label: string, detail: string} The result.
     */
    private static function capability(Settings $settings): array
    {
        $capability = $settings->requiredCapability;
        $granted = self::rolesGranting($capability);

        if ($granted === []) {
            return [
                'id' => 'capability',
                'status' => self::FAIL,
                'label' => __('Required capability', 'amphibee-mcp-connector'),
                'detail' => sprintf(
                    /* translators: %s: capability name. */
                    __('No role on this site grants %s, so nobody can approve a client or reach the endpoint. Either the capability is misspelt, or it is granted per-user by a plugin this check cannot see.', 'amphibee-mcp-connector'),
                    $capability
                ),
            ];
        }

        return [
            'id' => 'capability',
            'status' => current_user_can($capability) ? self::PASS : self::WARN,
            'label' => __('Required capability', 'amphibee-mcp-connector'),
            'detail' => current_user_can($capability)
                ? sprintf(
                    /* translators: 1: capability name, 2: comma-separated role names. */
                    __('You hold %1$s. Granted by: %2$s.', 'amphibee-mcp-connector'),
                    $capability,
                    implode(', ', $granted)
                )
                : sprintf(
                    /* translators: 1: capability name, 2: comma-separated role names. */
                    __('You do not hold %1$s, so you can configure the connector but not authorise a client against your own account. Granted by: %2$s.', 'amphibee-mcp-connector'),
                    $capability,
                    implode(', ', $granted)
                ),
        ];
    }

    /**
     * The one requirement that lives outside WordPress.
     *
     * Everything else here can be read from PHP. Whether the web server hands
     * `/.well-known/` to WordPress at all can only be established by asking, and
     * a 403 there is both the most common reason a connection fails and the one
     * that leaves nothing in any log.
     *
     * @param bool $probe Whether to re-request rather than trust the cached answer.
     *
     * @return array{id: string, status: self::*, label: string, detail: string, fix?: array{title: string, lines: list<array{text: string, kind: string}>}} The result.
     */
    private static function discovery(bool $probe): array
    {
        $statuses = Environment::discoveryDocumentStatuses($probe);
        $broken = array_filter($statuses, static fn (int $status): bool => $status !== 200);

        if ($broken === []) {
            return [
                'id' => 'discovery',
                'status' => self::PASS,
                'label' => __('Discovery documents', 'amphibee-mcp-connector'),
                'detail' => __('Both documents answer with JSON at their well-known paths, which is where RFC 8414 and RFC 9728 pin them.', 'amphibee-mcp-connector'),
            ];
        }

        $url = (string) array_key_first($broken);
        $status = (int) reset($broken);

        // Zero means the request never completed. A site that cannot reach
        // itself over HTTP is common enough — a container without a route back
        // to its own hostname, a firewall that drops loopback — and it is not
        // evidence that a client would fail.
        if ($status === 0) {
            return [
                'id' => 'discovery',
                'status' => self::WARN,
                'label' => __('Discovery documents', 'amphibee-mcp-connector'),
                'detail' => sprintf(
                    /* translators: %s: the discovery document URL. */
                    __('Could not reach %s from the site itself. That may only mean the server has no route back to its own hostname; open the URL in a browser to settle it. A client will need it to answer with JSON.', 'amphibee-mcp-connector'),
                    $url
                ),
            ];
        }

        return [
            'id' => 'discovery',
            'status' => self::FAIL,
            'label' => __('Discovery documents', 'amphibee-mcp-connector'),
            'detail' => sprintf(
                /* translators: 1: HTTP status code, 2: the discovery document URL. */
                __('%1$d from %2$s, where a client expects JSON. The web server is almost certainly blocking /.well-known/: the near-universal rule against hidden files catches it too, and refuses the request before PHP is reached — which is why nothing appears in any WordPress log. On Apache, look for a RedirectMatch or a Files rule on names beginning with a dot.', 'amphibee-mcp-connector'),
                $status,
                $url
            ),
            'fix' => [
                'title' => __('In the nginx site configuration', 'amphibee-mcp-connector'),
                'lines' => [
                    ['text' => 'location ~ /\\. { deny all; }', 'kind' => 'wrong'],
                    ['text' => 'location ~* /\\.(?!well-known\\/) { deny all; }', 'kind' => 'right'],
                ],
            ],
        ];
    }

    /**
     * The provider is off, which is a choice rather than a fault.
     *
     * @return array{id: string, status: self::*, label: string, detail: string} The result.
     */
    private static function oauthDisabled(): array
    {
        return [
            'id' => 'oauth',
            'status' => self::WARN,
            'label' => __('OAuth provider', 'amphibee-mcp-connector'),
            'detail' => __('Disabled, so no discovery, authorization or token endpoint is served. Remote clients such as Claude cannot connect unless something else on the site handles authentication.', 'amphibee-mcp-connector'),
        ];
    }

    /**
     * How many tools a client would be offered.
     *
     * Abilities register lazily, so on a plain admin page load nothing has asked
     * for them yet and a zero here means "not counted", not "none". Registering
     * them to find out would be a side effect in a diagnostic, so the check
     * reports the configuration instead: an empty group selection is the one
     * state that genuinely publishes nothing, and that is readable without
     * touching the registry.
     *
     * @param Settings $settings The current configuration.
     *
     * @return array{id: string, status: self::*, label: string, detail: string} The result.
     */
    private static function tools(Settings $settings): array
    {
        $registered = count(AbilityRegistry::registeredAbilityNames());

        if ($settings->enabledGroups === [] && $settings->externalAbilities === []) {
            return [
                'id' => 'tools',
                'status' => self::WARN,
                'label' => __('Published tools', 'amphibee-mcp-connector'),
                'detail' => __('No ability group is enabled and no third-party ability is selected, so a client would connect successfully and find nothing to call.', 'amphibee-mcp-connector'),
            ];
        }

        return [
            'id' => 'tools',
            'status' => self::PASS,
            'label' => __('Published tools', 'amphibee-mcp-connector'),
            'detail' => $registered > 0
                ? sprintf(
                    /* translators: %d: number of abilities registered so far in this request. */
                    _n(
                        '%d ability registered in this request.',
                        '%d abilities registered in this request.',
                        $registered,
                        'amphibee-mcp-connector'
                    ),
                    $registered
                )
                : sprintf(
                    /* translators: %d: number of enabled ability groups. */
                    _n(
                        '%d ability group enabled. Abilities register on demand, so the live count comes from the endpoint rather than from this screen.',
                        '%d ability groups enabled. Abilities register on demand, so the live count comes from the endpoint rather than from this screen.',
                        count($settings->enabledGroups),
                        'amphibee-mcp-connector'
                    ),
                    count($settings->enabledGroups)
                ),
        ];
    }

    /**
     * Which roles grant a capability.
     *
     * @param string $capability The capability to look for.
     *
     * @return list<string> Display names of the roles granting it.
     */
    private static function rolesGranting(string $capability): array
    {
        $roles = wp_roles()->roles;
        $granting = [];

        foreach ($roles as $role) {
            if (! empty($role['capabilities'][$capability])) {
                $granting[] = translate_user_role((string) ($role['name'] ?? ''));
            }
        }

        return $granting;
    }
}

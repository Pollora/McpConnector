<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Server;

use Pollora\McpConnector\Abilities\AbilityRegistry;
use Pollora\McpConnector\Settings;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

defined('ABSPATH') || exit;

/**
 * Declares the MCP server that publishes this plugin's abilities.
 *
 * The MCP Adapter ships a default server exposing three meta-tools —
 * `discover-abilities`, `get-ability-info` and `execute-ability` — through which
 * a client must first discover an ability, then invoke it by name. That works,
 * but it costs two round trips before anything happens and hides the input
 * schemas behind a discovery call.
 *
 * This server instead registers every ability as a named MCP tool, so a client
 * sees `wp-mcp-create-post` alongside its full input schema in the initial
 * `tools/list` and can call it directly.
 */
final class ServerRegistry
{
    /**
     * Identifier of the server this plugin creates.
     */
    public const string SERVER_ID = 'mcp-connector';

    /**
     * REST namespace the MCP Adapter mounts servers under.
     */
    public const string ROUTE_NAMESPACE = 'mcp';

    /**
     * Filter through which the published tool list can be adjusted.
     */
    public const string TOOLS_FILTER = 'mcp_connector_server_tools';

    /**
     * Filter on the instructions the server sends to a client on `initialize`.
     * A value that is not a non-blank string falls back to the default.
     */
    public const string INSTRUCTIONS_FILTER = 'mcp_connector_server_instructions';

    /**
     * Filter on the prompts published next to the tools: ability names,
     * McpPrompt instances or prompt builders. None by default.
     */
    public const string PROMPTS_FILTER = 'mcp_connector_server_prompts';

    /**
     * Filter on the resources published next to the tools: ability names or
     * McpResource instances. None by default.
     */
    public const string RESOURCES_FILTER = 'mcp_connector_server_resources';

    /**
     * @param Settings $settings Resolved plugin configuration.
     */
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Attach the server creation hook.
     */
    public function register(): void
    {
        add_action('mcp_adapter_init', $this->createServer(...));

        // The adapter's own default server answers to any user holding `read`,
        // which on a site with open registration is every subscriber. They would
        // be refused by each ability's permission callback, but `discover-abilities`
        // would still hand them the full tool inventory. Raising the floor to the
        // configured capability closes that without disabling the default server.
        add_filter(
            'mcp_adapter_default_transport_permission_user_capability',
            fn (): string => $this->settings->requiredCapability,
        );
    }

    /**
     * Public URL of this site's MCP endpoint.
     *
     * This is the address a client such as Claude is given when adding the
     * connector, so it is also what the settings screen displays.
     *
     * @return string The absolute endpoint URL.
     */
    public static function endpointUrl(): string
    {
        return rest_url(self::ROUTE_NAMESPACE . '/' . Settings::current()->serverRoute);
    }

    /**
     * Create the server on the adapter.
     *
     * @param McpAdapter $adapter The adapter instance passed by the `mcp_adapter_init` action.
     */
    public function createServer(McpAdapter $adapter): void
    {
        $tools = $this->toolNames();

        // Creating a server with no tools yields an endpoint that answers
        // `tools/list` with nothing, which reads to a client as a broken
        // connector rather than as a deliberately empty one. Better to not
        // publish an endpoint at all.
        if ($tools === []) {
            return;
        }

        $result = $adapter->create_server(
            self::SERVER_ID,
            self::ROUTE_NAMESPACE,
            $this->settings->serverRoute,
            sprintf(
                /* translators: %s: the site title. */
                __('%s MCP server', 'amphibee-mcp-connector'),
                get_bloginfo('name'),
            ),
            $this->instructions(),
            'v1.0.0',
            [HttpTransport::class],
            ErrorLogMcpErrorHandler::class,
            NullMcpObservabilityHandler::class,
            $tools,
            $this->resources(),
            $this->prompts(),
            $this->checkPermission(...),
        );

        if (is_wp_error($result)) {
            (new ErrorLogMcpErrorHandler())->log(
                'MCP Connector could not create its server: ' . $result->get_error_message(),
                ['ServerRegistry::createServer'],
            );
        }
    }

    /**
     * Gate the whole endpoint on the configured capability.
     *
     * This is a floor, not the authorisation model: every ability still runs its
     * own permission callback against the specific object being touched. What
     * this stops is an authenticated but unprivileged user — a subscriber whose
     * OAuth token was issued before the capability was tightened — enumerating
     * the tool list.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming MCP request.
     *
     * @return bool True when the request may proceed.
     */
    public function checkPermission(\WP_REST_Request $request): bool
    {
        unset($request);

        return current_user_can($this->settings->requiredCapability);
    }

    /**
     * Returns the server description, which the adapter sends to clients as MCP `instructions`.
     */
    private function instructions(): string
    {
        $defaultInstructions = sprintf(
            /* translators: %s: the site URL. */
            __('Manage content on the WordPress site at %s.', 'amphibee-mcp-connector'),
            home_url(),
        );

        $instructions = apply_filters(self::INSTRUCTIONS_FILTER, $defaultInstructions);

        if (!is_string($instructions) || trim($instructions) === '') {
            return $defaultInstructions;
        }

        return $instructions;
    }

    /**
     * Returns the filtered prompts, left unvalidated: the adapter skips an unusable one.
     *
     * @return list<mixed> Ability names, McpPrompt instances or prompt builders.
     */
    private function prompts(): array
    {
        $prompts = apply_filters(self::PROMPTS_FILTER, []);

        return is_array($prompts) ? array_values($prompts) : [];
    }

    /**
     * Returns the filtered resources, left unvalidated: the adapter skips an unusable one.
     *
     * @return list<mixed> Ability names or McpResource instances.
     */
    private function resources(): array
    {
        $resources = apply_filters(self::RESOURCES_FILTER, []);

        return is_array($resources) ? array_values($resources) : [];
    }

    /**
     * The abilities to publish as tools.
     *
     * `wp_get_abilities()` is called for its side effect: the abilities registry
     * initialises lazily, and until it has, nothing has been registered and the
     * list would come back empty. Relying on the adapter's default server to
     * have triggered it first would break the moment a site filtered that server
     * off.
     *
     * @return list<string> Fully-qualified ability names.
     */
    private function toolNames(): array
    {
        wp_get_abilities();

        $tools = array_merge(
            AbilityRegistry::registeredAbilityNames(),
            // Abilities other plugins registered, curated. Meta Box alone knows
            // more about this site's custom fields than we could reconstruct,
            // and republishing its abilities here means one connector rather
            // than one per plugin.
            (new ExternalAbilities($this->settings))->selected(),
        );

        /** @var list<string> $tools */
        $tools = apply_filters(self::TOOLS_FILTER, $tools);

        // An unknown tool name makes create_server() reject the entire server,
        // taking every other tool down with it. Filtering here means a provider
        // deactivated since the selection was saved costs its own tools and
        // nothing else.
        return array_values(array_unique(array_filter(
            array_map(strval(...), $tools),
            static fn (string $name): bool => wp_has_ability($name),
        )));
    }
}

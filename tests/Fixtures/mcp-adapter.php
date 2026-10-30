<?php
/**
 * A stand-in for the MCP Adapter's entry point.
 *
 * The adapter is a WordPress plugin distributed from GitHub, not a Composer
 * package, so it is absent from the test process. This records what the plugin
 * asks it to create; the behaviour of the adapter itself is not under test.
 *
 * @package Pollora\McpConnector
 */

declare(strict_types=1);

namespace WP\MCP\Core;

if (!class_exists(McpAdapter::class)) {
    class McpAdapter
    {
        /**
         * The arguments of each create_server() call, in order.
         *
         * @var list<list<mixed>>
         */
        public array $servers = [];

        public function create_server(mixed ...$arguments): bool
        {
            $this->servers[] = array_values($arguments);

            return true;
        }
    }
}

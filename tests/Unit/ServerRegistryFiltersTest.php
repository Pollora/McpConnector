<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\McpConnector\Server\ServerRegistry;
use Pollora\McpConnector\Settings;
use WP\MCP\Core\McpAdapter;

require_once __DIR__ . '/../Fixtures/mcp-adapter.php';

/**
 * Positions of the arguments the plugin passes to create_server().
 */
const MCPC_SERVER_INSTRUCTIONS = 4;
const MCPC_SERVER_RESOURCES = 10;
const MCPC_SERVER_PROMPTS = 11;

/**
 * Creates the server on a recording adapter and returns what it was given.
 *
 * @return list<mixed> The arguments of the create_server() call.
 */
function mcpcCreatedServer(): array
{
    Functions\when('wp_get_abilities')->justReturn([]);
    Functions\when('wp_has_ability')->justReturn(true);
    Functions\when('is_wp_error')->justReturn(false);
    Filters\expectApplied(ServerRegistry::TOOLS_FILTER)->andReturn(['acme/read-things']);

    $adapter = new McpAdapter();
    (new ServerRegistry(Settings::fromArray([])))->createServer($adapter);

    return $adapter->servers[0];
}

describe('the server instructions, prompts and resources', function (): void {
    it('keep their defaults when no callback is hooked', function (): void {
        $server = mcpcCreatedServer();

        expect($server[MCPC_SERVER_INSTRUCTIONS])->toBe('Manage content on the WordPress site at ' . home_url() . '.')
            ->and($server[MCPC_SERVER_RESOURCES])->toBe([])
            ->and($server[MCPC_SERVER_PROMPTS])->toBe([]);
    });

    it('send the instructions a callback returns', function (): void {
        Filters\expectApplied(ServerRegistry::INSTRUCTIONS_FILTER)->once()->andReturn('Read the style guide first.');

        expect(mcpcCreatedServer()[MCPC_SERVER_INSTRUCTIONS])->toBe('Read the style guide first.');
    });

    it('fall back to the default instructions when the filtered value is not a non-blank string', function (mixed $filtered): void {
        Filters\expectApplied(ServerRegistry::INSTRUCTIONS_FILTER)->once()->andReturn($filtered);

        expect(mcpcCreatedServer()[MCPC_SERVER_INSTRUCTIONS])->toBe('Manage content on the WordPress site at ' . home_url() . '.');
    })->with([
        'an empty string' => [''],
        'blanks' => ["  \n\t "],
        'null' => [null],
        'an integer' => [42],
        'an array' => [['not', 'a', 'string']],
    ]);

    it('publish the prompts and resources a callback returns, as lists and unvalidated', function (): void {
        Filters\expectApplied(ServerRegistry::PROMPTS_FILTER)->once()->andReturn([3 => 'acme/write-post', 7 => 'acme/not-an-ability']);
        Filters\expectApplied(ServerRegistry::RESOURCES_FILTER)->once()->andReturn(['key' => 'acme/style-guide']);

        $server = mcpcCreatedServer();

        expect($server[MCPC_SERVER_PROMPTS])->toBe(['acme/write-post', 'acme/not-an-ability'])
            ->and($server[MCPC_SERVER_RESOURCES])->toBe(['acme/style-guide']);
    });

    it('publish none when a callback returns something that is not an array', function (): void {
        Filters\expectApplied(ServerRegistry::PROMPTS_FILTER)->once()->andReturn('acme/write-post');
        Filters\expectApplied(ServerRegistry::RESOURCES_FILTER)->once()->andReturn(null);

        $server = mcpcCreatedServer();

        expect($server[MCPC_SERVER_PROMPTS])->toBe([])
            ->and($server[MCPC_SERVER_RESOURCES])->toBe([]);
    });
});

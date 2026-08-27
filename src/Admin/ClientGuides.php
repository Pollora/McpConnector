<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Admin;

use Pollora\McpConnector\Settings;

defined('ABSPATH') || exit;

/**
 * What to do at the other end, per client.
 *
 * The walkthroughs genuinely differ, and the difference is not cosmetic. A
 * client that implements RFC 7591 registers itself on first contact: there is
 * nothing to create here and nothing to paste back, only a URL to hand over. A
 * client that does not needs a client identifier and secret created on this
 * screen first, which is a different sequence with a different failure mode —
 * and the secret is shown once.
 *
 * Menu paths inside these applications move between releases, so the steps name
 * what to look for rather than pretending to a fixed path. The commands, where
 * a client has one, are exact.
 */
final class ClientGuides
{
    /**
     * Filter through which a site can add or amend a walkthrough.
     *
     * @var string
     */
    public const FILTER = 'mcp_connector_client_guides';

    /**
     * Redirect URIs used by the Claude applications.
     *
     * Offered as the prefilled value of the manual creation form, because a
     * redirect URI is matched exactly and a typo in one produces a refusal the
     * client reports as a generic failure.
     *
     * @var list<string>
     */
    public const CLAUDE_REDIRECT_URIS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

    /**
     * The walkthroughs, in the order they should be offered.
     *
     * @param Settings $settings The current configuration, which decides whether
     *                           self-registering clients can connect unaided.
     *
     * @return list<array{id: string, name: string, tag: string, steps: list<string>, note: string}> The walkthroughs.
     */
    public static function all(Settings $settings): array
    {
        $selfRegisters = $settings->dynamicRegistrationOpen
            ? __('Registers itself on first contact, so there is nothing to create here and nothing to paste back.', 'amphibee-mcp-connector')
            : __('This client registers itself, but self-registration is currently turned off — create a client by hand under Applications first, and add its redirect URIs.', 'amphibee-mcp-connector');

        /** @var list<array{id: string, name: string, tag: string, steps: list<string>, note: string}> $guides */
        $guides = apply_filters(self::FILTER, [
            [
                'id' => 'claude',
                'name' => __('Claude — web and desktop', 'amphibee-mcp-connector'),
                'tag' => __('registers itself', 'amphibee-mcp-connector'),
                'steps' => [
                    __('Open Claude\'s settings and find the connectors section. Choose to add a custom or remote connector — the wording moves between releases, but it is the one that asks for a URL rather than offering a directory of ready-made integrations.', 'amphibee-mcp-connector'),
                    __('Paste the MCP server URL above as the only value it asks for. Claude finds the authorization server from it.', 'amphibee-mcp-connector'),
                    __('Claude opens this site in a browser tab. Sign in if you are not already, then approve the request on the consent screen.', 'amphibee-mcp-connector'),
                    __('The tab closes itself and the connector appears as connected. Its tools are then offered in a conversation, one per published ability.', 'amphibee-mcp-connector'),
                ],
                'note' => $selfRegisters,
            ],
            [
                'id' => 'claude-code',
                'name' => __('Claude Code', 'amphibee-mcp-connector'),
                'tag' => __('one command', 'amphibee-mcp-connector'),
                'steps' => [
                    __('Run the add command in a terminal, naming the server whatever you will want to see in the tool list:', 'amphibee-mcp-connector'),
                    __('Start Claude Code and type /mcp. The server is listed as needing authentication; choose it and a browser opens on the consent screen.', 'amphibee-mcp-connector'),
                    __('Approve there, and the terminal picks the session up. The authorisation is kept, so this is a first-connection step rather than a daily one.', 'amphibee-mcp-connector'),
                ],
                'note' => $selfRegisters,
            ],
            [
                'id' => 'chatgpt',
                'name' => __('ChatGPT', 'amphibee-mcp-connector'),
                'tag' => __('availability varies by plan', 'amphibee-mcp-connector'),
                'steps' => [
                    __('Open the connectors section of ChatGPT\'s settings and add a custom connector. Custom MCP connectors are not offered on every plan; if the option is absent, that is the reason.', 'amphibee-mcp-connector'),
                    __('Paste the MCP server URL and choose OAuth as the authentication method.', 'amphibee-mcp-connector'),
                    __('Approve the request on the consent screen this site opens.', 'amphibee-mcp-connector'),
                ],
                'note' => __('If it will not accept a self-registering connector, create a client by hand under Applications and give ChatGPT the identifier and secret shown there once.', 'amphibee-mcp-connector'),
            ],
            [
                'id' => 'editors',
                'name' => __('Cursor, VS Code and other editors', 'amphibee-mcp-connector'),
                'tag' => __('a JSON entry', 'amphibee-mcp-connector'),
                'steps' => [
                    __('These clients keep their MCP servers in a JSON configuration file, reachable from the MCP section of their settings.', 'amphibee-mcp-connector'),
                    __('Add one entry with the MCP server URL and an HTTP transport:', 'amphibee-mcp-connector'),
                    __('Reload the window. The server appears in the MCP list, and the first call opens the consent screen in a browser.', 'amphibee-mcp-connector'),
                ],
                'note' => __('Editors differ on the key naming — some want "type": "http", some "transport": "http". If the server never appears, that key is the first thing to check.', 'amphibee-mcp-connector'),
            ],
        ]);

        return $guides;
    }

    /**
     * The literal a walkthrough shows after one of its steps, if any.
     *
     * Kept apart from the step text so the command is never run through a
     * translation, and so a URL is interpolated once, here, rather than in four
     * translatable strings.
     *
     * @param string $id  Walkthrough identifier.
     * @param string $url The MCP server URL.
     *
     * @return array<int, array{label: string, text: string}> Step number (1-based) to the literal shown beneath it.
     */
    public static function literals(string $id, string $url): array
    {
        if ($id === 'claude-code') {
            return [
                1 => [
                    'label' => __('In a terminal', 'amphibee-mcp-connector'),
                    'text' => 'claude mcp add --transport http ' . self::serverName() . ' ' . $url,
                ],
            ];
        }

        if ($id === 'editors') {
            return [
                2 => [
                    'label' => __('In the MCP configuration file', 'amphibee-mcp-connector'),
                    'text' => wp_json_encode(
                        ['mcpServers' => [self::serverName() => ['type' => 'http', 'url' => $url]]],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    ) ?: '',
                ],
            ];
        }

        return [];
    }

    /**
     * A short, safe name for this site to appear under in a client's tool list.
     *
     * @return string A lowercase, hyphenated name derived from the site title.
     */
    private static function serverName(): string
    {
        $name = sanitize_title((string) get_bloginfo('name'));

        // Lowercase on purpose, and phpcbf must not "fix" it: this is the
        // fallback for a machine name a client displays, sitting alongside
        // values from sanitize_title(). It is not prose about WordPress.
        return $name !== '' ? $name : 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
    }
}

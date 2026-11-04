<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\McpConnector\Admin\ClientGuides;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/adminsmall-stubs.php';

beforeEach(function (): void {
    adminsmallPrepare();
});

describe('the walkthroughs', function (): void {
    it('offer the four clients in order, each complete', function (): void {
        $guides = ClientGuides::all(Settings::fromArray([]));

        expect(array_column($guides, 'id'))->toBe(['claude', 'claude-code', 'chatgpt', 'editors']);

        foreach ($guides as $guide) {
            expect($guide['name'])->not->toBe('')
                ->and($guide['tag'])->not->toBe('')
                ->and($guide['steps'])->not->toBe([])
                ->and($guide['note'])->not->toBe('');
        }
    });

    it('has a unique identifier per guide', function (): void {
        $ids = array_column(ClientGuides::all(Settings::fromArray([])), 'id');

        expect(array_unique($ids))->toHaveCount(count($ids));
    });

    it('tells self-registering clients there is nothing to create when registration is open', function (): void {
        $guides = array_column(ClientGuides::all(Settings::fromArray(['dynamic_registration_open' => true])), 'note', 'id');

        expect($guides['claude'])->toContain('Registers itself on first contact')
            ->and($guides['claude-code'])->toBe($guides['claude']);
    });

    it('warns those clients to create an application by hand when registration is closed', function (): void {
        $guides = array_column(ClientGuides::all(Settings::fromArray(['dynamic_registration_open' => false])), 'note', 'id');

        expect($guides['claude'])->toContain('self-registration is currently turned off')
            ->and($guides['claude-code'])->toContain('self-registration is currently turned off');
    });

    it('leaves the notes of the clients that never self-register untouched by that setting', function (): void {
        $open = array_column(ClientGuides::all(Settings::fromArray(['dynamic_registration_open' => true])), 'note', 'id');
        $closed = array_column(ClientGuides::all(Settings::fromArray(['dynamic_registration_open' => false])), 'note', 'id');

        expect($closed['chatgpt'])->toBe($open['chatgpt'])
            ->and($closed['editors'])->toBe($open['editors']);
    });

    it('lets a site amend the list through the filter', function (): void {
        Filters\expectApplied(ClientGuides::FILTER)->once()->andReturnUsing(function (array $guides): array {
            $guides[] = ['id' => 'acme', 'name' => 'Acme', 'tag' => 't', 'steps' => ['one'], 'note' => 'n'];

            return $guides;
        });

        expect(array_column(ClientGuides::all(Settings::fromArray([])), 'id'))->toContain('acme');
    });

    it('pins the Claude redirect URIs to https callbacks on the two Claude domains', function (): void {
        expect(ClientGuides::CLAUDE_REDIRECT_URIS)->toBe([
            'https://claude.ai/api/mcp/auth_callback',
            'https://claude.com/api/mcp/auth_callback',
        ]);
    });
});

describe('the literals', function (): void {
    it('gives Claude Code one exact command under its first step', function (): void {
        $literals = ClientGuides::literals('claude-code', 'https://example.test/wp-json/mcp/connector');

        expect(array_keys($literals))->toBe([1])
            ->and($literals[1]['text'])->toBe('claude mcp add --transport http example-site \'https://example.test/wp-json/mcp/connector\'');
    });

    it('gives the editors a JSON entry that parses back to the URL, with the slashes intact', function (): void {
        $literals = ClientGuides::literals('editors', 'https://example.test/wp-json/mcp/connector');
        $decoded = json_decode($literals[2]['text'], true);

        expect(array_keys($literals))->toBe([2])
            ->and($literals[2]['text'])->toContain('https://example.test/wp-json/mcp/connector')
            ->and($decoded['mcpServers']['example-site'])->toBe(['type' => 'http', 'url' => 'https://example.test/wp-json/mcp/connector']);
    });

    it('has nothing to show for the clients that only need the URL', function (): void {
        expect(ClientGuides::literals('claude', 'https://x'))->toBe([])
            ->and(ClientGuides::literals('chatgpt', 'https://x'))->toBe([])
            ->and(ClientGuides::literals('unknown', 'https://x'))->toBe([]);
    });

    it('only ever points at a step the guide really has', function (): void {
        foreach (ClientGuides::all(Settings::fromArray([])) as $guide) {
            foreach (array_keys(ClientGuides::literals($guide['id'], 'https://x')) as $stepNumber) {
                expect($stepNumber)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(count($guide['steps']));
            }
        }
    });

    it('names the server after the site, reduced to a safe slug', function (): void {
        Functions\when('get_bloginfo')->justReturn('My "Site" <b>&</b> Co');

        $text = ClientGuides::literals('claude-code', 'https://x')[1]['text'];

        expect($text)->toBe('claude mcp add --transport http my-site-b-b-co \'https://x\'');
    });

    it('falls back to a fixed name when the site title leaves nothing usable', function (): void {
        Functions\when('get_bloginfo')->justReturn('!!!');

        expect(ClientGuides::literals('claude-code', 'https://x')[1]['text'])->toBe('claude mcp add --transport http wordpress \'https://x\'');
    });

    it('never lets a site title put a shell metacharacter in the command', function (): void {
        Functions\when('get_bloginfo')->justReturn('x; rm -rf / $(id) `id` | y');

        $command = ClientGuides::literals('claude-code', 'https://x')[1]['text'];

        expect($command)->toMatch('/^claude mcp add --transport http [a-z0-9-]+ \'https:\/\/x\'$/');
    });
});

describe('the quoting of the URL', function (): void {
    it('keeps an address with shell metacharacters as one word', function (): void {
        $command = ClientGuides::literals('claude-code', 'https://x.test/?a=1&b=2;id $(id) `id`')[1]['text'];

        expect($command)->toBe('claude mcp add --transport http example-site \'https://x.test/?a=1&b=2;id $(id) `id`\'');
    });

    it('escapes a single quote so it cannot close the quoting', function (): void {
        $command = ClientGuides::literals('claude-code', "https://x.test/'; id; '")[1]['text'];

        expect($command)->toBe("claude mcp add --transport http example-site 'https://x.test/'\\''; id; '\\'''");
    });
});

<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Pollora\McpConnector\Admin\ClientGuides;
use Pollora\McpConnector\Admin\Parts;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/adminsmall-stubs.php';

const ADMINSMALL_PARTS_HOSTILE = '"><script>alert(1)</script>';

beforeEach(function (): void {
    adminsmallPrepare();
});

describe('the diagnostic list', function (): void {
    it('draws one item per check, tagged with its status', function (): void {
        $html = adminsmallCapture(fn () => Parts::checks([
            ['id' => 'a', 'status' => 'pass', 'label' => 'First', 'detail' => 'Fine'],
            ['id' => 'b', 'status' => 'fail', 'label' => 'Second', 'detail' => 'Broken'],
        ]));

        expect(substr_count($html, '<li class="mcpc-check '))->toBe(2)
            ->and($html)->toContain('mcpc-check--pass')->toContain('mcpc-check--fail')
            ->and($html)->not->toContain('mcpc-fix');
    });

    it('draws an empty list for no checks', function (): void {
        expect(adminsmallCapture(fn () => Parts::checks([])))->toBe('<ul class="mcpc-checks"></ul>');
    });

    it('nests the remedy inside the check that carries one', function (): void {
        $html = adminsmallCapture(fn () => Parts::checks([[
            'id' => 'a',
            'status' => 'fail',
            'label' => 'L',
            'detail' => 'D',
            'fix' => ['title' => 'Do this', 'lines' => [['text' => 'x', 'kind' => 'right']]],
        ]]));

        expect($html)->toContain('<span class="mcpc-fix__head">Do this</span>');
    });

    it('never prints a hostile label, detail or status raw', function (): void {
        $html = adminsmallCapture(fn () => Parts::checks([[
            'id' => 'a',
            'status' => ADMINSMALL_PARTS_HOSTILE,
            'label' => ADMINSMALL_PARTS_HOSTILE,
            'detail' => ADMINSMALL_PARTS_HOSTILE,
        ]]));

        expect($html)->not->toContain('<script');
    });
});

describe('the remedy', function (): void {
    it('marks the replaced line and its replacement, and leaves plain lines unmarked', function (): void {
        $html = adminsmallCapture(fn () => Parts::fix(['title' => 'T', 'lines' => [
            ['text' => 'old', 'kind' => 'wrong'],
            ['text' => 'new', 'kind' => 'right'],
            ['text' => 'note', 'kind' => 'plain'],
        ]]));

        expect($html)->toContain('<span class="mcpc-fix__wrong">old</span>')
            ->and($html)->toContain('<span class="mcpc-fix__right">new</span>')
            ->and($html)->toContain("\n<span>note</span>");
    });

    it('treats an unknown line kind as plain rather than as a class name', function (): void {
        $html = adminsmallCapture(fn () => Parts::fix(['title' => 'T', 'lines' => [
            ['text' => 'x', 'kind' => 'wrong" onmouseover="alert(1)'],
        ]]));

        expect($html)->toContain('<span>x</span>')->and($html)->not->toContain('onmouseover');
    });

    it('escapes configuration text, so a snippet reads literally', function (): void {
        $html = adminsmallCapture(fn () => Parts::fix(['title' => ADMINSMALL_PARTS_HOSTILE, 'lines' => [
            ['text' => 'location ~* /\\.(?!well-known\\/) { deny all; }', 'kind' => 'right'],
            ['text' => '<?php echo 1; ?>', 'kind' => 'plain'],
        ]]));

        expect($html)->not->toContain('<script')->and($html)->not->toContain('<?php')
            ->and($html)->toContain('&lt;?php');
    });
});

describe('the client walkthroughs', function (): void {
    it('draws one closed disclosure per guide, with every step', function (): void {
        $settings = Settings::fromArray([]);
        $html = adminsmallCapture(fn () => Parts::guides($settings, 'https://example.test/wp-json/mcp/connector'));
        $guides = ClientGuides::all($settings);

        expect(substr_count($html, '<details class="mcpc-guide__client">'))->toBe(count($guides))
            ->and($html)->not->toContain('<details open')
            ->and(substr_count($html, '<li>'))->toBe(array_sum(array_map(fn (array $g): int => count($g['steps']), $guides)));
    });

    it('puts the command under the step that introduces it, with a copy button holding the same text', function (): void {
        $html = adminsmallCapture(fn () => Parts::guides(Settings::fromArray([]), 'https://example.test/mcp'));

        expect($html)->toContain('data-copy="claude mcp add --transport http example-site &#039;https://example.test/mcp&#039;"')
            ->and($html)->toContain('<code class="mcpc-fix__code">claude mcp add --transport http example-site &#039;https://example.test/mcp&#039;</code>');
    });

    it('never lets a hostile URL out of the copy attribute or the code block', function (): void {
        $html = adminsmallCapture(fn () => Parts::guides(Settings::fromArray([]), ADMINSMALL_PARTS_HOSTILE));

        expect($html)->not->toContain('<script')->and($html)->not->toContain('"><script');
    });

    it('escapes what a site adds through the guides filter', function (): void {
        Filters\expectApplied(ClientGuides::FILTER)->once()->andReturn([[
            'id' => 'acme',
            'name' => ADMINSMALL_PARTS_HOSTILE,
            'tag' => ADMINSMALL_PARTS_HOSTILE,
            'steps' => [ADMINSMALL_PARTS_HOSTILE],
            'note' => ADMINSMALL_PARTS_HOSTILE,
        ]]);

        $html = adminsmallCapture(fn () => Parts::guides(Settings::fromArray([]), 'https://example.test/mcp'));

        expect($html)->not->toContain('<script');
    });
});

describe('the endpoint list', function (): void {
    it('lists all six addresses, each with a copy button for the same value', function (): void {
        $html = adminsmallCapture(fn () => Parts::endpoints());

        expect(substr_count($html, '<dt>'))->toBe(6)
            ->and($html)->toContain('<dd class="mcpc-endpoints__url">https://example.test/.well-known/oauth-protected-resource</dd>')
            ->and($html)->toContain('data-copy="https://example.test/oauth/authorize"')
            ->and($html)->toContain('data-copy="https://example.test/wp-json/mcp-connector/v1/revoke"');
    });
});

describe('the connection test', function (): void {
    it('starts hidden, because it has no meaning without JavaScript', function (): void {
        $html = adminsmallCapture(fn () => Parts::test());

        expect($html)->toContain('id="mcpc-run-test" hidden')
            ->and($html)->toContain('id="mcpc-test-steps" hidden')
            ->and($html)->toContain('<noscript>')
            ->and($html)->toContain('deleted when the run ends');
    });
});

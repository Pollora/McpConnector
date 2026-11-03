<?php

declare(strict_types=1);

use Pollora\McpConnector\Admin\Field;

require_once __DIR__ . '/../../Fixtures/adminsmall-stubs.php';

const ADMINSMALL_HOSTILE = '"><script>alert(1)</script>\'';

beforeEach(function (): void {
    adminsmallPrepare();
});

/**
 * Whether the output carries a live script tag or an unescaped quote break-out.
 */
function adminsmallIsRaw(string $html): bool
{
    return str_contains($html, '<script');
}

describe('the switches', function (): void {
    it('draw a toggle as a labelled checkbox that submits "1" when on', function (): void {
        $html = adminsmallCapture(fn () => Field::toggle('read_only_mode', 'Read only', 'Safe', true));

        expect($html)->toContain('type="checkbox"')
            ->and($html)->toContain('id="mcpc-read_only_mode"')
            ->and($html)->toContain('name="read_only_mode" value="1" checked=\'checked\'')
            ->and($html)->toContain('for="mcpc-read_only_mode"');
    });

    it('leave an off toggle unchecked', function (): void {
        $html = adminsmallCapture(fn () => Field::toggle('x', 'X', 'help', false));

        expect($html)->not->toContain('checked');
    });

    it('point a toggle at a form declared elsewhere, escaped', function (): void {
        $html = adminsmallCapture(fn () => Field::toggle('x', 'X', 'help', false, 'create"form'));

        expect($html)->toContain('form="create&quot;form"');
    });

    it('omit the form attribute when the toggle sits in its own form', function (): void {
        expect(adminsmallCapture(fn () => Field::toggle('x', 'X', 'help', false)))->not->toContain('form=');
    });

    it('submit a choice as an array entry carrying its own value', function (): void {
        $html = adminsmallCapture(fn () => Field::choice('enabled_groups', 'content', 'Content', 'help', true));

        expect($html)->toContain('name="enabled_groups[]" value="content"')
            ->and($html)->toContain('id="mcpc-enabled_groups-content"')
            ->and($html)->toContain('checked');
    });

    it('never print a hostile label, help or value raw', function (): void {
        $outputs = [
            adminsmallCapture(fn () => Field::toggle(ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, true, ADMINSMALL_HOSTILE)),
            adminsmallCapture(fn () => Field::choice(ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, true)),
        ];

        foreach ($outputs as $html) {
            expect(adminsmallIsRaw($html))->toBeFalse()
                ->and($html)->toContain('&lt;script&gt;');
        }
    });
});

describe('the text controls', function (): void {
    it('draw an input with its value, help text and mono face on request', function (): void {
        $html = adminsmallCapture(fn () => Field::input('server_route', 'Route', 'The segment', 'connector', true));

        expect($html)->toContain('value="connector"')
            ->and($html)->toContain('mcpc-field__control--mono')
            ->and($html)->toContain('aria-describedby="mcpc-server_route-help"')
            ->and($html)->toContain('id="mcpc-server_route-help"');
    });

    it('keep the mono class off by default', function (): void {
        expect(adminsmallCapture(fn () => Field::input('a', 'A', 'h', 'v')))->not->toContain('mono');
    });

    it('never let an input value break out of its attribute', function (): void {
        $html = adminsmallCapture(fn () => Field::input('a', ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, false, ADMINSMALL_HOSTILE));

        expect(adminsmallIsRaw($html))->toBeFalse()
            ->and($html)->toContain('value="&quot;&gt;&lt;script&gt;');
    });

    it('draw a textarea with rows and a placeholder, escaping its body', function (): void {
        $html = adminsmallCapture(fn () => Field::textarea('uris', 'URIs', 'One per line', "a\nb", 7, 'https://x'));

        expect($html)->toContain('rows="7"')
            ->and($html)->toContain('placeholder="https://x"')
            ->and($html)->toContain(">a\nb</textarea>");
    });

    it('never print a hostile textarea body or placeholder raw, and cannot close the element early', function (): void {
        $html = adminsmallCapture(fn () => Field::textarea('a', 'A', 'h', '</textarea><script>x</script>', 4, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE));

        expect(adminsmallIsRaw($html))->toBeFalse()
            ->and(substr_count($html, '</textarea>'))->toBe(1);
    });

    it('draw a hidden value escaped, with an optional form', function (): void {
        $plain = adminsmallCapture(fn () => Field::hidden('mcpc_step', 'connect'));
        $hostile = adminsmallCapture(fn () => Field::hidden(ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE));

        expect($plain)->toBe('<input type="hidden" name="mcpc_step" value="connect">')
            ->and(adminsmallIsRaw($hostile))->toBeFalse();
    });
});

describe('the read-only and card controls', function (): void {
    it('draw the address as a readonly input the copy button can find', function (): void {
        $html = adminsmallCapture(fn () => Field::address('mcpc-url', 'MCP URL', 'https://example.test/wp-json/mcp/connector', 'Paste it'));

        expect($html)->toContain('readonly')
            ->and($html)->toContain('data-copy-from="mcpc-url"')
            ->and($html)->toContain('value="https://example.test/wp-json/mcp/connector"');
    });

    it('never print a hostile address raw', function (): void {
        expect(adminsmallIsRaw(adminsmallCapture(
            fn () => Field::address(ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE),
        )))->toBeFalse();
    });

    it('draw a type card whose warning note carries the warning class', function (): void {
        $warn = adminsmallCapture(fn () => Field::typeCard('types', 'page', 'Pages', 'Public', true, true));
        $fact = adminsmallCapture(fn () => Field::typeCard('types', 'page', 'Pages', 'Public', false, false));

        expect($warn)->toContain('mcpc-card-check__note--warn')->toContain('checked')
            ->and($fact)->not->toContain('--warn')->not->toContain('checked');
    });

    it('never print a hostile type card raw', function (): void {
        expect(adminsmallIsRaw(adminsmallCapture(
            fn () => Field::typeCard(ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, true, true),
        )))->toBeFalse();
    });

    it('hash an ability name into a valid id and fall back to the name when it has no label', function (): void {
        $html = adminsmallCapture(fn () => Field::ability('external_abilities', 'acme/do-thing', '', 'Does it', true, true));

        expect($html)->toContain('id="mcpc-ability-' . md5('acme/do-thing') . '"')
            ->and($html)->toContain('<span class="mcpc-ability__name">acme/do-thing<span class="mcpc-badge mcpc-badge--recommended">recommended</span>')
            ->and($html)->toContain('name="external_abilities[]" value="acme/do-thing"');
    });

    it('show no badge for an ability nothing recommends', function (): void {
        expect(adminsmallCapture(fn () => Field::ability('a', 'acme/x', 'X', 'd', false, false)))->not->toContain('badge');
    });

    it('never print a hostile ability label or description raw', function (): void {
        expect(adminsmallIsRaw(adminsmallCapture(
            fn () => Field::ability(ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, ADMINSMALL_HOSTILE, true, true),
        )))->toBeFalse();
    });
});

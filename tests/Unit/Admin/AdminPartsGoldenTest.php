<?php

declare(strict_types=1);

use Pollora\McpConnector\Admin\Field;
use Pollora\McpConnector\Admin\Parts;
use Pollora\McpConnector\Settings;

require_once __DIR__ . '/../../Fixtures/adminsmall-stubs.php';

beforeEach(function (): void {
    adminsmallPrepare();
});

/**
 * Join several renderings so a reference file reads as a list of fragments.
 *
 * @param array<string, callable> $cases Label to renderer.
 */
function adminpartsJoin(array $cases): string
{
    $out = '';

    foreach ($cases as $label => $render) {
        $out .= '<!-- ' . $label . " -->\n" . adminsmallCapture($render) . "\n";
    }

    return $out;
}

describe('the form fields, whole', function (): void {
    it('renders every control in both of its states', function (): void {
        mcpcAssertGolden('fields', adminpartsJoin([
            'toggle on' => fn () => Field::toggle('read_only_mode', 'Read <only>', 'Safe & sound', true),
            'toggle off with form' => fn () => Field::toggle('oauth_enabled', 'OAuth', 'Sign in', false, 'other-form'),
            'choice on' => fn () => Field::choice('enabled_groups', 'content', 'Content', 'Posts and pages', true),
            'choice off' => fn () => Field::choice('enabled_groups', 'media', 'Media', 'Files', false),
            'input plain' => fn () => Field::input('ability_namespace', 'Namespace', 'Prefix', 'my-site'),
            'input mono with form' => fn () => Field::input('server_route', 'Route', 'Path', 'a"b', true, 'other-form'),
            'textarea default' => fn () => Field::textarea('redirect_uris', 'URIs', 'One per line', "a\nb"),
            'textarea custom' => fn () => Field::textarea('notes', 'Notes', 'Free', 'x', 9, 'https://…', 'other-form'),
            'address' => fn () => Field::address('mcpc-url', 'MCP URL', 'https://example.test/mcp?a=1&b=2', 'Paste it'),
            'type card plain checked' => fn () => Field::typeCard('post_types', 'post', 'Post', '3 published', false, true),
            'type card warn unchecked' => fn () => Field::typeCard('post_types', 'book', 'Book', 'Private', true, false),
            'ability recommended checked' => fn () => Field::ability('external_abilities', 'acme/read', 'Read it', 'Reads <b>', true, true),
            'ability unlabelled' => fn () => Field::ability('external_abilities', 'acme/raw', '', 'No label', false, false),
            'hidden' => fn () => Field::hidden('mcpc_tab', 'security'),
            'hidden with form' => fn () => Field::hidden('mcpc_tab', 'a"b', 'other-form'),
        ]));
    });
});

describe('the shared parts, whole', function (): void {
    it('renders checks, remedies, endpoints and the connection test', function (): void {
        mcpcAssertGolden('parts', adminpartsJoin([
            'no checks' => fn () => Parts::checks([]),
            'checks' => fn () => Parts::checks([
                ['id' => 'a', 'status' => 'pass', 'label' => 'Rewrite <rules>', 'detail' => 'All good'],
                ['id' => 'b', 'status' => 'fail', 'label' => 'Headers', 'detail' => 'Stripped', 'fix' => [
                    'title' => 'Add this',
                    'lines' => [
                        ['text' => 'old line', 'kind' => 'wrong'],
                        ['text' => 'new line', 'kind' => 'right'],
                        ['text' => 'a note', 'kind' => 'plain'],
                    ],
                ]],
            ]),
            'fix without lines' => fn () => Parts::fix(['title' => 'Empty', 'lines' => []]),
            'guides' => fn () => Parts::guides(Settings::fromArray([]), 'https://example.test/mcp'),
            'endpoints' => fn () => Parts::endpoints(),
            'test' => fn () => Parts::test(),
        ]));
    });
});

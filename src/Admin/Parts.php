<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Admin;

use Pollora\McpConnector\OAuth\Endpoints;
use Pollora\McpConnector\Settings;

defined('ABSPATH') || exit;

/**
 * Fragments the setup assistant and the settings panels both draw.
 *
 * The assistant is not a separate feature with its own copy of the diagnostics
 * and its own copy of the walkthroughs: it is the same material, arranged as a
 * sequence rather than as a reference. Keeping the fragments here is what makes
 * that true rather than merely intended — a check whose remedy is corrected gets
 * corrected in both places, because there is only one place.
 */
final class Parts
{
    /**
     * Render a list of diagnostic results, each with its remedy.
     *
     * @param list<array{id: string, status: string, label: string, detail: string, fix?: array{title: string, lines: list<array{text: string, kind: string}>}}> $checks The results.
     */
    public static function checks(array $checks): void
    {
        echo '<ul class="mcpc-checks">';

        foreach ($checks as $check) {
            printf(
                '<li class="mcpc-check mcpc-check--%1$s">'
                . '<span class="mcpc-dot mcpc-dot--%1$s" aria-hidden="true"></span>'
                . '<span class="mcpc-check__body">'
                . '<span class="mcpc-check__label">%2$s</span>'
                . '<span class="mcpc-check__detail">%3$s</span>',
                esc_attr($check['status']),
                esc_html($check['label']),
                esc_html($check['detail']),
            );

            if (isset($check['fix'])) {
                self::fix($check['fix']);
            }

            echo '</span></li>';
        }

        echo '</ul>';
    }

    /**
     * Render a remedy as the literal change to make.
     *
     * A configuration change described in prose is a riddle; the change itself
     * is not. Where a line is being replaced, the old one is struck through
     * above the new one, so the reader can match what they have against what
     * they should have without parsing a sentence about regular expressions.
     *
     * @param array{title: string, lines: list<array{text: string, kind: string}>} $fix The remedy.
     */
    public static function fix(array $fix): void
    {
        printf(
            '<span class="mcpc-fix"><span class="mcpc-fix__head">%s</span><code class="mcpc-fix__code">',
            esc_html($fix['title']),
        );

        foreach ($fix['lines'] as $index => $line) {
            $class = match ($line['kind']) {
                'wrong' => ' class="mcpc-fix__wrong"',
                'right' => ' class="mcpc-fix__right"',
                default => '',
            };

            printf(
                '%s<span%s>%s</span>',
                $index > 0 ? "\n" : '',
                $class, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- One of three literals from the match above; no input reaches it.
                esc_html($line['text']),
            );
        }

        echo '</code></span>';
    }

    /**
     * Render the per-client walkthroughs.
     *
     * Closed by default and opened one at a time: an administrator connecting
     * Claude has no use for the Cursor sequence, and four expanded walkthroughs
     * stacked on one screen is the reference table this redesign exists to get
     * rid of.
     *
     * @param Settings $settings The current configuration.
     * @param string   $url      The MCP server URL clients are given.
     */
    public static function guides(Settings $settings, string $url): void
    {
        echo '<div class="mcpc-guide">';

        foreach (ClientGuides::all($settings) as $guide) {
            $literals = ClientGuides::literals($guide['id'], $url);

            printf(
                '<details class="mcpc-guide__client"><summary class="mcpc-guide__summary">%s'
                . '<span class="mcpc-guide__tag">%s</span></summary>'
                . '<div class="mcpc-guide__body"><ol class="mcpc-guide__steps">',
                esc_html($guide['name']),
                esc_html($guide['tag']),
            );

            foreach ($guide['steps'] as $index => $step) {
                printf('<li>%s', esc_html($step));

                if (isset($literals[$index + 1])) {
                    $literal = $literals[$index + 1];

                    printf(
                        '<span class="mcpc-fix"><span class="mcpc-fix__head">%s'
                        . '<button type="button" class="mcpc-button mcpc-button--quiet mcpc-copy" data-copy="%s">%s</button>'
                        . '</span><code class="mcpc-fix__code">%s</code></span>',
                        esc_html($literal['label']),
                        esc_attr($literal['text']),
                        esc_html__('Copy', 'amphibee-mcp-connector'),
                        esc_html($literal['text']),
                    );
                }

                echo '</li>';
            }

            printf('</ol><p class="mcpc-note">%s</p></div></details>', esc_html($guide['note']));
        }

        echo '</div>';
    }

    /**
     * Render the secondary endpoints.
     *
     * A client is only ever given the first URL; these are here because when a
     * connection fails, the next question is always which of them answers.
     */
    public static function endpoints(): void
    {
        $rows = [
            __('Protected resource metadata', 'amphibee-mcp-connector') => Endpoints::protectedResourceMetadata(),
            __('Authorization server metadata', 'amphibee-mcp-connector') => Endpoints::serverMetadata(),
            __('Authorization endpoint', 'amphibee-mcp-connector') => Endpoints::authorize(),
            __('Token endpoint', 'amphibee-mcp-connector') => Endpoints::token(),
            __('Registration endpoint', 'amphibee-mcp-connector') => Endpoints::register(),
            __('Revocation endpoint', 'amphibee-mcp-connector') => Endpoints::revoke(),
        ];

        echo '<dl class="mcpc-endpoints">';

        foreach ($rows as $label => $url) {
            printf(
                '<dt>%s</dt><dd class="mcpc-endpoints__url">%s</dd>'
                . '<dd class="mcpc-endpoints__action">'
                . '<button type="button" class="mcpc-button mcpc-button--quiet mcpc-copy" data-copy="%s">%s</button>'
                . '</dd>',
                esc_html($label),
                esc_html($url),
                esc_attr($url),
                esc_html__('Copy', 'amphibee-mcp-connector'),
            );
        }

        echo '</dl>';
    }

    /**
     * Render the end-to-end connection test.
     *
     * The button starts hidden and is revealed by the script, because the test
     * has no equivalent without JavaScript and a button that cannot do anything
     * is worse than no button. The note beneath says what the run will create
     * and destroy, since it does both.
     */
    public static function test(): void
    {
        printf(
            '<div class="mcpc-actions">'
            . '<button type="button" class="mcpc-button mcpc-button--primary" id="mcpc-run-test" hidden>%s</button>'
            . '<span class="mcpc-actions__status" id="mcpc-test-status" role="status" aria-live="polite"></span>'
            . '</div>',
            esc_html__('Test the connection', 'amphibee-mcp-connector'),
        );

        echo '<ol class="mcpc-test" id="mcpc-test-steps" hidden></ol>';

        printf(
            '<p class="mcpc-note">%s</p>',
            esc_html__('The test walks the whole chain the way a client does: it registers a throwaway application, authorises it as you, exchanges the code for a token, and calls the MCP endpoint with it. Everything it creates is deleted when the run ends, whether the run succeeded or not.', 'amphibee-mcp-connector'),
        );

        printf(
            '<noscript><p class="mcpc-note">%s</p></noscript>',
            esc_html__('The test needs JavaScript. The checks above are established without it.', 'amphibee-mcp-connector'),
        );
    }
}

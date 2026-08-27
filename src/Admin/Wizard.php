<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Admin;

use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\Server\ServerRegistry;
use Pollora\McpConnector\Settings;
use Pollora\McpConnector\Support\PostTypes;

defined('ABSPATH') || exit;

/**
 * The setup assistant: its own screen, and the whole window.
 *
 * Connecting a client is a chain — dependencies, permalinks, TLS, the discovery
 * documents, a capability, the URL, an approval — and the client at the far end
 * reports one generic failure whichever link breaks. A reference screen answers
 * that badly: it shows every link at once, in no order, and leaves the reader to
 * work out which one is theirs. A sequence answers it well, because it can stop.
 *
 * It takes the window rather than sitting inside wp-admin because this is a
 * thing done once, with an end. The surrounding menu offers nothing that helps
 * and several ways to abandon it halfway, so the only way out is a link that
 * says it is one. The chrome is suppressed in {@see SettingsPage}, which owns
 * the screen's registration; this class owns what fills it.
 *
 * The first step blocks. If nothing on this site can serve the discovery
 * documents, there is no point handing anybody a URL, and the assistant says so
 * instead of offering the next step. The escape hatch is deliberate but quiet:
 * a check can be wrong — a server with no route back to its own hostname reports
 * a failure a real client would never see — and refusing to let anyone past a
 * false negative would be its own kind of broken.
 */
final class Wizard
{
    /**
     * Page slug of the setup screen.
     *
     * Registered under Settings and then removed from the menu: the assistant is
     * somewhere you are sent, not somewhere you browse to.
     *
     * @var string
     */
    public const MENU_SLUG = 'mcp-connector-setup';

    /**
     * Body class marking the screen, so the stylesheet can hide what is left of
     * wp-admin around it.
     *
     * @var string
     */
    public const BODY_CLASS = 'mcpc-setup-screen';

    /**
     * The steps, in order.
     *
     * @var list<string>
     */
    private const STEPS = ['check', 'expose', 'connect', 'verify'];

    /**
     * @param Settings                                                                      $settings The current configuration.
     * @param list<Client>                                                                  $clients  Registered clients.
     * @param array<string, array{count: int, users: list<int>, issued: int, expires: int}> $tokens   Live token summaries by client.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly array $clients,
        private readonly array $tokens,
    ) {
    }

    /**
     * URL of one assistant step.
     *
     * @param string $step Step identifier.
     *
     * @return string The URL.
     */
    public static function url(string $step = 'check'): string
    {
        return add_query_arg(
            ['page' => self::MENU_SLUG, 'step' => $step],
            admin_url('options-general.php'),
        );
    }

    /**
     * Whether a step identifier is one of ours.
     *
     * @param string $step The candidate.
     *
     * @return bool True when it names a step.
     */
    public static function isStep(string $step): bool
    {
        return in_array($step, self::STEPS, true);
    }

    /**
     * Render the whole screen.
     */
    public function render(): void
    {
        $step = $this->currentStep();

        echo '<div class="mcpc mcpc-setup">';

        $this->renderBand($step);

        echo '<main class="mcpc-setup__stage">';

        match ($step) {
            'expose' => $this->renderExpose(),
            'connect' => $this->renderConnect(),
            'verify' => $this->renderVerify(),
            default => $this->renderCheck(),
        };

        echo '</main></div>';
    }

    /**
     * The step being shown.
     *
     * @return string One of {@see self::STEPS}.
     */
    private function currentStep(): string
    {
        // Selects which step to draw and changes nothing; the value is reduced
        // to [a-z0-9_-] and then has to match a known step.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() on the next line.
        $raw = isset($_GET['step']) ? wp_unslash($_GET['step']) : '';
        $requested = is_string($raw) ? sanitize_key($raw) : '';

        return self::isStep($requested) ? $requested : 'check';
    }

    /**
     * Render the coloured band: identity, progress, and the way out.
     *
     * Each step is a real link, so the rail is navigation rather than decoration
     * — somebody who knows their environment is fine can go straight to the URL.
     * A step is marked done only when its own criterion is actually met, not
     * merely because it has been walked past: the first is done when the checks
     * pass, the last when a client holds a token. The two in between have no
     * criterion to test, so they are marked done once the reader is past them,
     * which is the honest reading of "you have seen this".
     *
     * @param string $current The step being shown.
     */
    private function renderBand(string $current): void
    {
        $labels = [
            'check' => __('Preparation', 'amphibee-mcp-connector'),
            'expose' => __('Reach', 'amphibee-mcp-connector'),
            'connect' => __('The URL', 'amphibee-mcp-connector'),
            'verify' => __('Verify', 'amphibee-mcp-connector'),
        ];

        $position = (int) array_search($current, self::STEPS, true);

        printf(
            '<header class="mcpc-setup__band"><div class="mcpc-setup__bandinner">'
            . '<span class="mcpc-setup__brand">%s%s</span>',
            // A hardcoded SVG literal: no input reaches it, and escaping it
            // would print the markup instead of drawing it.
            SettingsPage::mark(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            esc_html__('MCP Connector', 'amphibee-mcp-connector'),
        );

        printf(
            '<ol class="mcpc-setup__rail" aria-label="%s">',
            esc_attr__('Setup steps', 'amphibee-mcp-connector'),
        );

        foreach (self::STEPS as $index => $step) {
            $done = match ($step) {
                'check' => ! Diagnostics::blocked($this->settings),
                'verify' => $this->connected(),
                default => $index < $position,
            };

            $class = 'mcpc-setup__step';

            if ($step === $current) {
                $class .= ' is-current';
            } elseif ($done) {
                $class .= ' is-done';
            }

            printf(
                '<li class="%s"><a class="mcpc-setup__steplink" href="%s"%s>'
                . '<span class="mcpc-setup__steptext">%s</span></a></li>',
                esc_attr($class),
                esc_url(self::url($step)),
                $step === $current ? ' aria-current="step"' : '',
                esc_html($labels[$step]),
            );
        }

        printf(
            '</ol><a class="mcpc-setup__exit" href="%s">%s</a></div></header>',
            esc_url(SettingsPage::url('dashboard')),
            esc_html__('Leave the assistant', 'amphibee-mcp-connector'),
        );
    }

    /**
     * Render a step's heading.
     *
     * @param string $step  The step identifier, for the counter.
     * @param string $title The step's question or instruction, in a few words.
     * @param string $lede  What this step establishes or decides.
     */
    private function head(string $step, string $title, string $lede): void
    {
        $position = (int) array_search($step, self::STEPS, true) + 1;

        printf(
            '<p class="mcpc-setup__eyebrow">%s</p>'
            . '<h1 class="mcpc-setup__title">%s</h1>'
            . '<p class="mcpc-setup__lede">%s</p>',
            esc_html(sprintf(
                /* translators: 1: current step number, 2: total number of steps. */
                __('Step %1$d of %2$d', 'amphibee-mcp-connector'),
                $position,
                count(self::STEPS),
            )),
            esc_html($title),
            esc_html($lede),
        );
    }

    /**
     * Render the step's footer: where to go next, and what else is on offer.
     *
     * @param string $primary   Markup for the primary action, already escaped.
     * @param string $secondary Markup for a secondary action, already escaped.
     */
    private function foot(string $primary, string $secondary = ''): void
    {
        printf(
            '<div class="mcpc-setup__actions"><div class="mcpc-setup__actionside">%s%s</div></div>',
            // Both are literals built by the calling method from translated
            // strings and escaped URLs; escaping again would print the markup.
            $primary, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $secondary, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
    }

    /**
     * Whether any client holds a live access token.
     *
     * @return bool True when something has completed the whole chain.
     */
    private function connected(): bool
    {
        foreach ($this->tokens as $summary) {
            if ($summary['count'] > 0) {
                return true;
            }
        }

        return false;
    }

    // -- Steps --

    /**
     * Step one: is this site in a state where anything could connect?
     */
    private function renderCheck(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Re-runs a read-only probe.
        $probe = isset($_GET['probe']);
        $checks = Diagnostics::all($this->settings, $probe);
        $blocked = Diagnostics::blocked($this->settings);

        $this->head(
            'check',
            $blocked
                ? __('Something here blocks every client', 'amphibee-mcp-connector')
                : __('The site is ready', 'amphibee-mcp-connector'),
            $blocked
                ? __('Not one client will connect until this is fixed, whatever it is and however it is configured. Each failure below states the change that fixes it.', 'amphibee-mcp-connector')
                : __('Nothing here would stop a client from connecting. Anything marked as needing attention is worth a look, but the chain holds.', 'amphibee-mcp-connector'),
        );

        echo '<div class="mcpc-setup__content">';
        Parts::checks($checks);

        printf(
            '<p class="mcpc-note">%s</p>',
            esc_html__('The discovery documents are the link that fails most often, and the only one that leaves no trace: a web server blocking /.well-known/ refuses the request before PHP is reached. The answer is cached for five minutes, so change the server, then ask again.', 'amphibee-mcp-connector'),
        );
        echo '</div>';

        $again = sprintf(
            '<a class="mcpc-button mcpc-button--%s" href="%s">%s</a>',
            $blocked ? 'primary' : 'quiet',
            esc_url(add_query_arg('probe', '1', self::url('check'))),
            esc_html__('Ask the server again', 'amphibee-mcp-connector'),
        );

        $next = sprintf(
            '<a class="mcpc-button mcpc-button--primary" href="%s">%s</a>',
            esc_url(self::url('expose')),
            esc_html__('Continue', 'amphibee-mcp-connector'),
        );

        // A failing check can be wrong — a container with no route back to its
        // own hostname reports a failure no real client would meet — so the way
        // past exists. It is a quiet link rather than a button because being
        // wrong about this costs an hour of confusion at the other end.
        $anyway = sprintf(
            '<a class="mcpc-link" href="%s">%s</a>',
            esc_url(self::url('expose')),
            esc_html__('Continue anyway', 'amphibee-mcp-connector'),
        );

        $this->foot($blocked ? $again : $next, $blocked ? $anyway : '');
    }

    /**
     * Step two: how far should a client be allowed to reach?
     */
    private function renderExpose(): void
    {
        $this->head(
            'expose',
            __('How far it may reach', 'amphibee-mcp-connector'),
            __('Two decisions worth making before anything connects. Everything else — which ability groups, which post types, which third-party abilities — has a working default and can wait.', 'amphibee-mcp-connector'),
        );

        echo '<div class="mcpc-setup__content">';
        echo '<form method="post" id="mcpc-wizard-form">';
        wp_nonce_field(SettingsPage::NONCE_SETTINGS);
        Field::hidden('mcpc_step', 'connect');

        echo '<div class="mcpc-stack">';

        Field::toggle(
            'read_only_mode',
            __('Start read-only', 'amphibee-mcp-connector'),
            __('Abilities that would change the site are not registered at all, so they are absent from the tool list rather than refused when called. The safe way to bring a connector up on a live site: prove the transport and the authentication first, then come back and turn it off.', 'amphibee-mcp-connector'),
            $this->settings->readOnlyMode,
        );

        Field::toggle(
            'dynamic_registration_open',
            __('Let clients register themselves', 'amphibee-mcp-connector'),
            __('What makes a one-click connection possible: the client introduces itself and gets an identifier. It grants nothing on its own — a user still has to approve the request, holding the capability below. Turned off, every application has to be created by hand first.', 'amphibee-mcp-connector'),
            $this->settings->dynamicRegistrationOpen,
        );

        echo '</div></form>';

        printf(
            '<p class="mcpc-note">%s</p>',
            esc_html(sprintf(
                /* translators: 1: capability name, 2: number of addressable post types. */
                __('A user needs the %1$s capability to approve a client and to reach the endpoint, and %2$d post types are currently addressable. Both are adjustable afterwards, under Security and Tools.', 'amphibee-mcp-connector'),
                $this->settings->requiredCapability,
                count(PostTypes::addressable()),
            )),
        );

        echo '</div>';

        $this->foot(sprintf(
            '<button type="submit" name="mcp_connector_wizard_save" value="1" form="mcpc-wizard-form" '
            . 'class="mcpc-button mcpc-button--primary">%s</button>',
            esc_html__('Save and continue', 'amphibee-mcp-connector'),
        ));
    }

    /**
     * Step three: the address, and what to do with it at the other end.
     */
    private function renderConnect(): void
    {
        $url = ServerRegistry::endpointUrl();

        $this->head(
            'connect',
            __('Hand over the URL', 'amphibee-mcp-connector'),
            __('This is the only thing the client needs. It reads the authorization server, the token endpoint and the tool list from it.', 'amphibee-mcp-connector'),
        );

        echo '<div class="mcpc-setup__content">';

        Field::address(
            'mcpc-wizard-url',
            __('MCP server URL', 'amphibee-mcp-connector'),
            $url,
            __('Paste this into the client. Nothing else has to be copied across, unless the client cannot register itself.', 'amphibee-mcp-connector'),
        );

        printf('<h2 class="mcpc-subhead">%s</h2>', esc_html__('Then, in the client', 'amphibee-mcp-connector'));
        Parts::guides($this->settings, $url);

        if ($this->clients !== []) {
            printf(
                '<p class="mcpc-note">%s</p>',
                esc_html(sprintf(
                    /* translators: %d: number of registered clients. */
                    _n(
                        '%d application has already registered against this site but holds no live token, which means it introduced itself and the approval never completed.',
                        '%d applications have already registered against this site but hold no live token, which means they introduced themselves and the approval never completed.',
                        count($this->clients),
                        'amphibee-mcp-connector',
                    ),
                    count($this->clients),
                )),
            );
        }

        echo '</div>';

        $this->foot(sprintf(
            '<a class="mcpc-button mcpc-button--primary" href="%s">%s</a>',
            esc_url(self::url('verify')),
            esc_html__('Continue', 'amphibee-mcp-connector'),
        ));
    }

    /**
     * Step four: did any of that work?
     */
    private function renderVerify(): void
    {
        $connected = $this->connected();

        $this->head(
            'verify',
            $connected ? __('Something is connected', 'amphibee-mcp-connector') : __('See it work', 'amphibee-mcp-connector'),
            $connected
                ? __('An application holds a live token, which means the whole chain completed at least once. The test below re-walks it under its own throwaway credentials, which is the way to find out whether it still works.', 'amphibee-mcp-connector')
                : __('Nothing holds a live token yet. Either the approval has not been made in the client, or something in the chain refuses it — the test below walks the same path a client does and reports where it stops.', 'amphibee-mcp-connector'),
        );

        echo '<div class="mcpc-setup__content">';
        Parts::test();
        echo '</div>';

        $this->foot(sprintf(
            '<a class="mcpc-button mcpc-button--%s" href="%s">%s</a>',
            $connected ? 'primary' : 'quiet',
            esc_url(SettingsPage::url('dashboard')),
            esc_html__('Finish', 'amphibee-mcp-connector'),
        ));
    }
}

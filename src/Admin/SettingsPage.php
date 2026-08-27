<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Admin;

use Pollora\McpConnector\Abilities\AbilityRegistry;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\TokenRepository;
use Pollora\McpConnector\Server\ExternalAbilities;
use Pollora\McpConnector\Server\ExternalAbility;
use Pollora\McpConnector\Server\ServerRegistry;
use Pollora\McpConnector\Settings;
use Pollora\McpConnector\Support\PostTypes;

use const Pollora\McpConnector\VERSION;

defined('ABSPATH') || exit;

/**
 * The settings screen: a setup assistant first, a reference afterwards.
 *
 * The screen has two modes, and which one it opens in is decided by whether
 * anything has ever actually connected. Until then it is a sequence — check the
 * environment, decide what to expose, hand over the URL, verify — because the
 * chain has six links and the client at the far end reports a generic failure
 * whichever of them breaks. Once a client holds a live token the sequence has
 * served its purpose and the screen becomes what a settings screen normally is:
 * panels, in the order somebody comes back to them.
 *
 * The panels are rendered directly rather than through the Settings API. That
 * API produces one flat form-table, which is exactly the shape this screen was
 * rewritten to stop being.
 */
final class SettingsPage
{
    /**
     * Menu slug of the settings page.
     *
     * @var string
     */
    public const MENU_SLUG = 'mcp-connector';

    /**
     * Identifier of the configuration form.
     *
     * @var string
     */
    public const SETTINGS_FORM = 'mcpc-settings';

    /**
     * Identifier of the manual client creation form.
     *
     * Declared as an empty element after the configuration form, with its
     * controls pointing at it by id. Nesting it inside the configuration form —
     * where its fields visually belong — is invalid HTML, and browsers respond
     * by silently discarding the inner form altogether.
     *
     * @var string
     */
    public const CLIENT_FORM = 'mcpc-create-client';

    /**
     * Nonce action guarding configuration saves.
     *
     * Public because the setup assistant submits against it too: its second step
     * writes two of the same settings, through the same verified handler.
     *
     * @var string
     */
    public const NONCE_SETTINGS = 'mcp_connector_save_settings';

    /**
     * Nonce action guarding client creation.
     *
     * @var string
     */
    private const NONCE_CREATE_CLIENT = 'mcp_connector_create_client';

    /**
     * Nonce action guarding client deletion.
     *
     * @var string
     */
    private const NONCE_DELETE_CLIENT = 'mcp_connector_delete_client';

    /**
     * Transient prefix holding a newly-created client secret for one page load.
     *
     * The secret exists in plaintext only at creation. It is handed forward
     * through a short-lived transient rather than a query parameter, because a
     * query parameter ends up in the browser history, in the referrer and in the
     * access log.
     *
     * @var string
     */
    private const SECRET_TRANSIENT = 'mcp_connector_new_secret_';

    /**
     * @param Settings $settings Resolved plugin configuration.
     */
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Attach the admin hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', $this->addMenuPage(...));
        add_action('admin_init', $this->handleActions(...));
        add_action('admin_enqueue_scripts', $this->enqueueAssets(...));
        add_filter(
            'plugin_action_links_' . plugin_basename(\Pollora\McpConnector\PLUGIN_FILE),
            $this->addSettingsLink(...)
        );

        if (self::onSetupScreen()) {
            $this->suppressAdminChrome();
        }

        (new ConnectionTest())->register();
    }

    /**
     * Add both screens: the settings page, and the assistant.
     *
     * The assistant is registered under Settings and then taken back out of the
     * menu. `remove_submenu_page()` only unlists it — the page hook and its entry
     * in `$_registered_pages` survive, so the URL keeps working. That is the
     * intent: it is somewhere you are sent, not somewhere you browse to.
     */
    public function addMenuPage(): void
    {
        add_options_page(
            __('MCP Connector', 'amphibee-mcp-connector'),
            __('MCP Connector', 'amphibee-mcp-connector'),
            'manage_options',
            self::MENU_SLUG,
            $this->render(...)
        );

        add_submenu_page(
            'options-general.php',
            __('Set up MCP Connector', 'amphibee-mcp-connector'),
            __('Set up MCP Connector', 'amphibee-mcp-connector'),
            'manage_options',
            Wizard::MENU_SLUG,
            $this->renderSetup(...)
        );

        remove_submenu_page('options-general.php', Wizard::MENU_SLUG);
    }

    /**
     * Whether the request is for the assistant's own screen.
     *
     * Read from `$_GET` rather than from `get_current_screen()` because two of
     * the callers run long before the screen object exists — the admin bar is
     * decided at `init`, and the notices are unhooked at `in_admin_header`.
     *
     * @return bool True on the assistant screen.
     */
    private static function onSetupScreen(): bool
    {
        // Chooses a presentation and changes nothing; the value is reduced to
        // [a-z0-9_-] and then compared against one constant.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = isset($_GET['page']) ? wp_unslash($_GET['page']) : '';

        return is_string($raw) && sanitize_key($raw) === Wizard::MENU_SLUG;
    }

    /**
     * Take wp-admin's furniture off the assistant's screen.
     *
     * Three places, on purpose. The admin bar's renderer is unhooked, the
     * notices are unhooked before they run, and the stylesheet hides what is
     * left. Doing it in CSS alone would leave a bar and a plugin-update nag that
     * are built, queried for and then thrown away; doing it in PHP alone would
     * leave the menu column's reserved space behind.
     *
     * ⚠️ `show_admin_bar` is not the way to do this. `is_admin_bar_showing()`
     * returns true for any admin request *before* it reaches the filter, so
     * filtering it has no effect at all inside wp-admin. The renderer has to be
     * unhooked instead — and even then WordPress still writes `wp-toolbar` onto
     * the `<html>` element and `admin-bar` onto the body, because both are
     * derived from that same unfiltered function. The stylesheet undoes the
     * 32px those reserve.
     *
     * Nothing here touches any other screen: the whole block is behind
     * {@see self::onSetupScreen()}.
     */
    private function suppressAdminChrome(): void
    {
        remove_action('in_admin_header', 'wp_admin_bar_render', 0);

        add_filter('admin_body_class', static function (string $classes): string {
            return $classes . ' ' . Wizard::BODY_CLASS . ' ';
        });

        // Notices are the reason this matters rather than being cosmetic. A
        // sequence that says "this is the one thing stopping you" reads as a lie
        // with four unrelated plugin warnings stacked above it.
        add_action('in_admin_header', static function (): void {
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
            remove_all_actions('user_admin_notices');
            remove_all_actions('network_admin_notices');
        }, 1);
    }

    /**
     * Render the assistant.
     */
    public function renderSetup(): void
    {
        (new Wizard(
            Settings::current(),
            (new ClientRepository())->all(),
            (new TokenRepository())->liveAccessTokenSummaries()
        ))->render();
    }

    /**
     * Add a direct link from the plugins list.
     *
     * @param array<int|string, string> $links The existing action links.
     *
     * @return array<int|string, string> The links, with ours first.
     */
    public function addSettingsLink(array $links): array
    {
        $settingsLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::url()),
            esc_html__('Settings', 'amphibee-mcp-connector')
        );

        return array_merge([$settingsLink], $links);
    }

    /**
     * Load the screen's assets, on this screen only.
     *
     * @param string $hookSuffix The admin page currently being rendered.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        $screens = [
            'settings_page_' . self::MENU_SLUG,
            'settings_page_' . Wizard::MENU_SLUG,
        ];

        if (! in_array($hookSuffix, $screens, true)) {
            return;
        }

        wp_enqueue_style(
            'mcp-connector-admin',
            plugins_url('assets/admin.css', \Pollora\McpConnector\PLUGIN_FILE),
            [],
            VERSION
        );

        wp_enqueue_script(
            'mcp-connector-admin',
            plugins_url('assets/admin.js', \Pollora\McpConnector\PLUGIN_FILE),
            [],
            VERSION,
            true
        );

        wp_localize_script('mcp-connector-admin', 'mcpConnectorAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'testAction' => ConnectionTest::ACTION,
            'testNonce' => wp_create_nonce(ConnectionTest::ACTION),
            // Panels that hold no setting: offering Save there invites a save
            // nobody asked for, on a screen where saving writes eleven values.
            'panelsWithoutSettings' => 'dashboard,connect,applications',
            'testing' => __('Running the whole chain…', 'amphibee-mcp-connector'),
            'testFailed' => __('The test could not be completed.', 'amphibee-mcp-connector'),
            'copied' => __('Copied', 'amphibee-mcp-connector'),
            'copyFailed' => __('Press Ctrl+C', 'amphibee-mcp-connector'),
        ]);
    }

    // -- Actions --

    /**
     * Process form submissions before anything is rendered.
     *
     * Each branch redirects on success, so a reload cannot resubmit it.
     */
    public function handleActions(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Each
        // branch verifies its own nonce before acting.
        if (isset($_POST['mcp_connector_save'])) {
            check_admin_referer(self::NONCE_SETTINGS);
            $this->saveSettings();
        }

        if (isset($_POST['mcp_connector_wizard_save'])) {
            check_admin_referer(self::NONCE_SETTINGS);
            $this->saveFromWizard();
        }

        if (isset($_POST['mcp_connector_create_client'])) {
            check_admin_referer(self::NONCE_CREATE_CLIENT);
            $this->createClient();
        }

        if (isset($_GET['mcp_connector_delete_client'])) {
            check_admin_referer(self::NONCE_DELETE_CLIENT);
            $this->deleteClient(sanitize_text_field(wp_unslash((string) $_GET['mcp_connector_delete_client'])));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $this->maybeSendToSetup();
    }

    /**
     * Send a first-time visitor to the assistant instead of to the panels.
     *
     * The question the assistant answers is "how do I get something connected",
     * so it steps aside the moment something is: a client holding a live access
     * token has completed the whole chain, which is the only proof that matters.
     * A client that merely registered is not enough — Claude registers itself and
     * then abandons the flow often enough that production carries the leftovers.
     *
     * An explicit `?tab=` overrides it, because somebody who navigated to a panel
     * asked for that panel. That is also the escape the assistant's own "Leave"
     * link uses, so there is no loop.
     */
    private function maybeSendToSetup(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Chooses a destination; changes nothing.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';

        if ($page !== self::MENU_SLUG || isset($_GET['tab'])) {
            return;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        foreach ((new TokenRepository())->liveAccessTokenSummaries() as $summary) {
            if ($summary['count'] > 0) {
                return;
            }
        }

        wp_safe_redirect(Wizard::url());

        exit;
    }

    /**
     * Persist the submitted configuration.
     */
    private function saveSettings(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by the caller.
        $groups = array_map(
            'sanitize_key',
            array_map('strval', (array) ($_POST['enabled_groups'] ?? []))
        );

        $postTypes = array_values(array_intersect(
            array_map('sanitize_key', array_map('strval', (array) ($_POST['addressable_post_types'] ?? []))),
            array_map(static fn (\WP_Post_Type $t): string => $t->name, PostTypes::offerable())
        ));

        Settings::save([
            'ability_namespace' => sanitize_key(wp_unslash((string) ($_POST['ability_namespace'] ?? ''))),
            'server_route' => sanitize_key(wp_unslash((string) ($_POST['server_route'] ?? ''))),
            'required_capability' => sanitize_key(wp_unslash((string) ($_POST['required_capability'] ?? ''))),
            'enabled_groups' => array_values($groups),
            'read_only_mode' => isset($_POST['read_only_mode']),
            'external_abilities' => $this->submittedExternalAbilities(),
            'external_abilities_reviewed' => true,
            'addressable_post_types' => $postTypes,
            'post_types_reviewed' => true,
            'oauth_enabled' => isset($_POST['oauth_enabled']),
            'dynamic_registration_open' => isset($_POST['dynamic_registration_open']),
        ]);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $this->redirect(['saved' => '1']);
    }

    /**
     * Persist the one or two values the assistant asks for.
     *
     * Merged into the stored configuration rather than saved outright: the
     * assistant shows a fraction of the settings, and a plain save would take
     * every field it does not render as absent — silently emptying the ability
     * groups and the addressable post types on the way past.
     */
    private function saveFromWizard(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by the caller.
        $changes = [
            'read_only_mode' => isset($_POST['read_only_mode']),
            'dynamic_registration_open' => isset($_POST['dynamic_registration_open']),
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        Settings::save(array_merge(Settings::current()->toArray(), $changes));

        $this->redirect(['saved' => '1']);
    }

    /**
     * The third-party abilities the form offered and the administrator kept.
     *
     * Ability names are validated by membership in the discovered set rather
     * than by a character filter: they carry a slash, which `sanitize_key()`
     * strips, and membership is the stricter test anyway.
     *
     * @return list<string> Fully-qualified ability names.
     */
    private function submittedExternalAbilities(): array
    {
        $offered = [];

        foreach ((new ExternalAbilities(Settings::current()))->available() as $abilities) {
            foreach ($abilities as $ability) {
                $offered[] = $ability->name;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the caller.
        $submitted = array_map('strval', (array) ($_POST['external_abilities'] ?? []));

        return array_values(array_intersect($submitted, $offered));
    }

    /**
     * Create a client by hand, for sites that keep self-registration closed.
     */
    private function createClient(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by the caller.
        $name = sanitize_text_field(wp_unslash((string) ($_POST['client_name'] ?? '')));
        $rawUris = wp_unslash((string) ($_POST['redirect_uris'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $uris = array_values(array_filter(array_map(
            static fn (string $line): string => esc_url_raw(trim($line)),
            preg_split('/\R/', $rawUris) ?: []
        )));

        if ($uris === []) {
            $this->redirect(['error' => 'redirect_uris']);
        }

        $created = (new ClientRepository())->create(
            name: $name !== '' ? $name : __('Manually created client', 'amphibee-mcp-connector'),
            redirectUris: $uris,
        );

        set_transient(
            self::SECRET_TRANSIENT . $created['client']->id,
            $created['secret'],
            MINUTE_IN_SECONDS * 5
        );

        $this->redirect(['created' => $created['client']->id]);
    }

    /**
     * Delete a client, revoking its tokens with it.
     *
     * @param string $clientId The client identifier.
     */
    private function deleteClient(string $clientId): void
    {
        (new ClientRepository())->delete($clientId);

        $this->redirect(['deleted' => '1']);
    }

    /**
     * Redirect back to this screen, and stop.
     *
     * The panel or assistant step the submission came from is carried along, so
     * a save lands back where it was made rather than at the top of the screen.
     *
     * @param array<string, string> $arguments Notice parameters to carry.
     *
     * @return never
     */
    private function redirect(array $arguments): never
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by the caller; these only choose a destination.
        $tab = sanitize_key(wp_unslash((string) ($_POST['mcpc_tab'] ?? '')));
        $step = sanitize_key(wp_unslash((string) ($_POST['mcpc_step'] ?? '')));
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (Wizard::isStep($step)) {
            // Back to the assistant, at the step the submission named. No
            // "saved" notice: the assistant suppresses notices, and the step
            // moving on is the confirmation.
            wp_safe_redirect(Wizard::url($step));

            exit;
        }

        if (array_key_exists($tab, self::panels())) {
            $arguments['tab'] = $tab;
        }

        wp_safe_redirect(add_query_arg($arguments, self::url()));

        exit;
    }

    // -- Screen furniture --

    /**
     * The panels, in navigation order.
     *
     * @return array<string, array{label: string, description: string}> Panel key to its label and one-line summary.
     */
    public static function panels(): array
    {
        return [
            'dashboard' => [
                'label' => __('Dashboard', 'amphibee-mcp-connector'),
                'description' => __('Health, and what is wrong', 'amphibee-mcp-connector'),
            ],
            'connect' => [
                'label' => __('Connect', 'amphibee-mcp-connector'),
                'description' => __('The URL, and what to do with it', 'amphibee-mcp-connector'),
            ],
            'tools' => [
                'label' => __('Tools', 'amphibee-mcp-connector'),
                'description' => __('What a client may call', 'amphibee-mcp-connector'),
            ],
            'security' => [
                'label' => __('Security', 'amphibee-mcp-connector'),
                'description' => __('Who gets in, and how far', 'amphibee-mcp-connector'),
            ],
            'applications' => [
                'label' => __('Applications', 'amphibee-mcp-connector'),
                'description' => __('Connected clients and tokens', 'amphibee-mcp-connector'),
            ],
            'advanced' => [
                'label' => __('Advanced', 'amphibee-mcp-connector'),
                'description' => __('Names and addresses', 'amphibee-mcp-connector'),
            ],
        ];
    }

    /**
     * Hand-drawn 20x20 line icon per panel.
     *
     * Inline and stroked in currentColor so they follow the tab's own state
     * without a second asset, a sprite sheet, or an icon font. Kept deliberately
     * plain: they mark a row, they do not illustrate it.
     *
     * @param string $panel Panel key.
     *
     * @return non-empty-string The SVG element.
     */
    private static function icon(string $panel): string
    {
        $paths = [
            // A gauge: status at a glance.
            'dashboard' => '<path d="M3 15a9 9 0 1 1 18 0"/><path d="m12 15 4-5"/><circle cx="12" cy="15" r="1.4"/>',
            // Two link halves meeting.
            'connect' => '<path d="M10.5 13.5a3.5 3.5 0 0 0 5 0l3-3a3.5 3.5 0 0 0-5-5l-1.5 1.5"/><path d="M13.5 10.5a3.5 3.5 0 0 0-5 0l-3 3a3.5 3.5 0 0 0 5 5L12 17"/>',
            // A toolbox handle over a body.
            'tools' => '<rect x="3" y="8" width="18" height="12" rx="2"/><path d="M9 8V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/><path d="M10 13v2h4v-2"/>',
            // A shield.
            'security' => '<path d="M12 3l7 3v5.5c0 4.3-2.9 7.8-7 9.5-4.1-1.7-7-5.2-7-9.5V6z"/><path d="m9 12 2 2 4-4"/>',
            // Two overlapping windows.
            'applications' => '<rect x="3" y="5" width="12" height="10" rx="2"/><path d="M9 19h10a2 2 0 0 0 2-2V9"/><path d="M3 9h12"/>',
            // Sliders.
            'advanced' => '<path d="M5 6h14"/><path d="M5 12h14"/><path d="M5 18h14"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="8" cy="18" r="2"/>',
        ];

        return '<svg class="mcpc__navicon" viewBox="0 0 24 24" width="20" height="20" fill="none" '
            . 'stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" '
            . 'aria-hidden="true" focusable="false">' . ($paths[$panel] ?? $paths['dashboard']) . '</svg>';
    }

    /**
     * The panel to show on load.
     *
     * Read from the URL so a reload, a bookmark, or the redirect performed after
     * saving all land back on the panel the user was on. Validated against the
     * panel list, so nothing arbitrary reaches the markup.
     *
     * @return string A key of {@see self::panels()}.
     */
    public static function activePanel(): string
    {
        // A nonce would be meaningless here: this selects which panel to draw,
        // it changes nothing. The value is reduced to [a-z0-9_-] and then has to
        // match a panel key, so nothing arbitrary survives to reach the markup.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = isset($_GET['tab']) ? wp_unslash($_GET['tab']) : '';
        $requested = is_string($raw) ? sanitize_key($raw) : '';

        return array_key_exists($requested, self::panels()) ? $requested : 'dashboard';
    }

    /**
     * URL of the settings screen, optionally pointing at one panel.
     *
     * @param string                $panel     Panel key, or an empty string for the screen itself.
     * @param array<string, string> $arguments Extra query arguments.
     *
     * @return string The URL.
     */
    public static function url(string $panel = '', array $arguments = []): string
    {
        if ($panel !== '') {
            $arguments['tab'] = $panel;
        }

        return add_query_arg(
            $arguments,
            admin_url('options-general.php?page=' . self::MENU_SLUG)
        );
    }

    // -- Rendering --

    /**
     * Render the settings screen.
     */
    public function render(): void
    {
        // Read fresh rather than using the injected instance: this runs in the
        // same request as a save, and the injected copy predates it.
        $settings = Settings::current();
        $clients = (new ClientRepository())->all();
        $tokens = (new TokenRepository())->liveAccessTokenSummaries();
        $active = self::activePanel();

        printf('<div class="wrap mcpc" data-active-panel="%s">', esc_attr($active));
        echo '<div class="mcpc__shell">';

        $this->renderMasthead($settings);

        // WordPress relocates its notices to just after .wp-header-end, or to
        // just after the first heading when that marker is absent — which drops
        // "Settings saved." into the middle of the masthead. This puts them on
        // their own row, between the masthead and the body.
        echo '<hr class="wp-header-end">';

        $this->renderNotices($clients);

        echo '<div class="mcpc__body">';
        $this->renderNav($active, $clients);

        echo '<div class="mcpc__panels">';
        printf('<form method="post" id="%s" class="mcpc__form">', esc_attr(self::SETTINGS_FORM));
        wp_nonce_field(self::NONCE_SETTINGS);
        Field::hidden('mcpc_tab', $active);

        // Every panel is rendered and only hidden visually. A field that is not
        // in the DOM is not submitted, so drawing just the active panel would
        // empty every setting on the others each time the form is saved — and
        // two of those settings treat an empty list as a decision to honour.
        $this->renderDashboard($settings, $active, $clients, $tokens);
        $this->renderConnect($settings, $active);
        $this->renderTools($settings, $active);
        $this->renderSecurity($settings, $active);
        $this->renderApplications($active, $clients, $tokens);
        $this->renderAdvanced($settings, $active);

        $this->renderSaveBar($active);

        echo '</form>';

        // The second form, empty and outside the first. Its controls live in the
        // Applications panel and point back at it by id.
        printf('<form method="post" id="%s" hidden></form>', esc_attr(self::CLIENT_FORM));

        echo '</div></div></div></div>';
    }

    /**
     * Render the screen's header.
     *
     * @param Settings $settings The current configuration.
     */
    private function renderMasthead(Settings $settings): void
    {
        $status = Diagnostics::worst($settings);
        $labels = [
            Diagnostics::PASS => __('Ready to connect', 'amphibee-mcp-connector'),
            Diagnostics::WARN => __('Needs attention', 'amphibee-mcp-connector'),
            Diagnostics::FAIL => __('Nothing can connect', 'amphibee-mcp-connector'),
        ];

        printf(
            '<header class="mcpc__masthead">'
            . '<div class="mcpc__identity">%1$s'
            . '<div class="mcpc__identitytext"><h1 class="mcpc__title">%2$s</h1>'
            . '<p class="mcpc__tagline">%3$s</p></div></div>'
            . '<div class="mcpc__meta">'
            . '<span class="mcpc-pill mcpc-pill--%4$s">%5$s</span>'
            . '<span class="mcpc__version">v%6$s</span>'
            . '</div></header>',
            // A hardcoded SVG literal: no input reaches it, and escaping it would
            // print the markup instead of drawing it.
            self::mark(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            esc_html__('MCP Connector', 'amphibee-mcp-connector'),
            esc_html__('What this site lets an AI client read, write and call — and who is allowed to.', 'amphibee-mcp-connector'),
            esc_attr($status),
            esc_html($labels[$status]),
            esc_html(VERSION)
        );
    }

    /**
     * The plugin's mark, drawn inline rather than shipped as a file.
     *
     * Sized by the stylesheet, which is why the assistant can reuse it at a
     * different scale without a second copy.
     *
     * @return non-empty-string The SVG element.
     */
    public static function mark(): string
    {
        return '<svg class="mcpc__mark" viewBox="0 0 44 44" width="44" height="44" fill="none" '
            . 'stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" '
            . 'aria-hidden="true" focusable="false">'
            . '<rect x="3.2" y="12" width="14" height="20" rx="4"/>'
            . '<path d="M8 12V7"/><path d="M12.4 12V7"/>'
            . '<path d="M17.2 22h9.6"/>'
            . '<path d="M26.8 12h9a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2h-9z"/>'
            . '<path d="M31 19v6"/>'
            . '</svg>';
    }

    /**
     * Render the notices resulting from the previous action.
     *
     * @param list<Client> $clients Registered clients.
     */
    private function renderNotices(array $clients): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect result.
        if (isset($_GET['saved'])) {
            $this->notice('success', __('Settings saved.', 'amphibee-mcp-connector'));
        }

        if (isset($_GET['deleted'])) {
            $this->notice('warning', __('Application removed and its tokens revoked.', 'amphibee-mcp-connector'));
        }

        if (isset($_GET['error'])) {
            $this->notice('error', __('At least one valid redirect URI is required.', 'amphibee-mcp-connector'));
        }

        $createdId = isset($_GET['created'])
            ? sanitize_text_field(wp_unslash((string) $_GET['created']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($createdId === '') {
            return;
        }

        $secret = get_transient(self::SECRET_TRANSIENT . $createdId);
        delete_transient(self::SECRET_TRANSIENT . $createdId);

        $client = null;

        foreach ($clients as $candidate) {
            if ($candidate->id === $createdId) {
                $client = $candidate;

                break;
            }
        }

        if (! is_string($secret) || $secret === '' || ! $client instanceof Client) {
            return;
        }

        printf(
            '<div class="mcpc__panels"><div class="mcpc-secret">'
            . '<p class="mcpc-secret__title">%s</p>'
            . '<p class="mcpc-secret__help">%s</p>',
            esc_html__('Application created — copy these now', 'amphibee-mcp-connector'),
            esc_html__('The secret is stored only as a hash. This is the one time it can be read.', 'amphibee-mcp-connector')
        );

        Field::address(
            'mcpc-new-client-id',
            __('Client ID', 'amphibee-mcp-connector'),
            $client->id,
            __('Identifies the application. Not secret.', 'amphibee-mcp-connector')
        );

        Field::address(
            'mcpc-new-client-secret',
            __('Client secret', 'amphibee-mcp-connector'),
            $secret,
            __('Proves the application is itself. Treat it as a password; it will not be shown again.', 'amphibee-mcp-connector')
        );

        echo '</div></div>';
    }

    /**
     * Render the panel switcher.
     *
     * Real links, not buttons: with JavaScript they switch panels in place,
     * without it they load the same screen at the requested panel. Either way
     * the address bar names the panel, so a reload comes back to it.
     *
     * @param string       $active  The panel being shown.
     * @param list<Client> $clients Registered clients.
     */
    private function renderNav(string $active, array $clients): void
    {
        $counts = [
            'applications' => (string) count($clients),
            'tools' => (string) count(PostTypes::addressable()),
        ];

        echo '<nav class="mcpc__nav" aria-label="' . esc_attr__('Settings sections', 'amphibee-mcp-connector') . '">';
        echo '<ul class="mcpc__navlist" role="tablist" aria-orientation="vertical">';

        foreach (self::panels() as $id => $panel) {
            $selected = $id === $active;

            printf(
                '<li class="mcpc__navrow" role="presentation">'
                . '<a role="tab" class="mcpc__navitem" href="%1$s" id="mcpc-tab-%2$s" '
                . 'aria-controls="mcpc-panel-%2$s" aria-selected="%3$s" tabindex="%4$s" data-panel="%2$s">'
                . '%5$s'
                . '<span class="mcpc__navtext"><span class="mcpc__navlabel">%6$s</span>'
                . '<span class="mcpc__navdesc">%7$s</span></span>%8$s</a></li>',
                esc_url(self::url($id)),
                esc_attr($id),
                $selected ? 'true' : 'false',
                $selected ? '0' : '-1',
                // A hardcoded SVG literal from self::icon(): no input reaches it.
                self::icon($id), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                esc_html($panel['label']),
                esc_html($panel['description']),
                isset($counts[$id])
                    ? '<span class="mcpc__navcount">' . esc_html($counts[$id]) . '</span>'
                    : ''
            );
        }

        echo '</ul></nav>';
    }

    /**
     * Render the sticky save bar.
     *
     * @param string $active The panel being shown.
     */
    private function renderSaveBar(string $active): void
    {
        $quiet = ['dashboard', 'connect', 'applications'];

        printf(
            '<div class="mcpc__savebar"%s>'
            . '<p class="mcpc__savenote">%s</p>'
            . '<button type="submit" name="mcp_connector_save" value="1" class="mcpc-button mcpc-button--primary">%s</button>'
            . '</div>',
            in_array($active, $quiet, true) ? ' hidden' : '',
            esc_html__('Saving writes every panel at once, including the ones you have not opened.', 'amphibee-mcp-connector'),
            esc_html__('Save settings', 'amphibee-mcp-connector')
        );
    }

    /**
     * Open a panel element.
     *
     * @param string $id     Panel key.
     * @param string $active The panel being shown.
     * @param string $title  Panel heading.
     * @param string $intro  One or two sentences on what the panel decides.
     */
    private function openPanel(string $id, string $active, string $title, string $intro): void
    {
        printf(
            '<section class="mcpc__panel" id="mcpc-panel-%1$s" role="tabpanel" aria-labelledby="mcpc-tab-%1$s" tabindex="0"%2$s>'
            . '<div class="mcpc__panelhead"><h2 class="mcpc__paneltitle">%3$s</h2>'
            . '<p class="mcpc__panelintro">%4$s</p></div>',
            esc_attr($id),
            $id === $active ? '' : ' hidden',
            esc_html($title),
            esc_html($intro)
        );
    }

    /**
     * Render an admin notice.
     *
     * @param string $type    Notice type: success, warning, error or info.
     * @param string $message The message.
     */
    private function notice(string $type, string $message): void
    {
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    // -- Panels --

    /**
     * Render the dashboard.
     *
     * @param Settings                                                                      $settings The current configuration.
     * @param string                                                                        $active   The panel being shown.
     * @param list<Client>                                                                  $clients  Registered clients.
     * @param array<string, array{count: int, users: list<int>, issued: int, expires: int}> $tokens   Live token summaries by client.
     */
    private function renderDashboard(Settings $settings, string $active, array $clients, array $tokens): void
    {
        $this->openPanel(
            'dashboard',
            $active,
            __('Dashboard', 'amphibee-mcp-connector'),
            __('Whether a client can connect right now, and if not, which link of the chain is broken.', 'amphibee-mcp-connector')
        );

        $connected = count(array_filter($tokens, static fn (array $s): bool => $s['count'] > 0));
        $live = array_sum(array_column($tokens, 'count'));

        printf(
            '<dl class="mcpc-figures">'
            . '<div><dt>%s</dt><dd>%s</dd></div>'
            . '<div><dt>%s</dt><dd>%s</dd></div>'
            . '<div><dt>%s</dt><dd>%s</dd></div>'
            . '<div><dt>%s</dt><dd>%s</dd></div>'
            . '</dl>',
            esc_html__('Connected', 'amphibee-mcp-connector'),
            esc_html(number_format_i18n($connected)),
            esc_html__('Registered', 'amphibee-mcp-connector'),
            esc_html(number_format_i18n(count($clients))),
            esc_html__('Live tokens', 'amphibee-mcp-connector'),
            esc_html(number_format_i18n((int) $live)),
            esc_html__('Post types', 'amphibee-mcp-connector'),
            esc_html(number_format_i18n(count(PostTypes::addressable())))
        );

        printf('<h3 class="mcpc-subhead">%s</h3>', esc_html__('Checks', 'amphibee-mcp-connector'));

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Re-runs a read-only probe.
        Parts::checks(Diagnostics::all($settings, isset($_GET['probe'])));

        printf(
            '<p class="mcpc-note">%s <a class="mcpc-link" href="%s">%s</a></p>',
            esc_html__('The discovery answer is cached for five minutes, since the usual sequence is to read this, change the server, and come back.', 'amphibee-mcp-connector'),
            esc_url(self::url('dashboard', ['probe' => '1'])),
            esc_html__('Ask the server again', 'amphibee-mcp-connector')
        );

        printf('<h3 class="mcpc-subhead">%s</h3>', esc_html__('End-to-end test', 'amphibee-mcp-connector'));
        Parts::test();

        printf(
            '<p class="mcpc-note"><a class="mcpc-link" href="%s">%s</a></p>',
            esc_url(Wizard::url()),
            esc_html__('Run the setup assistant again', 'amphibee-mcp-connector')
        );

        echo '</section>';
    }

    /**
     * Render the connection details and the per-client walkthroughs.
     *
     * @param Settings $settings The current configuration.
     * @param string   $active   The panel being shown.
     */
    private function renderConnect(Settings $settings, string $active): void
    {
        $this->openPanel(
            'connect',
            $active,
            __('Connect', 'amphibee-mcp-connector'),
            __('One address is all a client needs. It reads everything else — the authorization server, the token endpoint, the tool list — from there.', 'amphibee-mcp-connector')
        );

        Field::address(
            'mcpc-server-url',
            __('MCP server URL', 'amphibee-mcp-connector'),
            ServerRegistry::endpointUrl(),
            __('This is the only value to hand over. Anything a client asks for beyond it, it can already work out.', 'amphibee-mcp-connector')
        );

        printf('<h3 class="mcpc-subhead">%s</h3>', esc_html__('Client by client', 'amphibee-mcp-connector'));
        Parts::guides($settings, ServerRegistry::endpointUrl());

        printf('<h3 class="mcpc-subhead">%s</h3>', esc_html__('The other addresses', 'amphibee-mcp-connector'));
        printf(
            '<p class="mcpc-note">%s</p>',
            esc_html__('Nothing needs to be given these; they are here because when a connection fails, the next question is which of them answers.', 'amphibee-mcp-connector')
        );
        Parts::endpoints();

        echo '</section>';
    }

    /**
     * Render what a client may call.
     *
     * @param Settings $settings The current configuration.
     * @param string   $active   The panel being shown.
     */
    private function renderTools(Settings $settings, string $active): void
    {
        $this->openPanel(
            'tools',
            $active,
            __('Tools', 'amphibee-mcp-connector'),
            __('Every published ability becomes one tool in the client. What is not here is refused, whatever a client asks for.', 'amphibee-mcp-connector')
        );

        printf('<h3 class="mcpc-subhead">%s</h3>', esc_html__('Ability groups', 'amphibee-mcp-connector'));
        echo '<div class="mcpc-stack">';

        foreach (AbilityRegistry::availableGroups() as $group) {
            Field::choice(
                'enabled_groups',
                $group->key(),
                $group->label(),
                $group->description(),
                $settings->isGroupEnabled($group->key())
            );
        }

        echo '</div>';

        $this->renderPostTypes();
        $this->renderExternalAbilities($settings);

        echo '</section>';
    }

    /**
     * Render the addressable post type selection.
     */
    private function renderPostTypes(): void
    {
        printf('<h3 class="mcpc-subhead">%s</h3>', esc_html__('Addressable post types', 'amphibee-mcp-connector'));
        printf(
            '<p class="mcpc__panelintro">%s</p>',
            esc_html__('Which types a client may list, read and write. A site accumulates types that are storage rather than content, and those have no business being offered to a model.', 'amphibee-mcp-connector')
        );

        $addressable = PostTypes::addressable();

        echo '<div class="mcpc-typegrid">';

        foreach (PostTypes::offerable() as $type) {
            $label = is_string($type->labels->name ?? null) && $type->labels->name !== ''
                ? $type->labels->name
                : $type->name;

            Field::typeCard(
                'addressable_post_types',
                $type->name,
                $label,
                $type->public
                    ? self::publishedCount($type->name)
                    : __('not public', 'amphibee-mcp-connector'),
                ! $type->public,
                in_array($type->name, $addressable, true)
            );
        }

        echo '</div>';
    }

    /**
     * Render the third-party ability selection, grouped by what is claimed.
     *
     * Grouped by claim rather than by plugin because the claim is what the
     * decision turns on. It is also the only grouping the data supports: there
     * is no signal anywhere in the Abilities API separating "writes a value"
     * from "rewrites the content model" — Meta Box files all twenty-three of its
     * abilities under one category and annotates `update-field-value` and
     * `create-post-type` identically. Inventing a tidier grouping would mean
     * inventing the information it rests on.
     *
     * @param Settings $settings The current configuration.
     */
    private function renderExternalAbilities(Settings $settings): void
    {
        $external = new ExternalAbilities($settings);
        $available = $external->available();

        printf('<h3 class="mcpc-subhead">%s</h3>', esc_html__('Abilities from other plugins', 'amphibee-mcp-connector'));

        if ($available === []) {
            printf(
                '<p class="mcpc-empty">%s</p>',
                esc_html__('No other active plugin publishes abilities for MCP.', 'amphibee-mcp-connector')
            );

            return;
        }

        printf(
            '<p class="mcpc__panelintro">%s</p>',
            esc_html__('Publishing these here puts them in the same connector, so a client sees one set of tools rather than one server per plugin. Each keeps its own permission checks. They are grouped by what their provider says about them, which is the only thing that separates reading a field from deleting a field group.', 'amphibee-mcp-connector')
        );

        // Before the first save there is no stored selection, so show what would
        // actually be published rather than an empty list the administrator
        // would read as "nothing is exposed".
        $selected = $external->selected();

        if (! $settings->externalAbilitiesReviewed) {
            printf(
                '<p class="mcpc-note">%s</p>',
                esc_html__('These are the recommended defaults, in force until you save this screen. Reading is selected wherever a plugin declares it; anything a shipped profile marks as structural is not.', 'amphibee-mcp-connector')
            );
        }

        foreach ($available as $provider => $abilities) {
            $recommended = $external->defaultsFor($provider);

            printf(
                '<div class="mcpc-provider"><div class="mcpc-provider__head">'
                . '<h4 class="mcpc-provider__name">%s</h4>'
                . '<span class="mcpc-provider__count">%s</span></div>',
                esc_html($provider),
                esc_html(sprintf(
                    /* translators: %d: number of abilities offered by one plugin. */
                    _n('%d ability offered', '%d abilities offered', count($abilities), 'amphibee-mcp-connector'),
                    count($abilities)
                ))
            );

            foreach (self::byClaim($abilities) as $claim) {
                printf(
                    '<div class="mcpc-claim"><div class="mcpc-claim__head">'
                    . '<h5 class="mcpc-claim__title">%s</h5>'
                    . '<span class="mcpc-claim__note">%s</span></div>',
                    esc_html($claim['title']),
                    esc_html($claim['note'])
                );

                foreach ($claim['abilities'] as $ability) {
                    Field::ability(
                        'external_abilities',
                        $ability->name,
                        $ability->label,
                        $ability->description,
                        in_array($ability->name, $recommended, true),
                        in_array($ability->name, $selected, true)
                    );
                }

                echo '</div>';
            }

            echo '</div>';
        }
    }

    /**
     * Sort a provider's abilities into the four claims the annotations support.
     *
     * @param list<ExternalAbility> $abilities The provider's abilities.
     *
     * @return list<array{title: string, note: string, abilities: list<ExternalAbility>}> The non-empty groups, safest first.
     */
    private static function byClaim(array $abilities): array
    {
        $buckets = [
            'read' => [
                'title' => __('Reads only', 'amphibee-mcp-connector'),
                'note' => __('The provider declares these read-only.', 'amphibee-mcp-connector'),
                'abilities' => [],
            ],
            'write' => [
                'title' => __('Writes', 'amphibee-mcp-connector'),
                'note' => __('Declared as changing something, without claiming to destroy anything.', 'amphibee-mcp-connector'),
                'abilities' => [],
            ],
            'destructive' => [
                'title' => __('Writes, and may delete or overwrite', 'amphibee-mcp-connector'),
                'note' => __('The provider marks these destructive.', 'amphibee-mcp-connector'),
                'abilities' => [],
            ],
            'unknown' => [
                'title' => __('Undeclared', 'amphibee-mcp-connector'),
                'note' => __('The provider says nothing about what these do. Silence is not a claim of harmlessness.', 'amphibee-mcp-connector'),
                'abilities' => [],
            ],
        ];

        foreach ($abilities as $ability) {
            $key = match (true) {
                $ability->readonly === true => 'read',
                $ability->destructive === true => 'destructive',
                $ability->readonly === false => 'write',
                default => 'unknown',
            };

            $buckets[$key]['abilities'][] = $ability;
        }

        return array_values(array_filter(
            $buckets,
            static fn (array $bucket): bool => $bucket['abilities'] !== []
        ));
    }

    /**
     * Render the security choices.
     *
     * @param Settings $settings The current configuration.
     * @param string   $active   The panel being shown.
     */
    private function renderSecurity(Settings $settings, string $active): void
    {
        $this->openPanel(
            'security',
            $active,
            __('Security', 'amphibee-mcp-connector'),
            __('Three decisions: who may authorise a client, how far a client may reach, and whether a client may introduce itself.', 'amphibee-mcp-connector')
        );

        Field::input(
            'required_capability',
            __('Required capability', 'amphibee-mcp-connector'),
            __('A user needs this to authorise a client and to reach the endpoint. It is checked again on every token refresh, so demoting a user disconnects them rather than merely stopping them from reconnecting.', 'amphibee-mcp-connector'),
            $settings->requiredCapability,
            true
        );

        echo '<div class="mcpc-stack">';

        Field::toggle(
            'read_only_mode',
            __('Read-only mode', 'amphibee-mcp-connector'),
            __('Abilities that would change the site are not registered at all — not refused at call time, absent from the tool list. This is the safe way to bring a connector up on a live site: prove the transport and the authentication first.', 'amphibee-mcp-connector'),
            $settings->readOnlyMode
        );

        Field::toggle(
            'oauth_enabled',
            __('Built-in OAuth 2.1 provider', 'amphibee-mcp-connector'),
            __('Serves the discovery, authorization, token and registration endpoints. Required for a remote client such as Claude. Turn it off only if something else on the site handles authentication.', 'amphibee-mcp-connector'),
            $settings->oauthEnabled
        );

        Field::toggle(
            'dynamic_registration_open',
            __('Let clients register themselves', 'amphibee-mcp-connector'),
            __('RFC 7591, and what makes a one-click connection possible. Registering grants nothing on its own: a user still has to approve the request on the consent screen, holding the capability above. Turn it off to require that every application be created by hand.', 'amphibee-mcp-connector'),
            $settings->dynamicRegistrationOpen
        );

        echo '</div>';

        if ($settings->readOnlyMode) {
            printf(
                '<p class="mcpc-note">%s</p>',
                esc_html__('Read-only mode is on, so the write-side tools are absent from the tool list whatever the Tools panel says.', 'amphibee-mcp-connector')
            );
        }

        echo '</section>';
    }

    /**
     * Render the connected applications and the manual creation form.
     *
     * @param string                                                                        $active  The panel being shown.
     * @param list<Client>                                                                  $clients Registered clients.
     * @param array<string, array{count: int, users: list<int>, issued: int, expires: int}> $tokens  Live token summaries by client.
     */
    private function renderApplications(string $active, array $clients, array $tokens): void
    {
        $this->openPanel(
            'applications',
            $active,
            __('Applications', 'amphibee-mcp-connector'),
            __('Every client that has registered against this site. Removing one revokes its tokens with it, and the application is disconnected on its next call.', 'amphibee-mcp-connector')
        );

        if ($clients === []) {
            printf(
                '<p class="mcpc-empty">%s</p>',
                esc_html__('Nothing registered yet. Connecting from a client registers it automatically, or create one below.', 'amphibee-mcp-connector')
            );
        } else {
            echo '<div class="mcpc-apps">';

            foreach ($clients as $client) {
                $this->renderApplication($client, $tokens[$client->id] ?? null);
            }

            echo '</div>';
        }

        $this->renderClientForm();

        echo '</section>';
    }

    /**
     * Render one registered client.
     *
     * @param Client                                                                   $client  The client.
     * @param array{count: int, users: list<int>, issued: int, expires: int}|null      $summary Its live tokens, if any.
     */
    private function renderApplication(Client $client, ?array $summary): void
    {
        $deleteUrl = wp_nonce_url(
            self::url('applications', ['mcp_connector_delete_client' => $client->id]),
            self::NONCE_DELETE_CLIENT
        );

        $live = $summary['count'] ?? 0;

        printf(
            '<article class="mcpc-app%1$s">'
            . '<header class="mcpc-app__head"><div><h3 class="mcpc-app__name">%2$s</h3>'
            . '<code class="mcpc-app__id">%3$s</code></div>%4$s</header>'
            . '<dl class="mcpc-app__stats">'
            . '<div><dt>%5$s</dt><dd>%6$s</dd></div>'
            . '<div><dt>%7$s</dt><dd>%8$s</dd></div>'
            . '<div><dt>%9$s</dt><dd>%10$s</dd></div>'
            . '</dl>'
            . '<p class="mcpc-app__uris">%11$s</p>'
            . '<footer class="mcpc-app__foot">'
            . '<a href="%12$s" class="mcpc-button mcpc-button--danger" onclick="return confirm(%13$s)">%14$s</a>'
            . '</footer></article>',
            $live > 0 ? '' : ' is-idle',
            esc_html($client->name),
            esc_html($client->id),
            $client->selfRegistered
                ? '<span class="mcpc-badge mcpc-badge--quiet">' . esc_html__('self-registered', 'amphibee-mcp-connector') . '</span>'
                : '',
            esc_html__('Live tokens', 'amphibee-mcp-connector'),
            esc_html(number_format_i18n($live)),
            esc_html__('Registered', 'amphibee-mcp-connector'),
            esc_html(self::formatTime($client->createdAt)),
            esc_html__('Token last issued', 'amphibee-mcp-connector'),
            esc_html(
                isset($summary['issued']) && $summary['issued'] > 0
                    ? self::formatTime($summary['issued'])
                    : __('never', 'amphibee-mcp-connector')
            ),
            esc_html(implode(' · ', $client->redirectUris)),
            esc_url($deleteUrl),
            esc_attr(wp_json_encode(
                __('Remove this application and revoke its tokens?', 'amphibee-mcp-connector')
            ) ?: '""'),
            esc_html__('Remove', 'amphibee-mcp-connector')
        );
    }

    /**
     * Render the manual client creation controls.
     *
     * Their fields belong to the form declared after the configuration form, not
     * to the one they sit inside.
     */
    private function renderClientForm(): void
    {
        printf('<h3 class="mcpc-subhead">%s</h3>', esc_html__('Create an application by hand', 'amphibee-mcp-connector'));
        printf(
            '<p class="mcpc__panelintro">%s</p>',
            esc_html__('For a client that will not register itself, or for a site that keeps self-registration closed. The secret is shown once, at creation.', 'amphibee-mcp-connector')
        );

        Field::hidden('_wpnonce', wp_create_nonce(self::NONCE_CREATE_CLIENT), self::CLIENT_FORM);
        Field::hidden('mcpc_tab', 'applications', self::CLIENT_FORM);

        Field::input(
            'client_name',
            __('Name', 'amphibee-mcp-connector'),
            __('Shown in this list and on the consent screen the user approves.', 'amphibee-mcp-connector'),
            'Claude',
            false,
            self::CLIENT_FORM
        );

        Field::textarea(
            'redirect_uris',
            __('Redirect URIs', 'amphibee-mcp-connector'),
            __('One per line, matched exactly — a URI differing by so much as a trailing slash is refused. The values below are the ones the Claude applications use.', 'amphibee-mcp-connector'),
            implode("\n", ClientGuides::CLAUDE_REDIRECT_URIS),
            3,
            '',
            self::CLIENT_FORM
        );

        printf(
            '<button type="submit" name="mcp_connector_create_client" value="1" form="%s" '
            . 'class="mcpc-button mcpc-button--quiet">%s</button>',
            esc_attr(self::CLIENT_FORM),
            esc_html__('Create application', 'amphibee-mcp-connector')
        );
    }

    /**
     * Render the names and addresses that are rarely worth changing.
     *
     * @param Settings $settings The current configuration.
     * @param string   $active   The panel being shown.
     */
    private function renderAdvanced(Settings $settings, string $active): void
    {
        $this->openPanel(
            'advanced',
            $active,
            __('Advanced', 'amphibee-mcp-connector'),
            __('Both of these are baked into what a connected client already knows. Changing either is a reconnection, not a setting.', 'amphibee-mcp-connector')
        );

        Field::input(
            'ability_namespace',
            __('Ability namespace', 'amphibee-mcp-connector'),
            __('Prefixes every ability, and with it every MCP tool name. Changing it renames the tools a connected client has already learned, which reads to it as the old ones disappearing.', 'amphibee-mcp-connector'),
            $settings->abilityNamespace,
            true
        );

        Field::input(
            'server_route',
            __('Server route', 'amphibee-mcp-connector'),
            __('The last segment of the MCP endpoint URL. Changing it invalidates the address already handed to every client.', 'amphibee-mcp-connector'),
            $settings->serverRoute,
            true
        );

        printf(
            '<p class="mcpc-note">%s <code>%s</code></p>',
            esc_html__('The endpoint currently answers at:', 'amphibee-mcp-connector'),
            esc_html(ServerRegistry::endpointUrl())
        );

        echo '</section>';
    }

    // -- Internals --

    /**
     * Published entries for a post type, as a short human string.
     *
     * @param string $postType The post type slug.
     *
     * @return string The count, translated.
     */
    private static function publishedCount(string $postType): string
    {
        $counts = wp_count_posts($postType);
        $published = is_object($counts) ? ($counts->publish ?? 0) : 0;
        $total = is_numeric($published) ? (int) $published : 0;

        return sprintf(
            /* translators: %s: number of published entries. */
            _n('%s published', '%s published', $total, 'amphibee-mcp-connector'),
            number_format_i18n($total)
        );
    }

    /**
     * A timestamp in the site's own date and time format.
     *
     * @param int $timestamp Unix timestamp.
     *
     * @return string The formatted date.
     */
    private static function formatTime(int $timestamp): string
    {
        $date = get_option('date_format');
        $time = get_option('time_format');

        $format = (is_string($date) ? $date : 'Y-m-d') . ' ' . (is_string($time) ? $time : 'H:i');
        $formatted = wp_date($format, $timestamp);

        return is_string($formatted) ? $formatted : gmdate('Y-m-d H:i', $timestamp);
    }
}

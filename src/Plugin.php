<?php

declare(strict_types=1);

namespace Pollora\McpConnector;

use Pollora\McpConnector\Abilities\AbilityRegistry;
use Pollora\McpConnector\Admin\SettingsPage;
use Pollora\McpConnector\OAuth\ExpiredGrantCollector;
use Pollora\McpConnector\OAuth\OAuthServer;
use Pollora\McpConnector\Server\ServerRegistry;
use Pollora\McpConnector\Support\Environment;

defined('ABSPATH') || exit;

/**
 * Composition root: decides which subsystems run, and wires them to WordPress.
 *
 * Nothing in this class talks to WordPress beyond adding hooks. Each subsystem
 * owns its own hooks and its own state, which keeps the boot sequence readable
 * and lets any one of them be exercised in isolation.
 */
final class Plugin
{
    /**
     * The single booted instance.
     */
    private static ?self $instance = null;

    /**
     * Guards against a double boot, which would register every hook twice.
     */
    private bool $booted = false;

    /**
     * Private on purpose: use {@see Plugin::instance()}.
     */
    private function __construct()
    {
    }

    /**
     * Retrieve the shared plugin instance.
     *
     * @return self The singleton.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Wire every subsystem that the current environment can support.
     *
     * Subsystems are independent: a missing MCP Adapter costs you the MCP
     * endpoint but leaves the abilities registered (still reachable over the
     * core REST abilities controllers) and the OAuth provider running.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $settings = Settings::current();

        if (Environment::hasAbilitiesApi()) {
            (new AbilityRegistry($settings))->register();

            if (Environment::hasMcpAdapter()) {
                (new ServerRegistry($settings))->register();
            }
        }

        if ($settings->oauthEnabled) {
            (new OAuthServer($settings))->register();
            (new ExpiredGrantCollector())->register();
        }

        if (is_admin()) {
            (new SettingsPage())->register();

            add_action('admin_notices', $this->renderRequirementNotices(...));
        }
    }

    /**
     * Warn administrators about dependencies the plugin needs but cannot install.
     *
     * Shown only to users who could act on it, and only on the plugin's own
     * screens plus the plugins list, so it does not become wallpaper.
     */
    private function renderRequirementNotices(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        $relevant = ['plugins', 'settings_page_' . SettingsPage::MENU_SLUG];

        if (! $screen instanceof \WP_Screen || ! in_array($screen->id, $relevant, true)) {
            return;
        }

        foreach (Environment::unmetRequirements() as $key => $message) {
            printf(
                '<div class="notice notice-warning" data-mcp-connector-requirement="%s"><p><strong>%s</strong> %s</p></div>',
                esc_attr($key),
                esc_html__('MCP Connector:', 'amphibee-mcp-connector'),
                esc_html($message),
            );
        }
    }
}

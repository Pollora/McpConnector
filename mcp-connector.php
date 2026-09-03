<?php
/**
 * Plugin Name: AmphiBee MCP Connector
 * Description: Exposes WordPress content management as Model Context Protocol tools, with a built-in OAuth 2.1 provider so remote clients such as Claude can connect to the site.
 * Version: 1.2.2
 * Author: AmphiBee
 * Author URI: https://amphibee.fr
 * Requires PHP: 8.3
 * Requires at least: 6.9
 * License: GPL-2.0-or-later
 * Text Domain: amphibee-mcp-connector
 * Domain Path: /languages
 *
 * ⚠️ There is deliberately no `Requires Plugins: mcp-adapter` header, and that
 * is a decision rather than an oversight.
 *
 * The header would be accurate — the MCP Adapter really is required for the MCP
 * transport, and WordPress resolves the slug against installed directory names
 * rather than against wordpress.org, so the activation gate would work. What
 * does not work is the other half: the "Install now" link WordPress offers for a
 * missing dependency queries wordpress.org, and the adapter is not there. Anyone
 * installing this plugin from the directory would meet a refusal to activate
 * followed by a link that leads nowhere. A dead end is worse than a missing
 * guard rail.
 *
 * So the dependency is enforced where it can also be explained: nothing fatals
 * without the adapter, the OAuth provider and the abilities keep working, and
 * the settings screen states in one line what is missing and the two commands
 * that fix it. See {@see \Pollora\McpConnector\Support\Environment} for the
 * runtime guards and {@see \Pollora\McpConnector\Admin\Diagnostics} for the
 * remedy it prints.
 *
 * What is lost with the header: WordPress no longer refuses to deactivate the
 * adapter while this plugin is running. Deactivating it now silently removes the
 * MCP endpoint, and the dashboard is where that becomes visible.
 *
 * @package Pollora\McpConnector
 */

declare(strict_types=1);

namespace Pollora\McpConnector;

defined('ABSPATH') || exit;

/**
 * Plugin version, mirrored in the `Version:` header above.
 *
 * @var string
 */
const VERSION = '1.2.2';

/**
 * Absolute path to the plugin's main file.
 *
 * @var string
 */
const PLUGIN_FILE = __FILE__;

/**
 * Absolute path to the plugin directory, without a trailing slash.
 *
 * @var string
 */
const PLUGIN_DIR = __DIR__;

// Only the release zip carries a vendor/ directory — it is where the bundled
// pollora/abilities lives. A Composer install has none, and does not need one:
// both this package's PSR-4 mapping and its dependency are already in the
// consuming project's autoloader. Requiring the file unconditionally made every
// Composer install fatal the moment it was activated.
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Neither source produced the classes — a zip built without its dependencies,
// or a project whose autoloader is not loaded. Say so where someone can read
// it instead of fataling on the next line.
if (! class_exists(Plugin::class)) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__(
                'MCP Connector cannot find its classes. Install the plugin with Composer, or from a release zip.',
                'amphibee-mcp-connector',
            ),
        );
    });

    return;
}

/**
 * Boot the plugin once all other plugins are loaded.
 *
 * Booting on `plugins_loaded` rather than at file scope matters for two
 * reasons: the MCP Adapter plugin has to have declared its classes before we
 * can detect it, and the Abilities API may itself come from a plugin rather
 * than from core.
 */
add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});

/**
 * Load the bundled translations.
 *
 * On `init`, not earlier: since WordPress 6.7, loading a text domain before then
 * raises a `_load_textdomain_just_in_time` notice. A site installing from
 * wordpress.org would get its translations without this; one installing the zip,
 * or pulling the plugin in over Composer or by rsync, would not.
 */
add_action('init', static function (): void {
    load_plugin_textdomain(
        'amphibee-mcp-connector',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages',
    );
});

/**
 * Seed the configuration on first activation.
 *
 * No rewrite flush is needed, and that is deliberate: the front-end OAuth paths
 * are matched against the request path on `parse_request` rather than through
 * rewrite rules, so they answer on the first request after the plugin files land
 * on the server — with no flush to forget and no persisted rule set to go stale.
 */
register_activation_hook(__FILE__, static function (): void {
    if (! get_option(Settings::OPTION)) {
        update_option(Settings::OPTION, Settings::defaults(), false);
    }
});

/**
 * Clean up scheduled work on deactivation.
 *
 * Tokens and registered clients are left alone. Deactivating a plugin is
 * routinely how an administrator tests something, and it should not silently
 * disconnect every client that would otherwise still work on reactivation.
 * Uninstalling is where data removal belongs.
 */
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook(OAuth\ExpiredGrantCollector::CRON_HOOK);
});

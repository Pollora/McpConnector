<?php
/**
 * Removes every trace of the plugin when it is deleted from the admin.
 *
 * Deactivation is reversible and deliberately leaves tokens and registered
 * clients alone — deactivating is routinely how an administrator tests
 * something, and it should not disconnect every client. Deletion is not
 * reversible, so this is where the data goes.
 *
 * WordPress loads this file instead of the plugin itself, so nothing from the
 * plugin's own namespace is available here. Every name below is therefore a
 * literal, and each one is a copy of a class constant:
 *
 * | Literal here                             | Declared in                              |
 * |------------------------------------------|------------------------------------------|
 * | `mcp_connector_settings`                 | `Settings::OPTION`                       |
 * | `mcp_connector_oauth_clients`            | `OAuth\ClientRepository::OPTION`         |
 * | `mcp_connector_oauth_access_tokens`      | `OAuth\TokenRepository::ACCESS_OPTION`   |
 * | `mcp_connector_oauth_refresh_tokens`     | `OAuth\TokenRepository::REFRESH_OPTION`  |
 * | `mcp_connector_new_secret_`              | `Admin\SettingsPage::SECRET_TRANSIENT`   |
 * | `mcp_connector_purge_expired_grants`     | `OAuth\ExpiredGrantCollector::CRON_HOOK` |
 * | `mcp_connector_discovery_probe`          | `Support\Environment` (local variable)   |
 *
 * A test asserts that this list and those constants still agree, because the
 * failure mode of them drifting apart is silent: uninstalling would simply
 * leave rows behind, and nobody would look.
 *
 * @package Pollora\McpConnector
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

/**
 * Deletes the plugin's options, transients and scheduled event for one site.
 */
function mcp_connector_uninstall_site(): void
{
    // Before the options go: the one-shot secret transients are keyed by client
    // identifier, and the client list is the only place those identifiers exist.
    mcp_connector_uninstall_delete_secret_transients();

    delete_option('mcp_connector_settings');
    delete_option('mcp_connector_oauth_clients');
    delete_option('mcp_connector_oauth_access_tokens');
    delete_option('mcp_connector_oauth_refresh_tokens');

    delete_transient('mcp_connector_discovery_probe');

    wp_clear_scheduled_hook('mcp_connector_purge_expired_grants');

    // `mcp_connector_oauth_code_*` transients are not swept, and that is a
    // decision. Their keys are derived from the authorization code itself,
    // which is never stored in plaintext anywhere — so there is nothing to
    // derive them from, and a LIKE query over the options table would find
    // nothing at all on a site with a persistent object cache. They are
    // single-use and live five minutes; anything still present at uninstall
    // time expires on its own.
}

/**
 * Deletes the one-shot client secret transients.
 *
 * These hold a freshly generated secret between the redirect that creates a
 * client and the screen that shows it once. Keys are derived from the client
 * identifiers rather than found by sweeping the options table: that stays exact,
 * and it works the same whether transients live in the database or in an object
 * cache.
 */
function mcp_connector_uninstall_delete_secret_transients(): void
{
    $clients = get_option('mcp_connector_oauth_clients', []);

    if (! is_array($clients)) {
        return;
    }

    foreach (array_keys($clients) as $client_id) {
        if (is_string($client_id) && $client_id !== '') {
            delete_transient('mcp_connector_new_secret_' . $client_id);
        }
    }
}

if (is_multisite()) {
    $mcp_connector_sites = get_sites(['fields' => 'ids', 'number' => 0]);

    foreach ($mcp_connector_sites as $mcp_connector_site_id) {
        switch_to_blog((int) $mcp_connector_site_id);
        mcp_connector_uninstall_site();
        restore_current_blog();
    }

    unset($mcp_connector_sites, $mcp_connector_site_id);
} else {
    mcp_connector_uninstall_site();
}

<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

defined('ABSPATH') || exit;

/**
 * Removes expired tokens, and tokens belonging to deleted users.
 *
 * Tokens live in options rather than transients, which buys reliability across
 * object-cache flushes but costs automatic expiry. Without this, an option that
 * is read on every authenticated request would grow by two records an hour, for
 * ever — a slow leak that only becomes visible as an unexplained slowdown some
 * months later.
 *
 * Expired records are already treated as absent when read, so this is
 * housekeeping rather than enforcement. Deleting a user is different: that has
 * to revoke immediately, because the tokens themselves are still within their
 * validity window.
 */
final class ExpiredGrantCollector
{
    /**
     * Cron hook that performs the sweep.
     *
     * @var string
     */
    public const CRON_HOOK = 'mcp_connector_purge_expired_grants';

    /**
     * Attach the scheduling and cleanup hooks.
     */
    public function register(): void
    {
        add_action('init', $this->scheduleSweep(...));
        add_action(self::CRON_HOOK, $this->sweep(...));

        // Both hooks: `delete_user` fires on single sites, `wpmu_delete_user`
        // on multisite, and neither fires in place of the other.
        add_action('delete_user', $this->revokeForUser(...));
        add_action('wpmu_delete_user', $this->revokeForUser(...));
    }

    /**
     * Ensure the daily sweep is scheduled.
     */
    public function scheduleSweep(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
    }

    /**
     * Remove every expired token record.
     */
    public function sweep(): void
    {
        (new TokenRepository())->purgeExpired();
    }

    /**
     * Revoke every token belonging to a user being deleted.
     *
     * @param int $userId Identifier of the user being deleted.
     */
    public function revokeForUser(int $userId): void
    {
        (new TokenRepository())->revokeUser($userId);
    }
}

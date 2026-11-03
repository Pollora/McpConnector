<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Support;

defined('ABSPATH') || exit;

/**
 * A mutual-exclusion lock kept in the options table.
 *
 * WordPress has no lock of its own, and the object cache cannot stand in for
 * one: without a persistent cache it lives for a single request. The database
 * can, because `option_name` is unique. This is the technique core uses for
 * `WP_Upgrader::create_lock()`: an `INSERT IGNORE` either adds the row, so the
 * caller holds the lock, or adds nothing, because someone else does.
 *
 * Two details differ from core. The lock's value is a token only its holder
 * knows, so releasing it, or taking over a stale one, deletes that exact row
 * and never one a later holder has since written. And a lock older than the
 * timeout is taken to belong to a request that died, and is replaced.
 *
 * The rows are never read through `get_option()`: they would otherwise pass
 * through the object cache, which is exactly what a lock must not depend on.
 */
final class OptionLock
{
    /**
     * Option name prefix of every lock row.
     */
    private const string PREFIX = 'mcp_connector_lock_';

    /**
     * How long to sleep between two attempts, in microseconds.
     */
    private const int RETRY_DELAY = 25_000;

    /**
     * Token of the lock held under each name.
     *
     * @var array<string, string>
     */
    private array $held = [];

    /**
     * @param int $timeout          Seconds after which a held lock is taken to be abandoned.
     * @param int $waitMilliseconds How long to keep trying before giving up; zero tries once.
     */
    public function __construct(
        private readonly int $timeout = 10,
        private readonly int $waitMilliseconds = 1000,
    ) {
    }

    /**
     * Take the lock, waiting for it if another request holds it.
     *
     * @param string $name What is being protected.
     *
     * @return bool True when the caller now holds the lock.
     */
    public function acquire(string $name): bool
    {
        $deadline = microtime(true) + $this->waitMilliseconds / 1000;

        do {
            if ($this->attempt($name)) {
                return true;
            }

            usleep(self::RETRY_DELAY);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * Give the lock back. Releasing a lock that is not held, or that was lost to
     * a takeover in the meantime, does nothing.
     *
     * @param string $name What was being protected.
     */
    public function release(string $name): void
    {
        if (! isset($this->held[$name])) {
            return;
        }

        $this->deleteRow(self::PREFIX . $name, $this->held[$name]);

        unset($this->held[$name]);
    }

    /**
     * One attempt at taking the lock.
     *
     * @param string $name What is being protected.
     *
     * @return bool True when the lock was taken.
     */
    private function attempt(string $name): bool
    {
        global $wpdb;

        $option = self::PREFIX . $name;
        $token = time() . ':' . bin2hex(random_bytes(8));

        if ($this->insertRow($option, $token)) {
            $this->held[$name] = $token;

            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock row is read straight from the table on purpose, see the class comment.
        $current = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $option));

        if (! is_string($current) || (int) $current > time() - $this->timeout) {
            return false;
        }

        // Abandoned. Deleting the row only while it still holds the value that
        // was just read means two requests finding it stale cannot both take it
        // over: the second one deletes nothing, and its insert is refused.
        $this->deleteRow($option, $current);

        if (! $this->insertRow($option, $token)) {
            return false;
        }

        $this->held[$name] = $token;

        return true;
    }

    /**
     * Insert the lock row unless one exists.
     *
     * @param string $option Option name of the lock.
     * @param string $token  Value to store.
     *
     * @return bool True when the row was added.
     */
    private function insertRow(string $option, string $token): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE is the atomic step; no WordPress function offers it.
        return (bool) $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* LOCK */",
                $option,
                $token,
            ),
        );
    }

    /**
     * Delete the lock row, but only while it still holds the given value.
     *
     * @param string $option Option name of the lock.
     * @param string $token  The value the caller believes is stored.
     */
    private function deleteRow(string $option, string $token): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete, so a later holder's row is never removed.
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND option_value = %s", $option, $token));
    }
}

<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Support;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * Builds the `WP_Error` objects abilities return when they cannot proceed.
 *
 * Returning a `WP_Error` rather than an array with an `error` key matters: the
 * MCP Adapter recognises it and answers with `isError: true`, which is how a
 * client learns the call failed. An error-shaped success payload looks like a
 * successful call that happened to return the word "error", and models act on it.
 *
 * Messages are written for a model that will read them and decide what to do
 * next, so they say what was wrong and, where there is one, what would work.
 */
final class Failure
{
    /**
     * Not instantiable: every member is a static factory.
     */
    private function __construct()
    {
    }

    /**
     * The requested record does not exist.
     *
     * @param string     $subject Human-readable kind of record, e.g. `post` or `term`.
     * @param int|string $handle  Identifier or slug that was looked up.
     *
     * @return WP_Error The error.
     */
    public static function notFound(string $subject, int|string $handle): WP_Error
    {
        return new WP_Error(
            'mcp_connector_not_found',
            sprintf(
                /* translators: 1: kind of record, 2: identifier that was looked up. */
                __('No %1$s found for "%2$s".', 'amphibee-mcp-connector'),
                $subject,
                (string) $handle
            ),
            ['status' => 404]
        );
    }

    /**
     * The caller is authenticated but not allowed to do this.
     *
     * @param string $action What was attempted, phrased as an infinitive without "to".
     *
     * @return WP_Error The error.
     */
    public static function forbidden(string $action): WP_Error
    {
        return new WP_Error(
            'mcp_connector_forbidden',
            sprintf(
                /* translators: %s: the attempted action. */
                __('The authenticated user is not allowed to %s.', 'amphibee-mcp-connector'),
                $action
            ),
            ['status' => 403]
        );
    }

    /**
     * The input was well-formed against the schema but not usable.
     *
     * For everything the JSON Schema cannot express: a taxonomy that is not
     * registered, a term slug with no match, an option outside the allow-list.
     *
     * @param string $message What is wrong, and what an acceptable value looks like.
     *
     * @return WP_Error The error.
     */
    public static function invalid(string $message): WP_Error
    {
        return new WP_Error('mcp_connector_invalid_input', $message, ['status' => 400]);
    }

    /**
     * WordPress itself refused the operation.
     *
     * The original error is preserved as the cause so its code survives into the
     * response, rather than being flattened into a string.
     *
     * @param WP_Error $cause  The error WordPress returned.
     * @param string   $action What was being attempted when it failed.
     *
     * @return WP_Error The wrapped error.
     */
    public static function fromWordPress(WP_Error $cause, string $action): WP_Error
    {
        return new WP_Error(
            $cause->get_error_code() ?: 'mcp_connector_wordpress_error',
            sprintf(
                /* translators: 1: the attempted action, 2: the underlying error message. */
                __('Could not %1$s: %2$s', 'amphibee-mcp-connector'),
                $action,
                $cause->get_error_message()
            ),
            $cause->get_error_data() ?: ['status' => 500]
        );
    }
}

<?php

declare(strict_types=1);

namespace Pollora\McpConnector\OAuth;

defined('ABSPATH') || exit;

/**
 * Thrown when a client cannot be self-registered: the ceiling is reached and not
 * enough clients that never connected can be purged to make room, or the client
 * store is busy.
 */
final class ClientLimitReachedException extends \RuntimeException
{
}

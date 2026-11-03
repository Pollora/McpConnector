<?php

declare(strict_types=1);

/**
 * Stand-ins for the two core classes OAuthServer type-hints on: the REST
 * server (only its method constants are read) and the WP environment object.
 */

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const string READABLE = 'GET';

        public const string CREATABLE = 'POST';
    }
}

if (!class_exists('WP')) {
    class WP
    {
    }
}

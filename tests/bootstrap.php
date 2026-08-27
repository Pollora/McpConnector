<?php
/**
 * Unit test bootstrap.
 *
 * These tests run without a WordPress installation: Brain Monkey replaces the
 * core functions, and the few core classes the plugin type-hints against are
 * declared here as minimal doubles. Anything that genuinely needs a database,
 * the rewrite engine or a web server in front of it — which is most of the OAuth
 * flow — belongs in an end-to-end run against a real install, not here.
 *
 * @package Pollora\McpConnector
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Every source file guards on ABSPATH; tests need it defined before anything
// else, or autoloading a class silently exits the process.
defined('ABSPATH') || define('ABSPATH', __DIR__ . '/Fixtures/wordpress/');

// WordPress time constants. The OAuth lifetimes are expressed in them.
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
defined('WEEK_IN_SECONDS') || define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);
defined('MONTH_IN_SECONDS') || define('MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS);

// Return-format constants.
defined('OBJECT') || define('OBJECT', 'OBJECT');
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
defined('ARRAY_N') || define('ARRAY_N', 'ARRAY_N');

// The plugin's own namespaced constants, mirroring mcp-connector.php.
// Kept in sync by tests/Unit/DistributionTest.php.
require_once __DIR__ . '/Fixtures/plugin-constants.php';

// Minimal doubles for the core classes the plugin type-hints against.
require_once __DIR__ . '/Fixtures/wp-classes.php';

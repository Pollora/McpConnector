<?php
/**
 * The namespaced constants mcp-connector.php declares.
 *
 * The plugin file itself cannot be loaded in unit tests: it registers hooks at
 * file scope, before Brain Monkey is up, and requires vendor/autoload.php a
 * second time. DistributionTest asserts these values still match the ones in
 * mcp-connector.php, so the duplication cannot drift.
 *
 * @package Pollora\McpConnector
 */

declare(strict_types=1);

namespace Pollora\McpConnector;

defined(__NAMESPACE__ . '\VERSION') || define(__NAMESPACE__ . '\VERSION', '1.2.0');
defined(__NAMESPACE__ . '\PLUGIN_FILE') || define(__NAMESPACE__ . '\PLUGIN_FILE', dirname(__DIR__, 2) . '/mcp-connector.php');
defined(__NAMESPACE__ . '\PLUGIN_DIR') || define(__NAMESPACE__ . '\PLUGIN_DIR', dirname(__DIR__, 2));

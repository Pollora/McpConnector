<?php
/**
 * WordPress functions the admin screens share, doubled once for the whole
 * admin test group.
 *
 * @package Pollora\McpConnector
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Abilities\AbilityRegistry;
use Pollora\McpConnector\Admin\Diagnostics;
use Pollora\McpConnector\Settings;

/**
 * Double the functions the admin screens call and clear the static state they
 * memoise, so no test inherits another one.
 */
function adminsmallPrepare(): void
{
    Functions\when('add_query_arg')->alias(static function (mixed ...$arguments): string {
        // Both signatures WordPress accepts: (array, url) and (key, value, url).
        [$args, $url] = is_array($arguments[0])
            ? [$arguments[0], (string) $arguments[1]]
            : [[(string) $arguments[0] => $arguments[1]], (string) $arguments[2]];

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    });
    Functions\when('_n')->alias(
        static fn (string $single, string $plural, int $number): string => $number === 1 ? $single : $plural,
    );
    Functions\when('untrailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\'));
    Functions\when('wp_nonce_field')->alias(static function (string $action = '-1'): void {
        echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">';
    });
    Functions\when('translate_user_role')->returnArg(1);
    Functions\when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof \WP_Error);
    Functions\when('post_type_exists')->justReturn(true);
    Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page']);

    Settings::forget();

    foreach ([[Diagnostics::class, 'memo'], [AbilityRegistry::class, 'registered']] as [$class, $property]) {
        $reflection = new \ReflectionProperty($class, $property);
        $reflection->setValue(null, []);
    }
}

/**
 * Capture what a callable prints.
 */
function adminsmallCapture(callable $render): string
{
    ob_start();

    try {
        $render();
    } finally {
        $output = (string) ob_get_clean();
    }

    return $output;
}

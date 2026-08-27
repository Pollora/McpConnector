<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base case for the unit suite: Brain Monkey up, plus the handful of WordPress
 * functions that nearly every code path touches.
 *
 * ⚠️ Brain Monkey leaves a mocked function **defined for the rest of the
 * process**. A `function_exists()` guard therefore stays true in every test that
 * runs after one mocks it, and the suite runs in random order by design. Any
 * function the plugin probes for rather than calls — the Abilities API,
 * `apache_request_headers()`, anything the MCP Adapter declares — must have its
 * absent state declared here, or test order becomes a source of failures.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Values returned by the mocked get_option(), keyed by option name.
     *
     * @var array<string, mixed>
     */
    protected array $optionValues = [];

    /**
     * Values returned by the mocked get_transient(), keyed by name.
     *
     * @var array<string, mixed>
     */
    protected array $transientValues = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->optionValues = [];
        $this->transientValues = [];

        $this->stubEscaping();
        $this->stubOptions();
        $this->stubTransients();
        $this->stubSiteInfo();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Set the value get_option() will return for a given name.
     */
    protected function setOption(string $name, mixed $value): void
    {
        $this->optionValues[$name] = $value;
    }

    /**
     * Store plugin settings as they would be found in the database.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function setSettings(array $settings): void
    {
        $this->setOption('mcp_connector_settings', $settings);
    }

    /**
     * Escaping and sanitising helpers behave as identity or as their real PHP
     * equivalent — enough for assertions about structure, and honest about what
     * actually gets escaped.
     */
    private function stubEscaping(): void
    {
        Functions\when('esc_html')->alias(static fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_attr')->alias(static fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_textarea')->alias(static fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_url')->alias(static fn ($t) => filter_var((string) $t, FILTER_SANITIZE_URL) ?: '');
        Functions\when('esc_url_raw')->alias(static fn ($t) => filter_var((string) $t, FILTER_SANITIZE_URL) ?: '');
        Functions\when('esc_html__')->alias(static fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_attr__')->alias(static fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html_e')->alias(static function ($t): void {
            echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        });
        Functions\when('__')->returnArg(1);
        Functions\when('_e')->alias(static function ($t): void {
            echo (string) $t;
        });
        Functions\when('sanitize_text_field')->alias(static fn ($t) => trim(strip_tags((string) $t)));
        Functions\when('sanitize_textarea_field')->alias(static fn ($t) => trim(strip_tags((string) $t)));
        Functions\when('sanitize_key')->alias(
            static fn ($k) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)) ?? '',
        );
        Functions\when('sanitize_title')->alias(
            static fn ($t) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $t)) ?? '', '-'),
        );
        Functions\when('wp_unslash')->alias(static fn ($v) => is_string($v) ? stripslashes($v) : $v);
        Functions\when('wp_strip_all_tags')->alias(static fn ($t) => trim(strip_tags((string) $t)));
        Functions\when('wp_json_encode')->alias(static fn ($data, $flags = 0) => json_encode($data, (int) $flags));
        Functions\when('wp_parse_url')->alias(
            static fn (string $url, int $component = -1) => parse_url($url, $component),
        );
        // Deterministic, so a test can assert on what was generated. Anything
        // asserting on *randomness* has to say so and mock this itself.
        Functions\when('wp_generate_password')->alias(
            static fn (int $length = 12): string => substr(str_repeat('a1b2c3d4', 16), 0, $length),
        );
        Functions\when('wp_rand')->alias(static fn (int $min = 0, int $max = 0): int => $min);
        Functions\when('checked')->alias(
            static fn ($a, $b = true, $echo = true): string => $a === $b ? " checked='checked'" : '',
        );
        Functions\when('wp_date')->alias(
            static fn (string $format, ?int $timestamp = null): string => gmdate($format, $timestamp ?? 0),
        );
        Functions\when('plugins_url')->alias(
            static fn (string $path = '', string $plugin = ''): string => 'https://example.test/plugin/' . ltrim($path, '/'),
        );
        Functions\when('plugin_basename')->alias(
            static fn (string $file): string => 'mcp-connector/' . basename($file),
        );
        Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://example.test/wp-admin/' . ltrim($path, '/'),
        );
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://example.test/wp-json/' . ltrim($path, '/'),
        );
    }

    private function stubOptions(): void
    {
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->optionValues[$name] ?? $default,
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->optionValues[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->optionValues[$name]);

            return true;
        });
    }

    private function stubTransients(): void
    {
        Functions\when('get_transient')->alias(
            fn (string $name): mixed => $this->transientValues[$name] ?? false,
        );
        Functions\when('set_transient')->alias(function (string $name, mixed $value): bool {
            $this->transientValues[$name] = $value;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $name): bool {
            unset($this->transientValues[$name]);

            return true;
        });
    }

    private function stubSiteInfo(): void
    {
        Functions\when('home_url')->alias(
            static fn (string $path = '') => 'https://example.test/' . ltrim($path, '/'),
        );
        Functions\when('site_url')->alias(
            static fn (string $path = '') => 'https://example.test/' . ltrim($path, '/'),
        );
        Functions\when('get_bloginfo')->alias(static fn (string $show) => match ($show) {
            'name' => 'Example Site',
            'description' => 'Just another example',
            'language' => 'en-US',
            default => '',
        });
    }
}

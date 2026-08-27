<?php

declare(strict_types=1);

/**
 * Structural invariants: the things that hold across the whole codebase and are
 * cheap to break one file at a time.
 *
 * Two of these exist because a ruleset exemption rests on them. `phpcs.xml.dist`
 * silences `PrefixAllGlobals.DynamicHooknameFound` on the grounds that every
 * hook name is a class constant already carrying the prefix — a claim the sniff
 * cannot check because it cannot resolve a constant. This file checks it, at a
 * place that can see the values.
 */
$root = dirname(__DIR__, 2);

/**
 * Every class this plugin declares under src/, as a fully-qualified name.
 *
 * @return list<class-string>
 */
function mcpcDeclaredClasses(string $root): array
{
    $classes = [];

    foreach (mcpcSourceFiles($root . '/src') as $file) {
        $relative = substr($file, strlen($root . '/src/'), -4);

        if ($relative === 'index') {
            continue;
        }

        $classes[] = 'Pollora\\McpConnector\\' . str_replace('/', '\\', $relative);
    }

    return $classes;
}

describe('every source file', function () use ($root): void {
    it('declares strict types', function () use ($root): void {
        $offenders = [];

        foreach (mcpcSourceFiles($root . '/src') as $file) {
            if (!str_contains((string) file_get_contents($file), 'declare(strict_types=1);')) {
                $offenders[] = basename($file);
            }
        }

        expect($offenders)->toBe([]);
    });

    it('refuses to be reached over HTTP', function () use ($root): void {
        // Every file in a plugin directory is web-reachable on most hosts. These
        // only declare classes, so a direct hit is inert — but the guard costs a
        // line and removes the argument.
        $offenders = [];

        foreach ([...mcpcSourceFiles($root . '/src'), $root . '/mcp-connector.php'] as $file) {
            if (basename($file) === 'index.php') {
                continue;
            }

            if (!str_contains((string) file_get_contents($file), "defined('ABSPATH') || exit;")) {
                $offenders[] = basename($file);
            }
        }

        expect($offenders)->toBe([]);
    });

    it('lives in the plugin namespace, never the global one', function () use ($root): void {
        $offenders = [];

        foreach (mcpcSourceFiles($root . '/src') as $file) {
            if (basename($file) === 'index.php') {
                continue;
            }

            if (!str_contains((string) file_get_contents($file), 'namespace Pollora\\McpConnector')) {
                $offenders[] = basename($file);
            }
        }

        expect($offenders)->toBe([]);
    });

    it('declares a class whose name matches its path, so PSR-4 resolves it', function () use ($root): void {
        $missing = [];

        foreach (mcpcDeclaredClasses($root) as $class) {
            if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
                $missing[] = $class;
            }
        }

        expect($missing)->toBe([]);
    });
});

describe('hook names', function () use ($root): void {
    it('are all prefixed, which is what phpcs.xml.dist takes on trust', function () use ($root): void {
        $unprefixed = [];

        foreach (mcpcDeclaredClasses($root) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
                if (!str_contains($name, 'FILTER') || !is_string($value)) {
                    continue;
                }

                if (!str_starts_with($value, 'mcp_connector_')) {
                    $unprefixed[] = $class . '::' . $name . ' = ' . $value;
                }
            }
        }

        expect($unprefixed)->toBe([]);
    });

    it('are actually declared somewhere — the check above must not pass vacuously', function () use ($root): void {
        $found = 0;

        foreach (mcpcDeclaredClasses($root) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            foreach (array_keys((new ReflectionClass($class))->getConstants()) as $name) {
                if (str_contains($name, 'FILTER')) {
                    $found++;
                }
            }
        }

        // Eight at the time of writing. The assertion is that there are some,
        // not how many: adding a filter should not fail this test.
        expect($found)->toBeGreaterThanOrEqual(8);
    });

    it('are fired through their constant, never re-spelled as a literal', function () use ($root): void {
        // A literal at the call site is how a hook name and its documentation
        // drift apart, and it is also what would make the ruleset exemption
        // above stop covering the real name.
        $literals = [];

        foreach (mcpcSourceFiles($root . '/src') as $file) {
            preg_match_all(
                "/apply_filters\(\s*'(mcp_connector_[a-z_]+)'/",
                (string) file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] as $name) {
                $literals[] = basename($file) . ': ' . $name;
            }
        }

        expect($literals)->toBe([]);
    });
});

describe('option and transient keys', function () use ($root): void {
    it('are all prefixed, so nothing this plugin writes can collide', function () use ($root): void {
        $unprefixed = [];

        foreach (mcpcDeclaredClasses($root) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
                $isStorage = str_contains($name, 'OPTION')
                    || str_contains($name, 'TRANSIENT')
                    || str_contains($name, 'CRON')
                    || str_contains($name, 'NONCE');

                if (!$isStorage || !is_string($value)) {
                    continue;
                }

                if (!str_starts_with($value, 'mcp_connector_')) {
                    $unprefixed[] = $class . '::' . $name . ' = ' . $value;
                }
            }
        }

        expect($unprefixed)->toBe([]);
    });
});

describe('the plugin is generic', function () use ($root): void {
    it('mentions no site it was written for', function () use ($root): void {
        // It was extracted from one site's codebase. Anything left behind from
        // that would be both a leak and a reason for a directory reviewer to
        // stop reading.
        $offenders = [];

        foreach ([...mcpcSourceFiles($root . '/src'), $root . '/mcp-connector.php'] as $file) {
            $source = mb_strtolower((string) file_get_contents($file));

            foreach (['angres', 'batirdemain', 'amphibee.fr/wp'] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = basename($file) . ': ' . $needle;
                }
            }
        }

        expect($offenders)->toBe([]);
    });

    it('hardcodes no hostname beyond the ones it has a reason to name', function () use ($root): void {
        // Documentation links, the vendor's own site, the loopback address the
        // connection test dials, and the client vendors whose setup pages the
        // walkthroughs point at. Naming a client to describe compatibility is
        // allowed; what is not allowed is a hostname belonging to *a site*, and
        // that is the thing this catches.
        $allowed = [
            'modelcontextprotocol.io',
            'datatracker.ietf.org',
            'developer.wordpress.org',
            'wordpress.org',
            'github.com',
            'amphibee.fr',
            'schema.org',
            'claude.ai',
            'claude.com',
            'chatgpt.com',
            'openai.com',
            'cursor.com',
            'cursor.sh',
            '127.0.0.1',
            'localhost',
            'example.com',
            'example.test',
            'example.org',
        ];

        $offenders = [];

        foreach (mcpcSourceFiles($root . '/src') as $file) {
            preg_match_all('#https?://([a-z0-9.-]+)#i', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $host) {
                $host = mb_strtolower(preg_replace('/^www\./', '', $host) ?? '');

                if (!in_array($host, $allowed, true)) {
                    $offenders[] = basename($file) . ': ' . $host;
                }
            }
        }

        expect($offenders)->toBe([]);
    });
});

describe('the never-publish list', function () use ($root): void {
    it('still excludes the provider whose abilities would make every other choice decorative', function (): void {
        // The list is by *provider*, not by ability: mcp-adapter/execute-ability
        // invokes any registered ability by name, and publishing it would hand a
        // client everything the curation withholds. Excluding the namespace
        // rather than that one name means a future adapter release cannot
        // reintroduce the hole under a different ability name.
        $constant = (new ReflectionClass(\Pollora\McpConnector\Server\ExternalAbilities::class))
            ->getConstant('NEVER_PUBLISH');

        expect($constant)->toBeArray()->toContain('mcp-adapter');
    });

    it('is a constant, so no filter can widen it', function (): void {
        $reflection = (new ReflectionClass(\Pollora\McpConnector\Server\ExternalAbilities::class))
            ->getReflectionConstant('NEVER_PUBLISH');

        expect($reflection)->not->toBeFalse()
            ->and($reflection->isPrivate())->toBeTrue();

        // Two filters do shape the published set — SELECTION_FILTER and
        // PROFILES_FILTER — and neither may be able to reach past this list.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Server/ExternalAbilities.php');

        expect($source)->toContain('in_array($provider, self::NEVER_PUBLISH, true)');
    });
});

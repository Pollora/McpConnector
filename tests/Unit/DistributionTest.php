<?php

declare(strict_types=1);

use Pollora\McpConnector\Admin\SettingsPage;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\ExpiredGrantCollector;
use Pollora\McpConnector\OAuth\TokenRepository;
use Pollora\McpConnector\Settings;

/**
 * Guards the metadata a distributed plugin is judged on.
 *
 * Version strings, the text domain and the PHP requirement live in five
 * different files. Nothing in PHP enforces that they agree, and a mismatch is
 * invisible until an update ships to real sites — or, in the case of
 * `Stable tag`, until the directory quietly keeps serving the previous release.
 */
$root = dirname(__DIR__, 2);

/**
 * @return array<string, string>
 */
function mcpcPluginHeader(string $file): array
{
    $source = (string) file_get_contents($file);
    $header = substr($source, 0, 8192);

    preg_match_all('/^\s*\*\s*([A-Za-z][A-Za-z ]*?):\s*(.+?)\s*$/m', $header, $matches, PREG_SET_ORDER);

    $fields = [];

    foreach ($matches as $match) {
        $fields[$match[1]] = $match[2];
    }

    return $fields;
}

beforeEach(function () use ($root): void {
    $this->root = $root;
    $this->header = mcpcPluginHeader($root . '/mcp-connector.php');
    $this->composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
});

describe('plugin header', function (): void {
    it('declares every field WordPress needs to list the plugin', function (string $field): void {
        expect($this->header)->toHaveKey($field)
            ->and($this->header[$field])->not->toBe('');
    })->with([
        'Plugin Name',
        'Description',
        'Version',
        'Author',
        'License',
        'Text Domain',
        'Requires PHP',
        'Requires at least',
        'Domain Path',
    ]);

    it('uses a semantic version', function (): void {
        expect($this->header['Version'])->toMatch('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/');
    });

    it('uses the text domain the code actually calls', function (): void {
        expect($this->header['Text Domain'])->toBe('amphibee-mcp-connector');
    });

    it('requires the PHP version composer.json requires', function (): void {
        expect('>=' . $this->header['Requires PHP'])->toBe($this->composer['require']['php']);
    });

    it('names a GPL-compatible licence matching composer.json', function (): void {
        expect($this->header['License'])->toBe($this->composer['license']);
    });

    it('does not declare Requires Plugins, which would be a dead end from the directory', function (): void {
        // The adapter is on GitHub, not wordpress.org. With the header, anyone
        // installing from the directory meets a refusal to activate followed by
        // an "Install now" link that resolves to nothing. See the file's own
        // header comment for what is given up in exchange.
        expect($this->header)->not->toHaveKey('Requires Plugins');
    });
});

describe('version consistency', function (): void {
    it('declares the same version in the header and the VERSION constant', function (): void {
        $source = (string) file_get_contents($this->root . '/mcp-connector.php');

        preg_match("/const VERSION = '([^']+)'/", $source, $matches);

        expect($matches[1] ?? null)->toBe($this->header['Version']);
    });

    it('declares the same version in the changelog', function (): void {
        expect((string) file_get_contents($this->root . '/CHANGELOG.md'))
            ->toContain('## ' . $this->header['Version']);
    });

    it('declares the same version as readme.txt Stable tag', function (): void {
        // The directory serves whatever Stable tag names. Left behind, it keeps
        // serving the previous release however new the tree in trunk is.
        expect((string) file_get_contents($this->root . '/readme.txt'))
            ->toContain('Stable tag: ' . $this->header['Version']);
    });

    it('requires the same WordPress version as readme.txt', function (): void {
        expect((string) file_get_contents($this->root . '/readme.txt'))
            ->toContain('Requires at least: ' . $this->header['Requires at least']);
    });

    it('requires the same PHP version as readme.txt', function (): void {
        expect((string) file_get_contents($this->root . '/readme.txt'))
            ->toContain('Requires PHP: ' . $this->header['Requires PHP']);
    });

    it('keeps the test fixture in step with the real constants', function (): void {
        expect(\Pollora\McpConnector\VERSION)->toBe($this->header['Version']);
    });
});

describe('uninstall', function () use ($root): void {
    // uninstall.php is loaded by WordPress *instead of* the plugin, so nothing
    // from the namespace is available to it and every name in it is a literal.
    // Its own header promises this test exists; these are the assertions that
    // keep the promise, comparing each literal against the constant it copies.
    it('deletes exactly the options the plugin creates', function (string $option) use ($root): void {
        expect((string) file_get_contents($root . '/uninstall.php'))
            ->toContain("delete_option('{$option}')");
    })->with([
        Settings::OPTION,
        ClientRepository::OPTION,
        TokenRepository::ACCESS_OPTION,
        TokenRepository::REFRESH_OPTION,
    ]);

    it('clears the scheduled sweep as well as deactivation does', function () use ($root): void {
        expect((string) file_get_contents($root . '/uninstall.php'))
            ->toContain("wp_clear_scheduled_hook('" . ExpiredGrantCollector::CRON_HOOK . "')");
    });

    it('deletes the one-shot secret transients, whose keys only the client list holds', function () use ($root): void {
        // Read through reflection rather than widening the constant: the prefix
        // has no business being public just so a test can see it.
        $prefix = (new ReflectionClass(SettingsPage::class))->getConstant('SECRET_TRANSIENT');

        expect($prefix)->toBeString()->not->toBe('')
            ->and((string) file_get_contents($root . '/uninstall.php'))
            ->toContain("delete_transient('" . $prefix . "' . \$client_id)");
    });

    it('deletes the discovery probe transient under the key Environment writes', function () use ($root): void {
        // This one is a local variable rather than a constant, so the only thing
        // that can be asserted is that both files spell it the same way.
        expect((string) file_get_contents($root . '/src/Support/Environment.php'))
            ->toContain("'mcp_connector_discovery_probe'")
            ->and((string) file_get_contents($root . '/uninstall.php'))
            ->toContain("delete_transient('mcp_connector_discovery_probe')");
    });

    it('reads the client list before deleting the option that holds it', function () use ($root): void {
        $source = (string) file_get_contents($root . '/uninstall.php');

        $readsClients = strpos($source, 'mcp_connector_uninstall_delete_secret_transients();');
        $deletesOption = strpos($source, "delete_option('" . ClientRepository::OPTION . "')");

        expect($readsClients)->not->toBeFalse()
            ->and($deletesOption)->not->toBeFalse()
            ->and($readsClients)->toBeLessThan($deletesOption);
    });

    it('handles multisite, where every option above is per-site', function () use ($root): void {
        expect((string) file_get_contents($root . '/uninstall.php'))
            ->toContain('is_multisite()')
            ->toContain('switch_to_blog')
            ->toContain('restore_current_blog');
    });

    it('refuses to run outside an uninstall', function () use ($root): void {
        expect((string) file_get_contents($root . '/uninstall.php'))
            ->toContain("defined('WP_UNINSTALL_PLUGIN') || exit;");
    });
});

describe('composer metadata', function (): void {
    it('is a wordpress-plugin package so composer/installers places it correctly', function (): void {
        expect($this->composer['type'])->toBe('wordpress-plugin');
    });

    it('autoloads the namespace the code declares', function (): void {
        expect($this->composer['autoload']['psr-4'])->toHaveKey('Pollora\\McpConnector\\');
    });

    it('pins the platform PHP to the minimum supported version', function (): void {
        expect($this->composer['config']['platform']['php'])->toStartWith('8.3');
    });

    it('keeps every quality tool in require-dev, never in require', function (): void {
        // The shipped vendor/ is the autoloader plus pollora/abilities, which
        // owns the Abilities API primitives this plugin used to carry itself.
        // Anything else appearing here means the zip grew a package tree, and
        // wordpress.org reviewers read that diff.
        expect(array_keys($this->composer['require']))->toBe(['php', 'pollora/abilities']);
    });

    it('resolves its one runtime dependency from a declared repository', function (): void {
        // pollora/abilities is not on Packagist yet, so the release build cannot
        // find it without this. Drop the repositories block once it is.
        $urls = array_column($this->composer['repositories'] ?? [], 'url');

        expect($urls)->toContain('https://github.com/Pollora/abilities');
    })->skip(
        fn (): bool => ! isset($this->composer['repositories']),
        'pollora/abilities resolves from Packagist; the repository entry is gone.',
    );

    it('does not require composer/installers, which is the consuming project to decide', function (): void {
        expect($this->composer['require'])->not->toHaveKey('composer/installers');
    });
});

describe('shipped files', function () use ($root): void {
    it('marks development files as export-ignore so they stay out of the zip', function (string $path) use ($root): void {
        expect((string) file_get_contents($root . '/.gitattributes'))->toContain($path);
    })->with([
        'tests',
        'phpunit.xml.dist',
        'phpstan.neon.dist',
        'phpcs.xml.dist',
        'pint.json',
        '.github',
        '.wordpress-org',
        'composer.lock',
    ]);

    it('does not export-ignore anything the plugin needs at runtime', function (string $path) use ($root): void {
        // The stylesheet and the catalogues have both been left out of a deploy
        // before: without assets/ the settings screen renders unstyled, without
        // languages/ it renders in English.
        expect((string) file_get_contents($root . '/.gitattributes'))
            ->not->toMatch('/^\/' . preg_quote($path, '/') . '\s+export-ignore/m');
    })->with(['assets', 'languages', 'src', 'readme.txt', 'uninstall.php', 'LICENSE', 'CHANGELOG.md']);

    it('ships an index.php guard in every asset directory', function (string $directory) use ($root): void {
        expect($root . '/' . $directory . '/index.php')->toBeReadableFile();
    })->with(['src', 'assets', 'languages']);

    it('carries a licence file', function () use ($root): void {
        expect($root . '/LICENSE')->toBeReadableFile();
    });

    it('ships a translation template at the advertised Domain Path', function () use ($root): void {
        expect($root . '/languages/amphibee-mcp-connector.pot')->toBeReadableFile();
    });

    it('never leaves a debugging call in shipped code', function () use ($root): void {
        $offenders = [];

        foreach (mcpcSourceFiles($root . '/src') as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/\b(var_dump|print_r|error_log|die\s*\()\s*\(/', $source) === 1) {
                $offenders[] = basename($file);
            }
        }

        expect($offenders)->toBe([]);
    });
});

describe('internationalisation', function () use ($root): void {
    it('uses only its own text domain', function () use ($root): void {
        $wrong = [];

        foreach (mcpcSourceFiles($root . '/src') as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all(
                "/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|_x|_n)\([^)]*?,\s*'([a-z0-9-]+)'\s*[,)]/",
                $source,
                $matches,
            );

            foreach ($matches[1] as $domain) {
                if ($domain !== 'amphibee-mcp-connector') {
                    $wrong[] = basename($file) . ': ' . $domain;
                }
            }
        }

        expect($wrong)->toBe([]);
    });

    it('loads the text domain the header declares, on init and not before', function () use ($root): void {
        $source = (string) file_get_contents($root . '/mcp-connector.php');

        // Not earlier than `init`: since WordPress 6.7 a domain loaded before
        // then raises _load_textdomain_just_in_time on every request.
        expect($source)
            ->toMatch("/load_plugin_textdomain\(\s*'amphibee-mcp-connector'/")
            ->toMatch("/add_action\('init',[^;]*load_plugin_textdomain/s");
    });
});

describe('translations', function () use ($root): void {
    it('ships a compiled catalogue for every shipped .po', function () use ($root): void {
        $missing = [];

        foreach (glob($root . '/languages/amphibee-mcp-connector-*.po') ?: [] as $po) {
            if (!is_readable(substr($po, 0, -3) . '.mo')) {
                $missing[] = basename($po);
            }
        }

        expect($missing)->toBe([]);
    });

    it('translates every string the .pot declares, for every shipped language', function () use ($root): void {
        $languages = $root . '/languages';
        $potIds = mcpcExtractMsgids((string) file_get_contents($languages . '/amphibee-mcp-connector.pot'));

        // The template's own entries carry no msgstr; that is what it is for.
        expect($potIds)->not->toBe([]);

        foreach (glob($languages . '/amphibee-mcp-connector-*.po') ?: [] as $po) {
            $locale = basename($po, '.po');
            $source = (string) file_get_contents($po);

            expect(mcpcExtractMsgids($source))
                ->toBe($potIds, "{$locale}: does not declare the same strings as the .pot");

            $blank = mcpcBlankMsgstrs($source);
            expect($blank)->toBe([], "{$locale}: missing a translation for: " . implode(', ', $blank));
        }
    });

    it('declares a plural form for every language it ships', function () use ($root): void {
        foreach (glob($root . '/languages/amphibee-mcp-connector-*.po') ?: [] as $po) {
            expect((string) file_get_contents($po))->toContain('Plural-Forms:');
        }
    });

    it('never renders "ability" and "capability" with the same word', function () use ($root): void {
        // They are separate concepts on separate panels — Tools decides which
        // abilities exist, Security decides which capability reaches them — so a
        // language that collapses them loses the distinction the screen is built
        // on. English hides the risk because it already has two words; every
        // other language has to choose, and French for one is one letter away
        // from choosing "capacité" twice.
        //
        // Two short strings carry the concepts unambiguously. Both must be
        // present in every catalogue: a probe that silently finds nothing is
        // exactly the quiet pass this test exists to prevent.
        $collisions = [];
        $checked = 0;

        foreach (glob($root . '/languages/amphibee-mcp-connector-*.po') ?: [] as $po) {
            $locale = basename($po, '.po');
            $source = (string) file_get_contents($po);

            $ability = mcpcPluralTranslationOf($source, '%d ability offered');
            $capability = mcpcTranslationOf($source, 'Required capability');

            expect($ability)->not->toBeNull("{$locale}: no translation found for the ability probe")
                ->and($capability)->not->toBeNull("{$locale}: no translation found for the capability probe");

            $checked++;
            $shared = array_intersect(mcpcContentWords($ability), mcpcContentWords($capability));

            if ($shared !== []) {
                $collisions[] = $locale . ': both use ' . implode(', ', $shared);
            }
        }

        expect($checked)->toBeGreaterThan(0)
            ->and($collisions)->toBe([]);
    });
});

/**
 * @return list<string>
 */
function mcpcExtractMsgids(string $source): array
{
    preg_match_all('/^msgid "(.*)"$/m', $source, $matches);

    // The header carries an empty msgid; every real entry has content.
    return array_values(array_filter($matches[1], static fn (string $id): bool => $id !== ''));
}

/**
 * msgid values whose msgstr (or every msgstr[n] for a plural entry) is empty.
 *
 * @return list<string>
 */
function mcpcBlankMsgstrs(string $source): array
{
    $entries = preg_split('/\n\n+/', trim($source)) ?: [];
    $blank = [];

    foreach ($entries as $entry) {
        if (!preg_match('/^msgid "(.*)"$/m', $entry, $id) || $id[1] === '') {
            continue;
        }

        if (preg_match('/^msgstr\[0\] "/m', $entry)) {
            // Plural entry: every numbered form must be filled.
            preg_match_all('/^msgstr\[\d+\] "(.*)"$/m', $entry, $forms);

            if (in_array('', $forms[1], true)) {
                $blank[] = $id[1];
            }

            continue;
        }

        if (preg_match('/^msgstr "(.*)"$/m', $entry, $str) && $str[1] === '') {
            $blank[] = $id[1];
        }
    }

    return $blank;
}

/**
 * The translation of one exact msgid, or null when the catalogue has no entry.
 */
function mcpcTranslationOf(string $source, string $msgid): ?string
{
    $pattern = '/^msgid "' . preg_quote($msgid, '/') . '"\nmsgstr "(.*)"$/m';

    if (preg_match($pattern, $source, $matches) !== 1) {
        return null;
    }

    return $matches[1] !== '' ? $matches[1] : null;
}

/**
 * The singular form of a plural entry, or null when the catalogue has no entry.
 */
function mcpcPluralTranslationOf(string $source, string $msgid): ?string
{
    $pattern = '/^msgid "' . preg_quote($msgid, '/') . '"\nmsgid_plural "[^"]*"\nmsgstr\[0\] "(.*)"$/m';

    if (preg_match($pattern, $source, $matches) !== 1) {
        return null;
    }

    return $matches[1] !== '' ? $matches[1] : null;
}

/**
 * Lowercased words of a translated string, minus placeholders and short
 * function words — what is left is the part that carries the meaning.
 *
 * @return list<string>
 */
function mcpcContentWords(string $translation): array
{
    $stripped = preg_replace('/%\d*\$?[dsu]/', ' ', $translation) ?? $translation;

    preg_match_all('/\p{L}+/u', mb_strtolower($stripped), $matches);

    return array_values(array_filter(
        $matches[0],
        static fn (string $word): bool => mb_strlen($word) > 3,
    ));
}

<?php

declare(strict_types=1);

use Pollora\McpConnector\Tests\TestCase;

uses(TestCase::class)->in('Unit');

/**
 * Every PHP file under a directory, recursively.
 *
 * Several of the structural tests walk the source tree rather than loading it —
 * the point is to assert on files that were never meant to be executed outside
 * WordPress.
 *
 * @return list<string> Absolute paths.
 */
function mcpcSourceFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Compare rendered output with the reference stored under tests/Fixtures/golden.
 *
 * Markup is a pile of string concatenations: only the whole output pins every
 * fragment, its order and its escaping. Regenerate a reference on purpose with
 * `UPDATE_GOLDEN=1` and read the diff before committing it.
 */
function mcpcAssertGolden(string $name, string $actual): void
{
    $path = __DIR__ . '/Fixtures/golden/' . $name . '.html';

    if (getenv('UPDATE_GOLDEN') === '1') {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $actual);
    }

    expect(is_file($path))->toBeTrue('Missing reference ' . $name);
    expect($actual)->toBe((string) file_get_contents($path));
}

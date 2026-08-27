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

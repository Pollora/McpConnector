<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Settings;

beforeEach(function (): void {
    Settings::forget();
});

afterEach(function (): void {
    Settings::forget();
});

describe('reading the stored configuration', function (): void {
    it('reads the option once per request and keeps the answer', function (): void {
        $reads = 0;
        Functions\when('get_option')->alias(static function () use (&$reads): array {
            $reads++;

            return ['server_route' => 'route-one'];
        });

        $first = Settings::current();
        $second = Settings::current();

        expect($reads)->toBe(1)
            ->and($second)->toBe($first)
            ->and($first->serverRoute)->toBe('route-one');
    });

    it('reads it again after forgetting', function (): void {
        $reads = 0;
        Functions\when('get_option')->alias(static function () use (&$reads): array {
            $reads++;

            return [];
        });

        Settings::current();
        Settings::forget();
        Settings::current();

        expect($reads)->toBe(2);
    });

    it('falls back to the defaults for an option that is not an array', function (mixed $stored): void {
        Functions\when('get_option')->justReturn($stored);

        expect(Settings::current()->toArray())->toBe(Settings::fromArray([])->toArray());
    })->with(['an empty string' => [''], 'false' => [false], 'null' => [null]]);
});

describe('saving the configuration', function (): void {
    it('stores the normalised array without autoload, under the option name', function (): void {
        $calls = [];
        Functions\when('update_option')->alias(static function (string $name, mixed $value, mixed $autoload) use (&$calls): bool {
            $calls[] = [$name, $value, $autoload];

            return true;
        });

        Settings::save(['server_route' => 'Route One!']);

        expect($calls)->toHaveCount(1)
            ->and($calls[0][0])->toBe(Settings::OPTION)
            ->and($calls[0][1])->toBe(Settings::fromArray(['server_route' => 'Route One!'])->toArray())
            ->and($calls[0][2])->toBeFalse();
    });

    it('is what the next read sees, without a stale copy', function (): void {
        Functions\when('get_option')->alias(fn (string $name, mixed $default = false): mixed => $this->optionValues[$name] ?? $default);
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->optionValues[$name] = $value;

            return true;
        });

        $before = Settings::current();
        Settings::save(['server_route' => 'changed']);

        expect($before->serverRoute)->not->toBe('changed')
            ->and(Settings::current()->serverRoute)->toBe('changed');
    });
});

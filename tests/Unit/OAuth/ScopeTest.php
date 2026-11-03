<?php

declare(strict_types=1);

use Pollora\McpConnector\OAuth\Scope;

afterEach(function (): void {
    Scope::setCurrent(null);
});

describe('parsing a scope string', function (): void {
    it('defaults to read when nothing usable is requested', function (string $raw): void {
        expect(Scope::parse($raw))->toBe(['read']);
    })->with([
        'empty' => [''],
        'blanks' => ["  \t "],
        'only unknown scopes' => ['admin superuser'],
    ]);

    it('drops unknown scopes and keeps the known ones', function (): void {
        expect(Scope::parse('write admin delete_users'))->toBe(['write', 'read']);
    });

    it('implies read when write is requested alone', function (): void {
        expect(Scope::parse('write'))->toContain('read')->toContain('write');
    });

    it('does not duplicate a scope requested twice', function (): void {
        expect(Scope::parse('read read write write'))->toBe(['read', 'write']);
    });

    it('splits on any whitespace', function (): void {
        expect(Scope::parse("read\n\twrite"))->toBe(['read', 'write']);
    });

    it('is case sensitive, so WRITE grants nothing beyond read', function (): void {
        expect(Scope::parse('WRITE'))->toBe(['read']);
    });

    it('round-trips through toString', function (): void {
        expect(Scope::toString(['read', 'write']))->toBe('read write');
    });
});

describe('the scope of the current request', function (): void {
    it('allows writes when no token authenticated the request', function (): void {
        Scope::setCurrent(null);

        expect(Scope::current())->toBeNull()
            ->and(Scope::currentAllowsWrite())->toBeTrue();
    });

    it('refuses writes for a read-only token', function (): void {
        Scope::setCurrent(['read']);

        expect(Scope::currentAllowsWrite())->toBeFalse();
    });

    it('refuses writes for an empty scope list', function (): void {
        Scope::setCurrent([]);

        expect(Scope::currentAllowsWrite())->toBeFalse();
    });

    it('allows writes for a token carrying write', function (): void {
        Scope::setCurrent(['read', 'write']);

        expect(Scope::currentAllowsWrite())->toBeTrue();
    });
});

it('describes every supported scope and echoes an unknown one', function (): void {
    foreach (Scope::supported() as $scope) {
        expect(Scope::describe($scope))->not->toBe($scope);
    }

    expect(Scope::describe('mystery'))->toBe('mystery');
});

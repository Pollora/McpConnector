<?php

declare(strict_types=1);

use Pollora\McpConnector\OAuth\Scope;
use Pollora\McpConnector\Server\ExternalScopeGate;
use Pollora\McpConnector\Settings;

beforeEach(function (): void {
    Scope::setCurrent(null);
    $this->gate = new ExternalScopeGate(Settings::fromArray([]));
});

afterEach(function (): void {
    Scope::setCurrent(null);
});

function mcpcGatedPermission(ExternalScopeGate $gate, string $name, array $annotations): callable
{
    $args = [
        'permission_callback' => static fn (): bool => true,
        'meta' => $annotations === [] ? [] : ['annotations' => $annotations],
    ];

    return $gate->gate($args, $name)['permission_callback'];
}

describe('the scope gate on another plugin\'s abilities', function (): void {
    it('refuses a read-only token an ability that writes, or says nothing', function (array $annotations): void {
        Scope::setCurrent(['read']);

        $result = (mcpcGatedPermission($this->gate, 'vendor/update-thing', $annotations))();

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_data())->toBe(['status' => 403]);
    })->with([
        'silent' => [[]],
        'writes' => [['readonly' => false]],
        'destructive' => [['readonly' => false, 'destructive' => true]],
    ]);

    it('lets a token that was granted write do it, and a request without a token', function (): void {
        $permission = mcpcGatedPermission($this->gate, 'vendor/update-thing', []);

        expect($permission())->toBeTrue();

        Scope::setCurrent(['read', 'write']);

        expect($permission())->toBeTrue();
    });

    it('leaves an ability that claims to read only alone', function (): void {
        Scope::setCurrent(['read']);

        expect((mcpcGatedPermission($this->gate, 'vendor/get-thing', ['readonly' => true]))())->toBeTrue();
    });

    it('passes the arguments on to the original permission callback', function (): void {
        Scope::setCurrent(['read', 'write']);

        $args = ['permission_callback' => static fn (mixed $input = null): mixed => $input];
        $permission = $this->gate->gate($args, 'vendor/update-thing')['permission_callback'];

        expect($permission(['id' => 3]))->toBe(['id' => 3]);
    });

    it('leaves its own abilities, which carry their own gate, and malformed arguments alone', function (): void {
        $args = ['permission_callback' => static fn (): bool => true];

        expect($this->gate->gate($args, 'wp-mcp/update-post'))->toBe($args)
            ->and($this->gate->gate(['label' => 'x'], 'vendor/thing'))->toBe(['label' => 'x'])
            ->and($this->gate->gate('nope', 'vendor/thing'))->toBe('nope');
    });

    it('hooks into the registration of every ability', function (): void {
        $registered = [];
        Brain\Monkey\Functions\when('add_filter')->alias(function (string $hook, mixed $callback, int $priority = 10, int $args = 1) use (&$registered): bool {
            $registered[] = [$hook, $priority, $args];

            return true;
        });

        $this->gate->register();

        expect($registered)->toBe([['wp_register_ability_args', 10, 2]]);
    });
});

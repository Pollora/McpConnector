<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\AuthorizationCodeRepository;

describe('authorization codes', function (): void {
    it('are stored under the hash of the code, for five minutes', function (): void {
        $calls = [];
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl) use (&$calls): bool {
            $calls[] = [$key, $value, $ttl];

            return true;
        });

        $code = (new AuthorizationCodeRepository())->issue(7, 'client-1', 'https://app.example/cb', 'challenge', ['read']);

        expect($calls)->toHaveCount(1)
            ->and($calls[0][0])->toBe('mcp_connector_oauth_code_' . hash('sha256', $code))
            ->and($calls[0][0])->not->toContain($code)
            ->and($calls[0][2])->toBe(300);
    });

    it('can be redeemed with everything they were bound to', function (): void {
        $repo = new AuthorizationCodeRepository();
        $code = $repo->issue(7, 'client-1', 'https://app.example/cb', 'challenge', ['read', 'write']);

        expect($repo->consume($code))->toBe([
            'user_id' => 7,
            'client_id' => 'client-1',
            'redirect_uri' => 'https://app.example/cb',
            'code_challenge' => 'challenge',
            'scopes' => ['read', 'write'],
        ]);
    });

    it('can be redeemed only once', function (): void {
        $repo = new AuthorizationCodeRepository();
        $code = $repo->issue(7, 'client-1', 'https://app.example/cb', 'challenge', ['read']);

        $repo->consume($code);

        expect($repo->consume($code))->toBeNull();
    });

    it('are unknown once the transient has expired', function (): void {
        $repo = new AuthorizationCodeRepository();
        $code = $repo->issue(7, 'client-1', 'https://app.example/cb', 'challenge', ['read']);

        $this->transientValues = [];

        expect($repo->consume($code))->toBeNull();
    });

    it('refuse empty and unknown codes', function (string $code): void {
        expect((new AuthorizationCodeRepository())->consume($code))->toBeNull();
    })->with(['empty' => [''], 'unknown' => ['nope']]);

    it('refuse a corrupt stored value', function (): void {
        $repo = new AuthorizationCodeRepository();
        $code = $repo->issue(7, 'client-1', 'https://app.example/cb', 'challenge', ['read']);
        $key = array_key_first($this->transientValues);
        $this->transientValues[$key] = 'garbage';

        expect($repo->consume($code))->toBeNull();
    });

    it('are generated 40 characters long and without special characters', function (): void {
        $asked = null;
        Functions\when('wp_generate_password')->alias(static function (int $length, bool $special) use (&$asked): string {
            $asked = [$length, $special];

            return 'generated';
        });

        $code = (new AuthorizationCodeRepository())->issue(1, 'c', 'https://a.test/cb', 'x', []);

        expect($asked)->toBe([40, false])->and($code)->toBe('generated');
    });

    it('store exactly what they were bound to', function (): void {
        (new AuthorizationCodeRepository())->issue(7, 'client-1', 'https://app.example/cb', 'challenge', ['read']);

        expect(array_values($this->transientValues))->toBe([[
            'user_id' => 7,
            'client_id' => 'client-1',
            'redirect_uri' => 'https://app.example/cb',
            'code_challenge' => 'challenge',
            'scopes' => ['read'],
        ]]);
    });

    it('do not even look up an empty code', function (): void {
        Functions\expect('get_transient')->never();
        Functions\expect('delete_transient')->never();

        expect((new AuthorizationCodeRepository())->consume(''))->toBeNull();
    });

    it('are spent even when the record is corrupt', function (): void {
        $repo = new AuthorizationCodeRepository();
        $code = $repo->issue(7, 'client-1', 'https://app.example/cb', 'challenge', ['read']);
        $key = array_key_first($this->transientValues);
        $this->transientValues[$key] = 'garbage';

        $repo->consume($code);

        expect($this->transientValues)->not->toHaveKey($key);
    });

    it('read a sparse or loosely typed record with typed defaults', function (): void {
        $repo = new AuthorizationCodeRepository();
        $code = $repo->issue(7, 'c', 'u', 'x', []);
        $key = array_key_first($this->transientValues);

        $this->transientValues[$key] = [];
        expect($repo->consume($code))->toBe(['user_id' => 0, 'client_id' => '', 'redirect_uri' => '', 'code_challenge' => '', 'scopes' => []]);

        $this->transientValues[$key] = ['user_id' => '12', 'client_id' => 3, 'redirect_uri' => 4, 'code_challenge' => 5, 'scopes' => [2 => 6, 5 => 'read']];
        expect($repo->consume($code))->toBe(['user_id' => 12, 'client_id' => '3', 'redirect_uri' => '4', 'code_challenge' => '5', 'scopes' => ['6', 'read']]);
    });
});

<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\ExpiredGrantCollector;
use Pollora\McpConnector\OAuth\TokenRepository;

beforeEach(function (): void {
    $counter = 0;
    Functions\when('wp_generate_password')->alias(
        static function (int $length = 12) use (&$counter): string {
            return substr(str_pad('t' . ++$counter, $length, 'x'), 0, $length);
        },
    );
});

describe('the grant collector', function (): void {
    it('registers its hooks, including both user-deletion hooks', function (): void {
        $hooks = [];
        Functions\when('add_action')->alias(function (string $hook) use (&$hooks): bool {
            $hooks[] = $hook;

            return true;
        });

        (new ExpiredGrantCollector())->register();

        expect($hooks)->toBe(['init', ExpiredGrantCollector::CRON_HOOK, 'delete_user', 'wpmu_delete_user']);
    });

    it('schedules a daily sweep only when none is pending', function (): void {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_event')->once()->with(\Mockery::type('int'), 'daily', ExpiredGrantCollector::CRON_HOOK);

        (new ExpiredGrantCollector())->scheduleSweep();
    });

    it('does not schedule a second sweep', function (): void {
        Functions\when('wp_next_scheduled')->justReturn(1234567890);
        Functions\expect('wp_schedule_event')->never();

        (new ExpiredGrantCollector())->scheduleSweep();
    });

    it('revokes a deleted user\'s tokens at once, while they are still within their validity', function (): void {
        $tokens = new TokenRepository();
        $gone = $tokens->issue(7, 'client-1', ['read']);
        $kept = $tokens->issue(8, 'client-1', ['read']);

        (new ExpiredGrantCollector())->revokeForUser(7);

        expect($tokens->findAccessToken($gone['access_token']))->toBeNull()
            ->and($tokens->findAccessToken($kept['access_token']))->not->toBeNull();
    });

    it('sweeps expired records', function (): void {
        $tokens = new TokenRepository();
        $issued = $tokens->issue(7, 'client-1', ['read']);
        $stored = get_option(TokenRepository::ACCESS_OPTION);
        foreach ($stored as $hash => $record) {
            $stored[$hash]['expires_at'] = time() - 5;
        }
        update_option(TokenRepository::ACCESS_OPTION, $stored);

        (new ExpiredGrantCollector())->sweep();

        expect($this->optionValues[TokenRepository::ACCESS_OPTION])->toBe([])
            ->and($tokens->findAccessToken($issued['access_token']))->toBeNull();
    });
});

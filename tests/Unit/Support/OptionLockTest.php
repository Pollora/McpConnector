<?php

declare(strict_types=1);

use Pollora\McpConnector\Support\OptionLock;

/**
 * A lock that gives up at once instead of waiting for the one that holds it.
 */
function mcpcImpatientLock(int $timeout = 10): OptionLock
{
    return new OptionLock($timeout, 0);
}

describe('taking a lock', function (): void {
    it('is granted when nobody holds it', function (): void {
        expect(mcpcImpatientLock()->acquire('tokens'))->toBeTrue();
    });

    it('is refused while another request holds it', function (): void {
        $first = mcpcImpatientLock();
        $second = mcpcImpatientLock();

        expect($first->acquire('tokens'))->toBeTrue()
            ->and($second->acquire('tokens'))->toBeFalse();
    });

    it('is granted again once the holder has released it', function (): void {
        $first = mcpcImpatientLock();
        $second = mcpcImpatientLock();

        $first->acquire('tokens');
        $first->release('tokens');

        expect($second->acquire('tokens'))->toBeTrue();
    });

    it('is independent for each name', function (): void {
        $first = mcpcImpatientLock();
        $second = mcpcImpatientLock();

        $first->acquire('access');

        expect($second->acquire('refresh'))->toBeTrue();
    });
});

describe('a lock whose holder never released it', function (): void {
    it('is left alone while it is recent', function (): void {
        $GLOBALS['wpdb']->rows['mcp_connector_lock_tokens'] = (time() - 3) . ':someone';

        expect(mcpcImpatientLock(10)->acquire('tokens'))->toBeFalse();
    });

    it('is taken over once it is older than the timeout', function (): void {
        $GLOBALS['wpdb']->rows['mcp_connector_lock_tokens'] = (time() - 60) . ':dead';

        expect(mcpcImpatientLock(10)->acquire('tokens'))->toBeTrue();
    });

    it('is not released out from under its new holder by the request that lost it', function (): void {
        $slow = mcpcImpatientLock(10);
        $slow->acquire('tokens');

        // The slow request outlives the timeout; a second one takes the lock over.
        $GLOBALS['wpdb']->rows['mcp_connector_lock_tokens'] = (time() - 60) . ':' . 'expired';
        $taker = mcpcImpatientLock(10);
        $taker->acquire('tokens');

        // Waking up, the slow request releases what it believes is still its own.
        $slow->release('tokens');

        expect(mcpcImpatientLock(10)->acquire('tokens'))->toBeFalse();
    });
});

describe('releasing a lock', function (): void {
    it('does nothing for a lock that was never taken', function (): void {
        $holder = mcpcImpatientLock();
        $holder->acquire('tokens');

        mcpcImpatientLock()->release('tokens');

        expect(mcpcImpatientLock()->acquire('tokens'))->toBeFalse();
    });
});

describe('the details of a lock', function (): void {
    it('is taken over when it is exactly as old as the timeout', function (): void {
        $GLOBALS['wpdb']->rows['mcp_connector_lock_tokens'] = (time() - 10) . ':edge';

        expect(mcpcImpatientLock(10)->acquire('tokens'))->toBeTrue();
    });

    it('is left alone one second short of the timeout', function (): void {
        $GLOBALS['wpdb']->rows['mcp_connector_lock_tokens'] = (time() - 9) . ':edge';

        expect(mcpcImpatientLock(10)->acquire('tokens'))->toBeFalse();
    });

    it('stores the time and 16 random hex characters under a prefixed name', function (): void {
        mcpcImpatientLock()->acquire('tokens');

        expect(array_keys($GLOBALS['wpdb']->rows))->toBe(['mcp_connector_lock_tokens'])
            ->and($GLOBALS['wpdb']->rows['mcp_connector_lock_tokens'])->toMatch('/^\d{10}:[0-9a-f]{16}$/');
    });

    it('is taken over by writing a fresh token of its own', function (): void {
        $GLOBALS['wpdb']->rows['mcp_connector_lock_tokens'] = (time() - 60) . ':dead';

        mcpcImpatientLock(10)->acquire('tokens');

        expect($GLOBALS['wpdb']->rows['mcp_connector_lock_tokens'])->not->toContain('dead')
            ->and((int) $GLOBALS['wpdb']->rows['mcp_connector_lock_tokens'])->toBeGreaterThan(time() - 5);
    });

    it('waits for a lock that is released while it waits, and gives up after about its patience', function (): void {
        $holder = mcpcImpatientLock();
        $holder->acquire('tokens');

        $start = microtime(true);
        $refused = (new OptionLock(10, 300))->acquire('tokens');
        $waited = microtime(true) - $start;

        expect($refused)->toBeFalse()
            ->and($waited)->toBeGreaterThan(0.25)
            ->and($waited)->toBeLessThan(2.0);
    });

    it('tries at least once even with no patience at all', function (): void {
        expect((new OptionLock(10, 0))->acquire('tokens'))->toBeTrue();
    });
});

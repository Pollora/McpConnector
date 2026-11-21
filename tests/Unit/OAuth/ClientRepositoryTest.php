<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\OAuth\ClientLimitReachedException;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\TokenRepository;
use Pollora\McpConnector\Support\OptionLock;

beforeEach(function (): void {
    $counter = 0;
    Functions\when('wp_generate_uuid4')->alias(static function () use (&$counter): string {
        return 'uuid-' . ++$counter;
    });
    $tokenCounter = 0;
    Functions\when('wp_generate_password')->alias(
        static function (int $length = 12) use (&$tokenCounter): string {
            return substr(str_pad('t' . ++$tokenCounter, $length, 'x'), 0, $length);
        },
    );
    Functions\when('wp_hash_password')->alias(static fn (string $p): string => 'hashed:' . $p);
});

describe('registering a client', function (): void {
    it('stores a hash of the secret and returns the plaintext once', function (): void {
        $created = (new ClientRepository())->create('App', ['https://app.example/cb']);

        expect($created['secret'])->toHaveLength(48)
            ->and($created['client']->secretHash)->toBe('hashed:' . $created['secret'])
            ->and(serialize($this->optionValues[ClientRepository::OPTION]))->not->toContain('"' . $created['secret'] . '"');
    });

    it('gives a public client no secret at all', function (): void {
        $created = (new ClientRepository())->create('App', ['https://app.example/cb'], Client::AUTH_METHOD_NONE);

        expect($created['secret'])->toBe('')
            ->and($created['client']->secretHash)->toBe('')
            ->and($created['client']->isConfidential())->toBeFalse();
    });

    it('can find the client it stored, and not another', function (): void {
        $repo = new ClientRepository();
        $created = $repo->create('App', ['https://app.example/cb'], selfRegistered: true);

        expect($repo->find($created['client']->id))->toEqual($created['client'])
            ->and($created['client']->selfRegistered)->toBeTrue()
            ->and($repo->find('unknown'))->toBeNull()
            ->and($repo->find(''))->toBeNull();
    });

    it('lists the newest client first and skips corrupt records', function (): void {
        $repo = new ClientRepository();
        $this->setOption(ClientRepository::OPTION, [
            'a' => (new Client('a', '', 'Old', [], 'none', 100, false))->toArray(),
            'b' => (new Client('b', '', 'New', [], 'none', 200, false))->toArray(),
            'c' => 'corrupt',
        ]);

        expect(array_map(static fn (Client $c): string => $c->id, $repo->all()))->toBe(['b', 'a']);
    });
});

describe('deleting a client', function (): void {
    it('revokes the tokens it was issued, and only those', function (): void {
        $clients = new ClientRepository();
        $tokens = new TokenRepository();
        $doomed = $clients->create('Doomed', ['https://a.example/cb'])['client'];
        $other = $clients->create('Other', ['https://b.example/cb'])['client'];
        $doomedTokens = $tokens->issue(7, $doomed->id, ['read']);
        $otherTokens = $tokens->issue(7, $other->id, ['read']);

        expect($clients->delete($doomed->id))->toBeTrue()
            ->and($clients->find($doomed->id))->toBeNull()
            ->and($tokens->findAccessToken($doomedTokens['access_token']))->toBeNull()
            ->and($tokens->findAccessToken($otherTokens['access_token']))->not->toBeNull()
            ->and($clients->find($other->id))->not->toBeNull();
    });

    it('reports false for an unknown client', function (): void {
        expect((new ClientRepository())->delete('unknown'))->toBeFalse();
    });
});

/**
 * Seed the client option with self-registered clients created at the given times.
 *
 * @param array<string, int> $createdAt Client identifier to creation time.
 */
function mcpcSeedClients(array $createdAt, bool $selfRegistered = true): void
{
    $clients = [];

    foreach ($createdAt as $id => $time) {
        $clients[$id] = (new Client($id, '', 'App ' . $id, ['https://a.example/cb'], 'none', $time, $selfRegistered))->toArray();
    }

    update_option(ClientRepository::OPTION, $clients);
}

describe('the ceiling on self-registered clients', function (): void {
    it('defaults to 100 and follows the filter', function (): void {
        expect(ClientRepository::maxSelfRegistered())->toBe(100);

        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook === ClientRepository::MAX_FILTER ? '5' : $value);

        expect(ClientRepository::maxSelfRegistered())->toBe(5);
    });

    it('purges the oldest client without a live token to make room, and spares a connected one', function (): void {
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook === ClientRepository::MAX_FILTER ? 3 : $value);
        mcpcSeedClients(['oldest' => 100, 'middle' => 200, 'newest' => 300]);
        (new TokenRepository())->issue(7, 'oldest', ['read']);

        $created = (new ClientRepository())->create('New', ['https://n.example/cb'], selfRegistered: true);

        expect(array_keys($this->optionValues[ClientRepository::OPTION]))->toBe(['oldest', 'newest', $created['client']->id]);
    });

    it('refuses the registration when every client is connected', function (): void {
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook === ClientRepository::MAX_FILTER ? 2 : $value);
        mcpcSeedClients(['a' => 100, 'b' => 200]);
        $tokens = new TokenRepository();
        $tokens->issue(7, 'a', ['read']);
        $tokens->issue(7, 'b', ['read']);

        expect(fn () => (new ClientRepository())->create('New', ['https://n.example/cb'], selfRegistered: true))
            ->toThrow(ClientLimitReachedException::class)
            ->and(array_keys($this->optionValues[ClientRepository::OPTION]))->toBe(['a', 'b']);
    });

    it('does not count, or purge, the clients an administrator created', function (): void {
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook === ClientRepository::MAX_FILTER ? 1 : $value);
        mcpcSeedClients(['admin-1' => 1, 'admin-2' => 2], selfRegistered: false);

        (new ClientRepository())->create('Self', ['https://n.example/cb'], selfRegistered: true);
        (new ClientRepository())->create('By hand', ['https://n.example/cb']);

        expect($this->optionValues[ClientRepository::OPTION])->toHaveKeys(['admin-1', 'admin-2'])->toHaveCount(4);
    });

    it('refuses a self-registration while the store lock is held, and keeps the option', function (): void {
        $other = new OptionLock();
        $other->acquire('clients');

        expect(fn () => (new ClientRepository(new OptionLock(10, 0)))->create('New', ['https://n.example/cb'], selfRegistered: true))
            ->toThrow(ClientLimitReachedException::class)
            ->and($this->optionValues)->not->toHaveKey(ClientRepository::OPTION);
    });

    it('releases the lock after a registration, a refusal and a deletion', function (): void {
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook === ClientRepository::MAX_FILTER ? 0 : $value);
        $repo = new ClientRepository();

        try {
            $repo->create('New', ['https://n.example/cb'], selfRegistered: true);
        } catch (ClientLimitReachedException) {
        }

        $repo->delete('nobody');

        expect((new OptionLock(10, 0))->acquire('clients'))->toBeTrue();
    });
});

describe('purging the clients that never connected', function (): void {
    it('removes self-registered clients older than the age without a live token only', function (): void {
        $now = time();
        mcpcSeedClients(['stale' => $now - 8 * 86400, 'fresh' => $now - 86400, 'connected' => $now - 30 * 86400]);
        $this->optionValues[ClientRepository::OPTION]['admin'] = (new Client('admin', '', 'Admin', [], 'none', 1, false))->toArray();
        (new TokenRepository())->issue(7, 'connected', ['read']);

        $removed = (new ClientRepository())->purgeStale();

        expect($removed)->toBe(1)
            ->and(array_keys($this->optionValues[ClientRepository::OPTION]))->toBe(['fresh', 'connected', 'admin']);
    });
});

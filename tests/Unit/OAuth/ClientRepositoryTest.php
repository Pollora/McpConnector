<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\TokenRepository;

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

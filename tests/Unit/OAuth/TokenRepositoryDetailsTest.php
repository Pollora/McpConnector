<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\TokenRepository;

beforeEach(function (): void {
    $counter = 0;
    Functions\when('wp_generate_password')->alias(
        static function (int $length = 12) use (&$counter): string {
            return substr(str_pad('d' . ++$counter, $length, 'x'), 0, $length);
        },
    );
});

describe('what issuing generates and stores', function (): void {
    it('asks for 48 characters without special ones, twice', function (): void {
        $asked = [];
        Functions\when('wp_generate_password')->alias(static function (int $length, bool $special) use (&$asked): string {
            $asked[] = [$length, $special];

            return 'p' . count($asked);
        });

        $issued = (new TokenRepository())->issue(7, 'client-1', ['read']);

        expect($asked)->toBe([[48, false], [48, false]])
            ->and($issued['access_token'])->toBe('p1')
            ->and($issued['refresh_token'])->toBe('p2');
    });

    it('stores the same record under both tokens, apart from the expiry', function (): void {
        $before = time();
        $issued = (new TokenRepository())->issue(7, 'client-1', ['read', 'write']);

        $access = $this->optionValues[TokenRepository::ACCESS_OPTION][hash('sha256', $issued['access_token'])];
        $refresh = $this->optionValues[TokenRepository::REFRESH_OPTION][hash('sha256', $issued['refresh_token'])];
        $family = hash('sha256', $issued['refresh_token']);

        expect(array_keys($access))->toBe(['user_id', 'client_id', 'scopes', 'created_at', 'family', 'expires_at'])
            ->and(array_keys($refresh))->toBe(['user_id', 'client_id', 'scopes', 'created_at', 'family', 'expires_at'])
            ->and($access['user_id'])->toBe(7)
            ->and($access['client_id'])->toBe('client-1')
            ->and($access['scopes'])->toBe(['read', 'write'])
            ->and($access['created_at'])->toBeBetween($before, time())
            ->and($access['family'])->toBe($family)
            ->and($refresh['family'])->toBe($family)
            ->and($refresh['created_at'])->toBe($access['created_at']);
    });

    it('hands a given lineage on instead of starting one', function (): void {
        $issued = (new TokenRepository())->issue(7, 'client-1', ['read'], 'lineage-1');

        expect($this->optionValues[TokenRepository::REFRESH_OPTION][hash('sha256', $issued['refresh_token'])]['family'])->toBe('lineage-1');
    });
});

describe('refusing a refresh token before taking the lock', function (): void {
    it('writes nothing for an empty or an unknown token', function (): void {
        Functions\expect('update_option')->never();

        $repo = new TokenRepository();

        expect($repo->consumeRefreshToken(''))->toBeNull()
            ->and($repo->consumeRefreshToken('unknown'))->toBeNull();
    });

    it('does not take an empty token for the entry stored under the hash of nothing', function (): void {
        $this->setOption(TokenRepository::REFRESH_OPTION, [hash('sha256', '') => [
            'user_id' => 1, 'client_id' => 'c', 'scopes' => ['read'], 'expires_at' => time() + 600, 'family' => 'f',
        ]]);

        expect((new TokenRepository())->consumeRefreshToken(''))->toBeNull()
            ->and($this->optionValues[TokenRepository::REFRESH_OPTION])->toHaveKey(hash('sha256', ''));
    });
});

describe('a replay whose lineage is blank', function (): void {
    it('revokes nothing, and in particular drops no unrelated entry', function (): void {
        $spent = hash('sha256', 'spent-token');
        $this->setOption(TokenRepository::REFRESH_OPTION, [
            $spent => ['spent' => true, 'family' => '', 'client_id' => 'c', 'user_id' => 1, 'expires_at' => time() + 600],
            'not-a-record' => 'junk',
        ]);

        expect((new TokenRepository())->consumeRefreshToken('spent-token'))->toBeNull()
            ->and($this->optionValues[TokenRepository::REFRESH_OPTION])->toHaveKey('not-a-record');
    });
});

describe('revoking by client or user', function (): void {
    it('revokes a record that names no client when asked to revoke the empty client, and only then', function (): void {
        $this->setOption(TokenRepository::ACCESS_OPTION, [
            'anonymous' => ['user_id' => 1, 'expires_at' => time() + 600],
            'named' => ['user_id' => 1, 'client_id' => 'c', 'expires_at' => time() + 600],
        ]);

        (new TokenRepository())->revokeClient('other');
        expect(array_keys($this->optionValues[TokenRepository::ACCESS_OPTION]))->toBe(['anonymous', 'named']);

        (new TokenRepository())->revokeClient('');
        expect(array_keys($this->optionValues[TokenRepository::ACCESS_OPTION]))->toBe(['named']);
    });

    it('treats a record that names no user as user 0, and a stored string id as the number', function (): void {
        $this->setOption(TokenRepository::ACCESS_OPTION, [
            'anonymous' => ['client_id' => 'c', 'expires_at' => time() + 600],
            'five' => ['user_id' => '5', 'client_id' => 'c', 'expires_at' => time() + 600],
        ]);

        (new TokenRepository())->revokeUser(1);
        expect(array_keys($this->optionValues[TokenRepository::ACCESS_OPTION]))->toBe(['anonymous', 'five']);

        (new TokenRepository())->revokeUser(5);
        expect(array_keys($this->optionValues[TokenRepository::ACCESS_OPTION]))->toBe(['anonymous']);

        (new TokenRepository())->revokeUser(0);
        expect($this->optionValues[TokenRepository::ACCESS_OPTION])->toBe([]);
    });
});

describe('purging at the boundary', function (): void {
    it('removes a record that expires this very second, and a record with no expiry', function (): void {
        $this->setOption(TokenRepository::ACCESS_OPTION, [
            'now' => ['expires_at' => time()],
            'never-set' => ['client_id' => 'c'],
            'string-future' => ['expires_at' => (string) (time() + 600)],
            'future' => ['expires_at' => time() + 600],
        ]);

        $removed = (new TokenRepository())->purgeExpired();

        expect($removed)->toBe(2)
            ->and(array_keys($this->optionValues[TokenRepository::ACCESS_OPTION]))->toBe(['string-future', 'future']);
    });
});

describe('counting live tokens', function (): void {
    it('skips an entry that is not a record without stopping at it', function (): void {
        $this->setOption(TokenRepository::ACCESS_OPTION, [
            'junk' => 'x',
            'expired' => ['client_id' => 'a', 'expires_at' => time() - 5],
            'now' => ['client_id' => 'a', 'expires_at' => time()],
            'one' => ['client_id' => 'a', 'expires_at' => time() + 600],
            'two' => ['client_id' => 'a', 'expires_at' => time() + 600],
            'other' => ['client_id' => 'b', 'expires_at' => time() + 600],
            'nameless' => ['expires_at' => time() + 600],
        ]);

        expect((new TokenRepository())->liveAccessTokenCounts())->toBe(['a' => 2, 'b' => 1, '' => 1]);
    });

    it('summarises users once each, the newest issue and the latest expiry', function (): void {
        $now = time();
        $this->setOption(TokenRepository::ACCESS_OPTION, [
            'junk' => 'x',
            'expired' => ['client_id' => 'a', 'user_id' => 9, 'created_at' => 1, 'expires_at' => $now - 5],
            'now' => ['client_id' => 'a', 'user_id' => 8, 'created_at' => 2, 'expires_at' => $now],
            'one' => ['client_id' => 'a', 'user_id' => 3, 'created_at' => 100, 'expires_at' => $now + 600],
            'two' => ['client_id' => 'a', 'user_id' => '3', 'created_at' => '300', 'expires_at' => (string) ($now + 900)],
            'three' => ['client_id' => 'a', 'user_id' => 4, 'created_at' => 200, 'expires_at' => $now + 300],
            'anonymous' => ['client_id' => 'a', 'expires_at' => $now + 100],
            'nameless' => ['user_id' => 6, 'expires_at' => $now + 700],
        ]);

        expect((new TokenRepository())->liveAccessTokenSummaries())->toBe([
            'a' => ['count' => 4, 'users' => [3, 4], 'issued' => 300, 'expires' => $now + 900],
            '' => ['count' => 1, 'users' => [6], 'issued' => 0, 'expires' => $now + 700],
        ]);
    });
});

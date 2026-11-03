<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\TokenRepository;
use Pollora\McpConnector\Support\OptionLock;

beforeEach(function (): void {
    // The shared stub is deterministic; token tests need every call to differ.
    $counter = 0;
    Functions\when('wp_generate_password')->alias(
        static function (int $length = 12) use (&$counter): string {
            return substr(str_pad('t' . ++$counter, $length, 'x'), 0, $length);
        },
    );
});

/**
 * Rewrite the expiry of every stored record of an option.
 */
function mcpcAgeTokens(string $option, int $expiresAt): void
{
    $stored = get_option($option);

    foreach ($stored as $hash => $record) {
        $stored[$hash]['expires_at'] = $expiresAt;
    }

    update_option($option, $stored);
}

describe('issuing', function (): void {
    it('returns both tokens in plaintext with the scope and lifetime', function (): void {
        $issued = (new TokenRepository())->issue(7, 'client-1', ['read', 'write']);

        expect($issued['access_token'])->toHaveLength(48)
            ->and($issued['refresh_token'])->toHaveLength(48)
            ->and($issued['access_token'])->not->toBe($issued['refresh_token'])
            ->and($issued['expires_in'])->toBe(3600)
            ->and($issued['scope'])->toBe('read write');
    });

    it('stores only the SHA-256 of each token, never the plaintext', function (): void {
        $issued = (new TokenRepository())->issue(7, 'client-1', ['read']);

        $access = $this->optionValues[TokenRepository::ACCESS_OPTION];
        $refresh = $this->optionValues[TokenRepository::REFRESH_OPTION];

        expect(array_keys($access))->toBe([hash('sha256', $issued['access_token'])])
            ->and(array_keys($refresh))->toBe([hash('sha256', $issued['refresh_token'])])
            ->and(serialize($this->optionValues))->not->toContain($issued['access_token'])
            ->and(serialize($this->optionValues))->not->toContain($issued['refresh_token']);
    });

    it('gives the access token an hour and the refresh token thirty days', function (): void {
        $before = time();
        (new TokenRepository())->issue(7, 'client-1', ['read']);
        $after = time();

        $access = array_values($this->optionValues[TokenRepository::ACCESS_OPTION])[0];
        $refresh = array_values($this->optionValues[TokenRepository::REFRESH_OPTION])[0];

        expect($access['expires_at'])->toBeBetween($before + 3600, $after + 3600)
            ->and($refresh['expires_at'])->toBeBetween($before + 30 * 86400, $after + 30 * 86400);
    });
});

describe('looking up an access token', function (): void {
    it('resolves a live token to its user, client and scopes', function (): void {
        $repo = new TokenRepository();
        $issued = $repo->issue(7, 'client-1', ['read', 'write']);

        $record = $repo->findAccessToken($issued['access_token']);

        expect($record)->toMatchArray(['user_id' => 7, 'client_id' => 'client-1', 'scopes' => ['read', 'write']]);
    });

    it('does not accept a refresh token as an access token', function (): void {
        $repo = new TokenRepository();
        $issued = $repo->issue(7, 'client-1', ['read']);

        expect($repo->findAccessToken($issued['refresh_token']))->toBeNull();
    });

    it('refuses unknown, empty and altered tokens', function (string $token): void {
        $repo = new TokenRepository();
        $repo->issue(7, 'client-1', ['read']);

        expect($repo->findAccessToken($token))->toBeNull();
    })->with([
        'unknown' => ['not-a-token'],
        'empty' => [''],
        'a stored hash presented as the token' => [hash('sha256', 't1')],
    ]);

    it('refuses an expired token, including one expiring this very second', function (int $offset): void {
        $repo = new TokenRepository();
        $issued = $repo->issue(7, 'client-1', ['read']);

        mcpcAgeTokens(TokenRepository::ACCESS_OPTION, time() + $offset);

        expect($repo->findAccessToken($issued['access_token']))->toBeNull();
    })->with([
        'expired a minute ago' => [-60],
        'expiring now' => [0],
    ]);

    it('ignores a corrupt option value', function (): void {
        $this->setOption(TokenRepository::ACCESS_OPTION, 'garbage');

        expect((new TokenRepository())->findAccessToken('anything'))->toBeNull();
    });
});

describe('refresh tokens', function (): void {
    it('are consumed once and cannot be replayed', function (): void {
        $repo = new TokenRepository();
        $issued = $repo->issue(7, 'client-1', ['read']);

        expect($repo->consumeRefreshToken($issued['refresh_token']))->toMatchArray(['user_id' => 7])
            ->and($repo->consumeRefreshToken($issued['refresh_token']))->toBeNull();
    });

    it('cannot be redeemed after expiry, and are removed anyway', function (): void {
        $repo = new TokenRepository();
        $issued = $repo->issue(7, 'client-1', ['read']);

        mcpcAgeTokens(TokenRepository::REFRESH_OPTION, time() - 1);

        expect($repo->consumeRefreshToken($issued['refresh_token']))->toBeNull()
            ->and($this->optionValues[TokenRepository::REFRESH_OPTION])->toBe([]);
    });

    it('are not accepted when an access token is presented', function (): void {
        $repo = new TokenRepository();
        $issued = $repo->issue(7, 'client-1', ['read']);

        expect($repo->consumeRefreshToken($issued['access_token']))->toBeNull()
            ->and($repo->findAccessToken($issued['access_token']))->not->toBeNull();
    });
});

describe('revocation', function (): void {
    it('revokes an access token by its plaintext', function (): void {
        $repo = new TokenRepository();
        $issued = $repo->issue(7, 'client-1', ['read']);

        expect($repo->revoke($issued['access_token']))->toBeTrue()
            ->and($repo->findAccessToken($issued['access_token']))->toBeNull();
    });

    it('revokes a refresh token by its plaintext', function (): void {
        $repo = new TokenRepository();
        $issued = $repo->issue(7, 'client-1', ['read']);

        expect($repo->revoke($issued['refresh_token']))->toBeTrue()
            ->and($repo->consumeRefreshToken($issued['refresh_token']))->toBeNull();
    });

    it('reports false for an unknown token and leaves the others alone', function (): void {
        $repo = new TokenRepository();
        $issued = $repo->issue(7, 'client-1', ['read']);

        expect($repo->revoke('unknown'))->toBeFalse()
            ->and($repo->revoke(''))->toBeFalse()
            ->and($repo->findAccessToken($issued['access_token']))->not->toBeNull();
    });

    it('revokes a token for the client it was issued to and no other', function (): void {
        $repo = new TokenRepository();
        $issued = $repo->issue(7, 'client-1', ['read']);

        expect($repo->revoke($issued['access_token'], 'client-2'))->toBeFalse()
            ->and($repo->revoke($issued['refresh_token'], 'client-2'))->toBeFalse()
            ->and($repo->findAccessToken($issued['access_token']))->not->toBeNull()
            ->and($repo->revoke($issued['access_token'], 'client-1'))->toBeTrue()
            ->and($repo->findAccessToken($issued['access_token']))->toBeNull()
            ->and($repo->revoke($issued['refresh_token'], 'client-1'))->toBeTrue();
    });

    it('revokes every token of one client and none of another', function (): void {
        $repo = new TokenRepository();
        $mine = $repo->issue(7, 'client-1', ['read']);
        $other = $repo->issue(7, 'client-2', ['read']);

        $repo->revokeClient('client-1');

        expect($repo->findAccessToken($mine['access_token']))->toBeNull()
            ->and($repo->consumeRefreshToken($mine['refresh_token']))->toBeNull()
            ->and($repo->findAccessToken($other['access_token']))->not->toBeNull()
            ->and($repo->consumeRefreshToken($other['refresh_token']))->not->toBeNull();
    });

    it('revokes every token of one user and none of another', function (): void {
        $repo = new TokenRepository();
        $gone = $repo->issue(7, 'client-1', ['read']);
        $kept = $repo->issue(8, 'client-1', ['read']);

        $repo->revokeUser(7);

        expect($repo->findAccessToken($gone['access_token']))->toBeNull()
            ->and($repo->consumeRefreshToken($gone['refresh_token']))->toBeNull()
            ->and($repo->findAccessToken($kept['access_token']))->not->toBeNull();
    });
});

describe('housekeeping', function (): void {
    it('purges expired records and counts them', function (): void {
        $repo = new TokenRepository();
        $old = $repo->issue(7, 'client-1', ['read']);
        mcpcAgeTokens(TokenRepository::ACCESS_OPTION, time() - 10);
        mcpcAgeTokens(TokenRepository::REFRESH_OPTION, time() - 10);
        $live = $repo->issue(7, 'client-1', ['read']);

        expect($repo->purgeExpired())->toBe(2)
            ->and($repo->findAccessToken($live['access_token']))->not->toBeNull()
            ->and($this->optionValues[TokenRepository::ACCESS_OPTION])->toHaveCount(1)
            ->and($old['access_token'])->not->toBe($live['access_token']);
    });

    it('purges nothing when everything is live', function (): void {
        $repo = new TokenRepository();
        $repo->issue(7, 'client-1', ['read']);

        expect($repo->purgeExpired())->toBe(0);
    });

    it('counts and summarises only live access tokens per client', function (): void {
        $repo = new TokenRepository();
        $repo->issue(7, 'client-1', ['read']);
        $repo->issue(8, 'client-1', ['read']);
        $repo->issue(7, 'client-2', ['read']);

        expect($repo->liveAccessTokenCounts())->toBe(['client-1' => 2, 'client-2' => 1]);

        $summary = $repo->liveAccessTokenSummaries();

        expect($summary['client-1']['count'])->toBe(2)
            ->and($summary['client-1']['users'])->toBe([7, 8]);

        mcpcAgeTokens(TokenRepository::ACCESS_OPTION, time() - 1);

        expect($repo->liveAccessTokenCounts())->toBe([])
            ->and($repo->liveAccessTokenSummaries())->toBe([]);
    });
});

describe('two requests at the same moment', function (): void {
    it('refuses a refresh token while another request holds the lock, and keeps it', function (): void {
        $issued = (new TokenRepository())->issue(7, 'client-1', ['read']);

        $other = new OptionLock();
        $other->acquire(TokenRepository::REFRESH_OPTION);

        $repository = new TokenRepository(new OptionLock(10, 0));

        expect($repository->consumeRefreshToken($issued['refresh_token']))->toBeNull();

        $other->release(TokenRepository::REFRESH_OPTION);

        expect($repository->consumeRefreshToken($issued['refresh_token']))->toMatchArray(['user_id' => 7]);
    });

    it('looks at the token again once it holds the lock', function (): void {
        $issued = (new TokenRepository())->issue(7, 'client-1', ['read']);

        // The cache is cleared right after the lock is taken. That is the window in
        // which another request can spend the token the first look still saw.
        Functions\when('wp_cache_delete')->alias(function (): bool {
            update_option(TokenRepository::REFRESH_OPTION, []);

            return true;
        });

        expect((new TokenRepository())->consumeRefreshToken($issued['refresh_token']))->toBeNull();
    });

    it('spends a refresh token once, whoever asks first', function (): void {
        $issued = (new TokenRepository())->issue(7, 'client-1', ['read']);

        $first = (new TokenRepository())->consumeRefreshToken($issued['refresh_token']);
        $second = (new TokenRepository())->consumeRefreshToken($issued['refresh_token']);

        expect($first)->toMatchArray(['user_id' => 7])
            ->and($second)->toBeNull();
    });

    it('lets go of the lock once the token is spent', function (): void {
        $issued = (new TokenRepository())->issue(7, 'client-1', ['read']);

        (new TokenRepository())->consumeRefreshToken($issued['refresh_token']);

        expect((new OptionLock(10, 0))->acquire(TokenRepository::REFRESH_OPTION))->toBeTrue();
    });

    it('does not lose a token that is issued while the lock is held elsewhere', function (): void {
        $other = new OptionLock();
        $other->acquire(TokenRepository::ACCESS_OPTION);

        $repository = new TokenRepository(new OptionLock(10, 0));
        $issued = $repository->issue(7, 'client-1', ['read']);

        expect($repository->findAccessToken($issued['access_token']))->toMatchArray(['client_id' => 'client-1']);
    });

    it('lets a revocation through while the lock is held elsewhere', function (): void {
        $repository = new TokenRepository(new OptionLock(10, 0));
        $issued = $repository->issue(7, 'client-1', ['read']);

        $other = new OptionLock();
        $other->acquire(TokenRepository::ACCESS_OPTION);

        expect($repository->revoke($issued['access_token']))->toBeTrue()
            ->and($repository->findAccessToken($issued['access_token']))->toBeNull();
    });
});

/**
 * Pass a refresh token through one rotation, as the token endpoint does.
 *
 * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
 */
function mcpcRotate(TokenRepository $repo, string $refreshToken): array
{
    $record = $repo->consumeRefreshToken($refreshToken);

    return $repo->issue($record['user_id'], $record['client_id'], $record['scopes'], $record['family']);
}

/**
 * Make the markers of spent refresh tokens look older than they are allowed to be.
 */
function mcpcExpireSpentMarkers(): void
{
    $stored = get_option(TokenRepository::REFRESH_OPTION);

    foreach ($stored as $hash => $record) {
        if (($record['spent'] ?? false) === true) {
            $stored[$hash]['expires_at'] = time() - 1;
        }
    }

    update_option(TokenRepository::REFRESH_OPTION, $stored);
}

describe('a refresh token presented again after it was spent', function (): void {
    it('revokes the whole lineage, the pairs that replaced it included', function (): void {
        $repo = new TokenRepository();
        $first = $repo->issue(7, 'client-1', ['read']);
        $second = mcpcRotate($repo, $first['refresh_token']);
        $third = mcpcRotate($repo, $second['refresh_token']);

        expect($repo->findAccessToken($third['access_token']))->not->toBeNull()
            ->and($repo->consumeRefreshToken($first['refresh_token']))->toBeNull()
            ->and($repo->findAccessToken($second['access_token']))->toBeNull()
            ->and($repo->findAccessToken($third['access_token']))->toBeNull()
            ->and($repo->consumeRefreshToken($third['refresh_token']))->toBeNull();
    });

    it('leaves another lineage alone', function (): void {
        $repo = new TokenRepository();
        $mine = $repo->issue(7, 'client-1', ['read']);
        $other = $repo->issue(7, 'client-1', ['read']);
        $next = mcpcRotate($repo, $mine['refresh_token']);

        $repo->consumeRefreshToken($mine['refresh_token']);

        expect($repo->findAccessToken($next['access_token']))->toBeNull()
            ->and($repo->findAccessToken($other['access_token']))->not->toBeNull()
            ->and($repo->consumeRefreshToken($other['refresh_token']))->not->toBeNull();
    });

    it('is answered like an unknown token, whoever presents it', function (): void {
        $repo = new TokenRepository();
        $first = $repo->issue(7, 'client-1', ['read']);
        mcpcRotate($repo, $first['refresh_token']);

        expect($repo->consumeRefreshToken($first['refresh_token']))->toBeNull()
            ->and($repo->consumeRefreshToken('never-issued'))->toBeNull();
    });

    it('revokes nothing once its marker has expired, the token being simply unknown', function (): void {
        $repo = new TokenRepository();
        $first = $repo->issue(7, 'client-1', ['read']);
        $second = mcpcRotate($repo, $first['refresh_token']);

        mcpcExpireSpentMarkers();

        expect($repo->consumeRefreshToken($first['refresh_token']))->toBeNull()
            ->and($repo->findAccessToken($second['access_token']))->not->toBeNull()
            ->and($repo->consumeRefreshToken($second['refresh_token']))->not->toBeNull();
    });
});

describe('the lineage of a token', function (): void {
    it('is shared by the two tokens of a pair', function (): void {
        (new TokenRepository())->issue(7, 'client-1', ['read']);

        $access = array_values($this->optionValues[TokenRepository::ACCESS_OPTION])[0]['family'];
        $refresh = array_values($this->optionValues[TokenRepository::REFRESH_OPTION])[0]['family'];

        expect($access)->toBeString()->not->toBe('')
            ->and($refresh)->toBe($access);
    });

    it('is handed on by a rotation', function (): void {
        $repo = new TokenRepository();
        $first = $repo->issue(7, 'client-1', ['read']);
        $second = mcpcRotate($repo, $first['refresh_token']);

        $family = array_values($this->optionValues[TokenRepository::ACCESS_OPTION])[0]['family'];

        expect($repo->consumeRefreshToken($second['refresh_token']))->toMatchArray(['family' => $family]);
    });

    it('is absent from a token issued before lineages existed, which then leaves no marker', function (): void {
        update_option(TokenRepository::REFRESH_OPTION, [
            hash('sha256', 'legacy') => [
                'user_id' => 7,
                'client_id' => 'client-1',
                'scopes' => ['read'],
                'created_at' => time(),
                'expires_at' => time() + 100,
            ],
        ]);
        $repo = new TokenRepository();

        expect($repo->consumeRefreshToken('legacy'))->toMatchArray(['user_id' => 7, 'family' => null])
            ->and($this->optionValues[TokenRepository::REFRESH_OPTION])->toBe([])
            ->and($repo->consumeRefreshToken('legacy'))->toBeNull();
    });
});

describe('the marker of a spent refresh token', function (): void {
    it('is never accepted as a token, and never counts as one', function (): void {
        $repo = new TokenRepository();
        $first = $repo->issue(7, 'client-1', ['read']);
        $repo->consumeRefreshToken($first['refresh_token']);

        expect($this->optionValues[TokenRepository::REFRESH_OPTION][hash('sha256', $first['refresh_token'])])
            ->toMatchArray(['spent' => true, 'client_id' => 'client-1', 'user_id' => 7]);
    });

    it('is dropped with the other expired records', function (): void {
        $repo = new TokenRepository();
        $first = $repo->issue(7, 'client-1', ['read']);
        $repo->consumeRefreshToken($first['refresh_token']);

        mcpcExpireSpentMarkers();

        expect($repo->purgeExpired())->toBe(1)
            ->and($this->optionValues[TokenRepository::REFRESH_OPTION])->toBe([]);
    });

    it('is capped at a week, however long the token would have lived', function (): void {
        $repo = new TokenRepository();
        $first = $repo->issue(7, 'client-1', ['read']);
        $repo->consumeRefreshToken($first['refresh_token']);

        $marker = $this->optionValues[TokenRepository::REFRESH_OPTION][hash('sha256', $first['refresh_token'])];

        expect($marker['expires_at'])->toBeLessThanOrEqual(time() + TokenRepository::SPENT_TTL)
            ->and($marker['expires_at'])->toBeGreaterThan(time() + TokenRepository::SPENT_TTL - 5);
    });

    it('goes with the client and with the user it belonged to', function (): void {
        $repo = new TokenRepository();
        $mine = $repo->issue(7, 'client-1', ['read']);
        $theirs = $repo->issue(8, 'client-2', ['read']);
        $repo->consumeRefreshToken($mine['refresh_token']);
        $repo->consumeRefreshToken($theirs['refresh_token']);

        $repo->revokeClient('client-1');

        expect(array_keys($this->optionValues[TokenRepository::REFRESH_OPTION]))->toBe([hash('sha256', $theirs['refresh_token'])]);

        $repo->revokeUser(8);

        expect($this->optionValues[TokenRepository::REFRESH_OPTION])->toBe([]);
    });
});

describe('an entry that is not a record', function (): void {
    /**
     * Seed an option with two valid records and some entries that are not records at all.
     *
     * @return array<string, mixed>
     */
    function mcpcCorruptTokens(): array
    {
        $record = static fn (int $user, string $client, string $family): array => [
            'user_id' => $user,
            'client_id' => $client,
            'scopes' => ['read'],
            'created_at' => time(),
            'expires_at' => time() + 600,
            'family' => $family,
        ];

        return [
            'mine' => $record(7, 'client-1', 'fam-1'),
            'theirs' => $record(8, 'client-2', 'fam-2'),
            'a string' => 'garbage',
            'a null' => null,
            'an int' => 42,
        ];
    }

    it('does not survive the revocation of a client', function (): void {
        $this->setOption(TokenRepository::ACCESS_OPTION, mcpcCorruptTokens());

        (new TokenRepository())->revokeClient('client-1');

        expect(array_keys($this->optionValues[TokenRepository::ACCESS_OPTION]))->toBe(['theirs']);
    });

    it('does not survive the revocation of a user', function (): void {
        $this->setOption(TokenRepository::REFRESH_OPTION, mcpcCorruptTokens());

        (new TokenRepository())->revokeUser(7);

        expect(array_keys($this->optionValues[TokenRepository::REFRESH_OPTION]))->toBe(['theirs']);
    });

    it('does not survive the revocation of a lineage', function (): void {
        $repo = new TokenRepository();
        $first = $repo->issue(7, 'client-1', ['read']);
        $repo->consumeRefreshToken($first['refresh_token']);
        $this->setOption(
            TokenRepository::REFRESH_OPTION,
            $this->optionValues[TokenRepository::REFRESH_OPTION] + ['a string' => 'garbage', 'an int' => 42],
        );

        $repo->consumeRefreshToken($first['refresh_token']);

        expect($this->optionValues[TokenRepository::REFRESH_OPTION])->toBe([]);
    });

    it('is dropped by the purge of expired records, as it already was', function (): void {
        $this->setOption(TokenRepository::ACCESS_OPTION, mcpcCorruptTokens());

        $removed = (new TokenRepository())->purgeExpired();

        expect($removed)->toBe(3)
            ->and(array_keys($this->optionValues[TokenRepository::ACCESS_OPTION]))->toBe(['mine', 'theirs']);
    });
});

<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\TokenRepository;

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

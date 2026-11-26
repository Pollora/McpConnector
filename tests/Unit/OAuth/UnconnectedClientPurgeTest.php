<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\AuthorizationCodeRepository;
use Pollora\McpConnector\OAuth\Client;
use Pollora\McpConnector\OAuth\ClientLimitReachedException;
use Pollora\McpConnector\OAuth\ClientRepository;
use Pollora\McpConnector\OAuth\Controller\TokenController;
use Pollora\McpConnector\OAuth\ExpiredGrantCollector;
use Pollora\McpConnector\OAuth\Pkce;
use Pollora\McpConnector\OAuth\TokenRepository;
use Pollora\McpConnector\Settings;
use Pollora\McpConnector\Support\OptionLock;

require_once __DIR__ . '/../../Fixtures/oauth-request.php';

/**
 * Register a public client through the self-registration path.
 */
function mcpcRegisterPublicClient(): Client
{
    return (new ClientRepository())->create('Self', ['https://app.example/cb'], Client::AUTH_METHOD_NONE, selfRegistered: true)['client'];
}

/**
 * Redeem a fresh authorization code for a client at the token endpoint.
 */
function mcpcConnect(string $clientId, ?ClientRepository $clients = null, ?string $verifier = null): WP_REST_Response
{
    $pkce = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $codes = new AuthorizationCodeRepository();
    $code = $codes->issue(7, $clientId, 'https://app.example/cb', Pkce::challengeFor($pkce), ['read']);

    return (new TokenController(Settings::fromArray([]), $clients ?? new ClientRepository(), $codes, new TokenRepository()))
        ->handle(mcpcTokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'redirect_uri' => 'https://app.example/cb',
            'code_verifier' => $verifier ?? $pkce,
        ]));
}

/**
 * Make every stored client look a month old.
 *
 * @param array<string, mixed> $stored The client option.
 *
 * @return array<string, mixed> The same records, registered thirty days ago.
 */
function mcpcAged(array $stored): array
{
    return array_map(static fn (array $record): array => ['created_at' => time() - 30 * 86400] + $record, $stored);
}

/**
 * A record as stored before unconnected registrations were marked.
 *
 * @return array<string, mixed>
 */
function mcpcLegacyRecord(string $id): array
{
    return (new Client($id, '', 'Legacy', [], 'none', 1, true))->toArray();
}

beforeEach(function (): void {
    $tokens = 0;
    Functions\when('wp_generate_password')->alias(static function (int $length = 12) use (&$tokens): string {
        return substr(str_pad('t' . ++$tokens, $length, 'x'), 0, $length);
    });
    $ids = 0;
    Functions\when('wp_generate_uuid4')->alias(static function () use (&$ids): string {
        return 'client-' . ++$ids;
    });
    Functions\when('user_can')->justReturn(true);

    $this->cap = function (int $max): void {
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook === ClientRepository::MAX_FILTER ? $max : $value);
    };
    $this->age = function (): void {
        $this->optionValues[ClientRepository::OPTION] = mcpcAged($this->optionValues[ClientRepository::OPTION]);
    };
});

describe('a client that has been issued a token', function (): void {
    it('survives the sweep once it has gone idle and its tokens have expired', function (): void {
        $client = mcpcRegisterPublicClient();
        expect(mcpcConnect($client->id)->get_status())->toBe(200);

        foreach ([TokenRepository::ACCESS_OPTION, TokenRepository::REFRESH_OPTION] as $option) {
            $this->optionValues[$option] = array_map(
                static fn (array $record): array => ['expires_at' => time() - 5] + $record,
                $this->optionValues[$option],
            );
        }
        ($this->age)();

        (new ExpiredGrantCollector())->sweep();

        expect((new ClientRepository())->find($client->id))->not->toBeNull()
            ->and($this->optionValues[TokenRepository::REFRESH_OPTION])->toBe([]);
    });

    it('survives the sweep once its tokens have been revoked', function (): void {
        $client = mcpcRegisterPublicClient();
        mcpcConnect($client->id);
        (new TokenRepository())->revokeClient($client->id);
        ($this->age)();

        expect((new ClientRepository())->purgeStale())->toBe(0)
            ->and((new ClientRepository())->find($client->id))->not->toBeNull();
    });

    it('is not evicted at the ceiling, and the registration is refused instead', function (): void {
        ($this->cap)(1);
        $client = mcpcRegisterPublicClient();
        mcpcConnect($client->id);
        (new TokenRepository())->revokeClient($client->id);

        expect(fn () => mcpcRegisterPublicClient())->toThrow(ClientLimitReachedException::class)
            ->and(array_keys($this->optionValues[ClientRepository::OPTION]))->toBe([$client->id]);
    });

    it('is only marked as connected by a grant that actually yields tokens', function (): void {
        $client = mcpcRegisterPublicClient();

        expect(mcpcConnect($client->id, verifier: str_repeat('a', 43))->get_status())->toBe(400);
        ($this->age)();

        expect((new ClientRepository())->purgeStale())->toBe(1);
    });
});

describe('a record stored before unconnected registrations were marked', function (): void {
    it('is kept by the sweep, which still removes a marked one', function (): void {
        $marked = mcpcRegisterPublicClient();
        ($this->age)();
        $this->optionValues[ClientRepository::OPTION]['legacy'] = mcpcLegacyRecord('legacy');

        expect((new ClientRepository())->purgeStale())->toBe(1)
            ->and(array_keys($this->optionValues[ClientRepository::OPTION]))->toBe(['legacy'])
            ->and((new ClientRepository())->find($marked->id))->toBeNull();
    });

    it('counts towards the ceiling but is never evicted', function (): void {
        ($this->cap)(2);
        $this->setOption(ClientRepository::OPTION, ['a' => mcpcLegacyRecord('a'), 'b' => mcpcLegacyRecord('b')]);

        expect(fn () => mcpcRegisterPublicClient())->toThrow(ClientLimitReachedException::class)
            ->and(array_keys($this->optionValues[ClientRepository::OPTION]))->toBe(['a', 'b']);

        (new ClientRepository())->delete('a');

        expect(mcpcRegisterPublicClient()->selfRegistered)->toBeTrue()
            ->and($this->optionValues[ClientRepository::OPTION])->toHaveCount(2);
    });

    it('leaves the unconnected ones alone too when evicting them would not make enough room', function (): void {
        ($this->cap)(3);
        $pending = mcpcRegisterPublicClient();
        $this->optionValues[ClientRepository::OPTION] += ['a' => mcpcLegacyRecord('a'), 'b' => mcpcLegacyRecord('b'), 'c' => mcpcLegacyRecord('c')];

        expect(fn () => mcpcRegisterPublicClient())->toThrow(ClientLimitReachedException::class)
            ->and((new ClientRepository())->find($pending->id))->not->toBeNull()
            ->and($this->optionValues[ClientRepository::OPTION])->toHaveCount(4);
    });
});

describe('the client store being busy or changed', function (): void {
    it('issues no token while the connection cannot be recorded', function (): void {
        $client = mcpcRegisterPublicClient();
        (new OptionLock())->acquire('clients');

        $response = mcpcConnect($client->id, new ClientRepository(new OptionLock(10, 0)));

        expect($response->get_status())->toBe(400)
            ->and($response->get_data()['error'])->toBe('invalid_grant')
            ->and($this->optionValues)->not->toHaveKey(TokenRepository::ACCESS_OPTION);
    });

    it('cannot record the connection of a client that is gone', function (): void {
        $client = mcpcRegisterPublicClient();
        $repo = new ClientRepository();
        $repo->delete($client->id);

        expect($repo->markConnected($client->id))->toBeFalse()
            ->and($this->optionValues[ClientRepository::OPTION])->toBe([]);
    });

    it('records a connection without writing again once it is known', function (): void {
        $client = mcpcRegisterPublicClient();
        $repo = new ClientRepository();

        expect($repo->markConnected($client->id))->toBeTrue();

        (new OptionLock())->acquire('clients');

        expect((new ClientRepository(new OptionLock(10, 0)))->markConnected($client->id))->toBeTrue();
    });

    it('skips the sweep rather than write without the lock', function (): void {
        mcpcRegisterPublicClient();
        ($this->age)();
        (new OptionLock())->acquire('clients');

        expect((new ClientRepository(new OptionLock(10, 0)))->purgeStale())->toBe(0)
            ->and($this->optionValues[ClientRepository::OPTION])->toHaveCount(1);
    });
});

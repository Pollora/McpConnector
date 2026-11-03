<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\OAuth\Client;

function mcpcClient(string $secretHash = 'hash', string $authMethod = 'client_secret_post', array $uris = ['https://app.example/cb']): Client
{
    return new Client('client-1', $secretHash, 'App', $uris, $authMethod, 1000, false);
}

describe('redirect URI matching', function (): void {
    it('accepts a registered URI', function (): void {
        expect(mcpcClient()->allowsRedirectUri('https://app.example/cb'))->toBeTrue();
    });

    it('accepts any of several registered URIs', function (): void {
        $client = mcpcClient(uris: ['https://a.example/cb', 'http://127.0.0.1:8080/cb']);

        expect($client->allowsRedirectUri('http://127.0.0.1:8080/cb'))->toBeTrue();
    });

    it('requires an exact match', function (string $presented): void {
        expect(mcpcClient()->allowsRedirectUri($presented))->toBeFalse();
    })->with([
        'a longer path (prefix match)' => ['https://app.example/cb/evil'],
        'a trailing slash' => ['https://app.example/cb/'],
        'an added query string' => ['https://app.example/cb?next=https://evil.example'],
        'a fragment' => ['https://app.example/cb#x'],
        'another case' => ['https://APP.example/cb'],
        'another scheme' => ['http://app.example/cb'],
        'a lookalike host' => ['https://app.example.evil.test/cb'],
        'a userinfo trick' => ['https://app.example@evil.test/cb'],
        'a leading space' => [' https://app.example/cb'],
        'an empty string' => [''],
    ]);

    it('accepts nothing when no URI is registered', function (): void {
        expect(mcpcClient(uris: [])->allowsRedirectUri(''))->toBeFalse();
    });
});

describe('secret verification', function (): void {
    it('delegates a confidential client to wp_check_password', function (): void {
        Functions\expect('wp_check_password')->once()->with('s3cret', 'hash')->andReturn(true);

        expect(mcpcClient()->verifySecret('s3cret'))->toBeTrue();
    });

    it('rejects a wrong secret', function (): void {
        Functions\when('wp_check_password')->justReturn(false);

        expect(mcpcClient()->verifySecret('nope'))->toBeFalse();
    });

    it('rejects an empty secret without consulting the hash', function (): void {
        Functions\expect('wp_check_password')->never();

        expect(mcpcClient()->verifySecret(''))->toBeFalse();
    });

    it('treats a client with no auth method as public and accepts any secret', function (): void {
        $client = mcpcClient('', Client::AUTH_METHOD_NONE);

        expect($client->isConfidential())->toBeFalse()
            ->and($client->verifySecret(''))->toBeTrue();
    });

    it('treats a client without a stored hash as public even if it declares a method', function (): void {
        expect(mcpcClient('')->isConfidential())->toBeFalse();
    });

    it('is confidential only when it has a method other than none and a hash', function (): void {
        expect(mcpcClient()->isConfidential())->toBeTrue()
            ->and(mcpcClient('hash', 'client_secret_basic')->isConfidential())->toBeTrue()
            ->and(mcpcClient('hash', Client::AUTH_METHOD_NONE)->isConfidential())->toBeFalse();
    });
});

describe('storage', function (): void {
    it('round-trips through its stored representation', function (): void {
        $client = new Client('id', 'h', 'Name', ['https://a.example/cb'], 'client_secret_basic', 42, true);

        expect(Client::fromArray($client->toArray()))->toEqual($client);
    });

    it('survives a corrupt record', function (): void {
        $client = Client::fromArray(['redirect_uris' => 'https://a.example/cb']);

        expect($client->id)->toBe('')
            ->and($client->redirectUris)->toBe(['https://a.example/cb'])
            ->and($client->authMethod)->toBe('client_secret_post')
            ->and($client->selfRegistered)->toBeFalse();
    });
});

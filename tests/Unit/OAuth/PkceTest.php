<?php

declare(strict_types=1);

use Pollora\McpConnector\OAuth\Pkce;

// RFC 7636 appendix B.
const MCPC_RFC_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
const MCPC_RFC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

describe('PKCE', function (): void {
    it('derives the challenge published in RFC 7636 appendix B', function (): void {
        expect(Pkce::challengeFor(MCPC_RFC_VERIFIER))->toBe(MCPC_RFC_CHALLENGE);
    });

    it('verifies the verifier that produced the challenge', function (): void {
        expect(Pkce::verify(MCPC_RFC_VERIFIER, MCPC_RFC_CHALLENGE))->toBeTrue();
    });

    it('rejects a different verifier, a verifier equal to the challenge, and empty values', function (string $verifier, string $challenge): void {
        expect(Pkce::verify($verifier, $challenge))->toBeFalse();
    })->with([
        'another verifier' => [str_repeat('x', 43), MCPC_RFC_CHALLENGE],
        'the challenge replayed as the verifier (plain method)' => [MCPC_RFC_CHALLENGE, MCPC_RFC_CHALLENGE],
        'a truncated verifier' => [substr(MCPC_RFC_VERIFIER, 0, -1), MCPC_RFC_CHALLENGE],
        'an empty verifier' => ['', MCPC_RFC_CHALLENGE],
        'an empty challenge' => [MCPC_RFC_VERIFIER, ''],
        'both empty' => ['', ''],
    ]);

    it('produces unpadded base64url', function (): void {
        $challenge = Pkce::challengeFor('any verifier');

        expect($challenge)->toMatch('/^[A-Za-z0-9_-]{43}$/');
    });

    it('accepts only S256 with a challenge of at least 43 characters', function (string $challenge, string $method, bool $expected): void {
        expect(Pkce::isSupported($challenge, $method))->toBe($expected);
    })->with([
        'S256 and a real challenge' => [MCPC_RFC_CHALLENGE, 'S256', true],
        'plain is refused' => [MCPC_RFC_CHALLENGE, 'plain', false],
        'lowercase s256 is refused' => [MCPC_RFC_CHALLENGE, 's256', false],
        'no method' => [MCPC_RFC_CHALLENGE, '', false],
        'a challenge one character short' => [str_repeat('a', 42), 'S256', false],
        'an empty challenge' => ['', 'S256', false],
        'a challenge of exactly 43 characters' => [str_repeat('a', 43), 'S256', true],
    ]);

    it('refuses a challenge that is not exactly 43 base64url characters', function (string $challenge): void {
        expect(Pkce::isSupported($challenge, 'S256'))->toBeFalse();
    })->with([
        'one character too long' => [str_repeat('a', 44)],
        'a padded challenge' => [str_repeat('a', 42) . '='],
        'a standard base64 plus' => [str_repeat('a', 42) . '+'],
        'a standard base64 slash' => [str_repeat('a', 42) . '/'],
        'a dot' => [str_repeat('a', 42) . '.'],
        'a space' => [str_repeat('a', 42) . ' '],
        'a trailing newline' => [str_repeat('a', 43) . "\n"],
        'ten thousand characters' => [str_repeat('a', 10000)],
    ]);

    it('bounds the verifier to 43-128 unreserved characters', function (string $verifier, bool $expected): void {
        expect(Pkce::verify($verifier, Pkce::challengeFor($verifier)))->toBe($expected);
    })->with([
        '42 characters' => [str_repeat('a', 42), false],
        '43 characters' => [str_repeat('a', 43), true],
        '128 characters' => [str_repeat('a', 128), true],
        '129 characters' => [str_repeat('a', 129), false],
        'every allowed punctuation mark' => [str_repeat('aA0-._~', 7), true],
        'a space' => [str_repeat('a', 42) . ' ', false],
        'a plus' => [str_repeat('a', 42) . '+', false],
        'a trailing newline' => [str_repeat('a', 43) . "\n", false],
        'a multibyte character' => [str_repeat('a', 42) . 'é', false],
    ]);
});

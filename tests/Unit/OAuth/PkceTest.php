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
});

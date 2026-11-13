<?php

declare(strict_types=1);

use Pollora\McpConnector\Server\ExternalAbility;

function mcpcExternalAbility(array $annotations): ExternalAbility
{
    return ExternalAbility::fromAbility(new WP_Ability(
        'vendor/do-thing',
        'Do thing',
        'Does a thing.',
        'tools',
        $annotations === [] ? [] : ['annotations' => $annotations],
    ));
}

describe('an external ability description', function (): void {
    it('takes the provider from the name prefix', function (): void {
        expect(mcpcExternalAbility([])->provider)->toBe('vendor');
    });

    it('states what the provider claims, and is only safe by default when it claims to read only', function (array $annotations, string $claim, bool $safe): void {
        $ability = mcpcExternalAbility($annotations);

        expect($ability->claim())->toBe($claim)
            ->and($ability->isSafeByDefault())->toBe($safe);
    })->with([
        'reads only' => [['readonly' => true], 'Reads only', true],
        'destructive' => [['readonly' => false, 'destructive' => true], 'Writes — may delete or overwrite', false],
        'writes' => [['readonly' => false, 'destructive' => false], 'Writes', false],
        'silent' => [[], 'Undeclared — the provider says nothing about what this does', false],
    ]);
});

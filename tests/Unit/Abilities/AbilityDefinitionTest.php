<?php

declare(strict_types=1);

use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\AbilityDefinition;

$schema = ['type' => 'object', 'properties' => []];
$noop = static fn (): bool => true;

describe('AbilityDefinition', function () use ($schema, $noop): void {
    it('is read-only when built with reading()', function () use ($schema, $noop): void {
        $definition = AbilityDefinition::reading('get-x', 'Get X', 'Reads X.', AbilityCategory::Site, $schema, $noop, $noop);

        expect($definition->isReadOnly())->toBeTrue()
            ->and($definition->behaviour)->toBe(Behaviour::Reads)
            ->and($definition->slug)->toBe('get-x')
            ->and($definition->category)->toBe(AbilityCategory::Site)
            ->and($definition->inputSchema)->toBe($schema);
    });

    it('creates by default when built with writing(), and is not read-only', function () use ($schema, $noop): void {
        $definition = AbilityDefinition::writing('make-x', 'Make X', 'Makes X.', AbilityCategory::Content, $schema, $noop, $noop);

        expect($definition->behaviour)->toBe(Behaviour::Creates)
            ->and($definition->isReadOnly())->toBeFalse();
    });

    it('is never read-only whatever write behaviour it declares', function (Behaviour $behaviour) use ($schema, $noop): void {
        $definition = AbilityDefinition::writing('w', 'W', 'Writes.', AbilityCategory::Media, $schema, $noop, $noop, $behaviour);

        expect($definition->behaviour)->toBe($behaviour)
            ->and($definition->isReadOnly())->toBeFalse();
    })->with([
        'updates' => [Behaviour::Updates],
        'deletes' => [Behaviour::Deletes],
        'creates' => [Behaviour::Creates],
    ]);
});

describe('AbilityCategory', function (): void {
    it('maps to a package category under the namespace', function (AbilityCategory $category): void {
        $package = $category->toPackageCategory('acme');

        expect($package->slug)->toBe('acme-' . $category->value)
            ->and($package->label)->toBe($category->label())
            ->and($package->description)->toBe($category->description());
    })->with(AbilityCategory::cases());

    it('has a distinct non-empty label and description for every case', function (): void {
        $labels = array_map(static fn (AbilityCategory $c): string => $c->label(), AbilityCategory::cases());
        $descriptions = array_map(static fn (AbilityCategory $c): string => $c->description(), AbilityCategory::cases());

        expect(array_unique($labels))->toHaveCount(count(AbilityCategory::cases()))
            ->and(array_filter($descriptions, static fn (string $d): bool => $d === ''))->toBe([]);
    });

    it('says the users category only reads', function (): void {
        expect(AbilityCategory::Users->description())->toBe('Read user accounts.');
    });
});

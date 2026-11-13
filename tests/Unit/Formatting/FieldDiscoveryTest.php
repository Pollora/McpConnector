<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Pollora\McpConnector\Formatting\FieldDiscovery;

describe('field discovery', function (): void {
    it('finds nothing when no source is registered and Meta Box is absent', function (): void {
        if (function_exists('rwmb_get_object_fields')) {
            $this->markTestSkipped('Meta Box was declared by another test in this process.');
        }

        expect(FieldDiscovery::frameworkFields('post'))->toBe([]);
    });

    it('tags each field with the source that reported it, and passes the post type along', function (): void {
        Filters\expectApplied(FieldDiscovery::SOURCES_FILTER)->once()->andReturn([
            'acme' => static fn (string $type): array => [['key' => 'k_' . $type, 'label' => 'K', 'type' => 'text']],
        ]);

        expect(FieldDiscovery::frameworkFields('book'))->toBe([
            ['key' => 'k_book', 'label' => 'K', 'type' => 'text', 'source' => 'acme'],
        ]);
    });

    it('ignores a source that is not callable', function (): void {
        Filters\expectApplied(FieldDiscovery::SOURCES_FILTER)->andReturn([
            'broken' => 'not_a_function_anywhere',
            'ok' => static fn (): array => [['key' => 'a', 'label' => 'A', 'type' => 'text']],
        ]);

        $fields = FieldDiscovery::frameworkFields('post');

        expect($fields)->toHaveCount(1)
            ->and($fields[0]['source'])->toBe('ok');
    });
});

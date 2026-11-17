<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Abilities\AbilityGroup;
use Pollora\McpConnector\Abilities\Group\ContentGroup;
use Pollora\McpConnector\Abilities\Group\MediaGroup;
use Pollora\McpConnector\Abilities\Group\NavigationGroup;
use Pollora\McpConnector\Abilities\Group\SiteGroup;
use Pollora\McpConnector\Abilities\Group\TaxonomyGroup;
use Pollora\McpConnector\Abilities\Group\UserGroup;
use Pollora\McpConnector\Settings;
use Pollora\McpConnector\Tests\Unit\Abilities\AbilityHarness as H;

beforeEach(function (): void {
    WP_Query::reset();
    Settings::forget();
    H::actAs([]);
    H::addressable(['post', 'page', 'book', 'attachment']);
    H::taxonomies(['category', 'post_tag', 'genre']);
    H::stubPostFormatting();
    H::posts();
    Functions\when('get_taxonomy')->alias(static fn (string $name): WP_Taxonomy|false => in_array($name, ['category', 'post_tag', 'genre'], true)
        ? new WP_Taxonomy($name, $name)
        : false);
});

afterEach(function (): void {
    Settings::forget();
});

/**
 * What a group tells a client about its abilities, closures aside.
 */
function groupcatalogDescribe(AbilityGroup $group): string
{
    $catalog = [
        'key' => $group->key(),
        'label' => $group->label(),
        'description' => $group->description(),
        'abilities' => array_map(static fn ($definition): array => [
            'slug' => $definition->slug,
            'label' => $definition->label,
            'description' => $definition->description,
            'category' => $definition->category->name,
            'behaviour' => $definition->behaviour->name,
            'readOnly' => $definition->isReadOnly(),
            'inputSchema' => $definition->inputSchema,
        ], $group->definitions()),
    ];

    return (string) json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

describe('what each group tells a client', function (): void {
    it('pins the name, the wording, the schema and the behaviour of every ability', function (string $name, AbilityGroup $group): void {
        mcpcAssertGolden('catalog-' . $name, groupcatalogDescribe($group));
    })->with([
        'content' => ['content', fn () => new ContentGroup()],
        'media' => ['media', fn () => new MediaGroup()],
        'navigation' => ['navigation', fn () => new NavigationGroup()],
        'site' => ['site', fn () => new SiteGroup()],
        'taxonomy' => ['taxonomy', fn () => new TaxonomyGroup()],
        'user' => ['user', fn () => new UserGroup()],
    ]);
});

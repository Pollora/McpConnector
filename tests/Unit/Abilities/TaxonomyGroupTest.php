<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\Group\TaxonomyGroup;
use Pollora\McpConnector\Tests\Unit\Abilities\AbilityHarness as H;

beforeEach(function (): void {
    H::actAs([]);
    H::taxonomies(['category', 'post_tag', 'genre']);
});

function taxonomyAbility(string $slug): \Pollora\McpConnector\Abilities\AbilityDefinition
{
    return H::definition(new TaxonomyGroup(), $slug);
}

/**
 * Make get_taxonomy() resolve these taxonomies, with a capability per action.
 *
 * @param list<string> $names
 */
function knownTaxonomies(array $names): void
{
    Functions\when('get_taxonomy')->alias(static function (string $name) use ($names): WP_Taxonomy|false {
        if (! in_array($name, $names, true)) {
            return false;
        }

        $taxonomy = new WP_Taxonomy($name, ucfirst($name));
        $taxonomy->cap = (object) [
            'manage_terms' => 'manage_' . $name,
            'delete_terms' => 'delete_' . $name,
        ];

        return $taxonomy;
    });
}

describe('the group', function (): void {
    it('publishes four abilities under a stable key', function (): void {
        $group = new TaxonomyGroup();

        expect($group->key())->toBe('taxonomy')
            ->and($group->label())->not->toBe('')
            ->and($group->description())->not->toBe('')
            ->and(array_map(static fn ($d): string => $d->slug, $group->definitions()))
            ->toBe(['get-taxonomies', 'get-terms', 'create-term', 'delete-term']);
    });

    it('files every ability under the taxonomy category', function (): void {
        foreach ((new TaxonomyGroup())->definitions() as $definition) {
            expect($definition->category)->toBe(AbilityCategory::Taxonomy);
        }
    });

    it('withholds the writes in read-only mode and marks the delete as destructive', function (): void {
        expect(taxonomyAbility('get-taxonomies')->behaviour)->toBe(Behaviour::Reads)
            ->and(taxonomyAbility('get-terms')->isReadOnly())->toBeTrue()
            ->and(taxonomyAbility('create-term')->behaviour)->toBe(Behaviour::Creates)
            ->and(taxonomyAbility('create-term')->isReadOnly())->toBeFalse()
            ->and(taxonomyAbility('delete-term')->behaviour)->toBe(Behaviour::Deletes)
            ->and(taxonomyAbility('delete-term')->behaviour->annotations()->destructive)->toBeTrue();
    });

    it('requires a name to create a term and an id to delete one', function (): void {
        expect(taxonomyAbility('create-term')->inputSchema['required'])->toBe(['name'])
            ->and(taxonomyAbility('delete-term')->inputSchema['required'])->toBe(['id'])
            ->and(taxonomyAbility('get-terms')->inputSchema['properties']['number']['maximum'])->toBe(200);
    });
});

describe('get-taxonomies', function (): void {
    it('lists the public taxonomies with their post types', function (): void {
        $category = new WP_Taxonomy('category', 'Categories');
        $category->hierarchical = true;
        $category->object_type = ['post', 'book'];

        Functions\expect('get_taxonomies')->once()->with(['public' => true], 'objects')->andReturn(['category' => $category]);

        expect(H::run(taxonomyAbility('get-taxonomies')))->toBe(['taxonomies' => [
            ['name' => 'category', 'label' => 'Categories', 'hierarchical' => true, 'post_types' => ['post', 'book']],
        ]]);
    });

    it('lists every taxonomy when public_only is false', function (): void {
        Functions\expect('get_taxonomies')->once()->with([], 'objects')->andReturn([]);

        expect(H::run(taxonomyAbility('get-taxonomies'), ['public_only' => false]))->toBe(['taxonomies' => []]);
    });

    it('requires edit_posts', function (): void {
        expect(H::permitted(taxonomyAbility('get-taxonomies')))->toBeFalse();

        H::actAs(['edit_posts']);
        expect(H::permitted(taxonomyAbility('get-taxonomies')))->toBeTrue();
    });
});

describe('get-terms', function (): void {
    it('lists the terms of the category taxonomy by default', function (): void {
        $captured = [];
        Functions\when('get_terms')->alias(function (array $args) use (&$captured): array {
            $captured = $args;

            return [new WP_Term(['term_id' => 3, 'name' => 'News', 'slug' => 'news', 'description' => 'd', 'parent' => 1, 'count' => 8])];
        });

        $result = H::run(taxonomyAbility('get-terms'));

        expect($captured)->toBe(['taxonomy' => 'category', 'hide_empty' => false, 'number' => 50])
            ->and($result)->toBe([
                'taxonomy' => 'category',
                'terms' => [['id' => 3, 'name' => 'News', 'slug' => 'news', 'description' => 'd', 'parent' => 1, 'count' => 8]],
            ]);
    });

    it('passes the search, the empty filter and a clamped number through', function (): void {
        $captured = [];
        Functions\when('get_terms')->alias(function (array $args) use (&$captured): array {
            $captured = $args;

            return [];
        });

        H::run(taxonomyAbility('get-terms'), ['taxonomy' => 'genre', 'search' => 'sci', 'hide_empty' => true, 'number' => 9999]);

        expect($captured)->toBe(['taxonomy' => 'genre', 'hide_empty' => true, 'number' => 200, 'search' => 'sci']);
    });

    it('raises a number below one to one', function (): void {
        $captured = [];
        Functions\when('get_terms')->alias(function (array $args) use (&$captured): array {
            $captured = $args;

            return [];
        });

        H::run(taxonomyAbility('get-terms'), ['number' => -5]);

        expect($captured['number'])->toBe(1);
    });

    it('refuses an unknown taxonomy without querying', function (): void {
        Functions\expect('get_terms')->never();

        $result = H::run(taxonomyAbility('get-terms'), ['taxonomy' => 'nope']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_invalid_input')
            ->and($result->get_error_message())->toContain('Unknown taxonomy "nope"');
    });

    it('relays an error from get_terms', function (): void {
        Functions\when('get_terms')->justReturn(new WP_Error('invalid_taxonomy', 'Invalid taxonomy.'));

        $result = H::run(taxonomyAbility('get-terms'));

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('invalid_taxonomy')
            ->and($result->get_error_message())->toBe('Could not list the terms: Invalid taxonomy.');
    });

    it('requires edit_posts', function (): void {
        expect(H::permitted(taxonomyAbility('get-terms')))->toBeFalse();

        H::actAs(['edit_posts']);
        expect(H::permitted(taxonomyAbility('get-terms')))->toBeTrue();
    });
});

describe('create-term', function (): void {
    it('creates a term and returns it', function (): void {
        Functions\expect('wp_insert_term')->once()->with('Sci-fi', 'genre', [])->andReturn(['term_id' => 12, 'term_taxonomy_id' => 13]);
        Functions\when('get_term')->justReturn(new WP_Term(['term_id' => 12, 'name' => 'Sci-fi', 'slug' => 'sci-fi', 'taxonomy' => 'genre']));

        expect(H::run(taxonomyAbility('create-term'), ['taxonomy' => 'genre', 'name' => 'Sci-fi']))
            ->toBe(['id' => 12, 'name' => 'Sci-fi', 'slug' => 'sci-fi', 'taxonomy' => 'genre', 'parent' => 0]);
    });

    it('sanitises the slug and passes the description and a positive parent', function (): void {
        $captured = [];
        Functions\when('wp_insert_term')->alias(function (string $name, string $taxonomy, array $args) use (&$captured): array {
            $captured = [$name, $taxonomy, $args];

            return ['term_id' => 12];
        });
        Functions\when('get_term')->justReturn(new WP_Term(['term_id' => 12]));

        H::run(taxonomyAbility('create-term'), ['name' => 'X', 'slug' => 'Sci Fi / 2024!', 'description' => 'About', 'parent' => 4]);

        expect($captured)->toBe(['X', 'category', ['slug' => 'sci-fi-2024', 'description' => 'About', 'parent' => 4]]);
    });

    it('leaves the parent out when it is zero or not a number', function (string|int $parent): void {
        $captured = [];
        Functions\when('wp_insert_term')->alias(function (string $name, string $taxonomy, array $args) use (&$captured): array {
            $captured = $args;

            return ['term_id' => 12];
        });
        Functions\when('get_term')->justReturn(new WP_Term(['term_id' => 12]));

        H::run(taxonomyAbility('create-term'), ['name' => 'X', 'parent' => $parent]);

        expect($captured)->toBe([]);
    })->with([0, 'abc', -3]);

    it('refuses an unknown taxonomy before creating anything', function (): void {
        Functions\expect('wp_insert_term')->never();

        $result = H::run(taxonomyAbility('create-term'), ['taxonomy' => 'nope', 'name' => 'X']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toContain('Unknown taxonomy "nope"');
    });

    it('relays the duplicate-term error with its code', function (): void {
        Functions\when('wp_insert_term')->justReturn(new WP_Error('term_exists', 'A term with the name provided already exists.', 5));

        $result = H::run(taxonomyAbility('create-term'), ['name' => 'News']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('term_exists')
            ->and($result->get_error_message())->toBe('Could not create the term: A term with the name provided already exists.')
            ->and($result->get_error_data())->toBe(5);
    });

    it('reports a 404 when the created term cannot be read back', function (): void {
        Functions\when('wp_insert_term')->justReturn(['term_id' => 12]);
        Functions\when('get_term')->justReturn(null);

        $result = H::run(taxonomyAbility('create-term'), ['name' => 'X']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_not_found');
    });

    describe('permission', function (): void {
        it('asks the taxonomy for its own manage capability', function (): void {
            knownTaxonomies(['category', 'genre']);
            H::actAs(['manage_category']);

            expect(H::permitted(taxonomyAbility('create-term')))->toBeTrue()
                ->and(H::permitted(taxonomyAbility('create-term'), ['taxonomy' => 'genre']))->toBeFalse();
        });

        it('denies a taxonomy that does not exist', function (): void {
            knownTaxonomies(['category']);
            H::actAs(['manage_category', 'manage_ghost']);

            expect(H::permitted(taxonomyAbility('create-term'), ['taxonomy' => 'ghost']))->toBeFalse();
        });
    });
});

describe('delete-term', function (): void {
    it('deletes an existing term', function (): void {
        Functions\when('get_term')->justReturn(new WP_Term(['term_id' => 8]));
        Functions\expect('wp_delete_term')->once()->with(8, 'genre')->andReturn(true);

        expect(H::run(taxonomyAbility('delete-term'), ['id' => 8, 'taxonomy' => 'genre']))
            ->toBe(['deleted' => true, 'id' => 8, 'taxonomy' => 'genre']);
    });

    it('defaults to the category taxonomy', function (): void {
        Functions\expect('get_term')->once()->with(8, 'category')->andReturn(new WP_Term(['term_id' => 8]));
        Functions\when('wp_delete_term')->justReturn(true);

        expect(H::run(taxonomyAbility('delete-term'), ['id' => 8])['taxonomy'])->toBe('category');
    });

    it('answers with a 404 error for a term that does not exist', function (): void {
        Functions\when('get_term')->justReturn(new WP_Error('invalid_term', 'Empty Term.'));
        Functions\expect('wp_delete_term')->never();

        $result = H::run(taxonomyAbility('delete-term'), ['id' => 8]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_not_found')
            ->and($result->get_error_data())->toBe(['status' => 404]);
    });

    it('relays an error from wp_delete_term', function (): void {
        Functions\when('get_term')->justReturn(new WP_Term(['term_id' => 8]));
        Functions\when('wp_delete_term')->justReturn(new WP_Error('invalid_taxonomy', 'Invalid taxonomy.'));

        $result = H::run(taxonomyAbility('delete-term'), ['id' => 8]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('Could not delete the term: Invalid taxonomy.');
    });

    it('reports a refusal when WordPress returns false', function (): void {
        Functions\when('get_term')->justReturn(new WP_Term(['term_id' => 8]));
        Functions\when('wp_delete_term')->justReturn(false);

        $result = H::run(taxonomyAbility('delete-term'), ['id' => 8]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('WordPress refused to delete term 8.');
    });

    it('asks the taxonomy for its own delete capability', function (): void {
        knownTaxonomies(['category', 'genre']);
        H::actAs(['delete_genre']);

        expect(H::permitted(taxonomyAbility('delete-term'), ['id' => 1, 'taxonomy' => 'genre']))->toBeTrue()
            ->and(H::permitted(taxonomyAbility('delete-term'), ['id' => 1]))->toBeFalse()
            ->and(H::permitted(taxonomyAbility('delete-term'), ['id' => 1, 'taxonomy' => 'ghost']))->toBeFalse();
    });
});

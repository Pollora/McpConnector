<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\Group\ContentGroup;
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
    Functions\when('term_exists')->justReturn(null);
});

afterEach(function (): void {
    Settings::forget();
});

/**
 * @return list<string> Slugs published by the group.
 */
function contentSlugs(): array
{
    return array_map(static fn ($definition): string => $definition->slug, (new ContentGroup())->definitions());
}

function contentAbility(string $slug): \Pollora\McpConnector\Abilities\AbilityDefinition
{
    return H::definition(new ContentGroup(), $slug);
}

/**
 * Stage a successful insert and capture what was handed to WordPress.
 *
 * @param array<string, mixed> $captured
 */
function stageInsert(array &$captured, int $id = 31, string $type = 'post'): void
{
    Functions\when('wp_insert_post')->alias(function (array $data, bool $wpError) use (&$captured, $id): int {
        $captured = $data + ['__wp_error' => $wpError];

        return $id;
    });
    H::posts(H::post($id, ['post_type' => $type, 'post_status' => 'draft']));
}

describe('the group', function (): void {
    it('publishes the seven content abilities under a stable key', function (): void {
        $group = new ContentGroup();

        expect($group->key())->toBe('content')
            ->and($group->label())->not->toBe('')
            ->and($group->description())->not->toBe('')
            ->and(contentSlugs())->toBe([
                'get-posts', 'get-post', 'get-pages', 'create-post', 'update-post', 'delete-post', 'set-post-terms',
            ]);
    });

    it('files every ability under the content category', function (): void {
        foreach ((new ContentGroup())->definitions() as $definition) {
            expect($definition->category)->toBe(AbilityCategory::Content)
                ->and($definition->label)->not->toBe('')
                ->and($definition->description)->not->toBe('');
        }
    });

    it('declares what each ability does to the site', function (string $slug, Behaviour $behaviour, bool $readOnly): void {
        $definition = contentAbility($slug);

        expect($definition->behaviour)->toBe($behaviour)
            ->and($definition->isReadOnly())->toBe($readOnly);
    })->with([
        'list posts' => ['get-posts', Behaviour::Reads, true],
        'read a post' => ['get-post', Behaviour::Reads, true],
        'list pages' => ['get-pages', Behaviour::Reads, true],
        'create' => ['create-post', Behaviour::Creates, false],
        'update' => ['update-post', Behaviour::Updates, false],
        'delete' => ['delete-post', Behaviour::Deletes, false],
        'terms' => ['set-post-terms', Behaviour::Updates, false],
    ]);

    it('keeps the read-only abilities apart from the ones read-only mode must withhold', function (): void {
        $readOnly = array_filter(
            (new ContentGroup())->definitions(),
            static fn ($definition): bool => $definition->isReadOnly(),
        );

        expect(array_map(static fn ($d): string => $d->slug, array_values($readOnly)))
            ->toBe(['get-posts', 'get-post', 'get-pages']);
    });

    it('marks the destructive abilities as such for the MCP client', function (): void {
        expect(contentAbility('delete-post')->behaviour->annotations()->destructive)->toBeTrue()
            ->and(contentAbility('get-post')->behaviour->annotations()->readonly)->toBeTrue()
            ->and(contentAbility('create-post')->behaviour->annotations()->idempotent)->toBeFalse();
    });

    it('requires the identifiers and titles a write cannot do without', function (string $slug, array $required): void {
        expect(contentAbility($slug)->inputSchema['required'] ?? [])->toBe($required);
    })->with([
        'get-post' => ['get-post', ['id']],
        'create-post' => ['create-post', ['title']],
        'update-post' => ['update-post', ['id']],
        'delete-post' => ['delete-post', ['id']],
        'set-post-terms' => ['set-post-terms', ['post_id', 'terms']],
    ]);

    it('bounds the listing page size in the schema', function (): void {
        $properties = contentAbility('get-posts')->inputSchema['properties'];

        expect($properties['posts_per_page']['maximum'])->toBe(100)
            ->and($properties['posts_per_page']['minimum'])->toBe(1)
            ->and($properties['paged']['minimum'])->toBe(1)
            ->and($properties['order']['enum'])->toBe(['ASC', 'DESC']);
    });

    it('only offers statuses a post can be created in', function (): void {
        $properties = contentAbility('create-post')->inputSchema['properties'];

        expect($properties['post_status']['enum'])->toBe(['draft', 'publish', 'pending', 'private', 'future'])
            ->and($properties['post_status']['default'])->toBe('draft');
    });
});

describe('get-posts', function (): void {
    it('lists published posts of type post, ten at a time, newest first', function (): void {
        H::run(contentAbility('get-posts'));

        expect(WP_Query::$lastArgs)->toMatchArray([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'paged' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
        ])->not->toHaveKey('s')
            ->not->toHaveKey('tax_query')
            ->not->toHaveKey('author');
    });

    it('restricts the query to what the current user may read, whatever status is asked for', function (): void {
        H::run(contentAbility('get-posts'), ['post_status' => 'any']);

        expect(WP_Query::$lastArgs['perm'])->toBe('readable')
            ->and(WP_Query::$lastArgs['post_status'])->toBe('any');
    });

    it('clamps the page size and the page number', function (): void {
        H::run(contentAbility('get-posts'), ['posts_per_page' => 100000, 'paged' => -4]);

        expect(WP_Query::$lastArgs['posts_per_page'])->toBe(100)
            ->and(WP_Query::$lastArgs['paged'])->toBe(1);
    });

    it('raises a page size of zero to one', function (): void {
        H::run(contentAbility('get-posts'), ['posts_per_page' => 0]);

        expect(WP_Query::$lastArgs['posts_per_page'])->toBe(1);
    });

    it('accepts any casing for the sort direction and falls back to DESC on nonsense', function (string $given, string $sent): void {
        H::run(contentAbility('get-posts'), ['order' => $given]);

        expect(WP_Query::$lastArgs['order'])->toBe($sent);
    })->with([
        ['asc', 'ASC'],
        ['ASC', 'ASC'],
        ['desc', 'DESC'],
        ['sideways; DROP TABLE', 'DESC'],
    ]);

    it('passes a search term and an author through, and ignores an author of zero', function (): void {
        H::run(contentAbility('get-posts'), ['search' => 'hello', 'author' => 7]);
        expect(WP_Query::$lastArgs)->toMatchArray(['s' => 'hello', 'author' => 7]);

        H::run(contentAbility('get-posts'), ['search' => '', 'author' => 0]);
        expect(WP_Query::$lastArgs)->not->toHaveKey('s')->not->toHaveKey('author');
    });

    it('reports the page it served and the totals WP_Query computed', function (): void {
        WP_Query::$stagedPosts = [H::post(1), H::post(2)];
        WP_Query::$stagedTotal = 42;
        WP_Query::$stagedPages = 5;

        $result = H::run(contentAbility('get-posts'), ['paged' => 3, 'posts_per_page' => 10]);

        expect($result)->toMatchArray(['total' => 42, 'total_pages' => 5, 'page' => 3])
            ->and($result['posts'])->toHaveCount(2)
            ->and($result['posts'][0])->toMatchArray(['id' => 1, 'title' => 'Post 1', 'slug' => 'post-1']);
    });

    it('returns summaries without the post body', function (): void {
        WP_Query::$stagedPosts = [H::post(1, ['post_content' => 'SECRET BODY', 'post_password' => 'hunter2'])];

        $result = H::run(contentAbility('get-posts'));

        expect($result['posts'][0])->not->toHaveKey('content')
            ->and(json_encode($result))->not->toContain('SECRET BODY')
            ->and(json_encode($result))->not->toContain('hunter2');
    });

    it('refuses a post type that is not addressable, and says what is', function (string $type): void {
        $result = H::run(contentAbility('get-posts'), ['post_type' => $type]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_invalid_input')
            ->and($result->get_error_data())->toBe(['status' => 400])
            ->and($result->get_error_message())->toContain($type)->toContain('post, page, book, attachment')
            ->and(WP_Query::$lastArgs)->toBe([]);
    })->with([
        'a never-offered core type' => ['revision'],
        'a type that does not exist' => ['od_url_metrics'],
        'an injection attempt' => ["post' OR 1=1"],
    ]);

    it('builds a tax query from a single taxonomy without a relation', function (): void {
        H::run(contentAbility('get-posts'), ['terms' => ['genre' => ['sf', ' ', 'noir']]]);

        expect(WP_Query::$lastArgs['tax_query'])->toBe([
            ['taxonomy' => 'genre', 'field' => 'slug', 'terms' => ['sf', 'noir']],
        ]);
    });

    it('combines several taxonomies with OR, or AND when every term is required', function (): void {
        $terms = ['genre' => ['sf'], 'category' => ['news']];

        H::run(contentAbility('get-posts'), ['terms' => $terms]);
        expect(WP_Query::$lastArgs['tax_query']['relation'])->toBe('OR');

        H::run(contentAbility('get-posts'), ['terms' => $terms, 'terms_match_all' => true]);
        expect(WP_Query::$lastArgs['tax_query']['relation'])->toBe('AND');
    });

    it('rejects an unknown taxonomy instead of silently returning everything', function (): void {
        $result = H::run(contentAbility('get-posts'), ['terms' => ['nope' => ['x'], 'genre' => ['sf']]]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toContain('Unknown taxonomy: nope')
            ->and(WP_Query::$lastArgs)->toBe([]);
    });

    it('drops a taxonomy whose slugs are all blank', function (): void {
        H::run(contentAbility('get-posts'), ['terms' => ['genre' => ['', '  ']]]);

        expect(WP_Query::$lastArgs)->not->toHaveKey('tax_query');
    });

    describe('permission', function (): void {
        it('requires the edit capability of the post type', function (): void {
            expect(H::permitted(contentAbility('get-posts')))->toBeFalse();

            H::actAs(['edit_posts']);
            expect(H::permitted(contentAbility('get-posts')))->toBeTrue();
        });

        it('asks the custom post type for its own capability', function (): void {
            H::actAs(['edit_posts']);
            expect(H::permitted(contentAbility('get-posts'), ['post_type' => 'book']))->toBeFalse();

            H::actAs(['edit_books']);
            expect(H::permitted(contentAbility('get-posts'), ['post_type' => 'book']))->toBeTrue();
        });

        it('denies a type outside the addressable set even to an administrator', function (): void {
            H::actAs(['edit_posts']);

            expect(H::permitted(contentAbility('get-posts'), ['post_type' => 'revision']))->toBeFalse()
                ->and(H::permitted(contentAbility('get-posts'), ['post_type' => 'ghost']))->toBeFalse();
        });
    });
});

describe('get-post', function (): void {
    it('returns the full record, body included', function (): void {
        H::posts(H::post(5, ['post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->', 'post_status' => 'draft']));

        $result = H::run(contentAbility('get-post'), ['id' => 5]);

        expect($result)->toMatchArray([
            'id' => 5,
            'status' => 'draft',
            'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
            'featured_image_id' => null,
            'page_template' => null,
        ])->toHaveKeys(['terms', 'meta', 'url', 'edit_url']);
    });

    it('never echoes the password protecting a post', function (): void {
        H::posts(H::post(5, ['post_password' => 'hunter2']));

        $result = H::run(contentAbility('get-post'), ['id' => 5]);

        expect($result)->not->toHaveKey('post_password')
            ->and(json_encode($result))->not->toContain('hunter2');
    });

    it('groups the terms by taxonomy as slugs', function (): void {
        H::posts(H::post(5));
        Functions\when('get_object_taxonomies')->justReturn(['category', 'genre', 'post_tag']);
        Functions\when('get_the_terms')->alias(static fn ($post, string $taxonomy): array|false => match ($taxonomy) {
            'category' => [new WP_Term(['slug' => 'news']), new WP_Term(['slug' => 'misc'])],
            'genre' => [],
            default => false,
        });

        expect(H::run(contentAbility('get-post'), ['id' => 5])['terms'])->toBe(['category' => ['news', 'misc']]);
    });

    it('reads only the meta the post type declares', function (): void {
        H::posts(H::post(5));
        Functions\when('get_registered_meta_keys')->justReturn([
            'subtitle' => ['show_in_rest' => true, 'single' => true],
            'hidden' => ['show_in_rest' => false, 'single' => true],
        ]);
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key): string => 'value of ' . $key);

        expect(H::run(contentAbility('get-post'), ['id' => 5])['meta'])->toBe(['subtitle' => 'value of subtitle']);
    });

    it('answers with a 404 error for a post that does not exist', function (): void {
        $result = H::run(contentAbility('get-post'), ['id' => 404]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_not_found')
            ->and($result->get_error_data())->toBe(['status' => 404]);
    });

    it('treats a non-numeric id as post 0, which does not exist', function (): void {
        expect(H::run(contentAbility('get-post'), ['id' => 'abc']))->toBeInstanceOf(WP_Error::class);
    });

    describe('permission', function (): void {
        it('is decided per post through read_post, so a private post stays private', function (): void {
            H::posts(H::post(1, ['post_status' => 'publish']), H::post(2, ['post_status' => 'private']));
            H::actAs(['read_post:1']);

            expect(H::permitted(contentAbility('get-post'), ['id' => 1]))->toBeTrue()
                ->and(H::permitted(contentAbility('get-post'), ['id' => 2]))->toBeFalse();
        });

        it('is not satisfied by the blanket capability of the post type', function (): void {
            H::posts(H::post(2, ['post_status' => 'private']));
            H::actAs(['edit_posts', 'read']);

            expect(H::permitted(contentAbility('get-post'), ['id' => 2]))->toBeFalse();
        });

        it('denies a post that does not exist', function (): void {
            H::actAs(['read_post']);

            expect(H::permitted(contentAbility('get-post'), ['id' => 99]))->toBeFalse();
        });

        it('denies a post of a type outside the addressable set', function (): void {
            H::posts(H::post(3, ['post_type' => 'revision']));
            H::actAs(['read_post:3']);

            expect(H::permitted(contentAbility('get-post'), ['id' => 3]))->toBeFalse();
        });
    });
});

describe('get-pages', function (): void {
    it('lists published pages in menu order', function (): void {
        H::run(contentAbility('get-pages'));

        expect(WP_Query::$lastArgs)->toBe([
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'perm' => 'readable',
        ]);
    });

    it('treats a parent of zero as a request for top-level pages', function (): void {
        H::run(contentAbility('get-pages'), ['parent' => 0]);

        expect(WP_Query::$lastArgs)->toHaveKey('post_parent', 0);
    });

    it('filters on a parent, and clamps the page size', function (): void {
        H::run(contentAbility('get-pages'), ['parent' => 12, 'posts_per_page' => 5000]);

        expect(WP_Query::$lastArgs)->toMatchArray(['post_parent' => 12, 'posts_per_page' => 100]);
    });

    it('returns the page summaries and the total', function (): void {
        WP_Query::$stagedPosts = [H::post(8, ['post_type' => 'page'])];
        WP_Query::$stagedTotal = 1;

        $result = H::run(contentAbility('get-pages'));

        expect($result['total'])->toBe(1)
            ->and($result['pages'][0])->toMatchArray(['id' => 8, 'type' => 'page']);
    });

    it('requires the page edit capability', function (): void {
        expect(H::permitted(contentAbility('get-pages')))->toBeFalse();

        H::actAs(['edit_posts']);
        expect(H::permitted(contentAbility('get-pages')))->toBeTrue();
    });

    it('is unavailable when pages are not addressable', function (): void {
        H::addressable(['post']);
        H::actAs(['edit_posts']);

        expect(H::permitted(contentAbility('get-pages')))->toBeFalse();
    });
});

describe('create-post', function (): void {
    it('creates a draft post by default', function (): void {
        $captured = [];
        stageInsert($captured);

        $result = H::run(contentAbility('create-post'), ['title' => 'Hello']);

        expect($captured)->toBe([
            'post_title' => 'Hello',
            'post_content' => '',
            'post_excerpt' => '',
            'post_type' => 'post',
            'post_status' => 'draft',
            '__wp_error' => true,
        ])->and($result)->toMatchArray(['id' => 31, 'status' => 'draft']);
    });

    it('hands block markup to WordPress untouched, leaving KSES to the capability system', function (): void {
        $captured = [];
        stageInsert($captured);
        $markup = '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">Hi <b>there</b></p><!-- /wp:paragraph -->';

        H::run(contentAbility('create-post'), ['title' => 'T', 'content' => $markup]);

        expect($captured['post_content'])->toBe($markup);
    });

    it('sanitises the slug', function (): void {
        $captured = [];
        stageInsert($captured);

        H::run(contentAbility('create-post'), ['title' => 'T', 'slug' => 'My Slug/../Évil!']);

        expect($captured['post_name'])->toBe('my-slug-vil');
    });

    it('passes the date, parent and menu order only when given', function (): void {
        H::actAs(['publish_posts']);
        $captured = [];
        stageInsert($captured);

        H::run(contentAbility('create-post'), ['title' => 'T']);
        expect($captured)->not->toHaveKeys(['post_date', 'post_parent', 'menu_order', 'post_name']);

        H::run(contentAbility('create-post'), [
            'title' => 'T', 'post_status' => 'future', 'date' => '2030-01-02 03:04:05', 'parent' => 9, 'menu_order' => 0,
        ]);
        expect($captured)->toMatchArray([
            'post_status' => 'future', 'post_date' => '2030-01-02 03:04:05', 'post_parent' => 9, 'menu_order' => 0,
        ]);
    });

    it('refuses a post type outside the addressable set before touching WordPress', function (): void {
        Functions\expect('wp_insert_post')->never();

        $result = H::run(contentAbility('create-post'), ['title' => 'T', 'post_type' => 'revision']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_invalid_input');
    });

    it('relays the error WordPress raised, keeping its code', function (): void {
        Functions\when('wp_insert_post')->justReturn(new WP_Error('empty_content', 'Content, title, and excerpt are empty.'));

        $result = H::run(contentAbility('create-post'), ['title' => '']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('empty_content')
            ->and($result->get_error_message())->toBe('Could not create the post: Content, title, and excerpt are empty.')
            ->and($result->get_error_data())->toBe(['status' => 500]);
    });

    it('sets and clears the featured image', function (): void {
        $captured = [];
        stageInsert($captured);

        Functions\expect('set_post_thumbnail')->once()->with(31, 77);
        H::run(contentAbility('create-post'), ['title' => 'T', 'featured_image' => 77]);

        Functions\expect('delete_post_thumbnail')->once()->with(31);
        H::run(contentAbility('create-post'), ['title' => 'T', 'featured_image' => 0]);
    });

    it('leaves the featured image alone when it is not mentioned', function (): void {
        $captured = [];
        stageInsert($captured);
        Functions\expect('set_post_thumbnail')->never();
        Functions\expect('delete_post_thumbnail')->never();

        H::run(contentAbility('create-post'), ['title' => 'T']);
    });

    it('sanitises the page template before storing it', function (): void {
        $captured = [];
        stageInsert($captured);
        Functions\expect('update_post_meta')->once()->with(31, '_wp_page_template', 'template-wide.php');

        H::run(contentAbility('create-post'), ['title' => 'T', 'page_template' => '  <b>template-wide.php</b> ']);
    });

    it('assigns categories by slug and tags by name', function (): void {
        H::actAs(['edit_posts', 'manage_categories']);
        $captured = [];
        stageInsert($captured);
        Functions\when('get_term_by')->alias(static fn (string $field, string $slug, string $taxonomy): WP_Term|false => $slug === 'news'
            ? new WP_Term(['term_id' => 4, 'slug' => 'news'])
            : false);
        Functions\expect('wp_set_post_categories')->once()->with(31, [4]);
        Functions\expect('wp_set_post_tags')->once()->with(31, ['Big News', 'Other']);

        H::run(contentAbility('create-post'), [
            'title' => 'T', 'categories' => ['news'], 'tags' => ['Big News', '', 'Other'],
        ]);
    });

    it('rejects meta the post type does not declare, naming what it does declare', function (): void {
        $captured = [];
        stageInsert($captured);
        Functions\when('get_registered_meta_keys')->justReturn(['subtitle' => ['show_in_rest' => true]]);
        Functions\expect('update_post_meta')->never();
        Functions\expect('wp_insert_post')->never();

        $result = H::run(contentAbility('create-post'), ['title' => 'T', 'meta' => ['_edit_lock' => 'x']]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toContain('_edit_lock')->toContain('Declared fields are: subtitle');
    });

    it('writes no meta at all, and takes the new post back, when one field is forbidden', function (): void {
        $captured = [];
        stageInsert($captured);
        Functions\when('get_registered_meta_keys')->justReturn([
            'subtitle' => ['show_in_rest' => true],
            'internal' => ['show_in_rest' => true],
        ]);
        H::actAs(['edit_post_meta:31']);
        Functions\when('current_user_can')->alias(static fn (string $cap, mixed ...$args): bool => $cap === 'edit_post_meta' && ($args[1] ?? '') === 'subtitle');
        Functions\expect('update_post_meta')->never();
        Functions\expect('wp_delete_post')->once()->with(31, true);

        $result = H::run(contentAbility('create-post'), ['title' => 'T', 'meta' => ['subtitle' => 'Hi', 'internal' => 'no']]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_forbidden')
            ->and($result->get_error_data())->toBe(['status' => 403])
            ->and($result->get_error_message())->toContain('internal');
    });

    describe('permission', function (): void {
        it('requires the create capability of the post type', function (): void {
            expect(H::permitted(contentAbility('create-post'), ['title' => 'T']))->toBeFalse();

            H::actAs(['edit_posts']);
            expect(H::permitted(contentAbility('create-post'), ['title' => 'T']))->toBeTrue()
                ->and(H::permitted(contentAbility('create-post'), ['title' => 'T', 'post_type' => 'book']))->toBeFalse();

            H::actAs(['create_books']);
            expect(H::permitted(contentAbility('create-post'), ['title' => 'T', 'post_type' => 'book']))->toBeTrue();
        });

        it('denies a post type outside the addressable set', function (): void {
            H::actAs(['edit_posts']);

            expect(H::permitted(contentAbility('create-post'), ['title' => 'T', 'post_type' => 'revision']))->toBeFalse();
        });
    });
});

describe('update-post', function (): void {
    it('changes only the fields the caller mentioned', function (): void {
        H::posts(H::post(6, ['post_content' => 'keep me']));
        $captured = [];
        Functions\when('wp_update_post')->alias(function (array $data) use (&$captured): int {
            $captured = $data;

            return 6;
        });

        H::run(contentAbility('update-post'), ['id' => 6, 'title' => 'New']);

        expect($captured)->toBe(['ID' => 6, 'post_title' => 'New']);
    });

    it('can blank a field on purpose with an empty string, but not by omission', function (): void {
        H::posts(H::post(6));
        $captured = [];
        Functions\when('wp_update_post')->alias(function (array $data) use (&$captured): int {
            $captured = $data;

            return 6;
        });

        H::run(contentAbility('update-post'), ['id' => 6, 'excerpt' => '']);

        expect($captured)->toBe(['ID' => 6, 'post_excerpt' => '']);
    });

    it('maps every writable column and sanitises the slug', function (): void {
        H::posts(H::post(6));
        $captured = [];
        Functions\when('wp_update_post')->alias(function (array $data) use (&$captured): int {
            $captured = $data;

            return 6;
        });

        H::run(contentAbility('update-post'), [
            'id' => 6, 'title' => 'T', 'content' => '<p>c</p>', 'excerpt' => 'e', 'post_status' => 'pending',
            'slug' => 'New Slug', 'parent' => 2, 'menu_order' => 4,
        ]);

        expect($captured)->toBe([
            'ID' => 6, 'post_title' => 'T', 'post_content' => '<p>c</p>', 'post_excerpt' => 'e',
            'post_status' => 'pending', 'post_name' => 'new-slug', 'post_parent' => 2, 'menu_order' => 4,
        ]);
    });

    it('answers with a 404 error and writes nothing for a missing post', function (): void {
        Functions\expect('wp_update_post')->never();

        $result = H::run(contentAbility('update-post'), ['id' => 6, 'title' => 'x']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_data())->toBe(['status' => 404]);
    });

    it('relays an error from wp_update_post and does not apply the side effects', function (): void {
        H::posts(H::post(6));
        Functions\when('wp_update_post')->justReturn(new WP_Error('db_update_error', 'Could not update post in the database.'));
        Functions\expect('set_post_thumbnail')->never();

        $result = H::run(contentAbility('update-post'), ['id' => 6, 'title' => 'x', 'featured_image' => 3]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('db_update_error')
            ->and($result->get_error_message())->toStartWith('Could not update the post:');
    });

    it('does not touch categories, tags or meta when only a title changes, and ignores empty tags and meta', function (): void {
        H::posts(H::post(6));
        Functions\when('wp_update_post')->justReturn(6);
        Functions\expect('wp_set_post_categories')->never();
        Functions\expect('wp_set_post_tags')->never();
        Functions\expect('update_post_meta')->never();

        H::run(contentAbility('update-post'), ['id' => 6, 'title' => 'x', 'tags' => [], 'meta' => []]);
    });

    describe('permission', function (): void {
        it('is decided per post, so the capability for one post is not the capability for another', function (): void {
            H::posts(H::post(1), H::post(2));
            H::actAs(['edit_post:1', 'edit_posts']);

            expect(H::permitted(contentAbility('update-post'), ['id' => 1]))->toBeTrue()
                ->and(H::permitted(contentAbility('update-post'), ['id' => 2]))->toBeFalse();
        });

        it('denies a missing post and a post of a type outside the addressable set', function (): void {
            H::posts(H::post(3, ['post_type' => 'revision']));
            H::actAs(['edit_post:3', 'edit_post:4']);

            expect(H::permitted(contentAbility('update-post'), ['id' => 3]))->toBeFalse()
                ->and(H::permitted(contentAbility('update-post'), ['id' => 4]))->toBeFalse();
        });

        it('denies an id that is not a number', function (): void {
            H::posts(H::post(1));
            H::actAs(['edit_post:1']);

            expect(H::permitted(contentAbility('update-post'), ['id' => 'one']))->toBeFalse();
        });
    });
});

describe('delete-post', function (): void {
    it('moves the post to the bin by default', function (): void {
        H::posts(H::post(9));
        Functions\expect('wp_trash_post')->once()->with(9)->andReturn(H::post(9));
        Functions\expect('wp_delete_post')->never();

        expect(H::run(contentAbility('delete-post'), ['id' => 9]))
            ->toBe(['deleted' => true, 'id' => 9, 'permanent' => false]);
    });

    it('deletes permanently only when forced', function (): void {
        H::posts(H::post(9));
        Functions\expect('wp_delete_post')->once()->with(9, true)->andReturn(H::post(9));
        Functions\expect('wp_trash_post')->never();

        expect(H::run(contentAbility('delete-post'), ['id' => 9, 'force' => true]))
            ->toBe(['deleted' => true, 'id' => 9, 'permanent' => true]);
    });

    it('reads the string "false" as not forced', function (): void {
        H::posts(H::post(9));
        Functions\expect('wp_trash_post')->once()->andReturn(H::post(9));
        Functions\expect('wp_delete_post')->never();

        H::run(contentAbility('delete-post'), ['id' => 9, 'force' => 'false']);
    });

    it('answers with an error when WordPress refuses', function (): void {
        H::posts(H::post(9));
        Functions\when('wp_trash_post')->justReturn(false);

        $result = H::run(contentAbility('delete-post'), ['id' => 9]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('WordPress refused to delete post 9.');
    });

    it('answers with a 404 error and deletes nothing for a missing post', function (): void {
        Functions\expect('wp_trash_post')->never();
        Functions\expect('wp_delete_post')->never();

        $result = H::run(contentAbility('delete-post'), ['id' => 9, 'force' => true]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_not_found');
    });

    it('is decided per post through delete_post', function (): void {
        H::posts(H::post(1), H::post(2), H::post(3, ['post_type' => 'revision']));
        H::actAs(['delete_post:1', 'delete_post:3', 'delete_posts']);

        expect(H::permitted(contentAbility('delete-post'), ['id' => 1]))->toBeTrue()
            ->and(H::permitted(contentAbility('delete-post'), ['id' => 2]))->toBeFalse()
            ->and(H::permitted(contentAbility('delete-post'), ['id' => 3]))->toBeFalse()
            ->and(H::permitted(contentAbility('delete-post'), ['id' => 99]))->toBeFalse();
    });
});

describe('set-post-terms', function (): void {
    beforeEach(function (): void {
        H::actAs(['edit_posts', 'manage_categories']);
        H::posts(H::post(11));
        Functions\when('get_term_by')->alias(static fn (string $field, string $slug, string $taxonomy): WP_Term|false => match ($slug) {
            'news' => new WP_Term(['term_id' => 4, 'slug' => 'news']),
            'sport' => new WP_Term(['term_id' => 5, 'slug' => 'sport']),
            default => false,
        });
        Functions\when('wp_get_object_terms')->justReturn(['news', 'sport']);
    });

    it('appends the resolved term ids by default and returns the resulting slugs', function (): void {
        Functions\expect('wp_set_object_terms')->once()->with(11, [4, 5], 'category', true)->andReturn([4, 5]);

        expect(H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'terms' => ['news', 'sport']]))
            ->toBe(['post_id' => 11, 'taxonomy' => 'category', 'terms' => ['news', 'sport']]);
    });

    it('replaces the terms when append is false', function (): void {
        Functions\expect('wp_set_object_terms')->once()->with(11, [4], 'genre', false)->andReturn([4]);

        H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'taxonomy' => 'genre', 'terms' => ['news'], 'append' => false]);
    });

    it('answers with a 404 error for a missing post', function (): void {
        Functions\expect('wp_set_object_terms')->never();

        $result = H::run(contentAbility('set-post-terms'), ['post_id' => 12, 'terms' => ['news']]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_data())->toBe(['status' => 404]);
    });

    it('refuses an unknown taxonomy', function (): void {
        Functions\expect('wp_set_object_terms')->never();

        $result = H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'taxonomy' => 'nope', 'terms' => ['news']]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toContain('Unknown taxonomy "nope"');
    });

    it('lists every slug that does not exist and assigns nothing', function (): void {
        Functions\expect('wp_set_object_terms')->never();
        Functions\expect('wp_insert_term')->never();

        $result = H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'terms' => ['news', 'ghost', 'phantom']]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toContain('ghost, phantom')->toContain('create_missing=true');
    });

    it('creates the missing terms when asked to', function (): void {
        Functions\expect('wp_insert_term')->once()->with('ghost', 'category')->andReturn(['term_id' => '90', 'term_taxonomy_id' => '91']);
        Functions\expect('wp_set_object_terms')->once()->with(11, [4, 90], 'category', true)->andReturn([4, 90]);

        H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'terms' => ['news', 'ghost'], 'create_missing' => true]);
    });

    it('relays a failure to create a term and assigns nothing', function (): void {
        Functions\when('wp_insert_term')->justReturn(new WP_Error('term_exists', 'A term with the name provided already exists.'));
        Functions\expect('wp_set_object_terms')->never();

        $result = H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'terms' => ['ghost'], 'create_missing' => true]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('term_exists')
            ->and($result->get_error_message())->toContain('create the term "ghost"');
    });

    it('relays an error from wp_set_object_terms', function (): void {
        Functions\when('wp_set_object_terms')->justReturn(new WP_Error('invalid_taxonomy', 'Invalid taxonomy.'));

        $result = H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'terms' => ['news']]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('Could not assign the terms: Invalid taxonomy.');
    });

    it('is decided per post through edit_post, on post_id', function (): void {
        H::posts(H::post(11), H::post(12));
        H::actAs(['edit_post:11']);

        expect(H::permitted(contentAbility('set-post-terms'), ['post_id' => 11]))->toBeTrue()
            ->and(H::permitted(contentAbility('set-post-terms'), ['post_id' => 12]))->toBeFalse()
            ->and(H::permitted(contentAbility('set-post-terms'), ['id' => 11]))->toBeFalse();
    });
});

describe('password-protected posts', function (): void {
    beforeEach(function (): void {
        H::posts(H::post(5, ['post_content' => 'Secret body', 'post_excerpt' => 'Secret excerpt', 'post_password' => 'hunter2']));
    });

    it('withholds the body and excerpt from a reader who has no password, and says so', function (): void {
        $result = H::run(contentAbility('get-post'), ['id' => 5]);

        expect($result['content'])->toBe('')
            ->and($result['excerpt'])->toBe('')
            ->and($result['password_protected'])->toBeTrue()
            ->and(json_encode($result))->not->toContain('hunter2');
    });

    it('withholds them again when the password is wrong', function (): void {
        $result = H::run(contentAbility('get-post'), ['id' => 5, 'password' => 'wrong']);

        expect($result['content'])->toBe('')->and($result['password_protected'])->toBeTrue();
    });

    it('returns them when the right password is given, still without echoing it', function (): void {
        $result = H::run(contentAbility('get-post'), ['id' => 5, 'password' => 'hunter2']);

        expect($result['content'])->toBe('Secret body')
            ->and($result['excerpt'])->toBe('Secret excerpt')
            ->and($result['password_protected'])->toBeTrue()
            ->and(json_encode($result))->not->toContain('hunter2');
    });

    it('returns them to a user who may edit the post', function (): void {
        H::actAs(['edit_post:5']);

        expect(H::run(contentAbility('get-post'), ['id' => 5])['content'])->toBe('Secret body');
    });

    it('reports an unprotected post as such and leaves its body alone', function (): void {
        H::posts(H::post(6, ['post_content' => 'Open body']));

        $result = H::run(contentAbility('get-post'), ['id' => 6]);

        expect($result['content'])->toBe('Open body')->and($result['password_protected'])->toBeFalse();
    });
});

describe('publishing capability', function (): void {
    it('refuses to create a post in a publishing status without the capability', function (string $status): void {
        $captured = [];
        stageInsert($captured);
        Functions\expect('wp_insert_post')->never();

        $result = H::run(contentAbility('create-post'), ['title' => 'T', 'post_status' => $status]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_forbidden')
            ->and($result->get_error_data())->toBe(['status' => 403]);
    })->with(['publish', 'future', 'private']);

    it('creates a publishing status for a user who holds the capability', function (): void {
        $captured = [];
        stageInsert($captured);
        H::actAs(['publish_posts']);

        H::run(contentAbility('create-post'), ['title' => 'T', 'post_status' => 'publish']);

        expect($captured['post_status'])->toBe('publish');
    });

    it('still lets a user without the capability create a draft or a pending post', function (string $status): void {
        $captured = [];
        stageInsert($captured);

        H::run(contentAbility('create-post'), ['title' => 'T', 'post_status' => $status]);

        expect($captured['post_status'])->toBe($status);
    })->with(['draft', 'pending']);

    it('refuses to publish an existing post without the capability, and writes nothing', function (): void {
        H::posts(H::post(7, ['post_status' => 'draft']));
        Functions\expect('wp_update_post')->never();

        $result = H::run(contentAbility('update-post'), ['id' => 7, 'post_status' => 'publish']);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_forbidden');
    });

    it('publishes an existing post for a user who holds the capability', function (): void {
        H::posts(H::post(7, ['post_status' => 'draft']));
        H::actAs(['publish_posts']);
        Functions\expect('wp_update_post')->once()->andReturn(7);

        H::run(contentAbility('update-post'), ['id' => 7, 'post_status' => 'publish']);
    });

    it('does not ask for the capability when the status is not changing', function (): void {
        H::posts(H::post(7, ['post_status' => 'publish']));
        Functions\expect('wp_update_post')->once()->andReturn(7);

        H::run(contentAbility('update-post'), ['id' => 7, 'post_status' => 'publish', 'title' => 'New']);
    });
});

describe('term capabilities', function (): void {
    it('refuses to assign terms without the assign capability of the taxonomy', function (): void {
        H::posts(H::post(11));
        Functions\when('get_term_by')->justReturn(new WP_Term(['term_id' => 4, 'slug' => 'news']));
        Functions\expect('wp_set_object_terms')->never();

        $result = H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'terms' => ['news']]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_forbidden');
    });

    it('refuses create_missing without the edit capability of the taxonomy, and creates nothing', function (): void {
        H::posts(H::post(11));
        H::actAs(['edit_posts']);
        Functions\when('get_term_by')->justReturn(false);
        Functions\expect('wp_insert_term')->never();
        Functions\expect('wp_set_object_terms')->never();

        $result = H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'terms' => ['ghost'], 'create_missing' => true]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_forbidden');
    });

    it('creates the missing terms for a user who may edit the taxonomy', function (): void {
        H::posts(H::post(11));
        H::actAs(['edit_posts', 'manage_categories']);
        Functions\when('get_term_by')->justReturn(false);
        Functions\when('wp_get_object_terms')->justReturn(['ghost']);
        Functions\expect('wp_insert_term')->once()->andReturn(['term_id' => 90]);
        Functions\expect('wp_set_object_terms')->once()->andReturn([90]);

        H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'terms' => ['ghost'], 'create_missing' => true]);
    });

    it('refuses to create a new tag without the edit capability, before creating the post', function (): void {
        $captured = [];
        stageInsert($captured);
        H::actAs(['edit_posts', 'create_posts']);
        Functions\expect('wp_insert_post')->never();
        Functions\expect('wp_set_post_tags')->never();

        $result = H::run(contentAbility('create-post'), ['title' => 'T', 'tags' => ['Brand new']]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_forbidden');
    });

    it('lets a user without the edit capability attach tags that already exist', function (): void {
        $captured = [];
        stageInsert($captured);
        H::actAs(['edit_posts']);
        Functions\when('term_exists')->justReturn(['term_id' => 3]);
        Functions\expect('wp_set_post_tags')->once()->with(31, ['Known']);

        H::run(contentAbility('create-post'), ['title' => 'T', 'tags' => ['Known']]);
    });

    it('refuses to assign categories or tags without the assign capability', function (string $field): void {
        $captured = [];
        stageInsert($captured);
        Functions\expect('wp_insert_post')->never();

        $result = H::run(contentAbility('create-post'), ['title' => 'T', $field => ['x']]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_forbidden');
    })->with(['categories', 'tags']);
});

describe('unknown categories', function (): void {
    beforeEach(function (): void {
        H::actAs(['edit_posts']);
        Functions\when('get_term_by')->justReturn(false);
    });

    it('fail the creation before any post is written', function (): void {
        $captured = [];
        stageInsert($captured);
        Functions\expect('wp_insert_post')->never();
        Functions\expect('wp_set_post_categories')->never();

        $result = H::run(contentAbility('create-post'), ['title' => 'T', 'categories' => ['ghost', 'phantom']]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_invalid_input')
            ->and($result->get_error_message())->toContain('ghost, phantom');
    });

    it('fail the update before the post is touched', function (): void {
        H::posts(H::post(7));
        Functions\expect('wp_update_post')->never();

        $result = H::run(contentAbility('update-post'), ['id' => 7, 'title' => 'New', 'categories' => ['ghost']]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_message())->toContain('ghost');
    });
});

describe('refusals that must come before any write', function (): void {
    it('refuses undeclared meta on update before the post is touched', function (): void {
        H::posts(H::post(6));
        Functions\when('get_registered_meta_keys')->justReturn(['subtitle' => ['show_in_rest' => true]]);
        Functions\expect('wp_update_post')->never();
        Functions\expect('update_post_meta')->never();

        $result = H::run(contentAbility('update-post'), ['id' => 6, 'title' => 'New', 'meta' => ['ghost' => 'x']]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toContain('ghost');
    });

    it('refuses meta the user may not edit on update before the post is touched', function (): void {
        H::posts(H::post(6));
        Functions\when('get_registered_meta_keys')->justReturn(['subtitle' => ['show_in_rest' => true]]);
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('wp_update_post')->never();
        Functions\expect('update_post_meta')->never();

        $result = H::run(contentAbility('update-post'), ['id' => 6, 'title' => 'New', 'meta' => ['subtitle' => 'x']]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_forbidden');
    });

    it('removes every category when an update sends an empty list', function (): void {
        H::posts(H::post(6));
        H::actAs(['edit_posts']);
        Functions\when('wp_update_post')->justReturn(6);
        Functions\expect('wp_set_object_terms')->once()->with(6, [], 'category');
        Functions\expect('wp_set_post_categories')->never();

        H::run(contentAbility('update-post'), ['id' => 6, 'categories' => []]);
    });

    it('needs the term capability to remove categories too', function (): void {
        H::posts(H::post(6));
        Functions\expect('wp_update_post')->never();
        Functions\expect('wp_set_object_terms')->never();

        $result = H::run(contentAbility('update-post'), ['id' => 6, 'categories' => []]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_forbidden');
    });

    it('leaves the default category alone when a creation sends an empty list', function (): void {
        $captured = [];
        stageInsert($captured);
        Functions\expect('wp_set_object_terms')->never();
        Functions\expect('wp_set_post_categories')->never();

        H::run(contentAbility('create-post'), ['title' => 'T', 'categories' => []]);
    });

    it('creates no term when the batch is refused for lack of the capability', function (): void {
        H::actAs(['edit_posts']);
        H::posts(H::post(11));
        Functions\when('get_term_by')->justReturn(false);
        Functions\expect('wp_insert_term')->never();

        $result = H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'terms' => ['a', 'b'], 'create_missing' => true]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_forbidden');
    });

    it('takes back the terms it created when a later one is refused', function (): void {
        H::actAs(['edit_posts', 'manage_categories']);
        H::posts(H::post(11));
        Functions\when('get_term_by')->justReturn(false);
        Functions\when('wp_insert_term')->alias(static fn (string $slug): array|WP_Error => $slug === 'bad'
            ? new WP_Error('term_exists', 'exists')
            : ['term_id' => 70, 'term_taxonomy_id' => 71]);
        Functions\expect('wp_delete_term')->once()->with(70, 'category');
        Functions\expect('wp_set_object_terms')->never();

        $result = H::run(contentAbility('set-post-terms'), ['post_id' => 11, 'terms' => ['good', 'bad'], 'create_missing' => true]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('term_exists');
    });
});

describe('password-protected posts in listings and meta', function (): void {
    beforeEach(function (): void {
        $this->locked = H::post(5, ['post_excerpt' => 'Secret excerpt', 'post_password' => 'hunter2']);
        $this->open = H::post(6, ['post_excerpt' => 'Public excerpt']);
        WP_Query::$stagedPosts = [$this->locked, $this->open];
    });

    it('empties the excerpt of a protected post in get-posts and get-pages, and flags it', function (): void {
        $posts = H::run(contentAbility('get-posts'))['posts'];
        $pages = H::run(contentAbility('get-pages'))['pages'];

        foreach ([$posts, $pages] as $list) {
            expect($list[0]['excerpt'])->toBe('')
                ->and($list[0]['password_protected'])->toBeTrue()
                ->and($list[1]['excerpt'])->toBe('Public excerpt')
                ->and($list[1]['password_protected'])->toBeFalse()
                ->and(json_encode($list))->not->toContain('hunter2')->not->toContain('Secret');
        }
    });

    it('keeps the excerpt for a user who may edit that post', function (): void {
        H::actAs(['edit_post:5']);

        expect(H::run(contentAbility('get-posts'))['posts'][0]['excerpt'])->toBe('Secret excerpt');
    });

    it('hides the declared meta of a protected post along with its body', function (): void {
        H::posts($this->locked);
        Functions\when('get_registered_meta_keys')->justReturn(['subtitle' => ['show_in_rest' => true, 'single' => true]]);
        Functions\when('get_post_meta')->justReturn('private value');

        expect(H::run(contentAbility('get-post'), ['id' => 5])['meta'])->toBe([])
            ->and(H::run(contentAbility('get-post'), ['id' => 5, 'password' => 'hunter2'])['meta'])->toBe(['subtitle' => 'private value']);

        H::actAs(['edit_post:5']);
        expect(H::run(contentAbility('get-post'), ['id' => 5])['meta'])->toBe(['subtitle' => 'private value']);
    });
});

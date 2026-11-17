<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Formatting\PostMeta;

beforeEach(function (): void {
    Functions\when('get_registered_meta_keys')->alias(static fn (string $type, string $subtype = ''): array => match ($subtype) {
        'book' => [
            'isbn' => ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'description' => 'The ISBN'],
            'tags' => ['type' => 'array', 'show_in_rest' => true],
            'hidden' => ['type' => 'string', 'show_in_rest' => false],
        ],
        'empty' => [],
        default => [
            'footnotes' => ['type' => 'string', 'single' => true, 'show_in_rest' => true],
            'isbn' => ['type' => 'integer', 'show_in_rest' => true],
            'private' => ['show_in_rest' => false],
        ],
    });
    Functions\when('is_protected_meta')->alias(static fn (string $key): bool => $key === 'footnotes');
});

describe('the declared keys', function (): void {
    it('merges the general ones with the type\'s own, which win, and drops what is not in REST', function (): void {
        $declared = PostMeta::declaredFor('book');

        expect(array_keys($declared))->toBe(['footnotes', 'isbn', 'tags'])
            ->and($declared['isbn']['type'])->toBe('string');
    });
});

describe('reading meta', function (): void {
    it('reads each declared key as single or as a list, as it was declared', function (): void {
        $calls = [];
        Functions\when('get_post_meta')->alias(static function (int $id, string $key, bool $single) use (&$calls): mixed {
            $calls[$key] = [$id, $single];

            return $single ? 'one' : ['many'];
        });

        $values = PostMeta::read(new WP_Post(['ID' => 5, 'post_type' => 'book']));

        expect($values)->toBe(['footnotes' => 'one', 'isbn' => 'one', 'tags' => ['many']])
            ->and($calls)->toBe(['footnotes' => [5, true], 'isbn' => [5, true], 'tags' => [5, false]]);
    });

    it('reads nothing for a type that declares nothing in particular beyond the general keys', function (): void {
        Functions\when('get_post_meta')->justReturn('v');

        expect(array_keys(PostMeta::read(new WP_Post(['ID' => 1, 'post_type' => 'empty']))))->toBe(['footnotes', 'isbn']);
    });
});

describe('describing meta', function (): void {
    it('states type, cardinality, description and protection for every key', function (): void {
        expect(PostMeta::describe('book'))->toBe([
            ['key' => 'footnotes', 'type' => 'string', 'single' => true, 'description' => '', 'protected' => true],
            ['key' => 'isbn', 'type' => 'string', 'single' => true, 'description' => 'The ISBN', 'protected' => false],
            ['key' => 'tags', 'type' => 'array', 'single' => false, 'description' => '', 'protected' => false],
        ]);
    });

    it('defaults a key declared without a type to a string and without cardinality to a list', function (): void {
        Functions\when('get_registered_meta_keys')->alias(static fn (string $type, string $subtype = ''): array => $subtype === ''
            ? ['bare' => ['show_in_rest' => true]]
            : []);

        expect(PostMeta::describe('post'))->toBe([
            ['key' => 'bare', 'type' => 'string', 'single' => false, 'description' => '', 'protected' => false],
        ]);
    });
});

describe('checking meta', function (): void {
    beforeEach(function (): void {
        Functions\when('current_user_can')->justReturn(true);
    });

    it('accepts nothing to check, and declared keys', function (): void {
        expect(PostMeta::check('book', [], null))->toBeTrue()
            ->and(PostMeta::check('book', ['isbn' => 'x', 'tags' => []], null))->toBeTrue()
            ->and(PostMeta::check('book', ['isbn' => 'x'], 5))->toBeTrue();
    });

    it('names the undeclared keys and the declared ones', function (): void {
        $error = PostMeta::check('book', ['nope' => 1, 'isbn' => 2, 'also' => 3], null);

        expect($error)->toBeInstanceOf(WP_Error::class)
            ->and($error->get_error_message())->toBe('The post type "book" does not declare the field(s): nope, also. Declared fields are: footnotes, isbn, tags.');
    });

    it('says when a type declares no field at all', function (): void {
        Functions\when('get_registered_meta_keys')->justReturn([]);

        $error = PostMeta::check('book', ['nope' => 1], null);

        expect($error->get_error_message())->toBe('The post type "book" does not declare the field(s): nope. It declares no custom fields at all; fields managed by a framework such as Meta Box are reachable through that plugin\'s own tools.');
    });

    it('asks the capability for each declared key of an existing post and names the refused ones', function (): void {
        $asked = [];
        Functions\when('current_user_can')->alias(static function (string $capability, int $id, string $key) use (&$asked): bool {
            $asked[] = [$capability, $id, $key];

            return $key !== 'tags';
        });

        $error = PostMeta::check('book', ['isbn' => 1, 'tags' => 2, 'nope' => 3], 9);

        expect($asked)->toBe([['edit_post_meta', 9, 'isbn'], ['edit_post_meta', 9, 'tags']])
            ->and($error)->toBeInstanceOf(WP_Error::class)
            ->and($error->get_error_message())->toContain('write the field(s) tags on post 9');
    });

    it('does not ask about a post that does not exist yet', function (): void {
        Functions\expect('current_user_can')->never();

        expect(PostMeta::check('book', ['isbn' => 1], null))->toBeTrue();
    });

    it('treats a numeric key as the string it will be stored under', function (): void {
        $error = PostMeta::check('book', [12 => 'x'], null);

        expect($error->get_error_message())->toContain('field(s): 12.');
    });
});

describe('writing meta', function (): void {
    beforeEach(function (): void {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post_type')->justReturn('book');
    });

    it('has nothing to write for no values, and asks nothing', function (): void {
        Functions\expect('get_post_type')->never();
        Functions\expect('update_post_meta')->never();

        expect(PostMeta::write(5, []))->toBeTrue();
    });

    it('writes every declared key once all of them pass', function (): void {
        $written = [];
        Functions\when('update_post_meta')->alias(static function (int $id, string $key, mixed $value) use (&$written): bool {
            $written[] = [$id, $key, $value];

            return true;
        });

        expect(PostMeta::write(5, ['isbn' => 'x', 'tags' => ['a']]))->toBeTrue()
            ->and($written)->toBe([[5, 'isbn', 'x'], [5, 'tags', ['a']]]);
    });

    it('reports a missing post', function (): void {
        Functions\when('get_post_type')->justReturn(false);
        Functions\expect('update_post_meta')->never();

        $error = PostMeta::write(5, ['isbn' => 'x']);

        expect($error)->toBeInstanceOf(WP_Error::class)
            ->and($error->get_error_code())->not->toBe('');
    });

    it('writes nothing when any key is refused', function (): void {
        Functions\expect('update_post_meta')->never();

        $error = PostMeta::write(5, ['isbn' => 'x', 'nope' => 'y']);

        expect($error)->toBeInstanceOf(WP_Error::class);
    });
});

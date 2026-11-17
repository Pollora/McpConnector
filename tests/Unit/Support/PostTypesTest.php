<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\McpConnector\Settings;
use Pollora\McpConnector\Support\PostTypes;

beforeEach(function (): void {
    Settings::forget();
    Functions\when('post_type_exists')->alias(static fn (string $type): bool => in_array($type, ['post', 'page', 'book', 'attachment', 'revision'], true));
});

afterEach(function (): void {
    Settings::forget();
});

describe('the addressable set', function (): void {
    it('is the stored selection once reviewed, deduplicated, existing and as a list', function (): void {
        $this->setSettings(['post_types_reviewed' => true, 'addressable_post_types' => ['page', 'ghost', 'book', 'page', 'revision']]);

        expect(PostTypes::addressable())->toBe(['page', 'book']);
    });

    it('is the defaults until reviewed', function (): void {
        Functions\when('get_post_types')->justReturn(['post' => 'post', 'book' => 'book']);
        $this->setSettings(['post_types_reviewed' => false, 'addressable_post_types' => ['page']]);

        expect(PostTypes::addressable())->toBe(['post', 'book', 'attachment']);
    });

    it('lets the filter have the last word on the list it was handed', function (): void {
        $seen = null;
        $this->setSettings(['post_types_reviewed' => true, 'addressable_post_types' => ['page']]);
        Filters\expectApplied(PostTypes::FILTER)->once()->andReturnUsing(function (array $types) use (&$seen): array {
            $seen = $types;

            return [5 => 'book', 9 => 'post', 'x' => 'post'];
        });

        expect(PostTypes::addressable())->toBe(['book', 'post'])->and($seen)->toBe(['page']);
    });
});

describe('the defaults', function (): void {
    it('are the public REST-visible types plus attachments, as a list', function (): void {
        $asked = null;
        Functions\when('get_post_types')->alias(static function (array $args, string $output) use (&$asked): array {
            $asked = [$args, $output];

            return ['post' => 'post', 'page' => 'page'];
        });

        expect(PostTypes::defaults())->toBe(['post', 'page', 'attachment'])
            ->and($asked)->toBe([['public' => true, 'show_in_rest' => true], 'names']);
    });
});

describe('the offerable types', function (): void {
    it('are every registered type but the never-offered ones, as a list', function (): void {
        Functions\when('get_post_types')->justReturn([
            'post' => new WP_Post_Type('post', 'Post'),
            'revision' => new WP_Post_Type('revision', 'Revision'),
            'book' => new WP_Post_Type('book', 'Book'),
        ]);

        $offerable = PostTypes::offerable();

        expect(array_map(static fn (WP_Post_Type $t): string => $t->name, $offerable))->toBe(['post', 'book'])
            ->and(array_is_list($offerable))->toBeTrue();
    });
});

describe('the capability gates', function (): void {
    beforeEach(function (): void {
        $this->setSettings(['post_types_reviewed' => true, 'addressable_post_types' => ['book']]);
        Functions\when('get_post_type_object')->alias(static function (string $type): WP_Post_Type|null {
            if ($type !== 'book') {
                return null;
            }

            $object = new WP_Post_Type('book', 'Book');
            $object->cap->edit_posts = 'edit_books';
            $object->cap->create_posts = 'create_books';

            return $object;
        });
    });

    it('asks the type\'s own capability for reading and for creating', function (): void {
        $asked = [];
        Functions\when('current_user_can')->alias(static function (string $capability) use (&$asked): bool {
            $asked[] = $capability;

            return true;
        });

        expect(PostTypes::canRead('book'))->toBeTrue()
            ->and(PostTypes::canCreate('book'))->toBeTrue()
            ->and($asked)->toBe(['edit_books', 'create_books']);
    });

    it('refuses a type that is not addressable without asking the capability', function (): void {
        Functions\expect('current_user_can')->never();

        expect(PostTypes::canRead('page'))->toBeFalse()
            ->and(PostTypes::canCreate('page'))->toBeFalse();
    });

    it('refuses an addressable type that has no object', function (): void {
        $this->setSettings(['post_types_reviewed' => true, 'addressable_post_types' => ['page']]);
        Functions\when('get_post_type_object')->justReturn(null);
        Functions\when('current_user_can')->justReturn(true);

        expect(PostTypes::canRead('page'))->toBeFalse()
            ->and(PostTypes::canCreate('page'))->toBeFalse();
    });
});

describe('the refusal', function (): void {
    it('says a type is not exposed when it exists, and unknown when it does not, listing what is available', function (): void {
        $this->setSettings(['post_types_reviewed' => true, 'addressable_post_types' => ['post', 'book']]);

        expect(PostTypes::notAddressable('page')->get_error_message())->toBe('The post type "page" is not exposed through this connector. Available types: post, book.')
            ->and(PostTypes::notAddressable('ghost')->get_error_message())->toBe('There is no post type "ghost" on this site. Available types: post, book.');
    });
});

describe('the never-addressable types', function (): void {
    it('are refused even when stored and even when they exist', function (string $type): void {
        Functions\when('post_type_exists')->justReturn(true);
        $this->setSettings(['post_types_reviewed' => true, 'addressable_post_types' => ['post', $type]]);

        expect(PostTypes::addressable())->toBe(['post'])
            ->and(PostTypes::isAddressable($type))->toBeFalse();
    })->with(['revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles']);

    it('are not offered on the settings screen', function (string $type): void {
        Functions\when('get_post_types')->justReturn([$type => new WP_Post_Type($type, $type), 'post' => new WP_Post_Type('post', 'Post')]);

        expect(array_map(static fn (WP_Post_Type $t): string => $t->name, PostTypes::offerable()))->toBe(['post']);
    })->with(['revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles']);
});

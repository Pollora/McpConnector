<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\McpConnector\Formatting\PostFormatter;
use Pollora\McpConnector\Tests\Unit\Abilities\AbilityHarness as H;

beforeEach(function (): void {
    H::stubPostFormatting();
});

function postformatterPost(): WP_Post
{
    return new WP_Post(['ID' => 7] + [
        'post_title' => 'Hello',
        'post_name' => 'hello',
        'post_status' => 'draft',
        'post_type' => 'book',
        'post_date' => '2026-01-02 03:04:05',
        'post_modified' => '2026-02-03 04:05:06',
        'post_author' => 3,
        'post_excerpt' => 'Short',
        'post_content' => 'Body',
        'post_parent' => 4,
        'menu_order' => 9,
    ]);
}

describe('a post summary', function (): void {
    it('carries every field, typed', function (): void {
        Functions\when('get_the_author_meta')->alias(static fn (string $field, int $id): string => $field . ':' . $id);
        Functions\when('get_edit_post_link')->alias(static fn (int $id, string $context): string => 'edit/' . $id . '/' . $context);

        expect(PostFormatter::summary(postformatterPost()))->toBe([
            'id' => 7,
            'title' => 'Hello',
            'slug' => 'hello',
            'status' => 'draft',
            'type' => 'book',
            'date' => '2026-01-02 03:04:05',
            'modified' => '2026-02-03 04:05:06',
            'author' => 'display_name:3',
            'excerpt' => 'Short',
            'parent' => 4,
            'menu_order' => 9,
            'url' => 'https://example.test/?p=7',
            'edit_url' => 'edit/7/raw',
        ]);
    });

    it('reports a missing permalink as null', function (): void {
        Functions\when('get_permalink')->justReturn(false);

        expect(PostFormatter::summary(postformatterPost())['url'])->toBeNull();
    });
});

describe('a full post', function (): void {
    it('adds the body, the featured image, the template, the terms and the meta', function (): void {
        Functions\when('get_post_thumbnail_id')->justReturn(55);
        Functions\when('get_the_post_thumbnail_url')->alias(static fn (WP_Post $post, string $size): string => 'img/' . $size);
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key, bool $single): string => $key === '_wp_page_template' && $single ? 'wide.php' : '');
        Functions\when('get_object_taxonomies')->justReturn(['category', 'empty', 'post_tag']);
        Functions\when('get_the_terms')->alias(static fn (WP_Post $post, string $taxonomy): array|false => match ($taxonomy) {
            'category' => [4 => new WP_Term(['slug' => 'news']), 9 => new WP_Term(['slug' => 'sport'])],
            'post_tag' => false,
            default => [],
        });

        $full = PostFormatter::full(postformatterPost());

        expect($full['content'])->toBe('Body')
            ->and($full['featured_image_id'])->toBe(55)
            ->and($full['featured_image_url'])->toBe('img/full')
            ->and($full['page_template'])->toBe('wide.php')
            ->and($full['terms'])->toBe(['category' => ['news', 'sport']])
            ->and(array_is_list($full['terms']['category']))->toBeTrue()
            ->and($full)->toHaveKey('meta');
    });

    it('reports an absent image and template as null, not as zero or empty', function (): void {
        $full = PostFormatter::full(postformatterPost());

        expect($full['featured_image_id'])->toBeNull()
            ->and($full['featured_image_url'])->toBeNull()
            ->and($full['page_template'])->toBeNull()
            ->and($full['terms'])->toBe([]);
    });
});

describe('an attachment', function (): void {
    it('carries its file, its texts and its dimensions', function (): void {
        Functions\when('wp_get_attachment_metadata')->justReturn(['width' => 640, 'height' => 480]);
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key, bool $single): string => $key === '_wp_attachment_image_alt' && $single ? 'A cat' : '');
        $attachment = new WP_Post(['ID' => 12] + [
            'post_title' => 'Cat',
            'post_mime_type' => 'image/jpeg',
            'post_excerpt' => 'Caption',
            'post_content' => 'Description',
            'post_date' => '2026-03-04 05:06:07',
        ]);

        expect(PostFormatter::attachment($attachment))->toBe([
            'id' => 12,
            'title' => 'Cat',
            'url' => 'https://example.test/up/a.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => 'A cat',
            'caption' => 'Caption',
            'description' => 'Description',
            'width' => 640,
            'height' => 480,
            'date' => '2026-03-04 05:06:07',
        ]);
    });

    it('reports a missing file as null and missing alt text as an empty string', function (): void {
        Functions\when('wp_get_attachment_url')->justReturn(false);

        $record = PostFormatter::attachment(H::post(12));

        expect($record['url'])->toBeNull()
            ->and($record['alt_text'])->toBe('')
            ->and($record['width'])->toBeNull()
            ->and($record['height'])->toBeNull();
    });

    it('leaves a dimension null when the metadata lacks it', function (): void {
        Functions\when('wp_get_attachment_metadata')->justReturn(['width' => 10]);

        $record = PostFormatter::attachment(H::post(12));

        expect($record['width'])->toBe(10)->and($record['height'])->toBeNull();
    });
});

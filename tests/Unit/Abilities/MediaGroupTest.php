<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\Group\MediaGroup;
use Pollora\McpConnector\Settings;
use Pollora\McpConnector\Tests\Unit\Abilities\AbilityHarness as H;

beforeEach(function (): void {
    WP_Query::reset();
    Settings::forget();
    H::actAs([]);
    H::addressable(['post', 'page', 'attachment']);
    H::stubPostFormatting();
    H::posts();
});

afterEach(function (): void {
    Settings::forget();
});

function mediaAbility(string $slug): \Pollora\McpConnector\Abilities\AbilityDefinition
{
    return H::definition(new MediaGroup(), $slug);
}

/**
 * Stand in for the WordPress upload functions the import leans on.
 *
 * `wp_http_validate_url()` is reproduced faithfully enough for the cases that
 * matter here: only http and https pass.
 *
 * `download_url()` and `wp_delete_file()` are left to the test when it needs to
 * set an expectation on them: Brain Monkey ignores an expectation declared after a stub.
 *
 * @param list<array<string, mixed>> $sideloaded Collects the file array handed to media_handle_sideload().
 */
function stubUploadFunctions(array &$sideloaded, int|WP_Error $sideloadResult = 55, bool $withDownload = true, bool $withDelete = true): void
{
    Functions\when('wp_http_validate_url')->alias(
        static fn (string $url): string|false => in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : false,
    );
    if ($withDownload) {
        Functions\when('download_url')->justReturn('/tmp/phpUPLOAD');
    }

    Functions\when('wp_check_filetype')->alias(static function (string $name): array {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];

        return ['ext' => $types[$extension] ?? false ? $extension : false, 'type' => $types[$extension] ?? false];
    });
    Functions\when('media_handle_sideload')->alias(
        function (array $file, int $postId, ?string $description, array $postData) use (&$sideloaded, $sideloadResult): int|WP_Error {
            $sideloaded[] = ['file' => $file, 'post_id' => $postId, 'post_data' => $postData];

            return $sideloadResult;
        },
    );
    if ($withDelete) {
        Functions\when('wp_delete_file')->justReturn(null);
    }
}

describe('the group', function (): void {
    it('publishes five abilities under a stable key', function (): void {
        $group = new MediaGroup();

        expect($group->key())->toBe('media')
            ->and($group->label())->not->toBe('')
            ->and($group->description())->not->toBe('')
            ->and(array_map(static fn ($d): string => $d->slug, $group->definitions()))
            ->toBe(['get-media', 'upload-media-from-url', 'update-media', 'set-featured-image', 'delete-media']);
    });

    it('files every ability under the media category', function (): void {
        foreach ((new MediaGroup())->definitions() as $definition) {
            expect($definition->category)->toBe(AbilityCategory::Media);
        }
    });

    it('declares what each ability does to the site', function (string $slug, Behaviour $behaviour): void {
        expect(mediaAbility($slug)->behaviour)->toBe($behaviour);
    })->with([
        ['get-media', Behaviour::Reads],
        ['upload-media-from-url', Behaviour::Creates],
        ['update-media', Behaviour::Updates],
        ['set-featured-image', Behaviour::Updates],
        ['delete-media', Behaviour::Deletes],
    ]);

    it('withholds everything but the listing in read-only mode', function (): void {
        $readOnly = array_filter((new MediaGroup())->definitions(), static fn ($d): bool => $d->isReadOnly());

        expect(array_map(static fn ($d): string => $d->slug, array_values($readOnly)))->toBe(['get-media']);
    });

    it('requires the inputs a write cannot do without', function (): void {
        expect(mediaAbility('upload-media-from-url')->inputSchema['required'])->toBe(['url'])
            ->and(mediaAbility('update-media')->inputSchema['required'])->toBe(['id'])
            ->and(mediaAbility('set-featured-image')->inputSchema['required'])->toBe(['post_id', 'attachment_id'])
            ->and(mediaAbility('delete-media')->inputSchema['required'])->toBe(['id']);
    });
});

describe('get-media', function (): void {
    it('lists attachments, newest first, twenty at a time', function (): void {
        H::run(mediaAbility('get-media'));

        expect(WP_Query::$lastArgs)->toBe([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 20,
            'paged' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    });

    it('passes a search and a mime type through and clamps paging', function (): void {
        H::run(mediaAbility('get-media'), ['search' => 'logo', 'mime_type' => 'image', 'posts_per_page' => 9999, 'paged' => 0]);

        expect(WP_Query::$lastArgs)->toMatchArray([
            's' => 'logo', 'post_mime_type' => 'image', 'posts_per_page' => 100, 'paged' => 1,
        ]);
    });

    it('formats each attachment and reports the totals', function (): void {
        WP_Query::$stagedPosts = [H::post(4, [
            'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg', 'post_excerpt' => 'cap', 'post_content' => 'desc',
        ])];
        WP_Query::$stagedTotal = 31;
        WP_Query::$stagedPages = 2;
        Functions\when('wp_get_attachment_metadata')->justReturn(['width' => 640, 'height' => 480]);

        $result = H::run(mediaAbility('get-media'));

        expect($result['total'])->toBe(31)
            ->and($result['total_pages'])->toBe(2)
            ->and($result['media'][0])->toMatchArray([
                'id' => 4, 'url' => 'https://example.test/up/a.jpg', 'mime_type' => 'image/jpeg',
                'alt_text' => '', 'caption' => 'cap', 'description' => 'desc', 'width' => 640, 'height' => 480,
            ]);
    });

    it('requires upload_files', function (): void {
        expect(H::permitted(mediaAbility('get-media')))->toBeFalse();

        H::actAs(['upload_files']);
        expect(H::permitted(mediaAbility('get-media')))->toBeTrue();
    });
});

describe('upload-media-from-url', function (): void {
    it('refuses a URL that is not http or https before downloading anything', function (string $url): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded, withDownload: false);
        Functions\expect('download_url')->never();

        $result = H::run(mediaAbility('upload-media-from-url'), ['url' => $url]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_invalid_input')
            ->and($result->get_error_message())->toContain($url)
            ->and($sideloaded)->toBe([]);
    })->with([
        'file scheme' => ['file:///etc/passwd'],
        'ftp' => ['ftp://example.test/a.jpg'],
        'gopher' => ['gopher://example.test/a.jpg'],
        'javascript' => ['javascript:alert(1)'],
        'no scheme' => ['example.test/a.jpg'],
        'not a url' => ['not a url'],
    ]);

    it('relays a download failure, naming the URL', function (): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded, withDownload: false);
        Functions\when('download_url')->justReturn(new WP_Error('http_404', 'Not Found'));

        $result = H::run(mediaAbility('upload-media-from-url'), ['url' => 'https://example.test/a.jpg']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('http_404')
            ->and($result->get_error_message())->toBe('Could not download "https://example.test/a.jpg": Not Found')
            ->and($sideloaded)->toBe([]);
    });

    it('imports the file under its own name and returns the attachment', function (): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded);
        H::posts(H::post(55, ['post_type' => 'attachment', 'post_mime_type' => 'image/jpeg']));

        $result = H::run(mediaAbility('upload-media-from-url'), ['url' => 'https://example.test/media/photo.jpg']);

        expect($sideloaded)->toHaveCount(1)
            ->and($sideloaded[0]['file'])->toBe(['name' => 'photo.jpg', 'tmp_name' => '/tmp/phpUPLOAD'])
            ->and($sideloaded[0]['post_id'])->toBe(0)
            ->and($sideloaded[0]['post_data'])->toBe([])
            ->and($result)->toMatchArray(['id' => 55, 'mime_type' => 'image/jpeg']);
    });

    it('keeps only the last path segment as the file name', function (string $url, string $expected): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded);
        H::posts(H::post(55, ['post_type' => 'attachment']));

        H::run(mediaAbility('upload-media-from-url'), ['url' => $url]);

        expect($sideloaded[0]['file']['name'])->toBe($expected);
    })->with([
        'traversal in the path' => ['https://example.test/a/../../../etc/passwd.jpg', 'passwd.jpg'],
        'traversal in the query' => ['https://example.test/a.jpg?file=../../wp-config.php', 'a.jpg'],
        'a fragment' => ['https://example.test/a.png#../../x.php', 'a.png'],
        'a double extension' => ['https://example.test/shell.php.jpg', 'shell.php.jpg'],
        'upper-case extension' => ['https://example.test/PHOTO.JPG', 'PHOTO.JPG'],
        'a percent-encoded separator is not decoded' => ['https://example.test/%2e%2e%2fevil.jpg', '%2e%2e%2fevil.jpg'],
    ]);

    it('rejects a file type WordPress does not accept and removes the temporary file', function (string $url): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded, withDelete: false);
        Functions\expect('wp_delete_file')->once()->with('/tmp/phpUPLOAD');

        $result = H::run(mediaAbility('upload-media-from-url'), ['url' => $url]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('mcp_connector_invalid_input')
            ->and($sideloaded)->toBe([]);
    })->with([
        'a php file' => ['https://example.test/evil.php'],
        'a php file hidden behind a traversal' => ['https://example.test/x/../evil.php'],
        'a reversed double extension' => ['https://example.test/evil.jpg.php'],
        'no extension' => ['https://example.test/download'],
        'a trailing slash' => ['https://example.test/folder/'],
        'a bare host' => ['https://example.test'],
    ]);

    it('names the extension it refused, or "unknown" when there is none', function (): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded);

        $php = H::run(mediaAbility('upload-media-from-url'), ['url' => 'https://example.test/evil.php']);
        $none = H::run(mediaAbility('upload-media-from-url'), ['url' => 'https://example.test/download']);

        expect($php->get_error_message())->toContain('"php"')
            ->and($none->get_error_message())->toContain('"unknown"');
    });

    it('relays a sideload failure and removes the temporary file', function (): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded, new WP_Error('upload_error', 'Disk full.'), withDelete: false);
        Functions\expect('wp_delete_file')->once()->with('/tmp/phpUPLOAD');

        $result = H::run(mediaAbility('upload-media-from-url'), ['url' => 'https://example.test/a.jpg']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('upload_error')
            ->and($result->get_error_message())->toBe('Could not add the file to the media library: Disk full.');
    });

    it('leaves the temporary file to media_handle_sideload on success', function (): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded, withDelete: false);
        H::posts(H::post(55, ['post_type' => 'attachment']));
        Functions\expect('wp_delete_file')->never();

        H::run(mediaAbility('upload-media-from-url'), ['url' => 'https://example.test/a.jpg']);
    });

    it('maps the title, caption and description onto the attachment and attaches it to the post', function (): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded);
        H::posts(H::post(55, ['post_type' => 'attachment']));

        H::run(mediaAbility('upload-media-from-url'), [
            'url' => 'https://example.test/a.jpg', 'title' => 'T', 'caption' => 'C', 'description' => 'D', 'post_id' => 12,
        ]);

        expect($sideloaded[0]['post_data'])->toBe(['post_title' => 'T', 'post_excerpt' => 'C', 'post_content' => 'D'])
            ->and($sideloaded[0]['post_id'])->toBe(12);
    });

    it('stores a sanitised alt text', function (): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded);
        H::posts(H::post(55, ['post_type' => 'attachment']));
        Functions\expect('update_post_meta')->once()->with(55, '_wp_attachment_image_alt', 'A cat');

        H::run(mediaAbility('upload-media-from-url'), ['url' => 'https://example.test/a.jpg', 'alt_text' => ' A <script>cat</script> ']);
    });

    it('does not write alt text that was not given', function (): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded);
        H::posts(H::post(55, ['post_type' => 'attachment']));
        Functions\expect('update_post_meta')->never();

        H::run(mediaAbility('upload-media-from-url'), ['url' => 'https://example.test/a.jpg', 'alt_text' => '']);
    });

    it('reports a 404 when the new attachment cannot be read back', function (): void {
        $sideloaded = [];
        stubUploadFunctions($sideloaded);

        $result = H::run(mediaAbility('upload-media-from-url'), ['url' => 'https://example.test/a.jpg']);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_not_found');
    });

    it('requires upload_files', function (): void {
        expect(H::permitted(mediaAbility('upload-media-from-url'), ['url' => 'https://example.test/a.jpg']))->toBeFalse();

        H::actAs(['upload_files']);
        expect(H::permitted(mediaAbility('upload-media-from-url'), ['url' => 'https://example.test/a.jpg']))->toBeTrue();
    });
});

describe('update-media', function (): void {
    it('answers with a 404 error for something that is not an attachment', function (): void {
        H::posts(H::post(3, ['post_type' => 'post']));
        Functions\expect('wp_update_post')->never();

        foreach ([3, 999] as $id) {
            $result = H::run(mediaAbility('update-media'), ['id' => $id, 'title' => 'x']);

            expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_not_found');
        }
    });

    it('updates only the fields mentioned', function (): void {
        H::posts(H::post(7, ['post_type' => 'attachment']));
        $captured = [];
        Functions\when('wp_update_post')->alias(function (array $data) use (&$captured): int {
            $captured = $data;

            return 7;
        });

        H::run(mediaAbility('update-media'), ['id' => 7, 'title' => 'New', 'caption' => 'Cap']);

        expect($captured)->toBe(['ID' => 7, 'post_title' => 'New', 'post_excerpt' => 'Cap']);
    });

    it('writes the alt text without a post update when nothing else changed', function (): void {
        H::posts(H::post(7, ['post_type' => 'attachment']));
        Functions\expect('wp_update_post')->never();
        Functions\expect('update_post_meta')->once()->with(7, '_wp_attachment_image_alt', 'Alt');

        H::run(mediaAbility('update-media'), ['id' => 7, 'alt_text' => '<i>Alt</i>']);
    });

    it('relays an error from wp_update_post and skips the alt text', function (): void {
        H::posts(H::post(7, ['post_type' => 'attachment']));
        Functions\when('wp_update_post')->justReturn(new WP_Error('db_update_error', 'Nope.'));
        Functions\expect('update_post_meta')->never();

        $result = H::run(mediaAbility('update-media'), ['id' => 7, 'title' => 'x', 'alt_text' => 'y']);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('Could not update the attachment: Nope.');
    });

    it('is decided per attachment through edit_post', function (): void {
        H::posts(H::post(7, ['post_type' => 'attachment']), H::post(8, ['post_type' => 'attachment']));
        H::actAs(['edit_post:7', 'upload_files']);

        expect(H::permitted(mediaAbility('update-media'), ['id' => 7]))->toBeTrue()
            ->and(H::permitted(mediaAbility('update-media'), ['id' => 8]))->toBeFalse();
    });

    it('is denied altogether when attachments are not addressable', function (): void {
        H::addressable(['post']);
        H::posts(H::post(7, ['post_type' => 'attachment']));
        H::actAs(['edit_post:7']);

        expect(H::permitted(mediaAbility('update-media'), ['id' => 7]))->toBeFalse();
    });
});

describe('set-featured-image', function (): void {
    it('answers with a 404 error for a missing post', function (): void {
        $result = H::run(mediaAbility('set-featured-image'), ['post_id' => 9, 'attachment_id' => 4]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_data())->toBe(['status' => 404]);
    });

    it('removes the featured image when the attachment id is zero', function (): void {
        H::posts(H::post(9));
        Functions\expect('delete_post_thumbnail')->once()->with(9);
        Functions\expect('set_post_thumbnail')->never();

        expect(H::run(mediaAbility('set-featured-image'), ['post_id' => 9, 'attachment_id' => 0]))
            ->toBe(['post_id' => 9, 'featured_image_id' => null, 'featured_image_url' => null]);
    });

    it('refuses an attachment that is not an image', function (): void {
        H::posts(H::post(9));
        Functions\when('wp_attachment_is_image')->justReturn(false);
        Functions\expect('set_post_thumbnail')->never();

        $result = H::run(mediaAbility('set-featured-image'), ['post_id' => 9, 'attachment_id' => 4]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('Attachment 4 is not an image and cannot be used as a featured image.');
    });

    it('sets the image and returns its URL', function (): void {
        H::posts(H::post(9));
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\expect('set_post_thumbnail')->once()->with(9, 4)->andReturn(5);
        Functions\when('get_the_post_thumbnail_url')->justReturn('https://example.test/up/a.jpg');

        expect(H::run(mediaAbility('set-featured-image'), ['post_id' => 9, 'attachment_id' => 4]))
            ->toBe(['post_id' => 9, 'featured_image_id' => 4, 'featured_image_url' => 'https://example.test/up/a.jpg']);
    });

    it('reports a refusal from WordPress', function (): void {
        H::posts(H::post(9));
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('set_post_thumbnail')->justReturn(false);

        $result = H::run(mediaAbility('set-featured-image'), ['post_id' => 9, 'attachment_id' => 4]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toContain('refused to set attachment 4')->toContain('post 9');
    });

    it('is decided per post, on post_id', function (): void {
        H::posts(H::post(9), H::post(10));
        H::actAs(['edit_post:9']);

        expect(H::permitted(mediaAbility('set-featured-image'), ['post_id' => 9, 'attachment_id' => 4]))->toBeTrue()
            ->and(H::permitted(mediaAbility('set-featured-image'), ['post_id' => 10, 'attachment_id' => 4]))->toBeFalse();
    });
});

describe('delete-media', function (): void {
    it('always deletes permanently', function (): void {
        H::posts(H::post(7, ['post_type' => 'attachment']));
        Functions\expect('wp_delete_attachment')->once()->with(7, true)->andReturn(H::post(7));

        expect(H::run(mediaAbility('delete-media'), ['id' => 7, 'force' => false]))->toBe(['deleted' => true, 'id' => 7]);
    });

    it('refuses to delete anything that is not an attachment', function (): void {
        H::posts(H::post(3, ['post_type' => 'post']));
        Functions\expect('wp_delete_attachment')->never();

        $result = H::run(mediaAbility('delete-media'), ['id' => 3]);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('mcp_connector_not_found');
    });

    it('reports a refusal from WordPress', function (): void {
        H::posts(H::post(7, ['post_type' => 'attachment']));
        Functions\when('wp_delete_attachment')->justReturn(false);

        $result = H::run(mediaAbility('delete-media'), ['id' => 7]);

        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_message())->toBe('WordPress refused to delete attachment 7.');
    });

    it('is decided per attachment through delete_post', function (): void {
        H::posts(H::post(7, ['post_type' => 'attachment']), H::post(8, ['post_type' => 'attachment']));
        H::actAs(['delete_post:7', 'upload_files']);

        expect(H::permitted(mediaAbility('delete-media'), ['id' => 7]))->toBeTrue()
            ->and(H::permitted(mediaAbility('delete-media'), ['id' => 8]))->toBeFalse();
    });
});

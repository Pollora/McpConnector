<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities\Group;

use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\Abilities\Domain\Schema\SchemaBuilder;
use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\AbilityDefinition;
use Pollora\McpConnector\Abilities\AbilityGroup;
use Pollora\McpConnector\Formatting\PostFormatter;
use Pollora\McpConnector\Support\Failure;
use Pollora\McpConnector\Support\PostTypes;

defined('ABSPATH') || exit;

/**
 * Reading and writing media library attachments.
 *
 * Uploading is by URL rather than by payload: MCP tool arguments are JSON, and
 * base64-encoding a photograph into a tool call wastes an enormous amount of
 * context for something the server can fetch itself in one request.
 *
 * That fetch is a server-side request to a caller-supplied URL, so it is
 * deliberately narrow: only http and https, only MIME types WordPress already
 * accepts for upload, and only for users who hold `upload_files`.
 */
final class MediaGroup implements AbilityGroup
{
    /**
     * Stable group key stored in the settings option.
     *
     * @var string
     */
    public const KEY = 'media';

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('Media library', 'amphibee-mcp-connector');
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return __(
            'List attachments, import files from a URL, edit attachment metadata, and delete attachments.',
            'amphibee-mcp-connector',
        );
    }

    /**
     * {@inheritDoc}
     */
    public function definitions(): array
    {
        return [
            $this->listMedia(),
            $this->uploadFromUrl(),
            $this->updateMedia(),
            $this->setFeaturedImage(),
            $this->deleteMedia(),
        ];
    }

    /**
     * Set or clear a post's featured image.
     *
     * The same thing can be done through `update-post`, but this exists because
     * it is the step that immediately follows an import: a model that has just
     * uploaded an image should be able to attach it in one call, without having
     * to assemble a post update around it.
     *
     * @return AbilityDefinition The ability.
     */
    private function setFeaturedImage(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'set-featured-image',
            label: __('Set a featured image', 'amphibee-mcp-connector'),
            description: 'Set the featured image of a post from an existing attachment, or pass '
                . 'attachment_id=0 to remove it.',
            category: AbilityCategory::Media,
            inputSchema: (new SchemaBuilder())
                ->integer('post_id', 'ID of the post to set the featured image on.', required: true, minimum: 1)
                ->integer('attachment_id', 'Attachment ID of the image, or 0 to remove the current one.', required: true, minimum: 0)
                ->toArray(),
            execute: static function (Input $input): array|\WP_Error {
                $postId = $input->id('post_id');
                $attachmentId = $input->id('attachment_id');

                if (! get_post($postId) instanceof \WP_Post) {
                    return Failure::notFound('post', $postId);
                }

                if ($attachmentId === 0) {
                    delete_post_thumbnail($postId);

                    return ['post_id' => $postId, 'featured_image_id' => null, 'featured_image_url' => null];
                }

                // set_post_thumbnail() accepts any attachment, including PDFs and
                // audio, and the theme then renders a broken image. Refusing here
                // gives the caller something it can act on.
                if (! wp_attachment_is_image($attachmentId)) {
                    return Failure::invalid(sprintf(
                        'Attachment %d is not an image and cannot be used as a featured image.',
                        $attachmentId,
                    ));
                }

                if (! set_post_thumbnail($postId, $attachmentId)) {
                    return Failure::invalid(sprintf(
                        'WordPress refused to set attachment %d as the featured image of post %d.',
                        $attachmentId,
                        $postId,
                    ));
                }

                return [
                    'post_id' => $postId,
                    'featured_image_id' => $attachmentId,
                    'featured_image_url' => get_the_post_thumbnail_url($postId, 'full') ?: null,
                ];
            },
            permission: static fn (Input $input): bool => PostTypes::canEditPost($input->id('post_id')),
            behaviour: Behaviour::Updates,
        );
    }

    /**
     * List media library items.
     *
     * @return AbilityDefinition The ability.
     */
    private function listMedia(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-media',
            label: __('List media', 'amphibee-mcp-connector'),
            description: 'List media library attachments, with their URLs, dimensions and alt text. '
                . 'Use the returned IDs to set a featured image or to reference an image in post content.',
            category: AbilityCategory::Media,
            inputSchema: (new SchemaBuilder())
                ->string('search', 'Match attachments whose title or file name contains this text.')
                ->string('mime_type', 'Filter by MIME type or prefix, such as image/jpeg or image.')
                ->integer('posts_per_page', 'How many attachments to return, at most 100.', default: 20, minimum: 1, maximum: 100)
                ->integer('paged', '1-based page number.', default: 1, minimum: 1)
                ->toArray(),
            execute: static function (Input $input): array {
                $args = [
                    'post_type' => 'attachment',
                    'post_status' => 'inherit',
                    'posts_per_page' => $input->integer('posts_per_page', 20, 1, 100),
                    'paged' => $input->integer('paged', 1, 1),
                    'orderby' => 'date',
                    'order' => 'DESC',
                ];

                if ($input->filled('search')) {
                    $args['s'] = $input->string('search');
                }

                if ($input->filled('mime_type')) {
                    $args['post_mime_type'] = $input->string('mime_type');
                }

                $query = new \WP_Query($args);

                return [
                    'media' => array_map(PostFormatter::attachment(...), $query->posts),
                    'total' => $query->found_posts,
                    'total_pages' => $query->max_num_pages,
                ];
            },
            permission: static fn (): bool => current_user_can('upload_files'),
        );
    }

    /**
     * Import a remote file into the media library.
     *
     * @return AbilityDefinition The ability.
     */
    private function uploadFromUrl(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'upload-media-from-url',
            label: __('Import media from a URL', 'amphibee-mcp-connector'),
            description: 'Download a file from a public URL and add it to the media library, optionally '
                . 'attaching it to a post. Returns the new attachment ID, which you can pass to '
                . 'create-post or update-post as featured_image.',
            category: AbilityCategory::Media,
            inputSchema: (new SchemaBuilder())
                ->string('url', 'Publicly reachable http or https URL of the file to import.', required: true)
                ->string('title', 'Attachment title. Derived from the file name when omitted.')
                ->string('alt_text', 'Alternative text, describing the image for screen readers.')
                ->string('caption', 'Caption displayed under the image.')
                ->string('description', 'Long description stored on the attachment.')
                ->integer('post_id', 'Post to attach the file to.', minimum: 1)
                ->toArray(),
            execute: static function (Input $input): array|\WP_Error {
                // These live in wp-admin and are not loaded during a REST request.
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                $url = $input->string('url');

                if (! wp_http_validate_url($url)) {
                    return Failure::invalid(sprintf(
                        'The URL "%s" is not a valid, publicly reachable http or https address.',
                        $url,
                    ));
                }

                $temporaryFile = download_url($url);

                if (is_wp_error($temporaryFile)) {
                    return Failure::fromWordPress($temporaryFile, sprintf('download "%s"', $url));
                }

                // media_handle_sideload() moves the temporary file on success and
                // leaves it behind on failure, so the cleanup below only ever has
                // work to do on the error paths.
                $fileName = basename((string) wp_parse_url($url, PHP_URL_PATH));
                $checked = wp_check_filetype($fileName);

                if ($checked['type'] === false) {
                    wp_delete_file($temporaryFile);

                    return Failure::invalid(sprintf(
                        'WordPress does not accept uploads of type "%s" on this site.',
                        pathinfo($fileName, PATHINFO_EXTENSION) ?: 'unknown',
                    ));
                }

                $postData = [];

                if ($input->filled('title')) {
                    $postData['post_title'] = $input->string('title');
                }

                if ($input->filled('caption')) {
                    $postData['post_excerpt'] = $input->string('caption');
                }

                if ($input->filled('description')) {
                    $postData['post_content'] = $input->string('description');
                }

                $attachmentId = media_handle_sideload(
                    ['name' => $fileName, 'tmp_name' => $temporaryFile],
                    $input->id('post_id'),
                    null,
                    $postData,
                );

                if (is_wp_error($attachmentId)) {
                    wp_delete_file($temporaryFile);

                    return Failure::fromWordPress($attachmentId, 'add the file to the media library');
                }

                if ($input->filled('alt_text')) {
                    update_post_meta(
                        $attachmentId,
                        '_wp_attachment_image_alt',
                        sanitize_text_field($input->string('alt_text')),
                    );
                }

                $attachment = get_post($attachmentId);

                return $attachment instanceof \WP_Post
                    ? PostFormatter::attachment($attachment)
                    : Failure::notFound('attachment', $attachmentId);
            },
            permission: static fn (): bool => current_user_can('upload_files'),
        );
    }

    /**
     * Edit an attachment's metadata.
     *
     * @return AbilityDefinition The ability.
     */
    private function updateMedia(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'update-media',
            label: __('Update media metadata', 'amphibee-mcp-connector'),
            description: 'Change an attachment\'s title, alt text, caption or description. The file itself '
                . 'is not touched. Only the fields you pass are changed.',
            category: AbilityCategory::Media,
            inputSchema: (new SchemaBuilder())
                ->integer('id', 'ID of the attachment to update.', required: true, minimum: 1)
                ->string('title', 'New attachment title.')
                ->string('alt_text', 'New alternative text.')
                ->string('caption', 'New caption.')
                ->string('description', 'New description.')
                ->toArray(),
            execute: static function (Input $input): array|\WP_Error {
                $attachmentId = $input->id('id');

                if (get_post_type($attachmentId) !== 'attachment') {
                    return Failure::notFound('attachment', $attachmentId);
                }

                $postData = ['ID' => $attachmentId];

                foreach (['title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content'] as $key => $column) {
                    if ($input->has($key)) {
                        $postData[$column] = $input->string($key);
                    }
                }

                if (count($postData) > 1) {
                    $result = wp_update_post($postData, true);

                    if (is_wp_error($result)) {
                        return Failure::fromWordPress($result, 'update the attachment');
                    }
                }

                if ($input->has('alt_text')) {
                    update_post_meta(
                        $attachmentId,
                        '_wp_attachment_image_alt',
                        sanitize_text_field($input->string('alt_text')),
                    );
                }

                $attachment = get_post($attachmentId);

                return $attachment instanceof \WP_Post
                    ? PostFormatter::attachment($attachment)
                    : Failure::notFound('attachment', $attachmentId);
            },
            permission: static fn (Input $input): bool => PostTypes::canEditPost($input->id('id')),
            behaviour: Behaviour::Updates,
        );
    }

    /**
     * Delete an attachment.
     *
     * @return AbilityDefinition The ability.
     */
    private function deleteMedia(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'delete-media',
            label: __('Delete media', 'amphibee-mcp-connector'),
            description: 'Delete an attachment and its generated image sizes. Posts that embed the file '
                . 'keep their markup and will show a broken image. This cannot be undone.',
            category: AbilityCategory::Media,
            inputSchema: (new SchemaBuilder())
                ->integer('id', 'ID of the attachment to delete.', required: true, minimum: 1)
                ->toArray(),
            execute: static function (Input $input): array|\WP_Error {
                $attachmentId = $input->id('id');

                if (get_post_type($attachmentId) !== 'attachment') {
                    return Failure::notFound('attachment', $attachmentId);
                }

                // Always permanent: an attachment in the bin keeps its file on
                // disk but stops resolving, which reads as data loss with extra
                // steps. If the file should survive, do not call this.
                if (! wp_delete_attachment($attachmentId, true) instanceof \WP_Post) {
                    return Failure::invalid(sprintf('WordPress refused to delete attachment %d.', $attachmentId));
                }

                return ['deleted' => true, 'id' => $attachmentId];
            },
            permission: static fn (Input $input): bool => PostTypes::canDeletePost($input->id('id')),
            behaviour: Behaviour::Deletes,
        );
    }
}

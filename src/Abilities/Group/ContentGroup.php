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
use Pollora\McpConnector\Formatting\PostMeta;
use Pollora\McpConnector\Support\Failure;
use Pollora\McpConnector\Support\PostTypes;
use WP_Query;

defined('ABSPATH') || exit;

/**
 * Reading and writing posts, pages and any other post type.
 *
 * Post content is passed to `wp_insert_post()` unfiltered on purpose. WordPress
 * already applies `content_save_pre`, which runs KSES for every user who lacks
 * the `unfiltered_html` capability — sanitising here as well would strip the
 * block delimiters (`<!-- wp:paragraph -->`) that make Gutenberg content
 * editable, and would do so even for administrators who are entitled to raw HTML.
 * The capability system is the right place for this decision, not the ability.
 */
final class ContentGroup implements AbilityGroup
{
    /**
     * Stable group key stored in the settings option.
     */
    public const string KEY = 'content';

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
        return __('Posts and pages', 'amphibee-mcp-connector');
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return __(
            'List, read, create, update and delete posts, pages and custom post types, and assign their terms.',
            'amphibee-mcp-connector',
        );
    }

    /**
     * {@inheritDoc}
     */
    public function definitions(): array
    {
        return [
            $this->listPosts(),
            $this->getPost(),
            $this->listPages(),
            $this->createPost(),
            $this->updatePost(),
            $this->deletePost(),
            $this->setPostTerms(),
        ];
    }

    /**
     * Query posts of any type, with the usual filters.
     *
     * @return AbilityDefinition The ability.
     */
    private function listPosts(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-posts',
            label: __('List posts', 'amphibee-mcp-connector'),
            description: 'Search and list posts of any post type. Returns summaries without post content; '
                . 'call get-post for the body of a single item. Supports filtering by status, taxonomy term, '
                . 'author and free-text search, and is paginated.',
            category: AbilityCategory::Content,
            inputSchema: (new SchemaBuilder())
                ->string('post_type', 'Post type slug, such as post, page, or a custom type. Call get-post-types to discover what exists.', default: 'post')
                ->string('post_status', 'Post status: publish, draft, pending, private, future, or any.', default: 'publish')
                ->integer('posts_per_page', 'How many posts to return, at most 100.', default: 10, minimum: 1, maximum: 100)
                ->integer('paged', '1-based page number, used with posts_per_page.', default: 1, minimum: 1)
                ->enum('orderby', 'Sort field.', ['date', 'modified', 'title', 'menu_order', 'ID', 'rand'], default: 'date')
                ->enum('order', 'Sort direction.', ['ASC', 'DESC'], default: 'DESC')
                ->string('search', 'Free-text search across title and content.')
                ->map('terms', 'Filter by taxonomy terms, as a map of taxonomy slug to a list of term slugs, e.g. {"annuaire_categorie": ["sante"]}. Call get-taxonomies for the taxonomies a post type has.')
                ->boolean('terms_match_all', 'Require every listed term rather than any of them.', default: false)
                ->integer('author', 'User ID of the author to filter by.')
                ->toArray(),
            execute: static function (Input $input): array|\WP_Error {
                $postType = $input->string('post_type', 'post');

                if (! PostTypes::isAddressable($postType)) {
                    return PostTypes::notAddressable($postType);
                }

                $args = [
                    'post_type' => $postType,
                    'post_status' => $input->string('post_status', 'publish'),
                    'posts_per_page' => $input->integer('posts_per_page', 10, 1, 100),
                    'paged' => $input->integer('paged', 1, 1),
                    'orderby' => $input->string('orderby', 'date'),
                    'order' => strtoupper($input->string('order', 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
                    // Restricts private and draft results to what the authenticated
                    // user may actually read, so a low-privileged token cannot list
                    // other people's drafts by asking for post_status=any.
                    'perm' => 'readable',
                    'ignore_sticky_posts' => true,
                ];

                if ($input->filled('search')) {
                    $args['s'] = $input->string('search');
                }

                // A generic tax_query rather than the hardcoded category/tag
                // pair this used to carry. Those two cover `post` and nothing
                // else: a directory listing is filtered by `annuaire_categorie`,
                // an editorial item by `fil_dossier`, and neither was reachable.
                $taxQuery = self::buildTaxQuery($input);

                if (is_wp_error($taxQuery)) {
                    return $taxQuery;
                }

                if ($taxQuery !== []) {
                    $args['tax_query'] = $taxQuery;
                }

                if ($input->id('author') > 0) {
                    $args['author'] = $input->id('author');
                }

                $query = new \WP_Query($args);

                return [
                    'posts' => array_map(PostFormatter::summary(...), $query->posts),
                    'total' => $query->found_posts,
                    'total_pages' => $query->max_num_pages,
                    'page' => $args['paged'],
                ];
            },
            permission: static fn (Input $input): bool
                => PostTypes::canRead($input->string('post_type', 'post')),
        );
    }

    /**
     * Read one post in full.
     *
     * @return AbilityDefinition The ability.
     */
    private function getPost(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-post',
            label: __('Read a post', 'amphibee-mcp-connector'),
            description: 'Retrieve a single post by ID, including its full content, featured image, '
                . 'page template and taxonomy terms.',
            category: AbilityCategory::Content,
            inputSchema: (new SchemaBuilder())
                ->integer('id', 'ID of the post, page or custom post type item to read.', required: true, minimum: 1)
                ->string('password', 'Password of a password-protected post. Without the right one, the content and excerpt of such a post are left empty unless you may edit it.')
                ->toArray(),
            execute: static function (Input $input): array|\WP_Error {
                $post = get_post($input->id('id'));

                return $post instanceof \WP_Post
                    ? self::protectedRecord($post, $input->string('password'))
                    : Failure::notFound('post', $input->id('id'));
            },
            permission: static fn (Input $input): bool => PostTypes::canReadPost($input->id('id')),
        );
    }

    /**
     * List pages in menu order, optionally within one parent.
     *
     * @return AbilityDefinition The ability.
     */
    private function listPages(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-pages',
            label: __('List pages', 'amphibee-mcp-connector'),
            description: 'List pages in menu order, optionally restricted to the children of one parent. '
                . 'Use this rather than get-posts when you need the page hierarchy.',
            category: AbilityCategory::Content,
            inputSchema: (new SchemaBuilder())
                ->string('post_status', 'Page status: publish, draft, pending, private, or any.', default: 'publish')
                ->integer('posts_per_page', 'How many pages to return, at most 100.', default: 20, minimum: 1, maximum: 100)
                ->integer('parent', 'Return only the direct children of this page ID. Pass 0 for top-level pages only.', minimum: 0)
                ->toArray(),
            execute: static function (Input $input): array {
                $args = [
                    'post_type' => 'page',
                    'post_status' => $input->string('post_status', 'publish'),
                    'posts_per_page' => $input->integer('posts_per_page', 20, 1, 100),
                    'orderby' => 'menu_order title',
                    'order' => 'ASC',
                    'perm' => 'readable',
                ];

                // `has()` rather than `filled()`: parent=0 is a meaningful request
                // for top-level pages, and an empty-value test would discard it.
                if ($input->has('parent')) {
                    $args['post_parent'] = $input->id('parent');
                }

                $query = new \WP_Query($args);

                return [
                    'pages' => array_map(PostFormatter::summary(...), $query->posts),
                    'total' => $query->found_posts,
                ];
            },
            permission: static fn (): bool => PostTypes::canRead('page'),
        );
    }

    /**
     * Create a post.
     *
     * @return AbilityDefinition The ability.
     */
    private function createPost(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'create-post',
            label: __('Create a post', 'amphibee-mcp-connector'),
            description: 'Create a post, page or custom post type item. Content should be Gutenberg block '
                . 'markup, for example <!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->. Defaults to '
                . 'a draft: pass post_status explicitly to publish.',
            category: AbilityCategory::Content,
            inputSchema: (new SchemaBuilder())
                ->string('title', 'Post title.', required: true)
                ->string('content', 'Post body, as Gutenberg block markup.')
                ->string('excerpt', 'Short summary used in listings and meta descriptions.')
                ->string('post_type', 'Post type slug to create.', default: 'post')
                ->enum('post_status', 'Status to create the post in.', ['draft', 'publish', 'pending', 'private', 'future'], default: 'draft')
                ->string('date', 'Publication date in site time, as YYYY-MM-DD HH:MM:SS. Required when post_status is future.')
                ->string('slug', 'URL slug. Derived from the title when omitted.')
                ->integer('parent', 'Parent ID, for hierarchical post types.', minimum: 0)
                ->integer('menu_order', 'Sort position, for hierarchical post types.')
                ->string('page_template', 'Page template file name, such as template-full-width.php.')
                ->integer('featured_image', 'Attachment ID to set as the featured image. Pass 0 to remove it.', minimum: 0)
                ->list('categories', 'Category slugs to assign.', ['type' => 'string', 'description' => 'Category slug.'])
                ->list('tags', 'Tag names to assign. Tags that do not exist are created.', ['type' => 'string', 'description' => 'Tag name.'])
                ->map('meta', 'Custom fields to set, as a name/value map. Only fields the post type declares are accepted — get-post-types lists them. Fields managed by Meta Box are set with its own tools instead.')
                ->toArray(),
            execute: static function (Input $input): array|\WP_Error {
                $postType = $input->string('post_type', 'post');

                if (! PostTypes::isAddressable($postType)) {
                    return PostTypes::notAddressable($postType);
                }

                $prepared = self::preflight($input, $postType, $input->string('post_status', 'draft'), null, null);

                if ($prepared instanceof \WP_Error) {
                    return $prepared;
                }

                $postData = [
                    'post_title' => $input->string('title'),
                    'post_content' => $input->string('content'),
                    'post_excerpt' => $input->string('excerpt'),
                    'post_type' => $postType,
                    'post_status' => $input->string('post_status', 'draft'),
                ];

                if ($input->filled('slug')) {
                    $postData['post_name'] = sanitize_title($input->string('slug'));
                }

                if ($input->filled('date')) {
                    $postData['post_date'] = $input->string('date');
                }

                if ($input->has('parent')) {
                    $postData['post_parent'] = $input->id('parent');
                }

                if ($input->has('menu_order')) {
                    $postData['menu_order'] = $input->integer('menu_order');
                }

                $postId = wp_insert_post($postData, true);

                if (is_wp_error($postId)) {
                    return Failure::fromWordPress($postId, 'create the post');
                }

                $failed = self::applySideEffects($postId, $input, $prepared);

                if ($failed instanceof \WP_Error) {
                    // The per-field capability can only be asked of a post that
                    // exists, so a refusal can still surface here. The post was
                    // created by this very call: take it back rather than leave
                    // an article the caller was told failed.
                    wp_delete_post($postId, true);

                    return $failed;
                }

                $post = get_post($postId);

                return $post instanceof \WP_Post
                    ? PostFormatter::full($post)
                    : Failure::notFound('post', $postId);
            },
            permission: static fn (Input $input): bool
                => PostTypes::canCreate($input->string('post_type', 'post')),
        );
    }

    /**
     * Update an existing post.
     *
     * @return AbilityDefinition The ability.
     */
    private function updatePost(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'update-post',
            label: __('Update a post', 'amphibee-mcp-connector'),
            description: 'Update an existing post. Only the fields you pass are changed; everything else is '
                . 'left alone. Read the post first with get-post if you intend to edit its content, so you '
                . 'do not overwrite blocks you did not mean to touch.',
            category: AbilityCategory::Content,
            inputSchema: (new SchemaBuilder())
                ->integer('id', 'ID of the post to update.', required: true, minimum: 1)
                ->string('title', 'New title.')
                ->string('content', 'New body, as Gutenberg block markup. Replaces the existing content entirely.')
                ->string('excerpt', 'New excerpt.')
                ->enum('post_status', 'New status.', ['draft', 'publish', 'pending', 'private', 'future'])
                ->string('slug', 'New URL slug.')
                ->integer('parent', 'New parent ID, for hierarchical post types.', minimum: 0)
                ->integer('menu_order', 'New sort position.')
                ->string('page_template', 'New page template file name.')
                ->integer('featured_image', 'Attachment ID to set as the featured image. Pass 0 to remove it.', minimum: 0)
                ->list('categories', 'Category slugs, replacing the current ones.', ['type' => 'string', 'description' => 'Category slug.'])
                ->list('tags', 'Tag names, replacing the current ones.', ['type' => 'string', 'description' => 'Tag name.'])
                ->map('meta', 'Custom fields to set, as a name/value map. Only fields the post type declares are accepted.')
                ->toArray(),
            execute: static function (Input $input): array|\WP_Error {
                $postId = $input->id('id');

                if (! get_post($postId) instanceof \WP_Post) {
                    return Failure::notFound('post', $postId);
                }

                $existing = get_post($postId);

                $prepared = self::preflight(
                    $input,
                    $existing->post_type,
                    $input->has('post_status') ? $input->string('post_status') : null,
                    $existing->post_status,
                    $postId,
                );

                if ($prepared instanceof \WP_Error) {
                    return $prepared;
                }

                $postData = ['ID' => $postId];

                // Each field is copied only when the caller mentioned it. A
                // partial update must not blank the fields it says nothing about.
                $map = [
                    'title' => 'post_title',
                    'content' => 'post_content',
                    'excerpt' => 'post_excerpt',
                    'post_status' => 'post_status',
                ];

                foreach ($map as $inputKey => $column) {
                    if ($input->has($inputKey)) {
                        $postData[$column] = $input->string($inputKey);
                    }
                }

                if ($input->filled('slug')) {
                    $postData['post_name'] = sanitize_title($input->string('slug'));
                }

                if ($input->has('parent')) {
                    $postData['post_parent'] = $input->id('parent');
                }

                if ($input->has('menu_order')) {
                    $postData['menu_order'] = $input->integer('menu_order');
                }

                $result = wp_update_post($postData, true);

                if (is_wp_error($result)) {
                    return Failure::fromWordPress($result, 'update the post');
                }

                $failed = self::applySideEffects($postId, $input, $prepared);

                if ($failed instanceof \WP_Error) {
                    return $failed;
                }

                $post = get_post($postId);

                return $post instanceof \WP_Post
                    ? PostFormatter::full($post)
                    : Failure::notFound('post', $postId);
            },
            permission: static fn (Input $input): bool => PostTypes::canEditPost($input->id('id')),
            behaviour: Behaviour::Updates,
        );
    }

    /**
     * Delete a post, to the bin or permanently.
     *
     * @return AbilityDefinition The ability.
     */
    private function deletePost(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'delete-post',
            label: __('Delete a post', 'amphibee-mcp-connector'),
            description: 'Move a post to the bin, or delete it permanently. Deleting to the bin is '
                . 'reversible and is the default; permanent deletion cannot be undone.',
            category: AbilityCategory::Content,
            inputSchema: (new SchemaBuilder())
                ->integer('id', 'ID of the post to delete.', required: true, minimum: 1)
                ->boolean('force', 'Delete permanently instead of moving to the bin.', default: false)
                ->toArray(),
            execute: static function (Input $input): array|\WP_Error {
                $postId = $input->id('id');

                if (! get_post($postId) instanceof \WP_Post) {
                    return Failure::notFound('post', $postId);
                }

                $force = $input->boolean('force');
                $result = $force ? wp_delete_post($postId, true) : wp_trash_post($postId);

                if (! $result instanceof \WP_Post) {
                    return Failure::invalid(sprintf('WordPress refused to delete post %d.', $postId));
                }

                return [
                    'deleted' => true,
                    'id' => $postId,
                    'permanent' => $force,
                ];
            },
            permission: static fn (Input $input): bool => PostTypes::canDeletePost($input->id('id')),
            behaviour: Behaviour::Deletes,
        );
    }

    /**
     * Assign taxonomy terms to a post.
     *
     * @return AbilityDefinition The ability.
     */
    private function setPostTerms(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'set-post-terms',
            label: __('Set post terms', 'amphibee-mcp-connector'),
            description: 'Assign taxonomy terms to a post by slug. Adds to the existing terms by default; '
                . 'pass append=false to replace them. Terms that do not exist are refused unless '
                . 'create_missing is set.',
            category: AbilityCategory::Content,
            inputSchema: (new SchemaBuilder())
                ->integer('post_id', 'ID of the post to assign terms to.', required: true, minimum: 1)
                ->string('taxonomy', 'Taxonomy slug, such as category or post_tag.', default: 'category')
                ->list('terms', 'Term slugs to assign.', ['type' => 'string', 'description' => 'Term slug.'], required: true)
                ->boolean('append', 'Add to the existing terms rather than replacing them.', default: true)
                ->boolean('create_missing', 'Create terms that do not exist yet instead of failing.', default: false)
                ->toArray(),
            execute: static function (Input $input): array|\WP_Error {
                $postId = $input->id('post_id');
                $taxonomy = $input->string('taxonomy', 'category');

                if (! get_post($postId) instanceof \WP_Post) {
                    return Failure::notFound('post', $postId);
                }

                if (! taxonomy_exists($taxonomy)) {
                    return Failure::invalid(sprintf(
                        'Unknown taxonomy "%s". Call get-taxonomies to see what this site registers.',
                        $taxonomy,
                    ));
                }

                $denied = self::requireTermCapability(
                    $taxonomy,
                    'assign_terms',
                    sprintf('assign terms of the "%s" taxonomy', $taxonomy),
                );

                if ($denied instanceof \WP_Error) {
                    return $denied;
                }

                $resolved = self::resolveTermSlugs(
                    $input->stringList('terms'),
                    $taxonomy,
                    $input->boolean('create_missing'),
                );

                if (is_wp_error($resolved)) {
                    return $resolved;
                }

                $result = wp_set_object_terms(
                    $postId,
                    $resolved,
                    $taxonomy,
                    $input->boolean('append', true),
                );

                if (is_wp_error($result)) {
                    return Failure::fromWordPress($result, 'assign the terms');
                }

                return [
                    'post_id' => $postId,
                    'taxonomy' => $taxonomy,
                    'terms' => wp_get_object_terms($postId, $taxonomy, ['fields' => 'slugs']),
                ];
            },
            permission: static fn (Input $input): bool => PostTypes::canEditPost($input->id('post_id')),
            behaviour: Behaviour::Updates,
        );
    }

    /**
     * Apply the optional extras shared by post creation and update.
     *
     * Each is skipped unless the caller mentioned it, so an update that only
     * changes a title does not clear the categories.
     *
     * @param int            $postId     Post being written to.
     * @param Input          $input      The ability input.
     * @param list<int>|null $categories Category IDs {@see self::preflight()} resolved, null when none were asked for.
     *
     * @return \WP_Error|null An error when a declared field could not be written, null otherwise.
     */
    private static function applySideEffects(int $postId, Input $input, ?array $categories = null): ?\WP_Error
    {
        if ($input->filled('page_template')) {
            update_post_meta($postId, '_wp_page_template', sanitize_text_field($input->string('page_template')));
        }

        // Zero is not "absent" here, it is an instruction to clear the featured
        // image — which is the only way to remove one through this ability.
        if ($input->has('featured_image')) {
            $attachmentId = $input->id('featured_image');

            if ($attachmentId > 0) {
                set_post_thumbnail($postId, $attachmentId);
            } else {
                delete_post_thumbnail($postId);
            }
        }

        if ($categories === []) {
            // wp_set_post_categories() puts the default category back when given
            // nothing, which is the opposite of what was asked.
            wp_set_object_terms($postId, [], 'category');
        } elseif ($categories !== null) {
            wp_set_post_categories($postId, $categories);
        }

        // Tags are matched by name, not slug: wp_set_post_tags() creates the
        // ones that do not exist, which is the behaviour editors expect of tags
        // and do not expect of categories.
        if ($input->filled('tags')) {
            wp_set_post_tags($postId, $input->stringList('tags'));
        }

        // Meta goes through PostMeta, which accepts only declared fields and
        // reports what it refused. That error is deliberately allowed to
        // surface: a field the caller believes it set, and did not, is worse
        // than a failed call.
        $written = PostMeta::write($postId, $input->map('meta'));

        return is_wp_error($written) ? $written : null;
    }

    /**
     * Render a post in full, keeping the body of a password-protected one back.
     *
     * Same rule as the REST API: the content and excerpt are emptied unless the
     * caller may edit the post or supplies its password, and the record says the
     * post is protected. The password itself is never part of the record.
     *
     * @param \WP_Post $post     The post to render.
     * @param string   $password Password the caller supplied, possibly empty.
     *
     * @return array<string, mixed> The record.
     */
    private static function protectedRecord(\WP_Post $post, string $password): array
    {
        $record = PostFormatter::full($post);
        $record['password_protected'] = $post->post_password !== '';

        if ($record['password_protected'] && ! self::mayReadProtected($post, $password)) {
            $record['content'] = '';
            $record['excerpt'] = '';
        }

        return $record;
    }

    /**
     * Whether the current user may see the body of a password-protected post.
     *
     * @param \WP_Post $post     The protected post.
     * @param string   $password Password the caller supplied, possibly empty.
     *
     * @return bool True for editors of the post and callers holding the password.
     */
    private static function mayReadProtected(\WP_Post $post, string $password): bool
    {
        return current_user_can('edit_post', $post->ID)
            || ($password !== '' && hash_equals($post->post_password, $password));
    }

    /**
     * Check everything a write could be refused for, before anything is written.
     *
     * Runs ahead of `wp_insert_post()` and `wp_update_post()` so that a refusal
     * never leaves a half-written post behind: WordPress itself checks neither
     * the publish capability nor the term capabilities on those functions.
     *
     * @param Input       $input         The ability input.
     * @param string      $postType      Type of the post being written.
     * @param string|null $status        Status the caller asked for, null when not changing it.
     * @param string|null $currentStatus Status the post has now, null for a new post.
     * @param int|null    $postId        The post being updated, null for a new post.
     *
     * @return list<int>|null|\WP_Error The category IDs to assign (null when none were asked for), or the refusal.
     */
    private static function preflight(Input $input, string $postType, ?string $status, ?string $currentStatus, ?int $postId): array|\WP_Error|null
    {
        if (in_array($status, ['publish', 'future', 'private'], true) && $status !== $currentStatus) {
            $object = get_post_type_object($postType);

            if (! $object instanceof \WP_Post_Type || ! current_user_can($object->cap->publish_posts)) {
                return Failure::forbidden(sprintf(
                    'give a "%s" the status "%s": that takes the capability to publish this post type',
                    $postType,
                    $status,
                ));
            }
        }

        // Meta is validated here, ahead of the write, so that a refused field
        // does not leave a post saved with the rest of the request applied.
        $metaChecked = PostMeta::check($postType, $input->map('meta'), $postId);

        if ($metaChecked instanceof \WP_Error) {
            return $metaChecked;
        }

        $categories = null;

        // `has()` rather than `filled()`: an empty list on an update means
        // "remove every category". On creation it carries no instruction, and
        // the default category WordPress just assigned is left alone.
        if ($input->filled('categories') || ($postId !== null && $input->has('categories'))) {
            $denied = self::requireTermCapability('category', 'assign_terms', 'assign categories');

            if ($denied instanceof \WP_Error) {
                return $denied;
            }

            $resolved = self::resolveTermSlugs($input->stringList('categories'), 'category', false);

            if (is_wp_error($resolved)) {
                return $resolved;
            }

            $categories = $resolved;
        }

        if ($input->filled('tags')) {
            $denied = self::requireTermCapability('post_tag', 'assign_terms', 'assign tags');

            if ($denied instanceof \WP_Error) {
                return $denied;
            }

            foreach ($input->stringList('tags') as $name) {
                if (! term_exists($name, 'post_tag')) {
                    $denied = self::requireTermCapability('post_tag', 'edit_terms', 'create tags');

                    if ($denied instanceof \WP_Error) {
                        return $denied;
                    }

                    break;
                }
            }
        }

        return $categories;
    }

    /**
     * Refuse unless the current user holds one of a taxonomy's capabilities.
     *
     * @param string $taxonomy   Taxonomy slug.
     * @param string $capability Key of the taxonomy's `cap` object, such as `assign_terms` or `edit_terms`.
     * @param string $action     What is being attempted, phrased as an infinitive without "to".
     *
     * @return \WP_Error|null The refusal, null when allowed.
     */
    private static function requireTermCapability(string $taxonomy, string $capability, string $action): ?\WP_Error
    {
        $object = get_taxonomy($taxonomy);

        if (! $object instanceof \WP_Taxonomy) {
            return Failure::invalid(sprintf(
                'Unknown taxonomy "%s". Call get-taxonomies to see what this site registers.',
                $taxonomy,
            ));
        }

        return current_user_can($object->cap->{$capability}) ? null : Failure::forbidden($action);
    }

    /**
     * Build a `tax_query` from the generic `terms` map.
     *
     * The map is taxonomy slug to a list of term slugs. Unknown taxonomies are
     * an error rather than a silent no-op: a filter that quietly does nothing
     * returns the unfiltered set, and a caller has no way to tell that apart
     * from "everything matched".
     *
     * @param Input $input The ability input.
     *
     * @return array<int|string, mixed>|\WP_Error The tax_query, empty when no filter was asked for.
     */
    private static function buildTaxQuery(Input $input): array|\WP_Error
    {
        $requested = $input->map('terms');

        if ($requested === []) {
            return [];
        }

        $clauses = [];
        $unknown = [];

        foreach ($requested as $taxonomy => $slugs) {
            $taxonomy = (string) $taxonomy;

            if (! taxonomy_exists($taxonomy)) {
                $unknown[] = $taxonomy;

                continue;
            }

            $slugs = array_values(array_filter(
                array_map(
                    static fn (mixed $slug): string => is_scalar($slug) ? trim((string) $slug) : '',
                    (array) $slugs,
                ),
                static fn (string $slug): bool => $slug !== '',
            ));

            if ($slugs === []) {
                continue;
            }

            $clauses[] = [
                'taxonomy' => $taxonomy,
                'field' => 'slug',
                'terms' => $slugs,
            ];
        }

        if ($unknown !== []) {
            return Failure::invalid(sprintf(
                'Unknown taxonomy: %s. Call get-taxonomies to see what this site registers.',
                implode(', ', $unknown),
            ));
        }

        if ($clauses === []) {
            return [];
        }

        // The relation only means anything with two or more clauses, and WP_Query
        // warns when it is set on a single one.
        if (count($clauses) > 1) {
            $clauses['relation'] = $input->boolean('terms_match_all') ? 'AND' : 'OR';
        }

        return $clauses;
    }

    /**
     * Turn a list of term slugs into term IDs.
     *
     * @param list<string> $slugs         Slugs to resolve.
     * @param string       $taxonomy      Taxonomy to resolve them in.
     * @param bool         $createMissing Whether to create the terms that do not exist.
     *
     * @return list<int>|\WP_Error Term IDs, or an error naming the slugs that could not be resolved.
     */
    private static function resolveTermSlugs(array $slugs, string $taxonomy, bool $createMissing): array|\WP_Error
    {
        $ids = [];
        $missing = [];

        foreach ($slugs as $slug) {
            $term = get_term_by('slug', $slug, $taxonomy);

            if ($term instanceof \WP_Term) {
                $ids[] = $term->term_id;
            } else {
                $missing[] = $slug;
            }
        }

        if ($missing === []) {
            return $ids;
        }

        if (! $createMissing) {
            return Failure::invalid(sprintf(
                'No term in "%s" matches these slugs: %s. Call get-terms to list them, or pass create_missing=true.',
                $taxonomy,
                implode(', ', $missing),
            ));
        }

        // Everything that can be refused is refused before the first term is
        // created: the capability, once, for the whole batch.
        $denied = self::requireTermCapability(
            $taxonomy,
            'edit_terms',
            sprintf('create terms in the "%s" taxonomy', $taxonomy),
        );

        if ($denied instanceof \WP_Error) {
            return $denied;
        }

        $created = [];

        foreach (array_unique($missing) as $slug) {
            $term = wp_insert_term($slug, $taxonomy);

            if (is_wp_error($term)) {
                // WordPress can still refuse one term (a name already used by
                // another slug, say). Take back the ones this call made, so the
                // failure leaves the taxonomy as it found it.
                foreach ($created as $termId) {
                    wp_delete_term($termId, $taxonomy);
                }

                return Failure::fromWordPress($term, sprintf('create the term "%s"', $slug));
            }

            $created[] = (int) $term['term_id'];
            $ids[] = (int) $term['term_id'];
        }

        return $ids;
    }
}

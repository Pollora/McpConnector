<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities\Group;

use Pollora\McpConnector\Abilities\AbilityCategory;
use Pollora\McpConnector\Abilities\AbilityDefinition;
use Pollora\McpConnector\Abilities\AbilityGroup;
use Pollora\McpConnector\Abilities\Annotations;
use Pollora\McpConnector\Abilities\Input;
use Pollora\McpConnector\Abilities\Schema;
use Pollora\McpConnector\Support\Failure;
use WP_Error;
use WP_Term;

defined('ABSPATH') || exit;

/**
 * Reading and writing taxonomies and their terms.
 *
 * Terms are addressed by slug throughout, because slugs are stable, readable and
 * are what the content abilities accept. Identifiers are returned as well, for
 * the cases where a caller needs to disambiguate two terms sharing a name across
 * taxonomies.
 */
final class TaxonomyGroup implements AbilityGroup
{
    /**
     * Stable group key stored in the settings option.
     *
     * @var string
     */
    public const KEY = 'taxonomy';

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
        return __('Taxonomies and terms', 'amphibee-mcp-connector');
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return __(
            'List taxonomies, read their terms, and create or delete terms.',
            'amphibee-mcp-connector'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function definitions(): array
    {
        return [
            $this->listTaxonomies(),
            $this->listTerms(),
            $this->createTerm(),
            $this->deleteTerm(),
        ];
    }

    /**
     * List the taxonomies registered on the site.
     *
     * @return AbilityDefinition The ability.
     */
    private function listTaxonomies(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-taxonomies',
            label: __('List taxonomies', 'amphibee-mcp-connector'),
            description: 'List the taxonomies this site registers, with the post types each applies to. '
                . 'Call this before assigning terms, so you use a taxonomy that exists here.',
            category: AbilityCategory::Taxonomy,
            inputSchema: Schema::object([
                'public_only' => Schema::boolean('Return only publicly queryable taxonomies.', true),
            ]),
            execute: static function (Input $input): array {
                $args = $input->boolean('public_only', true) ? ['public' => true] : [];

                $taxonomies = array_map(
                    static fn (\WP_Taxonomy $taxonomy): array => [
                        'name' => $taxonomy->name,
                        'label' => $taxonomy->label,
                        'hierarchical' => $taxonomy->hierarchical,
                        'post_types' => array_values($taxonomy->object_type),
                    ],
                    array_values(get_taxonomies($args, 'objects'))
                );

                return ['taxonomies' => $taxonomies];
            },
            permission: static fn (): bool => current_user_can('edit_posts'),
        );
    }

    /**
     * List the terms of one taxonomy.
     *
     * @return AbilityDefinition The ability.
     */
    private function listTerms(): AbilityDefinition
    {
        return AbilityDefinition::reading(
            slug: 'get-terms',
            label: __('List terms', 'amphibee-mcp-connector'),
            description: 'List the terms of a taxonomy, with their slugs, post counts and parents. '
                . 'Slugs from this list are what the content abilities expect.',
            category: AbilityCategory::Taxonomy,
            inputSchema: Schema::object([
                'taxonomy' => Schema::string('Taxonomy slug, such as category or post_tag.', 'category'),
                'search' => Schema::string('Match terms whose name contains this text.'),
                'hide_empty' => Schema::boolean('Skip terms that are not assigned to any post.', false),
                'number' => Schema::integer('How many terms to return, at most 200.', 50, 1, 200),
            ]),
            execute: static function (Input $input): array|WP_Error {
                $taxonomy = $input->string('taxonomy', 'category');

                if (! taxonomy_exists($taxonomy)) {
                    return Failure::invalid(sprintf(
                        'Unknown taxonomy "%s". Call get-taxonomies to see what this site registers.',
                        $taxonomy
                    ));
                }

                $args = [
                    'taxonomy' => $taxonomy,
                    'hide_empty' => $input->boolean('hide_empty'),
                    'number' => $input->integer('number', 50, 1, 200),
                ];

                if ($input->filled('search')) {
                    $args['search'] = $input->string('search');
                }

                $terms = get_terms($args);

                if (is_wp_error($terms)) {
                    return Failure::fromWordPress($terms, 'list the terms');
                }

                return [
                    'taxonomy' => $taxonomy,
                    'terms' => array_map(
                        static fn (WP_Term $term): array => [
                            'id' => $term->term_id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                            'description' => $term->description,
                            'parent' => $term->parent,
                            'count' => $term->count,
                        ],
                        array_values($terms)
                    ),
                ];
            },
            permission: static fn (): bool => current_user_can('edit_posts'),
        );
    }

    /**
     * Create a term.
     *
     * @return AbilityDefinition The ability.
     */
    private function createTerm(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'create-term',
            label: __('Create a term', 'amphibee-mcp-connector'),
            description: 'Create a term in a taxonomy. Fails if a term with the same name already exists '
                . 'under the same parent.',
            category: AbilityCategory::Taxonomy,
            inputSchema: Schema::object([
                'taxonomy' => Schema::string('Taxonomy to create the term in.', 'category'),
                'name' => Schema::string('Term name, as it should be displayed.'),
                'slug' => Schema::string('URL slug. Derived from the name when omitted.'),
                'description' => Schema::string('Term description.'),
                'parent' => Schema::integer('Parent term ID, for hierarchical taxonomies.', minimum: 0),
            ], ['name']),
            execute: static function (Input $input): array|WP_Error {
                $taxonomy = $input->string('taxonomy', 'category');

                if (! taxonomy_exists($taxonomy)) {
                    return Failure::invalid(sprintf(
                        'Unknown taxonomy "%s". Call get-taxonomies to see what this site registers.',
                        $taxonomy
                    ));
                }

                $args = [];

                if ($input->filled('slug')) {
                    $args['slug'] = sanitize_title($input->string('slug'));
                }

                if ($input->filled('description')) {
                    $args['description'] = $input->string('description');
                }

                if ($input->id('parent') > 0) {
                    $args['parent'] = $input->id('parent');
                }

                $created = wp_insert_term($input->string('name'), $taxonomy, $args);

                if (is_wp_error($created)) {
                    return Failure::fromWordPress($created, 'create the term');
                }

                $term = get_term((int) $created['term_id'], $taxonomy);

                return $term instanceof WP_Term
                    ? [
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'taxonomy' => $term->taxonomy,
                        'parent' => $term->parent,
                    ]
                    : Failure::notFound('term', (int) $created['term_id']);
            },
            permission: static function (Input $input): bool {
                $taxonomy = get_taxonomy($input->string('taxonomy', 'category'));

                return $taxonomy !== false && current_user_can($taxonomy->cap->manage_terms);
            },
        );
    }

    /**
     * Delete a term.
     *
     * @return AbilityDefinition The ability.
     */
    private function deleteTerm(): AbilityDefinition
    {
        return AbilityDefinition::writing(
            slug: 'delete-term',
            label: __('Delete a term', 'amphibee-mcp-connector'),
            description: 'Delete a term. The posts assigned to it are not deleted; they simply lose the '
                . 'term. This cannot be undone.',
            category: AbilityCategory::Taxonomy,
            inputSchema: Schema::object([
                'id' => Schema::integer('ID of the term to delete.', minimum: 1),
                'taxonomy' => Schema::string('Taxonomy the term belongs to.', 'category'),
            ], ['id']),
            execute: static function (Input $input): array|WP_Error {
                $termId = $input->id('id');
                $taxonomy = $input->string('taxonomy', 'category');

                if (! get_term($termId, $taxonomy) instanceof WP_Term) {
                    return Failure::notFound('term', $termId);
                }

                $result = wp_delete_term($termId, $taxonomy);

                if (is_wp_error($result)) {
                    return Failure::fromWordPress($result, 'delete the term');
                }

                if ($result === false) {
                    return Failure::invalid(sprintf('WordPress refused to delete term %d.', $termId));
                }

                return ['deleted' => true, 'id' => $termId, 'taxonomy' => $taxonomy];
            },
            permission: static function (Input $input): bool {
                $taxonomy = get_taxonomy($input->string('taxonomy', 'category'));

                return $taxonomy !== false && current_user_can($taxonomy->cap->delete_terms);
            },
            annotations: Annotations::deletes(),
        );
    }
}

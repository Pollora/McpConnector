<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Abilities;

use Pollora\Abilities\Domain\Model\AbilityCategory as PackageCategory;

defined('ABSPATH') || exit;

/**
 * The categories abilities are filed under.
 *
 * Ability category slugs are global to the install, so registering a bare
 * `content` would collide with any other plugin that had the same idea — and a
 * collision is not benign: the second registration is refused and every ability
 * pointing at it fails to register. Categories are therefore namespaced with the
 * configured ability prefix at registration time, while groups keep referring to
 * the short keys below.
 */
enum AbilityCategory: string
{
    /** Posts, pages and any other post type. */
    case Content = 'content';

    /** Categories, tags and custom taxonomy terms. */
    case Taxonomy = 'taxonomy';

    /** Attachments and the media library. */
    case Media = 'media';

    /** Navigation menus and their items. */
    case Navigation = 'navigation';

    /** User accounts. */
    case Users = 'users';

    /** Site-wide information and options. */
    case Site = 'site';

    /**
     * Build the package category this one maps to, under the given namespace.
     *
     * Category slugs are global to the install, so the configured ability
     * namespace is prefixed here rather than in the enum: the same six
     * categories have to be able to coexist with another site's.
     *
     * @param string $namespace The configured ability namespace, e.g. `wp-mcp`.
     *
     * @return PackageCategory The category, ready to register.
     */
    public function toPackageCategory(string $namespace): PackageCategory
    {
        return PackageCategory::make(
            $namespace . '-' . $this->value,
            $this->label(),
            $this->description(),
        );
    }

    /**
     * Human-readable label shown wherever categories are listed.
     *
     * @return string The translated label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Content => __('Content', 'amphibee-mcp-connector'),
            self::Taxonomy => __('Taxonomy', 'amphibee-mcp-connector'),
            self::Media => __('Media', 'amphibee-mcp-connector'),
            self::Navigation => __('Navigation', 'amphibee-mcp-connector'),
            self::Users => __('Users', 'amphibee-mcp-connector'),
            self::Site => __('Site', 'amphibee-mcp-connector'),
        };
    }

    /**
     * Description of what belongs in the category.
     *
     * @return string The translated description.
     */
    public function description(): string
    {
        return match ($this) {
            self::Content => __('Read and write posts, pages and other post types.', 'amphibee-mcp-connector'),
            self::Taxonomy => __('Read and write categories, tags and custom taxonomy terms.', 'amphibee-mcp-connector'),
            self::Media => __('Read and write media library attachments.', 'amphibee-mcp-connector'),
            self::Navigation => __('Read and write navigation menus and their items.', 'amphibee-mcp-connector'),
            self::Users => __('Read user accounts.', 'amphibee-mcp-connector'),
            self::Site => __('Read site information and update a restricted set of options.', 'amphibee-mcp-connector'),
        };
    }
}

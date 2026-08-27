<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Formatting;

defined('ABSPATH') || exit;

/**
 * Reports the custom fields a post type has, including those owned by a field
 * framework rather than declared to WordPress.
 *
 * There are two kinds of custom field on a typical site, and only one of them is
 * visible to core. Fields passed to `register_post_meta()` announce themselves;
 * fields defined inside Meta Box, ACF or a similar builder do not, because the
 * framework keeps its own registry and reads the values directly.
 *
 * That distinction is invisible to an editor and fatal to a model. A directory
 * listing on this site has a `telephone` field, and a client given a Meta Box
 * ability *can* read it — but only if it already knows the key. Nothing in the
 * tool surface would ever tell it the key exists: `register_post_meta()` was
 * never called, and Meta Box's own `list-fields` covers only groups created
 * through its interface, not those declared in code.
 *
 * So this fills the gap in the one place it matters, discovery, while leaving
 * reading and writing to the framework — which knows that `donnees_verifiees` is
 * a switch and `site_web` a URL, and will coerce values accordingly.
 *
 * The mechanism is a filter; the Meta Box reader is shipped because that plugin
 * is common enough to be worth handling out of the box. A site using something
 * else adds its own reader in a few lines rather than waiting for this plugin to
 * learn about it.
 */
final class FieldDiscovery
{
    /**
     * Filter through which additional field sources are declared.
     *
     * A source is a callable taking a post type slug and returning a list of
     * `['key' => string, 'label' => string, 'type' => string]` entries.
     *
     * @var string
     */
    public const SOURCES_FILTER = 'mcp_connector_field_sources';

    /**
     * Not instantiable: every member is a static helper.
     */
    private function __construct()
    {
    }

    /**
     * The framework-owned fields of a post type.
     *
     * @param string $postType Post type slug.
     *
     * @return list<array<string, mixed>> One entry per discovered field.
     */
    public static function frameworkFields(string $postType): array
    {
        /** @var array<string, callable(string): list<array<string, mixed>>> $sources */
        $sources = apply_filters(self::SOURCES_FILTER, [
            'meta-box' => self::metaBoxFields(...),
        ]);

        $fields = [];

        foreach ($sources as $provider => $source) {
            if (! is_callable($source)) {
                continue;
            }

            foreach ($source($postType) as $field) {
                $field['source'] = (string) $provider;
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Read the fields Meta Box knows about for a post type.
     *
     * Works for groups declared in code through the `rwmb_meta_boxes` filter as
     * well as those built in the interface, because it asks Meta Box's runtime
     * registry rather than its stored definitions.
     *
     * @param string $postType Post type slug.
     *
     * @return list<array<string, mixed>> One entry per field.
     */
    private static function metaBoxFields(string $postType): array
    {
        if (! function_exists('rwmb_get_object_fields')) {
            return [];
        }

        $fields = rwmb_get_object_fields($postType);

        if (! is_array($fields)) {
            return [];
        }

        $described = [];

        foreach ($fields as $key => $definition) {
            $key = (string) $key;

            // Layout-only entries — headings, dividers, tabs — carry no id and
            // hold no value. Reporting them as fields would invite a client to
            // try writing to them.
            if ($key === '' || ! is_array($definition)) {
                continue;
            }

            $type = (string) ($definition['type'] ?? '');

            if (in_array($type, ['heading', 'divider', 'tab', 'custom_html'], true)) {
                continue;
            }

            $entry = [
                'key' => $key,
                'label' => (string) ($definition['name'] ?? $key),
                'type' => $type,
            ];

            // A closed set of values is the single most useful thing to know
            // about a field before writing to it.
            if (is_array($definition['options'] ?? null) && $definition['options'] !== []) {
                $entry['options'] = $definition['options'];
            }

            $described[] = $entry;
        }

        return $described;
    }
}

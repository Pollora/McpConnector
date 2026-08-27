<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Server;

use Pollora\McpConnector\Settings;
use WP_Ability;

defined('ABSPATH') || exit;

/**
 * Discovers and curates abilities registered by other plugins.
 *
 * The reason this exists is that the ecosystem is better at describing its own
 * content than this plugin could ever be. Meta Box already knows that
 * `professionnel_sante.conventionnement` is a select field labelled
 * "Conventionnement", and already publishes abilities to read and write it.
 * Re-deriving that by sampling the database would produce a worse answer.
 *
 * What this plugin adds is transport and judgement: putting those abilities in
 * the same MCP server as its own, and deciding which of them a chat model should
 * be handed. Those are different questions — a provider marking an ability
 * `mcp.public` means "this is fit to expose over MCP", not "expose this to
 * anyone who connects".
 */
final class ExternalAbilities
{
    /**
     * Filter through which the published third-party ability list can be adjusted.
     *
     * @var string
     */
    public const SELECTION_FILTER = 'mcp_connector_external_abilities';

    /**
     * Filter through which a provider profile can be declared.
     *
     * @var string
     */
    public const PROFILES_FILTER = 'mcp_connector_provider_profiles';

    /**
     * Namespaces this plugin will never publish, whatever the configuration says.
     *
     * `mcp-adapter` provides `execute-ability`, which invokes *any* registered
     * ability by name. Publishing it would not add one tool, it would silently
     * add every tool on the site and make every choice on the settings screen
     * decorative. That is a structural problem, not a matter of taste, so it is
     * not configurable.
     *
     * Our own namespace is excluded because it is published separately, in full.
     *
     * @var list<string>
     */
    private const NEVER_PUBLISH = ['mcp-adapter'];

    /**
     * @param Settings $settings Resolved plugin configuration.
     */
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Every third-party ability that could be published, grouped by provider.
     *
     * Ordered with read-only abilities first within each provider, since those
     * are the ones an administrator is most likely to want and least likely to
     * hesitate over.
     *
     * @return array<string, list<ExternalAbility>> Provider namespace to its abilities.
     */
    public function available(): array
    {
        $grouped = [];

        foreach (wp_get_abilities() as $ability) {
            if (! $ability instanceof WP_Ability || ! $this->isPublishable($ability)) {
                continue;
            }

            $described = ExternalAbility::fromAbility($ability);
            $grouped[$described->provider][] = $described;
        }

        foreach ($grouped as &$abilities) {
            usort(
                $abilities,
                static fn (ExternalAbility $a, ExternalAbility $b): int
                    => [$b->isSafeByDefault(), $a->name] <=> [$a->isSafeByDefault(), $b->name]
            );
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * The third-party abilities to publish.
     *
     * @return list<string> Fully-qualified ability names.
     */
    public function selected(): array
    {
        $grouped = $this->available();

        $available = [];

        foreach ($grouped as $abilities) {
            foreach ($abilities as $ability) {
                $available[] = $ability->name;
            }
        }

        // Until somebody has actually reviewed the list, fall back to the
        // recommended defaults, so a fresh install publishes something useful
        // without a configuration step. After a review the stored list wins,
        // empty included — see the note on the setting.
        if ($this->settings->externalAbilitiesReviewed) {
            $stored = $this->settings->externalAbilities;
        } else {
            $stored = [];

            foreach (array_keys($grouped) as $provider) {
                $stored = array_merge($stored, $this->defaultsFor($provider));
            }
        }

        /** @var list<string> $selected */
        $selected = apply_filters(self::SELECTION_FILTER, $stored, $available);

        // Intersecting rather than trusting the stored list matters: an ability
        // whose provider has since been deactivated must not be handed to
        // create_server(), which rejects the whole server if one tool is unknown.
        return array_values(array_intersect($selected, $available));
    }

    /**
     * The default selection for a provider whose abilities have never been reviewed.
     *
     * Two sources, in order. A shipped profile, when one exists for the provider —
     * that is how "read plus value writes" is achieved for Meta Box without
     * inventing a rule that pretends to generalise. Otherwise the only trustworthy
     * signal, an explicit `readonly` annotation.
     *
     * @param string $provider Provider namespace.
     *
     * @return list<string> Ability names to select by default.
     */
    public function defaultsFor(string $provider): array
    {
        $abilities = $this->available()[$provider] ?? [];
        $profile = self::profiles()[$provider] ?? null;

        if (is_array($profile)) {
            $names = array_map(static fn (ExternalAbility $a): string => $a->name, $abilities);

            return array_values(array_intersect($profile, $names));
        }

        return array_values(array_map(
            static fn (ExternalAbility $a): string => $a->name,
            array_filter($abilities, static fn (ExternalAbility $a): bool => $a->isSafeByDefault())
        ));
    }

    /**
     * Recommended selections for providers this plugin knows something about.
     *
     * A profile is data, not logic: a list of ability names a human decided were
     * value-level rather than structural. That distinction cannot be computed —
     * see {@see ExternalAbility} — but it can be written down, and written down
     * it stays honest, because it fails visibly when a provider renames something
     * rather than silently misclassifying it.
     *
     * The filter is the extension point: any plugin may declare a profile for
     * itself or for a provider it knows.
     *
     * @return array<string, list<string>> Provider namespace to recommended ability names.
     */
    public static function profiles(): array
    {
        /** @var array<string, list<string>> $profiles */
        $profiles = apply_filters(self::PROFILES_FILTER, [
            // Meta Box publishes reading, value writing and structure editing
            // side by side. Reading a field definition tells a model what
            // `conventionnement` means; creating and deleting field groups
            // rewrites the site's content model, which is not something a chat
            // should reach for. Values and definitions in, structure out.
            'meta-box' => [
                'meta-box/get-field-value',
                'meta-box/get-field-value-format',
                'meta-box/update-field-value',
                'meta-box/delete-field-value',
                'meta-box/list-fields',
                'meta-box/get-field',
                'meta-box/list-field-groups',
                'meta-box/get-field-group',
                'meta-box/get-post-types',
                'meta-box/get-taxonomies',
            ],
        ]);

        return $profiles;
    }

    /**
     * Whether an ability is a candidate for publication at all.
     *
     * @param WP_Ability $ability The ability.
     *
     * @return bool True when it may be offered on the settings screen.
     */
    private function isPublishable(WP_Ability $ability): bool
    {
        $name = $ability->get_name();
        $provider = explode('/', $name)[0];

        if ($provider === $this->settings->abilityNamespace) {
            return false;
        }

        if (in_array($provider, self::NEVER_PUBLISH, true)) {
            return false;
        }

        $meta = $ability->get_meta();

        // A provider opting an ability out of MCP is a decision we respect
        // rather than second-guess.
        if (empty($meta['mcp']['public'])) {
            return false;
        }

        return ($meta['mcp']['type'] ?? 'tool') === 'tool';
    }
}

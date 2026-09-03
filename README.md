# MCP Connector

Exposes WordPress content management as [Model Context Protocol](https://modelcontextprotocol.io)
tools, with a built-in OAuth 2.1 provider so a remote client such as Claude can
connect to the site by URL.

The plugin is deliberately generic: nothing in it is specific to one site. What
it publishes is configurable, what it refuses is enforced in two independent
layers, and the ability set is extensible through a filter.

---

## What it gives a client

27 first-class MCP tools, each with its own input schema and behaviour
annotations, grouped into six sets that can be switched on and off
independently:

| Group | Tools |
|---|---|
| **Posts and pages** | `get-posts`, `get-post`, `get-pages`, `create-post`, `update-post`, `delete-post`, `set-post-terms` |
| **Taxonomies and terms** | `get-taxonomies`, `get-terms`, `create-term`, `delete-term` |
| **Media library** | `get-media`, `upload-media-from-url`, `update-media`, `set-featured-image`, `delete-media` |
| **Navigation menus** | `get-menus`, `get-menu-items`, `get-menu-locations`, `create-menu`, `add-menu-item`, `update-menu-item`, `delete-menu-item`, `assign-menu-location` |
| **Users** *(off by default)* | `get-users`, `get-user` |
| **Site information** | `get-site-info`, `get-post-types`, `update-option` |

`get-posts` filters by any taxonomy through a generic `terms` map
(`{"annuaire_categorie": ["sante"]}`), not just categories and tags.

Tools are published individually rather than behind the MCP Adapter's default
`discover-abilities` / `execute-ability` pair, so a client sees every tool and
its schema in its first `tools/list` and can call one directly.

### Plus whatever else the site already publishes

Other plugins register abilities too, and re-deriving what they already know
would produce a worse answer. Meta Box knows that a directory listing's
`conventionnement` is a select field labelled "Conventionnement"; sampling the
database for that would be guesswork. So the connector republishes third-party
abilities in the same server — one connector, not one per plugin — with each
provider's own permission callbacks intact.

They are curated per ability, not per plugin, because the interesting line runs
*inside* a provider. Meta Box offers reading a field value and deleting a field
group under one namespace, one category, and for several of them identical
annotations. Nothing in the Abilities API separates "sets a value" from
"rewrites the content model", so the choice is explicit — see
**Third-party abilities** below.

---

## Requirements

| | |
|---|---|
| PHP | 8.3 or later |
| WordPress | 6.9 or later, for the Abilities API in core |
| [`pollora/abilities`](https://github.com/Pollora/abilities) | Installed by Composer. Owns the Abilities API primitives — the ability model, the JSON Schema builder, the input reader and the registration adapters. |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) | Required for the MCP endpoint. **Not on wordpress.org**, so it cannot be installed from the plugin screen: take the zip from GitHub, or `composer require wordpress/mcp-adapter` — see [The MCP Adapter over Composer](#the-mcp-adapter-over-composer). |
| Pretty permalinks | Required |
| HTTPS | Required by remote clients; plain HTTP is workable only locally |

Without the MCP Adapter the abilities still register and remain reachable
through the core abilities REST controllers; only the MCP endpoint is missing.
Without the Abilities API the plugin registers nothing but the OAuth provider
keeps working.

**There is deliberately no `Requires Plugins: mcp-adapter` header.** The header
would gate activation correctly — WordPress resolves the slug against installed
directory names, not against wordpress.org — but the "Install now" link it
offers for a missing dependency queries wordpress.org, where the adapter is not.
Anyone installing this plugin from the directory would meet a refusal to activate
followed by a link that leads nowhere, and a dead end is worse than a missing
guard rail. The dependency is enforced where it can also be explained: the
dashboard states what is missing and prints the two commands that fix it.

The cost is worth knowing: WordPress no longer refuses to deactivate the adapter
while this plugin runs, so deactivating it silently removes the MCP endpoint.

### The web server must not block `/.well-known/`

This is the single most common reason a connection fails, and it fails
invisibly — the client reports an unreachable server, and nothing appears in any
WordPress log, because the request never reaches PHP.

RFC 8414 and RFC 9728 pin the two discovery documents to the site root, and the
usual "deny all hidden files" rule blocks them:

```nginx
# Wrong — also blocks /.well-known/
location ~ /\. { deny all; }

# Right — RFC 8615 carve-out
location ~* /\.(?!well-known\/) { deny all; }
```

Verify with:

```bash
curl -s https://example.com/.well-known/oauth-protected-resource
curl -s https://example.com/.well-known/oauth-authorization-server
```

Both must return JSON. The settings screen probes them itself and prints the
nginx line to change when they do not. Mirrors are served at `/wp-json/mcp-connector/v1/protected-resource` and
`/wp-json/mcp-connector/v1/server-metadata` for diagnosis, but a conforming
client will only look at the canonical paths.

### Behind a CDN

`/oauth/authorize` is a dotless GET path, which is what a "cache HTML pages"
edge rule matches. It is only ever served to signed-in users, so a rule that
excludes session cookies already skips it, and the responses carry `no-store`.
But a cache rule with an explicit edge TTL overrides origin headers — on such a
setup, exclude this path explicitly. A cached authorization redirect carries
somebody else's one-time code.

---

## Installing with Composer

The package declares `"type": "wordpress-plugin"`, so with
[composer/installers](https://github.com/composer/installers) present in the
consuming project it lands in `wp-content/plugins/` rather than in `vendor/`.

It is published on [Packagist](https://packagist.org/packages/pollora/mcp-connector),
so the requirement is all a project needs:

```bash
composer require pollora/mcp-connector
```

Which leaves the consuming project declaring both halves:

```json
{
    "require": {
        "composer/installers": "^2.0",
        "pollora/mcp-connector": "^1.2"
    }
}
```

`composer/installers` is required by the project, not by this package: adding it
here would pull a Composer *plugin* into the tree, which is a different kind of
dependency from the one library this package does require.

### The MCP Adapter over Composer

The adapter is on Packagist as `wordpress/mcp-adapter`, typed
`wordpress-plugin`, so a project already installing this plugin with Composer
can install that one the same way rather than downloading it by hand:

```bash
composer require wordpress/mcp-adapter
```

It is not required from here. The adapter is needed at runtime, not to build
this package, and a site is free to install it from the zip instead; declaring
it would take that choice away and pin a version this plugin has no reason to
have an opinion about.

One thing a Composer install of the adapter does need. Its bootstrap looks for
a Jetpack autoloader under its own plugin directory, and a package installed by
Composer has no `vendor/` there — the dependencies land in the project's. The
adapter then shows a notice and returns without booting. It reads a constant
for exactly this case, which the project sets before WordPress loads plugins:

```php
define( 'WP_MCP_AUTOLOAD', false );
```

Its classes are already in the project's autoloader at that point, so this only
tells it to stop looking for a second one.

### The bundled dependency is not prefixed

The release zip carries `pollora/abilities` in `vendor/` — 416 KB, one package,
no transitive dependencies. Nothing in the wordpress.org guidelines forbids that:
the only rules that bear on it are GPL compatibility, which MIT satisfies, and
the ban on shipping libraries WordPress itself bundles, which this is not.

What it does mean is that a site running both this plugin and the Pollora
framework loads two copies of `Pollora\Abilities\`, and PHP's class namespace is
global — the first autoloader to register wins, and the other side silently runs
against a version it did not choose. That is accepted rather than solved: the
namespace belongs to us on both sides, and a `^1.0` constraint keeps them
compatible.

Revisit it if this plugin ships to the wordpress.org directory, where it would
sit next to plugins nobody here controls. The fix is a build step, not a code
change: run [PHP-Scoper](https://github.com/humbug/php-scoper) or
[Strauss](https://github.com/BrianHenryIE/strauss) in the release workflow to
rewrite the bundled namespace, leaving the source untouched.

---

## Connecting a client

Activate the plugin and open **Settings → MCP Connector**. Until some client
holds a live access token, that address sends you to the **setup assistant** — a
screen of its own that takes the whole window, with no admin menu, no admin bar
and no notices. Setting up a connector is a thing done once, with an end; the
surrounding chrome offers nothing that helps and several ways to abandon it
halfway. The only way out is a link that says so.

The assistant walks the chain in the order it has to happen:

1. **Preparation** — every environmental requirement, each failure stating the
   literal change that fixes it. This step blocks: if the discovery documents do
   not answer, there is no point handing anybody a URL.
2. **What it may do** — read-only mode and self-registration, the two decisions
   worth making before a client sees the site.
3. **Hand over the URL** — the MCP server URL, of the form
   `https://example.com/wp-json/mcp/connector`, and a walkthrough per client.
   Claude registers itself and needs nothing pasted back; a client that cannot
   wants an identifier and secret created here first.
4. **Verify** — the end-to-end test below.

It steps aside as soon as any client holds a live access token — a client that
merely registered is not enough, since a client can introduce itself and then
abandon the flow. From then on **Settings → MCP Connector** opens on six panels:
Dashboard, Connect, Tools, Security, Applications, Advanced. The assistant can be
brought back from the dashboard at any time.

### The end-to-end test

The dashboard's checks establish what can be established from PHP. What they
cannot establish is whether the seven steps *compose* — whether the code the
authorization endpoint issues is one the token endpoint will accept, whether that
token is one the MCP transport will honour. So the test does the real thing:
registers an application over HTTP, authorises it as the administrator running
it, redeems the code, calls `initialize` and `tools/list` with the resulting
token, and reports which step stopped.

Two consequences worth knowing. The requests are loopback HTTP rather than direct
calls into the controllers — a direct call would prove the PHP works while saying
nothing about the web server in front of it, which is where the failure usually
is. And the run creates a real client and a real token, so it deletes both when
it finishes, on the failure path as well as the success path.

---

## Security model

Three independent layers. Each assumes the others may be wrong.

**Capabilities.** Every ability checks the capability for the specific object it
touches — `edit_post` on the post being edited, not a blanket `edit_posts`. A
connected client can never exceed the WordPress permissions of the user who
authorised it. The endpoint as a whole is gated on a configurable capability,
re-checked on every token refresh, so demoting or deleting a user disconnects
them rather than leaving a valid token behind.

**Scopes.** A grant carries `read` or `read write`. A read-only grant is refused
any ability annotated as changing the site, whatever the user behind it could
otherwise do. This is enforced at the ability boundary rather than by filtering
capabilities, because the read abilities require `edit_posts` too — stripping
write capabilities would take reading down with it.

**Read-only mode.** A site-wide switch that stops write abilities being
registered at all. A tool a client never sees cannot be called by mistake, and
is never described to the model as available. This is the setting to use when
bringing a connector up on a live site: prove the transport and the
authentication work, then grant writing.

### OAuth details

- Authorization code flow with **mandatory PKCE S256**. `plain` is not
  advertised and not accepted.
- **A consent screen, not silent approval.** Self-registration is open, as the
  specification intends, so obtaining a client identifier costs one
  unauthenticated POST. Without a consent step and its nonce, any
  `<img src="…/oauth/authorize?…">` on any page a signed-in administrator
  visited would become a grant.
- **Exact redirect URI matching.** Prefix matching is what turns an open
  redirect elsewhere on the site into a stolen authorization code.
- **Nothing sensitive stored in the clear.** Client secrets are password hashes;
  tokens and authorization codes are stored as SHA-256 digests. A database dump
  does not confer the ability to act as any connected client.
- **Single-use codes**, deleted before validation, so a failed PKCE attempt
  still spends the code.
- **Rotating refresh tokens.** A stolen refresh token is good for at most one
  use before the legitimate client's next refresh fails and the theft surfaces.
- Access tokens last an hour, refresh tokens thirty days, swept daily by cron.

### What it deliberately does not do

No user creation, role change or password reset. Those are
privilege-escalation primitives, and handing them to a client that a prompt can
steer is a poor trade for the convenience. Option writing is restricted to an
allow-list (`blogname`, `blogdescription`) rather than to `manage_options`,
which would otherwise cover `siteurl`, `home` and `default_role`.

---

## Configuration

**Settings → MCP Connector.**

| Setting | Panel | Default | Notes |
|---|---|---|---|
| Ability groups | Tools | all but Users | |
| Addressable post types | Tools | public types declared to REST | Everything else is refused whatever slug is asked for. |
| Third-party abilities | Tools | read-only, plus a shipped profile | See below. |
| Required capability | Security | `edit_posts` | To authorise a client and to reach the endpoint. |
| Read-only mode | Security | off | |
| OAuth provider | Security | on | |
| Self-registration | Security | on | Turn off to require clients be created by hand. |
| Ability namespace | Advanced | `wp-mcp` | Prefixes every ability and tool name. Changing it renames tools a connected client already knows. |
| Server route | Advanced | `connector` | Last segment of the endpoint URL. Changing it invalidates the URL already given to clients. |

Every panel is in the page whichever one is showing, and saving writes all of
them at once. That is not incidental: two of these settings treat an empty
selection as a decision to honour rather than a value to recompute, so a form
that submitted only the visible panel would empty the others on its way past.

### Addressable post types

A site accumulates post types that are storage rather than content —
performance telemetry, form definitions, a RAG corpus in a non-public type.
Treating `post_type` as a free string makes all of them reachable by a client
that guesses the slug. The addressable set is therefore explicit, defaulting to
types that are both public *and* declared to the REST API.

Capabilities follow the type: a type declaring its own `capability_type` is
checked against `$type->cap`, not against a blanket `edit_posts`.

### Third-party abilities

Selection is per ability. Anything a provider explicitly annotates `readonly` is
selected by default; anything else — including abilities that annotate nothing —
is not, because an unstated annotation is not an implicit "harmless".

The screen groups them by what their provider claims — *reads only*, *writes*,
*writes and may delete or overwrite*, *undeclared* — rather than by anything more
useful-sounding. There is no signal anywhere in the Abilities API separating
"writes a value" from "rewrites the content model": Meta Box files all
twenty-three of its abilities under one category and annotates
`update-field-value` and `create-post-type` identically. A tidier grouping would
mean inventing the information it rests on.

Providers this plugin knows something about get a shipped **profile**, a
hand-written list of which of their abilities are value-level rather than
structural. Meta Box ships with one: values and field definitions in, field group
and post type editing out. A profile is data, not inference, so it fails visibly
when a provider renames something instead of silently misclassifying it.

Two things are never published, whatever the settings say:
`mcp-adapter/execute-ability`, which invokes any registered ability by name and
would make every choice above decorative, and this plugin's own namespace, which
is published separately in full.

### Custom fields

Two kinds, handled differently, because only one of them is visible to WordPress.

**Declared fields** — registered with `register_post_meta()` and `show_in_rest`
— are read back on `get-post`, listed under `declared_meta` on `get-post-types`,
and writable through the `meta` map of `create-post` and `update-post`. Writing
honours `auth_callback`, so a field its owner restricted stays restricted. Keys
the type does not declare are refused with a message naming the ones it does.

Undeclared fields are not writable at all, and the restriction is the point. An
earlier version accepted any key without a leading underscore, which meant a
client could set a phone number it had no way to read back — writing values you
cannot verify, into fields whose type you are guessing, is worse than refusing.

**Framework fields** — owned by Meta Box, ACF or similar — announce themselves to
nobody. They are reported under `framework_fields` on `get-post-types`, with key,
label and type, purely so a client can discover that they exist; reading and
writing goes through the owning plugin's own abilities, which know that
`donnees_verifiees` is a switch and `site_web` a URL. The Meta Box reader ships;
other frameworks are added through `mcp_connector_field_sources`.

Everything is also filterable through `mcp_connector_settings`, for sites that
would rather pin configuration in a mu-plugin than leave it editable.

---

## Extending

Publish your own abilities alongside the built-in ones by implementing
`AbilityGroup` and appending it:

```php
add_filter('mcp_connector_ability_groups', function (array $groups): array {
    $groups[] = new MyPluginAbilityGroup();

    return $groups;
});
```

The group's `definitions()` returns `AbilityDefinition::reading(...)` or
`::writing(...)` objects. The registry handles namespacing, category
registration, MCP metadata, input wrapping and scope enforcement.

Describe the input with the package's builder, and a write's effect with a
`Behaviour`:

```php
use Pollora\Abilities\Domain\Model\Behaviour;
use Pollora\Abilities\Domain\Model\Input;
use Pollora\Abilities\Domain\Schema\SchemaBuilder;

AbilityDefinition::writing(
    slug: 'archive-post',
    label: __('Archive a post', 'my-plugin'),
    description: 'Moves a post out of the published set without deleting it.',
    category: AbilityCategory::Content,
    inputSchema: (new SchemaBuilder)
        ->integer('id', 'ID of the post to archive.', required: true, minimum: 1)
        ->toArray(),
    execute: static fn (Input $input): array => ['id' => $input->id('id')],
    permission: static fn (Input $input): bool => current_user_can('edit_post', $input->id('id')),
    behaviour: Behaviour::Updates,
);
```

Other extension points:

| Filter | Purpose |
|---|---|
| `mcp_connector_settings` | Override any configuration value |
| `mcp_connector_ability_groups` | Add or remove ability groups |
| `mcp_connector_server_tools` | Adjust the published tool list |
| `mcp_connector_writable_options` | Widen the option write allow-list |
| `mcp_connector_external_abilities` | Adjust the published third-party ability list |
| `mcp_connector_provider_profiles` | Declare a recommended selection for a provider |
| `mcp_connector_addressable_post_types` | Adjust the addressable post type set |
| `mcp_connector_field_sources` | Teach the plugin to read another field framework |

---

## Translations

Six languages ship with the plugin, complete: German, Spanish, French, Italian,
Dutch and Brazilian Portuguese. The text domain is `amphibee-mcp-connector`,
loaded from `languages/` on `init` — not earlier, because WordPress 6.7 and later
raise a `_load_textdomain_just_in_time` notice for a domain loaded before then.

The domain is the intended wordpress.org slug rather than the directory name, and
has to be: language packs from translate.wordpress.org are keyed on the slug, so
a domain that differs from it receives nothing.

Two terms are worth knowing before touching a `.po`, because English uses one
word for both and this plugin does not:

| English | What it means here |
|---|---|
| **ability** | A unit the Abilities API registers, which the connector publishes as one MCP tool. |
| **capability** | A WordPress permission such as `edit_posts`. |

They are separate concepts on separate panels — Tools decides which abilities
exist, Security decides which capability reaches them — so a language that
renders both with the same word loses the distinction the screen is built on.
The shipped translations keep them apart (in French, *capacité* and
*permission*).

After changing any translatable string:

```bash
wp i18n make-pot . languages/amphibee-mcp-connector.pot \
  --slug=amphibee-mcp-connector --domain=amphibee-mcp-connector --exclude=vendor,assets
wp i18n update-po languages/amphibee-mcp-connector.pot languages/   # merges into every .po
# translate the new entries, then:
wp i18n make-mo languages/
```

The `.mo` files are what WordPress reads; the `.po` files are the source and
both are committed. `wp i18n make-php` produces the `.l10n.php` variants that
WordPress 6.5 and later prefer — those are generated, and gitignored.

---

## Architecture

The Abilities API primitives live in [`pollora/abilities`](https://github.com/Pollora/abilities)
rather than here: the ability model, the schema builder, the typed input reader
and the two registration adapters. What stays in this plugin is the policy that
package deliberately has no opinion about — the configurable namespace,
read-only mode, OAuth scope enforcement, and which groups a site has enabled.

```
src/
├── Plugin.php                  Composition root
├── Settings.php                Immutable configuration
├── Abilities/
│   ├── AbilityRegistry.php     Decides what to publish and under which name
│   ├── AbilityDefinition.php   An ability, described independently of registration
│   ├── AbilityCategory.php     The six categories, namespaced at registration
│   ├── AbilityGroup.php        Extension point
│   └── Group/                  The six built-in groups
├── Server/ServerRegistry.php   Declares the MCP server
├── OAuth/                      Provider: repositories, PKCE, controllers, consent
├── Http/HttpResponse.php       Non-REST responses
├── Formatting/                 Shared record shapes
├── Support/                    Environment probes, error factory
└── Admin/
    ├── SettingsPage.php        Both screens, form handling, the six panels
    ├── Wizard.php              The setup assistant, on a screen of its own
    ├── Diagnostics.php         Checks, each carrying its own remedy
    ├── ConnectionTest.php      The end-to-end run, over loopback HTTP
    ├── ClientGuides.php        Per-client walkthroughs (filterable)
    ├── Parts.php               Fragments the assistant and the panels share
    └── Field.php               Form controls
```

Assets live in `assets/`. The stylesheet is scoped entirely to `.mcpc` so nothing
leaks into the rest of wp-admin, and loads no web font: this plugin's whole
subject is which outbound requests a site makes. The script is an enhancement —
panels are real links, every panel is in the DOM, and the form submits without
it. The one exception is the connection test, which has no non-JavaScript
equivalent and says so on the screen rather than rendering a dead button.

One structural note worth knowing before changing anything: the authorization
endpoint is served as an ordinary front-end route on `parse_request`, not as a
REST route. WordPress's `rest_cookie_check_errors()` calls
`wp_set_current_user(0)` for any REST request carrying login cookies without a
`wp_rest` nonce — which is exactly the shape of a browser arriving from a
client's redirect. Inside a REST callback the visitor would appear permanently
signed out and the flow could never complete.

---

## Licence

GPL-2.0-or-later.

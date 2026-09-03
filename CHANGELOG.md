# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.2.2

### Fixed

- The fallback notice 1.2.1 added shipped in English only: it reached the
  catalogues after that release was cut. All six locales carry it now.

## 1.2.1

### Fixed

- **Activating the plugin after a Composer install fataled the site.** The
  bootstrap required `vendor/autoload.php` unconditionally, and that directory
  exists only in the release zip: installed as a Composer package the file is
  absent, so activation ended in a fatal error and the site answered 500. The
  autoloader is now required only when it is there — a Composer install already
  has this package's PSR-4 mapping, and `pollora/abilities` with it, in the
  consuming project's autoloader. If neither source produced the classes the
  dashboard says so instead of fataling.

## 1.2.0

### Changed

- The Abilities API primitives now come from [`pollora/abilities`](https://github.com/Pollora/abilities)
  instead of being carried here. The ability model, the JSON Schema builder, the
  typed input reader, the behaviour hints and the two registration adapters moved
  out; `AbilityRegistry` now decides *what* to publish and under which name while
  the package decides *how* and *when*.
- What stays in the plugin is the policy the package deliberately has no opinion
  about: the configurable ability namespace, read-only mode, OAuth scope
  enforcement, and which groups a site has enabled. `AbilityGroup` and
  `AbilityDefinition` are unchanged as an extension point, except that a write
  now declares a `Behaviour` where it declared `Annotations`.
- **No tool contract changed.** Every one of the 29 registered abilities produces
  the same label, description, category, behaviour hints and input schema as
  before, verified by diffing the generated schemas across the refactor.

### Removed

- `Abilities/Schema.php`, `Abilities/Input.php` and `Abilities/Annotations.php`,
  superseded by their equivalents in the package. Anything extending this plugin
  through `mcp_connector_ability_groups` should import
  `Pollora\Abilities\Domain\Schema\SchemaBuilder`,
  `Pollora\Abilities\Domain\Model\Input` and
  `Pollora\Abilities\Domain\Model\Behaviour` instead.

## 1.2.0

First public release. Versions 1.0.0 and 1.1.0 existed, but only ever ran on one
site and were never distributed; there is nothing for a user of this plugin to
upgrade from, so the history starts here.

### Added

- **27 MCP tools in six sets** — posts and pages, taxonomies and terms, media
  library, navigation menus, users (off by default), and site information. Each
  carries its own JSON Schema and behaviour annotations, and each set switches on
  and off independently. Tools are published individually rather than behind a
  generic discover-then-execute pair, so a client sees every tool and its schema
  in its first `tools/list`.
- **Curated republishing of third-party abilities.** Abilities registered by
  other plugins can be published through the same connector, selected one by one
  rather than per plugin — the interesting line runs *inside* a provider, and
  nothing in the Abilities API separates "sets a value" from "rewrites the
  content model". Anything explicitly annotated read-only is offered by default;
  anything else, including abilities that annotate nothing, is not.
- **A built-in OAuth 2.1 provider**: authorization code flow with mandatory PKCE
  S256, a real consent screen, exact redirect URI matching, single-use codes
  deleted before validation, and rotating refresh tokens. Client secrets are
  stored as password hashes, tokens and codes as SHA-256 digests.
- **Three independent enforcement layers**: per-object capabilities, grant
  scopes, and a site-wide read-only mode that stops write tools being registered
  at all.
- **A settings screen in six panels** — Dashboard, Connect, Tools, Security,
  Applications, Advanced — and a **four-step setup assistant** on a screen of its
  own, which the plugin opens on until some client holds a live access token.
- **An end-to-end connection test.** Seven steps over real loopback HTTP:
  discovery, registration, consent, approval, token, `initialize`, `tools/list`
  — then deletion of the throwaway client and token it created, reported as a
  step rather than done silently.
- **Health checks that name the two invisible failures**: a web server blocking
  `/.well-known/`, where RFC 8414 and RFC 9728 pin the discovery documents, and a
  missing MCP Adapter. Each check carries the literal change that fixes it.
- German, Spanish, French, Italian, Dutch and Brazilian Portuguese translations
  of every user-facing string.

### Changed

- **`Requires Plugins: mcp-adapter` was removed**, in preparation for the plugin
  directory. The header gates activation correctly, but the "Install now" link
  WordPress offers for a missing dependency queries wordpress.org, where the
  adapter is not — anyone installing from the directory would meet a refusal to
  activate followed by a link that leads nowhere. A dead end is worse than a
  missing guard rail.

  The cost is worth stating: WordPress no longer refuses to deactivate the
  adapter while this plugin runs, so deactivating it silently removes the MCP
  endpoint and drops every connected client. The dashboard check named "MCP
  Adapter" is where that becomes visible.
- **The text domain is now `amphibee-mcp-connector`**, matching the intended
  directory slug. Language packs from translate.wordpress.org are keyed on the
  slug, so a domain that differs from it receives nothing. The settings URL, the
  MCP server identifier, the hook prefix and the option keys are unchanged.

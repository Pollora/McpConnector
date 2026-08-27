# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

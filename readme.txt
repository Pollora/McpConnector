=== AmphiBee MCP Connector ===
Contributors: ogorzalka
Tags: mcp, model-context-protocol, ai, oauth, abilities-api
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes WordPress content management as Model Context Protocol tools, with a built-in OAuth 2.1 provider so a remote AI client can connect by URL.

== Description ==

An AI client that can read and write your site has to be able to reach it, prove
who it is, and be told what it may touch. This plugin does all three: it
publishes WordPress content management as [Model Context Protocol](https://modelcontextprotocol.io)
tools, and it ships the OAuth 2.1 provider those clients expect, so connecting
one is a matter of handing over a URL.

Nothing in it is specific to one site. What it publishes is configurable, what
it refuses is enforced in three independent layers, and the tool set is
extensible through a filter.

= What a client gets =

27 first-class tools, each with its own input schema and behaviour annotations,
in six sets that switch on and off independently:

* **Posts and pages** — `get-posts`, `get-post`, `get-pages`, `create-post`, `update-post`, `delete-post`, `set-post-terms`
* **Taxonomies and terms** — `get-taxonomies`, `get-terms`, `create-term`, `delete-term`
* **Media library** — `get-media`, `upload-media-from-url`, `update-media`, `set-featured-image`, `delete-media`
* **Navigation menus** — `get-menus`, `get-menu-items`, `get-menu-locations`, `create-menu`, `add-menu-item`, `update-menu-item`, `delete-menu-item`, `assign-menu-location`
* **Users** *(off by default)* — `get-users`, `get-user`
* **Site information** — `get-site-info`, `get-post-types`, `update-option`

Tools are published individually rather than behind a generic
"discover then execute" pair, so a client sees every tool and its schema in its
first `tools/list` and can call one directly.

Abilities registered by *other* plugins can be republished through the same
connector, chosen one by one. Anything a provider explicitly annotates as
read-only is offered by default; anything else, including abilities that
annotate nothing, is not — an unstated annotation is not an implicit "harmless".

= It does not phone home =

The plugin has no licence server, no analytics endpoint, no update channel of
its own, and the settings screen loads no web font and no remote icon.

It makes exactly two kinds of outbound HTTP request, and **both are loopback
requests to your own site**: a five-minute-cached probe that fetches your own
two OAuth discovery documents to check your web server does not block them, and
the end-to-end connection test described below, which you start by pressing a
button. Neither one leaves your server. Nothing about your content is sent
anywhere.

= Security model =

Three layers, each assuming the others may be wrong.

**Capabilities.** Every tool checks the capability for the specific object it
touches — `edit_post` on the post being edited, not a blanket `edit_posts`. A
connected client can never exceed the WordPress permissions of the user who
authorised it. The endpoint is gated on a configurable capability, re-checked on
every token refresh, so demoting or deleting a user disconnects them instead of
leaving a valid token behind.

**Scopes.** A grant carries `read` or `read write`. A read-only grant is refused
any tool annotated as changing the site, whatever the user behind it could
otherwise do.

**Read-only mode.** A site-wide switch that stops write tools being registered
at all. This is the setting to use when bringing a connector up on a live site:
prove the transport and the authentication work, then grant writing.

The OAuth provider uses the authorization code flow with **mandatory PKCE S256**
(`plain` is neither advertised nor accepted), a real consent screen rather than
silent approval, exact redirect URI matching, single-use codes deleted before
validation, and rotating refresh tokens. Client secrets are stored as password
hashes; tokens and codes as SHA-256 digests. A database dump does not confer the
ability to act as any connected client.

= What it deliberately does not do =

No user creation, role change or password reset. Those are
privilege-escalation primitives, and handing them to a client a prompt can steer
is a poor trade for the convenience. Option writing is restricted to an
allow-list (`blogname`, `blogdescription`) rather than to `manage_options`,
which would otherwise cover `siteurl`, `home` and `default_role`.

= For developers =

Publish your own tools by implementing `AbilityGroup` and appending it to
`mcp_connector_ability_groups`. Eight filters in all cover the settings, the
group list, the published tool list, the option allow-list, the third-party
ability selection and its provider profiles, the addressable post type set, and
the custom field readers.

Source, issues, and the full hook reference with examples:
[github.com/Pollora/McpConnector](https://github.com/Pollora/McpConnector).

= Translations =

Six languages ship complete: German, Spanish, French, Italian, Dutch and
Brazilian Portuguese. WordPress picks one from the site's own language setting;
there is nothing to configure.

Developed and maintained by [AmphiBee](https://amphibee.fr).

== Installation ==

This plugin needs the [MCP Adapter](https://github.com/WordPress/mcp-adapter)
for the MCP transport itself. The adapter is distributed on GitHub and is **not
on wordpress.org**, so it cannot be installed from the plugin screen — download
it and place it in `wp-content/plugins/mcp-adapter/`.

There is deliberately no `Requires Plugins: mcp-adapter` header. It would gate
activation correctly, but the "Install now" link WordPress offers for a missing
dependency queries wordpress.org, where the adapter is not, so installing from
the directory would mean a refusal to activate followed by a link that leads
nowhere. Instead nothing fatals without the adapter, and the plugin's dashboard
states what is missing and prints the commands that fix it.

1. Install and activate this plugin.
2. Install the MCP Adapter by hand, as above.
3. Visit `Settings → MCP Connector`. Until a client holds a live token, that
   address opens a four-step setup assistant.

The assistant checks every environmental requirement first, and each failure
states the literal change that fixes it. Pretty permalinks are required, and so
is HTTPS for any remote client.

= Your web server must not block /.well-known/ =

This is the single most common reason a connection fails, and it fails
invisibly: the client reports an unreachable server, and nothing appears in any
WordPress log, because the request never reaches PHP.

RFC 8414 and RFC 9728 pin the two OAuth discovery documents to the site root,
and the usual "deny all hidden files" nginx rule blocks them:

`location ~ /\. { deny all; }` is wrong — use `location ~* /\.(?!well-known\/) { deny all; }`

The setup assistant probes both documents and prints the line to change when
they do not answer.

= Behind a CDN =

`/oauth/authorize` is a dotless GET path, which is exactly what a "cache HTML
pages" edge rule matches. It is only ever served to signed-in users, so a rule
that excludes session cookies already skips it, and the responses carry
`no-store`. But a cache rule with an explicit edge TTL overrides origin headers,
and a *response header* rule usually has no cookie condition at all. On such a
setup, exclude this path from both. A cached authorization redirect carries
somebody else's one-time code.

== Frequently Asked Questions ==

= Which clients does it work with? =

Any client that speaks the Model Context Protocol over HTTP with OAuth 2.1 —
Claude, ChatGPT and Cursor among them. The setup assistant includes a
walkthrough per client, because they differ in one respect: some register
themselves and need nothing pasted back, and some want an identifier and secret
created here first.

= Does it send my content to an AI provider? =

No. The plugin has no AI provider, no API key and no credit. It is the thing
being read: a client you connect and control does the reading, over a connection
you authorised and can revoke from the settings screen.

= What does the connection test actually do? =

It runs the whole chain for real, over loopback HTTP: it registers a throwaway
application, authorises it, redeems the code, calls `initialize` and
`tools/list` with the resulting token, and reports which of the seven steps
stopped. Then it deletes the client and the token it created — and the cleanup
is a reported step, not a silent one, on the failure path as well as the success
path.

Two things are worth knowing before pressing the button. The requests are real
HTTP rather than direct calls into the plugin's own controllers, deliberately: a
direct call would prove the PHP works while saying nothing about the web server
in front of it, which is where the failure usually is. And the authorisation
step **replays your own session cookies** to that loopback request, because the
consent screen is a page a signed-in user approves. Forging a session instead
would work too and would leave a session token behind; reusing the request's own
does not.

= Why does a connected client's content keep my Gutenberg block markup? =

Because the plugin does not sanitise post content before handing it to
`wp_insert_post()`, and that is deliberate. WordPress already runs
`content_save_pre`, which applies KSES for any user without `unfiltered_html`.
Adding a `wp_kses_post()` pass on top would strip block delimiters
(`<!-- wp:paragraph -->`) and would do it even for an administrator. The
capability system is the right place for that decision, and it is already making
it.

= Can a client reach post types I use for storage rather than content? =

Not unless you say so. A site accumulates post types that are storage —
telemetry, form definitions, a search corpus — and treating `post_type` as a free
string would make all of them reachable by a client that guesses the slug. The
addressable set is explicit, defaulting to types that are both public *and*
declared to the REST API. Everything else is refused whatever slug is asked for.

= A client was connected and now it is not. What changed? =

Most often the MCP Adapter was deactivated. Without the `Requires Plugins`
header WordPress no longer refuses to deactivate it while this plugin runs, and
deactivating it removes the MCP endpoint — nothing fatals, the OAuth provider
and the abilities keep working, but every connected client drops. The dashboard
check named "MCP Adapter" is where that becomes visible.

= What happens to my data if I delete the plugin? =

Deleting removes the settings, the registered clients, and every access and
refresh token. Deactivating does not: deactivating is routinely how an
administrator tests something, and it should not disconnect every client that
would otherwise still work on reactivation.

= Why does it require WordPress 6.9? =

The Abilities API, which the tools are registered through, arrived in core in
6.9. Before that there is nothing to register them with.

== Screenshots ==

1. Step one of the setup assistant: every environmental requirement checked before you are handed a URL, including the two that otherwise fail invisibly — a web server blocking /.well-known/, and a missing MCP Adapter.
2. The dashboard: whether a client can connect right now, and if not, which link of the chain is broken.
3. Tools: the six built-in ability groups, each switched on or off independently. Everything not published here is refused, whatever a client asks for.
4. Security: the required capability, read-only mode, and the two OAuth switches.
5. The end-to-end connection test, having run: seven steps over real loopback HTTP, from the discovery documents to a tool list fetched with a token it obtained itself.

== Changelog ==

= 1.2.0 =

First public release.

* 27 MCP tools in six sets, plus curated republishing of abilities registered by other plugins.
* Built-in OAuth 2.1 provider: authorization code flow with mandatory PKCE S256, consent screen, exact redirect URI matching, single-use codes, rotating refresh tokens.
* Three enforcement layers: per-object capabilities, grant scopes, and a site-wide read-only mode.
* Settings screen in six panels, and a four-step setup assistant on a screen of its own.
* An end-to-end connection test that runs the whole OAuth and MCP chain over loopback HTTP and cleans up after itself.
* Health checks that name the two failures which are otherwise invisible: a web server blocking `/.well-known/`, and a missing MCP Adapter.
* German, Spanish, French, Italian, Dutch and Brazilian Portuguese translations of every user-facing string.

== Upgrade Notice ==

= 1.2.0 =
First public release.

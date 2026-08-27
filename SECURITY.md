# Security policy

## Supported versions

The latest minor release receives security fixes. Older ones do not.

| Version | Supported |
|---|---|
| 1.x | ✅ |

## Reporting a vulnerability

**Please do not open a public issue.**

Use GitHub's private reporting — [Security → Report a
vulnerability](https://github.com/Pollora/McpConnector/security/advisories/new).

Useful in a report: the plugin version, the WordPress and PHP versions, whether
the MCP Adapter was present, what an attacker gains, and the smallest sequence of
requests that demonstrates it.

You can expect an acknowledgement within three working days and an assessment
within ten. If the report is valid, you will be credited in the advisory and the
changelog unless you would rather not be.

## Threat model

This plugin is an authorisation server and a remote write endpoint. Its whole
subject is who may act on the site and how far, so the boundary is sharper than
for most plugins: **a connected client acting within the permissions of the user
who authorised it is the feature; anything that exceeds them is a
vulnerability.**

Specifically in scope:

- **Grant forgery.** Anything that produces an access token without the
  authenticated, deliberate consent of a user who holds the required capability.
  This includes CSRF against the consent screen, an authorization code delivered
  to a redirect URI its client did not register, and PKCE being bypassable or
  downgradable to `plain`.
- **Token theft becoming persistent access.** Refresh token rotation is what
  makes a stolen token surface; anything that defeats it is in scope, as is any
  path that leaves a token valid after the user behind it lost the required
  capability or was deleted.
- **Scope escape.** A `read` grant reaching any ability annotated as changing the
  site, whether through the ability boundary, through a republished third-party
  ability, or through read-only mode being incompletely applied.
- **Capability escape.** Any tool acting on an object the authorising user could
  not act on themselves — including a post type outside the addressable set, a
  meta key the type did not declare, or an option outside the write allow-list.
- **Publication of what is never meant to be published.**
  `mcp-adapter/execute-ability` invokes any registered ability by name; if it can
  reach the server through any configuration, every curation decision above
  becomes decorative.
- **Secrets in the clear.** Client secrets, access tokens, refresh tokens and
  authorization codes are stored hashed. A database read that confers the ability
  to act as a connected client is a vulnerability.
- Stored or reflected XSS on either admin screen, and privilege escalation
  through the settings forms, the connection-test AJAX action, or the OAuth
  endpoints.
- **The connection test as a lever.** It replays the current administrator's
  session cookies into a loopback request and creates a real client and token.
  Anything that lets a lower-privileged user trigger it, redirect where it points,
  or keep the client it was supposed to delete, is in scope.

Out of scope:

- A client doing, within its granted scope, exactly what the tools describe. That
  is the feature. If a connected client can edit a post, the answer is the
  capability of the user who authorised it, or read-only mode.
- Findings that require an administrator account to already be compromised.
- The MCP Adapter's own transport. Report those to
  [WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter).
- The site being reachable at all without the MCP Adapter installed. Without it
  the MCP endpoint simply does not exist; that is a documented state, not a
  bypass.

## What the code does about it

- **PKCE S256 is mandatory.** `plain` is neither advertised in the server
  metadata nor accepted at the token endpoint.
- **The consent screen is a real form with a nonce.** Self-registration is open,
  as the specification intends, so obtaining a client identifier costs one
  unauthenticated POST — without a consent step, any
  `<img src="…/oauth/authorize?…">` on a page a signed-in administrator visited
  would become a grant.
- **Redirect URIs match exactly.** Prefix matching is what turns an open redirect
  elsewhere on the site into a stolen authorization code.
- **Query arguments in redirects are encoded explicitly**, not by
  `add_query_arg()`, which applies `urlencode_deep()` *before* merging what you
  hand it and leaves the new arguments raw. A client-supplied `state` containing
  `&code=…` was injected verbatim into the redirect until that was fixed.
- **Authorization codes are deleted before validation**, so a failed PKCE attempt
  still spends the code — an intercepted code buys one guess at the verifier, not
  unlimited ones.
- **The gating capability is re-checked on every token refresh**, so demoting or
  deleting a user disconnects them rather than leaving a valid token behind.
- **Scope is enforced at the ability boundary**, not by stripping capabilities:
  the read abilities require `edit_posts` too, so filtering capabilities would
  take reading down with it.
- **`mcp-adapter/execute-ability` is on a non-configurable never-publish list**,
  as is this plugin's own namespace.
- **Only declared meta is writable**, honouring each field's `auth_callback`, and
  only post types in the addressable set are reachable at all. Option writing is
  an allow-list rather than a `manage_options` check, which would otherwise cover
  `siteurl`, `home` and `default_role`.
- **Tokens live in options, not transients.** Transients evaporate on
  `wp_cache_flush()`, which cache plugins call on every content purge; an access
  token that dies on each publish produces a refresh storm. Authorization codes
  stay transients — five minutes, single use, and the user is watching.
- **The connection test requires the settings capability and a nonce**, deletes
  the client and token it created on the failure path as well as the success
  path, and reports the deletion as a step.

CI runs the WordPress security sniffs, Semgrep's PHP and security rulesets, and
`composer audit` on every push, plus weekly so that an advisory published after
the last commit still surfaces.

# Contributing

## Getting set up

```bash
git clone https://github.com/Pollora/McpConnector.git
cd McpConnector
composer install
composer qa
```

`composer qa` is exactly what CI runs. If it passes locally it passes there.

⚠️ A fresh clone **fatals if dropped straight into `wp-content/plugins/`**:
`mcp-connector.php` requires `vendor/autoload.php`, and `/vendor/` is gitignored
because the whole of it is one generated autoloader — committing it would mean
reviewing a vendor diff for a tree with no runtime dependencies at all. Run
`composer install` first, or install the zip a release builds.

## The quality gate

Five tools, each answering a different question.

| Command | Question it answers |
|---|---|
| `composer lint` | Does every file parse? |
| `composer format:check` | Is it written the way the rest of the codebase is? |
| `composer sniff` | Is anything unescaped, unsanitised, untranslated or incompatible with PHP 8.3 / WordPress 6.9? |
| `composer analyse` | Do the types hold? |
| `composer test` | Do the structural invariants still hold? |

`composer format` applies style fixes; `composer sniff:fix` applies the ones
PHPCS can make automatically.

### Code style

Laravel Pint, PSR-12, configured in `pint.json`. This is deliberately **not**
the WordPress Coding Standard: the codebase uses `declare(strict_types=1)`,
camelCase methods, final classes and constructor promotion, and WPCS would
require rewriting all of it into a style it does not otherwise follow.

What is kept from the WordPress standards is the part that matters for a plugin
running on somebody else's site: the security, escaping, i18n, deprecation and
version-compatibility sniffs. Those are in `phpcs.xml.dist`, along with a
comment for every deliberate exemption.

Two of those exemptions rest on something the sniff cannot see, so a test checks
it instead. `PrefixAllGlobals.DynamicHooknameFound` is silenced because every
hook is fired as `apply_filters(self::SOME_FILTER, …)` where the constant already
carries the prefix — and `ArchitectureTest` asserts that every filter constant
does, and that no call site spells a hook name as a literal instead. If you
silence a sniff, do the same: leave behind something that still checks the claim.

### Static analysis

PHPStan runs over `src/`, `mcp-connector.php` and `uninstall.php`, with the
WordPress stubs loaded.

**The level is 6, not `max`, and that is a stop rather than the finish line.**
Levels 7 and up object to every `(string)` and `(int)` cast of a value WordPress
hands back as `mixed` — 142 of them, concentrated in the OAuth repositories, the
ability groups and the admin screens. Narrowing those is worth doing. It is also
a change to the runtime of a live authorisation server, and it belongs in the
same session as the behavioural tests that would catch a regression. Raising the
level is that work's first commit.

There is no baseline and none should be added: at the level the project claims,
a new error means either a real defect or a type that deserves to be written
down. Three `ignoreErrors` entries exist, each with its reason in the file — the
MCP Adapter's classes are not in `vendor/` and cannot be, nav-menu items are
`WP_Post` objects decorated with properties no stub can express, and `get_post()`
really can return null whatever the stub's conditional type says.

Tests are not analysed. Pest describes them as closures rebound onto a generated
test case, so every `$this->` in a test resolves to `PHPUnit\Framework\TestCase`
and reports as an undefined property. The suite proves itself by running.

## Tests

```bash
composer test                            # everything
vendor/bin/pest --filter=Architecture    # one file
```

### What the suite is, and what it is not

**It is structural, not behavioural, and there is no coverage threshold.** The
74 tests guard two things: the distribution metadata that five files have to
agree on, and the invariants that hold across the whole codebase. They are
genuinely load-bearing — a version string out of step with `Stable tag:` means
the directory keeps serving the previous release however new trunk is, and
nothing anywhere would say so.

What they do not do is exercise the OAuth flow, the ability groups or the admin
screens. Asserting a coverage percentage over code the suite never enters would
produce a number rather than a guarantee, so `test:coverage` enforces a minimum
of 0 and CI does not run it. **Raising that is the next piece of work**, in this
order, because it is the order of how much a defect would cost:

1. the OAuth flow — PKCE verification, redirect URI matching, code single-use,
   refresh rotation, the capability re-check on refresh;
2. `ExternalAbilities` — the curation, the never-publish list, the provider
   profiles;
3. `Settings` and the "reviewed" pattern, where an empty list is a decision to
   honour rather than a value to recompute;
4. the admin rendering.

Then raise the PHPStan level, then set a coverage floor a few points under
whatever the suite actually reaches, so it ratchets rather than tripping on a
single new line.

### How the suite runs

**Without a WordPress installation.** Brain Monkey replaces the core functions;
`tests/Fixtures/wp-classes.php` provides minimal doubles for the core classes the
plugin type-hints against. That keeps it fast and forces the code to state its
dependencies rather than reach for globals.

Three things to know before writing a test:

- **Brain Monkey leaves a mocked function defined for the rest of the process.**
  A `function_exists()` guard therefore stays true in every test that runs after
  one mocks it. Anything the plugin probes for rather than calls — the Abilities
  API, `apache_request_headers()`, anything the MCP Adapter declares — must have
  its absent state declared in `tests/TestCase.php`, or test order becomes a
  source of failures.
- **Tests run in random order by design.** A test that only passes in a
  particular order is a broken test, not an ordering problem.
- **A probe that finds nothing must fail, not pass.** The catalogue test that
  checks *ability* and *capability* are never rendered by the same word looks up
  two specific `msgid` values; if either goes missing from a catalogue the test
  fails rather than quietly asserting nothing. Write assertions that cannot pass
  vacuously — it is the failure mode this codebase has already been bitten by,
  in the script that filled the `.po` files by position rather than by `msgid`.

### What a unit test cannot see here

Most of this plugin, honestly. Whether the discovery documents survive the web
server, whether the authorization endpoint is reached before WordPress resolves
the request, whether the code one endpoint issues is one the other accepts —
none of that is visible from a process where WordPress was never loaded.

That is what the plugin's own **connection test** is for, and why it makes real
loopback HTTP requests rather than calling its controllers directly: a direct
call proves the PHP works while saying nothing about the server in front of it,
which is where the failure usually is. Run it from **Settings → MCP Connector**
after any change to the OAuth flow. Seven steps and a reported cleanup.

## Translating

Six languages ship complete. After changing any translatable string:

```bash
wp i18n make-pot . languages/amphibee-mcp-connector.pot \
  --slug=amphibee-mcp-connector --domain=amphibee-mcp-connector \
  --exclude=vendor,tests,build,node_modules,assets \
  --headers='{"Report-Msgid-Bugs-To":"https://amphibee.fr","Last-Translator":"AmphiBee","Language-Team":"AmphiBee"}'
wp i18n update-po languages/amphibee-mcp-connector.pot languages/
# translate the new entries, then:
wp i18n make-mo languages/
```

CI regenerates the template and compares it to the committed one, after stripping
`POT-Creation-Date` and the `#:` reference lines — the only two that move on
their own and say nothing about the strings. Without that guard a stale `.pot`
goes unnoticed until a translator finds it.

⚠️ **"ability" and "capability" must never be rendered by the same word.** They
are separate concepts on separate panels — Tools decides which abilities exist,
Security decides which capability reaches them — so a language that collapses
them loses the distinction the screen is built on. In French: *capacité* and
*permission*. A test enforces it for the shipped catalogues.

The `.l10n.php` files `wp i18n make-php` produces are generated and gitignored;
the release build refuses to ship one.

## Changing behaviour

- Anything a user would notice belongs in `CHANGELOG.md`.
- User-facing strings go through `__()` with the `amphibee-mcp-connector` text
  domain and are escaped at the point of output, never before.
- New hooks are prefixed `mcp_connector_` and declared as a class constant next
  to the documentation of what they do, never as a literal at the call site.

### Decisions that look like oversights

Four of these have been mistaken for bugs before. Each is explained where it
lives; do not "fix" one without reading that first.

- **Post content is not sanitised before `wp_insert_post()`.** WordPress already
  runs `content_save_pre`, which applies KSES for anyone without
  `unfiltered_html`. A `wp_kses_post()` pass on top would strip Gutenberg block
  delimiters, and would do it for administrators too.
- **`/oauth/authorize` is a front-end route, not a REST route.**
  `rest_cookie_check_errors()` calls `wp_set_current_user(0)` for any REST
  request carrying login cookies without a `wp_rest` nonce — exactly the shape of
  a browser arriving from a client's redirect.
- **There are no rewrite rules.** Paths are matched on `parse_request`. A rewrite
  rule needs a flush, and a forgotten flush shows up as "the connector cannot
  find the authorization server" with nothing in any log.
- **Redirect query arguments are encoded explicitly, not by `add_query_arg()`.**
  That function applies `urlencode_deep()` *before* merging what you hand it, so
  new arguments go through raw. A client-supplied `state` containing `&code=…`
  was injected verbatim until this was fixed.

## Releasing

1. Move the entries under a new `## X.Y.Z` heading in `CHANGELOG.md`.
2. Bump `Version:` in the `mcp-connector.php` header **and** the `VERSION`
   constant below it. `DistributionTest` fails if they disagree, or if either
   disagrees with the changelog.
3. Bump `Stable tag:` in `readme.txt` and add the entry to its own
   `== Changelog ==` — the directory reads that file, not `CHANGELOG.md`, and it
   serves whatever `Stable tag` names. `DistributionTest` checks it against the
   header, along with `Requires at least` and `Requires PHP`.
4. Regenerate the translation template: its `Project-Id-Version` header carries
   the plugin version, so a bump alone puts it out of date and CI's drift check
   fails on a tree that `composer qa` calls clean.
5. Tag `vX.Y.Z` and push it.

The release workflow re-runs the whole gate on the tagged tree, refuses to build
if the tag does not match the plugin header, builds the zip from `git archive` so
no development file can slip in, and then checks the result both ways: no
development file present, and nothing needed at runtime missing. `assets/` and
`languages/` are on the required list by name, because leaving them out is a
mistake that has actually been made — without the stylesheet the settings screen
renders unstyled, and without the catalogues it renders in English.

It also refuses a build whose `vendor/composer/autoload_files.php` declares
anything. That is a lesson from the MCP Adapter, whose shipped autoloader eagerly
loads five phpstan and phpunit files and fatals an entire site when `vendor/` is
copied without the dev tree.

⚠️ **Nothing in the workflow publishes to wordpress.org.** It builds a zip and
attaches it to a GitHub release; the SVN repository is never touched. That step
is still outstanding.

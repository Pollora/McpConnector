## What changed

<!-- One or two sentences. What behaviour is different after this PR? -->

## Why

<!-- The problem this solves. Link an issue if there is one. -->

## Checklist

- [ ] `composer qa` passes locally (lint, style, PHPCS, PHPStan, tests)
- [ ] New behaviour is covered by a test that fails without the change
- [ ] `CHANGELOG.md` records anything a user would notice
- [ ] User-facing strings go through `__()` with the `amphibee-mcp-connector`
      text domain and are escaped on output
- [ ] `languages/amphibee-mcp-connector.pot` regenerated if any string changed
      (CI compares it against the source)
- [ ] `Version:` in `mcp-connector.php` **and** the `VERSION` constant bumped if
      this is a release, along with `Stable tag:` in `readme.txt`

## If this touches the OAuth flow

- [ ] The consent step still verifies its nonce before anything is issued
- [ ] Redirect URIs are still matched exactly, never by prefix
- [ ] Nothing sensitive is stored in the clear — secrets are password hashes,
      tokens and codes are digests
- [ ] Scope is still enforced at the ability boundary, not by stripping
      capabilities

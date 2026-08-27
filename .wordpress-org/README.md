# WordPress.org directory assets

The images the plugin directory shows on its listing page. **Not** the plugin's
own media — those live in `/assets/`, ship inside the zip, and are served to
every site that installs the plugin. Nothing in this directory is ever
downloaded by a site.

The two are easy to confuse because WordPress.org calls its visuals "assets"
and the WordPress convention calls a plugin's CSS and JS "assets" too. They sit
at different levels of the SVN repository:

| Here, in Git | Lands in SVN at | Shipped to sites |
|---|---|---|
| `/.wordpress-org` | `/assets` (repository root) | no |
| `/assets` | `/trunk/assets` | yes |

`.gitattributes` marks this directory `export-ignore`, so `git archive` — and
therefore the release workflow — leaves it out of the distributed zip. The
workflow also fails the build if it finds this directory in one.

## Expected files

| File | Size | Format |
|---|---|---|
| `icon-128x128.png` | 128 × 128 | PNG or JPG |
| `icon-256x256.png` | 256 × 256 | PNG or JPG, exactly 2× the above |
| `icon.svg` | square | SVG — an alternative to both PNGs |
| `banner-772x250.png` | 772 × 250 | PNG or JPG |
| `banner-1544x500.png` | 1544 × 500 | PNG or JPG, exactly 2× the above |
| `screenshot-1.png` … | free, but identical across all of them | PNG or JPG |

Names are exact and lowercase; `Banner-772x250.PNG` is silently ignored. Use
sRGB — a JPG in Adobe RGB renders washed out in the browser. No animation.

Screenshots are numbered from 1 with no gaps, and each number takes its caption
from the matching line of `== Screenshots ==` in `readme.txt`. That section
describes five, so `screenshot-1` through `screenshot-5` are expected, in that
order. **Changing what a shot contains means changing its caption**: the two are
matched by position, and nothing checks that they still describe each other.

The directory's content column is 772px wide — the same width as the banner —
so screenshots authored at **1544px wide** display at exactly 2× and are never
resampled. All five are 1544 × 1200: the carousel jumps on every slide when the
heights differ.

Localised variants are optional and take a locale suffix before the extension:
`banner-772x250-fr_FR.png`, `screenshot-1-fr_FR.png`. The plugin ships six
locales, so translated screenshots are worth having, at least for `fr_FR`.

## Regenerating them

Everything here is generated, and the scripts are kept beside the output so a
change to the settings screen does not leave the listing showing last year's.

### The icon and the banners

```bash
python3 .wordpress-org/make_visuals.py .wordpress-org
```

Drawn with Pillow rather than a browser, because headless Chrome scales its
screenshots by the host's display factor and these files have to be exactly the
sizes above. The glyph is the same one `SettingsPage::mark()` renders in
wp-admin, kept in the mark's own 44 × 44 coordinate space, so the directory
listing and the settings screen carry one identity. The teal is the plugin's
own `--mcpc-band`; the sibling plugin AiVisibility is the same design one hue
away, which is the point.

The script refuses to write a banner whose text would run under the diagonal
pattern, rather than leaving it to be noticed.

### The screenshots

```bash
# 1. Point SITE in shoot.py at an install with the plugin active.
# 2. Generate an admin session and neutralise the site's identity — see below.
python3 .wordpress-org/shoot.py .wordpress-org /path/to/cookie.env
```

`cdp.py` is a hand-written Chrome DevTools Protocol client: enough of it to set
a cookie, run a script in the page, and capture a clip of an exact size. The
shots are taken at 1100 CSS pixels at 2× and downscaled to 1544 wide.

Three things the script does that are not obvious, and each of them is there
because the first attempt got it wrong:

- **Two cookies, not one.** `wordpress_logged_in_` says who you are, but over
  HTTPS `auth_redirect()` resolves the scheme to `secure_auth` and looks for
  `wordpress_sec_` as well. With only the first, wp-admin answers with the login
  form and the screenshot is of that.
- **The admin furniture is removed before the shot**, and each capture is
  clipped to the plugin's own root element. A plain capture shows the host
  site's menu, its name and its update nags — none of which belong on a listing
  for a plugin that runs on other people's sites.
- **The site's identity is neutralised for the duration.** The connection test
  reports the MCP server's name, which is derived from the site title, and the
  loopback request renders in the *site's* locale rather than the
  administrator's. Set `blogname`, `blogdescription` and `WPLANG` to neutral
  values, capture, then restore them. Do not edit the resulting image instead:
  a screenshot showing text the software did not produce is a fabrication,
  whereas a screenshot of a site called "Demo Site" is simply true.

`shoot.py` really runs the connection test for screenshot 5 — it registers a
throwaway client, authorises it and calls the endpoint, then deletes what it
created. That is the plugin's own behaviour, not the script's.

## Publishing them

These files are not versioned per release: the directory keeps one `/assets/`
folder, always the current one, and updating it needs no version bump.

Until the plugin is approved there is no SVN repository to push to. Afterwards:

```bash
svn co https://plugins.svn.wordpress.org/amphibee-mcp-connector mcp-connector-svn
cp .wordpress-org/*.png .wordpress-org/*.svg mcp-connector-svn/assets/
cd mcp-connector-svn && svn add assets/* && svn ci -m "assets: icon, banner, screenshots"
```

Copy only the images — the `.py` files are the recipe, not the dish.

If the SVN push is ever automated with `10up/action-wordpress-plugin-deploy`,
this is the directory it reads by default — hence the name.

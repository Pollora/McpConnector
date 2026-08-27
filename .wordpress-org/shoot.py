#!/usr/bin/env python3
"""Capture the five wordpress.org screenshots from the local install.

Authored at 772 CSS pixels at 2x, which is exactly the directory's content
column: a screenshot 1544px wide displays at 1:1 there and is never resampled.
The admin screens are laid out for a wider viewport than that, so each shot is
taken at 1100 CSS px and downscaled — legible, and still 2x at the display size.
"""
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).parent))

from cdp import Chrome, capture  # noqa: E402
from PIL import Image  # noqa: E402

SITE = "https://angres2024.ddev.site"
ADMIN = SITE + "/wp-admin/options-general.php"
OUT = pathlib.Path(sys.argv[1]).resolve()

# Read from the file WP-CLI wrote rather than the shell: the cookie value
# contains pipes, and a shell that splits on them mangles it silently.
_env = dict(
    line.split("=", 1)
    for line in pathlib.Path(sys.argv[2]).read_text().splitlines()
    if "=" in line
)
# Two cookies, not one. `wordpress_logged_in_` says who you are; over HTTPS
# `auth_redirect()` resolves the scheme to `secure_auth` and looks for
# `wordpress_sec_` as well, so wp-admin bounces to the login form without it.
COOKIES = [
    (_env["LOGGEDNAME"], _env["COOKIE"]),
    (_env["SECNAME"], _env["SECCOOKIE"]),
]

# CSS width the admin is captured at, and the size each file is saved at. The
# five are cropped to one height on purpose: the directory shows them in a
# carousel, and a set of different heights makes it jump on every slide.
SHOOT_WIDTH = 1100
FINAL_WIDTH = 1544
FINAL_HEIGHT = 1200

# The panels sit inside wp-admin, so a plain capture shows this installation's
# menu, its site name and its update nags — none of which belong on a public
# listing for a plugin that runs on other people's sites. Everything but the
# plugin's own screen is taken out of the page before the shot.
#
# The assistant needs none of this: it already suppresses the chrome itself.
STRIP_CHROME = """
(() => {
  // #dolly is Hello Dolly, which paints a lyric fixed to the top right corner
  // of every admin screen and would otherwise appear in the frame.
  for (const id of ['wpadminbar', 'adminmenumain', 'wpfooter', 'screen-meta',
                    'screen-meta-links', 'wp-toolbar', 'dolly']) {
    document.getElementById(id)?.remove();
  }
  document.querySelectorAll('.notice, .update-nag, .updated, .error, #message')
    .forEach(el => el.remove());

  const content = document.getElementById('wpcontent');
  if (content) { content.style.marginLeft = '0'; content.style.paddingLeft = '0'; }

  const body = document.getElementById('wpbody-content');
  if (body) { body.style.paddingBottom = '0'; }

  document.documentElement.classList.remove('wp-toolbar');
  document.body.style.background = '#f0f0f1';
  document.body.style.minWidth = '0';
  window.scrollTo(0, 0);
})();
"""

# The panels wrap in `.wrap.mcpc`; the assistant takes the window, so it is
# clipped to its own root instead.
PANEL = ".mcpc__shell"
SETUP = ".mcpc-setup"

# Screenshot 5 is the connection test having actually run, not the button that
# starts it. The run registers a throwaway client, authorises it, redeems a
# token and calls the endpoint — then deletes what it created, which is why it
# is safe to fire from here. It takes about seven seconds locally.
RUN_TEST = """
new Promise(resolve => {
  document.getElementById('mcpc-run-test').click();
  const started = Date.now();
  const done = () => {
    const steps = document.getElementById('mcpc-test-steps');
    const finished = steps && !steps.hidden && steps.children.length >= 8;
    if (finished || Date.now() - started > 40000) { resolve(true); }
    else { setTimeout(done, 500); }
  };
  setTimeout(done, 800);
})
"""

SHOTS = [
    # (number, url, viewport height in CSS px, prep script, element to clip to)
    (1, f"{ADMIN}?page=mcp-connector-setup&step=check", 1500, None, SETUP),
    (2, f"{ADMIN}?page=mcp-connector&tab=dashboard", 1600, STRIP_CHROME, PANEL),
    (3, f"{ADMIN}?page=mcp-connector&tab=tools", 1600, STRIP_CHROME, PANEL),
    (4, f"{ADMIN}?page=mcp-connector&tab=security", 1400, STRIP_CHROME, PANEL),
    (5, f"{ADMIN}?page=mcp-connector-setup&step=verify", 1700, RUN_TEST, SETUP),
]


def main():
    chrome = Chrome()

    try:
        chrome.call("Network.enable")
        for name, value in COOKIES:
            chrome.call("Network.setCookie", name=name, value=value,
                        domain="angres2024.ddev.site", path="/",
                        secure=True, httpOnly=True)

        for number, url, max_height, script, clip_to in SHOTS:
            raw = OUT / f"_raw-{number}.png"
            capture(chrome, url, str(raw), SHOOT_WIDTH, height=max_height,
                    scale=2, settle=2.5, script=script, clip_to=clip_to)

            image = Image.open(raw)
            ratio = FINAL_WIDTH / image.size[0]
            image = image.resize(
                (FINAL_WIDTH, round(image.size[1] * ratio)), Image.Resampling.LANCZOS
            ).convert("RGB")

            if image.size[1] >= FINAL_HEIGHT:
                image = image.crop((0, 0, FINAL_WIDTH, FINAL_HEIGHT))
            else:
                # Shorter than the common height: sit it on the admin's own
                # background rather than stretching it.
                padded = Image.new("RGB", (FINAL_WIDTH, FINAL_HEIGHT), (240, 240, 241))
                padded.paste(image, (0, 0))
                image = padded

            image.save(OUT / f"screenshot-{number}.png")
            print(f"  -> screenshot-{number}.png  {image.size[0]}x{image.size[1]}")
            raw.unlink()
    finally:
        chrome.close()


if __name__ == "__main__":
    main()

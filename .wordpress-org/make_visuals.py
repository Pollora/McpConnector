#!/usr/bin/env python3
"""Draw the wordpress.org icon and banner for MCP Connector.

Rendered with Pillow rather than a browser: headless Chrome scales its
screenshots by the host's display factor, and these files have to be exactly
the sizes the directory expects. Everything is drawn at 4x and downsampled,
which is what supplies the antialiasing Pillow's draw calls do not.

The glyph is the same one SettingsPage::mark() renders in wp-admin, so the
directory listing and the settings screen carry one identity. Its geometry is
kept here in the mark's own 44x44 coordinate space and mapped once.
"""
from PIL import Image, ImageDraw, ImageFont

SS = 4  # supersampling factor

# Where the diagonal field begins, as a fraction of the banner width. The text
# block has to end before it.
PATTERN_FROM = 0.74

BAND_TOP = (0, 90, 97)      # oklch(41% .088 200)-ish
BAND_BOTTOM = (0, 52, 58)   # oklch(28% .09 200)
ON_BAND = (221, 240, 240)   # --mcpc-onband
ON_BAND_DIM = (140, 186, 189)

NOTO_BOLD = "/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf"
NOTO_SEMI = "/usr/share/fonts/truetype/noto/NotoSans-SemiBold.ttf"
NOTO_REG = "/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf"


def vertical_gradient(size, top, bottom):
    """A single-pass vertical gradient, drawn one row at a time."""
    w, h = size
    image = Image.new("RGB", (1, h))
    pixels = image.load()

    for y in range(h):
        t = y / max(1, h - 1)
        pixels[0, y] = tuple(round(a + (b - a) * t) for a, b in zip(top, bottom))

    return image.resize((w, h), Image.Resampling.NEAREST)


def draw_mark(draw, x, y, scale, colour, weight=2.4):
    """The connector glyph, in the 44x44 space SettingsPage::mark() uses.

    rect + two antennae on the left, a link across, a panel on the right.
    """
    def p(*coords):
        return [(x + cx * scale, y + cy * scale) for cx, cy in zip(coords[::2], coords[1::2])]

    w = max(1, round(weight * scale))
    r = round(4 * scale)

    # The unit on the left.
    draw.rounded_rectangle(
        [*p(3.2, 12)[0], *p(17.2, 32)[0]], radius=r, outline=colour, width=w,
    )

    # Its two antennae.
    draw.line(p(8, 12, 8, 7), fill=colour, width=w)
    draw.line(p(12.4, 12, 12.4, 7), fill=colour, width=w)

    # The link between them.
    draw.line(p(17.2, 22, 26.8, 22), fill=colour, width=w)

    # The panel on the right: square where it meets the link, rounded away
    # from it — the shape the mark uses to say "this end is the remote one".
    draw.rounded_rectangle(
        [*p(26.8, 12)[0], *p(37.8, 32)[0]],
        radius=round(2 * scale), outline=colour, width=w,
        corners=(False, True, True, False),
    )
    draw.line(p(31, 19, 31, 25), fill=colour, width=w)


def make_icon(size):
    s = size * SS
    base = vertical_gradient((s, s), (0, 100, 107), (0, 46, 52)).convert("RGBA")

    # Rounded-square mask, so the corner radius is antialiased with the rest.
    mask = Image.new("L", (s, s), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, s - 1, s - 1], radius=round(0.22 * s), fill=255)

    icon = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    icon.paste(base, (0, 0), mask)

    # The mark occupies the middle 78% of the tile.
    draw = ImageDraw.Draw(icon)
    scale = (s * 0.78) / 44
    draw_mark(draw, (s - 44 * scale) / 2, (s - 44 * scale) / 2 + 0.5 * scale, scale, ON_BAND)

    return icon.resize((size, size), Image.Resampling.LANCZOS)


def make_banner(width, height):
    w, h = width * SS, height * SS
    banner = vertical_gradient((w, h), (0, 96, 103), (0, 44, 50)).convert("RGBA")

    # A quiet field of diagonals on the right quarter, fading out towards the
    # text. Drawn on its own layer and composited: ImageDraw replaces pixels
    # rather than blending them, so a translucent fill drawn straight onto the
    # banner comes out solid.
    lines = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    pen = ImageDraw.Draw(lines)
    step = round(h / 6)

    for i in range(-2, 16):
        offset = i * step
        pen.line(
            [(PATTERN_FROM * w + offset, h + step), (PATTERN_FROM * w + offset + h, -step)],
            fill=(255, 255, 255, 46), width=max(1, round(h / 150)),
        )

    fade = Image.new("L", (w, 1))
    fade_px = fade.load()
    for x in range(w):
        # Nothing before PATTERN_FROM, full strength a fifth of the way later.
        t = (x / w - PATTERN_FROM) / 0.20
        fade_px[x, 0] = round(255 * max(0.0, min(1.0, t)))

    lines.putalpha(Image.composite(
        lines.getchannel("A"),
        Image.new("L", (w, h), 0),
        fade.resize((w, h), Image.Resampling.BILINEAR),
    ))
    banner = Image.alpha_composite(banner, lines)

    draw = ImageDraw.Draw(banner)

    scale = (h * 0.36) / 44
    mark_w = 44 * scale
    mark_x = w * 0.058
    draw_mark(draw, mark_x, (h - 44 * scale) / 2, scale, ON_BAND)

    title = ImageFont.truetype(NOTO_BOLD, round(h * 0.165))
    subtitle = ImageFont.truetype(NOTO_REG, round(h * 0.068))

    text_x = mark_x + mark_w + h * 0.13
    lines_of_text = [
        (0.475, "MCP Connector", title, ON_BAND),
        (0.635, "Model Context Protocol tools for WordPress", subtitle, ON_BAND_DIM),
        (0.760, "with an OAuth 2.1 provider built in", subtitle, ON_BAND_DIM),
    ]

    for baseline, text, font, colour in lines_of_text:
        draw.text((text_x, h * baseline), text, font=font, fill=colour, anchor="ls")

    # The composition must not run under the diagonals. Checked rather than
    # eyeballed: the two banner sizes are rendered from the same code, so a
    # string that fits one and not the other would otherwise ship half-right.
    longest = max(draw.textlength(text, font=font) for _, text, font, _ in lines_of_text)

    if text_x + longest > w * PATTERN_FROM:
        raise SystemExit(
            f"banner {width}x{height}: text runs to {round(text_x + longest)}px, "
            f"past the {round(w * PATTERN_FROM)}px where the pattern starts"
        )

    return banner.convert("RGB").resize((width, height), Image.Resampling.LANCZOS)


if __name__ == "__main__":
    import sys

    out = sys.argv[1].rstrip("/")

    for size in (128, 256):
        make_icon(size).save(f"{out}/icon-{size}x{size}.png")
        print(f"icon-{size}x{size}.png")

    for width, height in ((772, 250), (1544, 500)):
        make_banner(width, height).save(f"{out}/banner-{width}x{height}.png")
        print(f"banner-{width}x{height}.png")

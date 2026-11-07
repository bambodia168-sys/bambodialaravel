#!/usr/bin/env python3
"""Build a dark-theme portrait with corrected hair-edge matte; preserve light image."""
from __future__ import annotations
from pathlib import Path
import sys
from PIL import Image, ImageFilter


def prepare_dark_portrait(light_path: Path, dark_path: Path) -> None:
    if light_path == dark_path:
        raise ValueError("The source and output must differ")
    with Image.open(light_path) as source:
        portrait = source.convert("RGBA")
    if portrait.size != (570, 478) or portrait.getchannel("A").getextrema() != (0, 255):
        raise ValueError("Expected the enhanced 570x478 transparent portrait")

    alpha = portrait.getchannel("A")
    foreground = alpha.point(lambda value: 255 if value >= 72 else 0)
    near = foreground.filter(ImageFilter.MinFilter(7)).tobytes()
    middle = foreground.filter(ImageFilter.MinFilter(17)).tobytes()
    far = foreground.filter(ImageFilter.MinFilter(31)).tobytes()
    width, height = portrait.size
    result_pixels = []

    for index, (red, green, blue, opacity) in enumerate(portrait.getdata()):
        y = index // width
        if opacity == 0 or y >= 370:
            result_pixels.append((red, green, blue, opacity))
            continue
        # Preserve the face and skin. Only change non-skin edges of hair/headset.
        if red > 115 and red - green > 24 and red - blue > 35:
            result_pixels.append((red, green, blue, opacity))
            continue

        edge_strength = min(
            1.0,
            .39 * (255 - near[index]) / 255
            + .37 * (255 - middle[index]) / 255
            + .24 * (255 - far[index]) / 255,
        )
        light_spill = max(0.0, min(1.0, ((red + green + blue) / 3 - 53) / 105))
        factor = 1 - .45 * edge_strength * light_spill
        corrected = (
            round(red * factor),
            round(green * factor),
            round(max(0, blue * factor - 12 * edge_strength * (blue > red + 10))),
            round(opacity * (1 - .24 * (255 - near[index]) / 255 * edge_strength)),
        )
        result_pixels.append(corrected)

    output = Image.new("RGBA", (width, height))
    output.putdata(result_pixels)
    output.save(dark_path, format="WEBP", quality=94, method=6, exact=True)
    with Image.open(dark_path) as check:
        if check.size != portrait.size or check.getchannel("A").getextrema() != (0, 255):
            raise ValueError("Dark portrait failed transparency validation")
    if dark_path.stat().st_size < 15000:
        raise ValueError("Dark portrait unexpectedly small")
    print(f"Dark-theme hair matte corrected: {width}x{height}, {dark_path.stat().st_size} bytes")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: python prepare-support-agent-dark.py LIGHT.webp DARK.webp")
    prepare_dark_portrait(Path(sys.argv[1]), Path(sys.argv[2]))

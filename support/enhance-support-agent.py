#!/usr/bin/env python3
"""Prepare a larger, clearer transparent Support Center portrait for release ZIPs.

Run only in the ephemeral GitHub Actions build workspace; this never changes
production installations or the committed 380px source asset.
"""
from __future__ import annotations
from pathlib import Path
import sys

from PIL import Image, ImageEnhance, ImageFilter


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python support/enhance-support-agent.py IMAGE_PATH")
    path = Path(sys.argv[1])
    if path.name != "support-agent-photo.webp" or not path.is_file():
        raise SystemExit("Unexpected/missing Support Center portrait path")

    with Image.open(path) as source:
        if source.format != "WEBP":
            raise SystemExit("Expected the trusted original WebP asset")
        portrait = source.convert("RGBA")

    if portrait.size == (570, 478):
        print("Support agent image already enhanced; no changes")
        return
    if portrait.size != (380, 319):
        raise SystemExit(f"Unexpected source dimensions: {portrait.size}")
    alpha = portrait.getchannel("A")
    if alpha.getextrema() != (0, 255):
        raise SystemExit("Portrait transparency missing")

    output_size = (570, 478)
    resized_alpha = alpha.resize(output_size, Image.Resampling.LANCZOS)
    enhanced = portrait.convert("RGB").resize(output_size, Image.Resampling.LANCZOS)
    enhanced = enhanced.filter(
        ImageFilter.UnsharpMask(radius=0.95, percent=120, threshold=2)
    )
    enhanced = ImageEnhance.Contrast(enhanced).enhance(1.06)
    enhanced = ImageEnhance.Color(enhanced).enhance(1.03)
    enhanced.putalpha(resized_alpha)
    enhanced.save(path, format="WEBP", quality=93, method=6, exact=True)

    with Image.open(path) as result:
        if result.size != output_size or result.getchannel("A").getextrema() != (0, 255):
            raise SystemExit("Enhanced portrait verification failed")
    if path.stat().st_size < 15000:
        raise SystemExit("Unexpectedly small encoded portrait")
    print(f"Enhanced transparent support agent: {output_size[0]}x{output_size[1]}, {path.stat().st_size} bytes")


if __name__ == "__main__":
    main()

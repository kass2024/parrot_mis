#!/usr/bin/env python3
"""
Remove black backdrop from Parrot logo PNG by flood-filling transparency
from image edges (only outer frame — keeps interior dark colors intact).
"""
from __future__ import annotations

import sys
from collections import deque
from pathlib import Path

try:
    from PIL import Image
except ImportError:
    print("Install Pillow: pip install Pillow", file=sys.stderr)
    sys.exit(1)


def main() -> None:
    root = Path(__file__).resolve().parents[1]
    src = Path(sys.argv[1]) if len(sys.argv) > 1 else root / "parrot-canada-logo-src.png"
    out = Path(sys.argv[2]) if len(sys.argv) > 2 else root / "parrot-canada-logo.png"
    thr = int(sys.argv[3]) if len(sys.argv) > 3 else 48

    if not src.is_file():
        print(f"Source not found: {src}", file=sys.stderr)
        sys.exit(1)

    img = Image.open(src).convert("RGBA")
    w, h = img.size
    px = img.load()

    def is_bg(x: int, y: int) -> bool:
        r, g, b, _a = px[x, y]
        return r <= thr and g <= thr and b <= thr

    transparent = [[False] * w for _ in range(h)]
    q: deque[tuple[int, int]] = deque()

    for x in range(w):
        for y in (0, h - 1):
            if is_bg(x, y):
                q.append((x, y))
    for y in range(h):
        for x in (0, w - 1):
            if is_bg(x, y):
                q.append((x, y))

    seen = set(q)
    while q:
        x, y = q.popleft()
        transparent[y][x] = True
        for nx, ny in ((x - 1, y), (x + 1, y), (x, y - 1), (x, y + 1)):
            if 0 <= nx < w and 0 <= ny < h and (nx, ny) not in seen and is_bg(nx, ny):
                seen.add((nx, ny))
                q.append((nx, ny))

    for y in range(h):
        for x in range(w):
            if transparent[y][x]:
                r, g, b, _ = px[x, y]
                px[x, y] = (r, g, b, 0)

    img.save(out, "PNG", optimize=True)
    print(f"Wrote {out} ({w}x{h}) — edge-connected black removed (thr={thr})")


if __name__ == "__main__":
    main()

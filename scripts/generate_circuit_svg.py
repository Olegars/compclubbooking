#!/usr/bin/env python3
"""Neon-green chip / HUD schematic → SVG.

Recreates the screenshot look: black field, nested rectilinear blocks,
memory-array mesh, routing tracks, crosshairs, and a faint neon glow.
Default size matches the source image (642×479). Same seed → same file.
"""

from __future__ import annotations

import argparse
import random
from pathlib import Path

# Source screenshot size
DEFAULT_W = 642
DEFAULT_H = 479
DEFAULT_SEED = 21

GREEN_DIM = "#0a3d14"
GREEN_MID = "#0f7a28"
GREEN_HOT = "#1cff4a"
GREEN_CORE = "#7dff9a"


class Svg:
    def __init__(self, w: int, h: int) -> None:
        self.w = w
        self.h = h
        self.bg: list[str] = []
        self.mid: list[str] = []
        self.fg: list[str] = []

    def _push(self, layer: str, el: str) -> None:
        getattr(self, layer).append(el)

    def line(
        self,
        x1: float,
        y1: float,
        x2: float,
        y2: float,
        *,
        layer: str = "mid",
        stroke: str = GREEN_MID,
        sw: float = 0.4,
        op: float = 0.7,
        extra: str = "",
    ) -> None:
        self._push(
            layer,
            f'<line x1="{x1:.2f}" y1="{y1:.2f}" x2="{x2:.2f}" y2="{y2:.2f}"'
            f' stroke="{stroke}" stroke-width="{sw}" stroke-opacity="{op}"'
            f' stroke-linecap="square"{extra}/>',
        )

    def rect(
        self,
        x: float,
        y: float,
        w: float,
        h: float,
        *,
        layer: str = "mid",
        fill: str = "none",
        stroke: str | None = GREEN_MID,
        sw: float = 0.45,
        fill_op: float | None = None,
        stroke_op: float | None = None,
        extra: str = "",
    ) -> None:
        bits = [f'<rect x="{x:.2f}" y="{y:.2f}" width="{w:.2f}" height="{h:.2f}" fill="{fill}"']
        if stroke:
            bits.append(f' stroke="{stroke}" stroke-width="{sw}"')
        else:
            bits.append(' stroke="none"')
        if fill_op is not None:
            bits.append(f' fill-opacity="{fill_op}"')
        if stroke_op is not None:
            bits.append(f' stroke-opacity="{stroke_op}"')
        bits.append(f"{extra}/>")
        self._push(layer, "".join(bits))

    def dumps(self) -> str:
        defs = f"""  <defs>
    <filter id="glow" x="-8%" y="-8%" width="116%" height="116%">
      <feGaussianBlur stdDeviation="0.7" result="b"/>
      <feMerge>
        <feMergeNode in="b"/>
        <feMergeNode in="SourceGraphic"/>
      </feMerge>
    </filter>
    <pattern id="bitcell" width="3" height="3" patternUnits="userSpaceOnUse">
      <rect x="0.4" y="0.4" width="2.2" height="2.2" fill="none"
            stroke="{GREEN_MID}" stroke-width="0.22" stroke-opacity="0.55"/>
    </pattern>
    <pattern id="dots" width="2.2" height="2.2" patternUnits="userSpaceOnUse">
      <circle cx="1.1" cy="1.1" r="0.32" fill="{GREEN_HOT}" fill-opacity="0.55"/>
    </pattern>
    <pattern id="rows" width="8" height="2.6" patternUnits="userSpaceOnUse">
      <line x1="0" y1="1.3" x2="8" y2="1.3" stroke="{GREEN_MID}"
            stroke-width="0.35" stroke-opacity="0.5"/>
    </pattern>
    <pattern id="cols" width="2.6" height="8" patternUnits="userSpaceOnUse">
      <line x1="1.3" y1="0" x2="1.3" y2="8" stroke="{GREEN_MID}"
            stroke-width="0.35" stroke-opacity="0.5"/>
    </pattern>
    <pattern id="hatch" width="4" height="4" patternUnits="userSpaceOnUse">
      <path d="M0 0H4M0 2H4" stroke="{GREEN_MID}" stroke-width="0.25" stroke-opacity="0.42"/>
    </pattern>
    <pattern id="gridfine" width="6" height="6" patternUnits="userSpaceOnUse">
      <path d="M6 0V6H0" fill="none" stroke="{GREEN_DIM}" stroke-width="0.25" stroke-opacity="0.7"/>
    </pattern>
    <pattern id="vias" width="5" height="5" patternUnits="userSpaceOnUse">
      <rect x="1.7" y="1.7" width="1.6" height="1.6" fill="{GREEN_HOT}" fill-opacity="0.45"/>
    </pattern>
  </defs>
"""
        body = [
            f'<svg xmlns="http://www.w3.org/2000/svg" width="{self.w}" height="{self.h}"',
            f' viewBox="0 0 {self.w} {self.h}" shape-rendering="crispEdges">',
            defs,
            f'  <rect width="{self.w}" height="{self.h}" fill="#000"/>',
            '  <g id="bg" fill="none">',
            *("    " + x for x in self.bg),
            "  </g>",
            '  <g id="mid" fill="none">',
            *("    " + x for x in self.mid),
            "  </g>",
            '  <g id="fg" fill="none" filter="url(#glow)">',
            *("    " + x for x in self.fg),
            "  </g>",
            "</svg>\n",
        ]
        return "\n".join(body)


class Board:
    def __init__(self, svg: Svg, rng: random.Random) -> None:
        self.s = svg
        self.rng = rng
        self.w = svg.w
        self.h = svg.h
        self.cx = svg.w / 2.0

    # ----- symmetry helpers: draw on the left, mirror to the right -----
    def mline(
        self,
        x1: float,
        y1: float,
        x2: float,
        y2: float,
        **kw: object,
    ) -> None:
        self.s.line(x1, y1, x2, y2, **kw)  # type: ignore[arg-type]
        self.s.line(self.w - x1, y1, self.w - x2, y2, **kw)  # type: ignore[arg-type]

    def mrect(self, x: float, y: float, w: float, h: float, **kw: object) -> None:
        self.s.rect(x, y, w, h, **kw)  # type: ignore[arg-type]
        self.s.rect(self.w - x - w, y, w, h, **kw)  # type: ignore[arg-type]

    def frame(
        self,
        x: float,
        y: float,
        w: float,
        h: float,
        *,
        rings: int = 1,
        gap: float = 2.2,
        layer: str = "mid",
        stroke: str = GREEN_MID,
        sw: float = 0.45,
        op: float = 0.75,
    ) -> None:
        for i in range(rings):
            d = i * gap
            if w - 2 * d < 2 or h - 2 * d < 2:
                break
            self.mrect(
                x + d,
                y + d,
                w - 2 * d,
                h - 2 * d,
                layer=layer,
                stroke=stroke,
                sw=sw if i == 0 else max(0.25, sw * 0.75),
                stroke_op=op if i == 0 else op * 0.7,
            )

    def brackets(self, x: float, y: float, w: float, h: float, arm: float, **kw: object) -> None:
        arm = min(arm, w * 0.35, h * 0.35)
        pts = [
            (x, y, x + arm, y),
            (x, y, x, y + arm),
            (x + w, y, x + w - arm, y),
            (x + w, y, x + w, y + arm),
            (x, y + h, x + arm, y + h),
            (x, y + h, x, y + h - arm),
            (x + w, y + h, x + w - arm, y + h),
            (x + w, y + h, x + w, y + h - arm),
        ]
        for a, b, c, d in pts:
            self.mline(a, b, c, d, **kw)  # type: ignore[arg-type]

    def cross(self, cx: float, cy: float, size: float, **kw: object) -> None:
        self.mline(cx - size, cy, cx + size, cy, **kw)  # type: ignore[arg-type]
        self.mline(cx, cy - size, cx, cy + size, **kw)  # type: ignore[arg-type]
        tick = max(1.1, size * 0.28)
        for dx, dy in ((-size, 0), (size, 0), (0, -size), (0, size)):
            if dx == 0:
                self.mline(cx - tick, cy + dy, cx + tick, cy + dy, **kw)  # type: ignore[arg-type]
            else:
                self.mline(cx + dx, cy - tick, cx + dx, cy + tick, **kw)  # type: ignore[arg-type]

    def ticks(
        self,
        x: float,
        y: float,
        w: float,
        h: float,
        *,
        step: float = 4,
        length: float = 1.6,
        **kw: object,
    ) -> None:
        n = int(w / step)
        for i in range(1, n):
            px = x + i * step
            self.mline(px, y, px, y + length, **kw)  # type: ignore[arg-type]
            self.mline(px, y + h, px, y + h - length, **kw)  # type: ignore[arg-type]
        n = int(h / step)
        for i in range(1, n):
            py = y + i * step
            self.mline(x, py, x + length, py, **kw)  # type: ignore[arg-type]
            self.mline(x + w, py, x + w - length, py, **kw)  # type: ignore[arg-type]

    # ----- block types -----
    def fill_pattern(self, x: float, y: float, w: float, h: float, pid: str, op: float = 0.9) -> None:
        pad = 1.2
        if w < 4 or h < 4:
            return
        self.mrect(
            x + pad,
            y + pad,
            w - 2 * pad,
            h - 2 * pad,
            layer="mid",
            fill=f"url(#{pid})",
            stroke="none",
            fill_op=op,
        )

    def tiny_cells(self, x: float, y: float, w: float, h: float, cols: int, rows: int) -> None:
        gap = 0.7
        cw = (w - gap * (cols + 1)) / cols
        ch = (h - gap * (rows + 1)) / rows
        if cw < 1.2 or ch < 1.2:
            return
        for j in range(rows):
            for i in range(cols):
                bx = x + gap + i * (cw + gap)
                by = y + gap + j * (ch + gap)
                hot = self.rng.random() < 0.12
                self.mrect(
                    bx,
                    by,
                    cw,
                    ch,
                    layer="fg" if hot else "mid",
                    stroke=GREEN_HOT if hot else GREEN_MID,
                    sw=0.3,
                    stroke_op=0.95 if hot else 0.55,
                )

    def decorate(self, x: float, y: float, w: float, h: float, depth: int) -> None:
        rng = self.rng
        area = w * h
        kind_roll = rng.random()

        self.frame(
            x,
            y,
            w,
            h,
            rings=1,
            layer="bg" if depth > 3 else "mid",
            stroke=GREEN_DIM if depth > 3 else GREEN_MID,
            sw=0.35 if depth > 2 else 0.5,
            op=0.45 + 0.12 * max(0, 4 - depth),
        )

        if w < 10 or h < 10:
            return

        if area > 2800 and kind_roll < 0.34:
            # memory macro
            self.frame(x, y, w, h, rings=2, gap=2.0, layer="fg", stroke=GREEN_HOT, sw=0.55, op=0.9)
            pid = rng.choice(["bitcell", "dots", "hatch"])
            self.fill_pattern(x + 2, y + 2, w - 4, h - 4, pid, op=0.85)
            self.brackets(x, y, w, h, min(7, w * 0.18), layer="fg", stroke=GREEN_HOT, sw=0.6, op=0.95)
            self.cross(x + w * 0.5, y + h * 0.5, min(5, w, h) * 0.18, layer="fg", stroke=GREEN_CORE, sw=0.4, op=0.8)
            if w > 28:
                strip = 3.5
                self.mrect(x + 2, y + 2, strip, h - 4, layer="mid", stroke=GREEN_MID, sw=0.3, stroke_op=0.5)
                self.fill_pattern(x + 2, y + 2, strip, h - 4, "vias", op=0.7)
        elif area > 1400 and kind_roll < 0.62:
            # standard-cell / routing
            self.frame(x, y, w, h, rings=2, gap=1.8, layer="mid", stroke=GREEN_MID, sw=0.4, op=0.7)
            pid = "rows" if w >= h else "cols"
            self.fill_pattern(x, y, w, h, pid, op=0.8)
            # power stripes
            n = max(2, int(min(w, h) / 14))
            if w >= h:
                for i in range(1, n):
                    px = x + w * i / n
                    self.mline(px, y + 1.5, px, y + h - 1.5, layer="fg", stroke=GREEN_HOT, sw=0.35, op=0.55)
            else:
                for i in range(1, n):
                    py = y + h * i / n
                    self.mline(x + 1.5, py, x + w - 1.5, py, layer="fg", stroke=GREEN_HOT, sw=0.35, op=0.55)
            self.ticks(x, y, w, h, step=4, length=1.4, layer="bg", stroke=GREEN_MID, sw=0.25, op=0.4)
        elif kind_roll < 0.78 and w > 16 and h > 16:
            # nested cells
            self.frame(x, y, w, h, rings=1, layer="fg", stroke=GREEN_HOT, sw=0.4, op=0.75)
            cols = max(2, min(8, int(w / 7)))
            rows = max(2, min(8, int(h / 7)))
            self.tiny_cells(x + 1.5, y + 1.5, w - 3, h - 3, cols, rows)
        elif kind_roll < 0.90:
            self.fill_pattern(x, y, w, h, rng.choice(["gridfine", "hatch", "vias"]), op=0.7)
            self.frame(x, y, w, h, rings=2, gap=1.6, layer="mid", stroke=GREEN_MID, sw=0.35, op=0.65)
            if min(w, h) > 14:
                self.cross(x + w / 2, y + h / 2, min(w, h) * 0.22, layer="fg", stroke=GREEN_HOT, sw=0.35, op=0.7)
        else:
            self.frame(x, y, w, h, rings=3, gap=1.7, layer="fg", stroke=GREEN_HOT, sw=0.4, op=0.8)
            self.brackets(x, y, w, h, min(6, w * 0.2), layer="fg", stroke=GREEN_CORE, sw=0.45, op=0.85)
            if min(w, h) > 12:
                self.cross(x + w / 2, y + h / 2, min(4.5, min(w, h) * 0.25), layer="fg", stroke=GREEN_CORE, sw=0.4, op=0.9)

    def partition(self, x: float, y: float, w: float, h: float, depth: int = 0, max_depth: int = 6) -> None:
        rng = self.rng
        min_span = 13.0
        too_small = w < min_span * 1.55 or h < min_span * 1.55
        stop = depth >= max_depth or too_small
        if not stop and depth >= 2:
            stop = rng.random() < (0.10 + depth * 0.06)
        if stop:
            self.decorate(x, y, w, h, depth)
            return

        gap = 2.4 if depth < 2 else 1.8
        split_v = w > h * 1.15 or (abs(w - h) < 8 and rng.random() < 0.5)
        if split_v:
            n = 2 if w < 90 else rng.choice([2, 2, 3])
            inner = w - gap * (n - 1)
            weights = [rng.uniform(0.75, 1.35) for _ in range(n)]
            ssum = sum(weights)
            cx = x
            for i, wt in enumerate(weights):
                ww = inner * (wt / ssum)
                self.partition(cx, y, ww, h, depth + 1, max_depth)
                cx += ww + gap
        else:
            n = 2 if h < 80 else rng.choice([2, 2, 3])
            inner = h - gap * (n - 1)
            weights = [rng.uniform(0.75, 1.35) for _ in range(n)]
            ssum = sum(weights)
            cy = y
            for i, wt in enumerate(weights):
                hh = inner * (wt / ssum)
                self.partition(x, cy, w, hh, depth + 1, max_depth)
                cy += hh + gap

    def global_grid(self) -> None:
        # faint construction grid across the whole die
        step = 8
        for x in range(0, self.w + 1, step):
            major = x % 32 == 0
            self.s.line(
                x,
                0,
                x,
                self.h,
                layer="bg",
                stroke=GREEN_DIM,
                sw=0.35 if major else 0.18,
                op=0.35 if major else 0.16,
            )
        for y in range(0, self.h + 1, step):
            major = y % 32 == 0
            self.s.line(
                0,
                y,
                self.w,
                y,
                layer="bg",
                stroke=GREEN_DIM,
                sw=0.35 if major else 0.18,
                op=0.35 if major else 0.16,
            )

    def buses_and_spine(self) -> None:
        # center spine (not mirrored)
        cx = self.cx
        self.s.rect(
            cx - 7,
            10,
            14,
            self.h - 20,
            layer="fg",
            stroke=GREEN_HOT,
            sw=0.55,
            stroke_op=0.7,
        )
        self.s.rect(
            cx - 3.5,
            14,
            7,
            self.h - 28,
            layer="mid",
            fill="url(#cols)",
            stroke=GREEN_MID,
            sw=0.3,
            stroke_op=0.5,
            fill_op=0.8,
        )
        for y in (28, self.h * 0.33, self.h * 0.5, self.h * 0.67, self.h - 28):
            self.s.line(cx - 9, y, cx + 9, y, layer="fg", stroke=GREEN_CORE, sw=0.45, op=0.85)
            self.cross(cx, y, 4.2, layer="fg", stroke=GREEN_CORE, sw=0.4, op=0.9)

        # full-width / full-height buses
        for y in (18, 42, self.h * 0.25, self.h * 0.5, self.h * 0.75, self.h - 18):
            self.s.line(10, y, self.w - 10, y, layer="mid", stroke=GREEN_MID, sw=0.35, op=0.32)
        for x in (18, self.w * 0.25, self.w * 0.75, self.w - 18):
            self.s.line(x, 10, x, self.h - 10, layer="mid", stroke=GREEN_MID, sw=0.35, op=0.28)

        # outer die ring
        self.s.rect(3, 3, self.w - 6, self.h - 6, layer="fg", stroke=GREEN_HOT, sw=0.7, stroke_op=0.85)
        self.s.rect(7, 7, self.w - 14, self.h - 14, layer="mid", stroke=GREEN_MID, sw=0.4, stroke_op=0.55)
        self.brackets(3, 3, self.w - 6, self.h - 6, 14, layer="fg", stroke=GREEN_CORE, sw=0.7, op=0.95)

        # pad-like ticks on the outer ring
        for x in range(24, self.w - 24, 12):
            self.s.line(x, 3, x, 7, layer="mid", stroke=GREEN_HOT, sw=0.4, op=0.55)
            self.s.line(x, self.h - 3, x, self.h - 7, layer="mid", stroke=GREEN_HOT, sw=0.4, op=0.55)
        for y in range(24, self.h - 24, 12):
            self.s.line(3, y, 7, y, layer="mid", stroke=GREEN_HOT, sw=0.4, op=0.55)
            self.s.line(self.w - 3, y, self.w - 7, y, layer="mid", stroke=GREEN_HOT, sw=0.4, op=0.55)

    def build(self) -> None:
        self.global_grid()
        self.buses_and_spine()
        # floorplan lives on the left half, then mirrored
        m = 12.0
        left = m
        top = m
        width = self.cx - 10 - m
        height = self.h - 2 * m
        self.partition(left, top, width, height, depth=0, max_depth=6)
        # extra overlay of a few bright floating frames
        rng = self.rng
        for _ in range(9):
            bw = rng.uniform(18, 70)
            bh = rng.uniform(14, 52)
            x = rng.uniform(m + 4, self.cx - 16 - bw)
            y = rng.uniform(m + 4, self.h - m - bh)
            self.frame(x, y, bw, bh, rings=1, layer="fg", stroke=GREEN_HOT, sw=0.5, op=0.55)
            if rng.random() < 0.4:
                self.cross(x + bw / 2, y + bh / 2, min(bw, bh) * 0.2, layer="fg", stroke=GREEN_CORE, sw=0.35, op=0.6)


def generate(width: int, height: int, seed: int) -> str:
    rng = random.Random(seed)
    svg = Svg(width, height)
    Board(svg, rng).build()
    return svg.dumps()


def main() -> None:
    p = argparse.ArgumentParser(description="Generate a neon-green circuit / chip-layout SVG.")
    p.add_argument("-o", "--output", default=str(Path(__file__).with_name("circuit.svg")))
    p.add_argument("--width", type=int, default=DEFAULT_W)
    p.add_argument("--height", type=int, default=DEFAULT_H)
    p.add_argument("--seed", type=int, default=DEFAULT_SEED)
    args = p.parse_args()
    svg = generate(args.width, args.height, args.seed)
    out = Path(args.output)
    out.write_text(svg, encoding="utf-8")
    print(f"Wrote {out.resolve()} ({args.width}x{args.height}, seed={args.seed})")


if __name__ == "__main__":
    main()

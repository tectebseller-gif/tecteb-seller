#!/usr/bin/env python3
"""
رندرهای سه‌بعدیِ Concept C (دور ۳) — بدون هیچ دارایی بیرونی جز فونت و لوگوی خودِ پروژه.

این تصویرها «عکس محصول» نیستند و روی صفحه هم «تصویر نمایشی» نامیده می‌شوند:
مدل‌های ساده‌شدهٔ تجهیزات واقعی — مانیتور علائم حیاتی روی پایه، پالس‌اکسیمتر
انگشتی و سرنگ‌پمپ — با ray-marching روی SDF ساخته می‌شوند. صفحهٔ مانیتور یک
بافت واقعی دارد (سه منحنی، چهار عدد) و منحنی ECG آن خودِ موج لوگوی تک‌طب است.
خروجی WebP شفاف با سایهٔ تماس روی یک کف نامرئی.

    python3 homepage-redesign/tools/render-3d.py [--fast] [hero-dark hero-light monitor oximeter pump]

بازتولیدپذیر: هیچ عدد تصادفی‌ای نیست.
"""
import sys
import time
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "concept-c" / "assets"
FONT = ROOT / "shared" / "fonts" / "Vazirmatn-wght.woff2"
LOGO = ROOT / "shared" / "brand" / "tecteb-logo-mark-512.png"
FAST = "--fast" in sys.argv
F = np.float32

# ================================================================ math

def norm(v):
    return v / np.linalg.norm(v, axis=-1, keepdims=True)

def rx(a):
    a = np.radians(a); c, s = np.cos(a), np.sin(a)
    return np.array([[1, 0, 0], [0, c, -s], [0, s, c]], F)

def ry(a):
    a = np.radians(a); c, s = np.cos(a), np.sin(a)
    return np.array([[c, 0, s], [0, 1, 0], [-s, 0, c]], F)

def rz(a):
    a = np.radians(a); c, s = np.cos(a), np.sin(a)
    return np.array([[c, -s, 0], [s, c, 0], [0, 0, 1]], F)

I3 = np.eye(3, dtype=F)

def sd_round_box(q, b, r):
    d = np.abs(q) - (b - r)
    return np.linalg.norm(np.maximum(d, 0), axis=-1) + np.minimum(d.max(axis=-1), 0) - r

def sd_capsule(q, a, b, r):
    pa, ba = q - a, b - a
    h = np.clip((pa @ ba) / (ba @ ba), 0, 1)
    return np.linalg.norm(pa - h[:, None] * ba, axis=-1) - r

def sd_torus(q, R, r):
    xz = np.sqrt(q[:, 0] ** 2 + q[:, 2] ** 2) - R
    return np.sqrt(xz ** 2 + q[:, 1] ** 2) - r

def sd_cyl(q, r, h, rr):
    """Rounded capped cylinder along local y."""
    d = np.stack([np.sqrt(q[:, 0] ** 2 + q[:, 2] ** 2) - r + rr, np.abs(q[:, 1]) - h + rr], -1)
    return np.minimum(d.max(-1), 0) + np.linalg.norm(np.maximum(d, 0), axis=-1) - rr

def sd_sphere(q, r):
    return np.linalg.norm(q, axis=-1) - r

# ================================================================ materials

WHITE, SCREEN, TEAL, NAVY, GLASS, GRAY, BRAND, BASE, DARK = range(1, 10)
ALBEDO = {
    WHITE: (0.92, 0.94, 0.96), SCREEN: (0.01, 0.03, 0.05), TEAL: (0.05, 0.50, 0.54),
    NAVY: (0.07, 0.22, 0.37), GLASS: (0.70, 0.86, 0.90), GRAY: (0.68, 0.74, 0.78),
    BRAND: (0.28, 0.56, 0.80), BASE: (0.16, 0.22, 0.27), DARK: (0.10, 0.13, 0.16),
}
GLOSS = {WHITE: 40, SCREEN: 180, TEAL: 70, NAVY: 60, GLASS: 160, GRAY: 90, BRAND: 110, BASE: 50, DARK: 60}
SPEC = {WHITE: 0.30, SCREEN: 0.55, TEAL: 0.55, NAVY: 0.5, GLASS: 1.0, GRAY: 0.85, BRAND: 0.8, BASE: 0.45, DARK: 0.5}

# ================================================================ screen textures

def font(size, weight="Bold"):
    f = ImageFont.truetype(str(FONT), size)
    f.set_variation_by_name(weight)
    return f

def logo_wave(height):
    """The white wave of the Tecteb mark — its largest white component, as a mask."""
    lg = Image.open(LOGO).convert("RGBA")
    a = np.asarray(lg).astype(int)
    h0, w0 = a.shape[:2]
    yy, xx = np.mgrid[0:h0, 0:w0]
    inside = np.hypot(xx - w0 / 2, yy - h0 / 2) < 0.465 * w0   # off the anti-aliased rim
    white = (a[..., :3].min(-1) > 225) & (a[..., 3] > 250) & inside
    # keep the biggest connected blob (drops the three small squares)
    h, w = white.shape
    lab = np.zeros((h, w), int)
    best, best_n, cur = 0, 0, 0
    for y0, x0 in zip(*np.nonzero(white)):
        if lab[y0, x0]:
            continue
        cur += 1
        stack, n = [(y0, x0)], 0
        lab[y0, x0] = cur
        while stack:
            y, x = stack.pop(); n += 1
            for yy, xx in ((y + 1, x), (y - 1, x), (y, x + 1), (y, x - 1)):
                if 0 <= yy < h and 0 <= xx < w and white[yy, xx] and not lab[yy, xx]:
                    lab[yy, xx] = cur; stack.append((yy, xx))
        if n > best_n:
            best, best_n = cur, n
    mask = Image.fromarray(((lab == best) * 255).astype(np.uint8))
    bbox = mask.getbbox()
    mask = mask.crop(bbox)
    s = height / mask.height
    return mask.resize((max(1, int(mask.width * s)), height), Image.LANCZOS)

def glow_draw(base, layer_fn, colour, blur):
    """Draw with a soft phosphor glow under a crisp line."""
    layer = Image.new("L", base.size, 0)
    layer_fn(ImageDraw.Draw(layer))
    halo = layer.filter(ImageFilter.GaussianBlur(blur))
    base.paste(Image.new("RGB", base.size, colour), (0, 0), halo.point(lambda v: int(v * 0.55)))
    base.paste(Image.new("RGB", base.size, colour), (0, 0), layer)

def tex_monitor():
    W, H = 1000, 640
    im = Image.new("RGB", (W, H), (4, 15, 24))
    d = ImageDraw.Draw(im)
    for x in range(0, W, 40):
        d.line([(x, 0), (x, H)], fill=(9, 26, 38))
    for y in range(0, H, 40):
        d.line([(0, y), (W, y)], fill=(9, 26, 38))
    split = 650
    d.line([(split, 0), (split, H)], fill=(24, 52, 66), width=2)
    for y in (213, 426):
        d.line([(0, y), (split, y)], fill=(18, 40, 54), width=2)
        d.line([(split, y), (W, y)], fill=(24, 52, 66), width=2)

    green, cyan, amber, white = (96, 232, 196), (98, 202, 246), (244, 194, 80), (232, 240, 244)
    small, mid, big = font(24, "SemiBold"), font(30, "Bold"), font(104, "Bold")

    # Row 1 — ECG: the logo's own wave, then a flat baseline
    # two beats of the mark, each joined to the next at its own baseline
    wave = logo_wave(150)
    wave2 = wave.resize((int(wave.width * 0.72), int(wave.height * 0.72)), Image.LANCZOS)

    def ends(m):
        a = np.asarray(m) > 128
        return int(np.nonzero(a[:, 0])[0].mean()), int(np.nonzero(a[:, -1])[0].mean())

    l1, r1 = ends(wave); l2, r2 = ends(wave2)
    x1, y1 = 60, 40
    x2 = x1 + wave.width + 80
    y2 = (y1 + r1) - l2
    segs = [((20, y1 + l1), (x1 + 2, y1 + l1)),
            ((x1 + wave.width - 2, y1 + r1), (x2 + 2, y1 + r1)),
            ((x2 + wave2.width - 2, y2 + r2), (split - 20, y2 + r2))]
    glow_draw(im, lambda dr: [dr.line(sg, fill=255, width=6) for sg in segs], green, 8)
    stamp = Image.new("RGB", im.size, green)
    for m, at in ((wave, (x1, y1)), (wave2, (x2, y2))):
        layer = Image.new("L", im.size, 0); layer.paste(m, at)
        im.paste(stamp, (0, 0), layer.filter(ImageFilter.GaussianBlur(8)).point(lambda v: int(v * 0.55)))
        im.paste(stamp, (0, 0), layer)
    d.text((20, 12), "ECG  II", font=small, fill=green)

    # Row 2 — SpO2 plethysmograph
    xs = np.arange(20, split - 20)
    ph = np.mod(xs - 20, 150) / 150
    y = 340 - 70 * (np.exp(-((ph - 0.22) / 0.09) ** 2) + 0.35 * np.exp(-((ph - 0.5) / 0.08) ** 2))
    glow_draw(im, lambda dr: dr.line(list(zip(xs, y)), fill=255, width=6, joint="curve"), cyan, 8)
    d.text((20, 225), "SpO2", font=small, fill=cyan)

    # Row 3 — respiration
    y3 = 540 - 42 * np.sin((xs - 20) / 95 * np.pi)
    glow_draw(im, lambda dr: dr.line(list(zip(xs, y3)), fill=255, width=5, joint="curve"), amber, 7)
    d.text((20, 438), "RESP", font=small, fill=amber)

    # Numerics column
    def num(y0, label, value, unit, colour):
        d.text((split + 24, y0 + 14), label, font=mid, fill=colour)
        d.text((W - 26, y0 + 18), unit, font=small, fill=colour, anchor="ra")
        d.text((W - 30, y0 + 200), value, font=big, fill=colour, anchor="rs")
    num(0, "HR", "72", "bpm", green)
    num(213, "SpO2", "98", "%", cyan)
    d.text((split + 24, 440), "NIBP", font=mid, fill=white)
    d.text((W - 26, 444), "mmHg", font=small, fill=white, anchor="ra")
    d.text((W - 30, 600), "120/80", font=font(70, "Bold"), fill=white, anchor="rs")
    return np.asarray(im).astype(F) / 255

def tex_oximeter():
    W, H = 420, 220
    im = Image.new("RGB", (W, H), (3, 12, 20))
    d = ImageDraw.Draw(im)
    cyan, green = (98, 202, 246), (96, 232, 196)
    d.text((22, 16), "%SpO2", font=font(26, "SemiBold"), fill=cyan)
    d.text((205, 190), "98", font=font(140, "Bold"), fill=cyan, anchor="rs")
    d.text((240, 16), "PRbpm", font=font(26, "SemiBold"), fill=green)
    d.text((398, 190), "72", font=font(140, "Bold"), fill=green, anchor="rs")
    for i in range(6):  # pulse-strength bar
        d.rectangle([214, 184 - i * 14, 224, 194 - i * 14], fill=cyan)
    return np.asarray(im).astype(F) / 255

def tex_pump():
    W, H = 520, 260
    im = Image.new("RGB", (W, H), (4, 15, 24))
    d = ImageDraw.Draw(im)
    green, white = (96, 232, 196), (232, 240, 244)
    d.text((24, 18), "RATE", font=font(28, "SemiBold"), fill=white)
    d.text((496, 22), "ml/h", font=font(28, "SemiBold"), fill=white, anchor="ra")
    d.text((496, 190), "5.0", font=font(130, "Bold"), fill=green, anchor="rs")
    d.rectangle([24, 214, 496, 236], outline=(40, 80, 96), width=3)
    d.rectangle([28, 218, 330, 232], fill=green)
    return np.asarray(im).astype(F) / 255

TEX = {}

def sample(tex, u, v):
    h, w, _ = tex.shape
    x = np.clip(u, 0, 1) * (w - 1); y = np.clip(v, 0, 1) * (h - 1)
    x0 = np.floor(x).astype(int); y0 = np.floor(y).astype(int)
    x1 = np.minimum(x0 + 1, w - 1); y1 = np.minimum(y0 + 1, h - 1)
    fx = (x - x0)[:, None]; fy = (y - y0)[:, None]
    return (tex[y0, x0] * (1 - fx) * (1 - fy) + tex[y0, x1] * fx * (1 - fy)
            + tex[y1, x0] * (1 - fx) * fy + tex[y1, x1] * fx * fy)

# ================================================================ objects

class Prim:
    def __init__(self, kind, at, rot=I3, mat=WHITE, cut=None, tex=None, **p):
        self.kind, self.at, self.rot, self.mat, self.cut, self.tex, self.p = kind, np.array(at, F), rot, mat, cut, tex, p

    def placed(self, C, R):
        q = Prim(self.kind, C + R @ self.at, R @ self.rot, self.mat, self.cut, self.tex, **self.p)
        return q

    def local(self, p):
        return (p - self.at) @ self.rot

    def d(self, p):
        q = self.local(p)
        k, P = self.kind, self.p
        if k == "box":
            dist = sd_round_box(q, np.array(P["b"], F), P["r"])
        elif k == "cap":
            dist = sd_capsule(q, np.array(P["a"], F), np.array(P["b"], F), P["r"])
        elif k == "torus":
            dist = sd_torus(q, P["R"], P["r"])
        elif k == "cyl":
            dist = sd_cyl(q, P["r"], P["h"], P["rr"])
        else:
            dist = sd_sphere(q, P["r"])
        if self.cut is not None:  # carve a recess, in this prim's own frame
            c_at, c_b, c_r = self.cut
            dist = np.maximum(dist, -sd_round_box(q - np.array(c_at, F), np.array(c_b, F), c_r))
        return dist

def group(prims, C, R):
    C = np.array(C, F)
    return [pr.placed(C, R) for pr in prims]

def monitor():
    """A bedside patient monitor on a pole and weighted base."""
    face = 0.17
    return [
        Prim("box", (0, 0, 0), b=(1.25, 0.86, face), r=0.11, mat=WHITE,
             cut=((-0.12, 0.0, face), (1.04, 0.76, 0.05), 0.04)),
        Prim("box", (-0.12, 0.0, face - 0.045), b=(1.0, 0.72, 0.012), r=0.006, mat=SCREEN, tex=("monitor", "z")),
        Prim("box", (0.0, 0.0, -0.22), b=(0.92, 0.62, 0.12), r=0.1, mat=WHITE),
        Prim("cyl", (1.1, -0.52, 0.18), rot=rx(90), r=0.085, h=0.05, rr=0.02, mat=TEAL),
        Prim("box", (1.1, 0.52, 0.17), b=(0.06, 0.035, 0.03), r=0.015, mat=GRAY),
        Prim("box", (1.1, 0.30, 0.17), b=(0.06, 0.035, 0.03), r=0.015, mat=GRAY),
        Prim("box", (1.1, 0.08, 0.17), b=(0.06, 0.035, 0.03), r=0.015, mat=GRAY),
        Prim("sphere", (1.1, 0.72, 0.17), r=0.025, mat=TEAL),
        Prim("cap", (0, 0, 0), a=(-0.62, 1.0, -0.04), b=(0.62, 1.0, -0.04), r=0.055, mat=GRAY),
        Prim("cap", (0, 0, 0), a=(-0.62, 0.8, -0.04), b=(-0.62, 1.0, -0.04), r=0.055, mat=GRAY),
        Prim("cap", (0, 0, 0), a=(0.62, 0.8, -0.04), b=(0.62, 1.0, -0.04), r=0.055, mat=GRAY),
        Prim("cap", (0, 0, 0), a=(0, -0.7, -0.26), b=(0, -1.55, -0.26), r=0.085, mat=GRAY),
        Prim("cyl", (0, -1.6, -0.26), r=0.62, h=0.055, rr=0.035, mat=BASE),
    ]

def oximeter():
    """A fingertip pulse oximeter, display up."""
    return [
        Prim("box", (0, -0.1, 0), b=(0.38, 0.11, 0.22), r=0.1, mat=TEAL),
        Prim("box", (0, 0.07, 0), b=(0.38, 0.1, 0.22), r=0.1, mat=WHITE,
             cut=((-0.03, 0.10, 0), (0.25, 0.03, 0.15), 0.025)),  # shell-local: its top face
        Prim("box", (-0.03, 0.147, 0), b=(0.22, 0.006, 0.12), r=0.003, mat=SCREEN, tex=("oxi", "y")),
        Prim("box", (0.3, 0.165, 0), b=(0.035, 0.012, 0.035), r=0.01, mat=GRAY),
        Prim("cap", (0, 0, 0), a=(-0.38, 0.0, -0.05), b=(-0.38, 0.0, 0.05), r=0.04, mat=GRAY),
    ]

def pump():
    """A single-channel syringe pump with a syringe loaded on top."""
    return [
        Prim("box", (0, 0, 0), b=(0.95, 0.36, 0.44), r=0.08, mat=WHITE,
             cut=((-0.33, 0.06, 0.44), (0.42, 0.2, 0.03), 0.03)),
        Prim("box", (-0.33, 0.06, 0.418), b=(0.39, 0.17, 0.008), r=0.004, mat=SCREEN, tex=("pump", "z")),
        *[Prim("box", (0.38 + 0.2 * (i % 3), 0.14 - 0.17 * (i // 3), 0.44), b=(0.07, 0.05, 0.03), r=0.02,
               mat=(TEAL if i == 2 else GRAY)) for i in range(6)],
        Prim("box", (0.0, 0.33, 0.0), b=(0.95, 0.04, 0.3), r=0.03, mat=GRAY),
        Prim("cap", (0, 0, 0), a=(-0.78, 0.5, 0.05), b=(0.32, 0.5, 0.05), r=0.11, mat=GLASS),
        Prim("cap", (0, 0, 0), a=(0.32, 0.5, 0.05), b=(0.74, 0.5, 0.05), r=0.03, mat=GRAY),
        Prim("cyl", (0.76, 0.5, 0.05), rot=rz(90), r=0.13, h=0.018, rr=0.008, mat=GRAY),
        Prim("cyl", (0.34, 0.5, 0.05), rot=rz(90), r=0.16, h=0.016, rr=0.008, mat=GLASS),
        Prim("box", (-0.18, 0.5, 0.05), b=(0.07, 0.17, 0.2), r=0.03, mat=TEAL),
        Prim("cap", (0, 0, 0), a=(-0.78, 0.5, 0.05), b=(-0.95, 0.5, 0.05), r=0.03, mat=GLASS),
    ]

# ================================================================ scenes

LIGHTS = {
    "day": dict(
        key=norm(np.array([-0.55, 0.85, 0.65], F)), key_col=np.array([1.0, 0.99, 0.97], F) * 1.05,
        fill=norm(np.array([0.85, 0.15, 0.45], F)), fill_col=np.array([0.72, 0.88, 0.96], F) * 0.40,
        rim=norm(np.array([0.3, 0.4, -1.0], F)), rim_col=np.array([0.70, 0.90, 1.0], F) * 0.55,
        sky_top=np.array([0.95, 0.97, 1.0], F), sky_bot=np.array([0.80, 0.88, 0.94], F),
        amb=0.36, glow=1.0, shadow=0.24, shadow_col=np.array([0.07, 0.20, 0.32], F),
    ),
    "night": dict(
        key=norm(np.array([-0.45, 0.80, 0.55], F)), key_col=np.array([0.82, 0.90, 0.97], F) * 0.78,
        fill=norm(np.array([0.9, -0.1, 0.35], F)), fill_col=np.array([0.10, 0.55, 0.62], F) * 0.55,
        rim=norm(np.array([0.25, 0.55, -1.0], F)), rim_col=np.array([0.35, 0.92, 0.95], F) * 1.25,
        sky_top=np.array([0.06, 0.16, 0.26], F), sky_bot=np.array([0.05, 0.33, 0.42], F),
        amb=0.18, glow=1.25, shadow=0.42, shadow_col=np.array([0.0, 0.03, 0.06], F),
    ),
}

def hero_scene():
    prims = group(monitor(), (0.25, 0.32, 0.0), ry(-22) @ rx(-5))
    prims += group(oximeter(), (-1.55, -0.98, 0.95), ry(30) @ rx(62))
    prims += [
        Prim("torus", (0.15, 0.25, -0.75), rot=rz(-10) @ rx(84), R=1.95, r=0.045, mat=BRAND),
        Prim("sphere", (1.72, 1.2, 0.25), r=0.15, mat=GLASS),
        Prim("sphere", (-1.75, 0.95, -0.4), r=0.09, mat=BRAND),
    ]
    cam = dict(ro=(0.0, 0.35, 7.2), target=(0.0, -0.05, 0.0), fov=32)
    return prims, cam, -1.36

SCENES = {
    "hero-dark": (hero_scene, "night", (1200, 960)),
    "hero-light": (hero_scene, "day", (1200, 960)),
    "monitor": (lambda: (group(monitor(), (0, 0.32, 0), ry(-24) @ rx(-4)),
                         dict(ro=(0.0, 0.2, 6.0), target=(0.05, 0.0, 0), fov=30), -1.36), "day", (640, 640)),
    "oximeter": (lambda: (group(oximeter(), (0, 0, 0), ry(28) @ rx(48)),
                          dict(ro=(0.0, 0.55, 2.2), target=(0, 0.0, 0), fov=30), -0.3), "day", (640, 640)),
    "pump": (lambda: (group(pump(), (0, 0, 0), ry(-30)),
                      dict(ro=(0.2, 1.25, 3.7), target=(0, 0.15, 0), fov=34), -0.36), "day", (640, 640)),
}

# ================================================================ renderer

def render(prims, cam, ground_y, L, width, height, ss):
    TEX.setdefault("monitor", tex_monitor())
    TEX.setdefault("oxi", tex_oximeter())
    TEX.setdefault("pump", tex_pump())

    def scene(p):
        return np.min(np.stack([pr.d(p) for pr in prims]), axis=0)

    def which(p):
        return np.argmin(np.stack([pr.d(p) for pr in prims]), axis=0)

    def normal(p):
        e = F(8e-4)
        out = []
        for ax in range(3):
            o = np.zeros(3, F); o[ax] = e
            out.append(scene(p + o) - scene(p - o))
        return norm(np.stack(out, -1))

    def march(ro, rd, steps, far=24.0):
        t = np.zeros(len(rd), F); hit = np.zeros(len(rd), bool); alive = np.arange(len(rd))
        for _ in range(steps):
            if alive.size == 0:
                break
            d = scene(ro[alive] + rd[alive] * t[alive, None])
            t[alive] += d * F(0.92)
            done = d < 3e-4
            hit[alive[done]] = True
            alive = alive[~(done | (t[alive] > far))]
        return t, hit

    def soft_shadow(p, l, k=10.0, steps=26):
        res = np.ones(len(p), F); t = np.full(len(p), 0.03, F)
        for _ in range(steps):
            h = scene(p + l * t[:, None])
            res = np.minimum(res, np.clip(k * h / t, 0, 1))
            t += np.clip(h, 0.02, 0.25)
        return res

    def ambient_occ(p, n):
        occ, sca = 0.0, 1.0
        for i in range(5):
            h = 0.02 + 0.11 * i
            occ = occ + (h - scene(p + n * F(h))) * sca
            sca *= 0.75
        return np.clip(1 - 1.7 * occ, 0, 1)

    W, H = width * ss, height * ss
    ro = np.array(cam["ro"], F); fwd = norm(np.array(cam["target"], F) - ro)
    right = norm(np.cross(fwd, np.array([0, 1, 0], F))); up = np.cross(right, fwd)
    fov = np.tan(np.radians(cam["fov"]) / 2)
    ys, xs = np.mgrid[0:H, 0:W].astype(F)
    u = (2 * (xs + 0.5) / W - 1) * fov * (W / H)
    v = (1 - 2 * (ys + 0.5) / H) * fov
    rd = norm(fwd + u.reshape(-1, 1) * right + v.reshape(-1, 1) * up).astype(F)
    ro_all = np.broadcast_to(ro, rd.shape)

    t, hit = march(ro_all, rd, 72 if FAST else 120)
    rgb = np.zeros((len(rd), 3), F); alpha = np.zeros(len(rd), F)

    idx = np.nonzero(hit)[0]
    p = ro_all[idx] + rd[idx] * t[idx, None]
    owner = which(p)
    mat = np.array([prims[o].mat for o in owner])
    n = normal(p); vdir = -rd[idx]
    sh = soft_shadow(p + n * F(2e-3), L["key"]); ao = ambient_occ(p, n)
    alb = np.array([ALBEDO[m] for m in mat], F)
    gloss = np.array([GLOSS[m] for m in mat], F)[:, None]
    spec = np.array([SPEC[m] for m in mat], F)[:, None]
    ndv = np.clip((n * vdir).sum(-1), 0, 1)

    def lit(l, col, shadow=None):
        diff = np.clip(n @ l, 0, 1)[:, None]
        hv = norm(l + vdir)
        sp = np.clip((n * hv).sum(-1), 0, 1)[:, None] ** gloss * spec * (gloss + 8) / 60
        out = (alb * diff + sp) * col
        return out if shadow is None else out * shadow[:, None]

    c = lit(L["key"], L["key_col"], sh) + lit(L["fill"], L["fill_col"])
    c += (np.clip(n @ L["rim"], 0, 1) * (1 - ndv) ** 2)[:, None] * L["rim_col"]
    refl = rd[idx] - 2 * (rd[idx] * n).sum(-1, keepdims=True) * n
    sky = L["sky_bot"] + (L["sky_top"] - L["sky_bot"]) * np.clip(refl[:, 1] * 0.5 + 0.5, 0, 1)[:, None]
    fres = 0.04 + 0.96 * (1 - ndv) ** 5
    c += sky * fres[:, None] * spec * 0.9
    c += alb * L["amb"] * ao[:, None] * (0.6 + 0.4 * sky)
    g = mat == GLASS
    c[g] += (ndv[g] ** 3)[:, None] * np.array([0.25, 0.5, 0.55], F) * 0.6

    # textured displays: sample on the face that looks out of the device
    for oi in np.unique(owner):
        pr = prims[oi]
        if pr.tex is None:
            continue
        sel = owner == oi
        q = pr.local(p[sel]); b = pr.p["b"]
        name, face = pr.tex
        if face == "z":
            front = q[:, 2] > b[2] - 0.004
            uu, vv = (q[:, 0] / b[0] + 1) / 2, (1 - q[:, 1] / b[1]) / 2
        else:
            front = q[:, 1] > b[1] - 0.003
            uu, vv = (q[:, 0] / b[0] + 1) / 2, (q[:, 2] / b[2] + 1) / 2
        em = sample(TEX[name], uu, vv) * F(1.15 * L["glow"])
        sheen = np.exp(-(((uu * 0.6 + vv) - 0.55) / 0.25) ** 2)[:, None] * F(0.04)
        sub = c[sel]
        sub[front] += em[front] + sheen[front]
        c[sel] = sub

    rgb[idx] = c; alpha[idx] = 1

    # shadow catcher on an invisible floor
    miss = np.nonzero(~hit & (rd[:, 1] < 0))[0]
    tg = (ground_y - ro[1]) / rd[miss, 1]
    pg = ro + rd[miss] * tg[:, None]
    keep = np.abs(pg[:, 0] - cam["target"][0]) < 4.5
    miss, pg = miss[keep], pg[keep]
    sg = soft_shadow(pg, norm(np.array([0.05, 1.0, 0.25], F)), k=4.0, steps=22)
    fade = np.exp(-(((pg[:, 0] - cam["target"][0]) / 2.6) ** 2 + ((pg[:, 2] - 0.2) / 1.6) ** 2))
    rgb[miss] = L["shadow_col"]
    alpha[miss] = np.clip((1 - sg) * fade * L["shadow"] * 2.2, 0, L["shadow"])

    rgb = np.clip(1 - np.exp(-rgb * 1.12), 0, 1) ** (1 / 2.2)
    img = np.concatenate([rgb, alpha[:, None]], -1).reshape(H, W, 4)
    if ss > 1:
        pre = img.copy(); pre[..., :3] *= pre[..., 3:4]
        pre = pre.reshape(height, ss, width, ss, 4).mean(axis=(1, 3))
        a = pre[..., 3:4]
        pre[..., :3] = np.where(a > 1e-4, pre[..., :3] / np.maximum(a, 1e-4), 0)
        img = pre
    return Image.fromarray((np.clip(img, 0, 1) * 255).astype(np.uint8), "RGBA")

def main():
    OUT.mkdir(parents=True, exist_ok=True)
    names = [a for a in sys.argv[1:] if not a.startswith("--")] or list(SCENES)
    for name in names:
        build, light, (w, h) = SCENES[name]
        prims, cam, ground = build()
        t0 = time.time()
        sc = 2 if FAST else 1
        im = render(prims, cam, ground, LIGHTS[light], w // sc, h // sc, 1 if FAST else 2)
        if name.startswith("hero"):
            v = name.split("-")[1]
            im.save(OUT / f"hero-3d-{v}-1200.webp", "WEBP", quality=84, method=6)
            im.resize((720, 576), Image.LANCZOS).save(OUT / f"hero-3d-{v}-720.webp", "WEBP", quality=72, method=6)
            files = [f"hero-3d-{v}-1200.webp", f"hero-3d-{v}-720.webp"]
        else:
            im.save(OUT / f"product-{name}-640.webp", "WEBP", quality=82, method=6)
            im.resize((360, 360), Image.LANCZOS).save(OUT / f"product-{name}-360.webp", "WEBP", quality=80, method=6)
            files = [f"product-{name}-640.webp", f"product-{name}-360.webp"]
        print(name, f"{time.time() - t0:.0f}s", *[f"{f}={(OUT / f).stat().st_size}" for f in files], flush=True)

if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""
رندر عناصر سه‌بعدیِ انتزاعیِ Concept C — بدون هیچ دارایی بیرونی.

این تصویرها «عکس محصول» نیستند و ادعای نشان‌دادن محصولی واقعی را ندارند:
یک بدنهٔ مانیتور انتزاعی با منحنی ECG، یک حلقه، یک کپسول و چند کره، که با
ray-marching روی SDF ساخته می‌شوند. خروجی WebP با کانال آلفا است (زمینه شفاف،
سایهٔ تماس روی یک صفحهٔ نامرئی)، تا روی گرادیان صفحه بنشیند.

    python3 homepage-redesign/tools/render-3d.py [--fast]

دو نورپردازی: light (روز، روی زمینهٔ روشن) و dark (شب، نور لبهٔ فیروزه‌ای).
هر کدام در دو اندازه: 1200px برای دسکتاپ و 720px برای موبایل (srcset).
"""
import sys
import time
from pathlib import Path

import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "concept-c" / "assets"
FAST = "--fast" in sys.argv

# ---------------------------------------------------------------- math

def norm(v):
    return v / np.linalg.norm(v, axis=-1, keepdims=True)

def rot_x(a):
    c, s = np.cos(a), np.sin(a)
    return np.array([[1, 0, 0], [0, c, -s], [0, s, c]])

def rot_y(a):
    c, s = np.cos(a), np.sin(a)
    return np.array([[c, 0, s], [0, 1, 0], [-s, 0, c]])

def rot_z(a):
    c, s = np.cos(a), np.sin(a)
    return np.array([[c, -s, 0], [s, c, 0], [0, 0, 1]])

def sd_round_box(q, b, r):
    d = np.abs(q) - (b - r)
    return np.linalg.norm(np.maximum(d, 0), axis=-1) + np.minimum(np.max(d, axis=-1), 0) - r

def sd_torus(q, R, r):
    xz = np.sqrt(q[:, 0] ** 2 + q[:, 2] ** 2) - R
    return np.sqrt(xz ** 2 + q[:, 1] ** 2) - r

def sd_capsule(p, a, b, r):
    pa, ba = p - a, b - a
    h = np.clip((pa @ ba) / (ba @ ba), 0, 1)
    return np.linalg.norm(pa - h[:, None] * ba, axis=-1) - r, h

def sd_sphere(p, c, r):
    return np.linalg.norm(p - c, axis=-1) - r

# ---------------------------------------------------------------- scene

DEV_C = np.array([0.18, 0.12, 0.0])
DEV_R = rot_y(np.radians(-24)) @ rot_x(np.radians(-7)) @ rot_z(np.radians(2))
DEV_B = np.array([1.12, 0.76, 0.085])
TOR_C = np.array([0.05, 0.02, -0.45])
TOR_R = rot_z(np.radians(-22)) @ rot_x(np.radians(74))
CAP_A = np.array([-1.55, -1.02, 0.55])
CAP_B = np.array([-0.92, -0.62, 0.95])
SPHERES = [  # centre, radius, material
    (np.array([1.48, 0.98, 0.45]), 0.21, 5),
    (np.array([1.30, -1.02, 0.75]), 0.12, 1),
    (np.array([-1.38, 0.90, -0.30]), 0.09, 4),
]
GROUND_Y = -1.55

MAT_WHITE, MAT_SCREEN, MAT_TEAL, MAT_NAVY, MAT_GLASS, MAT_CAP_TEAL = 1, 2, 3, 4, 5, 6

def scene(p, with_mat=False):
    q = (p - DEV_C) @ DEV_R          # p into the device's frame (R^T applied)
    d = sd_round_box(q, DEV_B, 0.075)
    mat = np.full(len(p), MAT_WHITE)

    tq = (p - TOR_C) @ TOR_R
    dt = sd_torus(tq, 1.62, 0.055)
    if with_mat:
        mat = np.where(dt < d, MAT_TEAL, mat)
    d = np.minimum(d, dt)

    dc, h = sd_capsule(p, CAP_A, CAP_B, 0.23)
    if with_mat:
        mat = np.where(dc < d, np.where(h > 0.5, MAT_CAP_TEAL, MAT_WHITE), mat)
    d = np.minimum(d, dc)

    for c, r, m in SPHERES:
        ds = sd_sphere(p, c, r)
        if with_mat:
            mat = np.where(ds < d, m, mat)
        d = np.minimum(d, ds)

    if with_mat:
        # the screen: the device's front face, inset
        on_front = (q[:, 2] > DEV_B[2] - 0.02) & (np.abs(q[:, 0]) < DEV_B[0] - 0.09) & (np.abs(q[:, 1]) < DEV_B[1] - 0.09)
        mat = np.where((mat == MAT_WHITE) & (d == sd_round_box(q, DEV_B, 0.075)) & on_front, MAT_SCREEN, mat)
        return d, mat, q
    return d

def normal(p):
    e = 1e-3
    ex, ey, ez = np.array([e, 0, 0]), np.array([0, e, 0]), np.array([0, 0, e])
    n = np.stack([
        scene(p + ex) - scene(p - ex),
        scene(p + ey) - scene(p - ey),
        scene(p + ez) - scene(p - ez),
    ], axis=-1)
    return norm(n)

def march(ro, rd, steps, far=20.0):
    t = np.zeros(len(ro))
    hit = np.zeros(len(ro), bool)
    alive = np.arange(len(ro))
    for _ in range(steps):
        if alive.size == 0:
            break
        p = ro[alive] + rd[alive] * t[alive, None]
        d = scene(p)
        t[alive] += d * 0.95
        done = d < 4e-4
        hit[alive[done]] = True
        gone = t[alive] > far
        alive = alive[~(done | gone)]
    return t, hit

def soft_shadow(p, l, k=10.0, steps=28):
    res = np.ones(len(p))
    t = np.full(len(p), 0.03)
    for _ in range(steps):
        h = scene(p + l * t[:, None])
        res = np.minimum(res, np.clip(k * h / t, 0, 1))
        t += np.clip(h, 0.02, 0.25)
    return np.clip(res, 0, 1)

def ambient_occ(p, n):
    occ, sca = 0.0, 1.0
    for i in range(5):
        h = 0.02 + 0.12 * i
        d = scene(p + n * h)
        occ = occ + (h - d) * sca
        sca *= 0.75
    return np.clip(1 - 1.8 * occ, 0, 1)

# ---------------------------------------------------------------- ECG on the screen

def ecg_y(x):
    """A stylised lead-II trace: baseline, P wave, QRS, T wave, period 0.62."""
    u = np.mod(x + 0.28, 0.62)
    y = 0.035 * np.exp(-((u - 0.10) / 0.035) ** 2)          # P
    y += -0.06 * np.exp(-((u - 0.205) / 0.012) ** 2)        # Q
    y += 0.34 * np.exp(-((u - 0.225) / 0.011) ** 2)         # R
    y += -0.10 * np.exp(-((u - 0.247) / 0.012) ** 2)        # S
    y += 0.075 * np.exp(-((u - 0.38) / 0.05) ** 2)          # T
    return y - 0.08

def screen_emission(q, glow):
    x, y = q[:, 0], q[:, 1]
    # true distance to the trace, sampled along x — a vertical-distance shortcut
    # turns the steep QRS into a full-height bar
    d = np.full(len(x), 9.0)
    for dx in np.linspace(-0.035, 0.035, 29):
        d = np.minimum(d, np.sqrt(dx ** 2 + (y - ecg_y(x + dx)) ** 2))
    # the sweep fades toward its tail, like a real monitor
    sweep = np.clip((x + 1.0) / 1.9, 0, 1) ** 0.6
    core = np.exp(-(d / 0.010) ** 2)
    halo = np.exp(-(d / (0.03 * glow)) ** 2) * 0.28
    line = (core + halo) * sweep
    # faint grid
    gx = np.abs(np.mod(x, 0.16) - 0.08) > 0.077
    gy = np.abs(np.mod(y, 0.16) - 0.08) > 0.077
    grid = (gx | gy) * 0.06
    # two readout bars, top-left of the screen in its own frame
    bars = ((np.abs(y - 0.48) < 0.028) & (x > -0.92) & (x < -0.45)) * 0.55
    bars += ((np.abs(y - 0.38) < 0.016) & (x > -0.92) & (x < -0.62)) * 0.30
    teal = np.array([0.36, 0.88, 0.86])
    return line[:, None] * teal * 1.6 + (grid + bars)[:, None] * teal

# ---------------------------------------------------------------- render

LIGHTS = {
    "light": dict(
        key=norm(np.array([-0.55, 0.85, 0.65])), key_col=np.array([1.0, 0.99, 0.97]) * 1.05,
        fill=norm(np.array([0.85, 0.15, 0.45])), fill_col=np.array([0.70, 0.90, 0.95]) * 0.38,
        rim=norm(np.array([0.3, 0.4, -1.0])), rim_col=np.array([0.65, 0.95, 0.95]) * 0.55,
        sky_top=np.array([0.93, 0.97, 1.0]), sky_bot=np.array([0.80, 0.90, 0.94]),
        amb=0.34, glow=1.0, shadow=0.22, shadow_col=np.array([0.07, 0.23, 0.38]),
    ),
    "dark": dict(
        key=norm(np.array([-0.45, 0.80, 0.55])), key_col=np.array([0.80, 0.88, 0.95]) * 0.72,
        fill=norm(np.array([0.9, -0.1, 0.35])), fill_col=np.array([0.10, 0.55, 0.60]) * 0.55,
        rim=norm(np.array([0.25, 0.55, -1.0])), rim_col=np.array([0.30, 0.95, 0.92]) * 1.25,
        sky_top=np.array([0.06, 0.16, 0.25]), sky_bot=np.array([0.05, 0.35, 0.40]),
        amb=0.16, glow=1.6, shadow=0.42, shadow_col=np.array([0.0, 0.03, 0.06]),
    ),
}

ALBEDO = {
    MAT_WHITE: np.array([0.93, 0.95, 0.97]),
    MAT_SCREEN: np.array([0.012, 0.035, 0.06]),
    MAT_TEAL: np.array([0.06, 0.52, 0.56]),
    MAT_NAVY: np.array([0.07, 0.22, 0.37]),
    MAT_GLASS: np.array([0.55, 0.86, 0.88]),
    MAT_CAP_TEAL: np.array([0.08, 0.56, 0.60]),
}
GLOSS = {MAT_WHITE: 48, MAT_SCREEN: 160, MAT_TEAL: 90, MAT_NAVY: 60, MAT_GLASS: 140, MAT_CAP_TEAL: 70}
SPEC = {MAT_WHITE: 0.35, MAT_SCREEN: 0.45, MAT_TEAL: 0.75, MAT_NAVY: 0.5, MAT_GLASS: 1.0, MAT_CAP_TEAL: 0.55}

def render(width, height, variant, ss):
    L = LIGHTS[variant]
    W, H = width * ss, height * ss
    ro = np.array([0.0, 0.25, 6.3])
    target = np.array([0.0, 0.0, 0.0])
    fwd = norm(target - ro)
    right = norm(np.cross(fwd, np.array([0, 1, 0])))
    up = np.cross(right, fwd)
    fov = np.tan(np.radians(31) / 2)
    ys, xs = np.mgrid[0:H, 0:W]
    u = (2 * (xs + 0.5) / W - 1) * fov * (W / H)
    v = (1 - 2 * (ys + 0.5) / H) * fov
    rd = norm(fwd + u.reshape(-1, 1) * right + v.reshape(-1, 1) * up)
    ro_all = np.broadcast_to(ro, rd.shape).copy()

    t, hit = march(ro_all, rd, 70 if FAST else 110)
    rgb = np.zeros((len(rd), 3))
    alpha = np.zeros(len(rd))

    # --- objects
    idx = np.nonzero(hit)[0]
    p = ro_all[idx] + rd[idx] * t[idx, None]
    _, mat, q = scene(p, with_mat=True)
    n = normal(p)
    vdir = -rd[idx]
    sh = soft_shadow(p + n * 2e-3, L["key"])
    ao = ambient_occ(p, n)
    alb = np.stack([ALBEDO[m] for m in mat])
    gloss = np.array([GLOSS[m] for m in mat])[:, None]
    spec = np.array([SPEC[m] for m in mat])[:, None]

    def lit(ldir, col, shadow=1.0):
        diff = np.clip(n @ ldir, 0, 1)[:, None]
        hv = norm(ldir + vdir)
        sp = np.clip((n * hv).sum(-1), 0, 1)[:, None] ** gloss * spec * (gloss + 8) / 60
        return (alb * diff + sp) * col * (shadow if np.isscalar(shadow) else shadow[:, None])

    c = lit(L["key"], L["key_col"], sh) + lit(L["fill"], L["fill_col"])
    # rim light: grazing only
    rim = np.clip(n @ L["rim"], 0, 1) * (1 - np.clip((n * vdir).sum(-1), 0, 1)) ** 2
    c += rim[:, None] * L["rim_col"]
    # environment reflection with fresnel
    refl = rd[idx] - 2 * (rd[idx] * n).sum(-1, keepdims=True) * n
    sky = L["sky_bot"] + (L["sky_top"] - L["sky_bot"]) * np.clip(refl[:, 1] * 0.5 + 0.5, 0, 1)[:, None]
    fres = 0.04 + 0.96 * (1 - np.clip((n * vdir).sum(-1), 0, 1)) ** 5
    c += sky * fres[:, None] * spec * 0.9
    c += alb * L["amb"] * ao[:, None] * (0.6 + 0.4 * sky)
    # a soft diagonal sheen across the screen glass
    s = mat == MAT_SCREEN
    if s.any():
        band = np.exp(-(((q[s, 0] * 0.55 + q[s, 1]) - 0.35) / 0.22) ** 2) * 0.05
        c[s] += band[:, None]
    # glass sphere: lighter core, as if lit through
    g = mat == MAT_GLASS
    c[g] += (np.clip((n[g] * vdir[g]).sum(-1), 0, 1) ** 3)[:, None] * np.array([0.25, 0.55, 0.55]) * (1.4 if variant == "dark" else 0.6)
    # screen emission
    s = mat == MAT_SCREEN
    if s.any():
        c[s] += screen_emission(q[s], L["glow"])
    rgb[idx] = c
    alpha[idx] = 1.0

    # --- shadow catcher on an invisible ground plane
    miss = np.nonzero(~hit & (rd[:, 1] < 0))[0]
    tg = (GROUND_Y - ro[1]) / rd[miss, 1]
    pg = ro + rd[miss] * tg[:, None]
    near = np.abs(pg[:, 0]) < 4.5
    miss, pg = miss[near], pg[near]
    sg = soft_shadow(pg, norm(np.array([0.05, 1.0, 0.25])), k=4.0, steps=24)
    occl = 1 - sg
    fade = np.exp(-((pg[:, 0] / 2.6) ** 2 + ((pg[:, 2] - 0.2) / 1.6) ** 2))
    a = np.clip(occl * fade * L["shadow"] * 2.2, 0, L["shadow"])
    rgb[miss] = L["shadow_col"]
    alpha[miss] = a

    # tone map (soft shoulder) and gamma
    rgb = 1 - np.exp(-rgb * 1.15)
    rgb = np.clip(rgb, 0, 1) ** (1 / 2.2)
    img = np.concatenate([rgb, alpha[:, None]], axis=-1).reshape(H, W, 4)
    if ss > 1:  # box-filter supersampling, premultiplied so edges do not halo
        pre = img.copy()
        pre[..., :3] *= pre[..., 3:4]
        pre = pre.reshape(height, ss, width, ss, 4).mean(axis=(1, 3))
        a = pre[..., 3:4]
        pre[..., :3] = np.where(a > 1e-4, pre[..., :3] / np.maximum(a, 1e-4), 0)
        img = pre
    return Image.fromarray((np.clip(img, 0, 1) * 255).astype(np.uint8), "RGBA")

def main():
    OUT.mkdir(parents=True, exist_ok=True)
    w, h = (600, 480) if FAST else (1200, 960)
    for variant in ("light", "dark"):
        t0 = time.time()
        im = render(w, h, variant, 1 if FAST else 2)
        im.save(OUT / f"hero-3d-{variant}-1200.webp", "WEBP", quality=84, method=6)
        small = im.resize((720, 576), Image.LANCZOS)
        small.save(OUT / f"hero-3d-{variant}-720.webp", "WEBP", quality=70, method=6)
        print(variant, f"{time.time() - t0:.1f}s",
              (OUT / f"hero-3d-{variant}-1200.webp").stat().st_size,
              (OUT / f"hero-3d-{variant}-720.webp").stat().st_size)

if __name__ == "__main__":
    main()

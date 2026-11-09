#!/usr/bin/env python3
"""فهرست منابعی که یک صفحه بارگذاری می‌کند — از HTML ِ خودِ صفحه، نه حدس. فقط می‌خواند.

    python3 perf/tools/head-assets.py <url> [out.json]

برای هر <link rel=stylesheet> و <script src>: شناسه (handle ِ وردپرس)، نشانی، جای آن (head یا body)، و مالک (از مسیر:
افزونه، قالب، هسته یا uploads ِ Elementor). به‌علاوه: شمار <style> ِ درون‌خطی، و اینکه قالب‌های Elementor
(data-elementor-id) و هدر مستقل (#tth-root) واقعاً در HTML رندر شده‌اند یا نه.
"""
import json
import re
import sys
import urllib.request

url = sys.argv[1]
html = urllib.request.urlopen(url, timeout=60).read().decode("utf-8", "replace")
head, _, body = html.partition("</head>")


def owner(href: str) -> str:
    m = re.search(r"/wp-content/plugins/([^/]+)/", href)
    if m:
        return "plugin:" + m.group(1)
    m = re.search(r"/wp-content/themes/([^/]+)/", href)
    if m:
        return "theme:" + m.group(1)
    if "/wp-content/uploads/elementor/" in href:
        return "uploads:elementor"
    if "/wp-includes/" in href:
        return "core"
    if href.startswith("http") and "127.0.0.1" not in href:
        return "external:" + re.sub(r"https?://([^/]+).*", r"\1", href)
    return "other"


def links(part: str, where: str):
    out = []
    for m in re.finditer(r"<link[^>]*rel=['\"]stylesheet['\"][^>]*>", part):
        tag = m.group(0)
        i = re.search(r"id=['\"]([^'\"]+)", tag)
        h = re.search(r"href=['\"]([^'\"]+)", tag)
        href = h.group(1) if h else ""
        out.append({"type": "css", "where": where, "handle": (i.group(1) if i else "").removesuffix("-css"),
                    "href": re.sub(r"https?://127\.0\.0\.1:\d+", "", href).replace("&#038;", "&"), "owner": owner(href),
                    "media": (re.search(r"media=['\"]([^'\"]+)", tag) or [None, "all"])[1]})
    for m in re.finditer(r"<script[^>]*src=['\"]([^'\"]+)['\"][^>]*>", part):
        tag = m.group(0)
        i = re.search(r"id=['\"]([^'\"]+)", tag)
        out.append({"type": "js", "where": where, "handle": (i.group(1) if i else "").removesuffix("-js"),
                    "href": re.sub(r"https?://127\.0\.0\.1:\d+", "", m.group(1)), "owner": owner(m.group(1)),
                    "defer": " defer" in tag or "async" in tag})
    return out


res = links(head, "head") + links(body, "body")
summary = {
    "url": url,
    "rendered_elementor_ids": sorted(set(re.findall(r'data-elementor-id="(\d+)"', body)), key=int),
    "tecteb_header_rendered": 'id="tth-root"' in body,
    "inline_styles_head": len(re.findall(r"<style", head)),
    "inline_style_bytes_head": sum(len(x) for x in re.findall(r"<style[^>]*>(.*?)</style>", head, re.S)),
    "css_head": sum(1 for r in res if r["type"] == "css" and r["where"] == "head"),
    "css_body": sum(1 for r in res if r["type"] == "css" and r["where"] == "body"),
    "js_head": sum(1 for r in res if r["type"] == "js" and r["where"] == "head"),
    "js_body": sum(1 for r in res if r["type"] == "js" and r["where"] == "body"),
    "assets": res,
}
if len(sys.argv) > 2:
    json.dump(summary, open(sys.argv[2], "w"), ensure_ascii=False, indent=1)
print(f"rendered Elementor templates: {summary['rendered_elementor_ids']}  tecteb header: {summary['tecteb_header_rendered']}")
print(f"head: {summary['css_head']} css, {summary['js_head']} js, {summary['inline_styles_head']} inline <style> ({summary['inline_style_bytes_head']} bytes) · body: {summary['css_body']} css, {summary['js_body']} js")
for r in res:
    print(f"  {r['where']:4} {r['type']:3} {r['handle']:40} {r['owner']:28} {r['href'][:90]}")

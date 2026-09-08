#!/usr/bin/env python3
"""
Builds a MINIMAL fa_IR language pack for the disposable acceptance site.

WHY THIS EXISTS
  The accessibility run has to exercise the plugin's pages with WordPress
  ITSELF in Persian and right-to-left — locale fa_IR, is_rtl() true, and the
  admin's *-rtl.css stylesheets loaded. A browser locale does not do that:
  WordPress decides direction from the translation of the string "ltr" in the
  "text direction" context, which only a real .mo file provides.

WHAT THIS IS NOT
  This is NOT the official Persian translation of WordPress. It carries the
  direction entry plus a small set of admin-chrome strings, enough to put
  wp-admin into the locale and direction under test. Every string it does not
  carry stays in English, and the evidence says so.

  translate.wordpress.org, downloads.wordpress.org and api.wordpress.org are
  all refused by this container's egress policy (403 / blocked), so the
  official pack could not be fetched. docs/evidence/acceptance/wpadmin-a11y-fa/
  records that.

Usage: python3 tools/wp-lang/make-fa-ir-mo.py <output.mo>
"""
import struct
import sys

# msgctxt is encoded as context + "\x04" + msgid in the MO catalogue.
CTX = "\x04"

ENTRIES = {
    # The entry WordPress reads in WP_Locale::init() to set text_direction.
    "text direction" + CTX + "ltr": "rtl",
    # A little real admin chrome, so the screenshots show the locale at work.
    "Dashboard": "پیشخوان",
    "Posts": "نوشته‌ها",
    "Media": "رسانه",
    "Pages": "برگه‌ها",
    "Comments": "دیدگاه‌ها",
    "Appearance": "نمایش",
    "Plugins": "افزونه‌ها",
    "Users": "کاربران",
    "Tools": "ابزارها",
    "Settings": "تنظیمات",
    "Log Out": "خروج",
    "Screen Options": "تنظیمات صفحه",
    "Help": "راهنما",
    "Collapse menu": "بستن منو",
    "Skip to main content": "پرش به محتوای اصلی",
    "About WordPress": "درباره وردپرس",
    "Search": "جست‌وجو",
    "Toolbar": "نوار ابزار",
    "Main menu": "منوی اصلی",
}

HEADER = (
    "Project-Id-Version: WordPress\n"
    "MIME-Version: 1.0\n"
    "Content-Type: text/plain; charset=UTF-8\n"
    "Content-Transfer-Encoding: 8bit\n"
    "Language: fa_IR\n"
    "Plural-Forms: nplurals=2; plural=(n > 1);\n"
    "X-Generator: tecteb-marketplace-core acceptance run (minimal, not the official pack)\n"
)


def build(entries: dict) -> bytes:
    items = [("", HEADER)] + sorted(entries.items())
    ids = [k.encode("utf-8") for k, _ in items]
    strs = [v.encode("utf-8") for _, v in items]
    n = len(items)

    # 7 uint32 of header, then two tables of (length, offset) pairs.
    key_table_off = 7 * 4
    val_table_off = key_table_off + n * 8
    data_off = val_table_off + n * 8

    key_entries, val_entries = [], []
    blob = b""
    off = data_off
    for b in ids:
        key_entries.append((len(b), off))
        blob += b + b"\x00"
        off += len(b) + 1
    for b in strs:
        val_entries.append((len(b), off))
        blob += b + b"\x00"
        off += len(b) + 1

    out = struct.pack("<IIIIIII", 0x950412DE, 0, n, key_table_off, val_table_off, 0, 0)
    for length, offset in key_entries:
        out += struct.pack("<II", length, offset)
    for length, offset in val_entries:
        out += struct.pack("<II", length, offset)
    return out + blob


if __name__ == "__main__":
    target = sys.argv[1] if len(sys.argv) > 1 else "fa_IR.mo"
    data = build(ENTRIES)
    with open(target, "wb") as fh:
        fh.write(data)
    print(f"wrote {target}: {len(ENTRIES) + 1} entries, {len(data)} bytes")

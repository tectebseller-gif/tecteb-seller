# WP Rocket «Load CSS asynchronously» شبیه‌سازی‌شده برای tecteb-home.css: media=print + onload.
import sys,re; h=sys.stdin.read(); h2=re.sub(r"(<link[^>]*id='tecteb-home-css'[^>]*)media='all'",r"\1media='print' onload=\"this.media='all'\"",h); assert h2!=h; sys.stdout.write(h2)

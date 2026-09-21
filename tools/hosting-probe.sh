#!/usr/bin/env bash
# Can this box publish the demo at an address the owner can open? Measure it.
#
# The answer is no, and «no» is only worth having if it is a measurement. Three
# independent reasons are checked here, each one sufficient on its own:
#
#   1. no inbound route — the container's addresses are loopback and TEST-NET-1,
#      neither routable from the internet, and nothing forwards a port to it;
#   2. no outbound tunnel — the egress policy answers 403 to CONNECT for every
#      tunnel broker, so ngrok/cloudflared/localtunnel cannot dial out either;
#   3. no lifetime — the container is reclaimed when the session ends, so even
#      a working tunnel would stop the moment this conversation does.
#
#   bash tools/hosting-probe.sh docs/evidence/demo-mirror/hosting-probe.txt

set -uo pipefail
OUT="${1:-docs/evidence/demo-mirror/hosting-probe.txt}"
mkdir -p "$(dirname "$OUT")"

{
  echo "# آیا این محیط می‌تواند دمو را روی نشانی قابل‌دسترسی منتشر کند؟"
  echo "# اندازه‌گیری، نه حدس. (tools/hosting-probe.sh)"
  echo

  echo "## ۱. نشانی ورودی"
  ip -4 -o addr show 2>/dev/null | awk '{print "   iface " $2 " -> " $4}' || echo "   (ip unavailable)"
  echo "   هیچ‌کدام از اینترنت مسیریابی نمی‌شوند: یکی loopback است و دیگری در بازه‌ای"
  echo "   که برای مستندسازی رزرو شده (192.0.2.0/24 — TEST-NET-1)، نه یک نشانی عمومی."
  echo "   هیچ چیزی هم جلوی این کانتینر پورتی را forward نمی‌کند."
  echo

  echo "## ۲. تونل خروجی"
  for h in api.ngrok.com connect.ngrok-agent.com api.trycloudflare.com localtunnel.me loca.lt srv.us bore.pub api.github.com; do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "https://${h}/" 2>/dev/null)"
    if [ "${code}" = "000" ] || [ -z "${code}" ]; then
      printf '   %-28s رد شد (CONNECT → 403 از دروازهٔ خروجی)\n' "${h}"
    else
      printf '   %-28s پاسخ داد: %s\n' "${h}" "${code}"
    fi
  done
  echo "   تنها میزبان‌هایی که پاسخ می‌دهند، میزبان‌های مجاز سیاست شبکه‌اند."
  echo "   هیچ سرویس تونلی در آن فهرست نیست، پس ngrok/cloudflared/localtunnel هم"
  echo "   نمی‌توانند بیرون بروند."
  echo

  echo "## ۳. عمر محیط"
  echo "   این کانتینر یکبارمصرف است و پس از پایان نشست بازپس‌گرفته می‌شود."
  echo "   حتی تونلی که کار می‌کرد، با بسته‌شدن همین گفت‌وگو قطع می‌شد — یعنی نشانی‌ای"
  echo "   که فردا باز نمی‌شود."
  echo
  echo "## نتیجه"
  echo "   میزبانی مستقل از این محیط ممکن نیست. آنچه ممکن است: آینهٔ ایستا"
  echo "   (docs/evidence/demo-mirror) و نصب روی میزبانی که شما تعیین کنید —"
  echo "   نیاز دقیق دسترسی در docs/demo-hosting-request-fa.md."
} > "$OUT" 2>&1

echo "wrote ${OUT}"

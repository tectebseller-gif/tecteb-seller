#!/usr/bin/env bash
# N اجرای Lighthouse روی تصویر لحظه‌ای صفحهٔ اصلی محلی (مثل کش صفحهٔ WP Rocket) و میانه/پراکندگی.
#
#   bash perf/tools/lh-runs.sh <label> [runs=5] [mobile|desktop]
#
# پیش‌نیاز: وردپرس محلی روی 127.0.0.1:8090، Lighthouse 12.8.2 در $LH (پوشهٔ node_modules)، Chromium در /opt/pw-browsers.
# خروجی: perf/evidence/<label>/lh-<mode>-<i>.json، home-snapshot.html و summary-<mode>.json. فقط می‌خواند.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LABEL="$1"; RUNS="${2:-5}"; MODE="${3:-mobile}"
SRC="${SRC:-http://127.0.0.1:8090}"; WPROOT="${WPROOT:-/home/user/wp/core}"   # بستر Elementor ِ واقعی: SRC=http://127.0.0.1:8092 WPROOT=/home/user/wp2/core
LH="${LH:-/tmp/claude-0/-home-user-tecteb-seller/abc7dd8b-6e40-5d40-8130-3e6a187d547a/scratchpad/lh}"
OUT="$HERE/perf/evidence/$LABEL"; mkdir -p "$OUT"
export CHROME_PATH=/opt/pw-browsers/chromium
# تصویر لحظه‌ای تازه از همان کدی که الان نصب است (بازدیدکننده، بی کوکی).
curl -s "$SRC/" | sed "s#$SRC#http://127.0.0.1:8099#g" > "$OUT/home-snapshot.html"
pkill -f "perf-server.mjs 8099" 2>/dev/null; sleep 0.5
(nohup node "$HERE/perf/tools/perf-server.mjs" 8099 "$SRC" "$WPROOT" "$OUT/home-snapshot.html" >/dev/null 2>&1 &)
until curl -s -o /dev/null http://127.0.0.1:8099/; do sleep 0.3; done
FLAGS=(--quiet --chrome-flags="--headless=new --no-sandbox" --only-categories=performance --output=json)
[[ "$MODE" == "desktop" ]] && FLAGS+=(--preset=desktop)
for i in $(seq 1 "$RUNS"); do
  timeout 400 "$LH/node_modules/.bin/lighthouse" http://127.0.0.1:8099/ "${FLAGS[@]}" --output-path="$OUT/lh-$MODE-$i.json" >/dev/null 2>&1
done
python3 - "$OUT" "$MODE" "$RUNS" <<'EOF'
import json, sys, statistics as st
out, mode, runs = sys.argv[1], sys.argv[2], int(sys.argv[3])
rows = []
for i in range(1, runs + 1):
    try: d = json.load(open(f"{out}/lh-{mode}-{i}.json"))
    except Exception: continue
    a = d['audits']; reqs = a['network-requests']['details']['items']
    el = a.get('largest-contentful-paint-element', {}).get('details', {}).get('items', [{}])[0].get('items', [{}])[0].get('node', {}).get('selector', '')
    rows.append({'score': round(d['categories']['performance']['score'] * 100), 'fcp': a['first-contentful-paint']['numericValue'], 'lcp': a['largest-contentful-paint']['numericValue'],
                 'cls': a['cumulative-layout-shift']['numericValue'], 'tbt': a['total-blocking-time']['numericValue'], 'si': a['speed-index']['numericValue'],
                 'requests': len(reqs), 'kb': sum(r.get('transferSize', 0) for r in reqs) / 1024, 'lcp_el': el,
                 'render_blocking_ms': a.get('render-blocking-insight', {}).get('metricSavings', {}).get('FCP', 0)})
def s(k):
    v = [r[k] for r in rows]
    return {'median': round(st.median(v), 3), 'min': round(min(v), 3), 'max': round(max(v), 3)}
summary = {'mode': mode, 'runs': len(rows), **{k: s(k) for k in ('score', 'fcp', 'lcp', 'cls', 'tbt', 'si', 'requests', 'kb', 'render_blocking_ms')}, 'lcp_element': sorted({r['lcp_el'] for r in rows})}
json.dump(summary, open(f"{out}/summary-{mode}.json", 'w'), indent=1, ensure_ascii=False)
print(json.dumps({k: (v['median'] if isinstance(v, dict) else v) for k, v in summary.items()}, ensure_ascii=False))
EOF

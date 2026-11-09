#!/usr/bin/env bash
# آزمایش اسناد: Lighthouse روی تصویر لحظه‌ای صفحهٔ نخست با یک ویرایش «فرضی» (فقط در فایل HTML ِ محلی) تا سهم یک عامل
# اندازه گرفته شود. هیچ کدی از افزونه‌ها و هیچ تنظیمی عوض نمی‌شود؛ نتیجه فقط برای نسبت‌دادن است، نه برای انتشار.
#
#   bash perf/tools/lh-variant.sh <label> <runs> <mode> <edit.py>
#   edit.py: اسکریپت پایتون که HTML را از stdin می‌گیرد و HTML ِ ویرایش‌شده را روی stdout می‌دهد.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LABEL="$1"; RUNS="${2:-3}"; MODE="${3:-mobile}"; EDIT="$4"
SRC="${SRC:-http://127.0.0.1:8092}"; WPROOT="${WPROOT:-/home/user/wp2/core}"
LH="${LH:-/tmp/claude-0/-home-user-tecteb-seller/abc7dd8b-6e40-5d40-8130-3e6a187d547a/scratchpad/lh}"
OUT="$HERE/perf/evidence/$LABEL"; mkdir -p "$OUT"
export CHROME_PATH=/opt/pw-browsers/chromium
curl -s "$SRC/" | sed "s#$SRC#http://127.0.0.1:8099#g" | python3 "$EDIT" > "$OUT/home-snapshot.html"
cp "$EDIT" "$OUT/edit.py"
pkill -f "perf-server.mjs 8099" 2>/dev/null; sleep 0.5
(nohup node "$HERE/perf/tools/perf-server.mjs" 8099 "$SRC" "$WPROOT" "$OUT/home-snapshot.html" >/dev/null 2>&1 &)
until curl -s -o /dev/null http://127.0.0.1:8099/; do sleep 0.3; done
FLAGS=(--quiet --chrome-flags="--headless=new --no-sandbox" --only-categories=performance --output=json)
[[ "$MODE" == "desktop" ]] && FLAGS+=(--preset=desktop)
for i in $(seq 1 "$RUNS"); do timeout 400 "$LH/node_modules/.bin/lighthouse" http://127.0.0.1:8099/ "${FLAGS[@]}" --output-path="$OUT/lh-$MODE-$i.json" >/dev/null 2>&1; done
python3 - "$OUT" "$MODE" "$RUNS" "$LABEL" <<'PY'
import json, sys, statistics as st
out, mode, runs, label = sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4]
r = []
for i in range(1, runs + 1):
    try: a = json.load(open(f"{out}/lh-{mode}-{i}.json"))
    except Exception: continue
    s = a['categories']['performance']['score']; a = a['audits']
    bad = [x['url'] for x in a['network-requests']['details']['items'][1:] if x.get('statusCode') not in (200, 204, 304) and 'favicon' not in x['url'] and '127.0.0.1' in x['url']]
    r.append((round(s * 100), a['first-contentful-paint']['numericValue'], a['largest-contentful-paint']['numericValue'], a['total-blocking-time']['numericValue'], len(bad)))
m = lambda k: round(st.median(x[k] for x in r))
summary = {'label': label, 'mode': mode, 'runs': len(r), 'score': m(0), 'fcp': m(1), 'lcp': m(2), 'tbt': m(3), 'lcp_range': [round(min(x[2] for x in r)), round(max(x[2] for x in r))], 'bad_requests': sum(x[4] for x in r)}
json.dump(summary, open(f"{out}/summary-{mode}.json", 'w'), indent=1); print(json.dumps(summary))
PY

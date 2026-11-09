#!/usr/bin/env bash
cd /home/user/tecteb-seller/homepage-redesign
export NODE_PATH=/opt/node-tools/node_modules SRC=http://127.0.0.1:8092; W="wp --allow-root --path=/home/user/wp2/core"
set_cfg() { $W option update tthp_site_font "$1" >/dev/null 2>&1; $W eval '$s=get_option("tecteb_home_settings"); $s["font"]="'$2'"; update_option("tecteb_home_settings",$s); $h=get_option("tecteb_header_settings"); $h["font"]="'$3'"; update_option("tecteb_header_settings",$h);' >/dev/null 2>&1; curl -s -o /dev/null $SRC/; }
INJ='<style id="exp-0001">@keyframes th-c-rise{from{opacity:.001;transform:translateY(14px)}to{opacity:1;transform:none}}</style>'
run() { local label=$1 cfg=$2 delay=$3 inject=$4; set_cfg $cfg; local d=perf/evidence/mech-$label; mkdir -p $d
  curl -s "$SRC/" | sed "s#$SRC#http://127.0.0.1:8099#g" > $d/home-snapshot.html
  [[ "$inject" == async ]] && python3 -c "import sys,re; p=sys.argv[1]; h=open(p).read(); h2=re.sub(r\"(<link[^>]*id='tecteb-home-css'[^>]*)media='all'\",r\"\\1media='print' onload=\\\"this.media='all'\\\"\",h); assert h2!=h; open(p,'w').write(h2)" $d/home-snapshot.html
  [[ "$inject" == 1 ]] && python3 -c "import sys; p=sys.argv[1]; h=open(p).read(); open(p,'w').write(h.replace('</head>', sys.argv[2]+'</head>',1))" $d/home-snapshot.html "$INJ"
  pkill -f "perf-server.mjs 8099"; sleep 0.5; (DELAY="$delay" nohup node perf/tools/perf-server.mjs 8099 $SRC /home/user/wp2/core $d/home-snapshot.html >/dev/null 2>&1 &); until curl -s -o /dev/null http://127.0.0.1:8099/; do sleep 0.3; done
  echo "== $label (cfg: $cfg; delay: $delay; 0.001: $inject)"; node perf/tools/trace.mjs http://127.0.0.1:8099/ $d/trace-mobile.json mobile 3 | python3 -c "import json,sys; d=json.load(sys.stdin); print({k:d[k] for k in ('fcp','lcp','lcp_el','lcp_candidates','cls')})"; }
run E8 "off bundled plugin" "tecteb-home.css=1500" async
run E9 "off bundled plugin" "nothing=0" async
set_cfg off bundled plugin; echo MECHDONE

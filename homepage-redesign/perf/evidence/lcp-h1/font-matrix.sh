#!/usr/bin/env bash
cd /home/user/tecteb-seller/homepage-redesign
export SRC=http://127.0.0.1:8092 WPROOT=/home/user/wp2/core NODE_PATH=/opt/node-tools/node_modules
W="wp --allow-root --path=/home/user/wp2/core"
set_cfg() { $W option update tthp_site_font "$1" >/dev/null 2>&1
  $W eval '$s=get_option("tecteb_home_settings"); $s["font"]="'$2'"; update_option("tecteb_home_settings",$s); $h=get_option("tecteb_header_settings"); $h["font"]="'$3'"; update_option("tecteb_header_settings",$h);' >/dev/null 2>&1; curl -s -o /dev/null $SRC/; }
for cfg in "P1 auto site inherit" "P3 swap site inherit" "P4 auto-preload site inherit" "P5 auto bundled inherit"; do
  set -- $cfg; set_cfg $2 $3 $4; echo "== $1 site-font=$2 home=$3 header=$4"; bash perf/tools/lh-runs.sh font-$1 3 mobile 2>&1 | tail -1
done
set_cfg off bundled plugin; echo MATRIXDONE

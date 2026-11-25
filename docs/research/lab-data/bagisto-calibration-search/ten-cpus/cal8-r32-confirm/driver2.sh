#!/usr/bin/env bash
# driver2.sh <run-record-dir> <obs-dir>: the same looks as driver.sh at the same times plus 15 s, but it does NOT stop taking them when the load tool's tail has ended
# (driver.sh skips every look after k6.exit; here the judgement needs the looks 5 and 10 minutes after the window whether or not the tail ended). Read only.
set -uo pipefail
rec="$1"; obs="$2"; here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mkdir -p "$obs"
while [ ! -s "$rec/started_at.txt" ]; do sleep 2; done
t0=$(date -d "$(cat "$rec/started_at.txt")" +%s); e=$((t0+600))
echo "$(date '+%F %T') driver2: load tool started at $(cat "$rec/started_at.txt"); the window ends at $(date -d @$e '+%F %T'); looks are taken 15 s after the times of driver.sh and also after the tail has ended" >> "$obs/observe-log.txt"
look() { bash "$here/observe.sh" "$1" "$obs"; { echo "== load tool progress line (last 'running' line of k6-output.txt)"; tr '\r' '\n' < "$rec/k6-output.txt" 2>/dev/null | grep -a 'running (' | tail -1; [ -e "$rec/k6.exit" ] && echo "k6.exit present: $(cat "$rec/k6.exit")"; } >> "$obs/$1.txt"; }
for m in -60:w-1min 5:end+5s 60:end+1m 120:end+2m 300:end+5m 600:end+10m; do
  off=${m%%:*}; label=${m#*:}; target=$((e+off+15)); [ "$off" = "-60" ] && target=$((e-60+15))
  while [ "$(date +%s)" -lt "$target" ]; do sleep 1; done
  look "$label"
done
echo "$(date '+%F %T') driver2: done" >> "$obs/observe-log.txt"

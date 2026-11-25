#!/usr/bin/env bash
# driver.sh <run-record-dir> <obs-dir> <inbox-script-dir>: waits for the load tool to start (started_at.txt), then calls observe.sh at the times asked for:
# one minute before the end of the 10 minute load window, right after it, 2, 5 and 10 minutes after it (and some more in between), and right after the tail ends
# (k6.exit). Read only. The load window is 600 s from the load tool's start.
set -uo pipefail
rec="$1"; obs="$2"; here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mkdir -p "$obs"
while [ ! -s "$rec/started_at.txt" ]; do sleep 2; done
t0=$(date -d "$(cat "$rec/started_at.txt")" +%s); e=$((t0+600))
echo "$(date '+%F %T') driver: load tool started at $(cat "$rec/started_at.txt"); the window ends at $(date -d @$e '+%F %T')" >> "$obs/observe-log.txt"
look() { # label
  bash "$here/observe.sh" "$1" "$obs"
  { echo "== load tool progress line (last 'running' line of k6-output.txt)"; tr '\r' '\n' < "$rec/k6-output.txt" 2>/dev/null | grep -a 'running (' | tail -1; } >> "$obs/$1.txt"
}
marks="-540:w-1min:in-window,5:end+5s:after-window,60:end+1m:after-window,120:end+2m:after-window,300:end+5m:after-window,600:end+10m:after-window,900:end+15m:after-window,1200:end+20m:after-window,1500:end+25m:after-window"
for m in ${marks//,/ }; do
  off=${m%%:*}; rest=${m#*:}; label=${rest%%:*}
  [ -e "$rec/k6.exit" ] && { echo "$(date '+%F %T') driver: the tail has ended; the mark $label is not taken" >> "$obs/observe-log.txt"; continue; }
  target=$((e+off)); [ "$off" = "-540" ] && target=$((e-60))
  while [ "$(date +%s)" -lt "$target" ]; do [ -e "$rec/k6.exit" ] && break; sleep 1; done
  [ -e "$rec/k6.exit" ] && { echo "$(date '+%F %T') driver: the tail has ended; the mark $label is not taken" >> "$obs/observe-log.txt"; continue; }
  look "$label"
done
while [ ! -e "$rec/k6.exit" ]; do sleep 2; done
look "tail-end"
echo "$(date '+%F %T') driver: done" >> "$obs/observe-log.txt"

#!/usr/bin/env bash
# watch_idle.sh <record-dir> <label> <stop-file>: if nothing under <record-dir> (watch.log and alerts.log do not count) has grown for 3600 s
# while the run has not ended, tell the coordinator once per stall. Ends when <stop-file> exists.
dir=$1; label=$2; stop=$3; limit=${IDLE_LIMIT:-3600}; told=0
ROOT=<notify-dir>
while [ ! -e "$stop" ]; do
  newest=$(find "$dir" -type f ! -name watch.log ! -name alerts.log -printf '%T@\n' 2>/dev/null | sort -n | tail -1 | cut -d. -f1)
  now=$(date +%s)
  if [ -n "$newest" ] && [ $((now - newest)) -ge "$limit" ]; then
    if [ "$told" = 0 ]; then
      <notify> "70,000-row measurement ($label): output has not grown for $((($now - newest) / 60)) minutes; see $dir. The run is left as it is."
      told=1
    fi
  else told=0; fi
  for i in 1 2 3 4 5 6 7 8 9 10 11 12; do [ -e "$stop" ] && break; sleep 10; done
done

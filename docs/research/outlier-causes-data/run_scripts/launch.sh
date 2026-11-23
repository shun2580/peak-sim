#!/usr/bin/env bash
# launch.sh <script> [label]: run one of the step scripts, with the idle watch, and tell the operator when it ends.
. "$(dirname "$0")/env.sh"
script=$1; name=$(basename "$script" .sh)
ROOT=<notify-dir>
step=${name#run_}; case "$step" in laps|single) wd=$OUT/$step ;; *) wd=$OUT ;; esac
stop=$OUT/.stop_$step; rm -f "$stop"
bash "$D/watch_idle.sh" "$wd" "$name" "$stop" & wp=$!
bash "$D/$script"; rc=$?
touch "$stop"; wait $wp 2>/dev/null; rm -f "$stop"
<notify> "step $name ended exit=$rc ($(date +%H:%M)); record: $OUT"
exit $rc

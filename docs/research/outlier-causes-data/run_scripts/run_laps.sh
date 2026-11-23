#!/usr/bin/env bash
# Step 1: the 21 routes of the 240,000-row repeat, in the same order, LAPS laps (default 3) on one database per group, with the
# statement tap on and no ANALYZE. Only difference from the 240,000-row repeat: --tap=on (it was off) and the row count.
. "$(dirname "$0")/env.sh"
before laps || exit 2
echo "start=$(stamp) scale=$SCALE laps=$LAPS" >> "$OUT/run_times.txt"
vrun "laps$SCALE" laps --routes="$ROUTES_21" --laps="$LAPS" --tap=on --analyze=none
rc=$?
stop_stack "laps$SCALE"
echo "end=$(stamp) exit=$rc step=laps" >> "$OUT/run_times.txt"
after laps || { echo "A VOLUME IS GONE (see volumes_check_laps.txt)" >&2; rc=99; }
exit $rc

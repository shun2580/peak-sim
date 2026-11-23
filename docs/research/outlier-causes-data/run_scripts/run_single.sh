#!/usr/bin/env bash
# Step 2: one request at a time, once, with the tap (--laps=1); then ANALYZE TABLE once and the same routes once more
# (--analyze=rerun). Own database, built the same way as step 1 (the tool records a checksum of every table to compare).
. "$(dirname "$0")/env.sh"
before single || exit 2
echo "start=$(stamp) scale=$SCALE step=single" >> "$OUT/run_times.txt"
vrun "single$SCALE" single --routes="$ROUTES_SINGLE" --laps=1 --tap=on --analyze=rerun
rc=$?
stop_stack "single$SCALE"
echo "end=$(stamp) exit=$rc step=single" >> "$OUT/run_times.txt"
after single || { echo "A VOLUME IS GONE (see volumes_check_single.txt)" >&2; rc=99; }
exit $rc

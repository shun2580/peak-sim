#!/usr/bin/env bash
# Test A at 240,000 rows: launcher of test A at 240,000 rows. NOT STARTED. Two lanes at the same time (they count, they do not time):
#   lane 1: measure_fixtures.sh, then measure_bookstack.sh      lane 2: repeat_bookstack.sh
# Start (detached):  T5A_START_OK=1 NOTIFY_TO=<id> setsid nohup bash run_all.sh > /dev/null 2>&1 &
# Each script runs its own gates first; a closed gate stops that script only (exit 2) and says why in gate_failed_<step>.txt.
here="$(cd "$(dirname "$0")" && pwd)"
bash "$here/repeat_bookstack.sh" & p2=$!
bash "$here/measure_fixtures.sh"; r1=$?
bash "$here/measure_bookstack.sh"; r3=$?
wait $p2; r2=$?
. "$here/common.sh"
echo "run_all_end $(date -Is) measure_fixtures=$r1 measure_bookstack=$r3 repeat_bookstack=$r2" >> "$ORDER"
notify "A3 run_all ended: measure_fixtures=$r1 measure_bookstack=$r3 repeat_bookstack=$r2"

#!/usr/bin/env bash
# check_run.sh <name> <run id> <rows>: read-only checks after one run of the search made after the fix (nothing is changed, nothing is killed).
#  (1) the settings of the run against the confirming run's (D279 section 8, B3): settings-ini.txt, settings-nginx.txt and limits.txt byte for byte;
#      settings-fpm.txt with the time at the start of each line taken off.
#  (2) the steps after the load window, read as in the confirming run (read_steps.py): "none" is expected.
#  (3) the receive queue of port 9000 in the app container now (the load tool has ended): 0 is expected.
#  (0) with <rows>, the count in the first line of tables-before.tsv against the run's count (D274 decision 2).
#  (4) how long the run took from the start of the window (started_at) to the end of the tail (ended_at): the confirming run took 11 min 2 s.
# It writes <out>/<name>/check.txt and prints it. Needs PEAK_SIM_REPO.
set -uo pipefail
name="$1"; id="$2"; rows="${3:-}"
REC=$HOME/.cache/peak-lab-bagisto/out/calibration-search; T5S=${CHECK_RUN_OUT:-$REC/t5s}; D=$T5S/$name/$id
REF=$PEAK_SIM_REPO/docs/research/lab-data/bagisto-calibration-search/ten-cpus/cal8-r32-confirm/cal-8
HERE=$PEAK_SIM_REPO/docs/research/lab-data/bagisto-calibration-search/after-fix
f=$T5S/$name/check.txt
{
echo "check of $name ($id) at $(date '+%F %T')"
b3=SAME
for x in settings-ini.txt settings-nginx.txt limits.txt; do
  if cmp -s "$REF/$x" "$D/$x"; then echo "B3 $x: same"; else echo "B3 $x: DIFFERENT"; b3=DIFFERENT; fi
done
if diff <(sed 's/^\[[^]]*\] //' "$REF/settings-fpm.txt") <(sed 's/^\[[^]]*\] //' "$D/settings-fpm.txt") >/dev/null; then echo "B3 settings-fpm.txt (time at line start taken off): same"; else echo "B3 settings-fpm.txt (time at line start taken off): DIFFERENT"; b3=DIFFERENT; fi
echo "B3 overall: $b3"
if [ -n "$rows" ]; then
  got=$(head -1 "$D/tables-before.tsv" 2>/dev/null | cut -f2)
  if [ "$got" = "$rows" ]; then echo "rows: first line of tables-before.tsv says $got, the run's count is $rows: same"; else echo "rows: first line of tables-before.tsv says '${got}', the run's count is $rows: DIFFERENT"; fi
fi
python3 -I "$HERE/read_steps.py" "$D/k6-output.txt" | sed 's/^/steps: /'
rq=$(docker exec peak-lab-bagisto-app sh -c 'cat /proc/net/tcp /proc/net/tcp6' 2>&1 | awk '$2 ~ /:2328$/ && $4=="0A" {split($5,q,":"); print q[2]}' | sort | head -1)
echo "Recv-Q of :9000 now (hex rx_queue of the listening socket): ${rq:-unreadable}"
s=$(cat "$D/started_at.txt" 2>/dev/null); e=$(cat "$D/ended_at.txt" 2>/dev/null)
python3 -I - "$s" "$e" <<'PY'
import sys, datetime
try:
    s = datetime.datetime.fromisoformat(sys.argv[1]); e = datetime.datetime.fromisoformat(sys.argv[2])
    d = (e - s).total_seconds(); print("window start to end of tail: %d s (%s to %s); confirming run: 662 s" % (d, sys.argv[1], sys.argv[2]))
except Exception as x:
    print("times unreadable:", x)
PY
} >"$f" 2>&1
cat "$f"

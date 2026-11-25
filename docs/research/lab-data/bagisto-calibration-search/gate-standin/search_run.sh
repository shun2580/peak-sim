#!/usr/bin/env bash
# search_run.sh <cal-6|cal-7> <R3> <R4> <name>: one search run through bin/measure-calibration. The other runs of the set are given stand-in rates only to ask the gate.
set -uo pipefail
cd "$PEAK_SIM_REPO"   # the repository root
OUT=$HOME/.cache/peak-lab-bagisto/out; REC=$OUT/calibration-search; P=$REC/plans
id="$1"; r3="$2"; r4="$3"; name="$4"
CAP="$PEAK_CAPTURE_JSON"   # the third capture of Bagisto
log=$REC/search-log.txt
echo "$(date '+%F %T') PRE free: $(free -g | awk 'NR==2{print "total "$2" available "$7}') df: $(df -h / | awk 'NR==2{print $4" free"}')" >>$log
echo "$(date '+%F %T') START $id R_3=$r3 R_4=$r4 name=$name (stand-ins R_0=1 R_1=4 R_2=0.45)" >>$log
poc/lab/bin/with-bagisto poc/lab/bin/measure-calibration --estimate=$REC/estimate-shared --gate-tables=n_shared \
  --run=cal-1:$P/even_r1.json --run=cal-2:$P/first80_r1.json --run=cal-3:$P/odd80_r1.json --run=cal-4:$P/even_r4.json --run=cal-5:$P/even_r0.45.json \
  --run=cal-6:$P/top1_r$r3.json --run=cal-7:$P/top2_r$r4.json \
  --scale=10000 --run-scale=cal-4:3000 --run-scale=cal-5:30000 --run-scale=cal-6:30000 --run-scale=cal-7:30000 \
  --measure=$id --request-timeout=600s --statement-record=$CAP --out=$REC/$name >$REC/$name.out.txt 2>$REC/$name.err.txt
rc=$?
echo "$(date '+%F %T') END $id name=$name exit=$rc" >>$log
if [ $rc = 0 ] && [ -f $REC/$name/$id/record.json ]; then
  python3 -I - $REC/$name/$id/record.json >>$log <<'PY'
import json,sys
d=json.load(open(sys.argv[1])); r=d['rps']; u=d['db_utilization']
print("RESULT target=%s achieved=%.4f status=%s db_util=%.4f%% requests=%s dropped=%s http=%s" % (r['target_rps'], r['achieved_rps'], r['status'], u['percent'], d['requests']['in_window'], d['requests']['dropped_iterations'], d['requests']['http_status']))
PY
fi


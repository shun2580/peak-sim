#!/usr/bin/env bash
# search_run.sh <cal-6|cal-7> <R_3> <R_4> <R_0> <R_1> <R_2> <name> [<R_5>]: one search run through bin/measure-calibration, in the lab with a ten-CPU app.
# The runs of the set other than the one measured are given stand-in rates only to ask the gate (they are not values of R). Request timeout 1800 s.
# Needs PEAK_SIM_REPO (the repository root) and PEAK_CAPTURE_JSON (the capture of the statements of the eight routes).
set -uo pipefail
cd "$PEAK_SIM_REPO"
REC=$HOME/.cache/peak-lab-bagisto/out/calibration-search; P=$REC/plans; OUT=$REC/t5e
id="$1"; r3="$2"; r4="$3"; r0="$4"; r1="$5"; r2="$6"; name="$7"; r5="${8:-}"
extra=(); [ -z "$r5" ] || extra=(--run=cal-8:$P/even_r$r5.json --run-scale=cal-8:1000)   # cal-8 is given to the gate only (a stand-in); it is never measured here
log=$OUT/search-log.txt
echo "$(date '+%F %T') PRE free: $(free -g | awk 'NR==2{print "total "$2" available "$7}') df: $(df -h / | awk 'NR==2{print $4" free"}')" >>$log
echo "$(date '+%F %T') START $id R_3=$r3 R_4=$r4 name=$name (stand-ins R_0=$r0 R_1=$r1 R_2=$r2${r5:+ R_5=$r5 (cal-8 asked of the gate only)})" >>$log
poc/lab/bin/with-bagisto poc/lab/bin/measure-calibration --estimate=$REC/estimate-shared --gate-tables=n_shared \
  --run=cal-1:$P/even_r$r0.json --run=cal-2:$P/first80_r$r0.json --run=cal-3:$P/odd80_r$r0.json --run=cal-4:$P/even_r$r1.json --run=cal-5:$P/even_r$r2.json \
  --run=cal-6:$P/top1_r$r3.json --run=cal-7:$P/top2_r$r4.json "${extra[@]}" \
  --scale=10000 --run-scale=cal-4:3000 --run-scale=cal-5:30000 --run-scale=cal-6:30000 --run-scale=cal-7:30000 \
  --measure=$id --request-timeout=1800s --statement-record="$PEAK_CAPTURE_JSON" --out=$OUT/$name >$OUT/$name.out.txt 2>$OUT/$name.err.txt
rc=$?
echo "$(date '+%F %T') END $id name=$name exit=$rc" >>$log
if [ $rc = 0 ] && [ -f $OUT/$name/$id/record.json ]; then
  python3 -I - $OUT/$name/$id/record.json >>$log <<'PY'
import json,sys
d=json.load(open(sys.argv[1])); r=d['rps']; u=d['db_utilization']; a=d.get('app_cpu_utilization')
print("RESULT target=%s achieved=%.4f status=%s db_util=%.4f%% app_cpu=%s requests=%s dropped=%s http=%s" % (r['target_rps'], r['achieved_rps'], r['status'], u['percent'], (a or {}).get('percent', a), d['requests']['in_window'], d['requests']['dropped_iterations'], d['requests']['http_status']))
PY
fi
exit $rc

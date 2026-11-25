#!/usr/bin/env bash
# search8.sh <run id> <name> <R_0> <R_1> <R_2> <R_3> <R_4> <R_5>: one search run through bin/measure-calibration with the set cal-1 to cal-8. Only <run id> is measured; the rates of the
# other runs are the real ones where they have been found and stand-ins (asked of the gate only) where they have not. The app is switched to the run's scale (--switch-scale).
# Needs PEAK_SIM_REPO (the repository root) and PEAK_CAPTURE_JSON (the capture of the statements of the eight routes). Request timeout 1800 s.
set -uo pipefail
cd "$PEAK_SIM_REPO"
REC=$HOME/.cache/peak-lab-bagisto/out/calibration-search; P=$REC/plans; OUT=$REC/t5e
id="$1"; name="$2"; r0="$3"; r1="$4"; r2="$5"; r3="$6"; r4="$7"; r5="$8"
log=$OUT/search-log.txt
echo "$(date '+%F %T') PRE free: $(free -g | awk 'NR==2{print "total "$2" available "$7}') df: $(df -h / | awk 'NR==2{print $4" free"}')" >>$log
echo "$(date '+%F %T') START $id name=$name R_0=$r0 R_1=$r1 R_2=$r2 R_3=$r3 R_4=$r4 R_5=$r5 (the one measured is $id; the other rates are real or stand-ins as the README says)" >>$log
poc/lab/bin/with-bagisto poc/lab/bin/measure-calibration --estimate=$REC/estimate-shared --gate-tables=n_shared \
  --run=cal-1:$P/even_r$r0.json --run=cal-2:$P/first80_r$r0.json --run=cal-3:$P/odd80_r$r0.json --run=cal-4:$P/even_r$r1.json --run=cal-5:$P/even_r$r2.json \
  --run=cal-6:$P/top1_r$r3.json --run=cal-7:$P/top2_r$r4.json --run=cal-8:$P/even_r$r5.json \
  --scale=10000 --run-scale=cal-4:3000 --run-scale=cal-5:30000 --run-scale=cal-6:30000 --run-scale=cal-7:30000 --run-scale=cal-8:1000 --switch-scale \
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

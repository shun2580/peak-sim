#!/usr/bin/env bash
# measure_cal.sh <name> <id> <rows>: one calibration run (cal-2 or cal-3, measured at R_0 = 2.83 rps) through bin/measure-calibration, then its checks, then a note to the one who started it.
#   The set is the eight runs of the calibration, with the rates decided by the search made after the fix (after-fix/README.md, "The R table"):
#   cal-1 even at 2.83 (the run found by the search), cal-2 first four routes at 80% at 2.83, cal-3 routes 1, 3, 5, 7 at 80% at 2.83, cal-4 even at 8 (found), cal-5 even at 1.0 (found),
#   cal-6 at 0.3 (found), cal-7 at 1.0 (found), cal-8 at 16 (found). Only <id> is measured; the others are given to the gate by their plans and are not measured again.
#   There is NO --search-gate-advisory: a refusal of the gate (exit 14) stops the entry before any container is touched, and nothing is measured.
#   - refuses to start if the output name already exists;
#   - writes the machine and the lab's state to cal-log.txt (the record of the run's start), runs measure-calibration, runs ../bagisto-calibration-search/after-fix/check_run.sh.
# Needs PEAK_SIM_REPO, PEAK_CAPTURE_JSON and NOTIFY_CMD (a command that takes one message argument). It does not touch any container but through the entry.
set -uo pipefail
name="$1"; id="$2"; rows="$3"
REC=$HOME/.cache/peak-lab-bagisto/out/calibration-search; P=$REC/plans; OUT=$REC/t5x; log=$OUT/cal-log.txt
HERE=$PEAK_SIM_REPO/docs/research/lab-data/bagisto-calibration-search/after-fix
notify() { $NOTIFY_CMD "$1" >/dev/null 2>&1; }
mkdir -p "$OUT"
if [ -e "$OUT/$name" ]; then echo "STOP: $OUT/$name exists"; notify "bgjob: $name not started (the output place exists)"; exit 90; fi
cd "$PEAK_SIM_REPO"
echo "$(date '+%F %T') PRESTATE $name: HEAD $(git rev-parse --short HEAD); poc/lab changed files: $(git status --short -- poc/lab | tr '\n' ';'); containers: $(docker ps --format '{{.Names}}:{{.Status}}' | tr '\n' ' ')" >>"$log"
python3 -I - >>"$log" <<'PY'
import subprocess, re
r = subprocess.run(['docker','logs','peak-lab-bagisto-db'], capture_output=True, text=True)
t = (r.stdout + r.stderr).splitlines()
bad = [l for l in t if re.search(r'recover|redo|error|corrupt|crash', l, re.I) and 'ready for connections' not in l]
print("DB log lines: %d; lines naming recovery or error: %d" % (len(t), len(bad)))
for l in bad[:5]: print("  ", l[:200])
PY
echo "$(date '+%F %T') PRE free: $(free -g | awk 'NR==2{print "total "$2" available "$7}') df: $(df -h / | awk 'NR==2{print $4" free"}')" >>"$log"
echo "$(date '+%F %T') START $id name=$name (the one measured is $id; the set is the eight plans named in this file's header; the gate has no advisory flag)" >>"$log"
poc/lab/bin/with-bagisto poc/lab/bin/measure-calibration --estimate=$REC/estimate-shared --gate-tables=n_shared \
  --run=cal-1:$P/even_r2.83.json --run=cal-2:$P/first80_r2.83.json --run=cal-3:$P/odd80_r2.83.json --run=cal-4:$P/even_r8.json --run=cal-5:$P/even_r1.json \
  --run=cal-6:$P/top1_r0.3.json --run=cal-7:$P/top2_r1.json --run=cal-8:$P/even_r16.json \
  --scale=10000 --run-scale=cal-1:10000 --run-scale=cal-2:10000 --run-scale=cal-3:10000 --run-scale=cal-4:3000 --run-scale=cal-5:30000 --run-scale=cal-6:30000 --run-scale=cal-7:30000 --run-scale=cal-8:1000 \
  --switch-scale --measure=$id --request-timeout=1800s --statement-record="$PEAK_CAPTURE_JSON" --out=$OUT/$name >$OUT/$name.out.txt 2>$OUT/$name.err.txt
rc=$?
echo "$(date '+%F %T') END $id name=$name exit=$rc" >>"$log"
if [ $rc = 0 ] && [ -f $OUT/$name/$id/record.json ]; then
  python3 -I - $OUT/$name/$id/record.json >>"$log" <<'PY'
import json,sys
d=json.load(open(sys.argv[1])); r=d['rps']; u=d['db_utilization']; a=d.get('app_cpu_utilization')
print("RESULT target=%s achieved=%.4f status=%s db_util=%.4f%% app_cpu=%s requests=%s dropped=%s http=%s" % (r['target_rps'], r['achieved_rps'], r['status'], u['percent'], (a or {}).get('percent', a), d['requests']['in_window'], d['requests']['dropped_iterations'], d['requests']['http_status']))
PY
  CHECK_RUN_OUT=$OUT bash "$HERE/check_run.sh" "$name" "$id" "$rows" >/dev/null 2>&1
fi
res=$(grep '^RESULT' "$log" | tail -1 | cut -c1-200)
notify "bgjob: $name ended exit=$rc. $res. Read $OUT/$name/check.txt and cal-log.txt"
exit $rc

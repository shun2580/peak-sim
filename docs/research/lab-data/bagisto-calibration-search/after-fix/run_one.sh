#!/usr/bin/env bash
# run_one.sh <run id> <name> <R_0> <R_1> <R_2> <R_3> <R_4> <R_5> <rows> <flag 0|1>: one run of the search made after the fix, then its checks, then a note to the one who started it.
#   - refuses to start if the output name already exists (no earlier directory is ever named) or if the guards before a run fail;
#   - writes the machine and the lab's state to search-log.txt (the record of the run's start), runs search8.sh, runs check_run.sh;
#   - the flag is 1 only for the searches of R_1 and R_5 (D275, D277).
# Needs PEAK_SIM_REPO, PEAK_CAPTURE_JSON and NOTIFY_CMD (a command that takes one message argument and tells the one who started the run). It does not touch any container but through the entry.
set -uo pipefail
id="$1"; name="$2"; r0="$3"; r1="$4"; r2="$5"; r3="$6"; r4="$7"; r5="$8"; rows="$9"; flag="${10}"
REC=$HOME/.cache/peak-lab-bagisto/out/calibration-search; OUT=$REC/t5s; log=$OUT/search-log.txt
HERE=$PEAK_SIM_REPO/docs/research/lab-data/bagisto-calibration-search/after-fix
notify() { $NOTIFY_CMD "$1" >/dev/null 2>&1; }
if [ -e "$OUT/$name" ]; then echo "STOP: $OUT/$name exists" ; notify "bgjob: $name を始められぬ(置き場が既に在る)"; exit 90; fi
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
export SEARCH_GATE_ADVISORY="$flag"
bash "$HERE/search8.sh" "$id" "$name" "$r0" "$r1" "$r2" "$r3" "$r4" "$r5"
rc=$?
bash "$HERE/check_run.sh" "$name" "$id" "$rows" >/dev/null 2>&1
res=$(grep '^RESULT' "$log" | tail -1 | cut -c1-200)
notify "bgjob: $name 終了 exit=$rc。$res。check=$OUT/$name/check.txt と search-log を読め"
exit $rc

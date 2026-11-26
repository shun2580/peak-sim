#!/usr/bin/env bash
# gate_r5grid.sh <R_4>...: ask the gate (inputs only, nothing measured) for the set cal-1 to cal-8 with R_0 = 2.83, R_1 = 8, R_2 = 1.0, R_3 = 0.3,
# the given R_4, and cal-8 at each R_5 in 1, 2, 4, 8, 16, 32. Records: <out>/gate-r5grid-r4_<R_4>_r5_<R_5>/. Prints one line per point.
set -uo pipefail
cd "$PEAK_SIM_REPO"
REC=$HOME/.cache/peak-lab-bagisto/out/calibration-search; P=$REC/plans
r0=2.83; r1=8; r2=1; r3=0.3
for r4 in "$@"; do
  for r5 in 1 2 4 8 16 32; do
    o=$REC/t5s/gate-r5grid-r4_${r4}_r5_${r5}
    poc/lab/bin/with-bagisto poc/lab/bin/measure-calibration --estimate=$REC/estimate-shared --gate-tables=n_shared \
      --run=cal-1:$P/even_r$r0.json --run=cal-2:$P/first80_r$r0.json --run=cal-3:$P/odd80_r$r0.json --run=cal-4:$P/even_r$r1.json --run=cal-5:$P/even_r$r2.json \
      --run=cal-6:$P/top1_r$r3.json --run=cal-7:$P/top2_r$r4.json --run=cal-8:$P/even_r$r5.json \
      --scale=10000 --run-scale=cal-4:3000 --run-scale=cal-5:30000 --run-scale=cal-6:30000 --run-scale=cal-7:30000 --run-scale=cal-8:1000 \
      --gate-only --out=$o >"$o.out.txt" 2>"$o.err.txt"
    rc=$?
    python3 -I - "$o/calibration-gate.json" "$r4" "$r5" "$rc" <<'PY'
import json, sys
try:
    d = json.load(open(sys.argv[1]))
    print("R_4=%s R_5=%s exit=%s passed=%s smallest_degree=%.6f left_out=%s threshold=%s" % (sys.argv[2], sys.argv[3], sys.argv[4], d["passed"], d["min_degree"], d["min_left_out"], d["threshold"]))
except Exception as x:
    print("R_4=%s R_5=%s exit=%s unreadable: %s" % (sys.argv[2], sys.argv[3], sys.argv[4], x))
PY
  done
done

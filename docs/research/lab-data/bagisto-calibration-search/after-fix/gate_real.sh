#!/usr/bin/env bash
# gate_real.sh <R_0> <R_1> <R_2> <R_5> [<R_3> <R_4>]: ask the gate (inputs only, nothing measured) for the set cal-1 to cal-8 at these rates (R_3 0.2 and R_4 1 unless given).
set -uo pipefail
cd "$PEAK_SIM_REPO"
REC=$HOME/.cache/peak-lab-bagisto/out/calibration-search; P=$REC/plans
r0=$1; r1=$2; r2=$3; r5=$4; r3=${5:-0.2}; r4=${6:-1}
poc/lab/bin/with-bagisto poc/lab/bin/measure-calibration --estimate=$REC/estimate-shared --gate-tables=n_shared \
  --run=cal-1:$P/even_r$r0.json --run=cal-2:$P/first80_r$r0.json --run=cal-3:$P/odd80_r$r0.json --run=cal-4:$P/even_r$r1.json --run=cal-5:$P/even_r$r2.json \
  --run=cal-6:$P/top1_r$r3.json --run=cal-7:$P/top2_r$r4.json --run=cal-8:$P/even_r$r5.json \
  --scale=10000 --run-scale=cal-4:3000 --run-scale=cal-5:30000 --run-scale=cal-6:30000 --run-scale=cal-7:30000 --run-scale=cal-8:1000 \
  --gate-only --out=$REC/t5s/gate-real-r0_${r0}_r1_${r1}_r2_${r2}_r5_${r5}
echo "exit=$?"

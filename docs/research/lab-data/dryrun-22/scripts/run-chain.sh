#!/usr/bin/env bash
# the chain of the rehearsal: the copy's own tools, the prediction committed in the copy, B settings applied in the copy
R="$HOME/projects/peak-sim-trials/_scratch/dryrun22/repo"
ST="$HOME/.cache/peak-dryrun22"
OUT="$ST/out"
export PEAK_LAB_PROJECT=peak-dryrun22 PEAK_LAB_STATE_DIR="$ST" PEAK_LAB_OUT_DIR="$OUT"
export PEAK_APP_ROOT="$HOME/projects/peak-sim-trials/bookstack"
SCEN="${1:-s-1 s-2 s-3 s-4 s-5}"
SUF="${2:-}"
"$R/poc/lab/bin/measure-ladder-chain" --scale=30000 \
  --predictions="$R/poc/lab/predictions/ladder-prediction-dryrun22-b30k.json" \
  --statement-record="$HOME/projects/peak-sim-trials/_out/run-277_recapture2/bookstack/capture.json" \
  --out-dir="$OUT/chain$SUF" --ladder-out="$OUT/ladder$SUF" --max-steps=2 --scenarios="$SCEN" > "$OUT/chain$SUF.stdout" 2>&1
rc=$?
echo "chain exit=$rc $(date -Iseconds)" >> "$OUT/chain-ends.txt"

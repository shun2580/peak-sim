#!/usr/bin/env bash
# B at 170,000 rows: the real repository's tools, the prediction committed in f52f692, B's settings applied, scenarios s-1 .. s-5 (all 15 steps, no --max-steps)
cd "$HOME/projects/peak-sim" || exit 1
export PEAK_LAB_OUT_DIR="$HOME/.cache/peak-lab/out"
W="$PEAK_LAB_OUT_DIR/<out-dir>"
SCEN="${1:-s-1 s-2 s-3 s-4 s-5}"
date -Is > "$W/chain-first-start.txt"
poc/lab/bin/measure-ladder-chain --scale=170000 \
  --predictions=poc/lab/predictions/ladder-prediction-5-b170k.json \
  --statement-record="$HOME/projects/peak-sim-trials/_out/cmd277_recapture2/bookstack/capture.json" \
  --out-dir="$W/chain" --ladder-out="$W/ladder" --scenarios="$SCEN" > "$W/chain.stdout" 2>&1
rc=$?
echo "chain exit=$rc $(date -Is)" >> "$W/chain-ends.txt"
# (line that sent a note to another process of the working environment: removed in this copy)

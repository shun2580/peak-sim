#!/usr/bin/env bash
# the chain of the rehearsal: the replica's own tools, the prediction committed in the replica, B settings applied in the replica
R="$HOME/projects/peak-sim-trials/_out/run-286_dryrun/repo"
OUT="$HOME/.cache/peak-lab/out/dryrun-286-b"
export PEAK_APP_ROOT="$HOME/projects/peak-sim-trials/bookstack"
SCEN="${1:-s-1 s-2 s-3 s-4 s-5}"
"$R/poc/lab/bin/measure-ladder-chain" --scale=30000 \
  --predictions="$R/poc/lab/predictions/ladder-prediction-dryrun-b30k-fabricated-128M.json" \
  --statement-record="$HOME/projects/peak-sim-trials/_out/run-277_recapture2/bookstack/capture.json" \
  --out-dir="$OUT/chain" --ladder-out="$OUT/ladder" --max-steps=2 --scenarios="$SCEN" > "$OUT/chain.stdout" 2>&1
rc=$?
echo "chain exit=$rc $(date -Iseconds)" >> "$OUT/chain-ends.txt"
# (a line that sent a note to another process of the working environment is left out of this copy)

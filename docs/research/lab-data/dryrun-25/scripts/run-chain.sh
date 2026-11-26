#!/usr/bin/env bash
# The chain of the rehearsal: the copy's own tools, the prediction committed in the copy, B settings applied in the copy.
# Usage: run-chain.sh [scenarios] [suffix]
R="$HOME/projects/peak-sim-trials/_scratch/dryrun25/repo"
export PEAK_LAB_BAGISTO_STATE_DIR="$HOME/.cache/peak-dryrun25"
export PEAK_APP_ROOT="$HOME/projects/peak-sim-trials/bagisto"
OUT="$PEAK_LAB_BAGISTO_STATE_DIR/out"
SCEN="${1:-s-1 s-2 s-3 s-4 s-5}"
SUF="${2:-}"
cd "$R" || exit 2
"$R/poc/lab/bin/with-bagisto" "$R/poc/lab/bin/measure-ladder-chain" --scale=30000 \
  --predictions="$R/poc/lab/predictions/ladder-prediction-dryrun25-b30k.json" \
  --statement-record="$HOME/projects/peak-sim-trials/_out/bagisto/capture3/capture.json" \
  --out-dir="$OUT/chain$SUF" --ladder-out="$OUT/ladder$SUF" --max-steps=2 --scenarios="$SCEN" > "$OUT/chain$SUF.stdout" 2>&1
rc=$?
echo "chain exit=$rc $(date -Iseconds)" >> "$OUT/chain-ends.txt"
# (the last line of the working copy of this script, a note to another process of the working environment, is left out)
exit $rc

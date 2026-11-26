#!/usr/bin/env bash
# The measurement chain of the backtest: the lab of this repository, the committed prediction, B settings as applied.
# No --request-timeout and no --max-steps are given: the chain's default under the Bagisto wrapper (2400s) is the one in use.
set -uo pipefail
R="$HOME/projects/peak-sim"
OUT="$HOME/.cache/peak-lab-bagisto/out"
cd "$R" || exit 2
"$R/poc/lab/bin/with-bagisto" "$R/poc/lab/bin/measure-ladder-chain" --scale=190000 \
  --predictions="$R/poc/lab/predictions/ladder-prediction-bagisto-b.json" \
  --statement-record="$HOME/projects/peak-sim-trials/_out/bagisto/capture3/capture.json" \
  --out-dir="$OUT/chain-b" --ladder-out="$OUT/ladder-b" --scenarios="s-1 s-2 s-3 s-4 s-5" > "$OUT/chain-b.stdout" 2>&1
rc=$?
echo "chain exit=$rc $(date -Iseconds)" >> "$OUT/chain-b-ends.txt"
exit $rc

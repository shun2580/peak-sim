#!/usr/bin/env bash
# puts the stop mark while s-2 runs (so that the chain stops before s-3); records the time
OUT="$HOME/.cache/peak-lab/out/dryrun-286-b"
until grep -q "START s-2 " "$OUT/chain/chain.log" 2>/dev/null; do sleep 5; done
sleep 30
touch "$OUT/chain/STOP"; echo "STOP mark made $(date -Iseconds) (while s-2 runs)" > "$OUT/stop-mark-made.txt"

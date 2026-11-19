#!/usr/bin/env bash
# Watched chain (2026-11-20, ruling of 00:36): the SAME chain invocation as scripts/run-chain.sh (same arguments, no --max-steps),
# but one scenario per call of measure-ladder-chain, so the db log can be read before and after each scenario. measure-ladder-chain, measure-ladder,
# the prediction, the limits and the rules are untouched. A new db log line with [ERROR], recovery, a server start or a shutdown stops the chain
# before the next scenario and tells the maintainer. Every new line, whatever it is, is kept in dblog-watch/.
cd "$HOME/projects/peak-sim" || exit 1
export PEAK_LAB_OUT_DIR="$HOME/.cache/peak-lab/out"
W="$PEAK_LAB_OUT_DIR/<out-dir>"
D="$W/dblog-watch"; mkdir -p "$D"
PAT='\[ERROR\]|ecover|starting as process|SHUTDOWN|Shutting down|Aborted|orrupt'
date -Is > "$W/chain-restart-20261120.txt"
seen=$(wc -l < "$D/db-log-0040-before-s-3.txt")
msg() { :; }  # (the body, which sent a note to another process of the working environment, is removed in this copy)
readdb() { # $1 label: keep the whole log, the lines new since the last reading, and say whether one of them matches
  local f="$D/db-log-$(date +%H%M%S)-$1.txt"
  docker logs peak-lab-db > "$f" 2>&1
  date -Is > "$f.at"
  tail -n +$((seen+1)) "$f" > "$D/new-lines-$1.txt"
  seen=$(wc -l < "$f")
  { echo "== $1 at $(date -Is): $(wc -l < "$D/new-lines-$1.txt") new line(s)"; cat "$D/new-lines-$1.txt"; } >> "$D/watch.log"
  ! grep -qE "$PAT" "$D/new-lines-$1.txt"
}
for sc in "$@"; do
  readdb "before-$sc" || { echo "db log: stop before $sc" >> "$W/chain.log"; : ; exit 20; }   # (notes sent here to other processes: removed in this copy)
  rm -f "$W/chain/chain.done"
  poc/lab/bin/measure-ladder-chain --scale=170000 \
    --predictions=poc/lab/predictions/ladder-prediction-5-b170k.json \
    --statement-record="$HOME/projects/peak-sim-trials/_out/cmd277_recapture2/bookstack/capture.json" \
    --out-dir="$W/chain" --ladder-out="$W/ladder" --scenarios="$sc" >> "$W/chain.stdout" 2>&1
  rc=$?
  echo "chain($sc) exit=$rc $(date -Is)" >> "$W/chain-ends.txt"
  readdb "after-$sc" || { echo "db log: stop after $sc" >> "$W/chain.log"; : ; exit 21; }   # (notes sent here to other processes: removed in this copy)
  [ "$rc" -eq 0 ] || { : ; exit "$rc"; }   # (note sent here to another process: removed in this copy)
done
: # (the final note to another process is removed in this copy)

#!/usr/bin/env bash
# Watched chain of the sixth B at 180,000 rows: one scenario per call of measure-ladder-chain (same arguments for each, no --max-steps),
# the db log is read before and after each scenario. A new db log line with [ERROR], recovery, a server start or a shutdown stops the chain
# before the next scenario. Every new line is kept in dblog-watch/. The tools are the copy's own (names replaced); the prediction is the committed one.
source "$HOME/.cache/peak-b180k/scripts/env.sh"
cd "$R" || exit 1
W="$OUT"; D="$W/dblog-watch"; mkdir -p "$D" "$W/chain"
PAT='\[ERROR\]|ecover|starting as process|SHUTDOWN|Shutting down|Aborted|orrupt'
[ -e "$W/chain-first-start.txt" ] || date -Is > "$W/chain-first-start.txt"
seen=$(wc -l < "$W/pause3b/db-log-before-chain.txt")
notify() { :; }  # record copy: the call that sent a note to another process of the working environment is replaced by a no-op
readdb() { # $1 label
  local f="$D/db-log-$(date +%H%M%S)-$1.txt"
  docker logs peak-b180k-db > "$f" 2>&1; date -Is > "$f.at"
  tail -n +$((seen+1)) "$f" > "$D/new-lines-$1.txt"; seen=$(wc -l < "$f")
  { echo "== $1 at $(date -Is): $(wc -l < "$D/new-lines-$1.txt") new line(s)"; cat "$D/new-lines-$1.txt"; } >> "$D/watch.log"
  ! grep -qE "$PAT" "$D/new-lines-$1.txt"
}
for sc in "$@"; do
  if [ -e "$W/chain/STOP" ]; then echo "stop mark seen before $sc $(date -Is)" >> "$W/chain.log"; notify "本番の鎖: 止めの印を見て $sc の前で止まった $(date -Is)"; exit 0; fi
  readdb "before-$sc" || { echo "db log: stop before $sc" >> "$W/chain.log"; notify "本番の鎖: DB のログに要注意の行があり $sc の前で止まった"; exit 20; }
  rm -f "$W/chain/chain.done"
  poc/lab/bin/measure-ladder-chain --scale=180000 --predictions="$PRED" --statement-record="$REC" \
    --out-dir="$W/chain" --ladder-out="$W/ladder" --scenarios="$sc" >> "$W/chain-$sc.stdout" 2>&1
  rc=$?
  echo "chain($sc) exit=$rc $(date -Is)" >> "$W/chain-ends.txt"
  readdb "after-$sc" || { echo "db log: stop after $sc" >> "$W/chain.log"; notify "本番の鎖: $sc の後で DB のログに要注意の行があり止まった (exit=$rc)"; exit 21; }
  if [ "$rc" -ne 0 ]; then notify "本番の鎖: $sc が exit=$rc で止まった $(date -Is)"; exit "$rc"; fi
  notify "本番の鎖: $sc が終わった exit=0 $(date -Is)"
done
notify "本番の鎖が全て終わった $(tail -1 "$W/chain-ends.txt")"

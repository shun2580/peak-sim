#!/usr/bin/env bash
# Every 5 minutes: if no k6-output.txt / chain stdout moved for 30 minutes while the chain runs, tell the operator once per episode; if the lab's db container is gone, tell the operator.
source "$HOME/.cache/peak-b180k/scripts/env.sh"
# IN: command that sends a short message to the operator (left out of this copy)
IN=:
alerted=0; downalerted=0
while true; do
  sleep 300
  if ! pgrep -f run-chain-watched.sh >/dev/null; then echo "chain script gone $(date -Is)" >> $OUT/watchdog.log; exit 0; fi
  now=$(date +%s); last=0
  for f in $(find $OUT/ladder -name k6-output.txt 2>/dev/null) $OUT/chain-s-*.stdout $OUT/chain/chain.log; do
    [ -e "$f" ] || continue; m=$(stat -c %Y "$f"); [ "$m" -gt "$last" ] && last=$m
  done
  idle=$(( now - last ))
  if [ $idle -gt 1800 ]; then
    if [ $alerted = 0 ]; then bash $IN "作業担当より(自動の見張り): 本番の測りの出力(k6-output/chain)が ${idle} 秒動かぬ。$(date -Is)。確かめに入る。"; bash $IN "見張り: 出力が ${idle} 秒動かぬ"; alerted=1; fi
  else alerted=0; fi
  if ! docker ps --format '{{.Names}}' | grep -q '^peak-b180k-db$'; then
    if [ $downalerted = 0 ]; then bash $IN "作業担当より(自動の見張り): peak-b180k-db が動いておらぬ(落ちたか)。$(date -Is)。§6.6 の確かめと起こし直しに入る。"; bash $IN "見張り: DB のコンテナが無い"; downalerted=1; fi
  else downalerted=0; fi
  echo "$(date -Is) idle=${idle}s" >> $OUT/watchdog.log
done

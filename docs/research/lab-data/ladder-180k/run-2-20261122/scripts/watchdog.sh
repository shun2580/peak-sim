#!/usr/bin/env bash
# Every 5 minutes: if no k6-output.txt / chain stdout moved for 30 minutes while the chain runs, note it once per episode; if the lab's db container is gone, note that too.
source "$HOME/.cache/peak-b180k/scripts/env.sh"
# record copy: the calls that sent notes to other processes of the working environment are replaced by comments
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
    if [ $alerted = 0 ]; then : ; alerted=1; fi  # record copy: a note that output was idle was sent here
  else alerted=0; fi
  if ! docker ps --format '{{.Names}}' | grep -q '^peak-b180k-db$'; then
    if [ $downalerted = 0 ]; then : ; downalerted=1; fi  # record copy: a note that the db container was gone was sent here
  else downalerted=0; fi
  echo "$(date -Is) idle=${idle}s" >> $OUT/watchdog.log
done

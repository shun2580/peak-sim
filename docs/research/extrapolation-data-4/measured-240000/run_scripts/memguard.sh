#!/usr/bin/env bash
# Self-notify if MemAvailable < 1500 MiB (checked every 60 s), once; stops when memlog.stop exists.
D="${T5A_OUTROOT:-$(dirname "$0")/../../measure}"
while [ ! -e "$D/memlog.stop" ]; do
  a=$(free -m | awk '/^Mem:/{print $7}')
  if [ "$a" -lt 1500 ]; then
    cd <notify-dir>
    <notify> "MemAvailable=${a}MiB (<1500). Start no new route; tell the operator."
    echo "$(date -Is) low ${a}" >> "$D/memguard.txt"; break
  fi
  sleep 60
done

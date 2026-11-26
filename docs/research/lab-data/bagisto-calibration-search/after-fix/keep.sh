#!/usr/bin/env bash
# keep.sh <name>...: copy the output of runs (the directory, the .out.txt and .err.txt) or of gate-only calls from the first place of the records into this directory,
# with the absolute paths in the text replaced (<out> for the output place, <repo> for the repository, <home> for the home directory). Nothing is removed.
set -uo pipefail
REC=$HOME/.cache/peak-lab-bagisto/out/calibration-search/t5s
HERE=$(cd "$(dirname "$0")" && pwd)
for n in "$@"; do
  [ -e "$REC/$n" ] || { echo "no $n"; continue; }
  [ -e "$HERE/$n" ] && { echo "already kept: $n"; continue; }
  cp -r "$REC/$n" "$HERE/$n"
  for x in "$n.out.txt" "$n.err.txt"; do [ -f "$REC/$x" ] && cp "$REC/$x" "$HERE/$x"; done
  grep -rIl . "$HERE/$n" "$HERE/$n.out.txt" "$HERE/$n.err.txt" 2>/dev/null | while read -r f; do
    sed -i "s#$REC#<out>#g; s#${PEAK_SIM_REPO:-/nonexistent}#<repo>#g; s#$HOME#<home>#g" "$f"
  done
done

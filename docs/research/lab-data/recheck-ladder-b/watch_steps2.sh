#!/usr/bin/env bash
# polls for newly finished steps (ended_at.txt: no DB, no docker) and reads the stats right after each one (inside the next step's discarded minute)
W=$HOME/.cache/peak-lab/out/cmd277t6
seen=$W/watch.seen.restart; touch $seen
while [ ! -e $W/chain.done ]; do
  for f in $HOME/.cache/peak-lab/out/ladder/r-*/rung-*/ended_at.txt; do
    [ -e "$f" ] || continue
    grep -qxF "$f" $seen && continue
    echo "$f" >> $seen
    d=$(dirname "$f"); sc=$(basename $(dirname $d)); scale=${sc##*-}
    bash $W/stats.sh "$scale" $W/stats-steps-restart.tsv "after-$(basename $(dirname $d))/$(basename $d)"
  done
  sleep 5
done

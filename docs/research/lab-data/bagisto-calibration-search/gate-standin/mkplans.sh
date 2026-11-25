#!/usr/bin/env bash
# mkplans.sh <rps...>: make the plans (even, first80, odd80, top1, top2) at each rps in the search record's plans dir (tools container, no network)
REC=$HOME/.cache/peak-lab-bagisto/out/calibration-search; P=$REC/plans; mkdir -p $P
mk(){ docker exec peak-lab-bagisto-tools php /peak-sim/poc/lab/load/make-plan.php --routes=/out/calibration-search/route-list.json "$@"; }
for r in "$@"; do
  [ -s $P/even_r$r.json ]   || mk --mix=even   --rps=$r > $P/even_r$r.json
  [ -s $P/first80_r$r.json ] || mk --mix=first80 --rps=$r > $P/first80_r$r.json
  [ -s $P/odd80_r$r.json ]  || mk --mix=odd80  --rps=$r > $P/odd80_r$r.json
  [ -s $P/top1_r$r.json ]   || mk --mix=top1_80 --rps=$r --allocation=/out/calibration-search/alloc_top1.json > $P/top1_r$r.json
  [ -s $P/top2_r$r.json ]   || mk --mix=top2_80 --rps=$r --allocation=/out/calibration-search/alloc_top2.json > $P/top2_r$r.json
done

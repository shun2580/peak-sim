#!/usr/bin/env bash
# Records WSL MemAvailable every 180 s until fixtures/bookstack/variance have all ended (stop file) .
OUT="${T5A_OUTROOT:-$(dirname "$0")/../../measure}/memavail.tsv"
echo -e "time\tavailable_MiB\tfree_MiB\tswap_used_MiB" >> "$OUT"
while [ ! -e "${T5A_OUTROOT:-$(dirname "$0")/../../measure}/memlog.stop" ]; do
  free -m | awk -v t="$(date -Is)" '/^Mem:/{a=$7;f=$4} /^Swap:/{s=$3} END{printf "%s\t%s\t%s\t%s\n",t,a,f,s}' >> "$OUT"
  sleep 180
done

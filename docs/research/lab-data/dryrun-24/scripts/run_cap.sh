#!/usr/bin/env bash
# dry run 24: does the memory cap of the app container work? Same door and tools as test A; the compose file is the one with
# mem_limit set from PEAK_MEASURE_PHP_MEM (here 400m so that a 920 MB route is killed). Two routes only. Not a test.
set -u
TR=~/projects/peak-sim-trials; W=$TR/_scratch/dryrun24; O=$TR/_out/dryrun24/cap; R=$W/rep
P=$R/poc/lab/predictions; IMG=peak-measure-php-bagisto:8.4
EST=$P/dryrun24-bagisto-estimate.json; MK=$P/dryrun24-bagisto-marked.json
NT=$(paste -sd, ~/projects/peak-sim/docs/research/bagisto-prep/n-tables.txt)
PROJ=peak-bagisto-dry24c; OR=$TR/_out/dryrun24/order_record.txt
avail=$(free -m | awk 'NR==2{print $7}')
if [ "$avail" -lt 3072 ]; then echo "available ${avail} MB < 3072: not started" > $O/not_started.txt; exit 3; fi
other=$(docker ps --format '{{.Names}}' | grep -E '^peak-' || true)
if [ -n "$other" ]; then mkdir -p $O; echo "other containers running: $other" > $O/not_started.txt; exit 3; fi
mkdir -p $O/measure
echo "free_before_cap: $(free -m | sed -n 2p)" > $O/host_before.txt; df -h / >> $O/host_before.txt
echo "cap_start_iso=$(date -Iseconds) tool_commit=$(git -C $R rev-parse HEAD) project=$PROJ mem=400m compose=$W/cap/measure.compose.cap.yaml" >> $OR
( while [ ! -e $O/sampler.stop ]; do
    c=$(docker ps -q --filter "label=com.docker.compose.project=$PROJ" --filter "label=com.docker.compose.service=measure-php")
    if [ -n "$c" ]; then echo "$(date -Is) mem_limit=$(docker inspect -f '{{.HostConfig.Memory}} swap={{.HostConfig.MemorySwap}} oomkilled={{.State.OOMKilled}} restarts={{.RestartCount}} state={{.State.Status}}' $c) events=$(docker exec $c cat /sys/fs/cgroup/memory.events 2>/dev/null | tr '\n' ' ') cur=$(docker exec $c cat /sys/fs/cgroup/memory.current 2>/dev/null)"; fi
    d=$(docker ps -q --filter "label=com.docker.compose.project=$PROJ" --filter "label=com.docker.compose.service=measure-db")
    [ -n "$d" ] && echo "$(date -Is) db state=$(docker inspect -f '{{.State.Status}} {{.State.Health.Status}} oomkilled={{.State.OOMKilled}} restarts={{.RestartCount}} limit={{.HostConfig.Memory}}' $d)"
    sleep 3
  done ) > $O/sampler.log 2>&1 &
sp=$!
PEAK_MEASURE_PHP_MEM=400m PEAK_COMPOSE_FILE=$W/cap/measure.compose.cap.yaml PEAK_COMPOSE_PROJECT=$PROJ PEAK_PHP_IMAGE=$IMG PHP_VERSION=8.4 \
  bash $R/poc/lab/bin/measure-extrapolation --estimate=$EST --marked=$MK measure -- \
  --app=$TR/bagisto --static=$TR/_out/bagisto/analysis.json --data=generator --profile=bagisto --scales=30000 --n-tables="$NT" \
  --only="GET /admin/customers/search,GET /admin/customers/view/{id}" --request-timeout=300 --warm=off --keep-stack --out=$O/measure > $O/measure.stdout.log 2> $O/measure.stderr.log
rc=$?
echo "cap_exit=$rc end_iso=$(date -Iseconds)" >> $OR
sleep 4; touch $O/sampler.stop; wait $sp
c=$(docker ps -q --filter "label=com.docker.compose.project=$PROJ" --filter "label=com.docker.compose.service=measure-php")
[ -n "$c" ] && { docker exec $c cat /sys/fs/cgroup/memory.events > $O/memory_events_final.txt 2>&1; docker inspect -f 'limit={{.HostConfig.Memory}} swap={{.HostConfig.MemorySwap}} oomkilled={{.State.OOMKilled}} restarts={{.RestartCount}}' $c > $O/php_inspect_final.txt; }
d=$(docker ps -q --filter "label=com.docker.compose.project=$PROJ" --filter "label=com.docker.compose.service=measure-db")
[ -n "$d" ] && docker inspect -f '{{.State.Status}} {{.State.Health.Status}} oomkilled={{.State.OOMKilled}} restarts={{.RestartCount}} limit={{.HostConfig.Memory}}' $d > $O/db_inspect_final.txt
for cc in $(docker ps -q --filter "label=com.docker.compose.project=$PROJ" --filter "label=com.docker.compose.service=measure-php"); do docker stop -t 30 "$cc" >/dev/null 2>&1; done
for cc in $(docker ps -q --filter "label=com.docker.compose.project=$PROJ" --filter "label=com.docker.compose.service=measure-db"); do docker stop -t 180 "$cc" >/dev/null 2>&1; done
echo "cap_stopped=$(date -Iseconds)" >> $OR

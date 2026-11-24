#!/usr/bin/env bash
# dry run 24: the same door and tools as test A, a few routes of Bagisto at 30,000 rows (a size already seen). Not a test.
# T=trials dir, R=scratch clone (the dry-run prediction is committed in it only), O=output dir, ROUTES / MARKED are set below.
set -u
TR=~/projects/peak-sim-trials; W=$TR/_scratch/dryrun24; O=$TR/_out/dryrun24/final; R=$W/rep
P=$R/poc/lab/predictions; IMG=peak-measure-php-bagisto:8.4
EST=$P/dryrun24-bagisto-estimate.json; MK=$P/dryrun24-bagisto-marked.json
ROUTES=$(paste -sd, $W/routes_measure.txt)            # "METHOD uri" lines
NT=$(paste -sd, ~/projects/peak-sim/docs/research/bagisto-prep/n-tables.txt)
MARKEDR=$(paste -sd, $W/routes_repeat.txt)            # routes of the repeat (marked ones that were selected, plus the state-changing ones, for the rehearsal)
avail=$(free -m | awk 'NR==2{print $7}')
if [ "$avail" -lt 3072 ]; then echo "available ${avail} MB < 3072: not started" > $O/not_started.txt; exit 3; fi
other=$(docker ps --format '{{.Names}}' | grep -E '^peak-' || true)
if [ -n "$other" ]; then echo "other containers running: $other" > $O/not_started.txt; exit 3; fi
mkdir -p $O/measure $O/variance
OR=$TR/_out/dryrun24/order_record.txt
echo "free_before_measure: $(free -m | sed -n 2p)" > $O/host_before_measure.txt; df -h / >> $O/host_before_measure.txt
echo "measure_start_iso=$(date -Iseconds) tool_commit=$(git -C $R rev-parse HEAD) project=peak-bagisto-dry24f" >> $OR
( while [ ! -e $O/sampler.stop ]; do
    for c in $(docker ps -q --filter "label=com.docker.compose.service=measure-php"); do
      pj=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' $c)
      case "$pj" in peak-bagisto-dry24f*|peak-bgdry24f-var*) echo "$(date -Is) $pj php limit=$(docker inspect -f '{{.HostConfig.Memory}} swap={{.HostConfig.MemorySwap}} oomkilled={{.State.OOMKilled}} restarts={{.RestartCount}}' $c) events=$(docker exec $c cat /sys/fs/cgroup/memory.events 2>/dev/null | tr '\n' ' ') cur=$(docker exec $c cat /sys/fs/cgroup/memory.current 2>/dev/null)";; esac
    done
    for d in $(docker ps -q --filter "label=com.docker.compose.service=measure-db"); do
      pj=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' $d)
      case "$pj" in peak-bagisto-dry24f*|peak-bgdry24f-var*) echo "$(date -Is) $pj db $(docker inspect -f '{{.State.Status}} {{.State.Health.Status}} oomkilled={{.State.OOMKilled}} restarts={{.RestartCount}} limit={{.HostConfig.Memory}}' $d)";; esac
    done
    sleep 5
  done ) > $O/sampler.log 2>&1 &
sp=$!
PEAK_COMPOSE_FILE=$W/cap/measure.compose.cap.yaml PEAK_COMPOSE_PROJECT=peak-bagisto-dry24f PEAK_PHP_IMAGE=$IMG PHP_VERSION=8.4 bash $R/poc/lab/bin/measure-extrapolation \
  --estimate=$EST --marked=$MK measure -- \
  --app=$TR/bagisto --static=$TR/_out/bagisto/analysis.json --data=generator --profile=bagisto --scales=30000 --n-tables="$NT" \
  --only="$ROUTES" --request-timeout=300 --warm=off --keep-stack --out=$O/measure > $O/measure.stdout.log 2> $O/measure.stderr.log
rc=$?
echo "measure_exit=$rc end_iso=$(date -Iseconds)" >> $OR
if [ $rc -eq 0 ] && [ -n "$MARKEDR" ]; then
  echo "free_before_repeat: $(free -m | sed -n 2p)" > $O/host_before_repeat.txt
  echo "repeat_start_iso=$(date -Iseconds) project-prefix=peak-bgdry24f-var" >> $OR
  PEAK_COMPOSE_FILE=$W/cap/measure.compose.cap.yaml PEAK_PHP_IMAGE=$IMG PHP_VERSION=8.4 bash $R/poc/lab/bin/measure-extrapolation --estimate=$EST --marked=$MK repeat -- \
    --app=$TR/bagisto --tasks=$O/measure/tasks.json --scale=30000 --out=$O/variance --label=dry24 \
    --routes="$MARKEDR" --dbs=1 --laps=2 --n-tables="$NT" --data=generator --profile=bagisto --php-image=$IMG --stats-auto-recalc=keep --tap=off \
    --project-prefix=peak-bgdry24f-var --source-commit=$(git -C $R rev-parse HEAD) --request-timeout=300 --keep-stack \
    > $O/repeat.stdout.log 2> $O/repeat.stderr.log
  rc2=$?
  echo "repeat_exit=$rc2 end_iso=$(date -Iseconds)" >> $OR
fi
touch $O/sampler.stop; wait $sp
# stop gently; containers and volumes are kept
for pr in peak-bagisto-dry24f $(docker ps -a --format '{{.Label "com.docker.compose.project"}}' | grep '^peak-bgdry24f-var' | sort -u); do
  for c in $(docker ps -q --filter "label=com.docker.compose.project=$pr" --filter "label=com.docker.compose.service=measure-php"); do docker stop -t 30 "$c" >/dev/null 2>&1; done
  for c in $(docker ps -q --filter "label=com.docker.compose.project=$pr" --filter "label=com.docker.compose.service=measure-db"); do docker stop -t 180 "$c" >/dev/null 2>&1; done
done
echo "final_stopped=$(date -Iseconds)" >> $OR

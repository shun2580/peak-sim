#!/usr/bin/env bash
# dry run 24: EXPLAIN at 30,000 rows (small side 3,000) on a throw-away database in its own stack. EXPLAIN only.
set -u
TR=~/projects/peak-sim-trials; W=$TR/_scratch/dryrun24; O=$TR/_out/dryrun24; R=$W/rep; CAP=$TR/_out/bagisto/capture3
PS=~/projects/peak-sim; NT=$(paste -sd, $PS/docs/research/bagisto-prep/n-tables.txt)
PROJ=peak-bagisto-dry24-eas
[ ! -e "$O/explain/n30000" ] || { echo "explain dir exists" >&2; exit 2; }
mkdir -p $O/explain
echo "start_explain=$(date -Is) project=$PROJ capture_sha=$(sha256sum $CAP/capture.json|cut -d' ' -f1) tasks_sha=$(sha256sum $CAP/tasks.json|cut -d' ' -f1)" >> $O/order_record.txt
PEAK_COMPOSE_PROJECT=$PROJ PHP_VERSION=8.4 bash $R/poc/runtime/bin/explain-at-scale --capture=$CAP/capture.json --tasks=$CAP/tasks.json --app=$TR/bagisto \
  --scale=30000 --small-scale=3000 --n-tables="$NT" --out=$O/explain --label=n30000 --php-image=peak-measure-php-bagisto:8.4 --keep-stack \
  > $O/explain/eas.stdout.log 2> $O/explain/eas.stderr.log
rc=$?
echo "end_explain=$(date -Is) exit=$rc" >> $O/order_record.txt
for c in $(docker ps -q --filter "label=com.docker.compose.project=$PROJ" --filter "label=com.docker.compose.service=measure-php"); do docker stop -t 30 "$c" >/dev/null 2>&1; done
for c in $(docker ps -q --filter "label=com.docker.compose.project=$PROJ" --filter "label=com.docker.compose.service=measure-db"); do docker stop -t 180 "$c" >/dev/null 2>&1; done
echo "stopped=$(date -Is)" >> $O/order_record.txt

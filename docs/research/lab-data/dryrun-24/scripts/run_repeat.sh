#!/usr/bin/env bash
# dry run 24: the repeat (marked routes, extra cold laps) on the tasks of the measure step. Second try: the first one stopped at once on a project prefix that was too long.
set -u
TR=~/projects/peak-sim-trials; W=$TR/_scratch/dryrun24; O=$TR/_out/dryrun24; R=$W/rep
P=$R/poc/lab/predictions; IMG=peak-measure-php-bagisto:8.4
EST=$P/dryrun24-bagisto-estimate.json; MK=$P/dryrun24-bagisto-marked.json
NT=$(paste -sd, ~/projects/peak-sim/docs/research/bagisto-prep/n-tables.txt); MARKEDR=$(paste -sd, $W/routes_repeat.txt)
mkdir -p $O/variance
echo "free_before_repeat: $(free -m | sed -n 2p)" > $O/host_before_repeat.txt
echo "repeat_start_iso=$(date -Iseconds) project-prefix=peak-bgdry24-var" >> $O/order_record.txt
PEAK_PHP_IMAGE=$IMG PHP_VERSION=8.4 bash $R/poc/lab/bin/measure-extrapolation --estimate=$EST --marked=$MK repeat -- \
  --app=$TR/bagisto --tasks=$O/measure/tasks.json --scale=30000 --out=$O/variance --label=dry24 \
  --routes="$MARKEDR" --dbs=1 --laps=2 --n-tables="$NT" --data=generator --profile=bagisto --php-image=$IMG --stats-auto-recalc=keep --tap=off \
  --project-prefix=peak-bgdry24-var --source-commit=$(git -C $R rev-parse HEAD) --request-timeout=300 --keep-stack \
  > $O/repeat.stdout.log 2> $O/repeat.stderr.log
rc=$?
echo "repeat_exit=$rc end_iso=$(date -Iseconds)" >> $O/order_record.txt
for pr in peak-bagisto-dry24 $(docker ps -a --format '{{.Label "com.docker.compose.project"}}' | grep '^peak-bgdry24-var' | sort -u); do
  for c in $(docker ps -q --filter "label=com.docker.compose.project=$pr" --filter "label=com.docker.compose.service=measure-php"); do docker stop -t 30 "$c" >/dev/null 2>&1; done
  for c in $(docker ps -q --filter "label=com.docker.compose.project=$pr" --filter "label=com.docker.compose.service=measure-db"); do docker stop -t 180 "$c" >/dev/null 2>&1; done
done
echo "stopped2=$(date -Iseconds)" >> $O/order_record.txt

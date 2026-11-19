#!/usr/bin/env bash
set -uo pipefail
# T: trials directory, R: scratch clone of the repository, O: output directory (set by the caller).
: "${T:?set T}" "${R:?set R}" "${O:?set O}"
P=$R/poc/lab/predictions; IMG=peak-measure-php-bookstack:8.3
notify() { echo "$(date -Iseconds) $1"; }
avail=$(free -m | awk 'NR==2{print $7}')
if [ "$avail" -lt 3072 ]; then echo "available ${avail} MB < 3072: not started" > $O/bookstack.not_started.txt; notify "A dry run on BookStack not started (available ${avail}MB)"; exit 3; fi
cd "$R"
ONLY='=/books/{slug},=/books/{slug}/references,=/shelves/{slug},=/settings/maintenance/regenerate-references,=/settings/users/create'
echo "free_before_measure: $(free -m | sed -n 2p)" > $O/host_before_measure_bookstack.txt
echo "measure_start_iso=$(date -Iseconds) tool_commit=$(git rev-parse HEAD) project=peak-dr6-a-bs" > $O/bookstack.order.txt
PEAK_COMPOSE_PROJECT=peak-dr6-a-bs PEAK_PHP_IMAGE=$IMG bash $R/poc/lab/bin/measure-extrapolation \
  --estimate=$P/extrapolation-3-bookstack-estimate.json --marked=$P/extrapolation-3-bookstack-marked.json measure -- \
  --app=$T/bookstack --static=$T/_out/_stageA3/bookstack/analysis.json --data=generator --profile=bookstack --scales=30000 \
  --only="$ONLY" --request-timeout=600 --warm=off --out=$O/bookstack > $O/bookstack.stdout.log 2> $O/bookstack.stderr.log
rc=$?
echo "measure_exit=$rc end_iso=$(date -Iseconds)" >> $O/bookstack.order.txt
notify "A dry run on BookStack: measure finished exit=$rc"
[ $rc -eq 0 ] || exit $rc
echo "free_before_repeat: $(free -m | sed -n 2p)" > $O/host_before_repeat_bookstack.txt
ROUTES='GET /books/{slug},PUT /books/{slug},GET /books/{slug}/references,GET /shelves/{slug},PUT /shelves/{slug},DELETE /shelves/{slug},POST /settings/maintenance/regenerate-references'
echo "repeat_start_iso=$(date -Iseconds) project-prefix=peak-dr6-var" >> $O/bookstack.order.txt
PEAK_REPO_ROOT=$R bash $R/poc/lab/bin/measure-extrapolation \
  --estimate=$P/extrapolation-3-bookstack-estimate.json --marked=$P/extrapolation-3-bookstack-marked.json repeat -- \
  --app=$T/bookstack --tasks=$O/bookstack/tasks.json --scale=30000 --out=$O/variance/bookstack --label=a-dry30k \
  --routes="$ROUTES" --dbs=1 --laps=2 --data=generator --profile=bookstack --php-image=$IMG --stats-auto-recalc=keep --tap=off \
  --project-prefix=peak-dr6-var --source-commit=$(git rev-parse HEAD) --request-timeout=600 --keep-stack > $O/bookstack.repeat.stdout.log 2> $O/bookstack.repeat.stderr.log
rc=$?
echo "repeat_exit=$rc end_iso=$(date -Iseconds)" >> $O/bookstack.order.txt
notify "A dry run on BookStack: repeat finished exit=$rc"
exit $rc

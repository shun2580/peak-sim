#!/usr/bin/env bash
# Test A at 240,000 rows: BookStack, the 31 items of T3_only.txt cold once at 240,000 rows (the build limit is 7,200 s). REDO of the cold-once round cut by the 11:12 Windows Update restart. Only differences from measure_bookstack.sh: the project name (peak-t5a-bs2) and this note. Started after measure_fixtures.sh (the earlier runs did the two apps one after the other).
. "$(dirname "$0")/common.sh"
PROJ_BS=peak-t5a-bs2   # redo after the 11:12 restart: a new project name, a new volume (the cut round of peak-t5a-bs is not reused)
D=$OUTROOT/bookstack; W=$OUTROOT/bookstack.wall.txt
gates measure_bookstack "$D"
echo "measure_bookstack_start $(date -Is) tool_commit=$TOOL_COMMIT project=$PROJ_BS" >> "$ORDER"
echo "start_iso=$(date -Is) tool_commit=$TOOL_COMMIT project=$PROJ_BS" > "$W"
CMD="PEAK_COMPOSE_PROJECT=$PROJ_BS PEAK_PHP_IMAGE=peak-measure-php-bookstack:8.3 bash $R/poc/lab/bin/measure-extrapolation --estimate=$BS_EST --marked=$BS_MARK measure -- --app=$B/bookstack --static=$BSTATIC --data=generator --profile=bookstack --scales=240000 --only=<T3_only.txt: 31 items> --request-timeout=600 --warm=off --out=$D"
echo "cmd=$CMD" >> "$W"
watch_opts "$PROJ_BS" "$OUTROOT/bookstack.create_options.tsv" "$OUTROOT/bookstack.done" & wp=$!
echo "start_bookstack=$(date -Is)" >> "$OUTROOT/run_times.txt"
PEAK_COMPOSE_PROJECT=$PROJ_BS PEAK_PHP_IMAGE=peak-measure-php-bookstack:8.3 bash "$R/poc/lab/bin/measure-extrapolation" --estimate="$BS_EST" --marked="$BS_MARK" measure -- \
  --app="$B/bookstack" --static="$BSTATIC" --data=generator --profile=bookstack --scales=240000 --only="$(cat "$ONLY_FILE")" \
  --request-timeout=600 --warm=off --out="$D" > "$OUTROOT/bookstack.stdout.log" 2> "$OUTROOT/bookstack.stderr.log"
rc=$?; touch "$OUTROOT/bookstack.done"; wait $wp
echo "end_bookstack=$(date -Is) exit_bookstack=$rc" >> "$OUTROOT/run_times.txt"
echo "measure_bookstack_end $(date -Is) exit=$rc" >> "$ORDER"; echo "exit=$rc end_iso=$(date -Is)" >> "$W"
notify "A 240,000 BookStack measure (31 items cold once) ended exit=$rc"
exit $rc

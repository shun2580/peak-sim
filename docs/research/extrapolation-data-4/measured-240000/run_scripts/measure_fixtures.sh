#!/usr/bin/env bash
# Test A at 240,000 rows: verification app, every route cold once at 240,000 rows. Started only through poc/lab/bin/measure-extrapolation (the gate).
# NOT STARTED. Needs T5A_START_OK=1 and NOTIFY_TO (see common.sh). The estimate and the marked list named here are the committed files of f52f692 (tool copy 7a4cf32).
. "$(dirname "$0")/common.sh"
D=$OUTROOT/fixtures; W=$OUTROOT/fixtures.wall.txt
gates measure_fixtures "$D"
echo "measure_fixtures_start $(date -Is) tool_commit=$TOOL_COMMIT project=$PROJ_FX" >> "$ORDER"
echo "start_iso=$(date -Is) tool_commit=$TOOL_COMMIT project=$PROJ_FX" > "$W"
CMD="PEAK_COMPOSE_PROJECT=$PROJ_FX bash $R/poc/lab/bin/measure-extrapolation --estimate=$FX_EST --marked=$FX_MARK measure -- --app=$R/fixtures/laravel-app --static=$FSTATIC --data=seeder --scales=240000 --request-timeout=600 --warm=off --out=$D"
echo "cmd=$CMD" >> "$W"
watch_opts "$PROJ_FX" "$OUTROOT/fixtures.create_options.tsv" "$OUTROOT/fixtures.done" & wp=$!
echo "start_fixtures=$(date -Is)" >> "$OUTROOT/run_times.txt"
PEAK_COMPOSE_PROJECT=$PROJ_FX bash "$R/poc/lab/bin/measure-extrapolation" --estimate="$FX_EST" --marked="$FX_MARK" measure -- \
  --app="$R/fixtures/laravel-app" --static="$FSTATIC" --data=seeder --scales=240000 --request-timeout=600 --warm=off --out="$D" \
  > "$OUTROOT/fixtures.stdout.log" 2> "$OUTROOT/fixtures.stderr.log"
rc=$?; touch "$OUTROOT/fixtures.done"; wait $wp
echo "end_fixtures=$(date -Is) exit_fixtures=$rc" >> "$OUTROOT/run_times.txt"
echo "measure_fixtures_end $(date -Is) exit=$rc" >> "$ORDER"; echo "exit=$rc end_iso=$(date -Is)" >> "$W"
notify "A 240,000 verification app measure (every route cold once) ended exit=$rc"
exit $rc

#!/usr/bin/env bash
# Test A at 240,000 rows: the marked routes, three more cold tries on ONE 240,000-row database (third round: --dbs=1 --laps=3).
# BookStack only: the verification app has 0 marked routes of 24 in the committed list, so nothing is repeated for it (written to fixtures.skipped).
# NOT STARTED. May run at the same time as measure_fixtures.sh (counts only; own compose project and database).
. "$(dirname "$0")/common.sh"
V=$OUTROOT/variance; W=$OUTROOT/repeat.wall.txt
gates repeat_bookstack "$V"
routes=$(awk -F'\t' '$1=="bookstack"{print $2}' "$MARKED_TSV" | paste -sd, -)
[ -n "$routes" ] || gate_fail repeat_bookstack "no marked routes for bookstack in $MARKED_TSV"
mkdir -p "$V"
fxn=$(awk -F'\t' '$1=="fixtures"' "$MARKED_TSV" | wc -l)
[ "$fxn" -eq 0 ] && echo "fixtures: 0 marked routes in the committed list ($(basename "$FX_MARK")); nothing to repeat, $(date -Is)" > "$V/fixtures.skipped"
[ "$fxn" -eq 0 ] || gate_fail repeat_bookstack "the verification app has $fxn marked routes now: this script repeats BookStack only; stop and ask the operator"
echo "repeat_start $(date -Is) tool_commit=$TOOL_COMMIT project=$PROJ_VAR" >> "$ORDER"
echo "start_iso=$(date -Is) tool_commit=$TOOL_COMMIT project=$PROJ_VAR" > "$W"
echo "cmd=bash $R/poc/lab/bin/measure-extrapolation --estimate=$BS_EST --marked=$BS_MARK repeat -- --app=$B/bookstack --tasks=$TASKS_BS --scale=240000 --out=$V/bookstack --label=$VAR_LABEL --routes=<the routes of the committed list> --dbs=1 --laps=3 --data=generator --profile=bookstack --php-image=peak-measure-php-bookstack:8.3 --stats-auto-recalc=keep --tap=off --project-prefix=$VAR_PREFIX --source-commit=$TOOL_COMMIT --request-timeout=600 --keep-stack" >> "$W"
# right after the build (content.sha256 appears), before the first request if possible
pre_check() {
  local f="$V/bookstack/$VAR_LABEL/db1/g1/content.sha256" t=0
  [ "${DRYRUN:-0}" = 1 ] && return 0
  while [ ! -f "$f" ] && [ ! -f "$V/bookstack.done" ] && [ $t -lt 40000 ]; do sleep 2; t=$((t+2)); done
  [ -f "$f" ] && read_opts "$PROJ_VAR" "$V/bookstack.create_options_before.tsv" || echo "build did not finish before the run ended" > "$V/bookstack.create_options_before.tsv"
  echo "first_trial_dir_at_read: $(ls -d "$V/bookstack/$VAR_LABEL"/db1/g1/*_lap* 2>/dev/null | wc -l) lap dirs existed" >> "$V/bookstack.create_options_before.tsv"
}
pre_check & pp=$!
echo "start_bookstack_repeat=$(date -Is)" >> "$OUTROOT/run_times.txt"
bash "$R/poc/lab/bin/measure-extrapolation" --estimate="$BS_EST" --marked="$BS_MARK" repeat -- \
  --app="$B/bookstack" --tasks="$TASKS_BS" --scale=240000 --out="$V/bookstack" --label="$VAR_LABEL" \
  --routes="$routes" --dbs=1 --laps=3 --data=generator --profile=bookstack --php-image=peak-measure-php-bookstack:8.3 \
  --stats-auto-recalc=keep --tap=off --project-prefix="$VAR_PREFIX" --source-commit="$TOOL_COMMIT" --request-timeout=600 --keep-stack \
  > "$V/bookstack.stdout.log" 2> "$V/bookstack.stderr.log"
rc=$?; touch "$V/bookstack.done"; wait $pp
if [ "${DRYRUN:-0}" != 1 ]; then
  read_opts "$PROJ_VAR" "$V/bookstack.create_options_after.tsv"
  PEAK_COMPOSE_PROJECT="$PROJ_VAR" bash "$R/poc/runtime/bin/stack" down >/dev/null 2>&1   # our own stack, brought down by the tool's own command (no kill)
fi
echo "end_bookstack_repeat=$(date -Is) exit_bookstack_repeat=$rc" >> "$OUTROOT/run_times.txt"
echo "repeat_end $(date -Is) exit=$rc" >> "$ORDER"; echo "exit=$rc end_iso=$(date -Is)" >> "$W"
notify "A 240,000 BookStack repeat (marked routes x 3 laps) ended exit=$rc"
exit $rc

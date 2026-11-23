#!/usr/bin/env bash
# Shared part of the 70,000-row cause measurement scripts. Sourced, never run alone.
#   SCALE   rows of the scaled table (default 70000)      LAPS  laps of the routes list (default 3)
#   MODE    main | dry : where the record goes and how the compose projects are named
set -u
B=$HOME/projects/peak-sim-trials
D=$B/_scratch/cause70k
TOOL=$B/_scratch/run-286-t5a/rep-7a4cf32      # the tool copy of the earlier 240,000-row run (git commit below, clean tree, vendor installed)
TOOL_COMMIT=7a4cf32d24e64e23acbfa842fc35ab81eade4d10
APP=$B/bookstack
TASKS=$D/tasks_merged_bookstack.json         # byte-identical to the list the 240,000-row run used
IMAGE=peak-measure-php-bookstack:8.3
MODE=${MODE:-main}
SCALE=${SCALE:-70000}
LAPS=${LAPS:-3}
MIN_AVAIL_MB=${MIN_AVAIL_MB:-3072}           # a floor carried over from the earlier run, not a measured need
if [ "$MODE" = main ]; then OUT=$B/_out/cause70k/main; PREFIX=peak-cause70k; else OUT=$B/_out/cause70k/$MODE; PREFIX=peak-cause70k-$MODE; fi
mkdir -p "$OUT"
# The sealed stack's own throwaway passwords, fixed per machine (files mode 600, values never printed or recorded) so that a kept
# database volume can be opened again later with the same stack; without them the tool draws new random ones for every run.
export PEAK_MEASURE_DB_ROOT_PASSWORD=$(cat "$D/secrets/db_root.pw") PEAK_MEASURE_DB_PASSWORD=$(cat "$D/secrets/db_user.pw")

# The 21 routes of the 240,000-row repeat, in that file's order.
ROUTES_21=$(awk -F'\t' '$1=="bookstack"{print $2}' "$D/marked_routes.tsv" | paste -sd, -)
# One request at a time: rows 2, 5, 9 (and 7) of the table, which are in the 21 as well, and the two routes outside the 21 (7, 10).
ROUTES_SINGLE='GET /books/{bookSlug}/page/{pageSlug},GET /shelves/{slug},POST /settings/maintenance/regenerate-references,POST /books/{bookSlug}/draft/{pageId},DELETE /settings/maintenance/cleanup-images'
ROUTE_CLEANUP='DELETE /settings/maintenance/cleanup-images'

stamp() { date +%Y-%m-%dT%H:%M:%S%z; }
vol_list() { docker volume ls -q | sort; }

# before <step>: checks, and writes what the host looked like. Returns non-zero (and says why) if a check fails.
before() {
  local step=$1 f am
  f="$OUT/host_before_$step.txt"
  [ -d "$TOOL/.git" ] && [ "$(git -C "$TOOL" rev-parse HEAD)" = "$TOOL_COMMIT" ] && [ -z "$(git -C "$TOOL" status --porcelain)" ] || { echo "tool copy is not clean at $TOOL_COMMIT" >&2; return 2; }
  [ -d "$TOOL/vendor" ] && [ -f "$APP/vendor/autoload.php" ] || { echo "vendor directories missing" >&2; return 2; }
  docker image inspect "$IMAGE" >/dev/null 2>&1 || { echo "image $IMAGE missing" >&2; return 2; }
  am=$(free -m | awk 'NR==2{print $7}')
  [ "${am:-0}" -ge "$MIN_AVAIL_MB" ] || { echo "available memory ${am}MB is below $MIN_AVAIL_MB MB" >&2; return 2; }
  { echo "# at $(stamp) step $step"; free -g; free -m; df -h "$HOME"; docker ps --format '{{.Names}} {{.Status}}'; } >> "$f"
  vol_list > "$OUT/volumes_before_$step.txt"
  echo "volumes before: $(wc -l < "$OUT/volumes_before_$step.txt")" >> "$f"
}
# after <step>: no volume that existed before may be gone (the run may add its own).
after() {
  local step=$1 gone
  vol_list > "$OUT/volumes_after_$step.txt"
  gone=$(comm -23 "$OUT/volumes_before_$step.txt" "$OUT/volumes_after_$step.txt")
  { echo "# at $(stamp) step $step"; echo "volumes before $(wc -l < "$OUT/volumes_before_$step.txt") after $(wc -l < "$OUT/volumes_after_$step.txt")";
    echo "gone: ${gone:-none}"; echo "added:"; comm -13 "$OUT/volumes_before_$step.txt" "$OUT/volumes_after_$step.txt"; free -g; } > "$OUT/volumes_check_$step.txt"
  [ -z "$gone" ]
}
# stop_stack <label>: the stack was left up (--keep-stack), so stop its containers the gentle way. Never `compose down`, never -v.
stop_stack() {
  local p="$PREFIX-$(printf '%s' "$1" | tr 'A-Z_' 'a-z-')-db1" c n
  # the database volume is anonymous in this copy's compose file: write down which volume belongs to which project (the earlier run did not)
  for c in $(docker ps -aq --filter "label=com.docker.compose.project=$p" --filter "label=com.docker.compose.service=measure-db"); do
    { echo "project $p"; docker inspect -f '{{range .Mounts}}{{.Type}} {{.Name}} {{.Destination}}{{"\n"}}{{end}}' "$c"; } >> "$OUT/volume_ids.txt"
  done
  # the PHP container holds no data and does not stop on SIGTERM: a short wait. The database gets the long one.
  for c in $(docker ps -q --filter "label=com.docker.compose.project=$p" --filter "label=com.docker.compose.service=measure-php"); do docker stop -t 3 "$c" >/dev/null; done
  for c in $(docker ps -q --filter "label=com.docker.compose.project=$p" --filter "label=com.docker.compose.service=measure-db"); do docker stop -t 180 "$c" >/dev/null; n=$(docker inspect -f '{{.State.ExitCode}}' "$c"); echo "project $p database container stopped, exit code $n" >> "$OUT/volume_ids.txt"; done
}
# vrun <label> <outdir-name> <args...>: variance-run from the tool copy. The copy is at 7a4cf32, whose teardown trap runs
# `compose down -v` (variance-run line 247; the fix that keeps the volume came later, 09514cd). So --keep-stack is REQUIRED here:
# it skips that trap, and the containers are stopped by stop_stack instead.
vrun() {
  local label=$1 odir=$2; shift 2
  bash "$TOOL/poc/runtime/bin/variance-run" --app="$APP" --tasks="$TASKS" --scale="$SCALE" --out="$OUT/$odir" --label="$label" \
    --dbs=1 --data=generator --profile=bookstack --php-image="$IMAGE" --stats-auto-recalc=keep --request-timeout=600 \
    --project-prefix="$PREFIX" --source-commit="$TOOL_COMMIT" --keep-stack "$@"
}

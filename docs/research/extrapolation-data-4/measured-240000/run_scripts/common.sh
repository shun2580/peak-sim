#!/usr/bin/env bash
# Test A at 240,000 rows: shared part of the three measurement scripts of test A at 240,000 rows (fifth round). Adapted from the third round scripts. NOT STARTED. Sourced, never run alone.
# Numbering: the plan (a file outside this repository, section 5-3) calls the two measurements "step 5" and "step 6"; the file
# docs/research/extrapolation-test-3.md calls them step 6 (every route cold once) and step 7 (the marked routes, three more cold tries).
# Here the scripts are named by what they do: measure_fixtures.sh, measure_bookstack.sh (= file step 6) and repeat_bookstack.sh (= file step 7).
#
# Environment the launcher gives:
#   T5A_START_OK=1     the operator's word that the lab (B and the mark) has ended; without it every script stops at the gate (no Docker touched)
#   NOTIFY_TO=<id>    who gets the message at the end of a run (<notify>)
#   DRYRUN=1          the runtime tools are replaced by stubs that only record their arguments (poc/lab/bin/measure-extrapolation reads
#                     PEAK_RUNTIME_BIN); outputs go to a different directory; no Docker container is started, no notification is sent
#   TOOL_COMMIT       the commit of the tool copy (default: 7a4cf32, a descendant of the prediction commit f52f692)
set -u
B=$HOME/projects/peak-sim-trials
A3=$B/_out/run-286_t5a/run
NOTIFY_DIR=<notify-dir>
REPO=$HOME/projects/peak-sim
TOOL_COMMIT=${TOOL_COMMIT:-7a4cf32d24e64e23acbfa842fc35ab81eade4d10}
R=$B/_scratch/run-286-t5a/rep-${TOOL_COMMIT:0:7}
PRED=$R/poc/lab/predictions
FX_EST=$PRED/extrapolation-4-fixtures-estimate.json;  FX_MARK=$PRED/extrapolation-4-fixtures-marked.json
BS_EST=$PRED/extrapolation-4-bookstack-estimate.json; BS_MARK=$PRED/extrapolation-4-bookstack-marked.json
FSTATIC=$B/_out/extrapolation/fixtures/analysis.json
BSTATIC=$B/_out/_stageA3/bookstack/analysis.json
ONLY_FILE=$A3/T3_only.txt                      # the 31 items of the earlier runs (byte-identical to _out/run-271/measure/T3_only.txt)
TASKS_BS=$A3/tasks_merged_bookstack.json       # made by make_tasks.py from the capture's task lists
MARKED_TSV=$A3/marked_routes.tsv               # made by make_tasks.py from the committed marked-routes files
PROJ_FX=peak-t5a-fx
PROJ_BS=peak-t5a-bs
VAR_PREFIX=peak-t5a-var
VAR_LABEL=a4var240k
PROJ_VAR=$VAR_PREFIX-$VAR_LABEL-db1            # variance-run names its project <prefix>-<label>-db<k>
OWN_RE='^(peak-t5a-)'
MIN_AVAIL_GIB=200                              # a floor chosen for this plan, not a measured need; the free space is recorded either way

if [ "${DRYRUN:-0}" = 1 ]; then
  OUTROOT=$A3/_dryrun/out; ORDER=$A3/_dryrun/order_record.txt; export PEAK_RUNTIME_BIN=$A3/stub; mkdir -p "$A3/_dryrun"
else
  OUTROOT=${T5A_OUTROOT:-$B/_out/run-286_t5a/measure}; ORDER=$OUTROOT/order_record.txt   # T5A_OUTROOT: T6A2 override (default unchanged)
fi

notify() { # message
  [ "${DRYRUN:-0}" = 1 ] && { echo "[dryrun] would notify ${NOTIFY_TO:-<unset>}: $1"; return 0; }
  [ -n "${NOTIFY_TO:-}" ] && (cd "$NOTIFY_DIR" && <notify> "$NOTIFY_TO" "$1")
}
gate_fail() { # step reason
  local w=$A3/gate_failed_$1.txt
  echo "gate_failed=$2 at=$(date -Is)" > "$w"; echo "GATE FAILED ($1): $2" >&2
  notify "A3 $1: gate failed, not started: $2"; exit 2
}

# The gates, in the order of cheapness. Nothing here starts a container; docker is only read (ps, image inspect).
gates() { # step outdir-that-must-not-exist...
  local step=$1; shift
  [ "${DRYRUN:-0}" = 1 ] || [ "${T5A_START_OK:-}" = 1 ] || gate_fail "$step" "T5A_START_OK is not 1 (the operator has not said that B and the mark have ended)"
  [ -n "${NOTIFY_TO:-}" ] || [ "${DRYRUN:-0}" = 1 ] || gate_fail "$step" "NOTIFY_TO is not set"
  [ -d "$R/.git" ] || gate_fail "$step" "the tool copy $R does not exist"
  [ "$(git -C "$R" rev-parse HEAD)" = "$TOOL_COMMIT" ] || gate_fail "$step" "the tool copy is not at $TOOL_COMMIT"
  [ -z "$(git -C "$R" status --porcelain)" ] || gate_fail "$step" "the tool copy has a difference from its commit"
  grep -q 'TIMEOUT = 7200' "$R/poc/runtime/src/Measure/ProcessDatabaseBuilder.php" || gate_fail "$step" "the copy does not carry the 7,200 s build limit (D200)"
  [ -d "$R/vendor" ] && [ -d "$R/fixtures/laravel-app/vendor" ] || gate_fail "$step" "vendor directories missing in the copy"
  local f
  for f in "$FX_EST" "$FX_MARK" "$BS_EST" "$BS_MARK"; do
    PEAK_REPO_ROOT=$R "$R/poc/lab/bin/prediction-gate" "$f" >/dev/null 2>&1 || gate_fail "$step" "prediction gate closed for $(basename "$f")"
  done
  [ -f "$ONLY_FILE" ] && [ -f "$TASKS_BS" ] && [ -f "$MARKED_TSV" ] || gate_fail "$step" "T3_only.txt, tasks_merged_bookstack.json or marked_routes.tsv missing (run make_tasks.py)"
  if [ "${DRYRUN:-0}" != 1 ]; then
    local own other
    other=$(docker ps --format '{{.Names}}' | grep -E '^peak-meas|^peak-measure' | grep -Ev "$OWN_RE" || true)
    [ -z "$other" ] || gate_fail "$step" "other measurement containers running: $(echo $other | tr '\n' ' ')"
    # the lab is not to be loaded at the same time (D199 (6)): no ladder, mark probe or chain script of the lab may be running
    pgrep -af 'measure-ladder|request_probe\.py|chain-B|chain-mark|k6 run' >/dev/null 2>&1 && gate_fail "$step" "a lab measurement (ladder, mark probe or chain script) is running"
    local d
    for d in "$@"; do [ ! -e "$d" ] || gate_fail "$step" "output already exists: $d"; done
    local avail; avail=$(df --output=avail -BG "$HOME" | tail -n1 | tr -dc '0-9')
    [ "${avail:-0}" -ge "$MIN_AVAIL_GIB" ] || gate_fail "$step" "free space ${avail:-?} GiB is below $MIN_AVAIL_GIB GiB"
    docker image inspect peak-measure-php:8.3 peak-measure-php-bookstack:8.3 >/dev/null 2>&1 || gate_fail "$step" "a measurement image is missing"
  fi
  if [ "${DRYRUN:-0}" != 1 ]; then
    local am; am=$(free -m | awk 'NR==2{print $7}')
    [ "${am:-0}" -ge 3072 ] || gate_fail "$step" "available memory ${am:-?} MB is below 3072 MB (the operator's floor)"
  fi
  mkdir -p "$OUTROOT"
  { echo "# df -h ~, free -m and docker ps at $(date -Is) (step $step)"; df -h "$HOME"; free -m; [ "${DRYRUN:-0}" = 1 ] || docker ps --format "{{.Names}} {{.Status}}"; } >> "$OUTROOT/host_before_$step.txt"
}

# CREATE_OPTIONS of every base table, read in the database container once the tables exist (before the first request),
# as in the 200,000-row runs. The root password stays inside the container.
read_opts() { # project outfile
  local c; c=$(docker ps -q --filter "label=com.docker.compose.project=$1" --filter "label=com.docker.compose.service=measure-db" | head -n1)
  { echo "# at $(date -Is) project=$1"
    [ -n "$c" ] && docker exec "$c" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B -e "SELECT table_schema, table_name, IFNULL(create_options,\"\") FROM information_schema.TABLES WHERE table_type=\"BASE TABLE\" AND table_schema LIKE \"peak_sim_measure_%\" ORDER BY table_name"' || echo "no db container"; } > "$2" 2>&1
}
watch_opts() { # project outfile donefile
  [ "${DRYRUN:-0}" = 1 ] && return 0
  local t=0 n c
  while [ ! -f "$3" ] && [ $t -lt 40000 ]; do
    c=$(docker ps -q --filter "label=com.docker.compose.project=$1" --filter "label=com.docker.compose.service=measure-db" | head -n1)
    if [ -n "$c" ]; then
      n=$(docker exec "$c" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_type=\"BASE TABLE\" AND table_schema LIKE \"peak_sim_measure_%\""' 2>/dev/null || echo 0)
      if [ "${n:-0}" -ge 5 ] 2>/dev/null; then sleep 20; read_opts "$1" "$2"; return 0; fi
    fi
    sleep 5; t=$((t+5))
  done
  echo "no tables seen before the run ended" > "$2"
}

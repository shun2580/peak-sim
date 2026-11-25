# Shared setup for the lab's host-side scripts (sourced, not run).
#
# Environment (all optional unless said):
#   PEAK_APP_ROOT          the BookStack checkout, with vendor/ installed. Default: ../peak-sim-trials/bookstack next to
#                          this repository. Mounted read-only.
#   PEAK_LAB_OUT_DIR       records (default ~/.cache/peak-lab/out; keep it outside the repository)
#   PEAK_LAB_LOAD_DIR      the k6 scripts (default poc/lab/load)
#   PEAK_LAB_STATE_DIR     where the throwaway passwords, the run id and the app key live (default ~/.cache/peak-lab).
#                          They are made once and kept, because the database's data volume outlives `down`.
#   PEAK_LAB_SCALE         the scale of the database the app reads (default 10000)
#   PEAK_LAB_APP_CPUS      the app side's CPU quota (default 6; the Bagisto stack sets 10): the compose file's `cpus` of the app and the web server, and
#                          the --app-cpus the measuring scripts give summarize-run. PEAK_LAB_CPUSET_APP (default 2-7) names the cores.
#   PEAK_LAB_ACT_AS        the login of the entry script (default the first BookStack user)
#   PEAK_LAB_LOGINS        the other logins a request may choose by name: name=Model\Class:key:guard, separated by `;` (default none: the
#                          default login and the guest alone). Read by the entry (X-Lab-Login: <name>) and by bin/targets.
#   PEAK_LAB_COMPOSE_FILES colon-separated compose files (default: lab.compose.yaml). TEST ONLY: the leaky overrides.
#   PEAK_LAB_PREFIX        the name every container, the data volume and the network of one stack start with (default peak-lab). A second
#                          stack (another app) sets its own project name (PEAK_LAB_PROJECT), prefix, state and out directories, and shares
#                          nothing with this one; poc/lab/bin/with-bagisto sets them all for the Bagisto stack.
#   PEAK_LAB_ALLOWED_WRITES_FILE  the tables the lab may write, one name per line (poc/lab/README.md, "The tables the lab may write"), as a path
#                          inside the repository, relative to it. Unset: `views` alone, as it has always been. Read by bin/check-writes and
#                          bin/check-tables on the host and, as /peak-sim/<that path>, by the entry inside php-fpm.
#   PEAK_LAB_ROUTE_LIST    the list of the eight measured routes, a path inside the repository, relative to it (default
#                          poc/lab/routes/route-list.json, BookStack's). Read by bin/check-writes, bin/measure-ladder and bin/targets.
#   PEAK_LAB_PROFILE       the app profile the builder and bin/targets use (default bookstack; the Bagisto stack sets bagisto)
#   PEAK_LAB_TABLES_FILE   the N tables (one name per line) for bin/check-tables when --tables is not given. Unset: entities,images,references.
#   PEAK_LAB_SEEN_SCALES   the scales above 30,000 that count as seen for bin/check-tables (default: 70000 100000, which BookStack has seen).
set -uo pipefail

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LAB_DIR="$(cd "$_here/.." && pwd)"
: "${PEAK_REPO_ROOT:=$(cd "$LAB_DIR/../.." && pwd)}"
: "${PEAK_LAB_PREFIX:=peak-lab}"
: "${PEAK_LAB_PROJECT:=peak-lab}"
: "${PEAK_LAB_STATE_DIR:=${HOME}/.cache/peak-lab}"
: "${PEAK_LAB_OUT_DIR:=${HOME}/.cache/peak-lab/out}"
: "${PEAK_LAB_LOAD_DIR:=$LAB_DIR/load}"
: "${PEAK_LAB_SCALE:=10000}"
# The app side's CPU quota: the denominator of the app CPU utilization every measuring script records (--app-cpus). It is the number the compose file
# gives the app container (lab.compose.yaml: PEAK_LAB_APP_CPUS, default 6); the Bagisto lab sets 10 in bin/with-bagisto (spec 7.4.7).
: "${PEAK_LAB_APP_CPUS:=6}"
case "$PEAK_LAB_APP_CPUS" in ''|*[!0-9]*|0) echo "lab: PEAK_LAB_APP_CPUS must be a whole number of CPUs, 1 or more (got '$PEAK_LAB_APP_CPUS')" >&2; exit 2 ;; esac
: "${PEAK_LAB_UID:=$(id -u)}"
: "${PEAK_LAB_GID:=$(id -g)}"
if [ -z "${PEAK_APP_ROOT:-}" ] && [ -d "$PEAK_REPO_ROOT/../peak-sim-trials/bookstack" ]; then
  PEAK_APP_ROOT="$(cd "$PEAK_REPO_ROOT/../peak-sim-trials/bookstack" && pwd)"
fi

mkdir -p "$PEAK_LAB_STATE_DIR" "$PEAK_LAB_OUT_DIR/web"
chmod 700 "$PEAK_LAB_STATE_DIR"
_state="$PEAK_LAB_STATE_DIR/state.env"
if [ ! -f "$_state" ]; then
  _rand() { head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n'; }
  (
    umask 077
    {
      echo "PEAK_MEASURE_DB_ROOT_PASSWORD=$(_rand)"
      echo "PEAK_MEASURE_DB_PASSWORD=$(_rand)"
      echo "PEAK_MEASURE_RUN_ID=$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
      echo "PEAK_MEASURE_APP_KEY=base64:$(head -c 32 /dev/urandom | base64 -w0)"
    } >"$_state"
  )
fi
set -a
# shellcheck disable=SC1090
. "$_state"
set +a

# One name per container, from the prefix. The compose file takes the same prefix, so the two cannot disagree. The names the
# older scripts spelled out (peak-lab-db and so on) are what the default prefix gives.
: "${PEAK_LAB_DB_CONTAINER:=${PEAK_LAB_PREFIX}-db}"
: "${PEAK_LAB_APP_CONTAINER:=${PEAK_LAB_PREFIX}-app}"
: "${PEAK_LAB_WEB_CONTAINER:=${PEAK_LAB_PREFIX}-web}"
: "${PEAK_LAB_K6_CONTAINER:=${PEAK_LAB_PREFIX}-k6}"
: "${PEAK_LAB_TOOLS_CONTAINER:=${PEAK_LAB_PREFIX}-tools}"
export PEAK_REPO_ROOT PEAK_APP_ROOT PEAK_LAB_OUT_DIR PEAK_LAB_LOAD_DIR PEAK_LAB_SCALE PEAK_LAB_UID PEAK_LAB_GID PEAK_LAB_PREFIX PEAK_LAB_APP_CPUS
export PEAK_LAB_DB_CONTAINER PEAK_LAB_APP_CONTAINER PEAK_LAB_WEB_CONTAINER PEAK_LAB_K6_CONTAINER PEAK_LAB_TOOLS_CONTAINER
# A path that names a file inside the repository, relative to it (it is also read inside the containers, where the repository is /peak-sim).
for _v in PEAK_LAB_ALLOWED_WRITES_FILE PEAK_LAB_ROUTE_LIST; do
  case "${!_v:-}" in
    /*|*..*) echo "lab: $_v must be a path inside the repository, relative to it, with no .. in it" >&2; exit 2 ;;
  esac
done
[ -z "${PEAK_LAB_ROUTE_LIST:-}" ] || export PEAK_LAB_ROUTE_LIST   # unset stays unset: the scripts then use BookStack's list, where they have always looked for it
if [ -n "${PEAK_LAB_ALLOWED_WRITES_FILE:-}" ]; then
  PEAK_LAB_ALLOWED_WRITES_IN_APP="/peak-sim/$PEAK_LAB_ALLOWED_WRITES_FILE"
  export PEAK_LAB_ALLOWED_WRITES_FILE PEAK_LAB_ALLOWED_WRITES_IN_APP
fi
[ -n "${PEAK_LAB_ACT_AS:-}" ] && export PEAK_LAB_ACT_AS
[ -n "${PEAK_LAB_LOGINS:-}" ] && export PEAK_LAB_LOGINS

LAB_CONTAINERS=("$PEAK_LAB_DB_CONTAINER" "$PEAK_LAB_APP_CONTAINER" "$PEAK_LAB_WEB_CONTAINER" "$PEAK_LAB_K6_CONTAINER" "$PEAK_LAB_TOOLS_CONTAINER")

compose() {
  local files=() f IFS=:
  for f in ${PEAK_LAB_COMPOSE_FILES:-$LAB_DIR/compose/lab.compose.yaml}; do files+=(-f "$f"); done
  unset IFS
  docker compose -p "$PEAK_LAB_PROJECT" "${files[@]}" "$@"
}

require_app() {
  if [ -z "${PEAK_APP_ROOT:-}" ] || [ ! -f "$PEAK_APP_ROOT/vendor/autoload.php" ] || [ ! -f "$PEAK_APP_ROOT/bootstrap/app.php" ]; then
    echo "lab: PEAK_APP_ROOT must be a BookStack checkout with vendor/ installed (it is mounted read-only; nothing is installed into it)" >&2
    exit 2
  fi
}

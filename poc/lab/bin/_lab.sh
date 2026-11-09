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
#   PEAK_LAB_ACT_AS        the login of the entry script (default the first BookStack user)
#   PEAK_LAB_COMPOSE_FILES colon-separated compose files (default: lab.compose.yaml). TEST ONLY: the leaky overrides.
set -uo pipefail

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LAB_DIR="$(cd "$_here/.." && pwd)"
: "${PEAK_REPO_ROOT:=$(cd "$LAB_DIR/../.." && pwd)}"
: "${PEAK_LAB_PROJECT:=peak-lab}"
: "${PEAK_LAB_STATE_DIR:=${HOME}/.cache/peak-lab}"
: "${PEAK_LAB_OUT_DIR:=${HOME}/.cache/peak-lab/out}"
: "${PEAK_LAB_LOAD_DIR:=$LAB_DIR/load}"
: "${PEAK_LAB_SCALE:=10000}"
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

export PEAK_REPO_ROOT PEAK_APP_ROOT PEAK_LAB_OUT_DIR PEAK_LAB_LOAD_DIR PEAK_LAB_SCALE PEAK_LAB_UID PEAK_LAB_GID
[ -n "${PEAK_LAB_ACT_AS:-}" ] && export PEAK_LAB_ACT_AS

LAB_CONTAINERS=(peak-lab-db peak-lab-app peak-lab-web peak-lab-k6 peak-lab-tools)

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

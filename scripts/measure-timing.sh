#!/usr/bin/env bash
# Measure how long peak-sim takes to analyse the fixture app and a generated synthetic app.
#
#   scripts/measure-timing.sh <compose-project> [--runs=5] [--routes=300] [--php=8.3]
#   scripts/measure-timing.sh --self-test
#
# Each measurement is `bin/peak-sim analyze … --stats`; the tool prints "elapsed" on standard error, and that is the
# number used. The fixture app is read through --export-dir (no artisan, no database). The synthetic app is made by
# scripts/synthetic-app/generate.php in a throw-away directory under .work/ (git-ignored) and removed afterwards;
# only a directory this script created itself is removed, and only after its real path is checked.
# No threshold is applied: the numbers are reported, nothing more.
#
# PHP runs only in Docker. <compose-project> is required (it keeps this run's containers and network apart from
# anyone else's) and only that project is torn down at the end.
# Output: a table for people, then a Markdown block to paste into docs/research/phase1-timing.md.
# Exit status: 0 done; 2 usage; 3 a run failed.
set -euo pipefail

usage() {
    cat >&2 <<'EOF'
usage: scripts/measure-timing.sh <compose-project> [--runs=5] [--routes=300] [--php=8.3]
       scripts/measure-timing.sh --self-test
EOF
}

ROOT=$(cd "$(dirname "$0")/.." && pwd)

# stats_of <seconds…> → "median min max" (the median of an even count is the mean of the two middle values).
stats_of() {
    printf '%s\n' "$@" | sort -g | awk '
        { v[NR] = $1 }
        END {
            if (NR == 0) { exit 1 }
            median = (NR % 2 == 1) ? v[(NR + 1) / 2] : (v[NR / 2] + v[NR / 2 + 1]) / 2
            printf "%.3f %.3f %.3f\n", median, v[1], v[NR]
        }'
}

# stat_value <file> <label> → the number after "<label>:" on the stats line (the first one).
stat_value() {
    { grep -F -m 1 -e "$2:" "$1" || true; } | sed -nE 's/^[^:]*: *([0-9.]+).*/\1/p'
}

self_test() {
    local fail=0 out
    out=$(stats_of 0.5 0.1 0.3)
    [ "$out" = "0.300 0.100 0.500" ] || { echo "self-test: odd count gave '$out'" >&2; fail=1; }
    out=$(stats_of 0.4 0.1 0.3 0.2)
    [ "$out" = "0.250 0.100 0.400" ] || { echo "self-test: even count gave '$out'" >&2; fail=1; }
    out=$(stats_of 1.25)
    [ "$out" = "1.250 1.250 1.250" ] || { echo "self-test: single value gave '$out'" >&2; fail=1; }

    local sample
    sample=$(mktemp)
    printf 'peak-sim stats\n  elapsed: 0.123 s\n  routes analysed: 24\n  files under app/: 40\n  summaries: 17 computed, 9 reused\n  deepest call depth: 6\n' >"$sample"
    [ "$(stat_value "$sample" elapsed)" = "0.123" ] || { echo "self-test: elapsed not read" >&2; fail=1; }
    [ "$(stat_value "$sample" 'routes analysed')" = "24" ] || { echo "self-test: routes not read" >&2; fail=1; }
    [ "$(stat_value "$sample" 'files under app/')" = "40" ] || { echo "self-test: files not read" >&2; fail=1; }
    [ "$(stat_value "$sample" 'deepest call depth')" = "6" ] || { echo "self-test: depth not read" >&2; fail=1; }
    rm -f -- "$sample"
    if [ "$fail" -eq 0 ]; then
        echo "self-test: ok (median/min/max for odd, even and single counts; stats lines are read)"
    fi
    return "$fail"
}

# --- arguments -----------------------------------------------------------------------------------------------

PROJECT=""
RUNS=5
ROUTES=300
PHP=8.3

if [ "$#" -eq 0 ]; then
    usage
    echo "measure-timing: the compose project name is required" >&2
    exit 2
fi
for arg in "$@"; do
    case $arg in
        --self-test) self_test; exit $? ;;
        --runs=*) RUNS=${arg#--runs=} ;;
        --routes=*) ROUTES=${arg#--routes=} ;;
        --php=*) PHP=${arg#--php=} ;;
        -h | --help) usage; exit 0 ;;
        -*) usage; echo "measure-timing: unknown option: $arg" >&2; exit 2 ;;
        *)
            if [ -n "$PROJECT" ]; then
                usage; echo "measure-timing: only one compose project name can be given" >&2; exit 2
            fi
            PROJECT=$arg
            ;;
    esac
done
if [ -z "$PROJECT" ]; then
    usage
    echo "measure-timing: the compose project name is required" >&2
    exit 2
fi
printf '%s' "$PROJECT" | grep -Eq '^[a-z0-9][a-z0-9_-]*$' || { echo "measure-timing: the compose project name must be lower-case letters, digits, '-' or '_'" >&2; exit 2; }
printf '%s' "$RUNS" | grep -Eq '^[1-9][0-9]*$' || { echo "measure-timing: --runs must be a positive integer" >&2; exit 2; }
printf '%s' "$ROUTES" | grep -Eq '^[1-9][0-9]*$' || { echo "measure-timing: --routes must be a positive integer" >&2; exit 2; }
printf '%s' "$PHP" | grep -Eq '^[0-9]+\.[0-9]+$' || { echo "measure-timing: --php must look like 8.3" >&2; exit 2; }
command -v docker >/dev/null 2>&1 || { echo "measure-timing: docker is required (PHP runs only in Docker)" >&2; exit 2; }

export HOST_UID=${HOST_UID:-$(id -u)}
export HOST_GID=${HOST_GID:-$(id -g)}
export PHP_VERSION=$PHP

dc() {
    docker compose -f "$ROOT/docker/compose.yaml" -p "$PROJECT" "$@"
}
php_in_docker() {
    dc run -T --rm --no-deps php-dev "$@"
}

# --- clean-up: this script's own files and its own throw-away directory only --------------------------------------

TMP_FILES=()
SYN_DIR=""
WORK_PREFIX="synthetic-timing."

remove_synthetic_dir() {
    [ -n "$SYN_DIR" ] || return 0
    local real work_real
    real=$(realpath -- "$SYN_DIR" 2>/dev/null || true)
    work_real=$(realpath -- "$ROOT/.work" 2>/dev/null || true)
    if [ -z "$real" ] || [ -z "$work_real" ] || [ -L "$SYN_DIR" ]; then
        echo "measure-timing: not removing the synthetic app: its path cannot be confirmed" >&2
        return 0
    fi
    case $real in
        "$work_real/$WORK_PREFIX"?*) rm -rf -- "$real" ;;
        *) echo "measure-timing: not removing the synthetic app: it is not under .work/$WORK_PREFIX*" >&2 ;;
    esac
    SYN_DIR=""
}

# shellcheck disable=SC2329 # called through trap
cleanup() {
    local f
    for f in "${TMP_FILES[@]+"${TMP_FILES[@]}"}"; do
        rm -f -- "$f"
    done
    remove_synthetic_dir
    dc down -v >/dev/null 2>&1 || true
}
trap cleanup EXIT

# --- setup -----------------------------------------------------------------------------------------------------

dc build --quiet php-dev >&2
if [ ! -f "$ROOT/vendor/autoload.php" ]; then
    php_in_docker composer install --no-interaction --no-progress >&2
fi

# measure <label> <app-root> <export-dir>: RUNS runs. Sets M_ELAPSED (list), M_ROUTES, M_FILES, M_COMPUTED, M_REUSED, M_DEPTH.
measure() {
    local label=$1 app=$2 export=$3 i err rc
    M_ELAPSED=()
    M_ROUTES="" M_FILES="" M_COMPUTED="" M_REUSED="" M_DEPTH=""
    for i in $(seq 1 "$RUNS"); do
        err=$(mktemp)
        TMP_FILES+=("$err")
        rc=0
        php_in_docker php bin/peak-sim analyze "$app" "--export-dir=$export" --stats >/dev/null 2>"$err" || rc=$?
        if [ "$rc" -ne 0 ]; then
            echo "measure-timing: $label run $i exited $rc; standard error follows" >&2
            cat "$err" >&2
            exit 3
        fi
        local seconds
        seconds=$(stat_value "$err" elapsed)
        if [ -z "$seconds" ]; then
            echo "measure-timing: $label run $i printed no 'elapsed' line on standard error (is --stats supported?)" >&2
            cat "$err" >&2
            exit 3
        fi
        M_ELAPSED+=("$seconds")
        if [ "$i" -eq 1 ]; then
            M_ROUTES=$(stat_value "$err" 'routes analysed')
            M_FILES=$(stat_value "$err" 'files under app/')
            M_DEPTH=$(stat_value "$err" 'deepest call depth')
            M_COMPUTED=$(sed -nE 's/^ *summaries: ([0-9]+) computed, ([0-9]+) reused.*/\1/p' "$err" | head -n 1)
            M_REUSED=$(sed -nE 's/^ *summaries: ([0-9]+) computed, ([0-9]+) reused.*/\2/p' "$err" | head -n 1)
        fi
        echo "measure-timing: $label run $i/$RUNS: $seconds s" >&2
    done
}

# reuse_rate <computed> <reused> → "NN.N%" or "n/a"
reuse_rate() {
    local c=${1:-} r=${2:-}
    if [ -z "$c" ] || [ -z "$r" ] || [ $((c + r)) -eq 0 ]; then
        echo "n/a"
        return 0
    fi
    awk -v c="$c" -v r="$r" 'BEGIN { printf "%.1f%%", 100 * r / (c + r) }'
}

measure "fixture app" fixtures/laravel-app fixtures/artisan-export
FIX_ELAPSED=("${M_ELAPSED[@]}")
FIX_ROUTES=$M_ROUTES FIX_FILES=$M_FILES FIX_COMPUTED=$M_COMPUTED FIX_REUSED=$M_REUSED FIX_DEPTH=$M_DEPTH
read -r FIX_MED FIX_MIN FIX_MAX < <(stats_of "${FIX_ELAPSED[@]}")

mkdir -p "$ROOT/.work"
SYN_DIR=$(mktemp -d "$ROOT/.work/${WORK_PREFIX}XXXXXX")
SYN_REL=".work/$(basename "$SYN_DIR")"
php_in_docker php scripts/synthetic-app/generate.php "$SYN_REL" "$ROUTES" >&2
measure "synthetic app ($ROUTES routes)" "$SYN_REL" "$SYN_REL"
SYN_ELAPSED=("${M_ELAPSED[@]}")
SYN_ROUTES=$M_ROUTES SYN_FILES=$M_FILES SYN_COMPUTED=$M_COMPUTED SYN_REUSED=$M_REUSED SYN_DEPTH=$M_DEPTH
read -r SYN_MED SYN_MIN SYN_MAX < <(stats_of "${SYN_ELAPSED[@]}")

HEAD_SHORT=$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)
FIX_RATE=$(reuse_rate "$FIX_COMPUTED" "$FIX_REUSED")
SYN_RATE=$(reuse_rate "$SYN_COMPUTED" "$SYN_REUSED")
join_by_comma() { local IFS=,; echo "$*"; }

# --- report ------------------------------------------------------------------------------------------------------

echo
echo "peak-sim analysis time (PHP $PHP, $RUNS runs each, tool commit $HEAD_SHORT, no threshold applied)"
printf '%-30s %8s %8s %8s %8s %8s %12s %9s\n' "app" "routes" "median s" "min s" "max s" "files" "summaries" "reused"
printf '%-30s %8s %8s %8s %8s %8s %12s %9s\n' "fixture app" "$FIX_ROUTES" "$FIX_MED" "$FIX_MIN" "$FIX_MAX" "$FIX_FILES" "${FIX_COMPUTED}+${FIX_REUSED}" "$FIX_RATE"
printf '%-30s %8s %8s %8s %8s %8s %12s %9s\n' "synthetic ($ROUTES routes)" "$SYN_ROUTES" "$SYN_MED" "$SYN_MIN" "$SYN_MAX" "$SYN_FILES" "${SYN_COMPUTED}+${SYN_REUSED}" "$SYN_RATE"
echo "individual runs, fixture app (s):   $(join_by_comma "${FIX_ELAPSED[@]}")"
echo "individual runs, synthetic app (s): $(join_by_comma "${SYN_ELAPSED[@]}")"
echo "summaries = computed+reused; reused = reused / (computed+reused); deepest call depth: fixture ${FIX_DEPTH:-n/a}, synthetic ${SYN_DEPTH:-n/a}"

echo
echo "--- markdown (paste into docs/research/phase1-timing.md) ---"
cat <<EOF
## Analysis time

Measured with \`bin/peak-sim analyze <app> --export-dir=<dir> --stats\` (the "elapsed" line on standard error), $RUNS runs each, PHP $PHP in Docker, tool commit \`$HEAD_SHORT\`. No target value is set yet; these are measurements only.

| App | Routes | Median (s) | Min (s) | Max (s) | Files under app/ | Summaries computed | Summaries reused | Reuse rate | Deepest call depth |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Fixture app | $FIX_ROUTES | $FIX_MED | $FIX_MIN | $FIX_MAX | $FIX_FILES | $FIX_COMPUTED | $FIX_REUSED | $FIX_RATE | ${FIX_DEPTH:-n/a} |
| Synthetic app ($ROUTES routes) | $SYN_ROUTES | $SYN_MED | $SYN_MIN | $SYN_MAX | $SYN_FILES | $SYN_COMPUTED | $SYN_REUSED | $SYN_RATE | ${SYN_DEPTH:-n/a} |

Individual runs, fixture app (s): $(join_by_comma "${FIX_ELAPSED[@]}")
Individual runs, synthetic app (s): $(join_by_comma "${SYN_ELAPSED[@]}")

The synthetic app is made by \`scripts/synthetic-app/generate.php\`, which copies the fixture app's controllers and what they use into $ROUTES routes under separate namespaces. Its counts come from the first run; the counts do not change between runs.
EOF

#!/usr/bin/env bash
# Run the tool's whole test suite on PHP 8.2 and on PHP 8.3, and save each run's output as a log.
#
#   scripts/run-tests-logged.sh <compose-project> [--out-dir=docs/research] [--suites=Unit,Acceptance] [--versions=8.2,8.3]
#   scripts/run-tests-logged.sh --self-test
#
# PHP and Composer run only in Docker (see docker/README.md). <compose-project> is required and has no default: it
# names this run's own containers, network and volumes, so that parallel runs never share a database. Only that
# project is torn down at the end (docker compose down -v).
#
# Output: <out-dir>/phase1-test-php82.log and <out-dir>/phase1-test-php83.log (one per version).
# Before a log is kept, environment details are removed (absolute paths, the user name, the host name, container
# ids) and the result is searched again: a log with anything left is not written, and the script exits 5.
# Exit status: PHPUnit's own (non-zero when a run is red); 2 usage; 5 environment details left in a log;
# 6 no PHPUnit summary found. The last line is one summary of tests, assertions and skipped for each version.
set -euo pipefail

usage() {
    cat >&2 <<'EOF'
usage: scripts/run-tests-logged.sh <compose-project> [--out-dir=DIR] [--suites=A,B] [--versions=8.2,8.3]
       scripts/run-tests-logged.sh --self-test
EOF
}

ROOT=$(cd "$(dirname "$0")/.." && pwd)

# --- environment details: removal and the search that proves nothing is left -------------------------------------

# Escape a literal for use in a sed -E pattern with '#' as the delimiter.
sed_escape() {
    printf '%s' "$1" | sed -e 's/[][\.*^$/|+?(){}#-]/\\&/g'
}

# Remove environment details from stdin, write the result to stdout. Paths are dropped or generalised, not masked
# with look-alikes: "/app/src/X.php" becomes "src/X.php", "/home/<name>/" disappears, "/tmp/" becomes "<tmp>/".
sanitize() {
    local user host
    user=$(id -un 2>/dev/null || true)
    host=$(hostname 2>/dev/null || true)
    local -a rules=(
        -e 's#(^|[^A-Za-z0-9_./<-])/app/#\1#g'
        -e 's#(^|[^A-Za-z0-9_./<-])/home/[^/[:space:]"'"'"']+/#\1#g'
        -e 's#(^|[^A-Za-z0-9_./<-])/Users/[^/[:space:]"'"'"']+/#\1#g'
        -e 's#(^|[^A-Za-z0-9_./<-])/root/#\1#g'
        -e 's#(^|[^A-Za-z0-9_./<-])/tmp/#\1<tmp>/#g'
        -e 's#\b[0-9a-f]{64}\b#<id>#g'
        -e 's#\b[0-9a-f]{12}\b#<id>#g'
    )
    if [ "${#user}" -ge 3 ]; then
        rules+=(-e "s#\\b$(sed_escape "$user")\\b#<user>#g")
    fi
    if [ "${#host}" -ge 3 ]; then
        rules+=(-e "s#\\b$(sed_escape "$host")\\b#<host>#g")
    fi
    sed -E "${rules[@]}"
}

# Print how many lines of a file still look like environment details. Values are never printed.
leak_count() {
    local file=$1 user host n=0 c
    user=$(id -un 2>/dev/null || true)
    host=$(hostname 2>/dev/null || true)
    c=$(grep -Ec '(^|[^A-Za-z0-9_.<-])/(app|home|Users|root|tmp|var|usr|mnt|opt|etc|srv|proc|run|media)/' "$file" || true)
    n=$((n + c))
    c=$(grep -Ec '\b([0-9a-f]{12}|[0-9a-f]{64})\b' "$file" || true)
    n=$((n + c))
    if [ "${#user}" -ge 3 ]; then
        c=$(grep -Fwc -e "$user" "$file" || true)
        n=$((n + c))
    fi
    if [ "${#host}" -ge 3 ]; then
        c=$(grep -Fwc -e "$host" "$file" || true)
        n=$((n + c))
    fi
    echo "$n"
}

# --- self test (no Docker): the removal works, and the search notices what it does not cover ---------------------

self_test() {
    local sample clean after before user host fail=0
    sample=$(mktemp) clean=$(mktemp)
    user=$(id -un 2>/dev/null || echo someone)
    host=$(hostname 2>/dev/null || echo somehost)
    cat >"$sample" <<EOF
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.
Configuration: /app/phpunit.xml
at /app/src/Cli/Application.php:42
at /home/$user/projects/peak-sim/src/X.php:7
at /Users/jane/work/Y.php:9
workspace /tmp/peak-sim-abc123/app/Z.php
user $user ran on $host
container 0123456789ab and 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
data set "/ok/eager" and "/hidden/appends" and GET /ok/bulk stay as they are
EOF
    before=$(leak_count "$sample")
    sanitize <"$sample" >"$clean"
    after=$(leak_count "$clean")
    [ "$before" -gt 0 ] || { echo "self-test: the search found nothing in a dirty sample" >&2; fail=1; }
    [ "$after" -eq 0 ] || { echo "self-test: $after line(s) still look like environment details after removal" >&2; fail=1; }
    grep -q '^Configuration: phpunit.xml$' "$clean" || { echo "self-test: /app/ was not dropped" >&2; fail=1; }
    grep -q '"/hidden/appends"' "$clean" || { echo "self-test: a route that contains \"app\" was damaged" >&2; fail=1; }
    grep -q 'GET /ok/bulk' "$clean" || { echo "self-test: a route was damaged" >&2; fail=1; }

    # A path the removal does not know must be caught by the search (so the script refuses to keep the log).
    printf 'at /opt/somewhere/file.php:1\n' >"$sample"
    sanitize <"$sample" >"$clean"
    [ "$(leak_count "$clean")" -gt 0 ] || { echo "self-test: an unknown absolute path was not detected" >&2; fail=1; }

    rm -f -- "$sample" "$clean"
    if [ "$fail" -eq 0 ]; then
        echo "self-test: ok (removal leaves 0 lines; an unknown path is still detected; routes untouched; before=$before)"
    fi
    return "$fail"
}

# --- arguments -----------------------------------------------------------------------------------------------

PROJECT=""
OUT_DIR="$ROOT/docs/research"
SUITES="Unit,Acceptance"
VERSIONS="8.2,8.3"

if [ "$#" -eq 0 ]; then
    usage
    echo "run-tests-logged: the compose project name is required" >&2
    exit 2
fi
for arg in "$@"; do
    case $arg in
        --self-test) self_test; exit $? ;;
        --out-dir=*) OUT_DIR=${arg#--out-dir=} ;;
        --suites=*) SUITES=${arg#--suites=} ;;
        --versions=*) VERSIONS=${arg#--versions=} ;;
        -h | --help) usage; exit 0 ;;
        -*) usage; echo "run-tests-logged: unknown option: $arg" >&2; exit 2 ;;
        *)
            if [ -n "$PROJECT" ]; then
                usage; echo "run-tests-logged: only one compose project name can be given" >&2; exit 2
            fi
            PROJECT=$arg
            ;;
    esac
done
if [ -z "$PROJECT" ]; then
    usage
    echo "run-tests-logged: the compose project name is required" >&2
    exit 2
fi
if ! printf '%s' "$PROJECT" | grep -Eq '^[a-z0-9][a-z0-9_-]*$'; then
    echo "run-tests-logged: the compose project name must be lower-case letters, digits, '-' or '_'" >&2
    exit 2
fi
if ! printf '%s' "$VERSIONS" | grep -Eq '^[0-9]+\.[0-9]+(,[0-9]+\.[0-9]+)*$'; then
    echo "run-tests-logged: --versions must look like 8.2,8.3" >&2
    exit 2
fi
if ! printf '%s' "$SUITES" | grep -Eq '^[A-Za-z0-9_]+(,[A-Za-z0-9_]+)*$'; then
    echo "run-tests-logged: --suites must be suite names separated by commas" >&2
    exit 2
fi
command -v docker >/dev/null 2>&1 || { echo "run-tests-logged: docker is required (PHP runs only in Docker)" >&2; exit 2; }

export HOST_UID=${HOST_UID:-$(id -u)}
export HOST_GID=${HOST_GID:-$(id -g)}

dc() {
    docker compose -f "$ROOT/docker/compose.yaml" -p "$PROJECT" "$@"
}

TMP_FILES=()
# shellcheck disable=SC2329 # called through trap
cleanup() {
    local f
    for f in "${TMP_FILES[@]+"${TMP_FILES[@]}"}"; do
        rm -f -- "$f"
    done
    # Only this run's own project: containers, network and volumes.
    dc down -v >/dev/null 2>&1 || true
}
trap cleanup EXIT

mkdir -p "$OUT_DIR"

PHPUNIT_ARGS=(vendor/bin/phpunit --colors=never --fail-on-skipped --fail-on-risky --fail-on-warning --testsuite "$SUITES")

git_state() {
    local head dirty
    head=$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)
    if [ -n "$(git -C "$ROOT" status --porcelain 2>/dev/null)" ]; then
        dirty="uncommitted changes present"
    else
        dirty="clean"
    fi
    echo "HEAD $head, $dirty"
}

# summary_of <file> → "tests=N assertions=M skipped=S", or "tests=? assertions=? skipped=?" when PHPUnit printed none.
summary_of() {
    local file=$1 line tests assertions skipped
    if line=$(grep -E '^OK \(' "$file" | tail -n 1) && [ -n "$line" ]; then
        tests=$(printf '%s' "$line" | sed -nE 's/^OK \(([0-9]+) tests?,.*/\1/p')
        assertions=$(printf '%s' "$line" | sed -nE 's/.*, ([0-9]+) assertions?.*/\1/p')
        skipped=0
    elif line=$(grep -E '^Tests: ' "$file" | tail -n 1) && [ -n "$line" ]; then
        tests=$(printf '%s' "$line" | sed -nE 's/.*Tests: ([0-9]+).*/\1/p')
        assertions=$(printf '%s' "$line" | sed -nE 's/.*Assertions: ([0-9]+).*/\1/p')
        skipped=$(printf '%s' "$line" | sed -nE 's/.*Skipped: ([0-9]+).*/\1/p')
        skipped=${skipped:-0}
    else
        echo "tests=? assertions=? skipped=?"
        return 0
    fi
    echo "tests=${tests:-?} assertions=${assertions:-?} skipped=${skipped:-?}"
}

OVERALL=0
SUMMARY_LINE=""
IFS=',' read -r -a VERSION_LIST <<<"$VERSIONS"
for version in "${VERSION_LIST[@]}"; do
    tag=${version//./}
    log="$OUT_DIR/phase1-test-php$tag.log"
    export PHP_VERSION=$version

    # Setup is not part of the log: the image and the Composer packages.
    dc build --quiet php-dev >&2
    if [ ! -f "$ROOT/vendor/autoload.php" ]; then
        dc run -T --rm --no-deps php-dev composer install --no-interaction --no-progress >&2
    fi

    raw=$(mktemp) body=$(mktemp) final=$(mktemp)
    TMP_FILES+=("$raw" "$body" "$final")

    echo "run-tests-logged: PHP $version ..." >&2
    rc=0
    dc run -T --rm --no-deps php-dev "${PHPUNIT_ARGS[@]}" >"$raw" 2>&1 || rc=$?

    # Docker Compose's own status lines are not PHPUnit's output.
    grep -Ev '^ ?(Container|Network|Volume) [^ ]+ +(Creating|Created|Starting|Started|Running|Healthy|Waiting|Removing|Removed|Stopping|Stopped)' "$raw" >"$body" || true
    sanitize <"$body" >"$raw"

    {
        echo "# peak-sim phase 1 test log (PHPUnit, suites: ${SUITES//,/, })"
        echo "# command: docker compose run --rm --no-deps php-dev ${PHPUNIT_ARGS[*]}"
        echo "# php: $version"
        echo "# repository state: $(git_state)"
        echo "# environment details (absolute paths, user and host names, container ids) were removed before saving"
        echo
        cat "$raw"
    } >"$final"

    left=$(leak_count "$final")
    if [ "$left" -ne 0 ]; then
        echo "run-tests-logged: PHP $version: $left line(s) still look like environment details; the log was NOT written" >&2
        OVERALL=5
        continue
    fi
    mv -- "$final" "$log"
    # mktemp gives 0600; the log is a public artefact like any other file in the repository.
    chmod 0644 -- "$log"

    s=$(summary_of "$log")
    SUMMARY_LINE="${SUMMARY_LINE:+$SUMMARY_LINE | }php$version $s exit=$rc"
    if [ "$rc" -eq 0 ] && [ "$s" = "tests=? assertions=? skipped=?" ]; then
        echo "run-tests-logged: PHP $version: exit 0 but no PHPUnit summary found in the log" >&2
        rc=6
    fi
    if [ "$rc" -gt "$OVERALL" ]; then
        OVERALL=$rc
    fi
    echo "run-tests-logged: PHP $version: wrote ${log#"$ROOT"/} (exit $rc)" >&2
done

echo "$SUMMARY_LINE"
exit "$OVERALL"

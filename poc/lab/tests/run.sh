#!/usr/bin/env bash
# Tests of the lab environment (they need Docker; PHP is not needed on the host). They start and stop the real lab, so
# they refuse to run while any container of the lab exists. The database volume is kept (a test never builds a database).
#
#   poc/lab/tests/run.sh
#
# 1. a sealed start succeeds, and the check inspects every container
# 2. a start on an ordinary (leaky) network is REFUSED with exit 11 and leaves nothing running
# 3. a start with a published port is REFUSED with exit 11 and leaves nothing running
# 4. on a leaky network the probes inside the containers really do reach the outside (so that a pass in 1 means something)
# 5. the app container cannot write to the app, to this repository, or to its own root
# 6. the web server refuses anything but GET at the entry (405), without starting the app
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"
source "$lab/bin/_lab.sh"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
check() { local label="$1"; shift; if "$@"; then pass "$label"; else fail "$label"; fi; }
nothing_left() { [ -z "$(docker ps -a --filter "label=com.docker.compose.project=$PEAK_LAB_PROJECT" --format '{{.Names}}')" ]; }

if ! nothing_left; then
  echo "lab tests: a container of the lab exists; run poc/lab/bin/down first (the tests start and stop the lab)" >&2
  exit 2
fi

main="$lab/compose/lab.compose.yaml"
tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT

# 1 -----------------------------------------------------------------------------------------------------------------
"$lab/bin/up" >"$tmp" 2>&1; rc=$?
check "1a sealed start: bin/up exits 0" test "$rc" -eq 0
check "1b sealed start: the check reports OK" grep -q '^PREFLIGHT OK' "$tmp"
for c in "${LAB_CONTAINERS[@]}"; do
  check "1c sealed start: the check looked at $c" grep -q "$c: no published port" "$tmp"
done
check "1d sealed start: no FAIL line" bash -c '! grep -q "^FAIL" "$1"' _ "$tmp"

# 5 and 6 run on the sealed lab -------------------------------------------------------------------------------------
write_fails() { ! docker exec peak-lab-app sh -c "echo x > '$1'" 2>/dev/null; }
check "5a the app container cannot write into the app" write_fails /app/lab-test-file
check "5b the app container cannot write into this repository" write_fails /peak-sim/lab-test-file
check "5c the app container cannot write to its root" write_fails /lab-test-file
check "5d the scratch directory (tmpfs) is writable" docker exec peak-lab-app sh -c 'echo x > /scratch/lab-test-file && rm /scratch/lab-test-file'
status="$(docker exec peak-lab-k6 sh -c 'wget -q -S -O /dev/null --post-data=x http://lab-web:8080/ 2>&1 | head -1')"
check "6 a POST at the entry gets 405 (got: ${status:-nothing})" bash -c 'echo "$1" | grep -q "405"' _ "$status"
"$lab/bin/down" >/dev/null 2>&1
check "1e down removes every container" nothing_left

# 2 -----------------------------------------------------------------------------------------------------------------
PEAK_LAB_COMPOSE_FILES="$main:$lab/compose/lab.leaky.override.yaml" "$lab/bin/up" >"$tmp" 2>&1; rc=$?
check "2a leaky network: bin/up exits 11" test "$rc" -eq 11
check "2b leaky network: the check names the network" grep -q 'is not internal' "$tmp"
check "2c leaky network: nothing is left running" nothing_left

# 3 -----------------------------------------------------------------------------------------------------------------
PEAK_LAB_COMPOSE_FILES="$main:$lab/compose/lab.ports.override.yaml" "$lab/bin/up" >"$tmp" 2>&1; rc=$?
check "3a published port: bin/up exits 11" test "$rc" -eq 11
check "3b published port: the check names the port" grep -q 'a port is published' "$tmp"
check "3c published port: nothing is left running" nothing_left

# 4 -----------------------------------------------------------------------------------------------------------------
export PEAK_LAB_COMPOSE_FILES="$main:$lab/compose/lab.leaky.override.yaml"
compose up -d --build --wait >/dev/null 2>&1
"$lab/bin/preflight" >"$tmp" 2>&1; rc=$?
unset PEAK_LAB_COMPOSE_FILES
check "4a leaky network: the check alone exits 11" test "$rc" -eq 11
for c in peak-lab-web peak-lab-k6 peak-lab-db; do
  check "4b leaky network: from inside $c an outside address IS reachable" grep -q "FAIL $c: LEAK 1.1.1.1:443 is reachable" "$tmp"
done
check "4c leaky network: from inside the app container the PHP probes see the way out" grep -q 'peak-lab-app: FAIL negative tcp:1.1.1.1:443' "$tmp"
"$lab/bin/down" >/dev/null 2>&1
check "4d down removes every container" nothing_left

echo
echo "lab tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

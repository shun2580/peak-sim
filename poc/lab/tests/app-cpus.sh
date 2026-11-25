#!/usr/bin/env bash
# The CPUs of the app side in the two labs (docs/simulator-spec.md 7.4.7: the Bagisto lab's app has 10 CPUs, the BookStack lab's stays at 6), read from the
# compose file as Docker renders it (`docker compose config`; no container is started, no image is built, no volume is touched), and from the scripts that
# give the number to the app CPU utilization.
#
#   poc/lab/tests/app-cpus.sh
#
# 1  the BookStack lab (no variable set): the app and the web server are on 6 CPUs, cores 2-7; the database 2 CPUs on 0-1, k6 on 8-9, the tools on 10-11
# 2  the Bagisto lab (through bin/with-bagisto): the app and the web server are on 10 CPUs, cores 2-7,12-15; the database, k6 and the tools are what they were
# 3  the two groups of cores in the Bagisto lab do not overlap and all lie inside 0-15; in the BookStack lab the same
# 4  the Bagisto wrapper does not take the app's CPUs from the environment: a BookStack-like value in the caller's environment does not change it
# 5  the number the app CPU utilization is taken over: bin/_lab.sh gives 6 by default and 10 under bin/with-bagisto, and neither measuring script writes
#    a literal 6 or reads another name for it (bin/measure-calibration and bin/measure-ladder pass --app-cpus="$PEAK_LAB_APP_CPUS")
# 6  php-fpm's pool is the same file for both labs and keeps 16 static workers (decisions D191 (4): the workers are not tied to the cores)
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"
repo="$(cd "$lab/../.." && pwd)"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
check() { local label="$1"; shift; if "$@"; then pass "$label"; else fail "$label"; fi; }

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT

# The rendered file, reduced to what this test reads: "<service> <cpus> <cpuset>" per service. Nothing else of it (passwords and the like) is kept.
reduce() {
  python3 -c '
import json, sys
cfg = json.load(sys.stdin)
for name in ("measure-db", "lab-app", "lab-web", "lab-k6", "lab-tools"):
    s = cfg["services"][name]
    print(name, float(s["cpus"]), s["cpuset"])
'
}

# 1 ------------------------------------------------------------------------------------------------------------------------------
# No PEAK_LAB_APP_CPUS and no PEAK_LAB_CPUSET_*: the compose file's own defaults. The values the file insists on are throw-away.
env -u PEAK_LAB_APP_CPUS -u PEAK_LAB_CPUSET_APP -u PEAK_LAB_CPUSET_DB -u PEAK_LAB_CPUSET_K6 -u PEAK_LAB_CPUSET_TOOLS \
  PEAK_REPO_ROOT="$repo" PEAK_APP_ROOT="$work" PEAK_LAB_OUT_DIR="$work" PEAK_LAB_LOAD_DIR="$work" \
  PEAK_MEASURE_DB_ROOT_PASSWORD=x PEAK_MEASURE_DB_PASSWORD=x PEAK_MEASURE_RUN_ID=x PEAK_MEASURE_APP_KEY=x \
  docker compose -p peak-lab -f "$lab/compose/lab.compose.yaml" config --format json 2>"$work/book.err" | reduce >"$work/book.txt" 2>>"$work/book.err"
cat "$work/book.txt" | sed 's/^/# BookStack lab: /'
check "1a the BookStack lab's database: 2 CPUs on 0-1" grep -qx 'measure-db 2.0 0-1' "$work/book.txt"
check "1b the BookStack lab's app: 6 CPUs on 2-7" grep -qx 'lab-app 6.0 2-7' "$work/book.txt"
check "1c the BookStack lab's web server: 6 CPUs on 2-7" grep -qx 'lab-web 6.0 2-7' "$work/book.txt"
check "1d the BookStack lab's k6: 2 CPUs on 8-9" grep -qx 'lab-k6 2.0 8-9' "$work/book.txt"
check "1e the BookStack lab's tools: 2 CPUs on 10-11" grep -qx 'lab-tools 2.0 10-11' "$work/book.txt"

# 2 ------------------------------------------------------------------------------------------------------------------------------
"$lab/bin/with-bagisto" bash -c ". '$lab/bin/_lab.sh'; compose config --format json" 2>"$work/bag.err" | reduce >"$work/bag.txt" 2>>"$work/bag.err"
cat "$work/bag.txt" | sed 's/^/# Bagisto lab: /'
check "2a the Bagisto lab's database: 2 CPUs on 0-1" grep -qx 'measure-db 2.0 0-1' "$work/bag.txt"
check "2b the Bagisto lab's app: 10 CPUs on 2-7,12-15" grep -qx 'lab-app 10.0 2-7,12-15' "$work/bag.txt"
check "2c the Bagisto lab's web server: 10 CPUs on 2-7,12-15" grep -qx 'lab-web 10.0 2-7,12-15' "$work/bag.txt"
check "2d the Bagisto lab's k6: 2 CPUs on 8-9" grep -qx 'lab-k6 2.0 8-9' "$work/bag.txt"
check "2e the Bagisto lab's tools: 2 CPUs on 10-11" grep -qx 'lab-tools 2.0 10-11' "$work/bag.txt"

# 3 ------------------------------------------------------------------------------------------------------------------------------
disjoint() {  # disjoint <file>: the cpusets of the database, the app side, k6 and the tools share no core and lie inside 0-15 (the web server shares the app's)
  python3 - "$1" <<'PY'
import sys
def cores(spec):
    out = set()
    for part in spec.split(","):
        a, _, b = part.partition("-")
        out |= set(range(int(a), int(b or a) + 1))
    return out
rows = {}
for line in open(sys.argv[1]):
    name, _cpus, cpuset = line.split()
    rows[name] = cores(cpuset)
groups = [rows["measure-db"], rows["lab-app"], rows["lab-k6"], rows["lab-tools"]]
assert rows["lab-web"] == rows["lab-app"], "the web server is not on the app's cores"
seen = set()
for g in groups:
    assert not (g & seen), "two groups share a core: %s" % sorted(g & seen)
    seen |= g
assert seen <= set(range(16)), "a core outside 0-15"
PY
}
check "3a the BookStack lab: the groups of cores share no core and lie inside 0-15" disjoint "$work/book.txt"
check "3b the Bagisto lab: the groups of cores share no core and lie inside 0-15" disjoint "$work/bag.txt"
check "3c the Bagisto lab's app cores are the BookStack lab's plus 12-15, and only those" bash -c '
  [ "$(sed -n "s/^lab-app [0-9.]* //p" "$1")" = 2-7 ] && [ "$(sed -n "s/^lab-app [0-9.]* //p" "$2")" = 2-7,12-15 ]' _ "$work/book.txt" "$work/bag.txt"

# 4 ------------------------------------------------------------------------------------------------------------------------------
got="$(PEAK_LAB_APP_CPUS=6 PEAK_LAB_CPUSET_APP=2-7 "$lab/bin/with-bagisto" bash -c 'echo "$PEAK_LAB_APP_CPUS $PEAK_LAB_CPUSET_APP"' 2>&1)"
check "4a a caller's PEAK_LAB_APP_CPUS=6 and PEAK_LAB_CPUSET_APP=2-7 do not change the Bagisto lab ('$got')" test "$got" = "10 2-7,12-15"

# 5 ------------------------------------------------------------------------------------------------------------------------------
got="$(env -u PEAK_LAB_APP_CPUS bash -c ". '$lab/bin/_lab.sh'; echo \"\$PEAK_LAB_APP_CPUS\"" 2>&1 | tail -1)"
check "5a bin/_lab.sh gives 6 with no value set ('$got')" test "$got" = 6
got="$("$lab/bin/with-bagisto" bash -c ". '$lab/bin/_lab.sh'; echo \"\$PEAK_LAB_APP_CPUS\"" 2>&1 | tail -1)"
check "5b bin/_lab.sh under bin/with-bagisto gives 10 ('$got')" test "$got" = 10
check "5c bin/_lab.sh refuses a count that is not a whole number of CPUs" bash -c '! PEAK_LAB_APP_CPUS=six bash -c ". \"$1\"" >/dev/null 2>&1 && ! PEAK_LAB_APP_CPUS=0 bash -c ". \"$1\"" >/dev/null 2>&1' _ "$lab/bin/_lab.sh"
for f in measure-calibration measure-ladder; do
  check "5d bin/$f gives summarize-run --app-cpus=\"\$PEAK_LAB_APP_CPUS\" and no literal number" bash -c '
    grep -c -- "--app-cpus=\"\$PEAK_LAB_APP_CPUS\"" "$1" | grep -qx 1 && ! grep -Eq -- "--app-cpus=[0-9]" "$1"' _ "$lab/bin/$f"
  check "5e bin/$f takes the app's samples in every run it records (--app-samples is given beside --samples)" bash -c '
    grep -c -- "--app-samples=" "$1" | grep -qx 1' _ "$lab/bin/$f"
done

# 6 ------------------------------------------------------------------------------------------------------------------------------
check "6a php-fpm's pool: pm = static and pm.max_children = 16" bash -c 'grep -qx "pm = static" "$1" && grep -qx "pm.max_children = 16" "$1"' _ "$lab/compose/php/zz-lab-fpm.conf"
check "6b the compose file mounts that one file into the app, for both labs" bash -c 'grep -c "compose/php/zz-lab-fpm.conf:/usr/local/etc/php-fpm.d/zz-lab.conf:ro" "$1" | grep -qx 1' _ "$lab/compose/lab.compose.yaml"
check "6c the Bagisto wrapper names no other pool file" bash -c '! grep -v "^[[:space:]]*#" "$1" | grep -qE "fpm.conf|max_children"' _ "$lab/bin/with-bagisto"

echo
echo "app cpus tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

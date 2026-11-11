#!/usr/bin/env bash
# Tests of poc/lab/load/scenario.js: the optional REQUEST_TIMEOUT and the status of each route in the summary. They run the real k6
# (the image the lab uses) against a stand-in web server, in throw-away containers: no network (they share one that has no outside),
# no database, no container of the lab is touched. They need Docker and the two images below; a missing image is a failure, not a skip.
#
#   poc/lab/tests/scenario-timeout.sh
#
# The stand-in answers /fast at once, /slow after 4 seconds and anything else with 404. One short plan, four routes, run four ways:
# 1. no REQUEST_TIMEOUT: as before: the slow route is answered (200), the summary says request_timeout null
# 2. REQUEST_TIMEOUT=1s: the slow route is cut by k6 (status 0) and every one of its requests is; the summary says 1s
# 3. REQUEST_TIMEOUT=600s: the slow route is answered again (200); the summary says 600s
# 4. REQUEST_TIMEOUT=abc or 600: k6 refuses to start (no request is sent, no summary)
# In every run the counts by route add up to the count of the route and the 404 route has only 404 (or 0 when it was cut with the rest).
# One more fact was measured once by hand, not here (it takes 70 s a run): k6 2.3.0 cuts a response at 60 s when no timeout is given
# (status 0 at 59.998 s) and lets a 70 s response through with REQUEST_TIMEOUT=100s.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"
repo="$(cd "$lab/../.." && pwd)"
php_image="${PEAK_DEV_IMAGE:-peak-sim-php-dev:8.3}"
k6_image="grafana/k6:${K6_VERSION:-2.3.0}"
cpuset="${PEAK_DEV_CPUSET:-12-15}"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
check() { local label="$1"; shift; if "$@"; then pass "$label"; else fail "$label"; fi; }

for image in "$php_image" "$k6_image"; do
  docker image inspect "$image" >/dev/null 2>&1 || { echo "scenario-timeout: the image $image is missing" >&2; exit 2; }
done

work="$(mktemp -d)"; srv="peak-scenario-timeout-$$"
cleanup() { docker stop -t 1 "$srv" >/dev/null 2>&1; rm -rf "$work"; }
trap cleanup EXIT
chmod 755 "$work"

cat >"$work/router.php" <<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/slow') { sleep(4); echo 'slow'; return; }
if ($p === '/fast' || $p === '/fast2') { echo 'ok'; return; }
http_response_code(404); echo 'no';
PHP
cat >"$work/list.json" <<'JSON'
{"selected":[{"route":"GET /fast","rank":1},{"route":"GET /slow","rank":2},{"route":"GET /missing","rank":3},{"route":"GET /fast2","rank":4}]}
JSON
cat >"$work/targets.json" <<'JSON'
{"base_url":"http://127.0.0.1:8080","routes":{"GET /fast":{"path":"/fast"},"GET /slow":{"path":"/slow"},"GET /missing":{"path":"/missing"},"GET /fast2":{"path":"/fast2"}}}
JSON

php_run() { docker run --rm --pull=never --network none --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$repo:/peak-sim:ro" -v "$work:/w" "$php_image" php "$@"; }
php_run /peak-sim/poc/lab/load/make-plan.php --routes=/w/list.json --mix=even --rps=2 --duration=30 --warmup=4 >"$work/plan.json" || { echo "scenario-timeout: make-plan failed" >&2; exit 2; }

docker run -d --rm --pull=never --network none --cpuset-cpus="$cpuset" --name "$srv" -e PHP_CLI_SERVER_WORKERS=32 -u "$(id -u):$(id -g)" \
  -v "$work:/w:ro" "$php_image" php -S 127.0.0.1:8080 /w/router.php >/dev/null || { echo "scenario-timeout: the stand-in server did not start" >&2; exit 2; }
sleep 2

k6run() {  # k6run <label> [-e NAME=value ...]: the summary is $work/<label>.json, the log $work/<label>.log, the exit code $work/<label>.rc
  local label="$1"; shift
  docker run --rm --pull=never --network "container:$srv" --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$work:/w" -v "$lab/load:/load:ro" \
    -e PLAN=/w/plan.json -e TARGETS=/w/targets.json -e "SUMMARY=/w/$label.json" "$@" "$k6_image" run /load/scenario.js >"$work/$label.log" 2>&1
  echo $? >"$work/$label.rc"
}
# the five runs are independent, so they run at once (a run is about 35 s)
k6run plain & k6run to1 -e REQUEST_TIMEOUT=1s & k6run to600 -e REQUEST_TIMEOUT=600s & k6run bad1 -e REQUEST_TIMEOUT=abc & k6run bad2 -e REQUEST_TIMEOUT=600 &
wait

# summary <label> <python expression on d>: prints a value of the summary (python3 on the host reads only this temporary directory)
summary() { python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); print(json.dumps(eval(sys.argv[2])))' "$work/$1.json" "$2"; }
eq() { [ "$(summary "$1" "$2")" = "$3" ]; }

# 1. no REQUEST_TIMEOUT
check "1a no timeout: k6 ran to the end (exit 0)" test "$(cat "$work/plain.rc")" = 0
check "1b no timeout: the summary says request_timeout null" eq plain 'd["request_timeout"]' null
check "1c no timeout: the slow route was answered, 200 only" eq plain 'd["http_status_by_route"]["GET /slow"].keys() == {"200"}' true
check "1d no timeout: the 404 route has only 404" eq plain 'set(d["http_status_by_route"]["GET /missing"]) == {"404"}' true
# 2. REQUEST_TIMEOUT=1s
check "2a 1s: k6 ran to the end (exit 0)" test "$(cat "$work/to1.rc")" = 0
check "2b 1s: the summary says 1s" eq to1 'd["request_timeout"]' '"1s"'
check "2c 1s: every request of the slow route was cut by k6 (status 0 only)" eq to1 'set(d["http_status_by_route"]["GET /slow"]) == {"0"} and d["http_status_by_route"]["GET /slow"]["0"] == d["per_route"]["GET /slow"] > 0' true
# 3. REQUEST_TIMEOUT=600s
check "3a 600s: k6 ran to the end (exit 0)" test "$(cat "$work/to600.rc")" = 0
check "3b 600s: the summary says 600s" eq to600 'd["request_timeout"]' '"600s"'
check "3c 600s: the slow route was answered again, 200 only" eq to600 'set(d["http_status_by_route"]["GET /slow"]) == {"200"}' true
# 4. a timeout k6 must not read as a default
for l in bad1 bad2; do
  check "4a $l: k6 refused to start (non-zero exit)" test "$(cat "$work/$l.rc")" != 0
  check "4b $l: it says what is wrong" grep -q 'REQUEST_TIMEOUT must be a k6 duration' "$work/$l.log"
  check "4c $l: no summary and no request" test ! -e "$work/$l.json"
done
# the counts by route add up to the route's count, in every run that ran
for l in plain to1 to600; do
  check "5 $l: the counts by route add up to the count of each route" eq "$l" 'all(sum(d["http_status_by_route"][r].values()) == d["per_route"][r] for r in d["per_route"])' true
  check "5b $l: the overall counts are the sum of the routes'" eq "$l" 'sum(d["http_status"].values()) == sum(d["per_route"].values())' true
done

echo
echo "scenario timeout tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

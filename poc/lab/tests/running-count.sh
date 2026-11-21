#!/usr/bin/env bash
# Tests of the count of the requests RUNNING at the edges of the kept window (section 7.4.5; a reading written in each step's record, never judged), on the
# real load tool: the real k6 (the image the lab uses) against a stand-in web server, in throw-away containers with no outside network and no database. No
# container of the lab is touched and no database is read or built. A missing image is a failure, not a skip.
#
#   poc/lab/tests/running-count.sh
#
# "Running at an instant" is a request sent before it and not answered until at or after it; the load tool counts them on its sending side, by route, at the
# window's first instant and at its last instant. The stand-in answers /fast at once, /mid after 12 seconds and /slow after 42 seconds. One plan: two routes,
# 0.5 rps in all (0.25 each, one request every 4 s), a 28 s step whose first 8 s are discarded (a window of 20 s, 10 requests decided). Each route's stream
# starts 2 s into the step, so it sends at 2, 6, 10, 14, 18, 22 and 26 s: every send is 2 s or more from the edges (the 8th and the 28th second) and every
# answer of the middle route is 2 s or more from the end of the window, so a machine that is busy with other work does not move a count (a 1 s margin did
# move one). Three runs, at once:
#   slow  /fast and /slow   at the window's start the sends at 2 and 6 s of the slow route are still waiting (2); at its end every send of the slow route is
#                           (7: the sends at 2 .. 26 s); the fast route has none at either edge (0 and 0)
#   mid   /fast and /mid    at the start the sends at 2 and 6 s of the middle route are waiting (they answer at 14 and 18 s): 2; at the end those sent at 18, 22
#                           and 26 s are (they answer at 30, 34 and 38 s; the one sent at 14 s answered at 26 s): 3; the fast route 0 and 0
#   none  /fast and /fast   nothing is running at either edge: 0 and 0 on both routes (a count that is "no requests" is written, not left out)
# Each run also keeps what it kept before: the sent count in the window is the decided 10, and the summary says which layer the counts are from.
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
  docker image inspect "$image" >/dev/null 2>&1 || { echo "running-count: the image $image is missing" >&2; exit 2; }
done

work="$(mktemp -d)"; srv="peak-running-count-$$"
cleanup() { docker stop -t 1 "$srv" >/dev/null 2>&1; rm -rf "$work"; }
trap cleanup EXIT
chmod 755 "$work"

cat >"$work/router.php" <<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/slow') { sleep(42); echo 'slow'; return; }
if ($p === '/mid') { sleep(12); echo 'mid'; return; }
echo 'ok';
PHP
cat >"$work/list.json" <<'JSON'
{"selected":[{"route":"GET /fast","rank":1},{"route":"GET /second","rank":2}]}
JSON
mk_targets() {  # mk_targets <file> <path of the second route>
  printf '{"base_url":"http://127.0.0.1:8080","routes":{"GET /fast":{"path":"/fast"},"GET /second":{"path":"%s"}}}\n' "$2" >"$work/$1"
}
mk_targets targets-slow.json /slow
mk_targets targets-mid.json /mid
mk_targets targets-none.json /fast

php_run() { docker run --rm --pull=never --network none --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$repo:/peak-sim:ro" -v "$work:/w" "$php_image" php "$@"; }
php_run /peak-sim/poc/lab/load/make-plan.php --routes=/w/list.json --mix=even --rps=0.5 --duration=28 --warmup=8 >"$work/plan-flat.json" || { echo "running-count: make-plan failed" >&2; exit 2; }
python3 -I -c 'import json,sys; p=json.load(open(sys.argv[1])); [r.update(start_offset_sec=2) for r in p["routes"]]; print(json.dumps(p))' "$work/plan-flat.json" >"$work/plan.json" || { echo "running-count: cannot stagger the plan" >&2; exit 2; }

docker run -d --rm --pull=never --network none --cpuset-cpus="$cpuset" --name "$srv" -e PHP_CLI_SERVER_WORKERS=64 -u "$(id -u):$(id -g)" \
  -v "$work:/w:ro" "$php_image" php -S 127.0.0.1:8080 /w/router.php >/dev/null || { echo "running-count: the stand-in server did not start" >&2; exit 2; }
sleep 2

k6run() {  # k6run <label> <targets file>
  docker run --rm --pull=never --network "container:$srv" --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$work:/w" -v "$lab/load:/load:ro" \
    -e PLAN=/w/plan.json -e "TARGETS=/w/$2" -e "SUMMARY=/w/$1.json" -e REQUEST_TIMEOUT=100s "$k6_image" run /load/scenario.js >"$work/$1.log" 2>&1
  echo $? >"$work/$1.rc"
}
k6run slow targets-slow.json &
k6run mid targets-mid.json &
k6run none targets-none.json &
wait

summary() { python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); print(json.dumps(eval(sys.argv[2])))' "$work/$1.json" "$2"; }
eq() { local got; got="$(summary "$1" "$2")"; [ "$got" = "$3" ] || { echo "     (got $got)" >&2; return 1; }; }

for l in slow mid none; do
  check "0 $l: k6 ran to the end (exit 0)" test "$(cat "$work/$l.rc")" = 0
  check "0b $l: the sent count in the window is the decided 10, as before" eq "$l" 'd["measure_sent"]' 10
  check "0c $l: the summary says the counts are from the load tool's sending side, not php-fpm" eq "$l" '"k6 sending side" in d["running_counted_by"] and "not php-fpm" in d["running_counted_by"]' true
  check "0d $l: both counts name every route of the plan" eq "$l" '[sorted(d["running_at_window_start"]), sorted(d["running_at_window_end"])] == [["GET /fast", "GET /second"]] * 2' true
done

# a slow route: running at both edges
check "1a slow: at the window's start 2 requests of the slow route are running" eq slow 'd["running_at_window_start"]["GET /second"]' 2
check "1b slow: at the window's end 7 requests of the slow route are running" eq slow 'd["running_at_window_end"]["GET /second"]' 7
check "1c slow: the fast route has none at either edge" eq slow '[d["running_at_window_start"]["GET /fast"], d["running_at_window_end"]["GET /fast"]]' '[0, 0]'

# a middle route: running at the start, and only the last three sends at the end
check "2a mid: at the window's start 2 requests of the middle route are running" eq mid 'd["running_at_window_start"]["GET /second"]' 2
check "2b mid: at the window's end 3 requests of the middle route are running (those sent at 18, 22 and 26 s)" eq mid 'd["running_at_window_end"]["GET /second"]' 3
check "2c mid: the fast route has none at either edge" eq mid '[d["running_at_window_start"]["GET /fast"], d["running_at_window_end"]["GET /fast"]]' '[0, 0]'

# nothing running: the number is 0, written
check "3a none: nothing is running at the start, on either route (0, written)" eq none '[d["running_at_window_start"]["GET /fast"], d["running_at_window_start"]["GET /second"]]' '[0, 0]'
check "3b none: nothing is running at the end, on either route (0, written)" eq none '[d["running_at_window_end"]["GET /fast"], d["running_at_window_end"]["GET /second"]]' '[0, 0]'

echo
echo "running-count tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

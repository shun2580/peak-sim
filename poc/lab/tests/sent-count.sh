#!/usr/bin/env bash
# Tests of the count of section 7.4.3 on the real load tool: the requests SENT inside the window are what the rps check counts, the load
# tool waits at the end of a step for the requests still running as long as the request timeout, and a dropped arrival makes a step invalid.
# They run the real k6 (the image the lab uses) against a stand-in web server, in throw-away containers with no outside network and no
# database; no container of the lab is touched. A missing image is a failure, not a skip.
#
#   poc/lab/tests/sent-count.sh
#
# The stand-in answers /fast at once and /slow after 42 seconds. One plan: two routes, 1 rps in all (0.5 each), a 24 s step whose first
# 4 s are discarded (a window of 20 s, 20 requests decided, 10 of them to the slow route). Each route's stream starts 1 s into the
# step (start_offset_sec, as the staggered plans of the real ladder do), so every send is 1 s or more from the edges of the window
# (the 4th and the 24th second): a plan that sent on an edge would count 19 to 22 requests from one run to the next, by milliseconds.
# A request of the slow route sent after the 12th second of the step is still running 30 s after the step's end. That is the shape of the third run's s-1 step (a request of over 40 s
# running at the end of the step), made small. Five runs, at once:
#   old   the load script of the commit before this change (k6's own 30 s wait at the end; only the requests that finished are counted)
#   new   this script with REQUEST_TIMEOUT=100s (it waits 100 s at the end; the requests sent are counted)
#   drop  this script with one VU only (the load tool cannot start every arrival: it drops some)
#   plain this script with no REQUEST_TIMEOUT (k6's own wait; used to show the wait is changed only when a timeout is given)
#   none  this script with REQUEST_TIMEOUT=100s on a plan whose routes all answer at once (nothing is running at the end)
# What it shows: old -> the finished count is below 95% of the decided and the step is invalid; new -> the sent count is the decided
# 20, the step is valid, every request finished, and some finished after the window (the record says so); drop -> invalid by the
# dropped arrivals; plain -> the wait stays k6's own (requests are still cut); none -> the sent count equals the finished count.
# A last run (phase.js, no server) checks, on fixed times, that a request sent at or after the window's end is not in the window.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"
repo="$(cd "$lab/../.." && pwd)"
php_image="${PEAK_DEV_IMAGE:-peak-sim-php-dev:8.3}"
k6_image="grafana/k6:${K6_VERSION:-2.3.0}"
cpuset="${PEAK_DEV_CPUSET:-12-15}"
old_commit="5e88409"   # the last commit before section 7.4.3's change of the load script

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
check() { local label="$1"; shift; if "$@"; then pass "$label"; else fail "$label"; fi; }

for image in "$php_image" "$k6_image"; do
  docker image inspect "$image" >/dev/null 2>&1 || { echo "sent-count: the image $image is missing" >&2; exit 2; }
done

work="$(mktemp -d)"; srv="peak-sent-count-$$"
cleanup() { docker stop -t 1 "$srv" >/dev/null 2>&1; rm -rf "$work"; }
trap cleanup EXIT
chmod 755 "$work"; mkdir -p "$work/old" && chmod 755 "$work/old"
git -C "$repo" show "$old_commit:poc/lab/load/scenario.js" >"$work/old/scenario.js" || { echo "sent-count: cannot read the old load script at $old_commit" >&2; exit 2; }
chmod 644 "$work/old/scenario.js"

cat >"$work/router.php" <<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/slow') { sleep(42); echo 'slow'; return; }
echo 'ok';
PHP
cat >"$work/list.json" <<'JSON'
{"selected":[{"route":"GET /fast","rank":1},{"route":"GET /slow","rank":2}]}
JSON
cat >"$work/list-fast.json" <<'JSON'
{"selected":[{"route":"GET /fast","rank":1},{"route":"GET /slow","rank":2}]}
JSON
cat >"$work/targets.json" <<'JSON'
{"base_url":"http://127.0.0.1:8080","routes":{"GET /fast":{"path":"/fast"},"GET /slow":{"path":"/slow"}}}
JSON
cat >"$work/targets-fast.json" <<'JSON'
{"base_url":"http://127.0.0.1:8080","routes":{"GET /fast":{"path":"/fast"},"GET /slow":{"path":"/fast"}}}
JSON

php_run() { docker run --rm --pull=never --network none --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$repo:/peak-sim:ro" -v "$work:/w" "$php_image" php "$@"; }
php_run /peak-sim/poc/lab/load/make-plan.php --routes=/w/list.json --mix=even --rps=1 --duration=24 --warmup=4 >"$work/plan-flat.json" || { echo "sent-count: make-plan failed" >&2; exit 2; }
# start every route's stream 1 s into the step: sends at 1, 3, ... 23 s (warm-up: 2 each, window: 10 each)
python3 -I -c 'import json,sys; p=json.load(open(sys.argv[1])); [r.update(start_offset_sec=1) for r in p["routes"]]; print(json.dumps(p))' "$work/plan-flat.json" >"$work/plan.json" || { echo "sent-count: cannot stagger the plan" >&2; exit 2; }

docker run -d --rm --pull=never --network none --cpuset-cpus="$cpuset" --name "$srv" -e PHP_CLI_SERVER_WORKERS=64 -u "$(id -u):$(id -g)" \
  -v "$work:/w:ro" "$php_image" php -S 127.0.0.1:8080 /w/router.php >/dev/null || { echo "sent-count: the stand-in server did not start" >&2; exit 2; }
sleep 2

k6run() {  # k6run <label> <load dir> <targets file> [-e NAME=value ...]
  local label="$1" loaddir="$2" targets="$3"; shift 3
  docker run --rm --pull=never --network "container:$srv" --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$work:/w" -v "$loaddir:/load:ro" \
    -e PLAN=/w/plan.json -e "TARGETS=/w/$targets" -e "SUMMARY=/w/$label.json" "$@" "$k6_image" run /load/scenario.js >"$work/$label.log" 2>&1
  echo $? >"$work/$label.rc"
}
k6run old "$work/old" targets.json -e REQUEST_TIMEOUT=100s &
k6run new "$lab/load" targets.json -e REQUEST_TIMEOUT=100s &
k6run drop "$lab/load" targets.json -e REQUEST_TIMEOUT=100s -e PRE_VUS=1 -e MAX_VUS=1 &
k6run plain "$lab/load" targets.json &
k6run none "$lab/load" targets-fast.json -e REQUEST_TIMEOUT=100s &
wait

summary() { python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); print(json.dumps(eval(sys.argv[2])))' "$work/$1.json" "$2"; }
eq() { local got; got="$(summary "$1" "$2")"; [ "$got" = "$3" ] || { echo "     (got $got; sent $(summary "$1" 'd["measure_sent"] if "measure_sent" in d else None'), finished $(summary "$1" 'd["measure_requests"]'))" >&2; return 1; }; }
# judge <label> <finished|sent> : the verdict of the rule given by the step's own summary, through the repository's own classes
judge() {
  php_run -r '
    require "/peak-sim/poc/runtime/bootstrap.php";
    $d = json_decode(file_get_contents("/w/'"$1"'.json"), true);
    if ("'"$2"'" === "finished") { $r = PeakSim\Runtime\LabLoad\RpsAchievement::judge(1.0, (int) $d["measure_requests"], 20); }
    else { $r = PeakSim\Runtime\LabLoad\RpsAchievement::judgeStep(1.0, (int) $d["measure_sent"], 20, (int) $d["dropped_iterations"]); }
    echo json_encode([$r["status"], $r["achieved_rps"], $r["invalid_reasons"] ?? null]);'
}

for l in old new drop plain none; do
  check "0 $l: k6 ran to the end (exit 0)" test "$(cat "$work/$l.rc")" = 0
done

# the step of the third run's shape, before the change: invalid
check "1a old: the old load script has no sent count" eq old '"measure_sent" in d' false
check "1b old: some requests of the slow route were cut at the end (finished < 20)" eq old 'd["measure_requests"] < 19' true
check "1c old: the finished count is outside 5% of the decided, so the step is invalid" test "$(judge old finished | cut -d'"' -f2)" = invalid

# after the change: valid
check "2a new: the sent count is the decided 20" eq new 'd["measure_sent"]' 20
check "2b new: every request that was sent finished (the wait was long enough)" eq new 'd["measure_requests"] == d["measure_sent"]' true
check "2c new: no arrival was dropped" eq new 'd["dropped_iterations"]' 0
check "2d new: the step is valid, counted on the sent requests" test "$(judge new sent | cut -d'"' -f2)" = valid
check "2e new: requests finished after the window and the summary says how long after" eq new 'd["finished_after_window"] > 0 and d["longest_after_window_ms"] > 10000' true
check "2f new: the summary says the wait at the end is the timeout" eq new 'd["graceful_stop"]' '"100s"'
check "2g new: the sent counts of the routes add up to the total" eq new 'sum(d["per_route_sent"].values()) == d["measure_sent"]' true
check "2h new: the slow route's requests were all answered (200)" eq new 'set(d["http_status_by_route"]["GET /slow"]) == {"200"}' true

# the old and the new scripts, on the same step
check "3a the old finished count and the new finished count are both below or equal to the sent count of the new run" eq new 'd["measure_requests"] <= d["measure_sent"]' true

# a dropped arrival
check "4a drop: the load tool dropped arrivals" eq drop 'd["dropped_iterations"] > 0' true
check "4b drop: the step is invalid and the reason is the dropped arrivals" test "$(judge drop sent)" != "" -a "$(judge drop sent | grep -c load_tool_dropped_arrivals)" = 1
check "4c drop: the verdict is invalid" test "$(judge drop sent | cut -d'"' -f2)" = invalid

# with no REQUEST_TIMEOUT the wait at the end is not touched
check "5a plain: the summary says no timeout and no wait" eq plain '[d["request_timeout"], d["graceful_stop"]]' '[null, null]'
check "5b plain: the old 30 s wait still cuts the slow requests at the end (finished < sent)" eq plain 'd["measure_requests"] < d["measure_sent"]' true

# nothing running at the end: the two counts are the same
check "6a none: nothing was running at the end, the sent count equals the finished count (20)" eq none '[d["measure_sent"], d["measure_requests"]]' '[20, 20]'
check "6b none: the step is valid" test "$(judge none sent | cut -d'"' -f2)" = valid
check "6c none: nothing finished after the window" eq none 'd["finished_after_window"]' 0

# a request sent at or after the window's end is not counted in the window (phase.js, run by k6 on fixed times, so no clock is involved)
cat >"$work/phase-test.js" <<'JS'
import { phaseAt } from '/load/phase.js';
export const options = { vus: 1, iterations: 1 };
export function setup() {
  return {
    first: phaseAt(0, 4, 24),
    before_edge: phaseAt(3999, 4, 24),
    at_start: phaseAt(4000, 4, 24),
    middle: phaseAt(13000, 4, 24),
    last_in: phaseAt(23999, 4, 24),
    at_end: phaseAt(24000, 4, 24),
    past_end: phaseAt(24001, 4, 24),
    long_after: phaseAt(60000, 4, 24),
  };
}
export default function () {}
export function handleSummary(data) {
  return { '/w/phase.json': JSON.stringify(data.setup_data) };
}
JS
docker run --rm --pull=never --network none --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$work:/w" -v "$lab/load:/load:ro" "$k6_image" run /w/phase-test.js >"$work/phase.log" 2>&1
check "7a phase: k6 ran phase.js to the end (exit 0)" test "$?" = 0
check "7b phase: before the window's start is warmup, from the start up to the last millisecond of the window is measure" eq phase '[d["first"], d["before_edge"], d["at_start"], d["middle"], d["last_in"]]' '["warmup", "warmup", "measure", "measure", "measure"]'
check "7c phase: a request sent at the window's end or later is after, not measure" eq phase '[d["at_end"], d["past_end"], d["long_after"]]' '["after", "after", "after"]'
check "7d scenario.js decides the phase of a request with phaseAt of phase.js" grep -q "phaseAt(Date.now() - started, plan.warmup_sec, plan.duration_sec)" "$lab/load/scenario.js"

echo
echo "sent-count tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

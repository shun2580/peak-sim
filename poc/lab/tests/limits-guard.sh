#!/usr/bin/env bash
# Tests of the limits guard: `bin/check-limits` and its place in front of every step of `bin/measure-ladder` for r-1 .. r-5. They never
# touch the lab: the `docker` they put on PATH is a stand-in that answers the three reads (`nginx -T`, `php-fpm -tt`, the probe through
# php-fpm) from files the test writes, runs the PHP tools in a throw-away container (no network, no database, nice, the dev cores) and
# records every other call, so that "nothing was measured" is shown by the load tool (`k6`) never being called.
#
#   poc/lab/tests/limits-guard.sh
#
# check-limits, one lab setting at a time:
#  1  unlimited, the lab set for B (PHP 0, pool 0s, nginx 600s, k6 600s)             -> 0
#  2  unlimited, PHP max_execution_time 30                                           -> 13, says max_execution_time
#  3  unlimited, nginx fastcgi_read_timeout 120s (shorter than k6's 600s)            -> 13, says fastcgi_read_timeout
#  4  unlimited, nginx 599s (one second short) / 10m (long enough)                   -> 13 / 0
#  5  unlimited, php-fpm request_terminate_timeout 300s                              -> 13, says request_terminate_timeout
#  6  a read that fails (nginx -T, php-fpm -tt, the probe, or the PHP tool itself)   -> 13 for every requirement: it fails closed
#  7  usage: unlimited without --request-timeout, an unknown requirement, a timeout without a unit -> 2
#  8  limited: PHP 30 -> 0; PHP 0 -> 13; an unreadable probe -> 13
#  9  none: reads and records only (a lab at the default passes), an unreadable one still stops
# measure-ladder, the real script in a copy of the lab's bin directory (preflight is the only thing replaced; it is not the subject):
# 10  r-1 on the lab as it is left after a switch was forgotten (PHP 30, nginx 120s)  -> 13 before any load: `k6` is never called
# 11  r-1 with the lab set for B reaches `k6` (so the test in 10 means something)
# 12  r-1 with nginx 599s, with the pool at 300s, with an unreadable probe             -> 13, `k6` never called
# 13  t-4 is measured as before: a lab at the default reaches `k6`; a limit that cannot be read still stops it (13) before `k6`
# 14  the refusal says why and where the readings are kept
# the memory limit (2026-11-16 ruling, D206 and D208): B needs memory_limit 4G, the mark 512M
# 15  unlimited with memory_limit 512M (or 2G, 8G, -1, 4GB, a word)                   -> 13, says memory_limit and 4G; 4096M is 4G -> 0
# 16  limited with memory_limit left at 4G                                            -> 13, says memory_limit and 512M; 30 and 512M -> 0; none reads only
# 17  r-1 with the time limits off but memory_limit 512M                              -> 13 before any load; set for B (4G) it reaches `k6`
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"
repo_real="$(cd "$lab/../.." && pwd)"
php_image="${PEAK_DEV_IMAGE:-peak-sim-php-dev:8.3}"
cpuset="${PEAK_DEV_CPUSET:-12-15}"
real_docker="$(command -v docker)"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
expect_code() { if [ "$3" -eq "$2" ]; then pass "$1"; else fail "$1 (exit $3, wanted $2)"; fi; }
has() { grep -q -- "$2" "$1"; }

[ -n "$real_docker" ] && "$real_docker" image inspect "$php_image" >/dev/null 2>&1 || { echo "limits-guard: docker and the image $php_image are needed (to run the PHP tools)" >&2; exit 2; }

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
mkdir -p "$work/state" "$work/out" "$work/fakebin" "$work/s" "$work/lab/bin" "$work/lab/observe"
out="$(cd "$work/out" && pwd)"

# ---- the stand-in docker ----------------------------------------------------------------------------------------------------
# The state of the pretend lab is a few files in $work/s; a missing file means the default (PHP 30, pool 0s, nginx 120s, scale 50000).
cat >"$work/fakebin/docker" <<FAKE
#!/usr/bin/env bash
w="$work"; s="\$w/s"
echo "\$*" >>"\$w/docker.calls"
val() { if [ -f "\$s/\$1" ]; then cat "\$s/\$1"; else echo "\$2"; fi; }
if [ "\$1" = inspect ]; then
  case "\$*" in
    *State.OOMKilled*) [ -f "\$s/inspect_fail" ] && exit 1; echo "\$(val oom_killed false) \$(val restart 0) \$(val started 2026-11-16T00:00:00Z)"; exit 0 ;;
    *) echo "PEAK_LAB_SCALE=\$(val scale 50000)"; exit 0 ;;
  esac
fi
if [ "\$1" = exec ]; then
  shift
  while [ "\${1:-}" = "-i" ] || [ "\${1:-}" = "-e" ]; do if [ "\$1" = "-e" ]; then shift 2; else shift; fi; done
  c="\$1"; shift
  if [ "\$c" = peak-lab-db ] && [ "\$1" = mysql ]; then   # the count and checksum of the N tables (bin/check-tables): the tables do not change
    case "\$*" in
      *CHECKSUM*) printf '10000\ndb.t\t111\n'; exit 0 ;;
      *performance_schema*) d="\$(printf '%s' "\$*" | sed -n "s/.*object_schema = '\([^']*\)'.*/\1/p")"; printf '%s\tentities\t0\t0\t0\n%s\tviews\t1\t0\t0\n' "\$d" "\$d"; exit 0 ;;
    esac
  fi
  case "\$c \$1 \$2" in
    "peak-lab-web nginx -T")
      [ -f "\$s/nginx_fail" ] && { echo "nginx: [emerg] cannot load"; exit 1; }
      printf 'http {\n    server {\n        location / {\n            fastcgi_pass lab_app;\n            fastcgi_read_timeout %s;\n        }\n    }\n}\n' "\$(val nginx 120s)"; exit 0 ;;
    "peak-lab-app cat /sys/fs/cgroup/memory.events")
      [ -f "\$s/events_fail" ] && exit 1
      [ -f "\$s/events_bad" ] && { printf 'low 0\nhigh 0\nmax 0\n'; exit 0; }
      printf 'low 0\nhigh 0\nmax 0\noom %s\noom_kill %s\noom_group_kill 0\n' "\$(val cg_oom 0)" "\$(val cg_oom_kill 0)"; exit 0 ;;
    "peak-lab-app php-fpm -tt")
      [ -f "\$s/fpm_fail" ] && { echo "ERROR: unable to read the configuration"; exit 1; }
      printf '[11-Nov-2026 10:00:00] NOTICE: \trequest_terminate_timeout = %s\n' "\$(val pool 0s)"; exit 0 ;;
  esac
  if [ "\$c" = peak-lab-tools ] && [ "\$1" = php ] && [ "\$2" = /peak-sim/poc/lab/diagnosis/fcgi_get.php ]; then
    [ -f "\$s/ini_fail" ] && { echo "cannot connect: Connection refused"; exit 1; }
    printf '{"sapi":"fpm-fcgi","php":"8.3.36","max_execution_time":"%s","memory_limit":"%s"}\n' "\$(val php 30)" "\$(val mem 512M)"; exit 0
  fi
  if [ "\$c" = peak-lab-tools ] && [ "\$1" = php ]; then
    [ -f "\$s/php_down" ] && { echo "the tools container is not running" >&2; exit 1; }
    shift
    exec "$real_docker" run --rm -i --pull=never --network none --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$repo_real:/peak-sim:ro" -v "$out:/out" "$php_image" php "\$@"
  fi
  if [ "\$c" = peak-lab-k6 ]; then echo "\$*" >>"\$w/k6.calls"; exit 99; fi
fi
exit 99
FAKE
chmod +x "$work/fakebin/docker"

# a copy of the lab's bin directory with the real scripts; preflight (the closed-network check) and the CPU sampler are stubs
for f in measure-ladder check-limits check-writes check-tables _lab.sh prediction-gate check-oom read-memory; do cp "$lab/bin/$f" "$work/lab/bin/$f"; done
mkdir -p "$work/rec" "$work/lab/routes"; cp "$lab/routes/route-list.json" "$work/lab/routes/route-list.json"
python3 "$here/make-statement-record.py" "$lab/routes/route-list.json" "$work/rec/ok.json" --variant=ok
rec="$work/rec/ok.json"   # the made-up record of the statements the write check reads (every write in it is to views)

printf '#!/usr/bin/env bash\nexit 0\n' >"$work/lab/bin/preflight"; chmod +x "$work/lab/bin/preflight"
printf '#!/usr/bin/env bash\nexit 0\n' >"$work/lab/observe/sample-cpu"; chmod +x "$work/lab/observe/sample-cpu"

# a git repository with one committed prediction file, so that the gate in front of measure-ladder is open
grepo="$work/repo"; mkdir -p "$grepo/poc/lab/predictions"
git -C "$grepo" init -q . && git -C "$grepo" config user.email t@example.invalid && git -C "$grepo" config user.name t
# the prediction holds the scenarios of the re-check (their tables are of 50,000 and 200,000 rows), so that the refusal for counts not seen yet is open for them
python3 -c 'import json,sys; n=lambda c:{"entities":c,"images":c,"references":c}; json.dump({"scenarios":[{"id":"r-1","tables":n(50000)},{"id":"r-2","tables":n(50000)},{"id":"r-3","tables":n(200000)},{"id":"r-4","tables":n(200000)},{"id":"r-5","tables":n(200000)}]}, open(sys.argv[1],"w"))' "$grepo/poc/lab/predictions/p.json"; git -C "$grepo" add -A && git -C "$grepo" commit -q -m "chore: seed"
pred="$grepo/poc/lab/predictions/p.json"
for scale in 10000 50000 200000; do echo '{}' >"$out/targets-$scale.json"; done

reset_lab() { rm -f "$work"/s/*; : >"$work/docker.calls"; : >"$work/k6.calls"; }
set_lab() {  # set_lab php=0 pool=0s nginx=600s ...
  reset_lab
  local kv; for kv in "$@"; do printf '%s' "${kv#*=}" >"$work/s/${kv%%=*}"; done
}
env_run() { PATH="$work/fakebin:$PATH" PEAK_REPO_ROOT="$grepo" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" "$@"; }
cl() { env_run "$work/lab/bin/check-limits" "$@" >"$work/cl.out" 2>"$work/cl.err"; }
ml() { env_run "$work/lab/bin/measure-ladder" "$@" --statement-record="$rec" >"$work/ml.out" 2>"$work/ml.err"; }
k6_calls() { wc -l <"$work/k6.calls" | tr -d ' '; }
said() { has "$work/cl.err" "$1" || has "$work/cl.out" "$1"; }

B=(php=0 pool=0s nginx=600s mem=4G)

# ---- check-limits ------------------------------------------------------------------------------------------------------------
set_lab "${B[@]}"; cl --require=unlimited --request-timeout=600s; expect_code "1 unlimited, lab set for B: passes" 0 $?
said 'LIMITS OK' && pass "1b it says OK and prints the readings" || fail "1b no OK line ($(cat "$work/cl.err"))"

set_lab php=30 pool=0s nginx=600s; cl --require=unlimited --request-timeout=600s; expect_code "2 unlimited, PHP limit 30: refused" 13 $?
said 'max_execution_time' && pass "2b it names max_execution_time" || fail "2b ($(cat "$work/cl.err"))"

set_lab php=0 pool=0s nginx=120s; cl --require=unlimited --request-timeout=600s; expect_code "3 unlimited, nginx 120s under k6 600s: refused" 13 $?
said 'fastcgi_read_timeout' && pass "3b it names fastcgi_read_timeout" || fail "3b ($(cat "$work/cl.err"))"

set_lab php=0 pool=0s nginx=599s; cl --require=unlimited --request-timeout=600s; expect_code "4 nginx 599s, one second short: refused" 13 $?
set_lab php=0 pool=0s nginx=10m mem=4G; cl --require=unlimited --request-timeout=600s; expect_code "4b nginx 10m = 600s: passes" 0 $?

set_lab php=0 pool=300s nginx=600s; cl --require=unlimited --request-timeout=600s; expect_code "5 pool request_terminate_timeout 300s: refused" 13 $?
said 'request_terminate_timeout' && pass "5b it names request_terminate_timeout" || fail "5b ($(cat "$work/cl.err"))"

for broken in nginx_fail fpm_fail ini_fail php_down; do
  for req in "unlimited --request-timeout=600s" "limited" "none"; do
    set_lab "${B[@]}"; : >"$work/s/$broken"
    # shellcheck disable=SC2086
    cl --require=$req; expect_code "6 $broken, require $req: refused (fails closed)" 13 $?
  done
done

set_lab "${B[@]}"; cl --require=unlimited; expect_code "7 unlimited without --request-timeout: usage" 2 $?
cl --require=sometimes; expect_code "7b an unknown requirement: usage" 2 $?
cl --require=unlimited --request-timeout=600; expect_code "7c a timeout without a unit: usage" 2 $?
cl; expect_code "7d no requirement at all: usage" 2 $?
[ "$(wc -l <"$work/docker.calls" | tr -d ' ')" = 0 ] && pass "7e none of them touched a container" || fail "7e a container was touched on a usage error"

set_lab php=30 pool=0s nginx=120s; cl --require=limited; expect_code "8 limited, PHP 30: passes" 0 $?
set_lab "${B[@]}"; cl --require=limited; expect_code "8b limited, PHP 0 (the lab was left set for B): refused" 13 $?
said 'max_execution_time' && pass "8c it names max_execution_time" || fail "8c ($(cat "$work/cl.err"))"

set_lab php=30 pool=0s nginx=120s; cl --require=none; expect_code "9 none: a lab at the default is read, not judged" 0 $?
[ -s "$(ls -d "$out"/limits/*/ | tail -1)settings.json" ] && pass "9b the readings are kept in the default directory" || fail "9b no settings.json under out/limits"

# ---- the memory limit (2026-11-16 ruling, D206 and D208): B needs 4G, the mark 512M ------------------------------------------------
set_lab php=0 pool=0s nginx=600s; cl --require=unlimited --request-timeout=600s; expect_code "15 unlimited, time limits off but memory_limit left at 512M: refused" 13 $?
said 'memory_limit' && said '4G' && pass "15b it names memory_limit and says B needs 4G" || fail "15b ($(cat "$work/cl.err"))"
for m in 2G 8G -1 4GB unlimited; do
  set_lab php=0 pool=0s nginx=600s mem=$m; cl --require=unlimited --request-timeout=600s; expect_code "15c unlimited, memory_limit $m: refused" 13 $?
done
set_lab php=0 pool=0s nginx=600s mem=4096M; cl --require=unlimited --request-timeout=600s; expect_code "15d memory_limit 4096M is 4G: passes" 0 $?
set_lab php=30 pool=0s nginx=120s mem=4G; cl --require=limited; expect_code "16 limited, memory_limit left at 4G after B: refused" 13 $?
said 'memory_limit' && said '512M' && pass "16b it names memory_limit and says the mark is measured with 512M" || fail "16b ($(cat "$work/cl.err"))"
set_lab php=30 pool=0s nginx=120s mem=512M; cl --require=limited; expect_code "16c limited, 30 and 512M: passes" 0 $?
set_lab php=30 pool=0s nginx=120s mem=4G; cl --require=none; expect_code "16d none: memory_limit 4G is read, not judged" 0 $?
has "$(ls -d "$out"/limits/*/ | tail -1)settings.json" '"php_memory_limit": "4G"' && pass "16e the reading of memory_limit is kept in settings.json" || fail "16e ($(cat "$(ls -d "$out"/limits/*/ | tail -1)settings.json"))"
set_lab php=0 pool=0s nginx=600s scale=50000
ml --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s; rc=$?
expect_code "17 r-1 with the time limits off but memory_limit at 512M: 13 before any load" 13 $rc
[ "$(k6_calls)" = 0 ] && has "$work/ml.err" 'memory_limit' && pass "17b the load tool was never called and the refusal names memory_limit" || fail "17b ($(k6_calls) calls, $(head -2 "$work/ml.err"))"
set_lab "${B[@]}" scale=50000
ml --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s; rc=$?
[ "$(k6_calls)" -ge 1 ] && has "$work/ml.out" 'php_memory_limit": "4G"' && pass "17c r-1 on a lab set for B (4G) reaches the load tool, and the step's settings carry memory_limit 4G" || fail "17c ($(head -2 "$work/ml.err"))"

# ---- measure-ladder ----------------------------------------------------------------------------------------------------------
echo "# the guard in front of r-1 (every call to the stand-in docker is recorded; the load tool k6 is the thing that must not be reached)"
set_lab php=30 pool=0s nginx=120s   # what the lab is when nobody switched it
ml --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s; rc=$?
expect_code "10 r-1 on a lab left at the default: 13" 13 $rc
[ "$(k6_calls)" = 0 ] && pass "10b the load tool was never called" || fail "10b k6 was called $(k6_calls) time(s)"
has "$work/ml.out" 'PREDICTION OK' && pass "10c the gate was open, so it is the limits that stopped it" || fail "10c the gate did not open ($(head -2 "$work/ml.err"))"
has "$work/ml.out" 'RUNG START' && fail "10d a step was started" || pass "10d no step was started"

set_lab "${B[@]}"
ml --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s; rc=$?
[ "$(k6_calls)" -ge 1 ] && pass "11 r-1 on a lab set for B reaches the load tool (so 10b means something)" || fail "11 r-1 never reached k6 ($(head -3 "$work/ml.err" | tr '\n' ' '))"
expect_code "11b the stand-in k6 fails, which stops it at 13 after the guard" 13 $rc
has "$work/ml.out" 'RUNG SETTINGS r-1' && pass "11c the settings were recorded for the step" || fail "11c no RUNG SETTINGS line"

for case_ in "nginx 599s:php=0 pool=0s nginx=599s" "pool 300s:php=0 pool=300s nginx=600s" "PHP 5:php=5 pool=0s nginx=600s" "unreadable probe:ini_fail=1 php=0 pool=0s nginx=600s"; do
  label="${case_%%:*}"; rest="${case_#*:}"
  # shellcheck disable=SC2086
  set_lab $rest scale=200000   # r-3 is 20 x N_0; the app of the pretend lab reads that scale
  ml --scenario=r-3 --predictions="$pred" --scheme=B --request-timeout=600s; rc=$?
  expect_code "12 r-3, $label: 13" 13 $rc
  [ "$(k6_calls)" = 0 ] && pass "12b r-3, $label: the load tool was never called" || fail "12b r-3, $label: k6 was called"
done

set_lab php=30 pool=0s nginx=120s scale=10000   # a lab at the default is how t-* has always been measured (t-4 is N_0)
ml --scenario=t-4 --predictions="$pred" --scheme=B; rc=$?
[ "$(k6_calls)" -ge 1 ] && pass "13 t-4 on a lab at the default still reaches the load tool (measured as before)" || fail "13 t-4 was stopped ($(head -3 "$work/ml.err" | tr '\n' ' '))"
set_lab ini_fail=1 php=30 pool=0s nginx=120s scale=10000
ml --scenario=t-4 --predictions="$pred" --scheme=B; rc=$?
expect_code "13b t-4 with a limit that cannot be read: still 13" 13 $rc
[ "$(k6_calls)" = 0 ] && pass "13c ... before the load tool" || fail "13c k6 was called"

set_lab php=30 pool=0s nginx=120s
ml --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s
has "$work/ml.err" 'not as r-1 needs' && pass "14 the refusal says the lab is not as r-1 needs it" || fail "14 ($(cat "$work/ml.err"))"
has "$work/ml.err" 'max_execution_time' && has "$work/ml.err" 'fastcgi_read_timeout' && pass "14b it names both limits that were wrong" || fail "14b ($(cat "$work/ml.err"))"
[ -s "$(ls -d "$out"/ladder/r-1-50000/rung-01-*/ | head -1)settings.json" ] && pass "14c the readings that were refused are kept in the step's directory" || fail "14c no settings.json in the step directory"

echo
echo "limits guard tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

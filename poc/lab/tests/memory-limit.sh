#!/usr/bin/env bash
# The container's own memory limit, tried on a real throwaway container (D210; it needs Docker and the lab's PHP image, peak-lab-php:8.3, which
# bin/up builds; the lab itself is not touched). The stand-in docker of write-guards.sh can only say what the counters would be; this makes the
# kernel really stop a process for memory inside a container that has a limit, and reads what bin/check-oom and bin/read-memory then see:
#
# 1. a container made with a 64 MiB limit and the same for swap reports both in docker inspect and in its cgroup's memory.max
# 2. bin/check-oom says OK before anything happens
# 3. a process in it that takes 200 MiB is stopped by the kernel (exit 137) while the container itself goes on running: no exit, no restart,
#    no restart (the case of a php-fpm worker, which the master makes again). Docker marks State.OOMKilled true on such an event too (measured here:
#    it is true although the container never stopped), so check-oom sees the stop by that flag as well as by the cgroup's counter
# 4. bin/check-oom then stops with exit 19, the cgroup's oom_kill went from 0 to 1, and it says where
# 5. bin/read-memory writes the limit, the peak (at the limit) and oom_kill 1, and stops nothing
# 6. the lab's compose file gives the limit to lab-app alone (that part is in write-guards.sh, E10)
# The throwaway container is on no network, has a read-only root and no capability; it is stopped with `docker stop` and removed with `docker rm`.
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"
image="${PEAK_LAB_PHP_IMAGE:-peak-lab-php:8.3}"
name="peak-memtest-$$"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
expect_code() { if [ "$3" -eq "$2" ]; then pass "$1"; else fail "$1 (exit $3, wanted $2)"; fi; }

work="$(mktemp -d)"
cleanup() { docker stop -t 2 "$name" >/dev/null 2>&1; docker rm "$name" >/dev/null 2>&1; rm -rf "$work"; }
trap cleanup EXIT

if ! docker image inspect "$image" >/dev/null 2>&1; then
  fail "0 the image $image is not there (run poc/lab/bin/up once to build it)"
  echo; echo "memory limit tests: $passed passed, $failed failed, $skipped skipped"; exit 1
fi
env_lab() { PEAK_LAB_APP_CONTAINER="$name" PEAK_LAB_DB_CONTAINER="$name" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$work/out" "$@"; }

docker run -d --name "$name" --pull=never --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true \
  --memory 64m --memory-swap 64m -u "$(id -u):$(id -g)" "$image" sleep 600 >"$work/run.out" 2>&1
expect_code "0 the throwaway container starts" 0 $?

mem="$(docker inspect -f '{{.HostConfig.Memory}} {{.HostConfig.MemorySwap}}' "$name" 2>/dev/null)"
cgmax="$(docker exec "$name" cat /sys/fs/cgroup/memory.max 2>/dev/null)"
[ "$mem" = "67108864 67108864" ] && [ "$cgmax" = 67108864 ] && pass "1 docker inspect says Memory and MemorySwap 67108864 (the same), and the cgroup's memory.max is 67108864" || fail "1 ($mem / $cgmax)"

env_lab "$lab/bin/check-oom" save "$work/base.txt" >/dev/null 2>&1
env_lab "$lab/bin/check-oom" compare "$work/base.txt" >"$work/c1.out" 2>&1; expect_code "2 check-oom before anything happens: OK (0)" 0 $?

docker exec "$name" php -d memory_limit=-1 -r '$a = str_repeat("x", 200 * 1024 * 1024); echo "survived";' >"$work/hog.out" 2>&1; rc=$?
state="$(docker inspect -f '{{.State.Running}} {{.RestartCount}}' "$name" 2>/dev/null)"
oomflag="$(docker inspect -f '{{.State.OOMKilled}}' "$name" 2>/dev/null)"
[ "$rc" = 137 ] && ! grep -q survived "$work/hog.out" && [ "$state" = "true 0" ] && pass "3 a process that takes 200 MiB is stopped by the kernel (137) and the container goes on: running, no restart (State.OOMKilled is $oomflag)" || fail "3 (rc $rc, state '$state', $(head -c 200 "$work/hog.out"))"

env_lab "$lab/bin/check-oom" compare "$work/base.txt" --where="the test step" >"$work/c2.out" 2>"$work/c2.err"; rc=$?
expect_code "4 check-oom after it: stopped (19)" 19 $rc
grep -q 'cgroup_oom_kill=0' "$work/c2.err" && grep -q 'cgroup_oom_kill=[1-9]' "$work/c2.err" && grep -q 'STOPPED at the test step' "$work/c2.err" && pass "4b the message says where, and that oom_kill went from 0 to a number above 0" || fail "4b ($(cat "$work/c2.err"))"

env_lab "$lab/bin/read-memory" "$work/mem.txt" --label="after the kill" >"$work/m.out" 2>&1; rc=$?
expect_code "5 read-memory after it: 0 (a record stops nothing)" 0 $rc
peak="$(sed -n 's/.*app_memory_peak=\([0-9]*\).*/\1/p' "$work/mem.txt")"
grep -q 'app_memory_max=67108864 ' "$work/mem.txt" && grep -q 'app_oom_kill=[1-9]' "$work/mem.txt" && [ -n "$peak" ] && [ "$peak" -ge 60000000 ] && [ "$peak" -le 67108864 ] \
  && pass "5b it holds the limit 67108864, the peak at the limit ($peak) and app_oom_kill above 0" || fail "5b ($(cat "$work/mem.txt"))"

echo
echo "memory limit tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

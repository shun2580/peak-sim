#!/usr/bin/env bash
# Tests of the B measuring chain and of B's settings (decisions.md D227): `bin/measure-ladder-chain` (the stop at the mark between two
# scenarios), `bin/lab-config-b` (the diff that makes B and takes it off) and the request timeout of 1,800 s on its way to the load tool,
# to nginx and to the wait at the end of a step. They never touch the lab and never touch a database: the `docker` on PATH is a stand-in
# that answers the reads and records every other call, the PHP tools run in throw-away containers (no network), and k6 is only asked to
# print its own options (`k6 inspect`, no request is sent).
#
#   poc/lab/tests/ladder-chain.sh
#
# A  the chain, with every tool it calls replaced by a recorder (the chain itself is the real script):
#    1  no mark: s-1 .. s-5 run in order, each after a recreation and a read of the limits; exit 0; chain.done; no chain.stopped
#    2  the request timeout reaches check-limits and measure-ladder: 1800s by default; 600s when given (the chain of the fourth round)
#    3  a mark there from the start: no scenario is entered and nothing is recreated; the memory, the statistics and the logs are read;
#       the stop is written to chain.log and chain.stopped; exit 0
#    4  a mark made while s-2 runs: s-2 is finished and read after, s-3 is not entered and not recreated; chain.stopped lists s-3 s-4 s-5
#    5  a mark at another path (--stop-mark) is the one that counts; the default STOP is not looked at then
#    6  after a stop, a start with the scenarios of chain.stopped goes on from s-3 (the mark removed)
#    7  a scenario that ends non-zero stops the chain with its exit (13); 19 keeps the state of the containers; nothing after it runs
#    8  a recreation that fails stops it (use-db: 11; the limits not as B needs: 13) before any measurement
#    9  usage errors (2) touch nothing; the chain contains no kill
#   16  the chain measures with --require-allocation by default (every scenario), --allow-no-allocation leaves it out and chain.log says so; a refusal
#       of measure-ladder (2) stops the chain with 2
# B  the real measure-ladder and check-limits behind a stand-in docker, B's settings taken from the diff itself:
#   10  the lab left at the default, or at the 600 s of the fourth round, with 1800s asked: 13 before the load tool
#   11  the lab as the diff makes it, with 1800s asked: the load tool is called with REQUEST_TIMEOUT=1800s, and the step's settings say 1800s
#       for nginx and for the load tool
#   12  without the flag nothing changed: 600s asked on a 600 s lab still reaches the load tool with REQUEST_TIMEOUT=600s; a scenario with no
#       --request-timeout is refused (2) as before
# C  the wait at the end of a step: the real k6 (`inspect`) gives gracefulStop 30m0s for 1800s, 10m0s for 600s
# E  the allocation (D226, D227): the real measure-ladder reads the prediction's allocation and sends that, not the mix:
#   13  a prediction with an allocation: the plan sends 7 routes at the shares of rps_after and the dropped route is not sent; the ALLOCATION line is
#       printed and allocation.json is kept; a prediction with none then removes the stale allocation.json
#   14  a prediction with none: the plan has all 8 routes, the mix's shares, no `allocation` key; make-plan's output is byte for byte what it was before the
#       option existed (hashes taken before the change, 16 plans over the 8 mixes)
#   15  a prediction with half an allocation, a dropped route that has an rps, stages that disagree: exit 2 before any container is touched
#   17  --require-allocation (D227): a prediction with an allocation, even one that drops no route, is measured; one with none (the gate passes it as made
#       without the flag), or with one that is switched off, is refused (2) before any container; without the flag nothing changed; it needs --predictions
#       and refuses --trial
#   18  --max-steps (D231; a setting for a rehearsal, default off): the chain hands it to every measure-ladder call and says so in chain.log, and without it
#       nothing is carried or written; measure-ladder ends a scenario after N steps (counting the recorded ones: the load tool is not called for them), and
#       refuses a value that is not 1 to 99, and --trial, before any container
# D  bin/lab-config-b in a throw-away repository: status; apply gives 1800s, max_execution_time 0, memory_limit 4G; a second apply and a
#    restore that is not exactly the diff are refused and change nothing; restore gives back the bytes; the diff applies to the files of HEAD
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"
repo_real="$(cd "$lab/../.." && pwd)"
php_image="${PEAK_DEV_IMAGE:-peak-sim-php-dev:8.3}"
k6_image="grafana/k6:${K6_VERSION:-2.3.0}"
cpuset="${PEAK_DEV_CPUSET:-12-15}"
real_docker="$(command -v docker)"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
expect_code() { if [ "$3" -eq "$2" ]; then pass "$1"; else fail "$1 (exit $3, wanted $2)"; fi; }
has() { grep -q -- "$2" "$1"; }
check() { local label="$1"; shift; if "$@"; then pass "$label"; else fail "$label"; fi; }

[ -n "$real_docker" ] || { echo "ladder-chain: docker is needed" >&2; exit 2; }
for image in "$php_image" "$k6_image"; do
  "$real_docker" image inspect "$image" >/dev/null 2>&1 || { echo "ladder-chain: the image $image is missing" >&2; exit 2; }
done

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
mkdir -p "$work/state" "$work/out" "$work/fakebin"
out="$(cd "$work/out" && pwd)"
# a count the lab has not used for anything: only a made-up repository and a stand-in docker ever see it
SCALE=120000

# ================================================================================================================================
# A. the chain with recorders
# ================================================================================================================================
A="$work/a"; mkdir -p "$A/bin" "$A/s"
cp "$lab/bin/measure-ladder-chain" "$lab/bin/_lab.sh" "$A/bin/"
for t in read-memory read-table-stats check-oom use-db check-limits measure-ladder; do
  cat >"$A/bin/$t" <<STUB
#!/usr/bin/env bash
echo "$t \$*" >>"$A/calls"
STUB
  chmod +x "$A/bin/$t"
done
# the stand-in for the scenario: the exit code is in s/rc-<scenario> (default 0); s/mark-during-<scenario> holds the path of a mark to make while it runs
cat >"$A/bin/measure-ladder" <<STUB
#!/usr/bin/env bash
echo "measure-ladder \$*" >>"$A/calls"
sc=""; for a in "\$@"; do case "\$a" in --scenario=*) sc="\${a#--scenario=}" ;; esac; done
[ -f "$A/s/mark-during-\$sc" ] && touch "\$(cat "$A/s/mark-during-\$sc")"
echo "stand-in output of \$sc"
[ -f "$A/s/rc-\$sc" ] && exit "\$(cat "$A/s/rc-\$sc")"
exit 0
STUB
printf '#!/usr/bin/env bash\necho "use-db $*" >>"%s/calls"\n[ -f "%s/s/usedb_fail" ] && exit 1\nexit 0\n' "$A" "$A" >"$A/bin/use-db"
printf '#!/usr/bin/env bash\necho "check-limits $*" >>"%s/calls"\n[ -f "%s/s/limits_fail" ] && exit 13\nexit 0\n' "$A" "$A" >"$A/bin/check-limits"
chmod +x "$A/bin/use-db" "$A/bin/check-limits"
cat >"$work/fakebin/docker" <<FAKE
#!/usr/bin/env bash
echo "docker \$*" >>"$work/docker.calls"
case "\$1" in
  logs) echo "log of \$*"; exit 0 ;;
  inspect) echo "inspect \$*"; exit 0 ;;
esac
exit 0
FAKE
chmod +x "$work/fakebin/docker"
# a repository for the chain's `git diff -- poc/lab/compose`
arepo="$work/arepo"; mkdir -p "$arepo/poc/lab/compose"; echo x >"$arepo/poc/lab/compose/f"
git -C "$arepo" init -q . && git -C "$arepo" config user.email t@example.invalid && git -C "$arepo" config user.name t
git -C "$arepo" add -A && git -C "$arepo" commit -q -m "chore: seed"
printf '{}' >"$work/pred.json"; printf '{}' >"$work/rec.json"

n=0
chain_dir() { n=$((n + 1)); d="$out/chain-$n"; }   # not run in a subshell: the counter must advance
reset_a() { rm -f "$A"/s/*; : >"$A/calls"; }
run_chain() {  # run_chain <out-dir> [extra args...]
  local d="$1"; shift
  PATH="$work/fakebin:$PATH" PEAK_REPO_ROOT="$arepo" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" \
    "$A/bin/measure-ladder-chain" --scale=$SCALE --predictions="$work/pred.json" --statement-record="$work/rec.json" --out-dir="$d" "$@" >"$work/chain.out" 2>"$work/chain.err"
}
calls_of() { grep -c "^$1 " "$A/calls"; }
order_of() {  # the scenarios measured, in the order of the calls
  grep '^measure-ladder ' "$A/calls" | sed -n 's/.*--scenario=\(s-[0-9]\).*/\1/p' | tr '\n' ' ' | sed 's/ $//'
}

# 1 --------------------------------------------------------------------------------------------------------------------
reset_a; chain_dir; run_chain "$d"; rc=$?
expect_code "1 no mark: the chain exits 0" 0 $rc
[ "$(order_of)" = "s-1 s-2 s-3 s-4 s-5" ] && pass "1b the five scenarios were measured in order" || fail "1b order was '$(order_of)'"
[ "$(calls_of use-db)" = 5 ] && [ "$(calls_of check-limits)" = 5 ] && pass "1c every scenario was preceded by a recreation and a read of the limits" || fail "1c use-db $(calls_of use-db), check-limits $(calls_of check-limits)"
# the recreation comes before the scenario it is for: the call list reads use-db, check-limits, ..., measure-ladder per scenario
python3 - "$A/calls" <<'PY' && pass "1d for each scenario: use-db, then check-limits, then measure-ladder" || fail "1d the order of use-db, check-limits and measure-ladder is wrong"
import sys
seq = [l.split()[0] for l in open(sys.argv[1]) if l.split()[0] in ("use-db", "check-limits", "measure-ladder")]
sys.exit(0 if seq == ["use-db", "check-limits", "measure-ladder"] * 5 else 1)
PY
[ -e "$d/chain.done" ] && [ ! -e "$d/chain.stopped" ] && has "$d/chain.log" 'CHAIN DONE' && pass "1e chain.done and CHAIN DONE; no chain.stopped" || fail "1e"
for s in s-1 s-5; do [ -s "$d/$s.output.txt" ] || fail "1f no output file of $s"; done; pass "1f the output of a scenario is kept as <scenario>.output.txt"
[ "$(grep -c '^read-table-stats' "$A/calls")" = 10 ] && pass "1g the table statistics were read before and after each scenario (10 readings)" || fail "1g $(grep -c '^read-table-stats' "$A/calls") readings of the table statistics"
[ -e "$d/logs/app-after-s-5.txt" ] && [ -e "$d/logs/web-after-s-1.txt" ] && [ -e "$d/logs/app-before-recreate-s-1.txt" ] && pass "1h the app and web logs were copied (before the first recreation and after every scenario)" || fail "1h a log copy is missing"

# 2 --------------------------------------------------------------------------------------------------------------------
[ "$(grep '^check-limits ' "$A/calls" | grep -c -- '--require=unlimited --request-timeout=1800s')" = 5 ] && pass "2 the limits are read with --request-timeout=1800s (the default) before each scenario" || fail "2 $(grep '^check-limits ' "$A/calls" | head -2)"
[ "$(grep '^measure-ladder ' "$A/calls" | grep -c -- '--scheme=B --request-timeout=1800s')" = 5 ] && pass "2b measure-ladder gets --scheme=B --request-timeout=1800s for each scenario" || fail "2b $(grep '^measure-ladder ' "$A/calls" | head -1)"
grep '^measure-ladder ' "$A/calls" | head -1 | grep -q -- "--scale=$SCALE --predictions=$work/pred.json --statement-record=$work/rec.json" && pass "2c it also gets the scale, the prediction and the statement record" || fail "2c $(grep '^measure-ladder ' "$A/calls" | head -1)"
reset_a; chain_dir; run_chain "$d" --request-timeout=600s --scenarios="s-1 s-2"
[ "$(grep -c -- '--request-timeout=600s' "$A/calls")" = 4 ] && ! grep -q -- '1800s' "$A/calls" && pass "2d --request-timeout=600s reaches both tools and 1800s is nowhere (the chain of the fourth round)" || fail "2d $(cat "$A/calls" | head -4)"

# 3 --------------------------------------------------------------------------------------------------------------------
reset_a; chain_dir; mkdir -p "$d"; touch "$d/STOP"; run_chain "$d"; rc=$?
expect_code "3 a mark from the start: exit 0" 0 $rc
[ "$(calls_of measure-ladder)" = 0 ] && [ "$(calls_of use-db)" = 0 ] && [ "$(calls_of check-limits)" = 0 ] && pass "3b no scenario was entered and nothing was recreated" || fail "3b the chain went on ($(calls_of measure-ladder) measure-ladder, $(calls_of use-db) use-db)"
grep -q -- '^read-memory .*--label=stop-before-s-1' "$A/calls" && grep -q '^read-table-stats .* stop-before-s-1' "$A/calls" && pass "3c the memory and the table statistics were read at the stop" || fail "3c"
[ -e "$d/logs/app-stop-before-s-1.txt" ] && [ -e "$d/logs/web-stop-before-s-1.txt" ] && pass "3d the app and web logs were copied at the stop" || fail "3d"
has "$d/chain.log" 'CHAIN STOP at mark before s-1' && pass "3e chain.log says CHAIN STOP at mark before s-1" || fail "3e $(cat "$d/chain.log")"
has "$d/chain.stopped" 'before=s-1 remaining="s-1 s-2 s-3 s-4 s-5"' && [ -e "$d/chain.done" ] && pass "3f chain.stopped lists the five that were not run; chain.done" || fail "3f $(cat "$d/chain.stopped" 2>&1)"

# 4 --------------------------------------------------------------------------------------------------------------------
reset_a; chain_dir; mkdir -p "$d"; printf '%s' "$d/STOP" >"$A/s/mark-during-s-2"; run_chain "$d"; rc=$?
expect_code "4 a mark made while s-2 runs: exit 0" 0 $rc
[ "$(order_of)" = "s-1 s-2" ] && pass "4b s-1 and s-2 were measured, s-3 .. s-5 were not entered" || fail "4b order was '$(order_of)'"
[ "$(calls_of use-db)" = 2 ] && pass "4c nothing was recreated for s-3 (two recreations, for s-1 and s-2)" || fail "4c $(calls_of use-db) recreations"
has "$d/chain.log" 'END s-2 exit=0' && grep -q -- '--label=after-s-2' "$A/calls" && [ -e "$d/logs/app-after-s-2.txt" ] && pass "4d s-2 was finished and read after (END s-2, memory, logs)" || fail "4d"
has "$d/chain.stopped" 'before=s-3 remaining="s-3 s-4 s-5"' && has "$d/chain.log" 'CHAIN STOP at mark before s-3' && pass "4e the stop is before s-3 and chain.stopped lists s-3 s-4 s-5" || fail "4e $(cat "$d/chain.stopped" 2>&1)"

# 5 --------------------------------------------------------------------------------------------------------------------
reset_a; chain_dir; mkdir -p "$d"; touch "$d/STOP"; run_chain "$d" --stop-mark="$out/elsewhere-mark" --scenarios="s-1 s-2"; rc=$?
[ "$rc" = 0 ] && [ "$(order_of)" = "s-1 s-2" ] && pass "5 with --stop-mark elsewhere the default STOP is not looked at" || fail "5 rc $rc, order '$(order_of)'"
reset_a; chain_dir; touch "$out/elsewhere-mark"; run_chain "$d" --stop-mark="$out/elsewhere-mark"; rc=$?
[ "$rc" = 0 ] && [ "$(calls_of measure-ladder)" = 0 ] && has "$d/chain.log" "elsewhere-mark exists" && pass "5b the mark at the given path stops it, and the log names the path" || fail "5b"
rm -f "$out/elsewhere-mark"

# 6 --------------------------------------------------------------------------------------------------------------------
reset_a; chain_dir; mkdir -p "$d"; printf '%s' "$d/STOP" >"$A/s/mark-during-s-2"; run_chain "$d"
left="$(sed -n 's/.*remaining="\([^"]*\)".*/\1/p' "$d/chain.stopped")"
rm -f "$d/STOP" "$A/s/mark-during-s-2" "$d/chain.done"; : >"$A/calls"
run_chain "$d" --scenarios="$left"; rc=$?
[ "$rc" = 0 ] && [ "$(order_of)" = "s-3 s-4 s-5" ] && pass "6 started again with chain.stopped's scenarios, the chain goes on from s-3 to s-5" || fail "6 rc $rc, order '$(order_of)' (left '$left')"

# 7 --------------------------------------------------------------------------------------------------------------------
reset_a; chain_dir; printf '13' >"$A/s/rc-s-2"; run_chain "$d"; rc=$?
expect_code "7 s-2 ends with 13: the chain exits 13" 13 $rc
[ "$(order_of)" = "s-1 s-2" ] && has "$d/chain.log" 'CHAIN STOP at s-2 exit=13' && [ -e "$d/chain.done" ] && pass "7b s-3 .. s-5 did not run; the log says why; chain.done" || fail "7b order '$(order_of)'"
[ ! -e "$d/oom-stop-s-2" ] && pass "7c an exit other than 19 keeps no oom-stop directory" || fail "7c"
reset_a; chain_dir; printf '19' >"$A/s/rc-s-1"; run_chain "$d"; rc=$?
expect_code "7d s-1 ends with 19 (memory): the chain exits 19" 19 $rc
[ -f "$d/oom-stop-s-1/inspect-app.txt" ] && [ -f "$d/oom-stop-s-1/app-logs-tail.txt" ] && pass "7e the state of the containers and the tail of the app log are kept for 19" || fail "7e"
[ "$(order_of)" = "s-1" ] && pass "7f nothing after it ran" || fail "7f order '$(order_of)'"

# 8 --------------------------------------------------------------------------------------------------------------------
reset_a; chain_dir; touch "$A/s/usedb_fail"; run_chain "$d"; rc=$?
expect_code "8 use-db fails: 11" 11 $rc
[ "$(calls_of measure-ladder)" = 0 ] && [ -e "$d/chain.done" ] && pass "8b nothing was measured; chain.done" || fail "8b"
reset_a; chain_dir; touch "$A/s/limits_fail"; run_chain "$d"; rc=$?
expect_code "8c the limits are not as B needs after the recreation: 13" 13 $rc
[ "$(calls_of measure-ladder)" = 0 ] && has "$d/chain.log" 'check-limits failed before s-1' && pass "8d nothing was measured; the log says it was the limits" || fail "8d"

# 9 --------------------------------------------------------------------------------------------------------------------
reset_a; chain_dir
PATH="$work/fakebin:$PATH" PEAK_REPO_ROOT="$arepo" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" "$A/bin/measure-ladder-chain" --scale=$SCALE >/dev/null 2>&1; expect_code "9 missing arguments: usage, 2" 2 $?
run_chain "$d" --request-timeout=1800; expect_code "9b a timeout without a unit: 2" 2 $?
run_chain "$d" --scenarios="s-1 t-1"; expect_code "9c a scenario that is not s-1 .. s-5: 2" 2 $?
run_chain "$work/not-in-out"; expect_code "9d an --out-dir outside the lab's out directory: 2" 2 $?
run_chain "$d" --bogus; expect_code "9e an unknown option: 2" 2 $?
[ ! -s "$A/calls" ] && pass "9f none of them called any tool" || fail "9f $(head -2 "$A/calls")"
if grep -Eq '(^|[^A-Za-z_])(kill|pkill|killall)([^A-Za-z_]|$)' <(grep -v '^#' "$lab/bin/measure-ladder-chain" | grep -v 'oom_kill'); then fail "9g the chain's code contains a kill"; else pass "9g the chain's code contains no kill (the stop is the mark)"; fi

# 16 -------------------------------------------------------------------------------------------------------------------------
reset_a; chain_dir; run_chain "$d"
[ "$(grep '^measure-ladder ' "$A/calls" | grep -c -- ' --require-allocation')" = 5 ] && has "$d/chain.log" 'require_allocation=1' && ! has "$d/chain.log" 'NOTE --allow-no-allocation' \
  && pass "16 by default every scenario is measured with --require-allocation (5 of 5), and chain.log says require_allocation=1" || fail "16 $(grep '^measure-ladder ' "$A/calls" | head -1)"
reset_a; chain_dir; run_chain "$d" --allow-no-allocation --scenarios="s-1 s-2"
[ "$(calls_of measure-ladder)" = 2 ] && ! grep '^measure-ladder ' "$A/calls" | grep -q -- '--require-allocation' && has "$d/chain.log" 'NOTE --allow-no-allocation' \
  && pass "16b --allow-no-allocation leaves the flag out, and chain.log says so" || fail "16b"
reset_a; chain_dir; printf '2' >"$A/s/rc-s-1"; run_chain "$d"; rc=$?
expect_code "16c measure-ladder refusing the prediction (2) stops the chain with 2" 2 $rc
[ "$(order_of)" = "s-1" ] && pass "16d nothing after it ran" || fail "16d order '$(order_of)'"

# ================================================================================================================================
# D (first: B's values are read from the diff for B and C)
# ================================================================================================================================
Drepo="$work/drepo"; mkdir -p "$Drepo/poc/lab/compose/nginx" "$Drepo/poc/lab/compose/php"
git -C "$repo_real" show HEAD:poc/lab/compose/nginx/lab.conf >"$Drepo/poc/lab/compose/nginx/lab.conf"
git -C "$repo_real" show HEAD:poc/lab/compose/php/zz-lab.ini >"$Drepo/poc/lab/compose/php/zz-lab.ini"
git -C "$Drepo" init -q . && git -C "$Drepo" config user.email t@example.invalid && git -C "$Drepo" config user.name t
git -C "$Drepo" add -A && git -C "$Drepo" commit -q -m "chore: seed"
cfgb() { PEAK_REPO_ROOT="$Drepo" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" "$lab/bin/lab-config-b" "$@" >"$work/cfg.out" 2>"$work/cfg.err"; }
sha_files() { (cd "$Drepo" && sha256sum poc/lab/compose/nginx/lab.conf poc/lab/compose/php/zz-lab.ini); }
orig_sha="$(sha_files)"
cfgb status; has "$work/cfg.out" 'STATE default' && pass "D1 status on the default files says default" || fail "D1 $(cat "$work/cfg.out")"
cfgb apply; expect_code "D2 apply: 0" 0 $?
cfgb status; has "$work/cfg.out" 'STATE B' && pass "D2b status then says B" || fail "D2b $(cat "$work/cfg.out")"
nginx_val="$(sed -n 's/^ *fastcgi_read_timeout \([^;]*\);.*/\1/p' "$Drepo/poc/lab/compose/nginx/lab.conf")"
php_val="$(sed -n 's/^max_execution_time=\(.*\)$/\1/p' "$Drepo/poc/lab/compose/php/zz-lab.ini" | tail -1)"
mem_val="$(sed -n 's/^memory_limit=\(.*\)$/\1/p' "$Drepo/poc/lab/compose/php/zz-lab.ini" | tail -1)"
[ "$nginx_val" = 1800s ] && [ "$php_val" = 0 ] && [ "$mem_val" = 4G ] && pass "D3 with B on the files say nginx fastcgi_read_timeout 1800s, max_execution_time 0, memory_limit 4G" || fail "D3 nginx '$nginx_val' php '$php_val' mem '$mem_val'"
on_sha="$(sha_files)"
cfgb apply; expect_code "D4 a second apply is refused (2)" 2 $?
[ "$(sha_files)" = "$on_sha" ] && pass "D4b ... and changes nothing" || fail "D4b the files changed"
cfgb restore; expect_code "D5 restore: 0" 0 $?
[ "$(sha_files)" = "$orig_sha" ] && git -C "$Drepo" diff --quiet && pass "D5b the files are byte for byte what they were, and git sees no change" || fail "D5b"
cfgb restore; expect_code "D6 restore on the default files is refused (2)" 2 $?
echo '; an edit nobody asked for' >>"$Drepo/poc/lab/compose/php/zz-lab.ini"; edited_sha="$(sha_files)"
cfgb apply; expect_code "D7 apply on compose files that already differ from HEAD is refused (2)" 2 $?
[ "$(sha_files)" = "$edited_sha" ] && pass "D7b ... and the files are as they were" || fail "D7b"
git -C "$Drepo" checkout -q -- poc/lab/compose/php/zz-lab.ini   # the throw-away repository only
cfgb apply; echo '; another edit' >>"$Drepo/poc/lab/compose/php/zz-lab.ini"; edited_sha="$(sha_files)"
cfgb restore; expect_code "D8 restore when the files are not exactly the default plus the diff is refused (2)" 2 $?
[ "$(sha_files)" = "$edited_sha" ] && pass "D8b ... and the files are as they were" || fail "D8b"
git -C "$Drepo" checkout -q -- poc/lab/compose/php/zz-lab.ini poc/lab/compose/nginx/lab.conf
cfgb frobnicate; expect_code "D9 an unknown command: usage, 2" 2 $?
git -C "$repo_real" diff --quiet -- poc/lab/compose && pass "D10 the lab's own compose files in this repository were never touched by the test" || fail "D10 the compose files of the repository differ from HEAD"

# ================================================================================================================================
# B. the real measure-ladder and check-limits behind a stand-in docker; B's values are the ones the diff made
# ================================================================================================================================
Bdir="$work/b"; mkdir -p "$Bdir/lab/bin" "$Bdir/lab/observe" "$Bdir/lab/routes" "$Bdir/s" "$Bdir/fakebin"
for f in measure-ladder check-limits check-writes check-tables _lab.sh prediction-gate check-oom read-memory; do cp "$lab/bin/$f" "$Bdir/lab/bin/$f"; done
cp "$lab/routes/route-list.json" "$Bdir/lab/routes/route-list.json"
mkdir -p "$Bdir/rec"; python3 "$here/make-statement-record.py" "$lab/routes/route-list.json" "$Bdir/rec/ok.json" --variant=ok
printf '#!/usr/bin/env bash\nexit 0\n' >"$Bdir/lab/bin/preflight"; chmod +x "$Bdir/lab/bin/preflight"
printf '#!/usr/bin/env bash\nexit 0\n' >"$Bdir/lab/observe/sample-cpu"; chmod +x "$Bdir/lab/observe/sample-cpu"
cat >"$Bdir/fakebin/docker" <<FAKE
#!/usr/bin/env bash
w="$Bdir"; s="\$w/s"
echo "\$*" >>"\$w/docker.calls"
val() { if [ -f "\$s/\$1" ]; then cat "\$s/\$1"; else echo "\$2"; fi; }
if [ "\$1" = inspect ]; then
  case "\$*" in
    *State.OOMKilled*) echo "false 0 2026-11-16T00:00:00Z"; exit 0 ;;
    *) echo "PEAK_LAB_SCALE=\$(val scale $SCALE)"; exit 0 ;;
  esac
fi
if [ "\$1" = exec ]; then
  shift
  while [ "\${1:-}" = "-i" ] || [ "\${1:-}" = "-e" ]; do if [ "\$1" = "-e" ]; then shift 2; else shift; fi; done
  c="\$1"; shift
  if [ "\$c" = peak-lab-db ] && [ "\$1" = mysql ]; then
    case "\$*" in
      *CHECKSUM*) printf '10000\ndb.t\t111\n'; exit 0 ;;
      *performance_schema*) d="\$(printf '%s' "\$*" | sed -n "s/.*object_schema = '\([^']*\)'.*/\1/p")"; printf '%s\tentities\t0\t0\t0\n%s\tviews\t1\t0\t0\n' "\$d" "\$d"; exit 0 ;;
    esac
  fi
  case "\$c \$1 \$2" in
    "peak-lab-web nginx -T")
      printf 'http {\n    server {\n        location / {\n            fastcgi_pass lab_app;\n            fastcgi_read_timeout %s;\n        }\n    }\n}\n' "\$(val nginx 120s)"; exit 0 ;;
    "peak-lab-app cat /sys/fs/cgroup/memory.events")
      printf 'low 0\nhigh 0\nmax 0\noom 0\noom_kill 0\noom_group_kill 0\n'; exit 0 ;;
    "peak-lab-app php-fpm -tt")
      printf '[11-Nov-2026 10:00:00] NOTICE: \trequest_terminate_timeout = %s\n' "\$(val pool 0s)"; exit 0 ;;
  esac
  if [ "\$c" = peak-lab-tools ] && [ "\$1" = php ] && [ "\$2" = /peak-sim/poc/lab/diagnosis/fcgi_get.php ]; then
    printf '{"sapi":"fpm-fcgi","php":"8.3.36","max_execution_time":"%s","memory_limit":"%s"}\n' "\$(val php 30)" "\$(val mem 512M)"; exit 0
  fi
  if [ "\$c" = peak-lab-tools ] && [ "\$1" = php ]; then
    shift
    exec "$real_docker" run --rm -i --pull=never --network none --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$repo_real:/peak-sim:ro" -v "$out:/out" "$php_image" php "\$@"
  fi
  if [ "\$c" = peak-lab-k6 ]; then exit 99; fi
fi
exit 99
FAKE
chmod +x "$Bdir/fakebin/docker"
brepo="$Bdir/repo"; mkdir -p "$brepo/poc/lab/predictions"
git -C "$brepo" init -q . && git -C "$brepo" config user.email t@example.invalid && git -C "$brepo" config user.name t
python3 -c 'import json,sys; n=lambda c:{"entities":c,"images":c,"references":c}; json.dump({"scenarios":[{"id":"s-1","tables":n(int(sys.argv[2]))}]}, open(sys.argv[1],"w"))' "$brepo/poc/lab/predictions/p.json" "$SCALE"
git -C "$brepo" add -A && git -C "$brepo" commit -q -m "chore: seed"
echo '{}' >"$out/targets-$SCALE.json"
bpred="$brepo/poc/lab/predictions/p.json"
set_b() { rm -f "$Bdir"/s/*; : >"$Bdir/docker.calls"; local kv; for kv in "$@"; do printf '%s' "${kv#*=}" >"$Bdir/s/${kv%%=*}"; done; }
ml_b() { PATH="$Bdir/fakebin:$PATH" PEAK_REPO_ROOT="$brepo" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" \
  "$Bdir/lab/bin/measure-ladder" "$@" --statement-record="$Bdir/rec/ok.json" >"$Bdir/ml.out" 2>"$Bdir/ml.err"; }
k6_calls() { grep -c 'peak-lab-k6' "$Bdir/docker.calls"; }

# 10 -------------------------------------------------------------------------------------------------------------------------
set_b php=30 pool=0s nginx=120s mem=512M
ml_b --scenario=s-1 --predictions="$bpred" --scheme=B --request-timeout=1800s; rc=$?
[ "$rc" = 13 ] && [ "$(k6_calls)" = 0 ] && pass "10 the lab at the default, 1800s asked: 13, the load tool never called" || fail "10 rc $rc, k6 calls $(k6_calls)"
set_b php=0 pool=0s nginx=600s mem=4G
ml_b --scenario=s-1 --predictions="$bpred" --scheme=B --request-timeout=1800s; rc=$?
[ "$rc" = 13 ] && [ "$(k6_calls)" = 0 ] && has "$Bdir/ml.err" 'fastcgi_read_timeout' && pass "10b the lab left at the fourth round's 600s, 1800s asked: 13 naming fastcgi_read_timeout, before the load tool" || fail "10b rc $rc ($(head -2 "$Bdir/ml.err"))"

# 11 -------------------------------------------------------------------------------------------------------------------------
set_b php="$php_val" pool=0s nginx="$nginx_val" mem="$mem_val"
ml_b --scenario=s-1 --predictions="$bpred" --scheme=B --request-timeout=1800s; rc=$?
[ "$(k6_calls)" -ge 1 ] && grep 'peak-lab-k6' "$Bdir/docker.calls" | head -1 | grep -q -- '-e REQUEST_TIMEOUT=1800s' && pass "11 the lab as the diff makes it: the load tool is called with REQUEST_TIMEOUT=1800s" || fail "11 k6 calls $(k6_calls) ($(head -2 "$Bdir/ml.err"))"
has "$Bdir/ml.out" '"nginx_fastcgi_read_timeout": "1800s"' && has "$Bdir/ml.out" '"k6_request_timeout": "1800s"' && has "$Bdir/ml.out" '"php_max_execution_time": "0"' && has "$Bdir/ml.out" '"php_memory_limit": "4G"' \
  && pass "11b the step's settings say nginx 1800s, k6 1800s, max_execution_time 0, memory_limit 4G" || fail "11b $(grep 'RUNG SETTINGS' "$Bdir/ml.out")"

# 12 -------------------------------------------------------------------------------------------------------------------------
set_b php=0 pool=0s nginx=600s mem=4G
ml_b --scenario=s-1 --predictions="$bpred" --scheme=B --request-timeout=600s; rc=$?
[ "$(k6_calls)" -ge 1 ] && grep 'peak-lab-k6' "$Bdir/docker.calls" | head -1 | grep -q -- '-e REQUEST_TIMEOUT=600s' && pass "12 the fourth round's 600s on a 600s lab still reaches the load tool with REQUEST_TIMEOUT=600s (nothing changed without the flag)" || fail "12 k6 calls $(k6_calls) ($(head -2 "$Bdir/ml.err"))"
ml_b --scenario=s-1 --predictions="$bpred" --scheme=B; rc=$?
expect_code "12b s-1 with no --request-timeout is refused as before (2)" 2 $rc
has "$Bdir/ml.err" 'needs --request-timeout' && pass "12c ... and says why" || fail "12c ($(head -2 "$Bdir/ml.err"))"

# ================================================================================================================================
# C. the wait at the end of a step, from the real k6
# ================================================================================================================================
ck="$work/c"; mkdir -p "$ck"
cat >"$ck/list.json" <<'JSON'
{"selected":[{"route":"GET /fast","rank":1},{"route":"GET /fast2","rank":2}]}
JSON
cat >"$ck/targets.json" <<'JSON'
{"base_url":"http://127.0.0.1:8080","routes":{"GET /fast":{"path":"/fast"},"GET /fast2":{"path":"/fast2"}}}
JSON
chmod -R a+rX "$ck"
"$real_docker" run --rm --pull=never --network none --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$repo_real:/peak-sim:ro" -v "$ck:/w" "$php_image" \
  php /peak-sim/poc/lab/load/make-plan.php --routes=/w/list.json --mix=even --rps=2 --duration=30 --warmup=4 >"$ck/plan.json" || { echo "ladder-chain: make-plan failed" >&2; exit 2; }
k6_graceful() {  # k6_graceful <REQUEST_TIMEOUT or "-">: the gracefulStop values k6 reads from the script
  local env=(); [ "$1" = "-" ] || env=(-e "REQUEST_TIMEOUT=$1")
  "$real_docker" run --rm --pull=never --network none --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$ck:/w" -v "$lab/load:/load:ro" "$k6_image" \
    inspect -e PLAN=/w/plan.json -e TARGETS=/w/targets.json -e SUMMARY=/w/s.json "${env[@]}" /load/scenario.js 2>&1 | sed -n 's/.*"gracefulStop": "\([^"]*\)".*/\1/p' | sort -u | tr '\n' ' ' | sed 's/ $//'
}
[ "$(k6_graceful 1800s)" = "30m0s" ] && pass "C1 REQUEST_TIMEOUT=1800s: every stream waits 30m0s at the end of a step (gracefulStop)" || fail "C1 got '$(k6_graceful 1800s)'"
[ "$(k6_graceful 600s)" = "10m0s" ] && pass "C2 REQUEST_TIMEOUT=600s: 10m0s, as in the fourth round" || fail "C2 got '$(k6_graceful 600s)'"
[ -z "$(k6_graceful -)" ] && pass "C3 no REQUEST_TIMEOUT: no gracefulStop is set, k6's own default stays" || fail "C3 got '$(k6_graceful -)'"

# ================================================================================================================================
# E. the allocation: measure-ladder sends the prediction's rps_after, not the mix
# ================================================================================================================================
python3 - "$lab/routes/route-list.json" "$brepo/poc/lab/predictions" "$SCALE" <<'PY'
import copy, json, sys
routes = [s["route"] for s in json.load(open(sys.argv[1]))["selected"]]
outdir, scale = sys.argv[2], int(sys.argv[3])
DROP = "GET /books/{slug}"
rungs = [0.025, 0.035, 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0]
tables = {"entities": scale, "images": scale, "references": scale}
kept = [r for r in routes if r != DROP]
after = {r: 1.0 / len(kept) for r in kept}          # even mix: 1/8 each in the file, the dropped route's share handed to the other seven
def doc(with_top=True, with_sc=True):
    sc = {"id": "s-1", "tables": tables, "rps_total_in_file": 1.0,
          "stages": [{"rps_total": t, "rps": {r: t * after[r] for r in kept}} for t in rungs]}
    if with_sc:
        sc["allocation"] = {"dropped_routes": [{"route": DROP, "reasons": ["placeholders"], "rps_in_file": 0.125}],
                            "groups_changed": [], "not_judged": {}, "longest_request": None,
                            "rps_in_file": {r: 0.125 for r in routes}, "rps_after": dict(after),
                            "rps_total_in_file": 1.0, "rps_total_after": 1.0}
    d = {"scenarios": [sc]}
    if with_top:
        d["allocation"] = {"enabled": True, "request_timeout_s": 1800.0, "half_request_timeout_s": 900.0,
                           "drop_rule": "a made-up rule for this test", "stop_rule": "a made-up stop rule for this test"}
    return d
def write(name, d):
    json.dump(d, open("%s/%s.json" % (outdir, name), "w"))
write("alloc-ok", doc())
write("alloc-top-only", doc(with_sc=False))
write("alloc-scenario-only", doc(with_top=False))
d = doc(); d["scenarios"][0]["allocation"]["rps_after"][DROP] = 0.1; write("alloc-dropped-has-rps", d)
d = doc(); d["scenarios"][0]["stages"][3]["rps"][DROP] = 0.001; write("alloc-stage-has-dropped", d)
d = doc(); st = d["scenarios"][0]["stages"][5]["rps"]; k = sorted(st)[0]; st[k] *= 1.5; write("alloc-stage-shares", d)
d = doc(); d["scenarios"][0]["allocation"]["rps_total_after"] = 1.5; write("alloc-total", d)
d = doc(); d["allocation"]["enabled"] = False; d["scenarios"][0].pop("allocation"); write("alloc-off", d)
# a prediction made with the flag that drops no route still states the allocation: the block at the top, and the scenario's own with no dropped route
d = doc(); sc = d["scenarios"][0]
full = 1.0 / len(routes)
sc["allocation"].update({"dropped_routes": [], "rps_after": {r: 0.125 for r in routes}})
sc["stages"] = [{"rps_total": t, "rps": {r: t * full for r in routes}} for t in rungs]
write("alloc-zero", d)
PY
git -C "$brepo" add -A && git -C "$brepo" commit -q -m "chore: seed the allocation predictions"
plan1() { echo "$out/ladder/s-1-$SCALE/rung-01-0.025/plan.json"; }
reset_e() { set_b php=0 pool=0s nginx=600s mem=4G; }
ml_pred() { ml_b --scenario=s-1 --predictions="$brepo/poc/lab/predictions/$1.json" --scheme=B --request-timeout=600s; }
routes_in_plan() { python3 -c 'import json,sys; print(",".join(sorted(r["route"] for r in json.load(open(sys.argv[1]))["routes"])))' "$1"; }

# 13 -------------------------------------------------------------------------------------------------------------------------
reset_e; ml_pred alloc-ok; rc=$?
[ "$(k6_calls)" -ge 1 ] && pass "13 a prediction with an allocation is measured (the load tool is reached)" || fail "13 rc $rc ($(head -3 "$Bdir/ml.err"))"
has "$Bdir/ml.out" "ALLOCATION s-1: 7 routes sent, 1 not sent (GET /books/{slug})" && pass "13b the ALLOCATION line says 7 routes sent and names the one not sent" || fail "13b $(grep ALLOCATION "$Bdir/ml.out")"
python3 - "$(plan1)" <<'PY' && pass "13c the plan sends 7 routes, each at 1/7 of the rps, and not the dropped route" || fail "13c the plan is not the allocation's"
import json, sys
p = json.load(open(sys.argv[1]))
routes = [r["route"] for r in p["routes"]]
ok = len(routes) == 7 and "GET /books/{slug}" not in routes
ok = ok and all(abs(r["share"] - 1 / 7) < 1e-9 for r in p["routes"])
ok = ok and abs(sum(r["rps"] for r in p["routes"]) - p["rps"]) < 1e-9
ok = ok and p["allocation"]["routes_not_sent"] == ["GET /books/{slug}"]
sys.exit(0 if ok else 1)
PY
[ -s "$out/ladder/s-1-$SCALE/allocation.json" ] && has "$out/ladder/s-1-$SCALE/allocation.json" '"GET /books/{slug}"' && pass "13d allocation.json is kept with the scenario's records" || fail "13d"
# every step's plan has the same seven routes (the stagger of scheme B keeps the allocation's routes)
PEAK_REPO_ROOT="$repo_real" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" "$lab/bin/measure-ladder" --scenario=s-1 --dry-run \
  --predictions="$brepo/poc/lab/predictions/alloc-ok.json" --scheme=B >"$Bdir/ml.out" 2>"$Bdir/ml.err"; rc=$?   # a dry run touches no container: the real docker runs the PHP tool in a throw-away container
[ "$rc" = 0 ] && ls "$out/ladder/s-1-$SCALE/dry-run/B"/rung-*/plan.json | wc -l | grep -qx 15 && pass "13e a dry run makes the plans of all 15 steps under every scheme from the allocation" || fail "13e rc $rc ($(head -2 "$Bdir/ml.err"))"
bad=0; for f in "$out/ladder/s-1-$SCALE/dry-run"/*/rung-*/plan.json; do [ "$(routes_in_plan "$f")" = "$(routes_in_plan "$(plan1)")" ] || bad=$((bad + 1)); done
[ "$bad" = 0 ] && pass "13f all 45 dry-run plans (15 steps x 3 schemes) hold the same seven routes" || fail "13f $bad plans differ"
reset_e; ml_pred p; rc=$?   # p.json has no allocation: the stale allocation.json of the run before is taken away
[ ! -e "$out/ladder/s-1-$SCALE/allocation.json" ] && pass "13g a prediction with no allocation removes the allocation.json left by the run before" || fail "13g"

# 14 -------------------------------------------------------------------------------------------------------------------------
[ "$(k6_calls)" -ge 1 ] && ! has "$Bdir/ml.out" 'ALLOCATION' && pass "14 a prediction with no allocation is measured and prints no ALLOCATION line" || fail "14"
python3 - "$(plan1)" <<'PY' && pass "14b its plan has all 8 routes at the mix's 1/8 and no allocation key" || fail "14b the plan changed"
import json, sys
p = json.load(open(sys.argv[1]))
ok = len(p["routes"]) == 8 and all(abs(r["share"] - 0.125) < 1e-12 for r in p["routes"]) and "allocation" not in p and p["mix"] == "even"
sys.exit(0 if ok else 1)
PY
rm -rf "$out/ladder/s-1-$SCALE/dry-run"
PEAK_REPO_ROOT="$repo_real" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" "$lab/bin/measure-ladder" --scenario=s-1 --dry-run \
  --predictions="$brepo/poc/lab/predictions/alloc-off.json" --scheme=B >"$Bdir/ml.out" 2>"$Bdir/ml.err"; rc=$?
[ "$rc" = 0 ] && [ "$(routes_in_plan "$out/ladder/s-1-$SCALE/dry-run/B/rung-05-0.1/plan.json" | tr ',' '\n' | wc -l)" = 8 ] && ! has "$Bdir/ml.out" 'ALLOCATION' \
  && pass "14c in a dry run an allocation switched off at the top and absent in the scenario is the mix, as before (the gate itself closes on a block that is not enabled)" || fail "14c rc $rc ($(head -2 "$Bdir/ml.err"))"
golden="$here/ladder-chain.golden-plans.txt"
bad=0; n_g=0
while read -r mix rps dur warm sha; do
  [ "$rps" = 0.3 ] || [ "$rps" = 0.025 ] || continue
  case "$mix" in even|first80|last80|places1235|places1345|bookpage80zip0|odd80|even80) ;; *) continue ;; esac
  got="$("$real_docker" run --rm --pull=never --network none --cpuset-cpus="$cpuset" -u "$(id -u):$(id -g)" -v "$repo_real:/peak-sim:ro" "$php_image" php /peak-sim/poc/lab/load/make-plan.php --routes=/peak-sim/poc/lab/routes/route-list.json --mix="$mix" --rps="$rps" --duration="$dur" --warmup="$warm" 2>&1 | sha256sum | cut -c1-16)"
  n_g=$((n_g + 1)); [ "$got" = "$sha" ] || bad=$((bad + 1))
done <"$golden"
[ "$n_g" = 16 ] && [ "$bad" = 0 ] && pass "14d make-plan without --allocation is byte for byte what it was before (16 plans over the 8 mixes; hashes taken before the change)" || fail "14d $bad of $n_g plans differ"

# 15 -------------------------------------------------------------------------------------------------------------------------
# measure-ladder's own reading of the allocation, tried in a dry run (the gate is not enforced there, so the file reaches this check): exit 2, nothing planned
for name in alloc-top-only alloc-scenario-only alloc-dropped-has-rps alloc-stage-has-dropped alloc-stage-shares alloc-total; do
  rm -rf "$out/ladder/s-1-$SCALE/dry-run"
  PEAK_REPO_ROOT="$repo_real" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" "$lab/bin/measure-ladder" --scenario=s-1 --dry-run \
    --predictions="$brepo/poc/lab/predictions/$name.json" --scheme=B >"$Bdir/ml.out" 2>"$Bdir/ml.err"; rc=$?
  expect_code "15 $name: refused in a dry run (2)" 2 $rc
  has "$Bdir/ml.err" "the allocation of s-1" && [ ! -d "$out/ladder/s-1-$SCALE/dry-run" ] && pass "15b $name: the refusal names the allocation and no plan was made" || fail "15b $name: $(head -2 "$Bdir/ml.err")"
done
# in a measurement: the stages that disagree with rps_after pass the gate (it does not read the shares) and are refused here, before any container; the others the gate closes first (12)
reset_e; ml_pred alloc-stage-shares; rc=$?
expect_code "15c a prediction whose stages do not have the shares of rps_after: 2 in a measurement too" 2 $rc
[ ! -s "$Bdir/docker.calls" ] && pass "15d ... and no container was touched" || fail "15d $(wc -l <"$Bdir/docker.calls") docker calls"
reset_e; ml_pred alloc-top-only; rc=$?
expect_code "15e half an allocation (the top without the scenario's): the gate closes first (12)" 12 $rc

# 17 -------------------------------------------------------------------------------------------------------------------------
ml_req() { ml_b --scenario=s-1 --predictions="$brepo/poc/lab/predictions/$1.json" --scheme=B --request-timeout=600s --require-allocation; }
reset_e; ml_req alloc-ok; rc=$?
[ "$(k6_calls)" -ge 1 ] && has "$Bdir/ml.out" 'ALLOCATION s-1: 7 routes sent' && pass "17 --require-allocation with an allocation: measured on it (the load tool is reached)" || fail "17 rc $rc ($(head -2 "$Bdir/ml.err"))"
reset_e; ml_req alloc-zero; rc=$?
[ "$(k6_calls)" -ge 1 ] && has "$Bdir/ml.out" 'ALLOCATION s-1: 8 routes sent, 0 not sent (none)' && [ "$(routes_in_plan "$(plan1)" | tr ',' '\n' | wc -l)" = 8 ] \
  && pass "17b an allocation that drops no route counts as stated: measured, all 8 routes sent (the block is there even with nothing dropped)" || fail "17b rc $rc ($(head -2 "$Bdir/ml.err"))"
reset_e; ml_req p; rc=$?
expect_code "17c --require-allocation with a prediction that has none (the gate passes it): 2" 2 $rc
[ ! -s "$Bdir/docker.calls" ] && has "$Bdir/ml.err" 'require-allocation' && pass "17d ... before any container, and the refusal names the flag" || fail "17d $(head -2 "$Bdir/ml.err"); docker calls $(wc -l <"$Bdir/docker.calls")"
reset_e; ml_pred p; rc=$?
[ "$(k6_calls)" -ge 1 ] && pass "17e the same prediction without the flag is measured as before" || fail "17e rc $rc"
PEAK_REPO_ROOT="$repo_real" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" "$lab/bin/measure-ladder" --scenario=s-1 --dry-run \
  --predictions="$brepo/poc/lab/predictions/alloc-off.json" --scheme=B --require-allocation >"$Bdir/ml.out" 2>"$Bdir/ml.err"; rc=$?
expect_code "17f a block that is switched off does not count as stated (dry run): 2" 2 $rc
"$lab/bin/measure-ladder" --scenario=s-1 --dry-run --require-allocation >"$Bdir/ml.out" 2>"$Bdir/ml.err"; rc=$?
expect_code "17g --require-allocation without --predictions: 2" 2 $rc
"$lab/bin/measure-ladder" --trial=cal-2 --predictions="$bpred" --require-allocation >"$Bdir/ml.out" 2>"$Bdir/ml.err"; rc=$?
expect_code "17h --require-allocation with --trial: 2" 2 $rc

# 18 -------------------------------------------------------------------------------------------------------------------------
# --max-steps (D231): a setting for a rehearsal of the tools, default off. In the chain it is handed to every measure-ladder call; in measure-ladder it ends the
# scenario after the first N steps, counting the ones already recorded; invalid values and --trial are refused (2) before any container.
reset_a; chain_dir; run_chain "$d" --max-steps=2; rc=$?
[ "$rc" = 0 ] && [ "$(grep '^measure-ladder ' "$A/calls" | grep -c -- ' --max-steps=2')" = 5 ] && has "$d/chain.log" 'CHAIN START .*max_steps=2' && [ "$(grep -c 'START s-.* max_steps=2' "$d/chain.log")" = 5 ] \
  && pass "18 the chain hands --max-steps=2 to all 5 measure-ladder calls and chain.log says max_steps=2 (CHAIN START and 5 START lines)" || fail "18 rc $rc ($(grep '^measure-ladder ' "$A/calls" | head -1))"
reset_a; chain_dir; run_chain "$d"
! grep '^measure-ladder ' "$A/calls" | grep -q -- 'max-steps' && ! has "$d/chain.log" 'max_steps' && pass "18b without the flag no call carries --max-steps and chain.log has no max_steps (the chain is what it was)" || fail "18b"
for bad in 0 100 x -1 ""; do
  reset_a; chain_dir; run_chain "$d" --max-steps="$bad"; rc=$?
  [ "$rc" = 2 ] && [ ! -s "$A/calls" ] && pass "18c --max-steps='$bad' is refused (2) and nothing is called" || fail "18c '$bad': rc $rc, calls $(wc -l <"$A/calls")"
done
for bad in 0 100 x; do
  set_b php="$php_val" pool=0s nginx="$nginx_val" mem="$mem_val"
  ml_b --scenario=s-1 --predictions="$bpred" --scheme=B --request-timeout=1800s --max-steps="$bad"; rc=$?
  [ "$rc" = 2 ] && [ ! -s "$Bdir/docker.calls" ] && has "$Bdir/ml.err" 'max-steps' && pass "18d measure-ladder --max-steps='$bad': 2 before any container, naming the flag" || fail "18d '$bad': rc $rc"
done
"$lab/bin/measure-ladder" --trial=cal-2 --max-steps=1 >"$Bdir/ml.out" 2>"$Bdir/ml.err"; rc=$?
expect_code "18e --max-steps with --trial: 2" 2 $rc
# the two lowest steps already recorded: with --max-steps=2 the scenario is read to its end there, the load tool is never called, MAX-STEPS says why
set_b php="$php_val" pool=0s nginx="$nginx_val" mem="$mem_val"
sd="$out/ladder/s-1-$SCALE"; rm -rf "$sd"; mkdir -p "$sd/rung-01-0.025" "$sd/rung-02-0.035"
for r in rung-01-0.025:1:0.025 rung-02-0.035:2:0.035; do
  python3 -c 'import json,sys; d,i,r=sys.argv[1].split(":"); json.dump({"rung":int(i),"rps":float(r),"decision":{"stop":False,"reasons":[]}}, open(sys.argv[2]+"/"+d+"/record.json","w"))' "$r" "$sd"
done
ml_b --scenario=s-1 --predictions="$bpred" --scheme=B --request-timeout=1800s --max-steps=2; rc=$?
[ "$(k6_calls)" = 0 ] && [ "$(grep -c '^RUNG SKIP' "$Bdir/ml.out")" = 2 ] && has "$Bdir/ml.out" 'MAX-STEPS s-1: stopped after rung 2' && ! has "$Bdir/ml.out" 'RUNG START' \
  && pass "18f two steps already recorded and --max-steps=2: both skipped, MAX-STEPS after rung 2, no step started, the load tool never called" || fail "18f rc $rc k6 $(k6_calls) ($(tail -3 "$Bdir/ml.out" | tr '\n' '|') $(head -2 "$Bdir/ml.err"))"
set_b php="$php_val" pool=0s nginx="$nginx_val" mem="$mem_val"
ml_b --scenario=s-1 --predictions="$bpred" --scheme=B --request-timeout=1800s --max-steps=1; rc=$?
[ "$(k6_calls)" = 0 ] && [ "$(grep -c '^RUNG SKIP' "$Bdir/ml.out")" = 1 ] && has "$Bdir/ml.out" 'MAX-STEPS s-1: stopped after rung 1' && ! has "$Bdir/ml.out" 'rung 2' \
  && pass "18g --max-steps=1 stops after the first step (rung 2 is not looked at)" || fail "18g rc $rc k6 $(k6_calls)"
rm -rf "$sd"

echo
echo "ladder chain tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

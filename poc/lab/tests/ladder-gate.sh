#!/usr/bin/env bash
# Tests of the prediction gate and of its place in front of the ladder measurement. They need git and bash only: no Docker, no PHP,
# and they never touch the lab (the `docker` they put on PATH is a recorder that fails the test if it is called).
#
#   poc/lab/tests/ladder-gate.sh
#
# 1. no prediction file                         -> the gate is closed (12)
# 2. a file that was never committed            -> closed
# 3. staged but not committed                   -> closed
# 4. committed and clean                        -> open (0), and it names the commit
# 5. committed, then edited (unstaged)          -> closed
# 6. committed, then edited and staged          -> closed
# 7. committed, plus a second uncommitted file in predictions/ -> closed
# 8. a committed .json outside predictions/     -> closed
# 9. measure-ladder with a closed gate exits 12 and calls `docker` zero times (take the gate out of it and this fails)
# 10. measure-ladder with an open gate goes on past the gate (it reaches `docker`), so the test in 9 means something
# 11. measure-ladder without --predictions (and not a dry run) is refused before anything else (2), `docker` zero times
# 12-19 the trial path (`--trial`, a rehearsal of the tool on a calibration mix; no prediction in it) cannot be used to get round the gate:
#   --trial=t-4 / even / cal-1 are refused (2); --trial with --scenario or with --predictions is refused (2); a trial's --out must be
#   under out/trial and an ordinary run's must not (2); every refusal calls `docker` zero times; the trial itself goes on to docker
#   with no prediction file; the ordinary path with a closed gate is still 12 (9) and an unknown scenario is still refused.
# 20-27 the re-check scenarios (r-1 .. r-5, spec 7.4.1) go through the same gate, and state the load tool's timeout:
#   r-1 without --request-timeout is refused (2), a timeout without a unit is refused (2), an unknown r-6 is refused (2), all with
#   `docker` zero times; r-1 with a closed gate is still 12 and with an open gate it reaches docker; t-4 still needs no
#   --request-timeout (the first backtest is measured as it was); a dry run of r-1 needs none either.
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
expect_code() {  # expect_code <label> <want> <got>
  if [ "$3" -eq "$2" ]; then pass "$1"; else fail "$1 (exit $3, wanted $2)"; fi
}

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
repo="$work/repo"; mkdir -p "$repo/poc/lab/predictions" "$repo/poc/lab/bin" "$work/state" "$work/out/web" "$work/fakebin"
git -C "$repo" init -q . && git -C "$repo" config user.email t@example.invalid && git -C "$repo" config user.name t
echo seed >"$repo/README"; git -C "$repo" add README && git -C "$repo" commit -q -m "chore: seed"

gate() { PEAK_REPO_ROOT="$repo" "$lab/bin/prediction-gate" "$@" >"$work/gate.out" 2>&1; }
pred="$repo/poc/lab/predictions/p.json"

# 1
gate "$pred"; expect_code "1 no prediction file: closed" 12 $?
# 2
echo '{"v":1}' >"$pred"
gate "$pred"; expect_code "2 never committed: closed" 12 $?
grep -q 'not tracked' "$work/gate.out" && pass "2b it says why" || fail "2b it says why"
# 3
git -C "$repo" add poc/lab/predictions/p.json
gate "$pred"; expect_code "3 staged, not committed: closed" 12 $?
# 4
git -C "$repo" commit -q -m "feat: add prediction"
gate "$pred"; expect_code "4 committed and clean: open" 0 $?
head="$(git -C "$repo" rev-parse HEAD)"
grep -q "^PREDICTION OK poc/lab/predictions/p.json $head " "$work/gate.out" && pass "4b it names the commit" || fail "4b it names the commit ($(cat "$work/gate.out"))"
# 5
echo '{"v":2}' >"$pred"
gate "$pred"; expect_code "5 edited after the commit: closed" 12 $?
# 6
git -C "$repo" add "$pred"
gate "$pred"; expect_code "6 edited and staged: closed" 12 $?
git -C "$repo" checkout -q HEAD -- poc/lab/predictions/p.json
gate "$pred"; expect_code "6b restored: open again" 0 $?
# 7
echo '{"v":9}' >"$repo/poc/lab/predictions/second.json"
gate "$pred"; expect_code "7 a second uncommitted file in predictions/: closed" 12 $?
rm -f "$repo/poc/lab/predictions/second.json"
# 8
echo '{}' >"$repo/other.json"; git -C "$repo" add other.json && git -C "$repo" commit -q -m "chore: add other"
gate "$repo/other.json"; expect_code "8 a .json outside predictions/: closed" 12 $?

# 9-11: measure-ladder, with a `docker` that only records that it was called
cat >"$work/fakebin/docker" <<FAKE
#!/usr/bin/env bash
echo "\$*" >>"$work/docker.calls"
exit 99
FAKE
chmod +x "$work/fakebin/docker"
ml() {
  : >"$work/docker.calls"
  PATH="$work/fakebin:$PATH" PEAK_REPO_ROOT="$repo" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$work/out" \
    "$lab/bin/measure-ladder" "$@" >"$work/ml.out" 2>&1
}
calls() { wc -l <"$work/docker.calls" | tr -d ' '; }

echo '{"v":3}' >"$pred"            # edited, not committed: the gate is closed
ml --scenario=t-4 --predictions="$pred"; rc=$?
expect_code "9 closed gate: measure-ladder exits 12" 12 $rc
[ "$(calls)" = 0 ] && pass "9b closed gate: docker was not called" || fail "9b closed gate: docker was called $(calls) time(s)"
git -C "$repo" checkout -q HEAD -- poc/lab/predictions/p.json
ml --scenario=t-4 --predictions="$pred"; rc=$?
[ "$rc" -ne 12 ] && pass "10 open gate: measure-ladder goes past it (exit $rc)" || fail "10 open gate: still closed"
[ "$(calls)" -ge 1 ] && pass "10b open gate: it reached docker" || fail "10b open gate: it never reached docker (the gate test in 9 would prove nothing)"
grep -q '^PREDICTION OK' "$work/ml.out" && pass "10c open gate: it printed the gate line" || fail "10c open gate: no gate line"
ml --scenario=t-4; rc=$?
expect_code "11 no --predictions: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "11b no --predictions: docker was not called" || fail "11b docker was called"

# 12-19: the trial path
for bad in t-4 even first80 cal-1 t-1; do
  ml --trial="$bad"; rc=$?
  expect_code "12 --trial=$bad: refused" 2 $rc
  [ "$(calls)" = 0 ] && pass "12b --trial=$bad: docker was not called" || fail "12b --trial=$bad: docker was called"
done
ml --trial=cal-2 --scenario=t-4; rc=$?
expect_code "13 --trial with --scenario: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "13b docker was not called" || fail "13b docker was called"
ml --trial=cal-2 --predictions="$pred"; rc=$?
expect_code "14 --trial with --predictions: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "14b docker was not called" || fail "14b docker was called"
ml --trial=cal-3 --out="$work/out/ladder"; rc=$?
expect_code "15 a trial's --out outside out/trial: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "15b docker was not called" || fail "15b docker was called"
ml --scenario=t-4 --predictions="$pred" --out="$work/out/trial"; rc=$?
expect_code "16 an ordinary run into out/trial: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "16b docker was not called" || fail "16b docker was called"
echo '{"v":3}' >"$pred"            # the gate is closed again; a trial has no use for it, an ordinary run still stops at it
ml --scenario=t-4 --predictions="$pred"; rc=$?
expect_code "17 the ordinary path still stops at a closed gate" 12 $rc
git -C "$repo" checkout -q HEAD -- poc/lab/predictions/p.json
ml --trial=cal-2 --switch-scale; rc=$?
[ "$rc" -ne 12 ] && [ "$rc" -ne 2 ] && pass "18 --trial=cal-2 goes past the arguments with no prediction file (exit $rc)" || fail "18 --trial=cal-2 was stopped (exit $rc: $(head -2 "$work/ml.out" | tr '\n' ' '))"
[ "$(calls)" -ge 1 ] && pass "18b the trial reached docker" || fail "18b the trial never reached docker"
grep -q '^PREDICTION OK' "$work/ml.out" && fail "18c a trial printed a prediction gate line" || pass "18c a trial prints no prediction gate line"
[ -d "$work/out/trial" ] && pass "19 the trial's records are under out/trial" || fail "19 no out/trial directory"
[ ! -d "$work/out/ladder/trial-cal-2-10000" ] && pass "19b nothing of the trial is in out/ladder" || fail "19b the trial wrote into out/ladder"

# 20-27: the re-check scenarios
ml --scenario=r-1 --predictions="$pred"; rc=$?
expect_code "20 r-1 without --request-timeout: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "20b docker was not called" || fail "20b docker was called"
grep -q 'request-timeout' "$work/ml.out" && pass "20c it says what is missing" || fail "20c no hint about --request-timeout"
for bad in 600 abc 600x ''; do
  ml --scenario=r-1 --predictions="$pred" --request-timeout="$bad"; rc=$?
  expect_code "21 r-1 --request-timeout='$bad': refused" 2 $rc
  [ "$(calls)" = 0 ] && pass "21b --request-timeout='$bad': docker was not called" || fail "21b --request-timeout='$bad': docker was called"
done
ml --scenario=r-6 --predictions="$pred" --request-timeout=600s; rc=$?
expect_code "22 r-6 does not exist: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "22b docker was not called" || fail "22b docker was called"
echo '{"v":3}' >"$pred"            # closed
ml --scenario=r-1 --predictions="$pred" --request-timeout=600s; rc=$?
expect_code "23 r-1 with a closed gate: 12" 12 $rc
[ "$(calls)" = 0 ] && pass "23b docker was not called" || fail "23b docker was called"
git -C "$repo" checkout -q HEAD -- poc/lab/predictions/p.json
ml --scenario=r-3 --predictions="$pred" --request-timeout=600s; rc=$?
[ "$rc" -ne 12 ] && [ "$rc" -ne 2 ] && pass "24 r-3 with an open gate and a timeout goes past the arguments (exit $rc)" || fail "24 r-3 was stopped (exit $rc: $(head -2 "$work/ml.out" | tr '\n' ' '))"
[ "$(calls)" -ge 1 ] && pass "24b it reached docker" || fail "24b it never reached docker"
ml --scenario=t-4 --predictions="$pred"; rc=$?
[ "$rc" -ne 12 ] && [ "$rc" -ne 2 ] && pass "25 t-4 needs no --request-timeout (exit $rc)" || fail "25 t-4 was stopped (exit $rc: $(head -2 "$work/ml.out" | tr '\n' ' '))"
ml --scenario=t-4 --predictions="$pred" --request-timeout=abc; rc=$?
expect_code "26 t-4 with a timeout that has no unit: refused too" 2 $rc
ml --scenario=r-1 --dry-run; rc=$?
[ "$rc" -ne 2 ] && pass "27 a dry run of r-1 needs no --request-timeout (exit $rc)" || fail "27 a dry run of r-1 was refused ($(head -2 "$work/ml.out" | tr '\n' ' '))"

echo
echo "ladder gate tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

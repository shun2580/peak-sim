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

echo
echo "ladder gate tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

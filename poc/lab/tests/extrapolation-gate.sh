#!/usr/bin/env bash
# Tests of the gate in front of the measurement of test A (poc/lab/bin/measure-extrapolation). They need git and bash only: no Docker,
# no PHP. The runtime tools (`measure`, `variance-run`) are replaced by a recorder, so nothing real is ever started.
#
#   poc/lab/tests/extrapolation-gate.sh
#
# 1  no estimate / no marked list / no mode / an unknown option  -> refused (2), the tool is not called
# 2  the estimate was never committed                            -> closed (12), the tool is not called
# 3  the estimate is committed but the marked list is not        -> closed
# 4  both staged but not committed                               -> closed
# 5  both committed and clean                                    -> open: `measure` and `repeat` reach their own tool, with the arguments
# 6  the gate lines (commit and time of both files) are printed before the tool runs
# 7  committed, then edited (unstaged / staged)                  -> closed again (each file)
# 8  committed, plus a second uncommitted file in predictions/   -> closed
# 9  a .json outside predictions/                                -> closed
# 10 the same file given as estimate and as marked list          -> refused (2)
# 11 THE CALL MATTERS: the same script with its two gate lines removed starts the tool under a closed gate. So the tests above
#    (closed gate, tool not called) fail when the gate call is taken out of measure-extrapolation.
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"

passed=0; failed=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
expect_code() { if [ "$3" -eq "$2" ]; then pass "$1"; else fail "$1 (exit $3, wanted $2)"; fi; }

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
repo="$work/repo"; mkdir -p "$repo/poc/lab/predictions" "$repo/poc/lab/bin" "$work/rt"
git -C "$repo" init -q . && git -C "$repo" config user.email t@example.invalid && git -C "$repo" config user.name t
echo seed >"$repo/README"; git -C "$repo" add README && git -C "$repo" commit -q -m "chore: seed"

# the copy of the tools under test lives in the temporary repository, so the gate reads that repository
cp "$lab/bin/prediction-gate" "$lab/bin/measure-extrapolation" "$repo/poc/lab/bin/"
for t in measure variance-run; do
  cat >"$work/rt/$t" <<FAKE
#!/usr/bin/env bash
echo "$t \$*" >>"$work/tool.calls"
exit 0
FAKE
  chmod +x "$work/rt/$t"
done
run() {  # run <script> <args...>
  local script="$1"; shift
  : >"$work/tool.calls"
  PEAK_REPO_ROOT="$repo" PEAK_RUNTIME_BIN="$work/rt" "$script" "$@" >"$work/out.txt" 2>&1
}
calls() { wc -l <"$work/tool.calls" | tr -d ' '; }
door="$repo/poc/lab/bin/measure-extrapolation"
est="$repo/poc/lab/predictions/estimate.json"
mk="$repo/poc/lab/predictions/marked-routes.json"
args=(--app=/x --scales=300000)

# 1
run "$door" ; expect_code "1a no arguments: refused" 2 $?
echo '{"e":1}' >"$est"; echo '{"m":1}' >"$mk"
run "$door" --estimate="$est" measure; expect_code "1b no marked list: refused" 2 $?
run "$door" --marked="$mk" measure; expect_code "1c no estimate: refused" 2 $?
run "$door" --estimate="$est" --marked="$mk"; expect_code "1d no mode: refused" 2 $?
run "$door" --estimate="$est" --marked="$mk" --bogus measure; expect_code "1e unknown option: refused" 2 $?
run "$door" --estimate="$est" --marked="$mk" measure --app=/x; expect_code "1f arguments without --: refused" 2 $?
[ "$(calls)" = 0 ] && pass "1g refused: the tool was not called" || fail "1g the tool was called"
# 2
run "$door" --estimate="$est" --marked="$mk" measure -- "${args[@]}"; expect_code "2 never committed: closed" 12 $?
[ "$(calls)" = 0 ] && pass "2b closed: the tool was not called" || fail "2b closed: the tool was called"
# 3
git -C "$repo" add poc/lab/predictions/estimate.json && git -C "$repo" commit -q -m "feat: add estimate"
run "$door" --estimate="$est" --marked="$mk" measure -- "${args[@]}"; expect_code "3 marked list not committed: closed" 12 $?
[ "$(calls)" = 0 ] && pass "3b closed: the tool was not called" || fail "3b closed: the tool was called"
# 4
git -C "$repo" add poc/lab/predictions/marked-routes.json
run "$door" --estimate="$est" --marked="$mk" repeat -- "${args[@]}"; expect_code "4 staged, not committed: closed" 12 $?
[ "$(calls)" = 0 ] && pass "4b closed: the tool was not called" || fail "4b closed: the tool was called"
# 5, 6
git -C "$repo" commit -q -m "feat: add marked routes"
head="$(git -C "$repo" rev-parse HEAD)"
run "$door" --estimate="$est" --marked="$mk" measure -- "${args[@]}"; expect_code "5a open: measure exits 0" 0 $?
grep -qx "measure --app=/x --scales=300000 --analyze-after-build=on" "$work/tool.calls" && pass "5b measure reached bin/measure with its arguments and the statistics held" || fail "5b ($(cat "$work/tool.calls"))"
run "$door" --estimate="$est" --marked="$mk" repeat -- "${args[@]}"; expect_code "5c open: repeat exits 0" 0 $?
grep -qx "variance-run --app=/x --scales=300000 --stats-auto-recalc=off" "$work/tool.calls" && pass "5d repeat reached bin/variance-run with its arguments and the statistics held" || fail "5d ($(cat "$work/tool.calls"))"
grep -q "^PREDICTION OK poc/lab/predictions/estimate.json $(git -C "$repo" log -1 --format=%H -- poc/lab/predictions/estimate.json) " "$work/out.txt" \
  && grep -q "^PREDICTION OK poc/lab/predictions/marked-routes.json $head " "$work/out.txt" \
  && pass "6 both gate lines are printed" || fail "6 gate lines ($(cat "$work/out.txt"))"
# 5e-5m: the held statistics (D224)
run "$door" --estimate="$est" --marked="$mk" measure -- "${args[@]}" --analyze-after-build=on; expect_code "5e measure given on: open" 0 $?
grep -qx "measure --app=/x --scales=300000 --analyze-after-build=on" "$work/tool.calls" && pass "5f on is not added twice" || fail "5f ($(cat "$work/tool.calls"))"
run "$door" --estimate="$est" --marked="$mk" measure -- "${args[@]}" --analyze-after-build=off; expect_code "5g measure given off: refused" 2 $?
[ "$(calls)" = 0 ] && pass "5h refused: the tool was not called" || fail "5h the tool was called"
run "$door" --estimate="$est" --marked="$mk" repeat -- "${args[@]}" --stats-auto-recalc=keep; expect_code "5i repeat given keep: refused" 2 $?
[ "$(calls)" = 0 ] && pass "5j refused: the tool was not called" || fail "5j the tool was called"
run "$door" --estimate="$est" --marked="$mk" repeat -- "${args[@]}" --stats-tables=entities,references; expect_code "5k --stats-tables without slug_history: refused" 2 $?
run "$door" --estimate="$est" --marked="$mk" repeat -- "${args[@]}" --stats-tables=entities,slug_history; expect_code "5l --stats-tables without references: refused" 2 $?
run "$door" --estimate="$est" --marked="$mk" repeat -- "${args[@]}" --stats-tables=entities,references,slug_history; expect_code "5m --stats-tables with both: open" 0 $?
grep -qx "variance-run --app=/x --scales=300000 --stats-tables=entities,references,slug_history --stats-auto-recalc=off" "$work/tool.calls" && pass "5n both tables named, off added" || fail "5n ($(cat "$work/tool.calls"))"
# 7
for f in "$est" "$mk"; do
  name="$(basename "$f")"
  echo '{"edited":1}' >"$f"
  run "$door" --estimate="$est" --marked="$mk" measure -- "${args[@]}"; rc=$?; expect_code "7a $name edited: closed" 12 $rc
  git -C "$repo" add "$f"
  run "$door" --estimate="$est" --marked="$mk" measure -- "${args[@]}"; rc=$?; expect_code "7b $name edited and staged: closed" 12 $rc
  [ "$(calls)" = 0 ] && pass "7c the tool was not called" || fail "7c the tool was called"
  git -C "$repo" checkout -q HEAD -- "${f#"$repo"/}"
done
run "$door" --estimate="$est" --marked="$mk" measure -- "${args[@]}"; expect_code "7d restored: open again" 0 $?
# 8
echo '{"s":1}' >"$repo/poc/lab/predictions/second.json"
run "$door" --estimate="$est" --marked="$mk" measure -- "${args[@]}"; expect_code "8 a second uncommitted file in predictions/: closed" 12 $?
[ "$(calls)" = 0 ] && pass "8b the tool was not called" || fail "8b the tool was called"
# 11 (done here, with the second file still there: a closed gate)
sed '/^est_line=/d;/^mk_line=/d;/^echo "\$est_line"/d' "$door" >"$repo/poc/lab/bin/no-gate"; chmod +x "$repo/poc/lab/bin/no-gate"
grep -q 'prediction-gate" "\$' "$repo/poc/lab/bin/no-gate" && fail "11a the copy still calls the gate" || pass "11a the copy has no gate call"
run "$repo/poc/lab/bin/no-gate" --estimate="$est" --marked="$mk" measure -- "${args[@]}"
[ "$(calls)" = 1 ] && pass "11b without the gate call the tool starts under a closed gate (so 8 and the other closed-gate tests mean something)" || fail "11b the copy did not start the tool"
rm -f "$repo/poc/lab/predictions/second.json" "$repo/poc/lab/bin/no-gate"
# 9
echo '{}' >"$repo/other.json"; git -C "$repo" add other.json && git -C "$repo" commit -q -m "chore: add other"
run "$door" --estimate="$repo/other.json" --marked="$mk" measure -- "${args[@]}"; expect_code "9 a .json outside predictions/: closed" 12 $?
[ "$(calls)" = 0 ] && pass "9b the tool was not called" || fail "9b the tool was called"
# 10
run "$door" --estimate="$est" --marked="$est" measure -- "${args[@]}"; expect_code "10 one file as both: refused" 2 $?

printf '\n%d passed, %d failed, 0 skipped\n' "$passed" "$failed"
[ "$failed" -eq 0 ]

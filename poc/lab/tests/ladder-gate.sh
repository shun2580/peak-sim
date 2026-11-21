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
# 29-34 the third backtest's scenarios (s-1 .. s-5, spec 7.4.2) go through the same gate, state the load tool's timeout as r-* do, and
#   the t-*/r-* rules are untouched: s-1 without --request-timeout is refused (2), an unknown s-6 is refused (2), both with `docker` zero times;
#   s-5 with a closed gate is still 12 and with an open gate it reaches docker; --trial=s-1 is refused (2). (The 15 steps of s-* against
#   the 13 of t-* and r-* are pinned in LadderTest and LadderCliTest: this script has no PHP, and its `docker` is a recorder.)
# 35-49 the count of a scenario is the prediction file's (B at 90,000 is s-N at 90,000, not 300,000): a --scale that disagrees, a prediction
#   with no such scenario or with tables of different counts, and a trial with a count are all refused (2) with `docker` zero times; the t-*, r-*
#   and s-* of the committed files are read at the counts they always had; a closed gate is still 12.
# 28  every file the ladder measurement writes is outside .gitignore (none is named *.log), so a record is not lost from a commit
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
python3 - "$pred" <<'PY'
import json, sys
table = {"t-1": 30000, "t-2": 100000, "t-3": 100000, "t-4": 10000, "t-5": 100000, "r-1": 50000, "r-3": 200000,
         "s-1": 300000, "s-2": 300000, "s-3": 300000, "s-4": 300000, "s-5": 300000}
# every stage states its preparation (section 7.4.5): a measurement stops before any container without it
stages = [{"rps_total": r, "warmup_sec": 60, "warmup_basis": {"GET /x": 1.0}} for r in (0.025, 0.035, 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0)]
json.dump({"v": 1, "scenarios": [{"id": k, "tables": {t: n for t in ("entities", "images", "references")}, "stages": stages} for k, n in table.items()]}, open(sys.argv[1], "w"))
PY
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

# the made-up record of the statements that the write check of measure-ladder reads (every write in it is to views); the real route list is read
python3 "$here/make-statement-record.py" "$lab/routes/route-list.json" "$work/rec.json" --variant=ok || { echo "ladder-gate: the record could not be made" >&2; exit 2; }
rec="$work/rec.json"

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
    "$lab/bin/measure-ladder" "$@" --statement-record="$rec" >"$work/ml.out" 2>&1
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

# 29-34: the third backtest's scenarios
ml --scenario=s-1 --predictions="$pred"; rc=$?
expect_code "29 s-1 without --request-timeout: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "29b docker was not called" || fail "29b docker was called"
ml --scenario=s-1 --predictions="$pred" --request-timeout=600; rc=$?
expect_code "30 s-1 --request-timeout=600 (no unit): refused" 2 $rc
ml --scenario=s-6 --predictions="$pred" --request-timeout=600s; rc=$?
expect_code "31 s-6 does not exist: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "31b docker was not called" || fail "31b docker was called"
echo '{"v":3}' >"$pred"            # closed
ml --scenario=s-5 --predictions="$pred" --request-timeout=600s; rc=$?
expect_code "32 s-5 with a closed gate: 12" 12 $rc
[ "$(calls)" = 0 ] && pass "32b docker was not called" || fail "32b docker was called"
git -C "$repo" checkout -q HEAD -- poc/lab/predictions/p.json
ml --scenario=s-5 --predictions="$pred" --request-timeout=600s; rc=$?
[ "$rc" -ne 12 ] && [ "$rc" -ne 2 ] && pass "33 s-5 with an open gate and a timeout goes past the arguments (exit $rc)" || fail "33 s-5 was stopped (exit $rc: $(head -2 "$work/ml.out" | tr '\n' ' '))"
[ "$(calls)" -ge 1 ] && pass "33b it reached docker" || fail "33b it never reached docker"
ml --trial=s-1; rc=$?
expect_code "34 --trial=s-1: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "34b docker was not called" || fail "34b docker was called"
# 35-50: the count of a scenario is the prediction file's. A prediction made at another count than the table of load/ladder.php (B at 90,000)
# is measured at its own count (the PHP side is pinned in LadderScaleFromPredictionTest); a --scale that disagrees, a file with no such
# scenario, tables of different counts and a trial with a count are refused before any container. The gate is not loosened.
mkpred() {  # mkpred <name> <json of the scenarios list>
  printf '{"v":4,"scenarios":%s}\n' "$2" >"$repo/poc/lab/predictions/$1.json"
}
WSTAGES='[{"rps_total":0.05,"warmup_sec":60,"warmup_basis":{"GET /x":1.0}}]'   # one stage that states its preparation (section 7.4.5)
sc_of() {  # sc_of <count> <id>...: the scenarios list, every table at <count>
  local n="$1"; shift; local out="" id
  for id in "$@"; do out="$out{\"id\":\"$id\",\"tables\":{\"entities\":$n,\"images\":$n,\"references\":$n},\"stages\":$WSTAGES},"; done
  printf '[%s]' "${out%,}"
}
mkpred b90k "$(sc_of 90000 s-1 s-2 s-3 s-4 s-5)"
mkpred p300k "$(sc_of 300000 s-1 s-2)"
mkpred only-s1 "$(sc_of 150000 s-1)"
mkpred mixed '[{"id":"s-1","tables":{"entities":90000,"images":90000,"references":300000}}]'
mkpred empty-tables '[{"id":"s-1","tables":{}}]'
mkpred twice "$(sc_of 90000 s-1 s-1)"
printf '{"v":4}\n' >"$repo/poc/lab/predictions/no-scenarios.json"
git -C "$repo" add poc/lab/predictions && git -C "$repo" commit -q -m "test: add predictions of other counts"
pb="$repo/poc/lab/predictions/b90k.json"
ml --scenario=s-1 --predictions="$pb" --request-timeout=600s; rc=$?
[ "$rc" -ne 12 ] && [ "$rc" -ne 2 ] && pass "35 s-1 on a 90,000 prediction goes past the arguments (exit $rc)" || fail "35 s-1 on a 90,000 prediction was stopped (exit $rc: $(head -3 "$work/ml.out" | tr '\n' ' '))"
grep -q '^SCALE s-1 90000 rows' "$work/ml.out" && pass "35b it says it takes 90000 from the prediction file" || fail "35b no SCALE 90000 line ($(head -3 "$work/ml.out" | tr '\n' ' '))"
grep -q 'ladder.php scenario s-1 --scale=90000 --line' "$work/docker.calls" && pass "35c the scenario table is read with --scale=90000" || fail "35c the count did not reach the scenario table ($(head -3 "$work/docker.calls" | tr '\n' ' '))"
ml --scenario=s-4 --predictions="$pb" --request-timeout=600s; rc=$?
grep -q 'ladder.php scenario s-4 --scale=90000 --line' "$work/docker.calls" && pass "35d s-4 takes the 90,000 too" || fail "35d s-4 did not"
ml --scenario=s-1 --predictions="$repo/poc/lab/predictions/p300k.json" --request-timeout=600s; rc=$?
grep -q '^SCALE s-1 300000 rows' "$work/ml.out" && grep -q 'ladder.php scenario s-1 --scale=300000 --line' "$work/docker.calls" && pass "36 a 300,000 prediction is still measured at 300,000" || fail "36 300,000 changed ($(head -3 "$work/ml.out" | tr '\n' ' '))"
ml --scenario=t-4 --predictions="$pred" ; rc=$?
grep -q '^SCALE t-4 10000 rows' "$work/ml.out" && grep -q 'ladder.php scenario t-4 --scale=10000 --line' "$work/docker.calls" && pass "37 t-4 on its prediction is still at 10000" || fail "37 t-4 changed ($(head -3 "$work/ml.out" | tr '\n' ' '))"
ml --scenario=s-1 --predictions="$pb" --scale=90000 --request-timeout=600s; rc=$?
[ "$rc" -ne 12 ] && [ "$rc" -ne 2 ] && [ "$(calls)" -ge 1 ] && pass "38 --scale=90000 that agrees with the prediction goes past (exit $rc)" || fail "38 an agreeing --scale was stopped (exit $rc)"
ml --scenario=s-1 --predictions="$pb" --scale=300000 --request-timeout=600s; rc=$?
expect_code "39 --scale=300000 against a 90,000 prediction: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "39b docker was not called" || fail "39b docker was called"
grep -q 'disagrees with the prediction file' "$work/ml.out" && pass "39c it says why" || fail "39c no reason given"
for bad in abc 0 -5 9e4 09000 ''; do
  ml --scenario=s-1 --predictions="$pb" --scale="$bad" --request-timeout=600s; rc=$?
  expect_code "40 --scale='$bad': refused" 2 $rc
  [ "$(calls)" = 0 ] && pass "40b --scale='$bad': docker was not called" || fail "40b --scale='$bad': docker was called"
done
ml --scenario=s-2 --predictions="$repo/poc/lab/predictions/only-s1.json" --request-timeout=600s; rc=$?
expect_code "41 a prediction with no scenario s-2: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "41b docker was not called" || fail "41b docker was called"
for name in mixed empty-tables twice no-scenarios; do
  ml --scenario=s-1 --predictions="$repo/poc/lab/predictions/$name.json" --request-timeout=600s; rc=$?
  expect_code "42 prediction '$name': refused" 2 $rc
  [ "$(calls)" = 0 ] && pass "42b prediction '$name': docker was not called" || fail "42b prediction '$name': docker was called"
done
ml --trial=cal-2 --scale=10000; rc=$?
expect_code "43 a trial with --scale: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "43b docker was not called" || fail "43b docker was called"
echo '{"v":5}' >"$pb"             # edited after the commit: the gate is closed, whatever the count says
ml --scenario=s-1 --predictions="$pb" --scale=90000 --request-timeout=600s; rc=$?
expect_code "44 a closed gate is still 12 with a --scale" 12 $rc
[ "$(calls)" = 0 ] && pass "44b docker was not called" || fail "44b docker was called"
git -C "$repo" checkout -q HEAD -- poc/lab/predictions/b90k.json
# a dry run reads the count from the file it is given, or takes --scale as given when there is no file; a count that disagrees is refused
ml --scenario=s-1 --dry-run --predictions="$pb"; rc=$?
grep -q '^SCALE s-1 90000 rows' "$work/ml.out" && pass "45 a dry run on a 90,000 prediction says 90000" || fail "45 dry run: no SCALE 90000 line"
ml --scenario=s-1 --dry-run --predictions="$pb" --scale=95000; rc=$?
expect_code "46 a dry run with a --scale that disagrees: refused" 2 $rc
[ "$(calls)" = 0 ] && pass "46b docker was not called" || fail "46b docker was called"
ml --scenario=s-1 --dry-run --scale=90000; rc=$?
grep -q '^SCALE s-1 90000 rows' "$work/ml.out" && grep -q 'ladder.php scenario s-1 --scale=90000 --line' "$work/docker.calls" && pass "47 a dry run with no prediction file takes --scale as given" || fail "47 dry run --scale not taken"
ml --scenario=s-1 --dry-run; rc=$?
! grep -q '^SCALE' "$work/ml.out" && grep -q 'ladder.php scenario s-1 --line' "$work/docker.calls" && pass "47b a dry run with neither takes the table's count" || fail "47b the table's count changed"
# the committed prediction files of the repository: each says what the tool will read from it (the counts the table of ladder.php holds, and B's 90,000)
real="$lab/predictions"
expect_scale() {  # expect_scale <file> <scenario> <count>
  ml --scenario="$2" --dry-run --predictions="$real/$1"
  grep -q "^SCALE $2 $3 rows" "$work/ml.out" && pass "48 $1 $2 is read at $3" || fail "48 $1 $2 is not read at $3 ($(head -3 "$work/ml.out" | tr '\n' ' '))"
}
expect_scale ladder-prediction.json t-1 30000
expect_scale ladder-prediction.json t-4 10000
expect_scale ladder-prediction.json t-2 100000
expect_scale ladder-prediction-2.json r-1 50000
expect_scale ladder-prediction-2.json r-3 200000
expect_scale ladder-prediction-3.json s-1 300000
expect_scale ladder-prediction-3.json s-5 300000
expect_scale ladder-prediction-4-b90k.json s-1 90000
expect_scale ladder-prediction-4-b90k.json s-3 90000
expect_scale ladder-prediction-4-b90k.json s-5 90000
expect_scale ladder-prediction-4-mark150k.json s-1 150000
ml --scenario=s-2 --dry-run --predictions="$real/ladder-prediction-4-mark150k.json"; rc=$?
expect_code "49 the 150,000 mark file holds s-1 only: s-2 is refused" 2 $rc
# 50: the record of every step carries the count the scenario was measured at (this test never reaches a step, so the line is read from the script)
grep -q 'record_args=(--scenario="$scenario" --scale="$scale" --prediction-file=' "$lab/bin/measure-ladder" && pass "50 the step's record is made with the scenario's count (--scale)" || fail "50 the record is not given the count"
# 28: every file the ladder measurement writes can be committed: none of its names is matched by .gitignore (the load tool's log was once
# named k6.log and was dropped by *.log). The names are the ones the script writes under "$d/", "$sdir/" and the ones check-limits writes in
# its --dir; this test never reaches the load tool, so the names are read from the scripts.
ign_dir="docs/research/lab-data/ladder/t-4-10000/rung-01-1"
names="$( { grep -ohE '"\$(d|sdir)/[A-Za-z0-9._-]+"' "$lab/bin/measure-ladder" | sed -E 's|^"\$[a-z]+/||; s|"$||'
            grep -ohE '"\$dir/[A-Za-z0-9._-]+"' "$lab/bin/check-limits" | sed -E 's|^"\$dir/||; s|"$||'
            printf 'summary.json\n'; } | sort -u )"   # summary.json is written by the load tool itself (SUMMARY=...)
n_names="$(printf '%s\n' "$names" | grep -c .)"
leaked="$(for nm in $names; do git -C "$lab" check-ignore -q --no-index "$ign_dir/$nm" && printf '%s ' "$nm"; done)"
[ "$n_names" -ge 20 ] && [ -z "$leaked" ] && pass "28 none of the $n_names output names is ignored by git (.gitignore)" || fail "28 names ignored by git: ${leaked:-none}; names checked: $n_names"
grep -q 'k6-output.txt' "$lab/bin/measure-ladder" && ! grep -qE 'k6\.log' "$lab/bin/measure-ladder" && pass "28b the load tool's output is written as k6-output.txt" || fail "28b measure-ladder still names k6.log"

echo
echo "ladder gate tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

#!/usr/bin/env bash
# Tests of `bin/predict-ladder --drop-marked-routes` (docs/simulator-spec.md section 7.4.4) through the command itself, on the committed records
# of the fourth lab backtest. They need the PHP of this machine or the already built PHP image (bin/predict-ladder says which), python3 and git; they
# start no container of the lab, touch no database and write only under a temporary directory. The estimates they read must be committed and unchanged
# (bin/predict-ladder checks that and exits 3 otherwise, which fails the test).
#
#   poc/lab/tests/predict-ladder-allocation.sh
#
#  1. without the flag, the prediction of B at 90,000 rows made again is byte for byte the committed one (ladder-prediction-4-b90k.json), but for the
#     one sentence on the maintenance state that D224 added to the note (ESTIMATE_MAINTENANCE_CLAUSE): that sentence is taken out of the new file first
#  2. the same for the mark at 150,000 rows (ladder-prediction-4-mark150k.json)
#  3. --request-timeout-s without --drop-marked-routes is refused (2)
#  4. with the flag at 90,000 rows (no route marked there) it exits 0 and the output is the plain one with the allocation blocks added
#  5. with the flag at 150,000 rows a marked route is taken out, the sum of rps is unchanged, the naive limit is unchanged, and the route is out of every stage
#  6. a group left with no route (the marked route alone at its rps) stops with exit 38 and writes no prediction
#  7. a request timeout under which a request is predicted over half of it stops with exit 39 and writes no prediction
#  7d-7h. the Bagisto B's request timeout of 2,400 s (the maintainer's ruling of 2026-11-27): accepted; the stop line is half of it, 1,200 s; the output is the 1,800 s one in
#     nothing but the timeout and its half, also with --warmup-from-marks (warmup_sec does not depend on the timeout)
#  8. a scenario file with no placeholder limit (or no memory limit) stops with exit 40 and writes no prediction
#  9. --warmup-from-marks (section 7.4.5): refused without --drop-marked-routes (2); with it every stage has warmup_sec (a whole number, at least 60, the largest CPU-time
#     bound of the routes that stay, rounded up) and warmup_basis, nothing else in the output changes, a request over half of the timeout still stops (39), the same input
#     gives the same bytes, and the committed predictions have no warmup_sec
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"
repo="$(cd "$lab/../.." && pwd)"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
expect_code() { if [ "$3" -eq "$2" ]; then pass "$1"; else fail "$1 (exit $3, wanted $2)"; fi; }

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
est90="$repo/docs/research/lab-data/estimate-90k/90k"
est150="$repo/docs/research/lab-data/estimate-150k/150k"
in90="$lab/predictions/ladder-prediction-4-b90k-input.yaml"
in150="$lab/predictions/ladder-prediction-4-mark150k-input.yaml"

predict() {  # predict <out dir> <estimate> <scenario> [flags...]; prints nothing, returns the exit code of the command
  local o="$1" e="$2" s="$3"; shift 3
  mkdir -p "$o"
  "$lab/bin/predict-ladder" --estimate="$e" --scenario="$s" --out="$o" --naive-count=10000 --expect-estimate-commit=a2598db "$@" >"$o/stdout.txt" 2>"$o/stderr.txt"
}

# The committed predictions were written before D224 added one sentence to the note of what the estimates assume. They are not rewritten; the
# new output is compared with them once that sentence (read from the constant in PredictionReport.php) is taken out of it.
without_clause() {  # without_clause <prediction.json>: the file on stdout, the sentence taken out
  python3 - "$1" "$repo/poc/runtime/src/Simulate/Predict/PredictionReport.php" <<'PY'
import re, sys
clause = re.search(r"ESTIMATE_MAINTENANCE_CLAUSE = '([^']*)';", open(sys.argv[2]).read()).group(1)
sys.stdout.write(open(sys.argv[1]).read().replace(clause, ""))
PY
}

# 1, 2
predict "$work/p90" "$est90" "$in90"; expect_code "1a plain at 90,000 rows: exit 0" 0 $?
grep -q "same maintenance state" "$work/p90/ladder-prediction.json" && pass "1c the new output carries the sentence on the maintenance state" || fail "1c the new output has no such sentence"
cmp -s <(without_clause "$work/p90/ladder-prediction.json") "$lab/predictions/ladder-prediction-4-b90k.json" && pass "1b plain at 90,000 rows: the committed bytes (but for the D224 sentence)" || fail "1b plain at 90,000 rows: not the committed bytes"
predict "$work/p150" "$est150" "$in150"; expect_code "2a plain at 150,000 rows: exit 0" 0 $?
cmp -s <(without_clause "$work/p150/ladder-prediction.json") "$lab/predictions/ladder-prediction-4-mark150k.json" && pass "2b plain at 150,000 rows: the committed bytes (but for the D224 sentence)" || fail "2b plain at 150,000 rows: not the committed bytes"

# 3
predict "$work/u" "$est90" "$in90" --request-timeout-s=1800; expect_code "3a --request-timeout-s alone is refused" 2 $?
[ ! -e "$work/u/ladder-prediction.json" ] && pass "3b ... and nothing is written" || fail "3b ... and nothing is written"

# 4
predict "$work/f90" "$est90" "$in90" --drop-marked-routes; expect_code "4a flag at 90,000 rows: exit 0" 0 $?
python3 - "$work/p90/ladder-prediction.json" "$work/f90/ladder-prediction.json" <<'PY' && pass "4b flag at 90,000 rows: the plain output plus the allocation blocks, nothing dropped" || fail "4b flag at 90,000 rows: not the plain output plus the allocation blocks"
import json, sys
plain, flagged = (json.load(open(p)) for p in sys.argv[1:3])
a = flagged.pop("allocation")
assert a["enabled"] is True and a["request_timeout_s"] == 1800.0 and a["half_request_timeout_s"] == 900.0, a
for s in flagged["scenarios"]:
    assert s.pop("allocation")["dropped_routes"] == []
assert plain == flagged
PY

# 5
predict "$work/f150" "$est150" "$in150" --drop-marked-routes; expect_code "5a flag at 150,000 rows: exit 0" 0 $?
python3 - "$work/p150/ladder-prediction.json" "$work/f150/ladder-prediction.json" <<'PY' && pass "5b flag at 150,000 rows: the marked route is out, the sum and the naive limit are the same" || fail "5b flag at 150,000 rows: the allocation is not as stated"
import json, sys
plain, flagged = (json.load(open(p)) for p in sys.argv[1:3])
for p, f in zip(plain["scenarios"], flagged["scenarios"]):
    al = f["allocation"]
    dropped = [d["route"] for d in al["dropped_routes"]]
    assert dropped, "no route was taken out at 150,000 rows"
    assert set(dropped) == set(p["app_limit_risk_placeholders"]["marked_routes"]) | set(p["app_limit_risk_memory"]["marked_routes"])
    assert abs(al["rps_total_in_file"] - al["rps_total_after"]) < 1e-12
    assert f["limit_rps"]["naive"] == p["limit_rps"]["naive"]
    for st in f["stages"]:
        assert not set(dropped) & set(st["rps"]), "a dropped route is still in a stage"
        assert abs(sum(st["rps"].values()) - st["rps_total"]) < 1e-9
    assert set(f["stages"][0]["rps"]) == set(p["stages"][0]["rps"]) - set(dropped)
PY

# 6: the marked route (found above) alone at its rps in s-1
route="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["scenarios"][0]["allocation"]["dropped_routes"][0]["route"])' "$work/f150/ladder-prediction.json")"
python3 - "$in150" "$work/alone.yaml" "$route" <<'PY'
import re, sys
text = open(sys.argv[1]).read()
head, s1 = text.split("  - id: s-1\n")
s1 = re.sub(r'("%s": )[0-9.]+' % re.escape(sys.argv[3]), r'\g<1>0.5', s1)
open(sys.argv[2], "w").write(head + "  - id: s-1\n" + s1)
PY
predict "$work/e38" "$est150" "$work/alone.yaml" --drop-marked-routes; expect_code "6a a group left empty: exit 38" 38 $?
[ ! -e "$work/e38/ladder-prediction.json" ] && pass "6b ... and no prediction is written" || fail "6b ... and no prediction is written"
grep -q 'a group is left with no route' "$work/e38/stderr.txt" && pass "6c ... and it says why" || fail "6c ... and it says why"

# 7: ZIP is predicted over 50 s of php CPU time for one request; a timeout of 100 s makes the half 50 s
predict "$work/e39" "$est150" "$in150" --drop-marked-routes --request-timeout-s=100; expect_code "7a a request over half of the timeout: exit 39" 39 $?
[ ! -e "$work/e39/ladder-prediction.json" ] && pass "7b ... and no prediction is written" || fail "7b ... and no prediction is written"
grep -q 'more than half of the request timeout' "$work/e39/stderr.txt" && pass "7c ... and it says why" || fail "7c ... and it says why"

# 7d: the Bagisto B's request timeout is 2,400 s (the maintainer's ruling of 2026-11-27): the same step at 2,400 passes, the stop line is half of it (1,200 s),
# and nothing else in the output differs from the one made at 1,800 s: the rule is the same, only the input timeout is
predict "$work/t1800" "$est150" "$in150" --drop-marked-routes --request-timeout-s=1800; expect_code "7d at 1,800 s: exit 0" 0 $?
predict "$work/t2400" "$est150" "$in150" --drop-marked-routes --request-timeout-s=2400; expect_code "7e at 2,400 s: exit 0" 0 $?
python3 - "$work/t1800/ladder-prediction.json" "$work/t2400/ladder-prediction.json" <<'PY' && pass "7f at 2,400 s the stop line is 1,200 s, and the output is the 1,800 s one in nothing else" || fail "7f the 2,400 s output differs from the 1,800 s one in more than the timeout and its half"
import json, sys
a, b = (json.load(open(p)) for p in sys.argv[1:3])
assert a["allocation"]["request_timeout_s"] == 1800.0 and a["allocation"]["half_request_timeout_s"] == 900.0, a["allocation"]
assert b["allocation"]["request_timeout_s"] == 2400.0 and b["allocation"]["half_request_timeout_s"] == 1200.0, b["allocation"]
for d in (a, b):
    d["allocation"].pop("request_timeout_s"); d["allocation"].pop("half_request_timeout_s")
assert a == b
PY
predict "$work/t2400w" "$est150" "$in150" --drop-marked-routes --warmup-from-marks --request-timeout-s=2400; expect_code "7g at 2,400 s with --warmup-from-marks: exit 0" 0 $?
predict "$work/t1800w" "$est150" "$in150" --drop-marked-routes --warmup-from-marks --request-timeout-s=1800
python3 - "$work/t1800w/ladder-prediction.json" "$work/t2400w/ladder-prediction.json" <<'PY' && pass "7h the discarded start (warmup_sec) does not depend on the request timeout: the same at 1,800 s and 2,400 s" || fail "7h warmup_sec differs between 1,800 s and 2,400 s"
import json, sys
a, b = (json.load(open(p)) for p in sys.argv[1:3])
wa = [[st["warmup_sec"] for st in s["stages"]] for s in a["scenarios"]]
wb = [[st["warmup_sec"] for st in s["stages"]] for s in b["scenarios"]]
assert wa == wb and wa and all(w for w in wa), (wa, wb)
PY

# 8
grep -v 'db_placeholder_limit' "$in150" >"$work/no-ph.yaml"
predict "$work/e40a" "$est150" "$work/no-ph.yaml" --drop-marked-routes; expect_code "8a no placeholder limit: exit 40" 40 $?
grep -v 'app_memory_limit' "$in150" >"$work/no-mem.yaml"
predict "$work/e40b" "$est150" "$work/no-mem.yaml" --drop-marked-routes; expect_code "8b no memory limit: exit 40" 40 $?
[ ! -e "$work/e40a/ladder-prediction.json" ] && [ ! -e "$work/e40b/ladder-prediction.json" ] && pass "8c ... and no prediction is written" || fail "8c ... and no prediction is written"

# 9: --warmup-from-marks (simulator-spec.md section 7.4.5)
predict "$work/w0" "$est150" "$in150" --warmup-from-marks; expect_code "9a --warmup-from-marks without --drop-marked-routes is refused (2)" 2 $?
[ ! -e "$work/w0/ladder-prediction.json" ] && pass "9b ... and nothing is written" || fail "9b ... and nothing is written"
predict "$work/w1" "$est150" "$in150" --drop-marked-routes --warmup-from-marks; expect_code "9c flag at 150,000 rows: exit 0" 0 $?
python3 - "$work/f150/ladder-prediction.json" "$work/w1/ladder-prediction.json" <<'PY' && pass "9d every stage has warmup_sec = max(60, ceil(largest CPU-time bound of the routes that stay)), and nothing else changed" || fail "9d the warm-up is not as the rule says"
import json, math, sys
plain, w = (json.load(open(p)) for p in sys.argv[1:3])
top = w.pop("warmup")
assert top["enabled"] is True and top["minimum_sec"] == 60 and top["rule"], top
for ws in w["scenarios"]:
    dropped = {d["route"] for d in ws["allocation"]["dropped_routes"]}
    assert dropped, "nothing was taken out at 150,000 rows"
    longest = ws["allocation"]["longest_request"]
    for st in ws["stages"]:
        assert isinstance(st["warmup_sec"], int) and st["warmup_sec"] >= 60
        b = st["warmup_basis"]
        assert b["route"] in st["rps"] and b["route"] not in dropped, "the basis is not a route of the stage"
        assert st["warmup_sec"] == max(60, math.ceil(b["upper_bound_sec"]))
        assert b["upper_bound_sec"] <= longest["php_cpu_bound_s"] + 1e-9, "a bound above the longest request of the file"
    assert len({(st["warmup_sec"], st["warmup_basis"]["route"]) for st in ws["stages"]}) == 1, "the bound of a request does not depend on the stage"
    for st in ws["stages"]:
        st.pop("warmup_sec"); st.pop("warmup_basis")
assert plain == w, "the flag changed something besides the warm-up"
PY
predict "$work/w2" "$est150" "$in150" --drop-marked-routes --warmup-from-marks --request-timeout-s=100; expect_code "9e a request over half of the timeout still stops (39)" 39 $?
[ ! -e "$work/w2/ladder-prediction.json" ] && pass "9f ... and no prediction is written" || fail "9f ... and no prediction is written"
predict "$work/w3" "$est150" "$in150" --drop-marked-routes --warmup-from-marks; cmp -s "$work/w1/ladder-prediction.json" "$work/w3/ladder-prediction.json" && pass "9g the same input gives the same bytes" || fail "9g the same input gave different bytes"
for old in "$lab"/predictions/ladder-prediction*.json; do
  if grep -q '"warmup_sec"' "$old"; then fail "9h $(basename "$old") has a warmup_sec (the committed predictions are not rewritten)"; else pass "9h $(basename "$old") has no warmup_sec (not rewritten)"; fi
done

echo
echo "predict-ladder allocation tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

#!/usr/bin/env bash
# Tests of the allocation check of the prediction gate (docs/simulator-spec.md section 7.4.4). They need git, bash and python3 only: no Docker, no PHP,
# no lab. The made-up predictions below have the shape `predict-ladder --drop-marked-routes` writes; the plain ones are the committed records.
#
#   poc/lab/tests/prediction-gate-allocation.sh
#
#  1. every committed prediction that has no `allocation` (made without the flag) is open, with the same line as before
#  2. a prediction made with the flag, stated in full, is open (one route taken out; and one with nothing taken out)
#  3. the same, with one part missing or not adding up, is closed (12) and says which part:
#     3a top block gone / 3b enabled false / 3c request timeout missing / 3d a rule empty / 3e one scenario's allocation gone /
#     3f dropped_routes gone / 3g a dropped route with no reason / 3h a dropped route still in rps_after / 3i a dropped route still in a stage /
#     3j totals that differ / 3k rps_after with a route that was never in the file / 3l no stages / 3m not JSON but it says "allocation"
#  4. a plain prediction that is not even an object is still open (the gate does not read what has no allocation: nothing changed for it)
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
expect_code() { if [ "$3" -eq "$2" ]; then pass "$1"; else fail "$1 (exit $3, wanted $2)"; fi; }

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
repo="$work/repo"; mkdir -p "$repo/poc/lab/predictions"
git -C "$repo" init -q . && git -C "$repo" config user.email t@example.invalid && git -C "$repo" config user.name t
echo seed >"$repo/README"; git -C "$repo" add README && git -C "$repo" commit -q -m "chore: seed"
pred="$repo/poc/lab/predictions/p.json"
gate() { PEAK_REPO_ROOT="$repo" "$lab/bin/prediction-gate" "$pred" >"$work/gate.out" 2>&1; }
commit_pred() { git -C "$repo" add poc/lab/predictions/p.json && git -C "$repo" commit -q -m "feat: add prediction" --allow-empty; }

# 1: every committed prediction without an allocation
n=0
for f in "$lab"/predictions/*.json; do
  if grep -q '"allocation"' "$f"; then continue; fi
  cp "$f" "$pred"; commit_pred; n=$((n + 1))
  gate; rc=$?
  if [ "$rc" -eq 0 ] && grep -q "^PREDICTION OK poc/lab/predictions/p.json $(git -C "$repo" rev-parse HEAD) " "$work/gate.out"; then pass "1 plain $(basename "$f"): open"; else fail "1 plain $(basename "$f"): exit $rc ($(head -1 "$work/gate.out"))"; fi
done
[ "$n" -ge 8 ] && pass "1z $n committed plain predictions were tried" || fail "1z only $n committed plain predictions were found"

make() {  # make <variant>: writes the made-up prediction (variant ok / ok-none / a mutation) to $pred
  python3 - "$pred" "$1" <<'PY'
import copy, json, sys
path, variant = sys.argv[1:3]
def scen(sid, drop):
    before = {"GET /a": 0.4, "GET /b": 0.4, "GET /c": 0.1, "GET /d": 0.1}
    dropped = [{"route": "GET /b", "reasons": ["placeholders", "memory"], "rps_in_file": 0.4}] if drop else []
    after = dict(before)
    if drop:
        del after["GET /b"]; after["GET /a"] = 0.8
    stages = [{"rps_total": t, "rps": {k: v * t for k, v in after.items()}, "utilization": {}, "naive": {}, "over_threshold": False} for t in (1.0, 2.0)]
    return {"id": sid, "tables": {"references": 150000}, "rps_total_in_file": 1.0, "stages": stages,
            "allocation": {"rule": "r", "dropped_routes": dropped, "groups_changed": [], "rps_in_file": before, "rps_after": after,
                           "rps_total_in_file": sum(before.values()), "rps_total_after": sum(after.values()),
                           "not_judged": {"placeholders": [], "memory": []}, "naive_estimate": "unchanged",
                           "longest_request": {"route": "GET /a", "php_cpu_bound_s": 1.5}}}
top = {"enabled": True, "request_timeout_s": 1800.0, "half_request_timeout_s": 900.0, "drop_rule": "d", "stop_rule": "s"}
d = {"schema_version": 1, "scenarios": [scen("s-1", variant != "ok-none"), scen("s-2", variant != "ok-none")], "allocation": top}
a0 = d["scenarios"][0]["allocation"]
if variant == "3a": del d["allocation"]
elif variant == "3b": top["enabled"] = False
elif variant == "3c": del top["request_timeout_s"]
elif variant == "3d": top["stop_rule"] = " "
elif variant == "3e": del d["scenarios"][1]["allocation"]
elif variant == "3f": del a0["dropped_routes"]
elif variant == "3g": a0["dropped_routes"][0]["reasons"] = []
elif variant == "3h": a0["rps_after"]["GET /b"] = 0.0; a0["rps_total_after"] = sum(a0["rps_after"].values())
elif variant == "3i": d["scenarios"][0]["stages"][1]["rps"]["GET /b"] = 0.5
elif variant == "3j": a0["rps_total_after"] = 1.5
elif variant == "3k": a0["rps_after"]["GET /zzz"] = 0.0
elif variant == "3l": d["scenarios"][0]["stages"] = []
json.dump(d, open(path, "w"), indent=1)
if variant == "3m": open(path, "w").write('{"allocation": {"enabled": tru')
PY
}

for v in ok ok-none; do
  make "$v"; commit_pred; gate; rc=$?
  expect_code "2 flagged, stated in full ($v): open" 0 $rc
done
declare -A why=([3a]="no allocation block at the top" [3b]="enabled is not true" [3c]="request_timeout_s" [3d]="stop_rule" [3e]="scenario s-2 has no allocation" \
  [3f]="dropped_routes" [3g]="has no reason" [3h]="rps_after is not rps_in_file without" [3i]="a stage still carries a dropped route" \
  [3j]="not the sum of its rps" [3k]="rps_after is not rps_in_file without" [3l]="has no stages" [3m]="not readable as JSON")
for v in 3a 3b 3c 3d 3e 3f 3g 3h 3i 3j 3k 3l 3m; do
  make "$v"; commit_pred; gate; rc=$?
  expect_code "$v flagged, a part wrong: closed" 12 $rc
  grep -q "${why[$v]}" "$work/gate.out" && pass "${v}b it says why (${why[$v]})" || fail "${v}b it does not say why: $(head -2 "$work/gate.out" | tr '\n' ' ')"
done

# 4
echo '[1, 2, 3]' >"$pred"; commit_pred; gate; expect_code "4 a plain file that is not an object: still open" 0 $?

echo
echo "prediction-gate allocation tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]

#!/usr/bin/env bash
# Checks the verdict scripts of this directory before they are used on a record.
#   known answers : records whose verdicts were written and committed earlier must give the same figures
#   made-up records: records in the shape a dry run writes (make_fake.py) must run to the end and give figures in every place
# Reads committed files, writes only under the scratch directory given (or a new temporary one). No database, no app, no lab.
#   bash selftest.sh [<scratch dir>]
# Exit 0 when every check passes (SKIP counts as a failure: there is no skip here), 1 otherwise.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(git -C "$here" rev-parse --show-toplevel)"
scratch="${1:-$(mktemp -d)}"
mkdir -p "$scratch"
cd "$root" || exit 1
pass=0; fail=0
check() { # check <name> <command...>
  local name="$1"; shift
  if "$@" >"$scratch/last.out" 2>&1; then pass=$((pass + 1)); echo "ok   $name"; else fail=$((fail + 1)); echo "FAIL $name"; sed 's/^/     /' "$scratch/last.out" | head -8; fi
}
py() { python3 -I "$@"; }
jq_py() { python3 -I -c "import json,sys; d=json.load(open(sys.argv[1])); print(eval(sys.argv[2]))" "$@"; }
expect() { # expect <file> <python expression over d> <expected>
  [ "$(jq_py "$1" "$2")" = "$3" ]
}

P=poc/lab/predictions
M=docs/research/extrapolation-data-3/measured

# ---- known answers: B at 90,000 rows (verdict written in docs/research/lab-backtest-4.md, section 6.2)
check "B 90,000 rows: runs" py "$here/judge_b.py" --prediction $P/ladder-prediction-4-b90k.json --ladder-dir docs/research/lab-data/ladder-90k --out "$scratch/b90" --captured-max 30000
check "B 90,000 rows: pass" expect "$scratch/b90/b-verdict.json" "d['verdict']" pass
check "B 90,000 rows: 32 usable steps, 5 limits" expect "$scratch/b90/b-verdict.json" "(d['counts']['usable_stages'], d['counts']['scenarios_with_limit'])" "(32, 5)"
check "B 90,000 rows: item 3 is 47 of 48" expect "$scratch/b90/b-verdict.json" "(d['items']['3']['agree'], d['items']['3']['valid_stages'])" "(47, 48)"
check "B 90,000 rows: item 4 is 5 of 5 at both tolerances" expect "$scratch/b90/b-verdict.json" "(d['items']['4']['within_minimum'], d['items']['4']['within_target'])" "(5, 5)"
check "B 90,000 rows: mean 1.035 points, naive 28.653" expect "$scratch/b90/b-verdict.json" "('%.3f %.3f' % (d['items']['2']['mean_points'], d['items']['1']['mean_points_naive']))" "1.035 28.653"
check "B 90,000 rows: not counted for condition 1b (3 times the capture)" expect "$scratch/b90/b-verdict.json" "d['condition_1b_counted']" False
# ---- known answers: B of the first round (score committed in docs/research/lab-data/score/)
check "B first round: runs" py "$here/judge_b.py" --prediction $P/ladder-prediction.json --ladder-dir docs/research/lab-data/ladder --out "$scratch/b1"
check "B first round: the tool's score.json is the committed one" cmp "$scratch/b1/score/score.json" docs/research/lab-data/score/score.json
check "B first round: fail" expect "$scratch/b1/b-verdict.json" "d['verdict']" fail
# ---- known answers: A of the third run (R1 committed in $M/compare/R1)
empty="$scratch/emptyvar"; mkdir -p "$empty"
check "A third run: runs" py "$here/judge_a.py" --out "$scratch/a3" \
  --app fixtures $P/extrapolation-3-fixtures-estimate.json $P/extrapolation-3-fixtures-marked.json $M/fixtures/attempts.jsonl "$empty" \
  --app bookstack $P/extrapolation-3-bookstack-estimate.json $P/extrapolation-3-bookstack-marked.json $M/bookstack/attempts.jsonl $M/variance/bookstack
check "A third run: compare.json is the committed R1" cmp "$scratch/a3/R1/compare.json" $M/compare/R1/compare.json
check "A third run: fixtures pass, BookStack fail" expect "$scratch/a3/a-verdict.json" "(d['apps']['fixtures']['verdict'], d['apps']['bookstack']['verdict'])" "('pass', 'fail')"
check "A third run: BookStack rows examined 24 of 33, short 3" expect "$scratch/a3/a-verdict.json" "(lambda x: (x['inside'], x['denominator'], x['needed'], x['short']))(d['apps']['bookstack']['condition_1a']['minimum_all_rows_examined'])" "(24, 33, 27, 3)"
check "A third run: 18 misses (16 marked, 2 unmarked), none on the verification app" expect "$scratch/a3/a-verdict.json" "(d['apps']['bookstack']['condition_3_misses_marked'], d['apps']['bookstack']['condition_3_misses_unmarked'], len(d['apps']['fixtures']['condition_3_misses']))" "(16, 2, 0)"
check "conditions table from the two known answers" py "$here/conditions.py" --out "$scratch/c" --a "$scratch/a3/a-verdict.json" --b "$scratch/b90/b-verdict.json"
check "conditions table: BookStack 18 remaining (provisional)" expect "$scratch/c/conditions.json" "d['condition_3']['bookstack']['remaining']" 18
# ---- order check: known answers and a case that must fail
check "order check B 90,000 rows holds" py "$here/order_check.py" --out "$scratch/o90.md" --prediction $P/ladder-prediction-4-b90k.json --ladder-dir docs/research/lab-data/ladder-90k
printf 'measure_start 2000-01-01T00:00:00+09:00\n' >"$scratch/early.txt"
check "order check fails (exit 1) when a measurement is before the commit" bash -c "python3 -I '$here/order_check.py' --out '$scratch/oearly.md' --prediction $P/ladder-prediction-4-b90k.json --order-record '$scratch/early.txt'; [ \$? -eq 1 ]"
# ---- the pinned tool: a changed copy must stop the script (exit 3)
cp docs/research/extrapolation-data-2/compare_test2.py "$scratch/compare_changed.py"; printf '\n# changed\n' >>"$scratch/compare_changed.py"
check "a changed comparison tool stops judge_a (exit 3)" bash -c "python3 -I '$here/judge_a.py' --out '$scratch/a-bad' --tool '$scratch/compare_changed.py' --app fixtures $P/extrapolation-3-fixtures-estimate.json $P/extrapolation-3-fixtures-marked.json $M/fixtures/attempts.jsonl '$empty'; [ \$? -eq 3 ]"

# ---- made-up records
check "make_fake" py "$here/make_fake.py" --out "$scratch/fake"
check "made-up B, 2 steps each (the dry-run shape): runs" py "$here/judge_b.py" --prediction "$scratch/fake/b/prediction.json" --ladder-dir "$scratch/fake/b/ladders" --out "$scratch/fb" --captured-max 30000
check "made-up B, 2 steps each: undecidable for both reasons, items still shown" expect "$scratch/fb/b-verdict.json" "(d['verdict'], len(d['undecidable_reasons']), sorted(d['items']))" "('undecidable', 2, ['1', '2', '3', '4'])"
check "made-up B, 2 steps each: the tool and the script agree" expect "$scratch/fb/b-verdict.json" "d['tool_and_script_agree_on_undecidable']" True
check "made-up B, full ladders: runs" py "$here/judge_b.py" --prediction "$scratch/fake/b-full/prediction.json" --ladder-dir "$scratch/fake/b-full/ladders" --out "$scratch/fbf" --captured-max 30000
check "made-up B, full ladders: decided, with a limit rps for 4 scenarios or more" expect "$scratch/fbf/b-verdict.json" "(d['verdict'] != 'undecidable', d['counts']['scenarios_with_limit'] >= 4)" "(True, True)"
check "made-up B with no usable step: runs (the means are null) and is undecidable" bash -c "python3 -I '$here/judge_b.py' --prediction '$scratch/fake/b-none/prediction.json' --ladder-dir '$scratch/fake/b-none/ladders' --out '$scratch/fbn' --captured-max 30000 && python3 -I -c \"import json; d=json.load(open('$scratch/fbn/b-verdict.json')); assert d['verdict']=='undecidable' and d['counts']['usable_stages']==0\""
F="$scratch/fake/a"
check "made-up A: runs" py "$here/judge_a.py" --out "$scratch/fa" \
  --app fixtures $F/fixtures/estimate.json $F/fixtures/marked.json $F/fixtures/attempts.jsonl $F/fixtures/variance \
  --app bookstack $F/bookstack/estimate.json $F/bookstack/marked.json $F/bookstack/attempts.jsonl $F/bookstack/variance
check "made-up A: a verdict for each application and the four numbers" expect "$scratch/fa/a-verdict.json" "(sorted(d['apps']), all(len(v['condition_1a']) == 4 for v in d['apps'].values()))" "(['bookstack', 'fixtures'], True)"
check "made-up A: a route whose measured value is 0 is listed, not a crash" expect "$scratch/fa/a-verdict.json" "any(m['route'] == 'GET /hidden/appends' for m in d['apps']['fixtures']['condition_3_misses'])" True
: >"$scratch/none.jsonl"
check "made-up A with nothing measured: undecidable, no number short claimed met" bash -c "python3 -I '$here/judge_a.py' --out '$scratch/fa0' --app fixtures $F/fixtures/estimate.json $F/fixtures/marked.json '$scratch/none.jsonl' $F/fixtures/variance && python3 -I -c \"import json; v=json.load(open('$scratch/fa0/a-verdict.json'))['apps']['fixtures']; assert v['verdict']=='undecidable' and not v['condition_1a_short_all_zero']\""
check "conditions table from made-up records" py "$here/conditions.py" --out "$scratch/fc" --a "$scratch/fa/a-verdict.json" --b "$scratch/fb/b-verdict.json"

echo
echo "passed $pass, failed $fail (skipped 0)"
echo "scratch: $scratch"
[ "$fail" -eq 0 ]

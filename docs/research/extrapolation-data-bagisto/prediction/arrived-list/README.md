# The routes whose data seems to have arrived, and the count on them alone

Test A on the Bagisto application is counted twice: on every route whose estimate is complete (the count of the rules of test A), and on the routes of this folder's list alone. The second count is an extra line on condition 2: test A counts as met only if the second count also meets the same line (at least 80% of all routes inside the lowest standard, at least 80% of the unmarked routes inside the goal; a quantity whose denominator has fewer than 5 routes is undecidable, and an undecidable count does not meet the line). It was fixed before any estimate of the 240,000-row round was made, and before any measurement.

The reason is in the capture. For 38 of the 57 routes whose estimate can complete, the capture's own mark says "the data may not have reached this route": the answer or the rows read are the same at every row count, so a flat count is not evidence that the route does not grow. Such routes are easy to estimate, and a pass of the full count can rest on them. The list holds the other 19.

## What is here

| File | What it is |
| --- | --- |
| `list-arrived.txt`, `list-arrived.tsv` | the 19 routes (`METHOD /uri`, the form the estimate uses), with the capture's verdict and the row counts it was captured at |
| `list-marked-out.tsv` | the 38 routes taken out, each with the capture's own words for its mark |
| `list-not-complete.tsv` | the 6 captured routes whose estimate cannot complete (capture status `partial`); they are in neither list |
| `list-inputs.sha256`, `list-summary.txt` | SHA-256 of the two capture files the list was made from, and the counts |
| `make_arrived_list.py` | makes the five files above from the capture's `capture.json` and `routes.jsonl` |
| `count_listed.py` | counts test A on the listed routes with `judge_a.py`, unchanged |
| `selftest_listed.py` | three checks of `count_listed.py` against known answers |

## How the list is made (no judgment in it)

1. Routes whose estimate can complete: `capture.json` routes with status `captured`, every statement type of which has a count fit with status `ok` and at least one row count at which rows were read. This is the capture's half of the estimator's test (`poc/runtime/src/Estimate/RowEstimator.php`, `route()` and `type()`). The other half (the EXPLAIN reads) is known only when the estimate is made; `count_listed.py` reports any listed route that has no complete estimate.
2. Of these, the routes with the capture's mark (`data_not_arriving_suspected` in `routes.jsonl`) are taken out. The mark is the capture's; it is neither added nor removed.
3. The rest is the list. If the numbers are not 57 and 19 the script writes nothing and stops (exit 4); the list is not fixed up.

```bash
python3 -I make_arrived_list.py --capture-dir <capture output dir> --out <dir>
```

Run on the third capture (SHA-256 of `capture.json` and `routes.jsonl` in `list-inputs.sha256`) it gives 57 routes whose estimate can complete, 38 with the mark, 19 without. All 19 have the verdict `measured_O1` (the capture's `no_info` routes all carry the mark).

## How the count on the list is made

`count_listed.py` keeps from the committed estimate file and the committed marked file only the routes of the list, writes the two filtered copies, and runs `judge_a.py` on them and on the records as they are. `judge_a.py` and the comparison tool it pins (`compare_test2.py`, whose SHA-256 `judge_a.py` checks) are not changed, and no rule is copied. A measured route that is not in the filtered estimate is listed by the comparison tool as measured without an estimate and is not counted.

```bash
python3 -I count_listed.py --out <dir> --list list-arrived.txt \
  --judge-a docs/research/lab-data/dryrun-286/judge/judge_a.py \
  --app bagisto <estimate.json> <marked.json> <measure dir or attempts.jsonl> <variance dir>
```

It writes `count-listed.md` and `count-listed.json` (verdict of rule B, rule A shown; the four numbers of condition 1a; per quantity the routes in the list, those with a complete estimate, the denominator and the routes not measured; whether the line is met), the filtered copies of the two committed files in `filtered/`, the output of `judge_a.py` in `judge/`, and the SHA-256 of what it read.

Two things to read with the output:

- The comparison tool has two grounds for "undecidable": a denominator of fewer than 5 routes, and more than 20% of the routes that have an estimate not measured. The first is a count of routes and is the same on any list. The second is a share, so on 19 routes 4 routes not measured are enough (4 of 19 is over 20%), where on 57 routes 12 are needed. `count-listed.md` shows the two grounds in two columns.
- "Marked" in condition 1a is the mark of the plan (`poc/runtime/bin/marked-routes`), not the capture's mark that the list was made with. The two are different things that share a word.

## Checks

`selftest_listed.py` (from the repository root: `python3 -I <dir>/selftest_listed.py --work <empty dir>`):

1. the whole list of the fourth run's BookStack estimate (every route) gives, in the application's entry, exactly the committed `docs/research/extrapolation-data-4/compare/a-verdict.json`;
2. every other route of that list gives the same counts as a second reading written in the script from the unfiltered comparison output;
3. a list of four routes (one not in the estimate) is undecidable, the line is not met, and the missing route is named.

## What this does not say

The list was made from the capture only. Nothing was measured and nothing was estimated at 240,000 rows to make it. Whether the 19 routes pass is not known here.

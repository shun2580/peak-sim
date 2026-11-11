# Row estimate at 70,000 rows, and the check against the criteria of D182

The estimate for 70,000 rows, made from the capture and the rules already committed, and put beside the measurement of `../recheck-70000/` (the one-request-at-a-time
record). Nothing was changed after the measurement was seen: the estimate tool, the capture and the criteria are the ones that were committed before. 70,000 rows is not a
capture size (the capture has 100, 1,000, 10,000 and 30,000 rows) and is not used for a pass or a fail of the backtest (spec 7.4.1); it is the development size that
checks the repair of the row estimate (D174, D175, D179, D180).

## Order of events (local time, 2026-11-11)

| time | what |
| --- | --- |
| 14:58:14 | the measurement of 70,000 rows was committed (`b5aa97a`); the criteria (D182) were committed at an earlier commit (`96fa0b4`) |
| 15:01:43 to 15:03:14 | `bin/explain-at-scale` at 70,000 rows (small size 7,000), EXPLAIN only |
| 15:03:29 and 15:03:30 | `bin/estimate` run twice on the same inputs |

## Tool: unchanged since before the measurement

- The estimate and EXPLAIN code was made from `git archive b5aa97a` (the commit of the measurement). `HEAD` was `b5aa97a` while this was made; `git diff b5aa97a -- poc/runtime`
  is empty, and so is `git diff -- poc/lab/compose`.
- The last commit that touched `poc/runtime/src/Estimate`, `poc/runtime/src/Explain`, `poc/runtime/src/Simulate/Load/EstimateFormulas.php`, `poc/runtime/bin/estimate`,
  `poc/runtime/bin/explain-at-scale` or `poc/runtime/bin/marked-routes` is `32359c1` (14:15:30), before the measurement; the last commit that touched `poc/runtime` at all is `e54f832` (14:29:02).
- The capture is the one of D174's addendum (`--analyze-after-build=on`, 100 / 1,000 / 10,000 / 30,000 rows), SHA-256 `69f08fcad32bdd31367747d8231dab69df32596a837876253169eda88807d839`;
  its task list `tasks.json` is `2dd8145996fc89e35a2af7d938f5b57597683fbbc9c542f68feaed4cdbd7f815`.

## How the files were made

```
bin/explain-at-scale --capture=<capture.json> --tasks=<tasks.json> --app=<bookstack> --scale=70000 --small-scale=7000 \
  --n-tables=entities,images,references --out=<out> --label=bookstack-n70k --php-image=<image>
bin/predict --fits=docs/research/extrapolation-data-2/bookstack/fits.json --at=70000 > predictions.json   # read for route, axis and table only
bin/estimate --capture=<capture.json> --explain=<out>/bookstack-n70k/explain.json --candidate=H1_RANGE --at=70000 \
  --predictions=predictions.json --axes-only --with-formulas > estimate.json
bin/marked-routes --explain=<out>/bookstack-n70k/explain.json > marked-routes.json
python3 poc/lab/diagnosis/request_probe.py report --scale=70000 --dir=../recheck-70000/probe --estimate=estimate.json \
  --prediction=poc/lab/predictions/ladder-prediction.json --out=probe
```

- **The EXPLAIN was taken on a throw-away database, not on the lab's.** `bin/explain-at-scale` builds a database of 70,000 rows with the tool's generator (the three N tables
  grown together, the same way the lab's database was built), runs `ANALYZE TABLE` on every table, takes `EXPLAIN FORMAT=JSON` of each statement type in three statistics
  states (default, low and high sample size), and drops the database with its stack. The same tool and the same way made the 10,000-, 30,000- and 100,000-row EXPLAIN files
  of `../estimate-r3/`. There is no tool in the repository that sends `EXPLAIN` to the lab's database; writing one would be a change of the tool. The lab's databases and
  stack were not used, so their statistics were not changed and nothing had to be put back. Result: 76 statement types, 1 `plan_unstable`, 7 `plan_changes_at_scale`, 0 not
  available. `explain.json` is kept outside the repository, as before.
- The small size was one tenth of the target (7,000 rows), as in the earlier files (`../estimate-r3/README.md`). The row estimate itself reads the capture's largest size (30,000
  rows, `small_scale` in `estimate.json`); the small size is only for the plan comparison.
- The same inputs give the same output: `bin/estimate` was run twice and the two files compared byte for byte (identical, `cmp` exit 0).

| File | SHA-256 |
| --- | --- |
| `explain.json` at 70,000 rows (kept outside the repository) | `634b1ebbe13ee20047732baf8b5a2298b797a5a17cbf2bd128a43ba82238b534` |
| `estimate.json` (both runs) | `299f92f52ace40dcbf005493baeb5b4742a0c75f24277a576e9c7bba90906b5e` |
| `marked-routes.json` | `021eea17e26f7c39e6474ed1ac2a12717e1130df7e8e62d9a4121bbd34864c6e` |
| `probe/table.json` | `0823724144850b307b22baf11644ce5ef42f8741e627d8ccf2c2a2eac2c62c23` |
| the committed calibration `poc/lab/predictions/ladder-prediction.json` | `d0fb256dd420a84fea7886f7a6e67a63e1d28c16dbed688c0cc9674722623189` |

`probe/table.md` is the full table (rows, statements and DB CPU time, estimate ÷ measured).

## The criteria of D182, applied as written

Measured values are the ones in `../recheck-70000/` (median of three cold requests). Estimate ÷ measured, rows examined, point and upper side:

| route | rows measured | estimate (point) | point ÷ measured | upper side ÷ measured | statements est ÷ meas |
| --- | --- | --- | --- | --- | --- |
| `GET /books/{slug}` | 882,454 | 891,734 | **1.011** | 4.32 | 1.042 (46.9 / 45) |
| `GET /books/{bookSlug}/export/zip` | 584,696 | 588,007 | **1.006** | 3.79 | 1.000 |
| `GET /shelves/{slug}` | 236 | 267.3 | **1.133** | 1.166 | 1.000 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 222 | 223.25 | 1.006 | 1.006 | 1.000 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 229 | 233.0 | 1.018 | 1.018 | 1.000 |
| `GET /books/{slug}/references` | 217 | 218 | 1.005 | 1.005 | 1.000 |
| `GET /images/edit/{id}` | 199 | 199 | 1.000 | 1.000 | 1.000 |
| `GET /oidc/callback` | 0 | 0 | n/a (0 and 0) | n/a | n/a (0 and 0) |

| # | criterion (D182) | reading | met |
| --- | --- | --- | --- |
| 1 | the three repaired routes: point within ±20% of the measured rows (0.80 to 1.20) | 1.011, 1.006, 1.133 | yes |
| 1b | within ±10% as well (then the target level is also reached) | 1.011 and 1.006 yes; **1.133 for `GET /shelves/{slug}` no** | for 2 of 3 |
| 2 | the three repaired routes: the upper side at least the measured | 4.32, 3.79, 1.166 | yes |
| 3 | the other five routes: statements within ±5%, rows within ±20% | statements 1.000 on each of the four that have statements (n/a for the route with 0); rows 1.000 to 1.018 | yes |
| 4 | any of the three repaired routes below 0.80 (estimate ÷ measured) | none (smallest 1.006) | no point below 0.80 |

By the criteria as written, the repair is **effective** at 70,000 rows: all of 1 to 3 are met and 4 does not occur. The ±10% level is reached for `GET /books/{slug}` and the ZIP export and not for `GET /shelves/{slug}`,
which is 13.3% above the measured 236 rows (an absolute difference of 31 rows). The upper side of the book page and the ZIP export is 4.3 and 3.8 times the measured, far above it
(the upper side is a bound and not a point estimate). No rule, capture, shape, formula, calibration or coefficient was changed after this reading.

## Recorded only (not part of the criteria)

1. **DB CPU time, conversion ÷ measured** (the two coefficients of the committed calibration applied to the estimate's statements and rows). `GET /books/{slug}`: **2.24** (point), 9.51 (upper side);
   ZIP export: **1.03** (point), 1.16 (upper side). The other six routes read below the measurement floor, so no ratio is made. `GET /shelves/{slug}` would be 0.62 and is withheld by the same rule.
   Against the measured counts instead of the estimate's, the conversion is 1.011 (book page) and 1.000 (ZIP): the miss of the book page's time is in what a row or a statement costs (the calibration),
   not in the count. The DB-CPU-time error of the formula is worked out later from this measurement and the new calibration's coefficients; the formula is not changed (D176).
2. **PHP CPU time ÷ DB CPU time** (the mark's coefficient is 9.11, D178; not changed): book page 7.89, ZIP export 1.25, both below 9.11 (`../README.md`).
3. **Per statement rows, the four main statements** (`s7ff3e12408`, `s24834a57a6`, `s400220f42f`, `s58efd897dc`): **not obtainable.** The measurement of `../recheck-70000/` keeps rows per route (the server's
   `Handler_read_*` of one request), not per statement, and no new reading was taken. What the estimate says for them (rows per request = count × rows per execution, all four have `plan_changes_at_scale`):
   `s7ff3e12408` 183,172, `s24834a57a6` 169,098, `s400220f42f` 255,282, `s58efd897dc` 283,664 (`GET /books/{slug}`; `s7ff3e12408` is also in the ZIP export). Their sum is 891,216 of the route's 891,734,
   so the route total is almost all four statements. That the two route totals agree does not show that the four agree one by one; to show it a per-statement reading would be needed.
4. **The baselines made again against the first ones**: in `../README.md` (10,000 rows: the book page 78,460 to 126,464 and the ZIP export 53,698 to 83,696 rows, +61% and +56%, after `ANALYZE`; 30,000 rows: no
   change of note; statement counts the same everywhere). The routes that hold `s400220f42f` and `s58efd897dc` (the book page and the ZIP export) are the two whose 10,000-row rows changed.

## What this does not say

- It is one count that is not in the capture. It does not test the estimate at 50,000 or 200,000 rows, which were not touched.
- The EXPLAIN is from a freshly built, analyzed database of the same shape as the lab's, not from the lab's own database; the sample of an `ANALYZE TABLE` is of random pages, so the lab's own statistics can
  differ slightly.
- The measurement was one request at a time with the limits off; it says nothing about load, and nothing here is a prediction.

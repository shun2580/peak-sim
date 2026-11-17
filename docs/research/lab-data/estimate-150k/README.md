# Row estimate at 150,000 rows (for the fourth lab backtest)

The estimate of the rows each route reads at 150,000 rows, made from the capture and the rules that were already committed (the tangent rule, the books and ZIP rule, the capture of 100 / 1,000 / 10,000 / 30,000 rows made on analyzed databases), in the
same way and with the same tool as the 300,000-row estimate (`../estimate-300k/`), with one flag added: `--with-placeholders` (the input of the placeholder side of the mark, `docs/decisions.md` D218). This is an input for a prediction. It is **not** a prediction and
nothing here was measured: no request, no load, no timing and no handler count was taken at this size, and no tool that sends requests (`bin/measure`, `bin/variance-run`, `measure-*`) was started.

## The estimate assumes analyzed databases

**This estimate assumes a database whose statistics have been made up to date with `ANALYZE TABLE`.** The EXPLAIN behind it was taken on a freshly built database on which `ANALYZE TABLE` had been run on every table, and the capture it reads was made the same way
(`--analyze-after-build=on`). A database whose statistics are stale or were never gathered can get other plans, and then these row counts do not apply to it. The same sentence belongs in anything that uses these files.

## How far the estimate is from what was seen

The capture's largest size is 30,000 rows. The target is 150,000 / 30,000 = 5 times that; the longest extrapolation checked so far was 300,000 / 30,000 = 10 times (`../estimate-300k/`).

## Tool and inputs

- Tool: a `git archive` of `HEAD` = `cb4a711e267c39c997fdcd886ad7e1e405c2264a` (`poc/runtime` only, made so that the working tree of other work cannot reach the run). The last commit that touched `poc/runtime/src/Estimate`, `poc/runtime/src/Explain`,
  `poc/runtime/src/Simulate/Load/EstimateFormulas.php`, `poc/runtime/bin/estimate`, `poc/runtime/bin/explain-at-scale` or `poc/runtime/bin/marked-routes` is `e79d7b5` (2026-11-17 08:29:05: the `--with-placeholders` flag; it adds one key per statement type and changes no
  other field or rule, `docs/decisions.md` D218). The rule of the estimate, the calibration and the formulas are those of the 300,000-row estimate.
- Capture: SHA-256 `69f08fcad32bdd31367747d8231dab69df32596a837876253169eda88807d839` (the one the 300,000-row estimate read); task list `tasks.json` `2dd8145996fc89e35a2af7d938f5b57597683fbbc9c542f68feaed4cdbd7f815`. A new capture was not made.
- Candidate `H1_RANGE`, `--with-formulas` and `--with-placeholders`, **without `--axes-only`**. The predictions file given to `bin/estimate` was made by `bin/predict --at=150000` from the committed fits of the extrapolation study
  (`docs/research/extrapolation-data-2/bookstack/fits.json`, SHA-256 `5f409c300b490463b9a198e677f301e09125d288a0d06d89fbce19c01a3a518f`, not refitted); it is `predictions-150000.json` here.
- The small size of the EXPLAIN comparison is **15,000 rows** (one tenth of the target, the practice of `docs/decisions.md` D201: 5,000 and 20,000 at 50,000 and 200,000 rows, 30,000 at 300,000). It was fixed before any EXPLAIN of this count was taken (`order_record.txt`,
  the line `decision=`). The row estimate itself reads the capture's largest size (`small_scale` = 30,000 in `estimate-150000.json`).

## How the files were made

```
bin/explain-at-scale --capture=<capture.json> --tasks=<tasks.json> --app=<bookstack> --scale=150000 --small-scale=15000 --n-tables=entities,images,references --out=<out> --label=bookstack-n150000 --php-image=peak-measure-php-bookstack:8.3 --project-prefix=peak-t4-n150
bin/predict  --fits=<fits.json> --at=150000 > predictions-150000.json
bin/estimate --capture=<capture.json> --explain=<out>/bookstack-n150000/explain.json --candidate=H1_RANGE --at=150000 --predictions=predictions-150000.json --with-formulas --with-placeholders > estimate-150000.json   # twice
bin/marked-routes --explain=<out>/bookstack-n150000/explain.json > marked-routes-150000.json
```

- **The EXPLAIN was taken on throw-away databases, not on the lab's.** `bin/explain-at-scale` built a database of 150,000 rows and one of 15,000 rows in its own sealed stack (no network, preflight passed), ran `ANALYZE TABLE` on every table, took `EXPLAIN FORMAT=JSON` of each
  statement type in three statistics states for the large one (default 20 sample pages, low 4, high 200) and in the default state for the small one, and dropped both databases. The lab's databases were not used for it. (The lab's own 150,000-row database, `../build-150k/`, was built, counted and analyzed once, before this.)
- Times (`order_record.txt`, local time): `explain-at-scale` 2026-11-17T09:02:24+09:00 to 2026-11-17T09:05:15+09:00, `predict` 2026-11-17T09:02:24+09:00 to 2026-11-17T09:02:25+09:00, `estimate` 2026-11-17T09:05:35+09:00 to 2026-11-17T09:05:35+09:00 and 2026-11-17T09:05:35+09:00 to 2026-11-17T09:05:36+09:00, `marked-routes` 2026-11-17T09:05:36+09:00 to 2026-11-17T09:05:36+09:00.
- Same inputs, same output: `bin/estimate` was run twice and the two files compared byte for byte (identical, `cmp` exit 0).
- `statistics.json` was written from the `stats_*_before.tsv` and `stats_*_after_set.tsv` files of the run (the same fields as `../estimate-300k/statistics.json`); the small database has the default state only.

| File | SHA-256 |
| --- | --- |
| `estimate-150000.json` (both runs; the same bytes as `150k/estimate.json`) | `c8f364b9e2775ef93133c063a519537c8e197ce03e5c7b0f853e24b327fadff7` |
| `marked-routes-150000.json` | `fe3403af4d315d06a43add6663434a315e1bfa7595a2370b005b0cdc024d5a4f` |
| `predictions-150000.json` (input of `bin/estimate`) | `243050b2744628fbd8edf913890ad25cdaf51f2aa61d64b7b0aa93a472fd4b2e` |
| `statistics.json` | `532561ddc560f9ed04a65b9a120340bc7c62619f0d883ee452d67c21cab9f278` |
| `explain.json` (kept outside the repository) | `f0f0f1fe31ed15a1ca0f0e86a60668c442835e4647d9ef66e48390704596cf98` |

## What the estimate says

| route | statements per request | rows (point) | rows (upper side) |
| --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 8 | 223.25 | same as point |
| `GET /books/{bookSlug}/export/zip` | 179,998.84 | 1,262,123.95 | 1,270,519.95 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 8 | 235.24 | same as point |
| `GET /books/{slug}` | 47.57 | 1,917,282.24 | 1,943,557.57 |
| `GET /books/{slug}/references` | 7 | 218.0 | same as point |
| `GET /images/edit/{id}` | 6 | 199.0 | same as point |
| `GET /oidc/callback` | 0 | 0.0 | same as point |
| `GET /shelves/{slug}` | 18 | 275.38 | 388.64 |

- **The peak memory of all 8 routes is `status: predicted`** in the estimate (checked in the output: 8 of 8; none is `not_given`). This README does not read the values against any memory limit, which is the prediction's work.
- The EXPLAIN: 76 statement types, `plan_unstable` 3, `plan_changes_at_scale` 0, not available 0. Routes with a marked statement (of 7 in the EXPLAIN): 2, listed in `marked-routes-150000.json` (these marks are the EXPLAIN marks of the row estimate; they are not the app-limit mark of the prediction).
- `ratio_used` 3 routes, `per_exec_held` 1 route, fell back to the H1 point 0.
- The upper side over the point is 1.007 for the ZIP export and 1.014 for the book page. At 150,000 rows the marks come from `plan_unstable` only; the small size was fixed at one tenth before the EXPLAIN and not changed after it, and whether another small size would give other marks was not tried.

## What this does not say

- Nothing here checks the estimate at 150,000 rows against a measurement, and nothing says how long a request takes, what a limit does, or how many requests a stack handles.
- The EXPLAIN is from freshly built, analyzed databases of the same shape as the lab's, not from the lab's own; the sample of an `ANALYZE TABLE` is of random pages, so the lab's own statistics can differ slightly.

## Per-count copy for the prediction tool

`150k/estimate.json` is a byte-for-byte copy of `estimate-150000.json` (same SHA-256). The ladder prediction tool reads one `estimate.json` per directory and takes the commit of an estimate from the last commit that touched its directory, so the count needs a directory of its own.
Nothing in the estimate was changed.

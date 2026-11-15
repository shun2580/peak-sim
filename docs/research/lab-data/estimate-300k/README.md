# Row estimate at 300,000 rows (for the third lab backtest)

The estimate of the rows each route reads at 300,000 rows, made from the capture and the rules that were already committed (the tangent rule, the books and ZIP rule, the capture of
100 / 1,000 / 10,000 / 30,000 rows made on analyzed databases), in the same way and with the same tool as the 50,000-row and 200,000-row estimates (`../estimate-50k-200k/`), with the two
changes the record names below (the small size and the source of the peak memory). This is an input for a prediction. It is **not** a prediction and nothing here was measured: no request, no load,
no timing and no handler count was taken at this size, and no tool that sends requests (`bin/measure`, `bin/variance-run`, `measure-*`) was started (see `order_record.txt`).

## The estimate assumes analyzed databases

**This estimate assumes a database whose statistics have been made up to date with `ANALYZE TABLE`.** The EXPLAIN behind it was taken on a freshly built database on which `ANALYZE TABLE` had been run on
every table, and the capture it reads was made the same way (`--analyze-after-build=on`). A database whose statistics are stale or were never gathered can get other plans, and then these row counts do not apply
to it. The same sentence belongs in anything that uses these files.

## How far the estimate is from what was seen

The capture's largest size is 30,000 rows. The target is 300,000 / 30,000 = 10 times that; the longest extrapolation checked so far was 200,000 / 30,000 = 6.667 times (`../estimate-50k-200k/`).

## Tool and inputs

- Tool: a `git archive` of `HEAD` = `14a7de60eaf4c34155338f8b718b53743b61b36e` (`poc/runtime` only, made so that the working tree of other work cannot reach the run). The last commit that touched
  `poc/runtime/src/Estimate`, `poc/runtime/src/Explain`, `poc/runtime/src/Simulate/Load/EstimateFormulas.php`, `poc/runtime/bin/estimate`, `poc/runtime/bin/explain-at-scale` or `poc/runtime/bin/marked-routes`
  is `32359c1` (2026-11-11 14:15:30), the same as for the 50,000 and 200,000 rows. No code was changed for this.
- Capture: SHA-256 `69f08fcad32bdd31367747d8231dab69df32596a837876253169eda88807d839` (the one the 50,000 and 200,000-row estimates read); task list `tasks.json`
  `2dd8145996fc89e35a2af7d938f5b57597683fbbc9c542f68feaed4cdbd7f815`. A new capture was not made.
- Candidate `H1_RANGE`, `--with-formulas`, **without `--axes-only`** (D196 and D199 (7): with `--axes-only` the peak memory of every route is `not_given` and the memory side of the app-limit mark could not be set).
  The predictions file given to `bin/estimate` was made by `bin/predict --at=300000` from the committed fits of the extrapolation study
  (`docs/research/extrapolation-data-2/bookstack/fits.json`, SHA-256 `5f409c300b490463b9a198e677f301e09125d288a0d06d89fbce19c01a3a518f`, not refitted); it is `predictions-300000.json` here.
  Without `--axes-only` the estimate reads the route fit and the peak-memory formula of that file; the statements, rows and types are those of the EXPLAIN.
- The small size of the EXPLAIN comparison is **30,000 rows** (one tenth of the target, D199 (9)), as 5,000 and 20,000 were one tenth at 50,000 and 200,000 rows. The row estimate itself reads the capture's
  largest size (`small_scale` = 30,000), so here the two coincide. The size was fixed before the EXPLAIN was taken (D201).

## How the files were made

```
bin/explain-at-scale --capture=<capture.json> --tasks=<tasks.json> --app=<bookstack> --scale=300000 --small-scale=30000 --n-tables=entities,images,references --out=<out> --label=bookstack-n300 --php-image=peak-measure-php-bookstack:8.3 --project-prefix=peak-t4-n300
bin/predict  --fits=<fits.json> --at=300000 > predictions-300000.json
bin/estimate --capture=<capture.json> --explain=<out>/bookstack-n300/explain.json --candidate=H1_RANGE --at=300000 --predictions=predictions-300000.json --with-formulas > estimate-300000.json   # twice
bin/marked-routes --explain=<out>/bookstack-n300/explain.json > marked-routes-300000.json
```

- **The EXPLAIN was taken on throw-away databases, not on the lab's.** `bin/explain-at-scale` built a database of 300,000 rows and one of 30,000 rows in its own sealed stack (no network, preflight passed),
  ran `ANALYZE TABLE` on every table, took `EXPLAIN FORMAT=JSON` of each statement type in three statistics states for the large one (default 20 sample pages, low 4, high 200) and in the default state for the small one,
  and dropped both databases. The statements go through one door that lets `EXPLAIN FORMAT=JSON <data statement>` through and refuses anything else (no refusal happened; exit 0). The lab's databases
  and stack were not used for it: nothing was sent to the lab's database server by this work.
- Times (`order_record.txt`, local time): `explain-at-scale` 2026-11-16 01:22:34 to 01:30:43 (8 min 9 s), `predict` 01:22:47 to 01:22:48, `estimate` 01:31:07 to 01:31:08 (twice), `marked-routes` 01:31:08 to 01:31:09.
- Same inputs, same output: `bin/estimate` was run twice and the two files compared byte for byte (identical, `cmp` exit 0).
- `statistics.json` was written from the `stats_*_before.tsv` and `stats_*_after_set.tsv` files of the run (the same fields as `../estimate-50k-200k/statistics.json`: `n_rows` and `last_update` of the three N tables and `views`);
  the small database has the default state only.

| File | SHA-256 |
| --- | --- |
| `estimate-300000.json` (both runs; the same bytes as `300k/estimate.json`) | `87b8793416224dd438f17cc2a8e68f4eaa079c0a4a910c1140b911c36d748a99` |
| `marked-routes-300000.json` | `35827efece4d5886133b47d05caf44dd3ba91f411747078d7906aee6ff3838ca` |
| `predictions-300000.json` (input of `bin/estimate`; route, axis, table and the peak-memory formulas) | `5753567fbad54c210aa63a87390644e5f8a97b6ecc9d2a9399a5cc16f12882f1` |
| `statistics.json` | `00509819e76f4041f822e69d241e7524e2382c61bfc948fe8088bc5492658da5` |
| `explain.json` (kept outside the repository) | `4bd438b09cc20b94788f2c0424e95f15f477486c598b72aab97e8ccba33c3aea` |

## What the estimate says

| route | statements per request | rows (point) | rows (upper side) |
| --- | --- | --- | --- |
| `GET /books/{slug}` | 48.19 | 3,840,178.6 | 3,937,000.9 |
| `GET /books/{bookSlug}/export/zip` | 359,992.7 | 2,526,093.6 | 2,555,275.3 |
| `GET /shelves/{slug}` | 18 | 290.6 | 1,273.5 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 8 | 223.25 | same as point |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 8 | 237.6 | same as point |
| `GET /books/{slug}/references` | 7 | 218 | same as point |
| `GET /images/edit/{id}` | 6 | 199 | same as point |
| `GET /oidc/callback` | 0 | 0 | same as point |

- **The peak memory of all 8 routes is `status: predicted`** in the estimate (checked in the output: 8 of 8; none is `not_given`). The values are in the file; this README does not read them against the memory limit, which is the prediction's work.
- The EXPLAIN: 76 statement types, `plan_unstable` 3, `plan_changes_at_scale` 5, not available 0. Routes with a marked statement (of 7 in the EXPLAIN): 2 (`GET /books/{slug}` and `GET /shelves/{slug}`), listed in `marked-routes-300000.json`
  (these marks are the EXPLAIN marks of the row estimate; they are not the app-limit mark of the prediction).
- Two statement types fell back to the H1 point because their value at the small size was 0 (`e_small_zero`), as at 50,000 and 200,000 rows. `ratio_used` 3 routes, `per_exec_held` 1 route.
- The statistics states (`statistics.json`): in the default state after setting the sample size the statistics of the 300,000-row database hold 292,022 (`entities`), 297,564 (`images`), 299,088 (`references`) and 299,128 (`views`) rows, the estimates `ANALYZE TABLE` makes from 20 sample pages.

## What this does not say

- Nothing here checks the estimate at 300,000 rows against a measurement, and nothing says how long a request takes, what a limit does, or how many requests a stack handles.
- The EXPLAIN is from freshly built, analyzed databases of the same shape as the lab's, not from the lab's own; the sample of an `ANALYZE TABLE` is of random pages, so the lab's own statistics can differ slightly.
  The three statistics states of the same run already disagree on a few statements (`plan_unstable` 3).
- At 50,000 rows the small size changed the marks (upper side 4.51 times the point at 50,000 against 1.018 at 200,000; `../estimate-50k-200k/README.md`). The small size was fixed at one tenth before this run and not changed after it; whether another small size
  would give other marks was not tried.

## Per-count copy for the prediction tool

`300k/estimate.json` is a byte-for-byte copy of `estimate-300000.json` (same SHA-256). The ladder prediction tool reads one `estimate.json` per directory and takes the commit of an estimate from the last commit that
touched its directory, so the count needs a directory of its own. Nothing in the estimate was changed.

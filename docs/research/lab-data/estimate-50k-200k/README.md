# Row estimates at 50,000 and 200,000 rows

The estimate of the rows each route reads at 50,000 and at 200,000 rows, made from the capture and the rules that were already committed (the tangent rule, the books and ZIP
rule, the capture of 100 / 1,000 / 10,000 / 30,000 rows made on analyzed databases), in the same way and with the same tool as the 70,000-row estimate
(`../recheck-70k/estimate-70k/`). This is an input for a prediction. It is **not** a prediction and nothing here was measured: no request, no load, no timing and no
handler count was taken at either size.

## The estimate assumes analyzed databases

**These estimates assume a database whose statistics have been made up to date with `ANALYZE TABLE`.** The EXPLAIN behind them was taken on a freshly built database on
which `ANALYZE TABLE` had been run on every table, and the capture they read was made the same way (`--analyze-after-build=on`). A database whose statistics are stale or
were never gathered can get other plans, and then these row counts do not apply to it. The same sentence belongs in anything that uses these files.

## How far the estimate is from what was seen

The rows an estimate can rest on come from the capture, whose largest size is 30,000 rows. The targets are further out than that:

| target | capture's largest size | multiple |
| --- | --- | --- |
| 50,000 rows | 30,000 rows | 50,000 / 30,000 = 1.667 |
| 200,000 rows | 30,000 rows | 200,000 / 30,000 = 6.667 |

At 70,000 rows the multiple was 2.333. The estimate at 200,000 rows is a longer extrapolation than any estimate checked so far.

## Tool and inputs

- Tool: a `git archive` of `HEAD` = `69ea19a`. `git diff b4c27a2 HEAD -- poc/runtime poc/lab/compose` is empty (`b4c27a2` is the commit of the 70,000-row estimate). The last commit that touched
  `poc/runtime/src/Estimate`, `poc/runtime/src/Explain`, `poc/runtime/src/Simulate/Load/EstimateFormulas.php`, `poc/runtime/bin/estimate`, `poc/runtime/bin/explain-at-scale` or
  `poc/runtime/bin/marked-routes` is `32359c1` (2026-11-11 14:15:30); the last commit that touched `poc/runtime` at all is `e54f832` (14:29:02). No code was changed for this.
- Capture: SHA-256 `69f08fcad32bdd31367747d8231dab69df32596a837876253169eda88807d839`; task list `tasks.json` `2dd8145996fc89e35a2af7d938f5b57597683fbbc9c542f68feaed4cdbd7f815`.
- Candidate `H1_RANGE`, `--axes-only --with-formulas`, as at 70,000 rows. The predictions file given to `bin/estimate` was made by `bin/predict` with the committed fits of the extrapolation
  study (`docs/research/extrapolation-data-2/bookstack/fits.json`) at the same size and is read only for route, axis and table.

## How the files were made

```
bin/explain-at-scale --capture=<capture.json> --tasks=<tasks.json> --app=<bookstack> --scale=50000  --small-scale=5000  --n-tables=entities,images,references --out=<out> --label=bookstack-n50  --php-image=<image>
bin/explain-at-scale --capture=<capture.json> --tasks=<tasks.json> --app=<bookstack> --scale=200000 --small-scale=20000 --n-tables=entities,images,references --out=<out> --label=bookstack-n200 --php-image=<image>
bin/predict  --fits=<fits.json> --at=<N> > predictions.json
bin/estimate --capture=<capture.json> --explain=<out>/<label>/explain.json --candidate=H1_RANGE --at=<N> --predictions=predictions.json --axes-only --with-formulas > estimate-<N>.json   # twice
bin/marked-routes --explain=<out>/<label>/explain.json > marked-routes-<N>.json
```

- **The EXPLAIN was taken on throw-away databases, not on the lab's.** Each `bin/explain-at-scale` run builds a database of N rows with the tool's generator in its own sealed stack (no network),
  runs `ANALYZE TABLE` on every table, takes `EXPLAIN FORMAT=JSON` of each statement type in three statistics states (default 20 sample pages, low 4, high 200), and drops the database with its stack.
  The statements go through one door that lets `EXPLAIN FORMAT=JSON <data statement>` through and refuses anything else (no refusal happened; both runs exited 0). The lab's databases and stack
  were not used; the lab's databases were not even counted for this. Both runs were made at the same time, 16:24:06 to 16:25:58 (50,000) and to 16:27:54 (200,000), local time.
- The small size was one tenth of the target (5,000 and 20,000 rows). The row estimate itself reads the capture's largest size (`small_scale` = 30,000 in both files); the small size is only for the
  comparison of plans.
- Same inputs, same output: `bin/estimate` was run twice for each size and the two files compared byte for byte (identical, `cmp` exit 0 for both).

| File | SHA-256 |
| --- | --- |
| `estimate-50000.json` (both runs) | `dea43a75222bd01113132dc5552260e4406093f2f1007744de11c2920507d56c` |
| `estimate-200000.json` (both runs) | `11e673a489ddb2c2b4bb2e1e3d62b79363132823e73f6015204635a14bcc4fa6` |
| `marked-routes-50000.json` | `6ddfc9bab2c15359710cbe522ec679db096b7e5d1b695a2319b75e01c846dea1` |
| `marked-routes-200000.json` | `0810f90ba7c6f94a9b02b7b7d8f13340c15d77351466f98a4c189a79370b378e` |
| `statistics.json` | `47c2d80a1353436a943e0127a3874a8d99ca4841770ac1b19f53fb7759d7af08` |
| `explain.json` at 50,000 rows (kept outside the repository) | `20611a18bbf255689047175ebe3363a72f27cc02f77e393cef30a1f4e6181f72` |
| `explain.json` at 200,000 rows (kept outside the repository) | `c6701fd0c0b544c1d3c7e66f64b15352166b5c287ef44aaaafb84b857b139dc3` |

## The estimates: rows examined and statements per request

Point estimate, and the upper side for the routes that have a statement marked in the EXPLAIN at the target size.

### 50,000 rows

| route | statements | rows (point) | rows (upper side) |
| --- | --- | --- | --- |
| `GET /books/{slug}` | 46.6 | 635,345.1 | 2,865,451.7 |
| `GET /books/{bookSlug}/export/zip` | 60,002.9 | 419,477.5 | 1,194,935.5 |
| `GET /shelves/{slug}` | 18 | 265.3 | 266.9 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 8 | 223.25 | same as point |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 8 | 232.1 | 589.5 |
| `GET /books/{slug}/references` | 7 | 218 | same as point |
| `GET /images/edit/{id}` | 6 | 199 | same as point |
| `GET /oidc/callback` | 0 | 0 | same as point |

### 200,000 rows

| route | statements | rows (point) | rows (upper side) |
| --- | --- | --- | --- |
| `GET /books/{slug}` | 47.8 | 2,558,248.3 | 2,604,435.1 |
| `GET /books/{bookSlug}/export/zip` | 239,996.8 | 1,683,447.2 | 1,697,946.2 |
| `GET /shelves/{slug}` | 18 | 280.4 | 561.9 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 8 | 223.25 | same as point |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 8 | 236.2 | same as point |
| `GET /books/{slug}/references` | 7 | 218 | same as point |
| `GET /images/edit/{id}` | 6 | 199 | same as point |
| `GET /oidc/callback` | 0 | 0 | same as point |

## What the EXPLAIN marked

| | 50,000 | 70,000 (earlier) | 200,000 |
| --- | --- | --- | --- |
| statement types | 76 | 76 | 76 |
| `plan_unstable` | 4 | 1 | 3 |
| `plan_changes_at_scale` | 9 | 7 | 2 |
| not available | 0 | 0 | 0 |
| routes with a marked statement (of 7 in the EXPLAIN) | 4 | 2 | 2 |

- At 50,000 rows the four main statements of the book page (`s7ff3e12408`, `s24834a57a6`, `s400220f42f`, `s58efd897dc`) are marked `plan_changes_at_scale`, and the upper side of the book page is
  4.51 times its point (the ZIP export 2.85 times). At 200,000 rows none of the four is marked, and the upper side is 1.018 times the point for the book page and 1.009 times for the ZIP export. So the upper
  side at 200,000 rows is far narrower than at 50,000 and at 70,000 (where the upper side was 4.32 times the measured rows for the book page and 3.79 times for the ZIP export). That is what the marks gave; it was not adjusted.
- One possible reason, **not tested here**: the plan at the small size is the one the plan at the target is compared with, and the small sizes differ (5,000 for 50,000; 20,000 for 200,000). A plan that
  has already changed between 5,000 and 30,000 rows would show as a change at 50,000 and not at 200,000. Which of the two is nearer to the plan of a database of that size cannot be told from these files.
- Two statement types fell back to the H1 point because their value at the small size was 0 (`e_small_zero`: `s6a6174c841` in `GET /books/{slug}` and in `GET /shelves/{slug}`), at both sizes. They are
  statements of 1 and 2 rows per request.
- The `plan_unstable` marks mean that the plan differs between the three statistics states of the same database. They are small in number but not zero at either size.

## Statistics states

`statistics.json` holds `innodb_table_stats` (rows the statistics hold and `last_update`, server time = UTC) of the three N tables and `views` in each state, from the same run that gave the EXPLAIN.

- The state order in each run is default, then low (4 sample pages), then high (200). The database was left in the high state when the plans were taken and was **dropped** with its stack; nothing was put back
  because nothing outside the throw-away stack was changed.
- **The lab's databases were not touched**: no `ANALYZE`, no sample size changed, no statement of any kind was sent to them. Their statistics are what they were before this work, and
  an analyzed database is still the premise (above).

## What this does not say

- Nothing here checks the estimate at 50,000 or 200,000 rows against a measurement. The one check so far is at 70,000 rows (`../recheck-70k/estimate-70k/`), which is a different count and a shorter extrapolation.
- The EXPLAIN is from freshly built, analyzed databases of the same shape as the lab's, not from the lab's own; the sample of an `ANALYZE TABLE` is of random pages, so the lab's own statistics can differ
  slightly. The marks above (4 / 9 at 50,000, 3 / 2 at 200,000) are what one run of each size gave; the three statistics states of the same run already disagree on a few statements.
- Nothing here says how long a request takes, what a limit does, or how many requests a stack handles.

# Row estimate at 180,000 rows (for the sixth lab backtest, test B)

The estimate of the rows each route reads at 180,000 rows, made from the capture and the rules committed at the tool's HEAD (the tangent rule, the books and ZIP rule, the point rules for marked statements, the capture of 100 / 1,000 / 10,000 / 30,000 rows made on analyzed databases), with the
tool and flags of the 170,000-row estimate (`../estimate-170k/`): `--with-formulas --with-placeholders`. This is an input for a prediction. It is **not** a prediction and
nothing here was measured: no request, no load, no timing and no handler count was taken at this size, and no tool that sends requests (`bin/measure`, `bin/variance-run`, `measure-*`) was started.

## The estimate assumes analyzed databases, and databases the application's rebuild of a table was never run on

**This estimate assumes a database whose statistics have been made up to date with `ANALYZE TABLE`, and on which the application's own rebuild of the references table (`POST /settings/maintenance/regenerate-references`) has not been run.** The EXPLAIN behind it was taken on a freshly built database on which `ANALYZE TABLE` had been run on every table and no rebuild was run, and the capture it reads was made the same way
(`--analyze-after-build=on`, all eight routes are GET routes that do not change a table). A database whose statistics are stale or were never gathered, or one on which the rebuild was run first, can get other plans, and then these row counts do not apply to it. The same sentence belongs in anything that uses these files.

## How far the estimate is from what was seen

The capture's largest size is 30,000 rows. The target is 180,000 / 30,000 = 6.0 times that; the longest extrapolation checked so far was 300,000 / 30,000 = 10 times (`../estimate-300k/`). 180,000 rows is not a count any earlier estimate, build or measurement used (the count was chosen as the smallest not yet seen, 5 times the capture or more, in steps of 10,000: `docs/decisions.md` D239).

## Tool and inputs

- Tool: a `git archive` of `HEAD` = `20a127abdc836c17751f090bb8e08b6ddc332995` (`poc/runtime`, `poc/lab/bin`, made so that the working tree of other work cannot reach the run). The last commit that touched `poc/runtime/src/Estimate`, `poc/runtime/src/Explain`,
  `poc/runtime/src/Simulate/Load/EstimateFormulas.php`, `poc/runtime/bin/estimate` or `poc/runtime/bin/marked-routes` is `bf39716` (2026-11-19 08:57:17: the full-scan point rule applies only above the largest captured size). `poc/runtime/bin/explain-at-scale` was last touched by `09514cd` (2026-11-21 09:03:13), which only keeps the database volume on teardown unless `--purge` is given; `git diff bf39716 HEAD` over the other paths is empty. The calibration and the formulas are those of the 170,000-row estimate.
- Capture: SHA-256 `69f08fcad32bdd31367747d8231dab69df32596a837876253169eda88807d839` (the 8-route capture of the earlier estimates); task list `tasks.json` `2dd8145996fc89e35a2af7d938f5b57597683fbbc9c542f68feaed4cdbd7f815`. Both hashes were read again before the run and equal the 170,000-row ones. It was not retaken: all eight routes are GET routes that change no table, so the order they ran in cannot have changed the state of the databases.
- Candidate `H1_RANGE`, `--with-formulas` and `--with-placeholders`, **without `--axes-only`**. The predictions file given to `bin/estimate` was made by `bin/predict --at=180000` from the committed fits of the extrapolation study
  (`docs/research/extrapolation-data-3/bookstack/fits.json`, SHA-256 `5f409c300b490463b9a198e677f301e09125d288a0d06d89fbce19c01a3a518f`, not refitted); it is `predictions-180000.json` here.
- The small size of the EXPLAIN comparison is **18,000 rows** (one tenth of the target, the practice of `docs/decisions.md` D201 and of the earlier estimates). It was fixed before any EXPLAIN of this count was taken (`order_record.txt`,
  the line `decision=`). The row estimate itself reads the capture's largest size (`small_scale` = 30,000 in `estimate-180000.json`).

## How the files were made

```
bin/explain-at-scale --capture=<capture.json> --tasks=<tasks.json> --app=<bookstack> --scale=180000 --small-scale=18000 --n-tables=entities,images,references --out=<out> --label=b-n180000 --php-image=peak-measure-php-bookstack:8.3 --project-prefix=peak-eas180
bin/predict  --fits=<fits.json> --at=180000 > predictions-180000.json
bin/estimate --capture=<capture.json> --explain=<out>/b-n180000/explain.json --candidate=H1_RANGE --at=180000 --predictions=predictions-180000.json --with-formulas --with-placeholders > estimate-180000.json   # twice
bin/marked-routes --explain=<out>/b-n180000/explain.json > marked-routes-180000.json
```

- **The EXPLAIN was taken on throw-away databases, not on the lab's.** `bin/explain-at-scale` built a database of 180,000 rows and one of 18,000 rows in its own sealed stack (no network, preflight passed), ran `ANALYZE TABLE` on every table, took `EXPLAIN FORMAT=JSON` of each
  statement type in three statistics states for the large one (default 20 sample pages, low 4, high 200) and in the default state for the small one, and dropped both databases. The lab's databases were not used for it, and no statement other than `EXPLAIN` (and the build, `ANALYZE TABLE` and the statistics reads of the tool) was run on a database of this count. The tool's own volume (`peak-eas180-b-n180000_measure-dbdata`) is left in place by design (`09514cd`); it was not removed.
- Times (`order_record.txt`, local time): `explain-at-scale` 2026-11-21T20:16:47+09:00 to 2026-11-21T20:19:46+09:00, `predict` 2026-11-21T20:19:59+09:00, `estimate` 2026-11-21T20:19:59+09:00 to 2026-11-21T20:20:00+09:00 and 2026-11-21T20:20:00+09:00 to 2026-11-21T20:20:01+09:00, `marked-routes` 2026-11-21T20:20:01+09:00.
  The EXPLAIN ran at the same time as the build of the lab's 180,000-row database; neither is a measurement.
- Same inputs, same output: `bin/estimate` was run twice and the two files compared byte for byte (identical, `cmp` exit 0).
- `statistics.json` was written from the `stats_*_after_set.tsv` and `stats_*_before.tsv` files of the run (the same fields as `../estimate-300k/statistics.json`; the script that writes it gave the same JSON as that file when run on the 300,000-row run's files); the small database has the default state only.

| File | SHA-256 |
| --- | --- |
| `estimate-180000.json` (both runs; the same bytes as `180k/estimate.json`) | `b58fa619899fe456b77ad9e17328d5912c1f364294d99e3307dfab20ce9634ca` |
| `marked-routes-180000.json` | `dbe676dedafefb8577a43cf1e197bc568435b8be964011883cfba69c6e188b9b` |
| `predictions-180000.json` (input of `bin/estimate`) | `3370f13decf7a3c9ef4eccf8117582e18b191aaa9f88540e2d071352f88a55ff` |
| `statistics.json` | `c7b47846a6b9e5273f71584e5c75500b92f21c890801d498f21bab0e483dd16a` |
| `explain.json` (kept outside the repository) | `64f90a7ea7a869e89dd0008015da5108e8b08ecbf2ad489b0237f3924e415eb8` |

## What the estimate says

| route | statements per request | rows (point) | rows (upper side) |
| --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 8 | 223.25 | same as point |
| `GET /books/{bookSlug}/export/zip` | 215,997.61 | 1,514,917.88 | 1,526,856.50 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 8 | 235.83 | same as point |
| `GET /books/{slug}` | 47.74 | 2,301,861.94 | 2,339,615.21 |
| `GET /books/{slug}/references` | 7 | 218.0 | same as point |
| `GET /images/edit/{id}` | 6 | 199.0 | same as point |
| `GET /oidc/callback` | 0 | 0.0 | same as point |
| `GET /shelves/{slug}` | 18 | 278.42 | 480.67 |

- **The peak memory of all 8 routes is `status: predicted`** in the estimate (checked in the output: 8 of 8; none is `not_given`). This README does not read the values against any memory limit, which is the prediction's work.
- The EXPLAIN: 76 statement types, `plan_unstable` 3, `plan_changes_at_scale` 0, not available 0. Routes with a marked statement (of 7 in the EXPLAIN): 2 (`GET /books/{slug}` and `GET /shelves/{slug}`), listed in `marked-routes-180000.json` (these marks are the EXPLAIN marks of the row estimate; they are not the app-limit mark of the prediction).
- `ratio_used` 3 routes, `per_exec_held` 1 route, fell back to the H1 point 0.
- The upper side over the point is 1.008 for the ZIP export and 1.016 for the book page. At 180,000 rows the marks come from `plan_unstable` only; the small size was fixed at one tenth before the EXPLAIN and not changed after it, and whether another small size would give other marks was not tried.

## What this does not say

- Nothing here checks the estimate at 180,000 rows against a measurement, and nothing says how long a request takes, what a limit does, or how many requests a stack handles.
- The EXPLAIN is from freshly built, analyzed databases of the same shape as the lab's, not from the lab's own; the sample of an `ANALYZE TABLE` is of random pages, so the lab's own statistics can differ slightly.

## Per-count copy for the prediction tool

`180k/estimate.json` is a byte-for-byte copy of `estimate-180000.json` (same SHA-256). The ladder prediction tool reads one `estimate.json` per directory and takes the commit of an estimate from the last commit that touched its directory, so the count needs a directory of its own.
Nothing in the estimate was changed.

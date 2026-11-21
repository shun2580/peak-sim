# Row estimate at 170,000 rows (for the fifth lab backtest, test B)

The estimate of the rows each route reads at 170,000 rows, made from the capture and the rules committed at the tool's HEAD (the tangent rule, the books and ZIP rule, the point rules for marked statements, the capture of 100 / 1,000 / 10,000 / 30,000 rows made on analyzed databases), with the
tool and flags of the 90,000-row and 150,000-row estimates (`../estimate-90k/`, `../estimate-150k/`): `--with-formulas --with-placeholders`. This is an input for a prediction. It is **not** a prediction and
nothing here was measured: no request, no load, no timing and no handler count was taken at this size, and no tool that sends requests (`bin/measure`, `bin/variance-run`, `measure-*`) was started.

## The estimate assumes analyzed databases, and databases the application's rebuild of a table was never run on

**This estimate assumes a database whose statistics have been made up to date with `ANALYZE TABLE`, and on which the application's own rebuild of the references table (`POST /settings/maintenance/regenerate-references`) has not been run.** The EXPLAIN behind it was taken on a freshly built database on which `ANALYZE TABLE` had been run on every table and no rebuild was run, and the capture it reads was made the same way
(`--analyze-after-build=on`, all eight routes are GET routes that do not change a table). A database whose statistics are stale or were never gathered, or one on which the rebuild was run first, can get other plans, and then these row counts do not apply to it. The same sentence belongs in anything that uses these files.

## How far the estimate is from what was seen

The capture's largest size is 30,000 rows. The target is 170,000 / 30,000 = 5.667 times that; the longest extrapolation checked so far was 300,000 / 30,000 = 10 times (`../estimate-300k/`). 170,000 rows is not a count any earlier estimate, build or measurement used.

## Tool and inputs

- Tool: a `git archive` of `HEAD` = `c72478d483f6f647d568cfb36e5d065e5762eb3c` (`poc/runtime`, `poc/lab/bin`, made so that the working tree of other work cannot reach the run). The last commit that touched `poc/runtime/src/Estimate`, `poc/runtime/src/Explain`,
  `poc/runtime/src/Simulate/Load/EstimateFormulas.php`, `poc/runtime/bin/estimate`, `poc/runtime/bin/explain-at-scale` or `poc/runtime/bin/marked-routes` is `bf39716` (2026-11-19 08:57:17: the full-scan point rule applies only above the largest captured size; before it `b6d59de` added the point rules for marked statements). The calibration and the formulas are those of the 90,000-row and 150,000-row estimates.
- Capture: SHA-256 `69f08fcad32bdd31367747d8231dab69df32596a837876253169eda88807d839` (the 8-route capture of the 300,000-row, 90,000-row and 150,000-row estimates); task list `tasks.json` `2dd8145996fc89e35a2af7d938f5b57597683fbbc9c542f68feaed4cdbd7f815`. It was not retaken: all eight routes are GET routes that change no table, so the order they ran in cannot have changed the state of the databases.
- Candidate `H1_RANGE`, `--with-formulas` and `--with-placeholders`, **without `--axes-only`**. The predictions file given to `bin/estimate` was made by `bin/predict --at=170000` from the committed fits of the extrapolation study
  (`docs/research/extrapolation-data-3/bookstack/fits.json`, SHA-256 `5f409c300b490463b9a198e677f301e09125d288a0d06d89fbce19c01a3a518f`, the same bytes as `extrapolation-data-2/bookstack/fits.json`, not refitted); it is `predictions-170000.json` here.
- The small size of the EXPLAIN comparison is **17,000 rows** (one tenth of the target, the practice of `docs/decisions.md` D201 and of the 90,000-row and 150,000-row estimates). It was fixed before any EXPLAIN of this count was taken (`order_record.txt`,
  the line `decision=`). The row estimate itself reads the capture's largest size (`small_scale` = 30,000 in `estimate-170000.json`).

## How the files were made

```
bin/explain-at-scale --capture=<capture.json> --tasks=<tasks.json> --app=<bookstack> --scale=170000 --small-scale=17000 --n-tables=entities,images,references --out=<out> --label=b-n170000 --php-image=peak-measure-php-bookstack:8.3
bin/predict  --fits=<fits.json> --at=170000 > predictions-170000.json
bin/estimate --capture=<capture.json> --explain=<out>/b-n170000/explain.json --candidate=H1_RANGE --at=170000 --predictions=predictions-170000.json --with-formulas --with-placeholders > estimate-170000.json   # twice
bin/marked-routes --explain=<out>/b-n170000/explain.json > marked-routes-170000.json
```

- **The EXPLAIN was taken on throw-away databases, not on the lab's.** `bin/explain-at-scale` built a database of 170,000 rows and one of 17,000 rows in its own sealed stack (no network, preflight passed), ran `ANALYZE TABLE` on every table, took `EXPLAIN FORMAT=JSON` of each
  statement type in three statistics states for the large one (default 20 sample pages, low 4, high 200) and in the default state for the small one, and dropped both databases. The lab's databases were not used for it, and no statement other than `EXPLAIN` (and the build, `ANALYZE TABLE` and the statistics reads of the tool) was run on a database of this count.
- Times (`order_record.txt`, local time): `explain-at-scale` 2026-11-19T17:19:38+09:00 to 2026-11-19T17:23:20+09:00, `predict` 2026-11-19T17:20:13+09:00 to 2026-11-19T17:20:13+09:00, `estimate` 2026-11-19T17:23:36+09:00 to 2026-11-19T17:23:37+09:00 and 2026-11-19T17:23:37+09:00 to 2026-11-19T17:23:38+09:00, `marked-routes` 2026-11-19T17:23:38+09:00 to 2026-11-19T17:23:38+09:00.
- Same inputs, same output: `bin/estimate` was run twice and the two files compared byte for byte (identical, `cmp` exit 0).
- `statistics.json` was written from the `stats_*_before.tsv` and `stats_*_after_set.tsv` files of the run (the same fields as `../estimate-150k/statistics.json`); the small database has the default state only.

| File | SHA-256 |
| --- | --- |
| `estimate-170000.json` (both runs; the same bytes as `170k/estimate.json`) | `c7b13696e63df9f1b01126746d949200bf63bac049cace5c9bb4b958772f4f26` |
| `marked-routes-170000.json` | `bcdadcb1fd70fad87381e08adbe30a60e9def89477f58dbc564023fa85d4614d` |
| `predictions-170000.json` (input of `bin/estimate`) | `347b0a1f7588c10c0fe68800a097c1fcf5f0606504ecae7c22dacd6415aefed5` |
| `statistics.json` | `2c5e5e850139e86efdc56a364f8871f67b2b7fdec12a9a1539e66cf27bc0f252` |
| `explain.json` (kept outside the repository) | `8311546694cda634d2c36f86f02e02d796a35f329ed861196a90ccc7d22f1e73` |

## What the estimate says

| route | statements per request | rows (point) | rows (upper side) |
| --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 8 | 223.25 | same as point |
| `GET /books/{bookSlug}/export/zip` | 203,998.02 | 1,430,653.24 | 1,441,368.96 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 8 | 235.64 | same as point |
| `GET /books/{slug}` | 47.68 | 2,173,668.74 | 2,207,434.88 |
| `GET /books/{slug}/references` | 7 | 218.0 | same as point |
| `GET /images/edit/{id}` | 6 | 199.0 | same as point |
| `GET /oidc/callback` | 0 | 0.0 | same as point |
| `GET /shelves/{slug}` | 18 | 277.41 | 446.25 |

- **The peak memory of all 8 routes is `status: predicted`** in the estimate (checked in the output: 8 of 8; none is `not_given`). This README does not read the values against any memory limit, which is the prediction's work.
- The EXPLAIN: 76 statement types, `plan_unstable` 3, `plan_changes_at_scale` 0, not available 0. Routes with a marked statement (of 7 in the EXPLAIN): 2, listed in `marked-routes-170000.json` (these marks are the EXPLAIN marks of the row estimate; they are not the app-limit mark of the prediction).
- `ratio_used` 3 routes, `per_exec_held` 1 route, fell back to the H1 point 0.
- The upper side over the point is 1.007 for the ZIP export and 1.016 for the book page. At 170,000 rows the marks come from `plan_unstable` only; the small size was fixed at one tenth before the EXPLAIN and not changed after it, and whether another small size would give other marks was not tried.

## What this does not say

- Nothing here checks the estimate at 170,000 rows against a measurement, and nothing says how long a request takes, what a limit does, or how many requests a stack handles.
- The EXPLAIN is from freshly built, analyzed databases of the same shape as the lab's, not from the lab's own; the sample of an `ANALYZE TABLE` is of random pages, so the lab's own statistics can differ slightly.

## Per-count copy for the prediction tool

`170k/estimate.json` is a byte-for-byte copy of `estimate-170000.json` (same SHA-256). The ladder prediction tool reads one `estimate.json` per directory and takes the commit of an estimate from the last commit that touched its directory, so the count needs a directory of its own.
Nothing in the estimate was changed.

## Changes to the copies

- After the first commit the copies in this directory were changed again, to take the names of the working environment out. The sha256 given here is of each file as it was committed before this change, so these files no longer have the bytes the tools wrote. The home directory of the working machine is written `<home>`; in directory, volume and label names the short word that named the operator's task before a number is written `run-` (the number and the rest of the name are kept, so one place has one name in every file, for example `run-281t6b`, `run-277t6`, `run-277_recapture2`); these names differ from the paths the runs really used. sha256 before, and the number of lines changed: `order_record.txt` caf797af26c59252f8fd3813cbb20a2e3331d35dd9795b0170a85e9f9cb4fd69 (1 line).

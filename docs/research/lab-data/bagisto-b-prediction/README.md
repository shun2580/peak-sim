# Prediction of the lab backtest on Bagisto at 190,000 rows (what is fixed before any plan at this count is made)

**Written 2026-11-26 19:12, before the first plan, estimate, mark or bound at this count is made, and before the prediction exists.** What is in this file is fixed now. The results are added after it, in a section below the line "Results"; nothing above that line is changed.

The count of 190,000 rows is the one decided for the backtest (decisions D265). No database of this count had been built, no estimate, prediction or bound at this count had been made anywhere before this file was written (the lab database build of this count was started at 2026-11-26 19:12, before this file; it is the database the backtest will measure, not an input of the prediction).

## The inputs and the flags (fixed)

1. **The plans of the statements at this count:** `bin/explain-at-scale` with the third capture of Bagisto (`capture.json`, `tasks.json`), `--scale=190000`, `--n-tables` the 123 tables of `docs/research/bagisto-prep/n-tables.txt` (one database for all routes), the small side at its default of 10,000, no memory cap, the PHP image of the capture's build (`peak-measure-php-bagisto:8.4`), `--keep-stack` (the data volume is kept; nothing is purged). The same flags as the rehearsal at 30,000 rows (`../dryrun-25/`) except the count.
2. **The estimate and the marks:** `bin/estimate --candidate=H1_RANGE --with-formulas --with-placeholders` with the route-level predictions of `bin/predict --at=190000` from the committed fits of the extrapolation test, and `bin/marked-routes`. Each is made twice and the two outputs must be the same bytes.
3. **The shared-table copy** of the estimate (`../bagisto-calibration-gate/share_n_table.py`, the same one as for the calibration), then the scenario file made by `../dryrun-25/scripts/make_input.py` from the calibration file of the eight runs (`../bagisto-calibration/fit/calibration.yaml`, unchanged) and the five target scenarios s-1 to s-5 at 190,000 rows over the eight routes of `poc/lab/bagisto/route-list.json`.
4. **The prediction:** `bin/predict-ladder --drop-marked-routes --request-timeout-s=1800 --warmup-from-marks --naive-count=10000`, committed under `poc/lab/predictions/`, then `bin/prediction-gate`.

The calibration (the two coefficients), the formulas, the rules of the marks, the pass lines and the rule of the verdict are those already committed; none of them is changed here.

## What is decided before the numbers are seen

- **A rerun is allowed only as the second run that checks the same bytes.** After a value is seen, the flags, the count, the calibration, the time limit and the routes are not changed to run again.
- **If the prediction tool stops with exit 39** (a route of the scenario, before the allocation, has a bound over 900 s; simulator-spec section 7.4.4; `LadderPrediction.php`), **nothing is measured and nothing is run again.** This reading is the strict one of the record of the allocation (D226): the check covers every route of the scenario, also a route that the allocation takes out of the mix. The database, the plans, the estimate and the standard error output are kept (nothing is deleted) and are committed as the record of the stop; there is no prediction file. The maintainers are asked what to do. The ways that could be taken (taking the routes that the allocation removes out of the check, another time limit, another count) are not chosen here; each would move the test toward a pass.
- **The same holds for exit 19 (the memory guard), for any other non-zero exit, and when the prediction gate stays closed:** stop, change nothing, report.
- **If the tool does not stop and the preparation W is 900 s or less,** the work goes on. Before the chain of the measurement is started, its length is worked out from the prediction's K (steps per scenario) and W, from the time that the build, the statistics, the plans and the prediction really took, and from the wait at the end of a step (up to 1,800 s per step), and set against the end of the pause of the operating system's updates (2026-11-29 00:02 JST). If the chain could run past that time it is not started.
- **The straight-line guess from the rehearsal** (an upper bound that grows in proportion to the count) is a premise that was not checked and is used for no choice.

## Layout

`lab/`: the lab stack, the build and the statistics of the 190,000-row database. `explain/`: the record of the plans. Later files (estimate, shared copy, scenario file, prediction output, checks) are listed in the section below the line.

## Results

Added 2026-11-26 19:31. **The prediction tool stopped with exit 39. No prediction file exists. Nothing is measured.**

### What was done (times are the host clock, JST; `lab/step-times.tsv`, `estimate/order_record.txt`)

- **The lab stack** was started with the wrapper (`lab/up-output.txt`); the settings for B were already on (`lab-config-b status` = B; the two files' sha256 as recorded before); the database's log had no recovery or error line (`lab/db-state-after-up.txt`). No database, volume, directory or record of the count of 190,000 existed before (`lab/unused-190000-check.txt`).
- **The lab database of 190,000 rows** was built 19:12:13 to 19:25:49 (802.89 s by the builder: migrations 17.5 s, filling 760.83 s; `lab/build-db-output.txt`), then `ANALYZE TABLE` once: 152 of 152 tables `status OK` (`lab/analyze/`). The database's log has no recovery or error line after the build (`lab/db-log-after-build.txt`).
- **The plans** (`bin/explain-at-scale`, flags as fixed above, run once): 19:13:09 to 19:28:48, exit 0. 432 statement types, 192 sent as EXPLAIN, 3 `plan_unstable`, 56 `plan_changes_at_scale`, 0 `explain_unavailable` (`explain/`; the sha256 of `explain.json` is in `explain/explain.json.sha256`; the file itself is 8 MB and is kept outside the repository).
- **The estimate and the marks**: `bin/predict --at=190000`, `bin/estimate` and `bin/marked-routes` each made twice; the two outputs are the same bytes (`estimate/two-runs.sha256`). 41 of the 63 routes are marked. The committed files are run 1.
- **The shared-table copy** (`shared/`): 119 of the 120 route-index entries changed, every changed line a table line, equal to the original after resetting those fields (`shared/share-proof.json`). The scenario file was made from the calibration file of the eight runs, unchanged, and the five target scenarios (`stop/scenario-input.yaml`, with its sha256).
- **The prediction tool** was run once, with the fixed flags, on the committed shared copy (`stop/order.txt`, `stop/predict-ladder.stdout`, `stop/predict-ladder.stderr`).

### The stop

`bin/predict-ladder` ended with **exit 39**: "a request is predicted to take more than half of the request timeout (1800 s, so over 900 s of php CPU time)". For each of s-1 to s-5 the route is `GET /api/products` with **1148.9 s** of php CPU time for one request. This is the strict reading fixed above (simulator-spec section 7.4.4): the check covers every route of the scenario before the allocation.

Following what was fixed above: it was **not run again**, no flag, count, calibration, time limit or route was changed, nothing was deleted (the database, the plans, the estimate, the shared copy and the standard error output are kept), nothing is measured, and the maintainers are asked what to do. The prediction gate was not run: there is no prediction file to give it.

### The state left

The two input files of the prediction tool are in `stop/`; the copy of the scenario file under `poc/lab/predictions/` (`ladder-prediction-bagisto-b-input.yaml`) is left where it was made and is not committed, so that the directory holds no prediction file.

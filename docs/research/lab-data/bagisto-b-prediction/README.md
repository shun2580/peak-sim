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

(added after the work)

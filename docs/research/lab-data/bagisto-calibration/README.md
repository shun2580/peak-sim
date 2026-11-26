# The calibration of the lab backtest on Bagisto (cal-2 and cal-3 measured; the other six runs are the search's)

**Written 2026-11-26 13:24:09, before any calibration run is measured.** What is above the line "Results" is fixed now and is not changed; the results are added below it.

## Before the first run

- The rates, the six runs taken from the search, how each is handed over and the table of `--run-scale` are in `../bagisto-calibration-search/after-fix/README.md` ("The R table", "The eight runs of the calibration").
- The runs that are measured: cal-2 (first four routes at 80%) and cal-3 (routes 1, 3, 5, 7 at 80%), both at R_0 = 2.83 rps and 10,000 rows, 10 minutes with the first 2 minutes discarded, limits off, request timeout 1800 s, one call of `measure_cal.sh` for each (cal-2, then cal-3), the checks of `check_run.sh` after each.
- The gate in front of them is the real one: no `--search-gate-advisory`. If it refuses (exit 14), nothing is measured and the work stops and is reported.
- Not decided here and not changed after a value is seen: a run that is invalid (rps not reached) or unexpected is not repaired; the work stops and is reported.

## Results

### The gate that counts (called by `measure_cal.sh`, no advisory flag; before each of the two runs, and again alone with `--gate-only`, `fit/gate-only.out.txt`)

Open (exit 0). Smallest degree **0.546895** (the set without cal-8), threshold 0.50; the degrees of the other sets are 0.671844 to 0.737940 (`cal2-r2.83.out.txt`).

### The two runs measured (R_0 = 2.83 rps, 10,000 rows; 2026-11-26 13:25 to 13:48)

| Run | Rate asked | Reached (status) | Dropped arrivals | Database | App | Requests in the kept window |
| --- | --- | --- | --- | --- | --- | --- |
| cal-2 (`cal2-r2.83/`) | 2.83 | 2.8333 (valid) | 0 | **36.9806%** | 81.84% | 1360, all 200 |
| cal-3 (`cal3-r2.83/`) | 2.83 | 2.8333 (valid) | 0 | **32.8299%** | 78.33% | 1360, all 200 |

Both are outside the band of the search (20% to 30%); the calibration runs are not held to it. The app stayed below 90% of its CPUs on both.
`check_run.sh` (`cal2-r2.83/check.txt`, `cal3-r2.83/check.txt`), for both runs: the four settings files the same as the confirming run's (raw bytes the same, and `recheck_nginx.sh`'s reading SAME), the first line of `tables-before.tsv` says 10,000 and the run's count is 10,000, no step after the window ("type iii: none"; 8 requests finished after the window ended, all within 7 s of it), the receive queue of port 9000 after the run 0, window start to end of tail 662 s. Before each run: 15 GiB total and 8 available, 882 GB free, the database's log (13 lines) had no recovery or error line. `cal-log.txt` is the record of the starts and ends.
The app and web containers were recreated by the entry's switch of the app from 1,000 to 10,000 rows before cal-2 (`cal2-r2.83.err.txt`); the settings files read after the run are the same as before.

### The formula, run dry on the eight runs (written 2026-11-26 13:49:59; nothing is predicted; `fit/`)

`make-calibration-scenario` was given the eight runs by `--run=<id>:<dir>` (the six of the search by their directories in `../bagisto-calibration-search/after-fix/`, cal-2 and cal-3 by theirs here), the `--run-scale` of the table, `--tables=n_shared` and `--cores=2` (`fit/calibration.yaml`), then `recalibrate` with the shared-table estimate (`fit/out/`, `fit/recalibrate.out.txt`).
The `tables:` lines of the written file agree with the table of counts (10,000 for cal-1 to cal-3; 3,000, 30,000, 30,000, 30,000 and 1,000 for cal-4 to cal-8), and its `observed` values agree with each record's database utilization.

| | |
| --- | --- |
| `c_query` | 3.79962e-4 |
| `c_row` | 8.52974e-7 |
| coefficient cut to 0 | none (`cut_coefficients: false`) |
| warnings | none |
| squared residual (load) | 0.130607 |

The queries-only fit (`c_query` 1.93037e-3, squared residual 1.35920) and the rows-only fit (`c_row` 9.07804e-7, squared residual 0.149962) are shown by the tool and not used. The width is the nine fits that leave one run out (`c_query` 1.56060e-4 to 6.08743e-4; `c_row` 7.99257e-7 to 1.01285e-6; none cut).

| Run | Observed (%) | Fitted (%) | Residual (points, observed - fitted) |
| --- | --- | --- | --- |
| cal-1 | 20.4348 | 21.9081 | -1.4733 |
| cal-2 | 36.9806 | 30.2983 | +6.6823 |
| cal-3 | 32.8299 | 25.8669 | +6.9631 |
| cal-4 | 20.1003 | 20.9247 | -0.8244 |
| cal-5 | 23.8785 | 22.5421 | +1.3365 |
| cal-6 | 24.6619 | 19.1129 | +5.5490 |
| cal-7 | 18.2450 | 32.2889 | -14.0438 |
| cal-8 | 17.6836 | 18.5345 | -0.8509 |

The residuals of cal-7 (-14.04 points), cal-3 (+6.96), cal-2 (+6.68) and cal-6 (+5.55) are large next to the first four runs of the BookStack seven-run calibration (\`../calibration-r4/\`), whose residuals were at most 0.47 point. Nothing was changed in response; the dry run of the rehearsal, the prediction and the measurement are not made here.

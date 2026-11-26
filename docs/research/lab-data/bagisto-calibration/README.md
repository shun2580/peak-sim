# The calibration of the lab backtest on Bagisto (cal-2 and cal-3 measured; the other six runs are the search's)

**Written 2026-11-26 13:24:09, before any calibration run is measured.** What is above the line "Results" is fixed now and is not changed; the results are added below it.

## Before the first run

- The rates, the six runs taken from the search, how each is handed over and the table of `--run-scale` are in `../bagisto-calibration-search/after-fix/README.md` ("The R table", "The eight runs of the calibration").
- The runs that are measured: cal-2 (first four routes at 80%) and cal-3 (routes 1, 3, 5, 7 at 80%), both at R_0 = 2.83 rps and 10,000 rows, 10 minutes with the first 2 minutes discarded, limits off, request timeout 1800 s, one call of `measure_cal.sh` for each (cal-2, then cal-3), the checks of `check_run.sh` after each.
- The gate in front of them is the real one: no `--search-gate-advisory`. If it refuses (exit 14), nothing is measured and the work stops and is reported.
- Not decided here and not changed after a value is seen: a run that is invalid (rps not reached) or unexpected is not repaired; the work stops and is reported.

## Results

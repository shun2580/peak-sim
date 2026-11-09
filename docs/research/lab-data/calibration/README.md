# Calibration runs of the lab backtest (BookStack, 8 routes)

The records of the lab's calibration runs (`docs/simulator-spec.md` 7.2): 10,000 rows in the three scaled tables, 1 rps in total, 10 minutes, the first 2 minutes discarded, a database with 2 CPUs. Each directory holds the plan that was sent (`plan.json`), the k6 summary, the raw CPU samples of the database container (`cpu.txt`: epoch seconds and `usage_usec`), the start and end times, and the record made by `poc/lab/load/summarize-run.php` (`record.json`).

| Directory | Mix of the 8 routes (in the order of the route list) | Role |
| --- | --- | --- |
| `cal-1` | even | calibration |
| `cal-2` | first four routes 80%, last four 20% | calibration |
| `cal-3` | the routes at the 1st, 3rd, 5th and 7th places 80%, the others 20% (`odd80`) | calibration |
| `cal-3-orig` | last four routes 80%, first four 20% | **not used for calibration** (see below) |

## `cal-3-orig`

The first cal-3 was `cal-2` with the left and right halves swapped, so its rps per route is exactly `2 x cal-1 - cal-2` on all 8 routes. Three runs whose rps rows are linearly dependent (rank 2) add nothing to a two-coefficient fit, so the spec was changed before any prediction scenario was measured: cal-3 is now "odd places 80%, even places 20%" (spec 7.2). The record of the first cal-3 is kept here under the name `cal-3-orig`, unchanged (its directory was renamed; no file was edited), as a check that utilisation adds up over the mix of rps. It is not used for calibration.

Because the model is linear in rps, `utilisation(cal-3-orig)` should equal `2 x utilisation(cal-1) - utilisation(cal-2)`:

| | Utilisation (%) |
| --- | --- |
| cal-1 | 23.0922 |
| cal-2 | 36.4054 |
| `2 x cal-1 - cal-2` | 9.7789 |
| cal-3-orig, observed | 9.6838 |
| observed - sum | -0.0951 points (-0.97% of the sum) |

The achieved rps of the three runs was 0.9917, 0.9979 and 0.9979 (target 1.0), so the totals were not exactly equal. The difference above is computed from the recorded utilisations as they are, with no correction for that.

## `cal-3` and the calibration on three runs

`cal-3` was measured on 2026-11-10 (08:14:48 to 08:24:55) with the same conditions as `cal-1` and `cal-2`: 10,000 rows in the three scaled tables, 1 rps in total, 10 minutes, the first 2 minutes discarded, a database with 2 CPUs. The plan was made by `poc/lab/load/make-plan.php --mix=odd80`. The rps came out (`valid`): 480 requests in the 480 kept seconds, 1.0 rps, none dropped. The database utilization was 9.9319%. The 96 requests counted as failed are the 302 answers of `GET /oidc/callback` (by design, see `poc/lab/load/README.md`).

Rank of the rps matrix (3 runs x 8 routes, rows `cal-1`, `cal-2`, `cal-3`, in the order of the route list; computed with a singular value decomposition, a singular value counted as zero only if it is below the usual tolerance):

| Run | rps per route |
| --- | --- |
| `cal-1` | 0.125, 0.125, 0.125, 0.125, 0.125, 0.125, 0.125, 0.125 |
| `cal-2` | 0.2, 0.2, 0.2, 0.2, 0.05, 0.05, 0.05, 0.05 |
| `cal-3` | 0.2, 0.05, 0.2, 0.05, 0.2, 0.05, 0.2, 0.05 |

The rank is **3** (singular values 0.6373, 0.2121, 0.1177). With `cal-3-orig` in place of `cal-3` the rank is 2 (the largest difference between `cal-3-orig` and `2 x cal-1 - cal-2` over the 8 routes is 1.4e-17).

The calibration is two coefficients (`c_query`, `c_row`), non-negative least squares, on `cal-1`, `cal-2`, `cal-3`, with the estimate in `../estimate/` (not rebuilt). It was made with `poc/lab/scenarios/make-calibration-scenario` and `recalibrate`; the scenario file and the output are in `fit/` (`calibration.yaml`, `calibration-result.json`). No prediction was made.

| | |
| --- | --- |
| `c_query` | 1.74879e-4 |
| `c_row` | 1.12854e-5 |
| coefficients cut to 0 | none (`cut_coefficients: false`, no warning) |

| Run | Observed (%) | Fitted (%) | Residual (points, observed - fitted) |
| --- | --- | --- | --- |
| `cal-1` | 23.0922 | 23.3301 | -0.2379 |
| `cal-2` | 36.4054 | 36.3858 | +0.0197 |
| `cal-3` | 9.9319 | 9.4186 | +0.5133 |

The width of the calibration is the range of the fits made by leaving one run out:

| Left out | `c_query` | `c_row` | A coefficient was cut to 0 |
| --- | --- | --- | --- |
| nothing (all three) | 1.74879e-4 | 1.12854e-5 | no |
| `cal-1` | 0 | 2.69340e-5 | **yes** (`c_query`) |
| `cal-2` | 0 | 2.60030e-5 | **yes** (`c_query`) |
| `cal-3` | 2.33090e-4 | 6.10571e-6 | no |

The fit on all three runs is not cut. Two of the three leave-one-out fits (two runs for two coefficients, so an exact solution that came out negative for `c_query`) are cut to 0, which is how the width of the calibration comes to include `c_query = 0`. The queries-only fit (sum of squared residual in load 2.275e-4) and the rows-only fit (3.112e-4) are worse than the two-coefficient fit (1.282e-4) on the same three runs; they are shown by the tool, not used.

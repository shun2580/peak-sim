# Calibration runs of the lab backtest (BookStack, 8 routes)

The records of the lab's calibration runs (`docs/simulator-spec.md` 7.2): 10,000 rows in the three scaled tables, 1 rps in total, 10 minutes, the first 2 minutes discarded, a database with 2 CPUs. Each directory holds the plan that was sent (`plan.json`), the k6 summary, the raw CPU samples of the database container (`cpu.txt`: epoch seconds and `usage_usec`), the start and end times, and the record made by `poc/lab/load/summarize-run.php` (`record.json`).

| Directory | Mix of the 8 routes (in the order of the route list) | Role |
| --- | --- | --- |
| `cal-1` | even | calibration |
| `cal-2` | first four routes 80%, last four 20% | calibration |
| `cal-3` | the routes at the 1st, 3rd, 5th and 7th places 80%, the others 20% (`odd80`) | calibration |
| `cal-3-orig` | last four routes 80%, first four 20% | **not used for calibration** (see below) |
| `cal-4` | even, **3,000 rows** in the three scaled tables, R_1 = 4 rps | calibration (see the last section) |

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

## `cal-4` and the calibration on four runs

`cal-1` to `cal-3` all have the same row count (10,000), so the weight of "number of queries" and the weight of "rows read" could not be told apart (the leave-one-out coefficients of the three-run fit spread widely, and two of them were cut to 0). `cal-4` varies the row count only: the three scaled tables hold 3,000 rows (0.3 x N_0), the routes are mixed evenly, the total is R_1 rps, 10 minutes, the first 2 minutes discarded, a database with 2 CPUs (`docs/simulator-spec.md` 7.2).

**R_1 was found by the same rule as R_0**: with `cal-4`'s own conditions only (3,000 rows, even mix, 10 minutes, first 2 discarded), rps is doubled from 1 until the database utilization is between 20% and 30%. No prediction scenario was measured. The probes are kept under `cal-4/r1-search/`.

| Probe | Started | rps achieved | Requests in the window | DB utilization (%) | App CPU utilization (%) |
| --- | --- | --- | --- | --- | --- |
| 1 rps | 2026-11-10 08:38:46 | 0.9958 (valid) | 478 | 7.7642 | 3.57 |
| 2 rps | 2026-11-10 08:49:56 | 2.0 (valid) | 960 | 14.8407 | 7.31 |
| 4 rps | 2026-11-10 09:01:06 | 4.0 (valid) | 1,920 | 27.6175 | 13.84 |

4 rps is inside 20% to 30%, so **R_1 = 4 rps**. `cal-4` was then measured as a fresh run (the probe is not reused), started 2026-11-10 09:12:36, ended 09:23:38: the rps came out (`valid`, 4.0 rps, 1,920 requests in the 480 kept seconds, none dropped, 1,680 answers 200 and 240 answers 302 from `GET /oidc/callback`), the database utilization was **27.6575%**, the app's CPU utilization 13.87%. The app was not the bottleneck. The checks of the closed network (`bin/preflight`) passed before the database was built and again before the run (exit 0).

Rank of the rps matrix (4 runs x 8 routes, rows `cal-1`, `cal-2`, `cal-3`, `cal-4`): **3** (singular values 1.5427, 0.2121, 0.2005, 0). `cal-4` has the same mix as `cal-1` (4 x its rps on every route), so its rps row adds no new mix; what it adds is the row count. The calibration's two columns (queries per second and rows per second, from the estimate) are told apart by the row count:

| Run | Rows per scaled table | Queries per second | Rows per second | Rows / queries (R/Q) |
| --- | --- | --- | --- | --- |
| `cal-1` | 10,000 | 1512.17 | 17912.8 | 11.846 |
| `cal-2` | 10,000 | 2414.83 | 27062.6 | 11.207 |
| `cal-3` | 10,000 | 608.32 | 7265.1 | 11.943 |
| `cal-4` | 3,000 | 1848.38 | 15916.2 | 8.611 |

The calibration is the same two coefficients (`c_query`, `c_row`), non-negative least squares, on the four runs, with the estimate in `../estimate/` (not rebuilt); the scenario file and the output are in `fit-4runs/` (`calibration.yaml`, `calibration-result.json`), made with `poc/lab/scenarios/make-calibration-scenario --run-scale=cal-4:3000` and `recalibrate`. The three-run fit in `fit/` is left as it was. No prediction was made.

| | |
| --- | --- |
| `c_query` | 2.81705e-4 |
| `c_row` | 1.95063e-6 |
| coefficients cut to 0 | none (`cut_coefficients: false`) |
| squared residual (load) | 1.9892e-4 |

| Run | Observed (%) | Fitted (%) | Residual (points, observed - fitted) |
| --- | --- | --- | --- |
| `cal-1` | 23.0922 | 23.0464 | +0.0457 |
| `cal-2` | 36.4054 | 36.6529 | -0.2475 |
| `cal-3` | 9.9319 | 9.2769 | +0.6550 |
| `cal-4` | 27.6575 | 27.5872 | +0.0703 |

The width of the calibration, the range of the fits made by leaving one run out (no fit is cut to 0):

| Left out | `c_query` | `c_row` |
| --- | --- | --- |
| nothing (all four) | 2.81705e-4 | 1.95063e-6 |
| `cal-1` | 2.82968e-4 | 1.81417e-6 |
| `cal-2` | 2.74741e-4 | 2.84096e-6 |
| `cal-3` | 2.87058e-4 | 1.37851e-6 |
| `cal-4` | 1.74879e-4 | 1.12854e-5 |

Leaving `cal-4` out gives back the three-run fit of the section above. The `c_query` of the five fits lies between 1.749e-4 and 2.871e-4 (a factor of 1.64); the `c_row` lies between 1.379e-6 and 1.129e-5 (a factor of 8.2), so the weight of the rows is still the less certain of the two. The queries-only fit (squared residual 2.7456e-4) is worse than the two-coefficient fit on these four runs; the rows-only fit (1.4015e-2, `cal-4` 5.28 points off) is far worse. They are shown by the tool, not used.

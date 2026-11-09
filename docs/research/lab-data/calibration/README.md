# Calibration runs of the lab backtest (BookStack, 8 routes)

The records of the lab's calibration runs (`docs/simulator-spec.md` 7.2): 10,000 rows in the three scaled tables, 1 rps in total, 10 minutes, the first 2 minutes discarded, a database with 2 CPUs. Each directory holds the plan that was sent (`plan.json`), the k6 summary, the raw CPU samples of the database container (`cpu.txt`: epoch seconds and `usage_usec`), the start and end times, and the record made by `poc/lab/load/summarize-run.php` (`record.json`).

| Directory | Mix of the 8 routes (in the order of the route list) | Role |
| --- | --- | --- |
| `cal-1` | even | calibration |
| `cal-2` | first four routes 80%, last four 20% | calibration |
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

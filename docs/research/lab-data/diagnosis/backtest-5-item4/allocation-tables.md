# Allocation tables (written by `allocate.py`; nothing here is a prediction, a measurement or a score)

Unit: points of the 2-core utilisation of the step's own window. A piece is its contribution to `diff = predicted - measured`; the pieces of a step add up to `diff`.

## Idle rate of the database (taken off the measured side)

| rows | idle CPU (cores, median) | steps read | min | max | in points of utilisation |
|---|---|---|---|---|---|
| 90000 | 0.0177 | 19 | 0.0172 | 0.0206 | 0.88 |
| 170000 | 0.0180 | 6 | 0.0176 | 0.0182 | 0.90 |
| 300000 | 0.0187 | 4 | 0.0177 | 0.0189 | 0.93 |

## Allocation by band of the measured utilisation

Sums over the steps of the band, in points. `rounding` (the whole number of requests sent against the decided rps), `estimate` (one request's work), `edge, request length alone` and `edge, added by load` (the window's edges, split by the model), `idle`. Shares are of the sum of `diff` and are printed only when that sum is 3 points or more in size (below that a share is not stable).

### 90000 rows

| band | steps | status | sum diff | rounding | estimate | edge, request length alone | edge, added by load | idle |
|---|---|---|---|---|---|---|---|---|
| below 60% | 38 | wait read | -21.33 | -0.06 | +12.12 | +0.04 | +0.19 | -33.62 |
| | | share of diff | 100% | 0% | -57% | 0% | -1% | 158% |
| 60% to below 90% | 5 | wait read | +2.43 | +0.03 | +2.83 | +0.36 | +3.63 | -4.42 |
| 60% to below 90% | 3 | edge from the residual | +16.32 | +0.11 | +10.10 | +0.12 | +8.64 | -2.65 |
| | | share of diff | 100% | 1% | 62% | 1% | 53% | -16% |
| 90% and above | 2 | edge from the residual | +29.79 | +0.00 | +6.62 | +0.00 | +24.94 | -1.77 |
| | | share of diff | 100% | 0% | 22% | 0% | 84% | -6% |

### 170000 rows

| band | steps | status | sum diff | rounding | estimate | edge, request length alone | edge, added by load | idle |
|---|---|---|---|---|---|---|---|---|
| below 60% | 30 | wait read | +11.11 | -13.19 | +15.47 | +33.97 | +1.78 | -26.93 |
| | | share of diff | 100% | -119% | 139% | 306% | 16% | -242% |
| 60% to below 90% | 4 | wait read | +24.20 | -9.85 | +4.65 | +25.99 | +7.00 | -3.59 |
| | | share of diff | 100% | -41% | 19% | 107% | 29% | -15% |
| 60% to below 90% | 3 | edge from the residual | +34.05 | -2.68 | +11.93 | +11.69 | +15.80 | -2.69 |
| | | share of diff | 100% | -8% | 35% | 34% | 46% | -8% |
| 90% and above | 2 | edge from the residual | +53.22 | +2.84 | +4.71 | +3.52 | +43.94 | -1.80 |
| | | share of diff | 100% | 5% | 9% | 7% | 83% | -3% |

### 300000 rows

| band | steps | status | sum diff | rounding | estimate | edge, request length alone | edge, added by load | idle |
|---|---|---|---|---|---|---|---|---|
| below 60% | 5 | wait read | -1.38 | -0.01 | +2.45 | +1.02 | -0.17 | -4.67 |
| below 60% | 5 | edge from the residual | +9.64 | -0.05 | +4.92 | +10.79 | -1.34 | -4.67 |
| | | share of diff | 100% | 0% | 51% | 112% | -14% | -48% |
| below 60% | 14 | not split (wait cut at the end of the step) | +41.35 | -0.15 | (estimate + edge together) +54.58 | | | -13.08 |
| 60% to below 90% | 6 | not split (wait cut at the end of the step) | +79.32 | +0.04 | (estimate + edge together) +84.88 | | | -5.61 |

## The 80% line: the two steps that bound the measured crossing, by scenario

| rows | scenario | rps | measured % | predicted % | diff | status | rounding | estimate | edge, length alone | edge, added by load | idle | one request over window (upper bound, weighted) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 90000 | s-1 | 0.3 | 61.52 | 60.99 | -0.53 | measured | +0.00 | -0.21 | +0.00 | +0.57 | -0.88 | 0.104 |
| 90000 | s-1 | 0.45 | 84.22 | 91.49 | +7.28 | residual | +0.11 | +2.82 | +0.12 | +5.11 | -0.88 | 0.117 |
| 90000 | s-2 | 0.2 | 64.98 | 65.05 | +0.07 | measured | +0.00 | +0.15 | +0.21 | +0.60 | -0.88 | 0.167 |
| 90000 | s-2 | 0.3 | 91.80 | 97.57 | +5.77 | residual | -0.00 | +2.95 | +0.00 | +3.71 | -0.88 | 0.167 |
| 90000 | s-3 | 1.0 | 79.19 | 81.40 | +2.21 | measured | +0.00 | +1.53 | +0.00 | +1.56 | -0.88 | 0.052 |
| 90000 | s-3 | 1.5 | 98.08 | 122.10 | +24.02 | residual | +0.00 | +3.67 | -0.00 | +21.24 | -0.88 | 0.052 |
| 90000 | s-4 | 0.2 | 63.31 | 63.41 | +0.10 | measured | +0.00 | +0.25 | +0.17 | +0.57 | -0.88 | 0.154 |
| 90000 | s-4 | 0.3 | 89.98 | 95.12 | +5.14 | residual | +0.00 | +2.68 | +0.00 | +3.34 | -0.88 | 0.154 |
| 90000 | s-5 | 0.7 | 62.12 | 62.69 | +0.57 | measured | +0.03 | +1.12 | -0.02 | +0.33 | -0.88 | 0.063 |
| 90000 | s-5 | 1.0 | 85.65 | 89.56 | +3.90 | residual | -0.00 | +4.60 | +0.00 | +0.19 | -0.88 | 0.068 |
| 170000 | s-1 | 0.2 | 76.03 | 84.82 | +8.79 | measured | -4.24 | +1.99 | +9.23 | +2.70 | -0.90 | 0.193 |
| 170000 | s-1 | 0.3 | 96.36 | 127.23 | +30.87 | residual | +1.06 | +2.82 | +2.72 | +25.16 | -0.90 | 0.193 |
| 170000 | s-2 | 0.1 | 71.58 | 79.16 | +7.58 | measured | -2.47 | +1.32 | +7.31 | +2.31 | -0.90 | 0.361 |
| 170000 | s-2 | 0.15 | 96.39 | 118.73 | +22.35 | residual | +1.78 | +1.89 | +0.80 | +18.77 | -0.90 | 0.271 |
| 170000 | s-3 | 0.3 | 55.98 | 59.39 | +3.41 | measured | +0.00 | +0.63 | +2.80 | +0.88 | -0.90 | 0.090 |
| 170000 | s-3 | 0.45 | 80.09 | 89.09 | +9.00 | residual | -2.67 | +4.29 | +4.88 | +3.39 | -0.90 | 0.101 |
| 170000 | s-4 | 0.1 | 55.85 | 59.37 | +3.52 | measured | +0.00 | +0.81 | +2.82 | +0.78 | -0.90 | 0.271 |
| 170000 | s-4 | 0.15 | 81.24 | 89.06 | +7.82 | residual | -0.06 | +2.78 | +1.99 | +4.00 | -0.90 | 0.203 |
| 170000 | s-5 | 0.45 | 60.78 | 66.83 | +6.05 | measured | +0.08 | +1.24 | +4.31 | +1.32 | -0.90 | 0.076 |
| 170000 | s-5 | 0.7 | 86.73 | 103.96 | +17.23 | residual | +0.04 | +4.85 | +4.82 | +8.41 | -0.90 | 0.079 |
| 300000 | s-3 | 0.3 | 69.98 | 81.31 | +11.32 | not_allocated | +0.00 | +3.02 | +9.78 | NA | -0.93 | 0.124 |
| 300000 | s-3 | 0.45 | 86.10 | 121.96 | +35.87 | not_allocated | +0.15 | +4.52 | +21.89 | NA | -0.93 | 0.139 |

## Limit rps recomputed from the tables (a check against the score of the records)

Measured limit: the straight line between the two steps that bound 80% of the measured utilisation. Predicted limit: 80% over the predicted utilisation per rps (the prediction is proportional to rps).

| rows | scenario | measured limit rps | predicted limit rps | error % | window of the upper step (s) | ZIP request alone (s) | alone over window | longest request of the upper step over window | measured limit with the sent count scaled to the decided rps | error % |
|---|---|---|---|---|---|---|---|---|---|---|
| 90000 | s-1 | 0.4221 | 0.3935 | -6.8 | 356 | 65.6 | 0.184 | 0.522 | 0.4216 | -6.7 |
| 90000 | s-2 | 0.2560 | 0.2460 | -3.9 | 400 | 66.0 | 0.165 | 0.235 | 0.2560 | -3.9 |
| 90000 | s-3 | 1.0215 | 0.9828 | -3.8 | 320 | 65.2 | 0.204 | 0.988 | 1.0215 | -3.8 |
| 90000 | s-4 | 0.2626 | 0.2523 | -3.9 | 400 | 65.4 | 0.163 | 0.220 | 0.2626 | -3.9 |
| 90000 | s-5 | 0.9279 | 0.8933 | -3.7 | 320 | 64.1 | 0.200 | 0.277 | 0.9278 | -3.7 |
| 170000 | s-1 | 0.2195 | 0.1886 | -14.1 | 400 | 123.0 | 0.308 | 0.686 | 0.2307 | -18.2 |
| 170000 | s-2 | 0.1170 | 0.1011 | -13.6 | 533 | 125.1 | 0.235 | 0.457 | 0.1186 | -14.8 |
| 170000 | s-3 | 0.4494 | 0.4041 | -10.1 | 356 | 121.3 | 0.341 | 0.417 | 0.4654 | -13.2 |
| 170000 | s-4 | 0.1476 | 0.1347 | -8.7 | 533 | 122.0 | 0.229 | 0.281 | 0.1477 | -8.7 |
| 170000 | s-5 | 0.6352 | 0.5387 | -15.2 | 343 | 121.4 | 0.354 | 0.491 | 0.6347 | -15.1 |
| 300000 | s-1 | NA | 0.1181 | NA | NA | NA | NA | NA | NA | NA |
| 300000 | s-2 | NA | 0.0738 | NA | NA | NA | NA | NA | NA | NA |
| 300000 | s-3 | 0.3932 | 0.2952 | -24.9 | 356 | 217.8 | 0.612 | 0.867 | 0.3926 | -24.8 |
| 300000 | s-4 | NA | 0.0757 | NA | NA | NA | NA | NA | NA | NA |
| 300000 | s-5 | NA | 0.2683 | NA | NA | NA | NA | NA | NA | NA |

By count (the scenarios that have a measured limit): mean error of the predicted limit, mean of the ZIP request's time alone over the window of the step above 80%, and the same with the longest request of that step.

| rows | scenarios | mean error % | mean alone over window | mean longest over window | mean error % with the sent count scaled |
|---|---|---|---|---|---|
| 90000 | 5 | -4.4 | 0.183 | 0.448 | -4.4 |
| 170000 | 5 | -12.3 | 0.293 | 0.466 | -14.0 |
| 300000 | 1 | -24.9 | 0.612 | 0.867 | -24.8 |

## Model of the edge, checked where the records give it directly (low load, wait read)

| rows | steps | sum of spill read from the records (CPU s) | sum of spill from the model (CPU s) | edge model minus edge record, per step: min / median / max (CPU s) |
|---|---|---|---|---|
| 90000 | 32 | 154.00 | 158.26 | -0.19 / -0.00 / +0.24 |
| 170000 | 22 | 406.35 | 416.19 | -0.02 / +0.47 / +1.17 |
| 300000 | 5 | 14.21 | 16.38 | +0.03 / +0.11 / +1.54 |

## One request over the window against the size of the miss

| rows | steps | Spearman: diff vs weighted upper bound of a request over window (all steps) | same, steps at or above 60% measured | diff vs longest request of the step over window (all steps) | same, 60% up | steps (60% up) |
|---|---|---|---|---|---|---|
| 90000 | 48 | +0.46 | -0.18 | +0.58 | +0.85 | 10 |
| 170000 | 39 | +0.63 | +0.15 | +0.77 | +0.87 | 9 |
| 300000 | 30 | +0.43 | -0.54 | +0.75 | +0.83 | 6 |

## Allocation over all steps of a band that could be split (wait read and edge from the residual together)

Shares are of the sum of `diff`; printed only where that sum is 3 points or more in size. The 300,000-row steps whose wait was cut are not in this table (see above).

| rows | band | steps | sum diff | rounding | estimate | edge, length alone | edge, added by load | idle |
|---|---|---|---|---|---|---|---|---|
| 90000 | below 60% | 38 | -21.33 | -0.06 | +12.12 | +0.04 | +0.19 | -33.62 |
| | | share | 100% | 0% | -57% | 0% | -1% | 158% |
| 90000 | 60% to below 90% | 8 | +18.75 | +0.14 | +12.94 | +0.48 | +12.27 | -7.08 |
| | | share | 100% | 1% | 69% | 3% | 65% | -38% |
| 90000 | 90% and above | 2 | +29.79 | +0.00 | +6.62 | +0.00 | +24.94 | -1.77 |
| | | share | 100% | 0% | 22% | 0% | 84% | -6% |
| 170000 | below 60% | 30 | +11.11 | -13.19 | +15.47 | +33.97 | +1.78 | -26.93 |
| | | share | 100% | -119% | 139% | 306% | 16% | -242% |
| 170000 | 60% to below 90% | 7 | +58.25 | -12.53 | +16.58 | +37.68 | +22.80 | -6.28 |
| | | share | 100% | -22% | 28% | 65% | 39% | -11% |
| 170000 | 90% and above | 2 | +53.22 | +2.84 | +4.71 | +3.52 | +43.94 | -1.80 |
| | | share | 100% | 5% | 9% | 7% | 83% | -3% |
| 300000 | below 60% | 10 | +8.27 | -0.06 | +7.37 | +11.81 | -1.51 | -9.34 |
| | | share | 100% | -1% | 89% | 143% | -18% | -113% |

## The owner's four points, as numbers

Point 1 (below about 60% measured, the difference is small and of both signs, so the estimate of one request is right there):

| rows | steps below 60% | diff min | diff max | steps above 0 | steps below 0 | mean diff | diff + idle: min | diff + idle: max | kappa (measured work over predicted work) min / median / max |
|---|---|---|---|---|---|---|---|---|---|
| 90000 | 38 | -1.76 | +0.46 | 3 | 35 | -0.56 | -0.87 | +1.35 | 0.941 / 0.969 / 1.018 |
| 170000 | 30 | -1.39 | +3.52 | 18 | 12 | +0.37 | -0.49 | +4.41 | 0.947 / 0.965 / 1.011 |
| 300000 | 24 | -1.49 | +7.81 | 20 | 4 | +2.07 | -0.55 | +8.74 | 0.950 / 0.952 / 0.963 |

Point 2 (the difference opens from about 70% measured, and the measured utilisation bends below the straight line of the prediction): measured utilisation per rps (the idle rate taken off) against its median at the steps below 35%, by scenario; the first step above that range where it is below 0.95 of that.

| rows | scenario | measured % per rps, low steps | predicted % per rps, low steps | first step below 0.95: rps | measured % there | predicted % there | ratio there |
|---|---|---|---|---|---|---|---|
| 90000 | s-1 | 196.9 | 203.3 | 0.45 | 84.2 | 91.5 | 0.941 |
| 90000 | s-2 | 314.9 | 325.2 | none | NA | NA | NA |
| 90000 | s-3 | 78.9 | 81.4 | 1.5 | 98.1 | 122.1 | 0.821 |
| 90000 | s-4 | 308.0 | 317.1 | none | NA | NA | NA |
| 90000 | s-5 | 84.9 | 89.6 | none | NA | NA | NA |
| 170000 | s-1 | 394.3 | 424.1 | 0.3 | 96.4 | 127.2 | 0.807 |
| 170000 | s-2 | 744.5 | 791.6 | 0.1 | 71.6 | 79.2 | 0.949 |
| 170000 | s-3 | 195.2 | 198.0 | 0.3 | 56.0 | 59.4 | 0.941 |
| 170000 | s-4 | 554.7 | 593.7 | none | NA | NA | NA |
| 170000 | s-5 | 140.9 | 148.5 | 0.45 | 60.8 | 66.8 | 0.944 |
| 300000 | s-1 | 595.5 | 677.4 | none | NA | NA | NA |
| 300000 | s-2 | 1010.4 | 1083.8 | none | NA | NA | NA |
| 300000 | s-3 | 235.4 | 271.0 | 0.45 | 86.1 | 122.0 | 0.804 |
| 300000 | s-4 | 956.2 | 1056.7 | none | NA | NA | NA |
| 300000 | s-5 | 275.4 | 298.1 | 0.15 | 36.9 | 44.7 | 0.871 |

Steps at or above 70% measured (scenario, rps, measured %, diff):

- 90000 rows: s-1 0.45: 84.2%, +7.3; s-2 0.3: 91.8%, +5.8; s-3 1.0: 79.2%, +2.2; s-3 1.5: 98.1%, +24.0; s-4 0.3: 90.0%, +5.1; s-5 1.0: 85.7%, +3.9
- 170000 rows: s-1 0.2: 76.0%, +8.8; s-1 0.3: 96.4%, +30.9; s-2 0.1: 71.6%, +7.6; s-2 0.15: 96.4%, +22.4; s-3 0.45: 80.1%, +9.0; s-4 0.15: 81.2%, +7.8; s-5 0.7: 86.7%, +17.2
- 300000 rows: s-3 0.45: 86.1%, +35.9; s-5 0.3: 75.5%, +13.9

## Residual steps: how far the edge piece moves with kappa, and the check against the upper bound of the records

The last step of a scenario has no next step to read the end of the wait from, so its factor kappa is taken as the median of the same scenario's other steps (`kappa_used`), with the range of those steps. `edge, added by load` is what is left after the pieces that are known; the range column moves kappa over the scenario's range. Where the records give an upper bound of the CPU after the window, the cap is the edge piece if the whole of that bound were spill.

| rows | scenario | rps | measured % | edge, added by load (kappa median) | range over kappa | cap from the upper bound | residual spill (CPU s) | upper bound (CPU s) |
|---|---|---|---|---|---|---|---|---|
| 90000 | s-1 | 0.45 | 84.2 | +5.1 | +4.7 to +8.5 | +5.1 | 92.6 | 92.9 |
| 90000 | s-2 | 0.3 | 91.8 | +3.7 | +3.5 to +8.2 | +3.0 | 94.3 | 88.5 |
| 90000 | s-3 | 1.5 | 98.1 | +21.2 | +20.8 to +25.7 | +20.3 | 220.9 | 214.9 |
| 90000 | s-4 | 0.3 | 90.0 | +3.3 | +3.0 to +7.7 | NA | 90.2 | NA |
| 90000 | s-5 | 1.0 | 85.7 | +0.2 | -0.5 to +3.2 | NA | 51.5 | NA |
| 170000 | s-1 | 0.3 | 96.4 | +25.2 | +23.5 to +29.3 | +18.9 | 351.4 | 301.4 |
| 170000 | s-2 | 0.15 | 96.4 | +18.8 | +16.3 to +21.6 | NA | 338.1 | NA |
| 170000 | s-3 | 0.45 | 80.1 | +3.4 | +3.2 to +6.5 | +4.0 | 135.5 | 139.8 |
| 170000 | s-4 | 0.15 | 81.2 | +4.0 | +2.5 to +6.1 | +2.5 | 147.4 | 131.7 |
| 170000 | s-5 | 0.7 | 86.7 | +8.4 | +7.8 to +12.5 | NA | 177.5 | NA |
| 300000 | s-1 | 0.025 | 16.6 | -0.4 | -0.4 to -0.2 | NA | 15.7 | NA |
| 300000 | s-1 | 0.035 | 21.8 | -0.4 | -0.4 to -0.1 | NA | 40.1 | NA |
| 300000 | s-3 | 0.07 | 16.3 | -0.1 | -0.1 to -0.1 | NA | 33.9 | NA |
| 300000 | s-4 | 0.025 | 24.8 | -0.4 | -0.5 to -0.2 | NA | 41.4 | NA |
| 300000 | s-5 | 0.07 | 17.9 | -0.1 | -0.1 to -0.0 | NA | 34.1 | NA |

## What one ZIP request draws from the database, and the time it takes alone

The prediction's work of one ZIP request divided by the time that request takes in the steps of low load (the longest request of those steps, median over the steps below 35% of the scenario) gives the share of the database it draws while it runs.

| rows | ZIP work per request (CPU s) | time alone (s): min / median / max over the 5 scenarios | cores drawn: min / median / max |
|---|---|---|---|
| 90000 | 31.43 | 64.1 / 65.4 / 66.0 | 0.476 / 0.481 / 0.490 |
| 170000 | 59.36 | 121.3 / 122.0 / 125.1 | 0.474 / 0.487 / 0.490 |
| 300000 | 104.75 | 217.7 / 221.3 / 223.7 | 0.468 / 0.473 / 0.481 |

## Trials on records already seen (NOT results and NOT tests)

Every count here was seen before the form of each trial was written. They are arithmetic on the records and show the size of what each proposal would touch; none is a prediction of an unseen count. The tables of the three counts and the prediction are not changed.

### Trial A: a ZIP request draws 0.481 cores while it runs, and the database gives at most 2 cores to all the requests together

The rate 0.481 is the median over the 5 scenarios at 90,000 rows only; the work of a request is the prediction's, the arrival times are the plan's, the idle rate is the one above. No kappa and no fit to a step. The window is read as the tool reads it.

| rows | band of measured % | steps | mean absolute error of the prediction (pt) | mean absolute error of trial A (pt) | mean error of the prediction | mean error of trial A |
|---|---|---|---|---|---|---|---|
| 90000 | below 60% | 38 | 0.60 | 0.43 | -0.56 | +0.32 |
| 90000 | 60% to below 90% | 8 | 2.47 | 3.16 | +2.34 | +3.16 |
| 90000 | 90% and above | 2 | 14.90 | 6.86 | +14.90 | +6.86 |
| 170000 | below 60% | 30 | 0.92 | 0.57 | +0.37 | +0.53 |
| 170000 | 60% to below 90% | 7 | 8.32 | 4.81 | +8.32 | +4.81 |
| 170000 | 90% and above | 2 | 26.61 | 3.45 | +26.61 | +3.45 |
| 300000 | below 60% | 24 | 2.32 | 0.66 | +2.07 | +0.17 |
| 300000 | 60% to below 90% | 6 | 13.22 | 2.12 | +13.22 | +1.99 |

Limit rps (the crossing of 80%) of the measurement, of the prediction, and of the series of trial A and of trial B:

| rows | scenario | measured | prediction | error % | trial A (the series of trial A against the measured limit) | error % | trial B (extension of the highest step whose wait is read; the measurement moved, not the prediction) | the prediction against it, % | highest step used: rps | measured % there |
|---|---|---|---|---|---|---|---|---|---|---|
| 90000 | s-1 | 0.4221 | 0.3935 | -6.8 | 0.3898 | -7.7 | 0.3865 | +1.8 | 0.3 | 61.5 |
| 90000 | s-2 | 0.2560 | 0.2460 | -3.9 | 0.2436 | -4.9 | 0.2432 | +1.1 | 0.2 | 65.0 |
| 90000 | s-3 | 1.0215 | 0.9828 | -3.8 | 0.9720 | -4.8 | 0.9907 | -0.8 | 1.0 | 79.2 |
| 90000 | s-4 | 0.2626 | 0.2523 | -3.9 | 0.2498 | -4.9 | 0.2498 | +1.0 | 0.2 | 63.3 |
| 90000 | s-5 | 0.9279 | 0.8933 | -3.7 | 0.8834 | -4.8 | 0.8970 | -0.4 | 0.7 | 62.1 |
| 170000 | s-1 | 0.2195 | 0.1886 | -14.1 | 0.1987 | -9.5 | 0.1819 | +3.7 | 0.2 | 76.0 |
| 170000 | s-2 | 0.1170 | 0.1011 | -13.6 | 0.1097 | -6.2 | 0.0985 | +2.6 | 0.1 | 71.6 |
| 170000 | s-3 | 0.4494 | 0.4041 | -10.1 | 0.4133 | -8.0 | 0.4023 | +0.4 | 0.3 | 56.0 |
| 170000 | s-4 | 0.1476 | 0.1347 | -8.7 | 0.1371 | -7.1 | 0.1346 | +0.1 | 0.1 | 55.9 |
| 170000 | s-5 | 0.6352 | 0.5387 | -15.2 | 0.5808 | -8.6 | 0.5421 | -0.6 | 0.45 | 60.8 |
| 300000 | s-1 | NA | 0.1181 | NA | NA | NA | NA | NA | NA | NA |
| 300000 | s-2 | NA | 0.0738 | NA | NA | NA | NA | NA | NA | NA |
| 300000 | s-3 | 0.3932 | 0.2952 | -24.9 | 0.3670 | -6.7 | 0.2860 | +3.2 | 0.05 | 13.5 |
| 300000 | s-4 | NA | 0.0757 | NA | NA | NA | NA | NA | NA | NA |
| 300000 | s-5 | NA | 0.2683 | NA | 0.2991 | NA | 0.2646 | +1.4 | 0.05 | 14.7 |

Trial B counts, for the steps whose wait was read, the work of the requests sent in the window in full (the window's work, less the carry-in, plus the spill) with the idle kept. It changes the measured side, so the prediction is set against the limit that this count gives (the column "the prediction against it"), not against the limit of the record. Its limit is the highest such step extended in proportion to rps up to 80%, because the step above 80% has no read wait; where that step is far below 80% (56% to 62% at several scenarios; 13.5% and 14.7% at 300,000 rows, so those are not read) the extension is long and rests on the measured work per rps staying flat.

### Trial C: examples of a rule "the load was not got through", read from the records of the steps

The stretch is the longest request of the step over the longest request of the same scenario's steps below 35% measured. The thresholds 1.10, 1.25 and 1.50 are examples to show how much the rps depends on them, not a choice. The table gives the first rps of the scenario at which the stretch reaches each (with the measured utilisation there).

| rows | scenario | measured limit rps | first rps at stretch 1.10 | at 1.25 | at 1.50 |
|---|---|---|---|---|---|
| 90000 | s-1 | 0.4221 | 0.3 (62%) | 0.45 (84%) | 0.45 (84%) |
| 90000 | s-2 | 0.2560 | 0.2 (65%) | 0.3 (92%) | none |
| 90000 | s-3 | 1.0215 | 0.7 (58%) | 1.5 (98%) | 1.5 (98%) |
| 90000 | s-4 | 0.2626 | 0.2 (63%) | 0.3 (90%) | none |
| 90000 | s-5 | 0.9279 | 0.7 (62%) | 1.0 (86%) | none |
| 170000 | s-1 | 0.2195 | 0.15 (62%) | 0.3 (96%) | 0.3 (96%) |
| 170000 | s-2 | 0.1170 | 0.1 (72%) | 0.15 (96%) | 0.15 (96%) |
| 170000 | s-3 | 0.4494 | 0.45 (80%) | none | none |
| 170000 | s-4 | 0.1476 | 0.15 (81%) | none | none |
| 170000 | s-5 | 0.6352 | 0.45 (61%) | 0.7 (87%) | none |
| 300000 | s-1 | NA | 0.1 (62%) | none | none |
| 300000 | s-2 | NA | 0.07 (69%) | none | none |
| 300000 | s-3 | 0.3932 | 0.3 (70%) | 0.45 (86%) | none |
| 300000 | s-4 | NA | 0.07 (68%) | none | none |
| 300000 | s-5 | NA | 0.2 (57%) | 0.3 (76%) | none |

For comparison, a rule on the share of the window's requests that finished after it (5%, 10%, 20%) is met at low load already at 170,000 and 300,000 rows (a ZIP request of 122 s or more lasts past the end of the window whatever the load): at 170,000 rows `s-1` reaches 5% at 0.1 rps, 43% measured, against a measured limit of 0.2195 rps.

## Work already running when a step started (an earlier step's requests)

The database CPU in the discarded minute (idle taken off) less what the step's own requests of that minute do in it (the model of the edge, with kappa). At 90,000 and 170,000 rows the wait was never cut and the database was idle at the start of every step, so the value on those steps is a check of the model: it should be near zero.

| rows | steps starting idle | min / median / max (CPU s) | steps starting with work running | min / median / max (CPU s) |
|---|---|---|---|---|
| 90000 | 48 | -10.5 / -0.0 / +0.6 | 0 | NA / NA / NA |
| 170000 | 39 | -1.4 / -0.0 / +0.4 | 0 | NA / NA / NA |
| 300000 | 10 | -0.4 / -0.0 / +0.0 | 20 | +5.6 / +29.7 / +66.4 |

The 300,000-row steps that started with work running (the load tool had cut requests at the end of the step before, which went on in the database):

| scenario | rps | measured % | rate at the start (cores) | incoming work in the discarded minute (CPU s) | over the window's CPU time |
|---|---|---|---|---|---|
| s-1 | 0.05 | 30.2 | 0.479 | +20.6 | 0.044 |
| s-1 | 0.07 | 47.4 | 0.476 | +29.6 | 0.056 |
| s-1 | 0.1 | 62.1 | 0.506 | +29.8 | 0.061 |
| s-2 | 0.025 | 26.2 | 0.962 | +49.6 | 0.061 |
| s-2 | 0.035 | 35.4 | 0.488 | +25.5 | 0.032 |
| s-2 | 0.05 | 54.0 | 0.486 | +29.7 | 0.035 |
| s-2 | 0.07 | 69.5 | 0.967 | +40.4 | 0.052 |
| s-3 | 0.025 | 8.3 | 0.970 | +58.8 | 0.251 |
| s-3 | 0.1 | 20.4 | 0.475 | +6.7 | 0.043 |
| s-3 | 0.15 | 33.3 | 0.487 | +25.7 | 0.075 |
| s-3 | 0.2 | 52.7 | 0.499 | +29.1 | 0.070 |
| s-3 | 0.3 | 70.0 | 0.962 | +40.3 | 0.073 |
| s-3 | 0.45 | 86.1 | 1.330 | +66.4 | 0.109 |
| s-4 | 0.035 | 34.5 | 0.482 | +25.2 | 0.033 |
| s-4 | 0.05 | 52.6 | 0.481 | +30.2 | 0.037 |
| s-4 | 0.07 | 67.8 | 0.959 | +39.2 | 0.051 |
| s-5 | 0.1 | 22.7 | 0.487 | +5.6 | 0.032 |
| s-5 | 0.15 | 36.9 | 0.486 | +24.2 | 0.063 |
| s-5 | 0.2 | 57.4 | 0.503 | +27.8 | 0.061 |
| s-5 | 0.3 | 75.5 | 0.966 | +38.8 | 0.065 |

## Per step

See `allocation_by_step.csv`.

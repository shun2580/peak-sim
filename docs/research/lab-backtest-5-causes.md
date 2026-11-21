# Lab backtest (B), item 4: why does the limit rps come out low in all five scenarios?

Status: DONE for the allocation of the difference from the records; the proposals of section 6 are not carried out and wait for the project owner's judgment. Every figure in this file is copied from the output of a script in `docs/research/lab-data/diagnosis/backtest-5-item4/` that reads records which already exist, or from a record; none is worked out by hand. Section 6 holds trials (arithmetic on records already seen): each sentence that gives one says that it is **not a result and not a test**.

This file follows `docs/research/lab-backtest-5.md`, the record of the fifth round of test B at 170,000 rows (verdict in `docs/research/lab-data/score-170k/`). It asks one question: **why is the predicted limit rps below the measured one in all five scenarios, and by how much does each cause account for it?** It is a separation of causes from records, not a second score. No measurement was made, no lab container was touched, and nothing is changed: not the prediction, the estimate, the calibration, the formula, the rules of the mark and of pass and fail, the specification, nor any earlier record or verdict. Where a repair is thought of, it is written in section 6 as a proposal and not carried out.

As in the files it follows, what is checked is a **check against a busy period reproduced in the lab**. Nothing here was observed on a real deployment. The limits of the lab are those of section 6 of `docs/research/lab-backtest.md` and hold here too.

## 1. What is to be explained, and the reading that is checked

At 170,000 rows (fifth round) the lowest standard is met and the goal is not; item 4 (the limit rps within ±10% of the measured one) holds for 1 of 5 scenarios. The point prediction of the limit rps is below the measurement in all five (−14.1%, −13.6%, −10.1%, −8.7%, −15.2%). At 90,000 rows (fourth round) it is below in all five as well, by 3.7% to 6.8%. At 300,000 rows (third round, a ladder that could not be decided; section 2) one scenario reached 80% measured and its limit is 24.9% below.

The project owner read the score of the 170,000-row round and put forward a reading in four points. They are checked here, not assumed, and where the records do not agree they are said not to:

1. Below about 60% measured, the difference is small (−1.4 to +1.8 points) and of both signs, so the estimate of the database work of one request is right in that range; the earlier thought that the estimate over-states the rows does not fit these numbers.
2. The difference opens from about 70% measured (at 170,000 rows, `s-1` 0.2 rps: 84.8% predicted, 76.0% measured; 0.3 rps: 127.2% and 96.4%). The prediction grows in proportion to rps; the measurement bends before saturation.
3. The prediction gives the database work of all the requests sent in a step, divided by the window; the measurement is the database's CPU time inside the window. Near saturation requests wait, and part of the work of the requests sent in the window falls after it (the wait at the end of a step is outside the window, `docs/simulator-spec.md` 7.4.3). The longer one request is (a ZIP request at 170,000 rows has an upper bound of 540.9 s of CPU time, longer than a window of about 300 s), the more falls out.
4. So the miss may not be an error of the estimate but a difference between the quantity predicted and the quantity measured near saturation, which would agree with the miss growing with the row count.

## 2. The records, and what differs between the counts

| | 90,000 rows | 170,000 rows | 300,000 rows |
|---|---|---|---|
| Round | B, 4th | B, 5th | B, 3rd (undecidable ladder) |
| Steps recorded (valid) | 48 (48) | 39 (39) | 30 (27) |
| Routes sent | 8 | 7 (`GET /books/{slug}` taken out by a mark) | 8 |
| Request time limit of the load tool | 600 s | 1,800 s | 600 s |
| Wait at the end of a step | up to 600 s | up to 1,800 s | 30 s (the tool's default); a request still running was cut |
| Wait cut at the end of a step | 0 of 48 steps | 0 of 39 | **24 of 30** (1 to 7 requests per step) |
| Longest wait after the window | 183 s | 172 s | not recorded |
| Requests counted for "did the decided rps come out" | sent in the window | sent in the window | finished when the window ended |
| HTTP 500 | 0 | 0 | 190 (`GET /books/{slug}`, every step) |
| Database / app container | 2 CPUs, no memory limit / 6 CPUs, 6 GiB | same | same |
| Window | 320 to 1,600 s; the first 60 s are discarded | 343 to 1,600 s | 356 to 1,600 s |

Both the predicted and the measured utilisation are a share of the database's **2 cores**, at all three counts: the prediction files state `resources.cores` 2.0, and the measured value is the change of the database container's `usage_usec` over the window divided by the window times 2 (rebuilt from the readings for all 127 recorded steps; the difference from the recorded value is 0 µs). The conditions that differ between the counts, and have to be kept apart from any difference between them, are in `docs/research/lab-data/diagnosis/backtest-5-item4/conditions/README.md`. The one that matters most here: **the 300,000-row records were made with an older tool**, which waited 30 s at the end of a step and cut what was still running; the cut requests went on in the database and were still running at the start of the next step in 20 of the 30 steps. What is said of 300,000 rows below is therefore said with less force, and the steps whose wait was cut are not split (section 3).

The tables of each count (one row per step: predicted and measured utilisation, the difference, the requests sent per route and the work the prediction gave them, the requests that finished after the window and the longest wait, the time of one request against the window, the database CPU after the window) are in `docs/research/lab-data/diagnosis/backtest-5-item4/{90k,170k,300k}/`. They were built by one worker from the records; the reviewer and a second worker rebuilt the numbers of the step table from the records without seeing each other's or the first tables, and the three agreed (utilisation to 0.01 point, work to 0.15 CPU second, request counts exactly) on the 90,000-row, 170,000-row and 300,000-row steps that the three had in common.

## 3. How the difference is allocated

The unit is points of the 2-core utilisation of the step's own window (`1 point = 0.02 × window_s` CPU seconds of that step). The difference is `diff = predicted − measured`. For every recorded step it is split into pieces that add up to it exactly:

```
diff = d_rounding + a_estimate + edge_unloaded + edge_extra − idle
```

- **`d_rounding`** (arithmetic). The prediction is made at the decided rps. The step sent a whole number of requests, and the work of those requests set against the window is not the same number: a window of 400 s at 0.2 rps holds 80 requests, 12 of them ZIP requests where the decided rps gives 11.4.
- **`a_estimate`**. The database CPU time of the requests that were sent, as measured, against the work the prediction gave them: `(1 − κ) × (work sent)`. `κ` is one factor for the whole step: the database's CPU from the step's first reading to the next step's first reading (idle taken off) divided by the predicted work of every request the step sent, the discarded minute's included. It can be formed for the steps that have a next step in the same scenario, at 90,000 and 170,000 rows (the database was idle at the start of all 87 steps and the wait was never cut, so no earlier work is in it). It assumes that the requests of the discarded minute have the same factor as those of the window, and takes their count from the plan of the step.
- **`edge`**, the window's edges: work of requests sent in the window that is done after it (spill), less work of requests sent in the discarded minute that is done inside it (carry-in). The spill of a step whose wait is read is read from the records; the carry-in follows from `κ`. `edge` is split with a model of one ZIP request: **`edge_unloaded`** is what the length of a request alone gives, with no waiting; **`edge_extra`** is the rest, what a step with a high load adds. The model rests on the records showing that a ZIP request draws nearly the same share of the database at the three counts: the work the prediction gives it divided by the time it takes in the steps of low load is 0.476 to 0.490 cores at 90,000 rows, 0.474 to 0.490 at 170,000 and 0.468 to 0.481 at 300,000 (the times alone are about 65 s, 122 s and 221 s). The model spreads the work of each ZIP request evenly over that time, with the arrival times of the plan. It is an assumption; it is checked in section 4.1.
- **`idle`** (arithmetic). The database's own CPU when nothing is asked of it (0.0177, 0.0180 and 0.0187 cores at the three counts: 0.88 to 0.93 points) is in the measurement and not in the prediction.

The four letters of the investigation map to the pieces as follows: (a) the estimate of one request is `a_estimate`; (b) the work that fell outside the window is `edge_unloaded`, the part given by the length of a request alone; (c) the stop that comes with waiting, as the database approaches its 2 cores, is `edge_extra`; (d) what is left and what cannot be split is `d_rounding`, `idle`, and for the steps that could not be split, the remainder (section 4.5). The yardstick of the shares is the sum of `diff` over the steps of a band of the measured utilisation; a share is printed only where that sum is 3 points or more in size. **For the last step of each scenario** there is no next step to read the end of the wait from, so `κ` is the median of the scenario's other steps and `edge_extra` is a residual that carries the error of that `κ` (section 4.3 gives its range). The steps of the 300,000-row records whose wait was cut are not split.

## 4. Results

### 4.1 The model of the edge holds where it can be checked

At the 59 steps below 35% measured whose wait was read, the model's spill and the spill in the records agree: the sums are 158.3 and 154.0 CPU seconds at 90,000 rows (32 steps), 416.2 and 406.3 at 170,000 rows (22 steps), and 16.4 and 14.2 at 300,000 rows (5 steps); the model's edge differs from the record's by a median of −0.00, +0.47 and +0.11 CPU seconds per step, at most 1.5. At the start of the 87 steps of 90,000 and 170,000 rows the work found in the discarded minute that does not belong to the step's own requests is between −10.5 and +0.6 CPU seconds (90,000) and between −1.4 and +0.4 (170,000); it is near zero, as it should be where the database was idle.

| rows | steps | sum of spill read from the records (CPU s) | sum of spill from the model (CPU s) | edge model minus edge record, per step: min / median / max (CPU s) |
|---|---|---|---|---|
| 90000 | 32 | 154.00 | 158.26 | -0.19 / -0.00 / +0.24 |
| 170000 | 22 | 406.35 | 416.19 | -0.02 / +0.47 / +1.17 |
| 300000 | 5 | 14.21 | 16.38 | +0.03 / +0.11 / +1.54 |

### 4.2 The allocation

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

Read with the unit above. Summary by count:

- **90,000 rows.** Below 60% measured, 38 steps: the sum of `diff` is −21.33 points, most of it `idle` (−33.62), against `a_estimate` (+12.12): the pieces other than these two are near 0. At 60% to 90% (8 steps) the sum is +18.75: `a_estimate` 69%, `edge_extra` 65%, `edge_unloaded` 3%, `idle` −38%. At 90% and above (2 steps) it is +29.79: `edge_extra` 84%, `a_estimate` 22%, `idle` −6%.
- **170,000 rows.** Below 60% (30 steps): +11.11, made of `edge_unloaded` +33.97, `a_estimate` +15.47, `d_rounding` −13.19, `idle` −26.93 and `edge_extra` +1.78 (the shares in the table, from −242% to 306%, are large because the sum is small against its pieces). At 60% to 90% (7 steps): +58.25: `edge_unloaded` 65%, `edge_extra` 39%, `a_estimate` 28%, `d_rounding` −22%, `idle` −11%. At 90% and above (2 steps): +53.22: `edge_extra` 83%, `d_rounding` 5%, `a_estimate` 9%, `edge_unloaded` 7%, `idle` −3%.
- **300,000 rows.** Below 60%, the 10 steps that could be split: +8.27: `edge_unloaded` +11.81, `a_estimate` +7.37, `idle` −9.34. The other 20 steps (14 below 60%, 6 at 60% to 90%) are not split: their `diff` is +41.35 and +79.32 points, of which `d_rounding` and `idle` are known; what is left, the estimate and the edge together, is +54.58 and +84.88 points.

`κ` at the low steps has a median of 0.969, 0.965 and 0.952 (range 0.941 to 1.018, 0.947 to 1.011 and 0.950 to 0.963) over the steps below 60% at the three counts. The database CPU of a request is 3% to 5% below the work the prediction gives it, at every row count and every load below 60%; it does not grow with the row count.

### 4.3 The last steps, whose split is a residual

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

At the steps of highest load the split of `edge_extra` is known to within the range shown: at 170,000 rows `s-1` 0.3 (96.4% measured) it is +25.2 points, +23.5 to +29.3 over the scenario's `κ`, and not more than +18.9 if the whole of the upper bound that the records give were spill. In 4 of the 6 steps that have a bound, the residual spill is above it by 6 to 50 CPU seconds (up to about 6 points); the allocation at those steps is not exact to better than that.

### 4.4 The 80% line

The measured limit rps is the straight line between the two steps that bound 80% of the measured utilisation; the predicted one is where the prediction (proportional to rps) reaches 80%. Recomputed from the tables they give the errors of the score of each record (−14.1%, −13.6%, −10.1%, −8.7%, −15.2% at 170,000 rows). The limit is decided by those two steps, so the allocation at them is what matters for the limit:

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

At 170,000 rows the two steps of a scenario that bound the crossing have a `diff` of 3.4 to 8.8 points at the lower step and 7.8 to 30.9 at the upper. At the lower step (55.9% to 76.0% measured) the largest piece is `edge_unloaded` (2.8 to 9.2 points, 71% to 105% of `diff`); at the upper step (80.1% to 96.4%) `edge_extra` is the largest in four of the five scenarios (+4.0 to +25.2 points; at the fifth, `s-3`, `edge_unloaded` is +4.9 and `edge_extra` +3.4). Together the two edge pieces are 93% to 136% of `diff` at the lower steps and 77% to 92% at the upper; `a_estimate` is 17% to 23% of `diff` at the lower steps and 8% to 48% at the upper.

### 4.5 The 300,000-row steps that were not split

The load tool cut the requests still running 30 s after the end of a step, and they went on in the database. At the start of 20 of the 30 steps the database was working at 0.48, 0.97 or 1.33 cores (the first interval of the step; in 7 of the 18 changes of step with such a rate, the rate fell below 0.1 cores before the step's own window began, in the others it did not fall in the readings). The work found running in the discarded minute alone, after taking off what the step's own requests do in it, is 5.6 to 66.4 CPU seconds (median 29.7), 3% to 25% of the window's CPU time; what is running later in the window is not sized by the records. So the measured value of those steps holds work of an earlier step, which the prediction does not give, and the window does not hold all the work of its own requests either: the two are of opposite sign and the records give only the first as a lower bound. This is why those steps are not split.

### 4.6 The four points, checked

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

**Point 1 (low steps).**

- At 170,000 rows 27 of the 30 steps below 60% lie between −1.4 and +1.8 points; three lie above (+2.45 at 19.8%, and +3.41 at 56.0% and +3.52 at 55.9%, both just below 60%). At 90,000 rows the differences are −1.76 to +0.46: **35 of the 38 are negative**, not of both signs; the offset is the idle rate, 0.88 points (with it added back they lie between −0.87 and +1.35). At 300,000 rows they are −1.49 to +7.81 and 20 of 24 are above zero, which is the older tool's incoming work (4.5), not a property of the estimate. The first half of the point holds in size at 90,000 and 170,000 rows and not at 300,000.
- The estimate of one request is right to within about 5% (`κ` 0.94 to 1.02), one-signed and small: the measured database CPU of a request is 3% to 5% below the prediction's work. The records do not say whether it is the rows or the statements. This neither supports nor contradicts the thought that the estimate over-states the rows; it says that whatever is over-stated is small in sum, and it accounts for 1.9 to 4.9 points at the upper step of the 80% line at 170,000 rows (8% to 48% of `diff` there), not for most of it.

**Point 2 (the difference opens from 70%).** At 90,000 rows the steps from 70% measured have a `diff` of +7.3 (84.2%), +5.8 (91.8%), +2.2 (79.2%), +24.0 (98.1%), +5.1 (90.0%) and +3.9 (85.7%); the steps from 60% to 70% have −0.5 to +0.6. At 170,000 rows the steps from 70% have +8.8, +30.9, +7.6, +22.4, +9.0, +7.8 and +17.2, and the two steps at 55.9% and 56.0% already have +3.5 and +3.4. Measured utilisation per rps (idle taken off), against its median at the steps below 35% measured, first falls below 0.95 of it at: 0.941 at 84.2% and 0.821 at 98.1% (90,000 rows, `s-1` and `s-3`; the other three scenarios do not fall below 0.95); 0.807 at 96.4%, 0.949 at 71.6%, 0.941 at 56.0% and 0.944 at 60.8% (170,000 rows, `s-1`, `s-2`, `s-3`, `s-5`; `s-4` does not). So the point holds in outline, with this exception: at 170,000 rows the first sign of a bend is at 56% to 72% in three of the five scenarios, and what it comes from there is `edge_unloaded`, which the point does not name (point 3).

**Point 3 (the quantity predicted is not the quantity measured).** It holds, and has two parts that the point does not separate.

- *The length of one request alone.* A ZIP request that was sent in the last stretch of a window finishes after it, and one sent in the discarded minute is still running in it; with a discarded minute shorter than a request they do not cancel. At 170,000 rows `edge_unloaded` is +33.97 points over the 30 steps below 60% (1.1 points per step; with `d_rounding` −13.19 and `idle` −26.93 it leaves the sum of `diff` at +11.11) and +37.68 over the 7 steps at 60% to 90%. The records show it at a low load: from 11% measured at 170,000 rows (one request finishes after the window; none at 10.8% and below) and from 14.6% at 90,000 rows. This part is arithmetic from the arrival times and the request's time alone, checked against the records (4.1).
- *What a high load adds.* From about 70% measured, requests overlap: at 170,000 rows `s-1` 0.2 rps there are on average 3.5 ZIP requests running (0.0286 requests per second × 123 s) and a ZIP request draws 0.49 cores while it runs, so 2 cores are reached at about 4.1 requests. Waiting makes a request longer (the longest request of the step is 149 s at 0.2 rps and 274 s at 0.3, against 123 s alone), more of the work falls after the window and less is done inside it. This is `edge_extra`: +2.7 points at 76.0% measured, +25.2 at 96.4% (the latter a residual, 4.3).
- *The statement that a request is longer than the window does not fit as it is written.* The upper bound of a ZIP request (540.9 s at 170,000 rows) is not what the requests take: alone it takes 122 s (4.4 times less), shorter than the window (343 to 1,600 s) and longer than the discarded minute (60 s). What matters for the edge is the second comparison. The upper bound is not an input of any piece above.

**Point 4 (the miss is not an error of the estimate).** Largely, but not wholly. The estimate of one request is close (4.2: `κ` 0.94 to 1.02) and at the two steps that decide the limit the difference is mostly the edge: at 170,000 rows `edge_unloaded` plus `edge_extra` is 93% to 136% of `diff` at the lower steps and 77% to 92% at the upper (4.4), while `a_estimate` is 17% to 23% and 8% to 48%. The miss is not wholly a difference of quantity: the estimate is 3% to 5% high at every load, `d_rounding` is −4.2 points at one step, and the step above 80% is at 96% measured in two of the five scenarios at 170,000 rows (the database cannot show more than 100%, 4.3).

### 4.7 The size of the miss next to one request over the window

| rows | steps | Spearman: diff vs weighted upper bound of a request over window (all steps) | same, steps at or above 60% measured | diff vs longest request of the step over window (all steps) | same, 60% up | steps (60% up) |
|---|---|---|---|---|---|---|
| 90000 | 48 | +0.46 | -0.18 | +0.58 | +0.85 | 10 |
| 170000 | 39 | +0.63 | +0.15 | +0.77 | +0.87 | 9 |
| 300000 | 30 | +0.43 | -0.54 | +0.75 | +0.83 | 6 |

By count, the miss grows with the time one ZIP request takes alone over the window. For the scenarios that reached 80%, the mean error of the predicted limit rps is −4.4% at 90,000 rows (5 scenarios; the time alone over the window of the step above 80% has a mean of 0.183), −12.3% at 170,000 rows (5 scenarios; 0.293) and −24.9% at 300,000 rows (one scenario; 0.612). The three move together, in the order of the row count. Within a count the match is weak by the upper bound (rank correlation of `diff` with the weighted upper bound of a request over the window: +0.46, +0.63, +0.43 over all steps; −0.18, +0.15, −0.54 at the steps at or above 60% measured) and strong with the longest request of the step over the window (+0.58, +0.77, +0.75 over all steps; +0.85, +0.87, +0.83 at 60% and above). The second does not show the length of a request as a cause by itself: the longest request of a step is lengthened by the waiting that a high load brings, so it rises with the load.

## 5. Reading

A cause is called established only where an arithmetic of the records shows it directly.

### 5.1 Arithmetic on the records (established)

1. The pieces add up to the difference at every step, and the rebuilt utilisation equals the recorded one (more than two thousand checks in `allocate.py`).
2. The database's idle CPU is 0.88 to 0.93 points of utilisation and lies on the measured side only; it is the main negative piece below 60% measured at all three counts (−33.62, −26.93 and −22.42 points over the steps below 60%).
3. The number of requests a step sends is a whole number: the work given in a window differs from the work at the decided rps by up to −4.2 points (170,000 rows `s-1` 0.2 rps: 12 ZIP requests where the decided rps gives 11.4). With the sent count scaled to the decided rps, the measured limit of `s-1` at 170,000 rows would be 0.2307 rps instead of 0.2195 and the error −18.2% instead of −14.1%; the mean over the five is −14.0% instead of −12.3%. The rounding hides part of the miss at 170,000 rows; it does not cause it.
4. The measured database CPU of a request is 3% to 5% below the prediction's work (`κ`), at all three counts and every load below 60%.
5. The prediction's work of one ZIP request divided by its time alone is 0.47 to 0.49 cores at all three counts (it takes 65 s, 122 s and 221 s alone).
6. At the steps whose wait is read, the database's CPU after the window is the work of requests sent inside it: at 170,000 rows `s-1` 0.2 rps it is 141 CPU seconds (143 read, idle taken off) of the 712 sent (20%), 17.6 points of that step's window before the carry-in (5.7 points) is taken off.
7. At 90,000 and 170,000 rows the database was idle at the start of every step and no wait was cut; at 300,000 rows 20 of 30 steps started with work running and 24 of 30 waits were cut.
8. The mean miss in the limit rps follows the row count, in the order of the ratio of the request's time alone to the window (4.7: −4.4%, −12.3% and −24.9% at 0.183, 0.293 and 0.612; the last is one scenario of an older tool).

### 5.2 Consistent with the records, not shown

1. That a ZIP request spends its database work evenly over its time. It is consistent with the draw of 0.47 to 0.49 cores at the three counts and with the check of 4.1 at low load; no record holds the database time of one request under load.
2. That the split of `edge` into `edge_unloaded` and `edge_extra` at high load is what the model says; at the last steps it is a residual under the scenario's `κ`.
3. That the part of the miss that waiting adds is the largest part at the steps at and above 90% measured (83% to 84% of `diff` at 90,000 and 170,000 rows); the steps at and above 90% are residuals (two at 90,000 rows and two at 170,000 rows), and the bound of the records does not fit the residual at four of the six steps that have one.
4. That the estimate is 3% to 5% high because of the rows, or because of the statements, or of a coefficient.

### 5.3 Not determined

1. The split between the estimate and the edge at the steps of highest load to better than about 6 points (4.3).
2. What the 300,000-row steps would give with a wait that was not cut: the older tool's incoming work cannot be separated from the step's own load beyond the lower bound of 4.5.
3. Whether the measured limit at 170,000 rows with the sent count of each step scaled to the decided rps is the better yardstick: the scaling takes the utilisation as proportional to the load, which is not so near saturation.
4. Why `edge_extra` is +3.4 points at the upper step of `s-3` at 170,000 rows (80.1% measured) and +25.2 at `s-1` (96.4%): the second is above 90% and the cap of 100% plays in it; the step between is not in the records.
5. The effect of the discarded minute being 60 s, shorter than a ZIP request at 170,000 and 300,000 rows. The records have only the minute of 60 s.

## 6. Proposals

These are proposals only. Nothing in them is carried out; the maintainer chooses. Proposals B and C touch the rules of the score or the specification and are not for the project to decide. The numbers marked trial are arithmetic on records already seen (90,000, 170,000 and 300,000 rows) and are **not results and not tests**: a form chosen after the data was seen is a fit, and a check on data already seen is not a test (specification 7.4.4). The scripts are in `docs/research/lab-data/diagnosis/backtest-5-item4/trials/` and `allocate.py`.

### 6.1 Proposal A. Make the prediction say what is measured

**What changes.** Specification 5, 6.1 and 7.2 (the limit rps is solved directly from a proportional formula): the prediction would carry the loss at the window's edge and the stop near saturation, and the limit rps would be solved numerically. The prediction tool (`LadderPrediction.php`, `limitRps()`) and its input (the length of the discarded minute, the window, the time one request takes) would change. The lines of pass and fail and the rules of the score do not change; the measuring tool does not change.

**What is gained.** The measurement stays as it is; the form can be fixed from records already seen and checked on a new count.

**What is lost, and the risks.** The loss at the window's edge is made by the lab's design (a discarded minute shorter than a ZIP request, a window that ends while requests are running). Put into the prediction, it would make the prediction describe the lab and not a deployment, whose load is continuous: for a deployment the proportional formula is the one that stays right in a steady state. The time of one request would have to be an input: the upper bound the prediction carries (540.9 s) is 4.4 times what the requests take (122 s) and would make the loss about 13 times larger than the 122 s does (arithmetic on the closed form of A-3: 53% against 4% of the work at a window of 400 s; not a result, not a test). The form of the bend cannot be fixed from a function of the utilisation alone (A-1 below): it needs the time of a request over the window.

**Trials (records already seen; not results and not tests).** The baseline (A-0) is the present formula against the measured limit: −3.7% to −6.8% at 90,000 rows, −8.7% to −15.2% at 170,000 rows, and −24.9% at 300,000 rows for the one scenario that has a measured limit (older tool) (a trial; not a result, not a test).
- A-1, a bend as a function of the predicted utilisation alone (a smooth minimum `P ÷ (1 + (P/100)^n)^(1/n)`, or a hyperbola `P ÷ (1 + k·P/100)`), the parameter fixed on two counts and applied to the held-out third. For the held-out count 90,000 rows the errors of the limit rps are +6.3% to +9.8% (smooth minimum, n = 4.0) and +9.9% to +13.5% (hyperbola) (a trial; not a result, not a test). For 170,000 rows they are −8.2% to −1.1% and −4.5% to +2.8% (a trial; not a result, not a test), and for 300,000 rows −20.1% and −16.2% (one scenario, older tool) (a trial; not a result, not a test). Fixed on one count and applied to another, 90,000 rows applied to 170,000 rows give −13.2% to −6.6% (smooth minimum) and −8.6% to −1.6% (hyperbola) (a trial; not a result, not a test), and 170,000 rows applied to 90,000 rows give +3.2% to +6.5% and +7.9% to +11.4% (a trial; not a result, not a test). The sign changes between counts: a form that helps 170,000 rows moves 90,000 rows from −4% to −7% to between +3% and +14%, so a bend that is a function of the utilisation alone does not carry across counts (a trial; not a result, not a test).
- A-2, every ZIP request spends its predicted work evenly over its own time (the step's mean ZIP time), the discarded minute's requests add their share that falls inside the window, and what falls after the window is dropped; no parameter is fitted. The errors of the limit rps become −4.8% to −7.1% at 170,000 rows and −3.1% to −5.2% at 90,000 rows, against −8.7% to −15.2% and −3.7% to −6.8% (a trial; not a result, not a test). The time is the mean measured over the step, so it contains whatever the load stretched it by, and part of what waiting adds is inside it.
- A-3, the loss at the window's edge in a closed form that has no fitted parameter: the share of the work that falls inside the window is `1 − (T − P)² ÷ (2·T·W)` for a request of time T (T > P), a discarded minute of P = 60 s and a window of W. With T the time of the scenario's lowest step the errors of the limit rps at 170,000 rows become −5.8% to −11.3%, and at 90,000 rows −3.7% to −6.7% (T is near P there, so the share is near 1) (a trial; not a result, not a test). With T the measured mean time of each step they become −3.8% to −8.4% and −2.9% to −5.7% (a trial; not a result, not a test).
- A-4 (from the allocation script), a ZIP request draws 0.481 cores while it runs (the median at 90,000 rows only), the database gives at most 2 cores to all the requests together, shared equally; the work of the prediction, the arrival times of the plan and the idle rate go in, and nothing is fitted to a step. The errors of the limit rps become −6.2% to −9.5% at 170,000 rows (all five within 10%), −4.8% to −7.7% at 90,000 rows, and −6.7% at 300,000 rows for the one scenario that has a measured limit (against −24.9%) (a trial; not a result, not a test). The mean absolute error of a step in the band at and above 90% measured is 6.9 points at 90,000 rows (14.9 for the prediction) and 3.5 at 170,000 rows (26.6) (a trial; not a result, not a test). Every count had been seen when the form was written.
- Reading: the closed form of the window's edge (A-3) accounts for 2.9 to 4.0 points of the miss of the limit rps at 170,000 rows with T of the lowest step, and 4.5 to 6.9 with T of each step; A-2 accounts for 3.9 to 8.1 points; the rate-capped model (A-4) accounts for most of it (a trial; not a result, not a test). What is left (−4% to −11% in A-3, −6% to −10% in A-4) is the part that the window's edge does not explain and that the model explains only in part; the three counts cannot separate it (a trial; not a result, not a test).

**Records that can check it.** The form from the records already seen; the test on a count not yet seen (the new prediction committed before it is measured; the lab is used).

**Touches the specification or the rules?** The lines of pass and fail and the rules of the score: no. The text of 5, 6.1 and 7.2: yes.

### 6.2 Proposal B. Make the measurement say what is predicted

**What changes.** Specification 7.2 (the definition of the measured utilisation: the change over the window) and 7.4.3 (the wait at the end of a step: the work done in it is outside the window), and the length of the discarded minute. The measuring tool: `DbUtilization.php`, `sample-cpu` (how long it reads), `measure-ladder` and `measure-ladder-chain` (the minute and the wait), `scenario.js` and `summarize-run.php`, `LadderScore.php`. There are three forms:

- **B-warm-up** (the definition of the utilisation is kept). The discarded minute is made at least as long as the longest request (a ZIP request or more). The loss at the window's edge is 0 for T ≤ P (the closed form of A-3). The tool changes in one argument. A step takes T − 60 s longer (about 60 to 150 s at 170,000 rows). The T that counts is the time of a request at the load of the step, which near saturation is more than twice the time alone (the longest request is 274 s at 0.3 rps at 170,000 rows, against 123 s alone), so the minute has to be long for the steps that matter.
- **B-whole** (no per-request time needed). The database's CPU from the step's first request until the database is quiet again is divided by the requests sent in that time (the discarded minute's included), and multiplied by rps. `sample-cpu` reads until the end of the wait, and the discarded minute's requests are counted.
- **B-per-request** (the database time of each request). A statement-level history on the database (`performance_schema`) or a measurement in the app. The lab's user has no right to it (`poc/lab/compose/lab.compose.yaml`); the rights and safety conditions would have to be changed. A request must be matched to its statements.

**What is gained.** B-warm-up removes the lab's window edge alone and keeps the meaning of the utilisation (the database's CPU over the window); saturation stays in the measure, so the measurement is not set to agree with the prediction. B-whole checks the cost per request directly, before saturation. B-per-request would allow a direct allocation by route.

**What is lost.** The new measures are not comparable with the earlier verdicts (the definition of the utilisation for B-whole and B-per-request, the length of the minute for B-warm-up); the earlier verdicts are not changed, and a new result is a separate record. The lab is used again, and the five ladders of 170,000 rows took a night. B-whole gives values above 100% (it is a quantity of work and not a utilisation) and hides saturation: because it counts what the prediction counts, the miss shrinks to the size of `κ` and the rounding, and a test that measures the same quantity as the prediction is a weaker test of it. B-warm-up leaves what the edge does not explain (waiting near saturation, the reading of A-3), and a minute as long as the stretched request makes the steps near the limit long.

**Records that can check it.** B-whole in part, on the records already seen. B-warm-up has no direct record (the minute is 60 s in all of them).

**Trials (records already seen; not results and not tests).**
- B-1, B-whole on the steps whose wait is read: the database CPU of the whole step (idle taken off) divided by the predicted work of every request started in it, minus 1. It is −6.2% to +2.0% (median −2.1%) over 34 of the 39 steps at 170,000 rows, and −8.4% to +5.7% (median −2.5%) over 43 of the 48 at 90,000 rows (a trial; not a result, not a test). At the steps at 60% predicted and above that can be counted (4 at 170,000 rows, 5 at 90,000) it is −2.4% to +0.3% and −2.0% to +0.1% (a trial; not a result, not a test). The step above 80% is the last of its scenario in every case and cannot be counted. This is the quantity `κ` of section 3, formed by an independent script; the two differ a little in how idle and the discarded minute's requests are counted.
- B-3 (from the allocation script), the work of the requests sent in the window counted whole (the window's work less the carry-in plus the spill, idle kept) on the highest step whose wait is read, extended in proportion to rps up to 80%. The predicted limit rps is within −0.8% to +3.7% of the limit this count gives in all ten scenarios at 90,000 and 170,000 rows, against −3.7% to −15.2% for the recorded measured limit (a trial; not a result, not a test). The step used is at 56% to 79% measured, so the extension is long where it starts short of 70% (it needs the work per rps to stay flat); at 300,000 rows the step is at 13.5% and 14.7%, so it is not read.
- B-2, what B-warm-up would do, by the closed form of the window's edge (A-3 read the other way): the errors move from −8.7% to −15.2% to about −5.8% to −11.3% at 170,000 rows (T the time of the lowest step) and stay at −4% to −7% at 90,000 rows (a trial; not a result, not a test).

**Touches the specification or the rules?** B-warm-up the length of the minute (the lines of pass and fail do not change); B-whole and B-per-request the definition of the utilisation (7.2, 7.4.3). The maintainer decides.

### 6.3 Proposal C. Redefine the limit

**What changes.** Specification 7.2 (the limit rps as the rps where the window's utilisation crosses 80%) and 6.1 (the limit multiplier as the first multiplier over the threshold): replaced by "the rps at which the load sent can no longer be got through" (waiting, or the delay of requests, grows without end). The meaning of item 4 of 7.4 changes. The tool: `measuredLimit()` in `LadderScore.php`, the stop rule of `measure-ladder` ("a step at 80% or more"). On the prediction: a limit formula that goes to 100% of the utilisation or to where a waiting formula diverges.

**Is it nearer to what a user wants to know ("where does it fall over")?** Yes. What hurts is not "the database CPU is at 80%" but the point where requests begin to pile up and answers are late for good, ending in time-outs or failures. 80% is a threshold chosen on the safe side. Two cautions: with 80% replaced by 100% the limit by the proportional formula is 1.25 times the present prediction (arithmetic, not a result and not a test: this is the direction, not a proposal of 100%); and where one request takes minutes, "piling up" and "long" cannot be told apart by the length of the window.

**Can the records give "can no longer get through"?** There are signs; nothing is fixed. The load tool's running requests per route (about one reading a second), the requests that finish after the window and the longest wait, and the database CPU after the window. A step begins empty, so the running requests rise at first (Little's law); at 170,000 rows even a low step has requests that finish after the window. What has to be seen is whether the arrivals minus the completions return to 0 at the window's end; the completion of each request is not recorded, but for the heavy route it can be built from the running requests and the planned arrivals (first in, first out; one-second steps). The rule has to be new and **the maintainer sets it**.

**What is gained.** Nearer to the user's question, and less dependent on the window's edge. **What is lost.** The definition of the limit changes, so the four earlier rounds of item 4 cannot be compared; the rule of waiting (the threshold and which series) is a new degree of freedom, and fixed after seeing the data it is a fit (the promise at the head of 7.4: the rule is fixed before the data); the prediction also needs a waiting formula, which brings C near to A.

**Records that can check it.** The three kinds above can check candidate rules on records already seen; the test is a new count.

**Trials (records already seen; not results and not tests).** The lines below were put down after part of the records had been seen; they are examples of a rule, not a choice.
- A rule on the share of the window's requests that finish after it. At 10% it is crossed in 2 of the 10 scenarios at 90,000 and 170,000 rows (170,000 rows `s-1` at 0.3 rps, 96.4% measured, 1.37 times the measured limit rps; `s-2` at 0.1 rps, 71.6% measured, 0.85 times), and at 20% in none (a trial; not a result, not a test). At 5% it is crossed already at a low load at 170,000 rows (`s-1` at 0.1 rps, 43% measured, against a limit of 0.2195 rps), because a ZIP request of 122 s or more lasts past the end of the window whatever the load (a trial; not a result, not a test). The share rises slowly with the utilisation and jumps only at the last step (96% measured and above), so the knee is at about 1.4 times the measured limit at 170,000 rows `s-1` and about 1.5 times at 90,000 rows `s-3` (5% at 1.5 rps; the limit is 1.0215 rps) (a trial; not a result, not a test).
- A rule on the stretch (from the allocation script): the longest request of a step against the longest request of the scenario's steps below 35% measured, at the thresholds 1.10, 1.25 and 1.50. The first rps that reaches 1.10 is at 57% to 81% measured at 90,000, 170,000 and 300,000 rows (a trial; not a result, not a test). Reaching 1.25 happens at 84% to 98% measured at 90,000 rows and at 87% to 96% in three of the five scenarios at 170,000 rows, and not in the other two (a trial; not a result, not a test). Reaching 1.50 happens only in two scenarios at 90,000 rows and two at 170,000 rows (a trial; not a result, not a test). The first rps depends strongly on the threshold.
- **The candidate rules cannot be fixed from the records alone.**

### 6.4 Side by side

| | A | B-warm-up | B-whole | B-per-request | C |
|---|---|---|---|---|---|
| What changes | the prediction's formula and its inputs | the length of the discarded minute | the definition of the utilisation, the tool | the tool and the database's rights | the definition of the limit |
| Lines of pass and fail, rules of the score | not touched | not touched | the definition of the utilisation is touched | touched | touched (item 4) |
| Meaning of the utilisation | unchanged | unchanged | changes (above 100%; saturation not seen) | changes | the limit's meaning changes |
| Check on records already seen (trial; not a result, not a test) | A-3 moves the 170,000-row errors from −8.7% to −15.2% to −5.8% to −11.3%; A-2 to −4.8% to −7.1%; the rate-capped model (A-4) to −6.2% to −9.5%; a bend as a function of the utilisation alone does not carry across counts | by the closed form, the same direction and size as A-3 | the work of a step against the prediction's is within −8.4% to +5.7% where it can be counted; the predicted limit is within −0.8% to +3.7% of the whole-work limit (B-3) | not possible | examples of rules: no stable knee across counts |
| Uses the lab | for the test | yes | yes | yes | for the test |
| Main risk | writes the lab's window edge into the prediction; the input of a request's time is open | what the edge does not explain remains | measures what the prediction predicts, a weaker test of it | a change of the safety conditions | the freedom of the rule; a change of definition |

## 7. The project's lean

**B-warm-up first; B-whole in addition only if the addition to the tool is small, and not as a yardstick of pass and fail; A only after what remains is seen; C is put to the maintainer separately.** The maintainer decides.

- **Reason 1: the estimate of one request is close, so the miss is in where the work falls in time.** The database CPU of a request is 3% to 5% below the prediction's work (4.2), and the whole-step count agrees with the prediction's work within −8.4% to +5.7% (B-1; not a result, not a test). This is said from records already in hand.
- **Reason 2: most of the miss at the lower step of the 80% line is the lab's window edge.** `edge_unloaded` is 71% to 105% of `diff` at the lower step of the five scenarios at 170,000 rows (4.4) and is given by a closed form of the minute, the window and the time one request takes. A deployment's load is continuous, and for it the proportional formula is the one that stays right. Putting the lab's edge into the prediction (A) would make the prediction describe the lab; repairing the lab's measure (B-warm-up) keeps the meaning of the utilisation and the lines of pass and fail.
- **Reason 3: a bend as a function of the utilisation alone does not carry across counts** (A-1; not a result, not a test), so if A is chosen, the forms to try are the closed form of the window's edge (A-3) and the rate-capped model (A-4), not a fitted bend, with the time of one request taken from its work and the 0.48 cores it draws, not from the upper bound.
- **Reason 4: B-whole counts the quantity the prediction predicts** (B-3: the prediction is within −0.8% to +3.7% of the whole-work limit; not a result, not a test), so after it the test says little about the prediction's power. If the tool addition is small, take it in the same measurement as a check of the cost per request, and do not use it as a yardstick of pass and fail.
- **Reason 5: C is the nearest to the user's question, but it changes the definition of the limit and the meaning of item 4** (6.3). That is an outward-facing choice for the maintainer, and it need not be made before B and A have been seen.
- **What is left unsure, stated:** (a) the model of the edge assumes that a ZIP request spends its database work evenly over its time (5.2); (b) the part of the miss that waiting adds cannot be separated from the estimate at the last step of each scenario to better than about 6 points (4.3); (c) the 300,000-row records are of an older tool and are read with less force; (d) in an app where the ZIP route does not dominate, the form is a sum over routes (the trials here take one heavy route).

## 8. What was and was not changed

Nothing in the records of the fifth round, the fourth round or the third round is rewritten, re-scored or re-predicted. The prediction, the estimate, the calibration, the formula, the rules of the mark and of pass and fail, and the specification are as committed. No measurement was made and no lab container was touched. The tables and the allocation are in `docs/research/lab-data/diagnosis/backtest-5-item4/` (the guide is its `README.md`).

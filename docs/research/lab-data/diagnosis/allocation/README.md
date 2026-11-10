# Allocation of the ladder miss

Records for the allocation of the difference between the predicted and the measured utilisation of the lab ladder (the one committed prediction, `poc/lab/predictions/`). Nothing here is a prediction, and nothing here changes the prediction, the estimate, the calibration, the formula or the score of the backtest.

## Sensitivity of the prediction to the coefficient `c_row`

The calibration on four runs has a `c_row` that is 8.2 times wider across the five fits (all four runs, and each run left out in turn) than `c_query` is (1.64 times). This puts `c_row` at the ends of that range and reads how far the committed prediction moves. The values are a **sensitivity**: they are not predictions (the backtest has one).

Coefficient sets (read from the committed prediction file, none typed by hand):

| set | `c_query` | `c_row` | meaning |
|---|---|---|---|
| `main` | 2.81705e-04 | 1.95063e-06 | main (fit on all four runs) |
| `i_min` | 2.87058e-04 | 1.37851e-06 | c_row smallest, c_query as in the same fit (without:cal-3) |
| `i_max` | 1.74879e-04 | 1.12854e-05 | c_row largest, c_query as in the same fit (without:cal-4) |
| `ii_min` | 2.81705e-04 | 1.37851e-06 | c_query of the main fit, c_row smallest |
| `ii_max` | 2.81705e-04 | 1.12854e-05 | c_query of the main fit, c_row largest |

`i_min`/`i_max` keep `c_query` as it is in the same leave-one-out fit; `ii_min`/`ii_max` keep `c_query` at the value of the main fit and move `c_row` alone.

Reading order: (i) is the primary reading, because each leave-one-out fit is the calibration's own width (simulator-spec section 6.3) and fits three of the four calibration runs; (ii) is set beside it as the literal answer to "put `c_row` alone at the ends", and it makes a pair that does not fit the calibration record. The (i) values are the ones already in the committed prediction's calibration range. This order was fixed before the per-route measurements were read.

### Limit rps (total over all routes) and its distance from the measured limit

| scenario | rows | measured | main | `i_min` | `i_max` | `ii_min` | `ii_max` |
|---|---|---|---|---|---|---|---|
| `t-1` | 30,000 | 1.3128 | 1.1157 (-15.0%) | 1.1344 (-13.6%) | 0.9235 (-29.7%) | 1.1541 (-12.1%) | 0.7225 (-45.0%) |
| `t-2` | 100,000 | 0.4966 | 0.3056 (-38.5%) | 0.3187 (-35.8%) | 0.1893 (-61.9%) | 0.3239 (-34.8%) | 0.1591 (-68.0%) |
| `t-3` | 100,000 | 0.2955 | 0.1917 (-35.1%) | 0.1997 (-32.4%) | 0.1198 (-59.5%) | 0.2030 (-31.3%) | 0.1005 (-66.0%) |
| `t-4` | 10,000 | 3.7646 | 3.4713 (-7.8%) | 3.4875 (-7.4%) | 3.4291 (-8.9%) | 3.5502 (-5.7%) | 2.5472 (-32.3%) |
| `t-5` | 100,000 | 1.2100 | 0.7529 (-37.8%) | 0.7880 (-34.9%) | 0.4505 (-62.8%) | 0.8007 (-33.8%) | 0.3815 (-68.5%) |

The percentage is the limit rps of the set against the measured limit rps (the same reading as the score: predicted ÷ measured − 1).

### Move against the main fit

| scenario | `i_min` | `i_max` | `ii_min` | `ii_max` |
|---|---|---|---|---|
| `t-1` | +1.7% | -17.2% | +3.4% | -35.2% |
| `t-2` | +4.3% | -38.1% | +6.0% | -47.9% |
| `t-3` | +4.2% | -37.5% | +5.9% | -47.6% |
| `t-4` | +0.5% | -1.2% | +2.3% | -26.6% |
| `t-5` | +4.7% | -40.2% | +6.3% | -49.3% |

### Utilisation (%) at two stages, point reading

Stage 0.20 rps total:

| scenario | main | `i_min` | `i_max` | `ii_min` | `ii_max` |
|---|---|---|---|---|---|
| `t-1` | 14.3 | 14.1 | 17.3 | 13.9 | 22.1 |
| `t-2` | 52.4 | 50.2 | 84.5 | 49.4 | 100.6 |
| `t-3` | 83.5 | 80.1 | 133.5 | 78.8 | 159.2 |
| `t-4` | 4.6 | 4.6 | 4.7 | 4.5 | 6.3 |
| `t-5` | 21.3 | 20.3 | 35.5 | 20.0 | 41.9 |

Stage 0.70 rps total:

| scenario | main | `i_min` | `i_max` | `ii_min` | `ii_max` |
|---|---|---|---|---|---|
| `t-1` | 50.2 | 49.4 | 60.6 | 48.5 | 77.5 |
| `t-2` | 183.3 | 175.7 | 295.8 | 172.9 | 351.9 |
| `t-3` | 292.1 | 280.4 | 467.3 | 275.9 | 557.1 |
| `t-4` | 16.1 | 16.1 | 16.3 | 15.8 | 22.0 |
| `t-5` | 74.4 | 71.1 | 124.3 | 69.9 | 146.8 |

All 13 stages and every set (also the five fits) are in `c-row-sensitivity.json`, with the rates the model turns into a load (queries per second and rows per second) at each stage.

### Reading

- The 100,000-row scenarios (`t-2`, `t-3`, `t-5`) over-predict the utilisation, so the predicted limit is below the measured one. To reach the measured limit the predicted limit would have to rise by 63% (`t-2`), 54% (`t-3`) and 61% (`t-5`).
- Putting `c_row` at its smallest value raises the predicted limit of those three by at most 6.0%, 5.9% and 6.3% (`ii_min`; with `c_query` as in its own fit, `i_min`, by less). The smallest `c_row` in the range closes only a small part of the gap, and the largest `c_row` widens it.
- So within the range of the four-run calibration, the width of `c_row` alone does not explain the 100,000-row miss. What the sensitivity cannot say is whether the rates the model reads (queries and rows per second from the estimate) are the part that is off; that is read from the per-route measurements, not from this table.
- For `t-4` (10,000 rows) and `t-1` (30,000 rows), the smallest `c_row` moves the limit by +2.3% and +3.4% (`ii_min`); the largest moves it by -26.6% and -35.2% (`ii_max`). These two scenarios were close to the measured limit with the main fit already.

### How it was made

`c-row-sensitivity.php` is a thin bridge outside the prediction tool. It loads the estimate and the scenario file as `poc/lab/bin/predict-ladder` does (the first stage of `SimulateCommand`, the safe-side estimates at their counts), takes the two rates the model turns into a load with `UtilizationModel::rates` (point reading) at every stage, and puts a pair of coefficients on them (`utilisation = (c_query × queries + c_row × rows) ÷ cores`, the form the prediction itself uses for its calibration range). The limit rps is `LadderPrediction::limitRps`. It adds no number of its own.

Self-check: for every target scenario and stage, the `main` set equals the committed point utilisation, and each leave-one-out fit equals the committed calibration range, with a maximum absolute difference of 0 (any difference above 1e-12 stops the bridge before it writes).

Run (a throwaway PHP container, no network, the repository mounted read-only):

```
docker run --rm --network none -v "$PWD":/app:ro -v "$OUT":/out -w /app <php image> php \
  docs/research/lab-data/diagnosis/allocation/c-row-sensitivity.php poc/runtime/bootstrap.php \
  --estimate=docs/research/lab-data/estimate \
  --safe-estimate=docs/research/lab-data/estimate-10k --safe-estimate=docs/research/lab-data/estimate-30k \
  --scenario=poc/lab/predictions/ladder-prediction-input.yaml --prediction=poc/lab/predictions/ladder-prediction.json \
  --score=docs/research/lab-data/score/score.json --out=/out
```

The output `sensitivity.json` of that run is kept here as `c-row-sensitivity.json`.

## Allocation of the miss to three causes

The miss of a usable stage is `delta = U0 - M`: the committed prediction's point utilisation `U0` minus the measured utilisation `M`, in points of the database's 2-core utilisation. It is split into three parts by replacing, one at a time, what the prediction reads with what was measured in `../per-request/` and `../zip-limit/`. `allocate.py` makes the numbers (`python3 -I allocate.py`; it reads committed records only, uses no lab container, and writes `allocation.json` and `allocation-tables.md`). Nothing here is a prediction or a score: the prediction, the estimate, the calibration, the formula and the score are as committed, and no usable-stage rule was changed (the stages are those the score marks usable: 31 of the 47).

The method and its order were fixed before the per-request and ZIP records were read (`docs/research/lab-backtest-causes.md` section 2.3, commit a6a5466); the inputs, the definitions and the one filling-in that the records called for are below.

| name | what is put in place of what | reads |
|---|---|---|
| `U0` | the committed point utilisation (`sum(rps_r x k_r^est) / 2`, the estimate's statements and rows at the scenario's row count, `c_q` and `c_row` as committed) | checked: `U0` rebuilt from the per-request table's estimate equals the committed value, largest difference 2.2e-16 |
| `U1` | every route's estimate replaced by its measured statements and rows, converted with the same `c_q` and `c_row` | (a) = `U0 - U1`: the estimate's error per request |
| `U2` | the ZIP route replaced by `f x k^trunc + (1 - f) x k^full` (the share `f` of its requests that did not return 200 cost what a request cut by php's 30-second limit costs, the rest what an uncut one costs) | (b) = `U1 - U2`: the work the cut left undone |
| `M` | the measured utilisation | (c) = `U2 - M`: what is left |
| `U3` | `U2` with the converted counts replaced by the measured database CPU time of one request | (c1) = `U2 - U3`: the conversion of counts to CPU; (c2) = `U3 - M`: the rest, which includes load, caching, and how the two are measured. (c1) + (c2) = (c) |

Where each input comes from:

- Statements and rows measured: the cold request of `measure-one` (`../per-request/<rows>/table.json`), the median of three. The ZIP route at 100,000 rows is the exception, and both its values are read the same way, from the server's own counters over the HTTP window: with php's limit as it is (cut: 96,024 statements and 799,197 rows read, `../per-request/100000/`) and with the limit off (uncut: 120,036 statements and 835,207 rows read, `../zip-limit/`). The per-request table's 100,000-row ZIP line (120,004 statements and 835,196 rows) is the uncut `measure-one` count and was not used for the cut side, because it would show no cut. The server's counters include the few statements and reads of booting the app (about 7 to 17 statements, about 12 rows); that is small against the ZIP route's tens of thousands.
- `f`, 100,000 rows only: ZIP requests that did not return 200 (HTTP 500 from `ladder.json`, which is only ever this route, plus the ZIP requests with no response from `../no-response/status0-by-route.csv`) over the ZIP requests counted in the stage (`per_route`). It is 1.00 in every usable stage of `t-2`, `t-3` and `t-5`. Under 100,000 rows the ZIP route's php CPU time is 4.1 s (10,000) and 12.1 s (30,000) against the 30-second limit, so no request is cut there and `f` is 0; the ZIP requests with no response at those counts (1 in `t-4` step 11, 4 in `t-1` step 10) are k6's own time limit, not a cut. Whether php-fpm kept running for a request k6 had given up on was not checked; counting those as cut follows the fixed method.
- Database CPU time of one request: `cpu_median_s` of `table.json` (the HTTP part, after the empty window and the idle drift are taken off); the ZIP route at 100,000 rows is 25.2 s cut and 33.4 s uncut (`../zip-limit/compare.json`).
- **Filled in because the record called for it.** Where the database CPU time was withheld in `../per-request/` (below the measurement floor, negative, or scattering by more than its median), a CPU time cannot be put in; `U3` takes the converted counts (`k^full`) of that route instead. These are the five small routes at every count and `/shelves/{slug}` at 10,000 and 30,000 rows. They carry 0.0% to 0.1% of the load of `U1` in `t-1`, `t-2`, `t-3` and `t-5` and 0.4% in `t-4` (last column of the per-stage tables). This is a rule of "no ratio, so no value", not a choice made from which way the value falls.

### Result

Sums over the usable stages, in points; shares are of the sum of `delta`. The per-stage tables, the per-route table and the single inputs are in `allocation-tables.md` and `allocation.json`.

| scenario | rows | usable stages | sum of delta | (a) | (b) | (c) | (c1) | (c2) | share (a) | share (b) | share (c) |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `t-2` | 100,000 | 6 | +115.56 | +31.40 | +54.25 | +29.90 | +37.74 | -7.83 | 27% | 47% | 26% |
| `t-3` | 100,000 | 6 | +124.25 | +33.15 | +59.46 | +31.64 | +41.38 | -9.74 | 27% | 48% | 25% |
| `t-5` | 100,000 | 6 | +105.37 | +31.79 | +47.84 | +25.74 | +33.22 | -7.48 | 30% | 45% | 24% |
| `t-1` | 30,000 | 7 | +30.05 | +2.02 | +0.00 | +28.02 | +29.64 | -1.62 | (points are the reading; see below) | | |
| `t-4` | 10,000 | 6 | +4.05 | +1.14 | +0.00 | +2.91 | +14.35 | -11.44 | (points are the reading; see below) | | |

Range of each share over the single stages (stages with `delta` above 0.5 points): `t-2` (a) 26% to 30%, (b) 46% to 52%, (c) 19% to 28%; `t-3` (a) 26% to 30%, (b) 47% to 54%, (c) 16% to 27%; `t-5` (a) 29% to 33%, (b) 44% to 50%, (c) 17% to 26%. Within each of those three scenarios the split is similar at every stage (each of (a), (b) and (c) moves within about 12 points).

The other order, and the (a) aligned to 10,000 rows (both fixed before the results were read; neither is chosen from its result):

- Other order (ZIP cut first, then the estimate's error; `(b'')`, `(a'')`): `t-2` (b'') +56.18 and (a'') +29.47; `t-3` +61.58 and +31.03; `t-5` +49.55 and +30.09. Only the split between (a) and (b) moves, by 2 points of `delta` or less in each; (c) does not depend on the order.
- The estimate divided by (ratio at N) / (ratio at 10,000), ratio = estimate / measured, so that the part the calibration absorbed at 10,000 rows is not counted as (a): (a) aligned is +31.24, +33.09 and +31.24 for `t-2`, `t-3`, `t-5`, shares 27%, 27% and 30%, within 0.5 point of the main shares. In `t-1` it is +1.48 against +2.02 (shares 5% and 7%, 1.8 points apart). In `t-4` it is 0 by construction (the row count is 10,000); the main (a) share there is 28%, so for `t-4` the border between (a) and (c) is not decided and (a) is a range of 0% to 28% of a `delta` sum of 4.05 points.

### What the numbers say

- **At 100,000 rows (`t-2`, `t-3`, `t-5`) the predicted utilisation is 43% to 57% above the measured one at every usable stage, and the three parts are about a quarter, a half and a quarter.** (a) is 27% to 30% of `delta`: the estimate's statements and rows are too large for two routes, `/books/{slug}` (2,539,961 rows against 1,260,454 measured; +17.5 to +21.7 points in the sums) and the ZIP route (+8.9 to +11.0), and `/shelves/{slug}` adds +0.4 to +5.5 (estimated rows grow with N, measured rows stay at 236). The ZIP route's statements are estimated right (119,994 against 120,036 uncut). (b) is 45% to 48% and is the largest single part: every ZIP request counted in these stages was cut by php's limit (`f` = 1.00), and a cut request does 80% of the statements and 96% of the rows of an uncut one, 25.2 s of database CPU against 33.4 s. (c) is 24% to 26%, and splits into (c1) +32% to +33% of `delta` (a cold request's database CPU time is below what its counts convert to: for example `/books/{slug}` at 100,000 rows 1.13 s against 2.47 s from counts, the cut ZIP request 25.2 s against 28.6 s) and (c2) -7% to -8%.
- **(c1) compares the converted counts with the measured CPU time of one cold request.** It shows that `c_q x statements + c_row x rows` gives more than the measured CPU time for the two heavy routes. Whether another pair of coefficients would remove that was not tested; the sensitivity above shows that `c_row` moved to the ends of the calibration's own range does not close the 100,000-row gap. The two readings are set side by side and not combined.
- **`t-4` (10,000 rows) and `t-1` (30,000 rows) were close because the estimate is close at those counts, not because errors cancel in (a).** (a) is +1.14 and +2.02 points of sums: the point statements and rows of the ZIP route equal the measured ones (-0.00 and -0.30), `/books/{slug}` is +0.03 and +0.75, and `/shelves/{slug}` is +1.12 and +1.57 (the one route whose rows are estimated far too high, at a few ms of CPU). ZIP at these counts is not cut (`f` = 0), so (b) is 0. What is left, (c), is small at the stages below 1.0 rps (about 0.1 to 1.9 points in `t-4`, 0.1 to 4.3 in `t-1`).
- **(c1) and (c2) cancel at 10,000 and 30,000 rows.** In `t-4`, (c1) is +14.35 and (c2) is -11.44; in `t-1`, +29.64 and -1.62. The converted counts are above the cold CPU time of one request (about 7% at 10,000 rows) and the measured utilisation is above the sum of those CPU times (-1.0 point at the lowest stages, down to -3.3 at 3.3 rps). The database's idle CPU rate read in the per-request records is 0.65, 0.63 and 0.64 points of utilisation at 10,000, 30,000 and 100,000 rows; (c2) at the lowest stages is about -1 point. The two are of the same size; this was not tested as the cause.
- **`t-1`'s sum is mostly one stage.** Stage 10 (1.5 rps, `M` = 87.8%, `U0` = 107.6%) has `delta` +19.77, of which (c) is +19.07. It is the stage where requests with no response begin (29, of which 4 are ZIP requests), and `M` cannot rise past the database's capacity; without stage 10 `delta` is +10.28 and (c) is +8.95 over six stages. The same stage is why `t-1`'s (c1) and (c2) shares are not stable (see the range table in `allocation-tables.md`).
- What the tables cannot say: why a cold request's CPU is below its counts' conversion (not tested); whether (c2) at 100,000 rows (-7% to -8%, about -1 to -2 points per stage) is the idle rate, the loaded run against the cold request, or the measuring; and what the limit rps would be if each part were corrected one at a time (that is a prediction, and this record makes none).

### Files

| File | |
|---|---|
| `allocate.py` | the reading of the committed records (no lab use; the definitions are in its docstring) |
| `allocation.json` | every number: per stage, per scenario, per route and row count |
| `allocation-tables.md` | the same as tables: per scenario, per stage, ranges, the other order, the aligned (a), (a) by route, and what one request is taken as |
